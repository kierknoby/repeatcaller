<?php

declare(strict_types=1);

// Install-specific contract, added after a real FreePBX 16 installation test
// found that a fresh module install reported success while the schema was
// never created. Root cause: install.php used FreePBX's legacy global $db
// wrapper (admin/libraries/DB.class.php), whose query() method catches every
// PDOException and returns a DB_Error object instead of throwing -- so a
// failed CREATE TABLE was silently swallowed and never surfaced. The fix
// moves schema creation into src/Schema.php using a real PDO connection
// (\FreePBX::Database()) with ERRMODE_EXCEPTION, called from both
// Repeatcaller::install() (the BMO method FreePBX unconditionally calls
// before install.php, confirmed against the real FreePBX 16.0.45 framework
// source) and install.php itself as a redundant safety net.
//
// The actual CREATE TABLE / guarded-migration / INSERT IGNORE SQL was
// additionally executed for real against a disposable MariaDB 10.11.14
// instance during development of this fix (not automated here, since the
// default php binary in this environment has no pdo_mysql extension). This
// suite proves the structural/behavioral invariants that ARE checkable
// without a live MySQL/MariaDB connection.

require_once __DIR__ . '/../src/Schema.php';

if (!function_exists('_')) {
	function _($text) {
		return $text;
	}
}

if (!interface_exists('BMO')) {
	interface BMO {}
}

if (!class_exists('FreePBX')) {
	class FreePBX {
		public static $database;

		public static function Database() {
			return self::$database;
		}

		public static function Log() {
			return new class {
				public function error($message): void {}
				public function warning($message): void {}
				public function info($message): void {}
			};
		}

		public static function Modules() {
			return new class {
				public function getInfo($name): array {
					return [];
				}
			};
		}
	}
}

require_once __DIR__ . '/../Repeatcaller.class.php';

use FreePBX\modules\Repeatcaller;
use FreePBX\modules\Repeatcaller\Schema;

function assert_true(bool $condition, string $message): void {
	if (!$condition) {
		throw new RuntimeException($message);
	}
}

function assert_same($expected, $actual, string $message): void {
	if ($expected !== $actual) {
		throw new RuntimeException($message . ' Expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
	}
}

/** Bytes a single indexed column contributes to InnoDB's key-length total. */
function columnByteWidth(string $type, int $bytesPerChar): int {
	$type = trim($type);
	if (preg_match('/^VARCHAR\((\d+)\)/i', $type, $m)) {
		return (int)$m[1] * $bytesPerChar;
	}
	if (preg_match('/^CHAR\((\d+)\)/i', $type, $m)) {
		return (int)$m[1] * $bytesPerChar;
	}
	if (preg_match('/^TINYINT/i', $type)) {
		return 1;
	}
	if (preg_match('/^BIGINT/i', $type)) {
		return 8;
	}
	if (preg_match('/^INT/i', $type)) {
		return 4;
	}
	if (preg_match('/^DATETIME/i', $type)) {
		return 8;
	}
	if (preg_match('/^TIME/i', $type)) {
		return 3;
	}
	throw new RuntimeException("columnByteWidth: unhandled column type '{$type}'");
}

/**
 * Parses a single CREATE TABLE statement and returns every index whose
 * combined column byte width exceeds $limit, as ["index_name" => bytes].
 * Deliberately assumes InnoDB Antelope/COMPACT row format with
 * innodb_large_prefix off (MariaDB 5.5 defaults) -- i.e. no credit is given
 * for a wider limit that a newer server/row-format might allow.
 */
function indexesExceedingKeyLimit(string $createStatement, int $bytesPerChar, int $limit): array {
	if (!preg_match('/\((.*)\)\s*ENGINE=/s', $createStatement, $bodyMatch)) {
		throw new RuntimeException('indexesExceedingKeyLimit: could not locate column/index body');
	}
	$lines = preg_split('/,\s*\n/', $bodyMatch[1]);

	$columnTypes = [];
	foreach ($lines as $line) {
		$line = trim($line);
		if ($line === '' || preg_match('/^(PRIMARY KEY|UNIQUE KEY|KEY)\b/i', $line)) {
			continue;
		}
		if (preg_match('/^(\w+)\s+(.*)$/', $line, $columnMatch)) {
			$columnTypes[$columnMatch[1]] = $columnMatch[2];
		}
	}

	$exceeding = [];
	foreach ($lines as $line) {
		$line = trim($line);
		$indexName = null;
		$columnList = null;
		if (preg_match('/^PRIMARY KEY\s*\(([^)]+)\)/i', $line, $keyMatch)) {
			$indexName = 'PRIMARY';
			$columnList = $keyMatch[1];
		} elseif (preg_match('/^UNIQUE KEY\s+(\w+)\s*\(([^)]+)\)/i', $line, $keyMatch)) {
			$indexName = $keyMatch[1];
			$columnList = $keyMatch[2];
		} elseif (preg_match('/^KEY\s+(\w+)\s*\(([^)]+)\)/i', $line, $keyMatch)) {
			$indexName = $keyMatch[1];
			$columnList = $keyMatch[2];
		}
		if ($indexName === null) {
			continue;
		}

		$totalBytes = 0;
		foreach (array_map('trim', explode(',', $columnList)) as $columnName) {
			if (!isset($columnTypes[$columnName])) {
				throw new RuntimeException("indexesExceedingKeyLimit: column '{$columnName}' referenced by index '{$indexName}' not found");
			}
			$totalBytes += columnByteWidth($columnTypes[$columnName], $bytesPerChar);
		}
		if ($totalBytes > $limit) {
			$exceeding[$indexName] = $totalBytes;
		}
	}

	return $exceeding;
}

final class SchemaUpgradePDO extends PDO {
	public function __construct() {
		parent::__construct('sqlite::memory:');
		$this->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
		if (method_exists($this, 'sqliteCreateFunction')) {
			$this->sqliteCreateFunction('DATABASE', static function (): string {
				return 'main';
			}, 0);
		}
		parent::exec("ATTACH DATABASE ':memory:' AS information_schema");
		parent::exec('CREATE TABLE information_schema.TABLES (TABLE_SCHEMA TEXT NOT NULL, TABLE_NAME TEXT NOT NULL)');
		parent::exec('CREATE TABLE information_schema.COLUMNS (TABLE_SCHEMA TEXT NOT NULL, TABLE_NAME TEXT NOT NULL, COLUMN_NAME TEXT NOT NULL, COLUMN_TYPE TEXT NOT NULL)');
	}

	public function prepare(string $query, array $options = []): PDOStatement|false {
		if (strpos($query, 'INSERT IGNORE INTO repeatcaller_settings') !== false) {
			$query = str_replace('INSERT IGNORE', 'INSERT OR IGNORE', $query);
		}

		return parent::prepare($query, $options);
	}

	public function exec(string $statement): int|false {
		$trimmed = ltrim($statement);
		if (stripos($trimmed, 'CREATE TABLE IF NOT EXISTS ') === 0) {
			return 0;
		}

		$result = parent::exec($statement);
		if ($result !== false && preg_match('/^ALTER TABLE\s+(\w+)\s+ADD COLUMN\s+/i', $trimmed)) {
			$this->syncInformationSchema();
		}

		return $result;
	}

	public function syncInformationSchema(): void {
		parent::exec('DELETE FROM information_schema.TABLES');
		parent::exec('DELETE FROM information_schema.COLUMNS');

		$tableStmt = parent::query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' AND name NOT LIKE 'information_schema.%' ORDER BY name");
		if (!$tableStmt) {
			return;
		}
		$insertTable = parent::prepare('INSERT INTO information_schema.TABLES (TABLE_SCHEMA, TABLE_NAME) VALUES (?, ?)');
		$insertColumn = parent::prepare('INSERT INTO information_schema.COLUMNS (TABLE_SCHEMA, TABLE_NAME, COLUMN_NAME, COLUMN_TYPE) VALUES (?, ?, ?, ?)');
		while (($table = $tableStmt->fetchColumn()) !== false) {
			$tableName = (string)$table;
			$insertTable->execute(['main', $tableName]);
			$columnStmt = parent::query("PRAGMA table_info('" . str_replace("'", "''", $tableName) . "')");
			if (!$columnStmt) {
				continue;
			}
			while (($column = $columnStmt->fetch(PDO::FETCH_ASSOC)) !== false) {
				$insertColumn->execute(['main', $tableName, (string)($column['name'] ?? ''), (string)($column['type'] ?? '')]);
			}
		}
	}
}

function schemaUpgradeCreateLegacyTables(SchemaUpgradePDO $db): void {
	$db->exec('CREATE TABLE repeatcaller_settings (
		setting_key TEXT PRIMARY KEY,
		setting_value TEXT,
		updated_at TEXT
	)');
	$db->exec('CREATE TABLE repeatcaller_rules (
		id INTEGER PRIMARY KEY AUTOINCREMENT,
		name TEXT NOT NULL,
		enabled INTEGER NOT NULL DEFAULT 1,
		mode TEXT NOT NULL,
		threshold_count INTEGER NOT NULL,
		observation_window_minutes INTEGER NOT NULL,
		caller_mode TEXT NOT NULL,
		did_scope_mode TEXT NOT NULL,
		exclude_withheld INTEGER NOT NULL DEFAULT 0,
		repeat_mode_override TEXT,
		suppression_minutes_override INTEGER,
		created_at TEXT,
		updated_at TEXT
	)');
	$db->exec('CREATE TABLE repeatcaller_rule_schedules (
		id INTEGER PRIMARY KEY AUTOINCREMENT,
		rule_id INTEGER NOT NULL,
		day_of_week INTEGER NOT NULL,
		start_time TEXT NOT NULL,
		end_time TEXT NOT NULL,
		created_at TEXT
	)');
	$db->exec('CREATE TABLE repeatcaller_rule_callers (
		id INTEGER PRIMARY KEY AUTOINCREMENT,
		rule_id INTEGER NOT NULL,
		list_type TEXT NOT NULL,
		raw_value TEXT NOT NULL,
		normalized_value TEXT NOT NULL,
		created_at TEXT
	)');
	$db->exec('CREATE TABLE repeatcaller_rule_dids (
		id INTEGER PRIMARY KEY AUTOINCREMENT,
		rule_id INTEGER NOT NULL,
		list_type TEXT NOT NULL,
		route_key TEXT NOT NULL,
		route_label TEXT NOT NULL,
		did_value TEXT,
		cid_value TEXT,
		created_at TEXT
	)');
	$db->exec('CREATE TABLE repeatcaller_seen_calls (
		id INTEGER PRIMARY KEY AUTOINCREMENT,
		call_identity TEXT NOT NULL,
		identity_type TEXT NOT NULL,
		fingerprint TEXT NOT NULL,
		linkedid TEXT,
		uniqueid TEXT,
		caller_raw TEXT,
		caller_normalized TEXT,
		inbound_route_key TEXT,
		did_value TEXT,
		call_started_at TEXT NOT NULL,
		call_completed_at TEXT,
		disposition TEXT,
		source_context TEXT,
		processed_at TEXT NOT NULL
	)');
	$db->exec('CREATE TABLE repeatcaller_rule_subject_state (
		id INTEGER PRIMARY KEY AUTOINCREMENT,
		rule_id INTEGER NOT NULL,
		subject_key TEXT NOT NULL,
		current_window_started_at TEXT,
		current_window_ends_at TEXT,
		current_window_call_count INTEGER NOT NULL DEFAULT 0,
		threshold_met INTEGER NOT NULL DEFAULT 0,
		clear_observed_since_trigger INTEGER NOT NULL DEFAULT 0,
		active_incident_id INTEGER,
		suppression_expires_at TEXT,
		last_call_at TEXT,
		last_evaluated_at TEXT,
		created_at TEXT,
		updated_at TEXT
	)');
	$db->exec('CREATE TABLE repeatcaller_incidents (
		id INTEGER PRIMARY KEY AUTOINCREMENT,
		rule_id INTEGER NOT NULL,
		subject_key TEXT NOT NULL,
		active_subject_key TEXT,
		subject_label TEXT NOT NULL,
		caller_normalized TEXT,
		caller_display TEXT,
		withheld_caller INTEGER NOT NULL DEFAULT 0,
		mode TEXT NOT NULL,
		threshold_count INTEGER NOT NULL DEFAULT 0,
		observation_window_minutes INTEGER NOT NULL DEFAULT 0,
		first_matched_at TEXT NOT NULL,
		last_matched_at TEXT NOT NULL,
		matched_call_count INTEGER NOT NULL DEFAULT 0,
		state TEXT NOT NULL,
		claimed_by TEXT,
		claimed_at TEXT,
		claim_source TEXT,
		suppression_expires_at TEXT,
		created_at TEXT,
		updated_at TEXT
	)');
	$db->exec('CREATE TABLE repeatcaller_incident_alert_state (
		id INTEGER PRIMARY KEY AUTOINCREMENT,
		incident_id INTEGER NOT NULL,
		rule_id INTEGER NOT NULL,
		repeat_mode TEXT NOT NULL,
		initial_sent_at TEXT,
		last_alert_at TEXT,
		reminders_sent INTEGER NOT NULL DEFAULT 0,
		next_due_at TEXT,
		created_at TEXT NOT NULL,
		updated_at TEXT NOT NULL
	)');
	$db->exec('CREATE TABLE repeatcaller_incident_alert_history (
		id INTEGER PRIMARY KEY AUTOINCREMENT,
		incident_id INTEGER NOT NULL,
		rule_id INTEGER NOT NULL,
		subject_key TEXT NOT NULL,
		subject_label TEXT NOT NULL,
		action_type TEXT NOT NULL,
		event_type TEXT NOT NULL,
		stage_n INTEGER NOT NULL DEFAULT 0,
		recipient TEXT,
		delivery_status TEXT NOT NULL,
		attempted_at TEXT,
		successful_at TEXT,
		next_retry_at TEXT,
		failure_detail TEXT,
		repeat_mode TEXT NOT NULL,
		dedupe_key TEXT NOT NULL UNIQUE,
		created_at TEXT NOT NULL,
		updated_at TEXT NOT NULL
	)');
	$db->exec('CREATE TABLE repeatcaller_incident_suppression_history (
		id INTEGER PRIMARY KEY AUTOINCREMENT,
		related_incident_id INTEGER NOT NULL UNIQUE,
		rule_id INTEGER NOT NULL,
		rule_name TEXT NOT NULL,
		mode TEXT NOT NULL,
		subject_key TEXT NOT NULL,
		subject_label TEXT NOT NULL,
		caller_normalized TEXT,
		caller_display TEXT,
		inbound_route_key TEXT,
		inbound_route_label TEXT,
		did_value TEXT,
		matched_call_count INTEGER NOT NULL DEFAULT 0,
		threshold_count INTEGER NOT NULL,
		observation_window_minutes INTEGER NOT NULL,
		suppression_source TEXT NOT NULL,
		suppression_minutes INTEGER NOT NULL,
		suppression_started_at TEXT NOT NULL,
		suppression_expires_at TEXT NOT NULL,
		related_incident_state TEXT NOT NULL,
		detected_at TEXT NOT NULL,
		created_at TEXT NOT NULL,
		updated_at TEXT NOT NULL
	)');

	$db->syncInformationSchema();
}

function schemaUpgradeColumnList(PDO $db, string $table): array {
	$stmt = $db->prepare('SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? ORDER BY COLUMN_NAME ASC');
	$stmt->execute([$table]);
	return array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN));
}

$root = dirname(__DIR__);

// --- 1: the install script contains executable schema creation for every
// current table (via the shared Schema class it delegates to) -------------

$createStatements = new ReflectionMethod(Schema::class, 'createTableStatements');
$createStatements->setAccessible(true);
$statements = $createStatements->invoke(null);
assert_true(count($statements) === count(Schema::REQUIRED_TABLES), 'one CREATE TABLE statement must exist per required table');

$createdTables = [];
foreach ($statements as $statement) {
	assert_true((bool)preg_match('/^CREATE TABLE IF NOT EXISTS (\w+)/', trim($statement), $match), 'each schema statement must be a guarded, executable CREATE TABLE IF NOT EXISTS');
	$createdTables[] = $match[1];
}
sort($createdTables);
$sortedRequired = Schema::REQUIRED_TABLES;
sort($sortedRequired);
assert_same($sortedRequired, $createdTables, 'schema creation must cover exactly the required table set');

$installSource = file_get_contents($root . '/install.php');
assert_true(strpos($installSource, 'Schema::install(') !== false, 'install.php must actually invoke schema creation, not merely reference the class');

$moduleXml = file_get_contents($root . '/module.xml');
assert_true($moduleXml !== false, 'module.xml should be readable');
assert_true(strpos($moduleXml, '<version>1.0.0</version>') !== false, 'module version must declare 1.0.0 for the current release');

$schemaSource = file_get_contents($root . '/src/Schema.php');
assert_true($schemaSource !== false, 'src/Schema.php should be readable');
assert_true(strpos($schemaSource, 'repeatcaller_incident_suppression_history') !== false, 'schema must define a dedicated suppression-history table');
assert_true(strpos($schemaSource, 'addColumnIfMissing($pdo, \'repeatcaller_incidents\', \'cleared_at\'') !== false, 'guarded migrations must add cleared_at for existing incident rows');
assert_true(strpos($schemaSource, 'addColumnIfMissing($pdo, \'repeatcaller_incident_suppression_history\', \'cleared_at\'') !== false, 'guarded migrations must add cleared_at for existing suppression-history rows');
assert_true(strpos($schemaSource, 'addColumnIfMissing($pdo, \'repeatcaller_rules\', \'alert_call_strategy\'') !== false, 'guarded migrations must add alert_call_strategy for existing installs');
assert_true(strpos($schemaSource, 'addColumnIfMissing($pdo, \'repeatcaller_rules\', \'alert_call_keep_trying\'') !== false, 'guarded migrations must add alert_call_keep_trying for existing installs');
assert_true(strpos($schemaSource, "alert_call_strategy VARCHAR(20) NOT NULL DEFAULT 'ringall'") !== false, 'fresh schema must default alert_call_strategy to ringall');
assert_true(strpos($schemaSource, 'alert_call_keep_trying TINYINT(1) NOT NULL DEFAULT 1') !== false, 'fresh schema must default alert_call_keep_trying to enabled');
assert_true(strpos($schemaSource, "'alert_recipients' => ''") === false, 'fresh schema must not define removed global email destinations');
assert_true(strpos($schemaSource, 'email_recipients TEXT NULL') !== false, 'fresh schema must provide rule-level email recipients');
assert_true(strpos($schemaSource, 'cleared_at DATETIME NULL') !== false, 'fresh suppression schema must include cleared_at for incident and suppression-history tables');
assert_true(strpos($schemaSource, 'alert_enabled') === false, 'schema defaults and guarded migrations must not include a removed global email enable flag');
assert_true(strpos($schemaSource, "'suppression_history_prune_policy' => 'daily'") !== false, 'fresh schema must default suppression-history pruning to daily');
assert_true(strpos($schemaSource, 'day_of_week TINYINT NOT NULL') !== false && strpos($schemaSource, 'ALTER TABLE repeatcaller_rule_schedules MODIFY COLUMN day_of_week TINYINT NOT NULL') !== false, 'schedule schema must define signed day_of_week and migrate existing unsigned columns');
assert_true(strpos($schemaSource, 'self::ensureScheduleDayOfWeekIsSigned($pdo);') !== false && strpos($schemaSource, 'self::applyGuardedMigrations($pdo);') !== false, 'signed day_of_week migration must be invoked during the normal Schema::install upgrade path');

$legacyDb = new SchemaUpgradePDO();
schemaUpgradeCreateLegacyTables($legacyDb);

$legacyDb->prepare('INSERT INTO repeatcaller_incidents (rule_id, subject_key, active_subject_key, subject_label, caller_normalized, caller_display, withheld_caller, mode, first_matched_at, last_matched_at, matched_call_count, state, claimed_by, claimed_at, claim_source, suppression_expires_at, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')->execute([
	7,
	'+441234567890',
	'active-7',
	'Legacy Incident',
	'+441234567890',
	'01234567890',
	0,
	'repeat',
	'2026-07-13 10:00:00',
	'2026-07-13 10:05:00',
	2,
	'suppressed',
	null,
	null,
	null,
	'2026-07-13 11:00:00',
	'2026-07-13 10:00:00',
	'2026-07-13 10:05:00',
]);
$legacyDb->prepare('INSERT INTO repeatcaller_incident_suppression_history (related_incident_id, rule_id, rule_name, mode, subject_key, subject_label, caller_normalized, caller_display, inbound_route_key, inbound_route_label, did_value, matched_call_count, threshold_count, observation_window_minutes, suppression_source, suppression_minutes, suppression_started_at, suppression_expires_at, related_incident_state, detected_at, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')->execute([
	42,
	7,
	'Legacy Rule',
	'repeat',
	'+441234567890',
	'Legacy Incident',
	'+441234567890',
	'01234567890',
	null,
	null,
	null,
	2,
	2,
	30,
	'global_default',
	30,
	'2026-07-13 10:00:00',
	'2026-07-13 11:00:00',
	'suppressed',
	'2026-07-13 10:05:00',
	'2026-07-13 10:05:00',
	'2026-07-13 10:05:00',
]);

FreePBX::$database = $legacyDb;
$upgradeModule = new Repeatcaller(new stdClass());
$upgradeModule->install();

$incidentColumnsAfterUpgrade = schemaUpgradeColumnList($legacyDb, 'repeatcaller_incidents');
assert_true(in_array('cleared_at', $incidentColumnsAfterUpgrade, true), 'upgrade migration must add cleared_at to repeatcaller_incidents');
$suppressionColumnsAfterUpgrade = schemaUpgradeColumnList($legacyDb, 'repeatcaller_incident_suppression_history');
assert_true(in_array('cleared_at', $suppressionColumnsAfterUpgrade, true), 'upgrade migration must add cleared_at to repeatcaller_incident_suppression_history');

$legacyIncident = $legacyDb->query('SELECT subject_label, suppression_expires_at, cleared_at FROM repeatcaller_incidents WHERE id = 1')->fetch(PDO::FETCH_ASSOC);
assert_same('Legacy Incident', (string)$legacyIncident['subject_label'], 'existing incident rows must be preserved during schema upgrade');
assert_same('2026-07-13 11:00:00', (string)$legacyIncident['suppression_expires_at'], 'existing incident suppression timestamps must be preserved during schema upgrade');
assert_same(null, $legacyIncident['cleared_at'], 'new incident cleared_at column must default to NULL for preserved rows');

$legacySuppression = $legacyDb->query('SELECT rule_name, suppression_expires_at, cleared_at FROM repeatcaller_incident_suppression_history WHERE id = 1')->fetch(PDO::FETCH_ASSOC);
assert_same('Legacy Rule', (string)$legacySuppression['rule_name'], 'existing suppression-history rows must be preserved during schema upgrade');
assert_same('2026-07-13 11:00:00', (string)$legacySuppression['suppression_expires_at'], 'existing suppression-history expiry must be preserved during schema upgrade');
assert_same(null, $legacySuppression['cleared_at'], 'new suppression-history cleared_at column must default to NULL for preserved rows');

$upgradeModule->install();
assert_same(1, (int)$legacyDb->query('SELECT COUNT(*) FROM repeatcaller_incidents')->fetchColumn(), 'running the schema migration twice must not duplicate existing incident rows');
assert_same(1, (int)$legacyDb->query('SELECT COUNT(*) FROM repeatcaller_incident_suppression_history')->fetchColumn(), 'running the schema migration twice must not duplicate suppression-history rows');

assert_true(strpos($installSource, '$astetc . \'/repeatcaller_alert.conf\'') !== false, 'alert-call dialplan must be generated as a module-owned fragment, not by editing a FreePBX-generated file directly');
assert_true(strpos($installSource, '$astetc . \'/extensions_custom.conf\'') !== false, 'install hook may only add the module-owned include to extensions_custom.conf');
assert_true(strpos($installSource, 'repeatcallerInstallAgi()') !== false, 'install hook must deploy the module AGI script into the Asterisk AGI directory');
assert_true(strpos($installSource, 'ASTAGIDIR') !== false, 'install hook must resolve the target Asterisk AGI directory from FreePBX configuration');
assert_true(strpos($installSource, "'/repeatcaller_alert_response.php'") !== false, 'install hook must deploy the expected AGI filename');
assert_true(strpos($installSource, '@copy($sourceAgiPath, $deployedAgiPath)') !== false, 'install hook must copy the AGI source into the Asterisk AGI directory');
assert_true(strpos($installSource, '@chmod($deployedAgiPath, 0755)') !== false, 'install hook must explicitly enforce executable mode on the deployed AGI script');
assert_true(strpos($installSource, 'U(repeatcaller-alert-playback^${REPEATCALLER_PLAYBACK_TARGET}') !== false, 'originate dialplan must route answered calls through the module playback subroutine');
assert_true(strpos($installSource, 'Set(REPEATCALLER_ATTEMPT=1)') !== false, 'alert-call prompt loop must start at the first playback attempt');
assert_true(strpos($installSource, 'While($[${REPEATCALLER_ATTEMPT} <= 3])') === false, 'alert-call response loop must be finite without replaying the full incident message through a While structure');
assert_true(strpos($installSource, 'Read(REPEATCALLER_DTMF,,1,,1,10)') !== false, 'alert-call prompt loop must wait 10 seconds for one DTMF digit after each playback');
assert_true(strpos($installSource, 'GotoIf($["${REPEATCALLER_DTMF}"="1"]?accepted)') !== false, 'DTMF 1 must stop the retry loop and route to accepted handling');
assert_true(strpos($installSource, 'GotoIf($["${REPEATCALLER_DTMF}"="2"]?declined)') !== false, 'DTMF 2 must stop the retry loop and route to declined handling');
assert_true(strpos($installSource, 'Set(REPEATCALLER_RESPONSE_PHASE=recording)') !== false && strpos($installSource, 'Set(REPEATCALLER_RESPONSE_PHASE=summary)') !== false && strpos($installSource, 'Set(REPEATCALLER_RESPONSE_PHASE=menu)') !== false, 'alert-call dialplan must distinguish recording, summary, and menu phases across each full playback attempt');
assert_true(strpos($installSource, 'Set(REPEATCALLER_ATTEMPT=$[${REPEATCALLER_ATTEMPT} + 1])') !== false, 'invalid or missing DTMF must consume the current attempt before retrying');
assert_true(strpos($installSource, 'same => n(begin_attempt),Set(REPEATCALLER_DTMF=)') !== false, 'each attempt must restart from the beginning of the full alert');
assert_true(strpos($installSource, 'GotoIf($[${REPEATCALLER_ATTEMPT} >= 3]?no_response)') !== false, 'the third unsuccessful attempt must terminate through the no-response path instead of looping again');
assert_true(strpos($installSource, 'same => n,Goto(begin_attempt)') !== false, 'missing or invalid DTMF must restart the complete alert while attempts remain');
assert_true(strpos($installSource, 'same => n(no_response),AGI(__REPEATCALLER_AGI_SCRIPT__,${REPEATCALLER_ALERT_HISTORY_ID},${REPEATCALLER_INCIDENT_ID},answered_no_response,${REPEATCALLER_ALERT_RECIPIENT},${REPEATCALLER_DTMF})') !== false, 'after the third unsuccessful attempt, dialplan must record answered_no_response and hang up unclaimed');
assert_true(strpos($installSource, 'AGI(__REPEATCALLER_AGI_SCRIPT__,${REPEATCALLER_ALERT_HISTORY_ID},${REPEATCALLER_INCIDENT_ID},answered_no_response,${REPEATCALLER_ALERT_RECIPIENT},${REPEATCALLER_DTMF})') !== false, 'after the third response window expires, dialplan must record answered_no_response and hang up unclaimed via the deployed AGI-bin script');
assert_true(strpos($installSource, 'Background(auth-thankyou)') !== false && strpos($installSource, 'Background(goodbye)') !== false, 'accepted, declined, and third-attempt no-response terminal outcomes must play thank-you then goodbye');
assert_true(strpos($installSource, 'exten => h,1,GotoIf($["${REPEATCALLER_ALERT_COMPLETED}"="1"]?done)') !== false, 'hangup during the prompt loop must be recorded as answered_no_response only when no answered terminal outcome already completed');
assert_true(strpos($installSource, 'AGI(__REPEATCALLER_AGI_SCRIPT__,${REPEATCALLER_ALERT_HISTORY_ID},${REPEATCALLER_INCIDENT_ID},dialstatus,${REPEATCALLER_ALERT_RECIPIENT},${DIALSTATUS},${HANGUPCAUSE})') !== false, 'launch context must pass DIALSTATUS and HANGUPCAUSE to the AGI callback after Dial returns');
assert_true(strpos($installSource, "str_replace('__REPEATCALLER_AGI_SCRIPT__', \$agiScriptName") !== false, 'generated dialplan must reference the deployed AGI script name used in Asterisk AGI-bin');

$agiSource = file_get_contents($root . '/agi/repeatcaller_alert_response.php');
assert_true($agiSource !== false, 'alert-call AGI handler should be readable');
assert_true(strpos($agiSource, 'recordAlertCallDtmfResponse($historyId, $incidentId, $response, $recipient, $digit') !== false, 'AGI handler must route exact alert attempt context to the repository response handler');
assert_true(strpos($agiSource, 'function repeatcallerTryImmediateOrderedFollowUp(') !== false, 'AGI handler must include a focused immediate ordered follow-up bridge');
assert_true(strpos($agiSource, 'loadDeliverableCallAlertByHistoryId($nextHistoryId') !== false, 'AGI immediate bridge must load the newly reserved alert-call history row from repository state');
assert_true(strpos($agiSource, 'markCallAlertSending($nextHistoryId') !== false, 'AGI immediate bridge must reuse existing sending state transition');
assert_true(strpos($agiSource, 'sendAlertCall(') !== false, 'AGI immediate bridge must reuse existing module alert-call sender');
assert_true(strpos($agiSource, 'markCallAlertSent($nextHistoryId') !== false, 'AGI immediate bridge must reuse existing sent state transition on successful originate');
assert_true(strpos($agiSource, 'markCallAlertSnoozed($nextHistoryId') !== false, 'AGI immediate bridge must keep failed immediate sends deliverable for normal monitor retry');
assert_true(strpos($agiSource, 'repeatcallerTryImmediateOrderedFollowUp($repository, $moduleRoot, $context, $result, $now);') !== false, 'AGI handler must invoke the immediate ordered follow-up bridge after terminal callback persistence');
assert_true(strpos($agiSource, "in_array(\$response, ['accepted', 'declined', 'timeout', 'hangup', 'answered_no_response', 'dialstatus'], true)") !== false, 'AGI handler must allow known DTMF and dialstatus callback actions');
assert_true(strpos($agiSource, 'function repeatcallerResolveModuleRoot(): ?string') !== false, 'AGI handler must resolve the module root explicitly when executed from Asterisk AGI-bin');
assert_true(strpos($agiSource, "FreePBX::Config()->get('AMPWEBROOT')") !== false, 'AGI handler must use FreePBX bootstrap configuration to find the installed module path');
assert_true(strpos($agiSource, 'attempted_roots') !== false, 'AGI handler must log attempted resolver roots on module-path resolution failure');
assert_true(strpos($agiSource, "__DIR__ . '/../src/RepeatCallerRepository.php'") === false, 'AGI handler must not depend on its own directory being inside the module root when deployed to AGI-bin');
assert_true(strpos($agiSource, "repeatcallerAgiLog('repeatcaller AGI: DTMF response was not persisted:") !== false, 'AGI handler must log persistence failures with contextual details rather than failing silently');
assert_true(strpos($agiSource, 'REPEATCALLER_AGI_EXIT_PERSISTENCE_FAILED') !== false, 'AGI handler must return non-zero on persistence failures');

$agiContractTemp = sys_get_temp_dir() . '/repeatcaller_agi_contract_' . uniqid('', true);
assert_true(@mkdir($agiContractTemp, 0777, true), 'AGI contract should create a temporary workspace');

$agiRuntimeDir = $agiContractTemp . '/agi-bin';
$fakeWebRoot = $agiContractTemp . '/web';
$fakeModuleRoot = $fakeWebRoot . '/admin/modules/repeatcaller';
$fakeSrcDir = $fakeModuleRoot . '/src';
assert_true(@mkdir($agiRuntimeDir, 0777, true), 'AGI contract should create a non-module AGI runtime directory');
assert_true(@mkdir($fakeSrcDir, 0777, true), 'AGI contract should create a fake FreePBX module source directory');

$copiedAgiPath = $agiRuntimeDir . '/repeatcaller_alert_response.php';
assert_true(copy($root . '/agi/repeatcaller_alert_response.php', $copiedAgiPath), 'AGI contract should copy the AGI script into a non-module runtime directory');
@chmod($copiedAgiPath, 0755);

$bootstrapPath = $agiContractTemp . '/freepbx.conf';
$bootstrapSource = "<?php\n"
	. "\$GLOBALS['amp_conf'] = ['AMPWEBROOT' => '" . addslashes($fakeWebRoot) . "'];\n"
	. "class FreePBX {\n"
	. "    public static function Config() {\n"
	. "        return new class {\n"
	. "            public function get(\$key) {\n"
	. "                return \$key === 'AMPWEBROOT' ? '" . addslashes($fakeWebRoot) . "' : '';\n"
	. "            }\n"
	. "        };\n"
	. "    }\n"
	. "    public static function Database() { return null; }\n"
	. "}\n";
assert_true(file_put_contents($bootstrapPath, $bootstrapSource) !== false, 'AGI contract should write a fake FreePBX bootstrap file');

$repoStubPath = $fakeSrcDir . '/RepeatCallerRepository.php';
$repoStub = "<?php\n"
	. "namespace FreePBX\\modules\\Repeatcaller;\n"
	. "class RepeatCallerRepository {\n"
	. "    public function __construct(\$db) {}\n"
	. "    public function recordAlertCallDtmfResponse(\$historyId, \$incidentId, \$response, \$recipient, \$digit, \$now): array {\n"
	. "        return ['status' => true, 'message' => 'ok'];\n"
	. "    }\n"
	. "}\n";
assert_true(file_put_contents($repoStubPath, $repoStub) !== false, 'AGI contract should write a fake RepeatCallerRepository class');

$runAgi = function (string $scriptPath, string $bootstrap, string $args, ?array &$lines = null): int {
	$lines = [];
	$command = 'REPEATCALLER_AGI_BOOTSTRAP=' . escapeshellarg($bootstrap)
		. ' php ' . escapeshellarg($scriptPath)
		. ' ' . $args
		. ' 2>&1';
	exec($command, $lines, $status);
	return (int)$status;
};

$successOutput = [];
$successExit = $runAgi($copiedAgiPath, $bootstrapPath, '63 23 accepted 1112223333 1', $successOutput);
assert_same(0, $successExit, 'AGI execution from a non-module directory should succeed with exit code 0 when module resolution and persistence succeed');
assert_same('', trim(implode("\n", $successOutput)), 'successful AGI execution from a non-module directory should remain silent');

assert_true(@unlink($repoStubPath), 'AGI contract should remove the fake repository class to test missing-repository failure');
$failureOutput = [];
$failureExit = $runAgi($copiedAgiPath, $bootstrapPath, '63 23 accepted 1112223333 1', $failureOutput);
assert_true($failureExit !== 0, 'missing RepeatCallerRepository class should return non-zero from AGI execution');

$cleanupIterator = new RecursiveIteratorIterator(
	new RecursiveDirectoryIterator($agiContractTemp, FilesystemIterator::SKIP_DOTS),
	RecursiveIteratorIterator::CHILD_FIRST
);
foreach ($cleanupIterator as $pathInfo) {
	$path = $pathInfo->getPathname();
	if ($pathInfo->isDir()) {
		@rmdir($path);
	} else {
		@unlink($path);
	}
}
@rmdir($agiContractTemp);

$uninstallSource = file_get_contents($root . '/uninstall.php');
assert_true($uninstallSource !== false, 'uninstall.php should be readable');
assert_true(strpos($uninstallSource, 'repeatcallerRemoveAgi()') !== false, 'uninstall hook must remove the deployed AGI script');
assert_true(strpos($uninstallSource, "'/repeatcaller_alert_response.php'") !== false, 'uninstall hook must target the deployed AGI filename in Asterisk AGI-bin');

// --- 1b: MariaDB 5.5 compatibility -- utf8 (not utf8mb4) charset, and every
// index fits within the 767-byte InnoDB max key length (utf8 = 3 bytes/char,
// vs. utf8mb4's 4), assuming the conservative Antelope/COMPACT row format
// with innodb_large_prefix off, i.e. MariaDB 5.5's defaults with no reliance
// on ROW_FORMAT=DYNAMIC or innodb_large_prefix being enabled. --------------

foreach ($statements as $statement) {
	assert_true(strpos($statement, 'utf8mb4') === false, 'schema statements must not use utf8mb4 (exceeds the 767-byte key length limit on MariaDB 5.5 InnoDB defaults)');
	assert_true((bool)preg_match('/DEFAULT CHARSET=utf8(?:\s+COLLATE=utf8_\w+)?"?$/', trim($statement)), 'schema statements must declare an explicit MariaDB 5.5-compatible utf8 charset/collation');
}

assert_same(
	array_fill(0, count($statements), 0),
	array_map(function (string $statement): int {
		return count(indexesExceedingKeyLimit($statement, 3, 767));
	}, $statements),
	'every index in every table must fit within the 767-byte InnoDB key length limit under utf8 (3 bytes/char)'
);

// --- 2: fresh-install table names match production repository references -

// repeatcaller_settings is owned by Repeatcaller.class.php (getAlertSettings()/
// setSetting()); every other table is owned by the repository.
$referencedTables = [];
foreach (['/src/RepeatCallerRepository.php', '/Repeatcaller.class.php'] as $productionFile) {
	$source = file_get_contents($root . $productionFile);
	assert_true($source !== false, "{$productionFile} should be readable");
	preg_match_all('/\b(?:FROM|INTO|UPDATE|JOIN)\s+(repeatcaller_\w+)/', $source, $matches);
	$referencedTables = array_merge($referencedTables, $matches[1]);
}
$referencedTables = array_values(array_unique($referencedTables));
sort($referencedTables);
assert_same($sortedRequired, $referencedTables, 'every table read or written by production code must be exactly one of the schema-declared required tables (no orphaned or undeclared table)');

// --- 3: re-running install is idempotent and does not overwrite settings --
// (structural proof; the actual SQL was verified end-to-end against live
// MariaDB separately -- see commit/report notes)

$schemaSource = file_get_contents($root . '/src/Schema.php');
assert_true(strpos($schemaSource, 'INSERT IGNORE') !== false, 'default settings must be seeded with INSERT IGNORE so existing values are never overwritten by a repeat install');
assert_true(strpos($schemaSource, 'CREATE TABLE IF NOT EXISTS') !== false, 'table creation must be idempotent (IF NOT EXISTS)');
assert_true((bool)preg_match('/COUNT\(\*\).*information_schema\.COLUMNS/s', $schemaSource) || (bool)preg_match('/information_schema\.COLUMNS/', $schemaSource), 'guarded column migrations must check for existing columns before altering');

// --- 4: missing schema produces a bounded page error, not a raw PDO fault -

$pageDb = new PDO('sqlite::memory:');
$pageDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
FreePBX::$database = $pageDb;
$module = new Repeatcaller(new stdClass());

$page = $module->showPage();
assert_true(strpos($page, 'missing') !== false || strpos($page, 'Reinstall') !== false, 'a missing schema must render a clear operator-facing message');
assert_true(stripos($page, 'PDOException') === false, 'a missing schema must never expose the PDOException class name to the browser');
assert_true(stripos($page, 'SQLSTATE') === false, 'a missing schema must never expose raw SQL error detail to the browser');
assert_true(stripos($page, '.php:') === false && stripos($page, 'Stack trace') === false, 'a missing schema must never expose a stack trace to the browser');

// Note: Schema::isReady() queries information_schema.TABLES, which does not
// exist under SQLite regardless of which tables are present, so the
// "ready schema renders the normal page" side of this guard cannot be
// exercised here even after creating all ten tables -- isSchemaReady()
// correctly treats the resulting query failure as not-ready (fail closed).
// That behavior, and the true ready/positive path, were verified for real
// against live MariaDB 10.11.14 during development of this fix.

// --- 5: existing suites are expected to still pass; proven by the shared
// validation run (php tests/repeat_*.php), not re-implemented here.

// --- 6-7: uninstall tolerates current + inherited tables, no obsolete
// registration table is created on fresh install --------------------------

$obsoleteRegistrationTables = [
	'repeatcaller_alert_escalation',
	'repeatcaller_alert_history',
];
foreach ($obsoleteRegistrationTables as $legacyTable) {
	assert_true(!in_array($legacyTable, $createdTables, true), "fresh install must not create obsolete registration table '{$legacyTable}'");
}

assert_true(strpos($uninstallSource, 'Schema::REQUIRED_TABLES') !== false, 'uninstall.php must drop every current table via the shared Schema table list');
foreach ($obsoleteRegistrationTables as $legacyTable) {
	assert_true(strpos($uninstallSource, "'{$legacyTable}'") !== false, "uninstall.php must tolerate dropping inherited table '{$legacyTable}'");
}

echo "repeat install contract tests passed\n";

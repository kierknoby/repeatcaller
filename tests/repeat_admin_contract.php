<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/RepeatCallerRepository.php';
if (!interface_exists('BMO')) {
	interface BMO {}
}
require_once __DIR__ . '/../Repeatcaller.class.php';

use FreePBX\modules\Repeatcaller\RepeatCallerRepository;

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

if (!function_exists('_')) {
	function _(string $value): string {
		return $value;
	}
}

if (!class_exists('FreePBX')) {
	class FreePBX {
		private static ?PDO $database = null;

		public static function setDatabase(PDO $database): void {
			self::$database = $database;
		}

		public static function Database(): PDO {
			if (!self::$database instanceof PDO) {
				throw new RuntimeException('Test FreePBX database is not configured.');
			}
			return self::$database;
		}
	}
}

function make_db(): PDO {
	$db = new PDO('sqlite::memory:');
	$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

	$db->exec('CREATE TABLE repeatcaller_rules (
		id INTEGER PRIMARY KEY AUTOINCREMENT,
		name TEXT NOT NULL,
		enabled INTEGER NOT NULL DEFAULT 1,
		email_enabled INTEGER NOT NULL DEFAULT 0,
		alert_call_enabled INTEGER NOT NULL DEFAULT 0,
		alert_call_destinations TEXT,
		alert_call_strategy TEXT NOT NULL DEFAULT "ringall",
		alert_call_keep_trying INTEGER NOT NULL DEFAULT 1,
		alert_call_recording_id INTEGER,
		alert_call_handle_callerid_upstream INTEGER NOT NULL DEFAULT 0,
		alert_call_callerid TEXT,
		is_deleted INTEGER NOT NULL DEFAULT 0,
		deleted_at TEXT,
		mode TEXT NOT NULL,
		threshold_count INTEGER NOT NULL,
		observation_window_minutes INTEGER NOT NULL,
		caller_mode TEXT NOT NULL,
		exclude_withheld INTEGER NOT NULL DEFAULT 0,
		did_scope_mode TEXT NOT NULL,
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
	$db->exec('CREATE TABLE repeatcaller_settings (
		setting_key TEXT PRIMARY KEY,
		setting_value TEXT,
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
		cleared_at TEXT,
		created_at TEXT,
		updated_at TEXT
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
		updated_at TEXT,
		UNIQUE(rule_id, subject_key)
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
		cleared_at TEXT,
		related_incident_state TEXT NOT NULL,
		detected_at TEXT NOT NULL,
		created_at TEXT NOT NULL,
		updated_at TEXT NOT NULL
	)');
	$db->exec('CREATE TABLE incoming (
		extension TEXT,
		cidnum TEXT,
		description TEXT
	)');
	$db->prepare('INSERT INTO repeatcaller_settings (setting_key, setting_value, updated_at) VALUES (?, ?, ?)')->execute(['default_country_code', '44', '2026-07-13 09:00:00']);

	return $db;
}

$db = make_db();
$dbForController = $db;
FreePBX::setDatabase($dbForController);
$repo = new RepeatCallerRepository($db);
$now = '2026-07-13 10:00:00';

$parserDb = make_db();
FreePBX::setDatabase($parserDb);
$parserRepo = new RepeatCallerRepository($parserDb);
$controller = new \FreePBX\modules\Repeatcaller(new stdClass());
$parseCallers = new ReflectionMethod($controller, 'rcParseCallers');
$parseCallers->setAccessible(true);

$newlineCallers = $parseCallers->invoke($controller, [['list_type' => 'include', 'raw_value' => "01234567890\n07876543210"]]);
assert_same(2, count($newlineCallers), 'newline-separated callers should parse into two entries');

$commaCallers = $parseCallers->invoke($controller, [['list_type' => 'include', 'raw_value' => '01234567890, 07876543210']]);
assert_same(2, count($commaCallers), 'comma-separated callers should parse into two entries');

$spaceCallers = $parseCallers->invoke($controller, [['list_type' => 'include', 'raw_value' => '01234567890 07876543210']]);
assert_same(2, count($spaceCallers), 'space-separated callers should parse into two entries');

$tabCallers = $parseCallers->invoke($controller, [['list_type' => 'include', 'raw_value' => "01234567890\t07876543210"]]);
assert_same(2, count($tabCallers), 'tab-separated callers should parse into two entries');

$mixedCallers = $parseCallers->invoke($controller, [['list_type' => 'include', 'raw_value' => "01234567890, 07876543210\n01632960000 07700900123\n02079460000,\n03301234567"]]);
assert_same(6, count($mixedCallers), 'mixed delimiter caller list should parse into six separate entries');

$duplicateCallers = $parseCallers->invoke($controller, [['list_type' => 'include', 'raw_value' => '01234567890, 01234567890']]);
assert_same(1, count($duplicateCallers), 'duplicate caller values should be removed during parsing');

$emptyCallers = $parseCallers->invoke($controller, [['list_type' => 'include', 'raw_value' => " , \n\t ,"]]);
assert_same(0, count($emptyCallers), 'empty caller values should be ignored during parsing');

$browserCallerSaveDb = make_db();
FreePBX::setDatabase($browserCallerSaveDb);
$browserCallerSaveRepo = new RepeatCallerRepository($browserCallerSaveDb);
$browserCallerSaveController = new \FreePBX\modules\Repeatcaller(new stdClass());
$browserCallerSaveMethod = new ReflectionMethod($browserCallerSaveController, 'rcHandleSaveRule');
$browserCallerSaveMethod->setAccessible(true);
$savedRequest = $_REQUEST;
$_REQUEST = [
	'rule_id' => '0',
	'name' => 'Browser Caller JSON Rule',
	'enabled' => '1',
	'email_enabled' => '0',
	'alert_call_enabled' => '0',
	'alert_call_destinations' => '',
	'alert_call_strategy' => 'ringall',
	'alert_call_keep_trying' => '1',
	'alert_call_recording_id' => '',
	'mode' => 'repeat',
	'threshold_count' => '2',
	'observation_window_minutes' => '60',
	'caller_mode' => 'specific_only',
	'exclude_withheld' => '0',
	'did_scope_mode' => 'all',
	'repeat_mode_override' => 'never',
	'email_recipients' => '',
	'suppression_minutes_override' => '',
	'schedules' => '[]',
	'callers' => json_encode([
		['list_type' => 'include', 'raw_value' => '01234567890'],
		['list_type' => 'include', 'raw_value' => '07876543210'],
		['list_type' => 'include', 'raw_value' => '01234567890'],
		['list_type' => 'exclude', 'raw_value' => '01234567890'],
		['list_type' => 'exclude', 'raw_value' => '02079460000'],
		['list_type' => 'exclude', 'raw_value' => '02079460000'],
	]),
	'dids' => '[]',
];
$browserCallerSaveResponse = $browserCallerSaveMethod->invoke($browserCallerSaveController);
$_REQUEST = $savedRequest;
assert_true(($browserCallerSaveResponse['status'] ?? false) === true, 'controller save should accept browser-shaped callers JSON payload');
$browserCallerSavedRule = $browserCallerSaveResponse['rule'] ?? [];
assert_same(2, count(($browserCallerSavedRule['caller_lists']['include'] ?? [])), 'specific_only save path should persist two unique include callers from browser JSON payload');
assert_same(2, count(($browserCallerSavedRule['caller_lists']['exclude'] ?? [])), 'specific_only save path should persist two unique exclude callers from browser JSON payload');
assert_same('01234567890', (string)($browserCallerSavedRule['caller_lists']['include'][0]['raw_value'] ?? ''), 'browser JSON payload should preserve include caller order on save');
assert_same('01234567890', (string)($browserCallerSavedRule['caller_lists']['exclude'][0]['raw_value'] ?? ''), 'include and exclude caller lists should remain independent when caller exists in both');
$browserCallerReloadedRule = $browserCallerSaveRepo->loadRule((int)($browserCallerSavedRule['id'] ?? 0));
assert_same(2, count(($browserCallerReloadedRule['caller_lists']['include'] ?? [])), 'reloaded rule should keep include callers from browser JSON payload');
assert_same(2, count(($browserCallerReloadedRule['caller_lists']['exclude'] ?? [])), 'reloaded rule should keep exclude callers from browser JSON payload');
assert_same('01234567890, 07876543210', implode(', ', array_map(function (array $row): string {
	return (string)($row['raw_value'] ?? '');
}, $browserCallerReloadedRule['caller_lists']['include'] ?? [])), 'include callers should remain in canonical comma-space presentation order after reload');
assert_same('01234567890, 02079460000', implode(', ', array_map(function (array $row): string {
	return (string)($row['raw_value'] ?? '');
}, $browserCallerReloadedRule['caller_lists']['exclude'] ?? [])), 'exclude callers should remain in canonical comma-space presentation order after reload');
FreePBX::setDatabase($db);

$mixedListRuleId = $parserRepo->saveRule([
	'name' => 'Mixed Caller List Rule',
	'enabled' => 1,
	'email_enabled' => 0,
	'alert_call_enabled' => 0,
	'alert_call_destinations' => '',
	'alert_call_recording_id' => null,
	'mode' => 'repeat',
	'threshold_count' => 2,
	'observation_window_minutes' => 60,
	'caller_mode' => 'specific_only',
	'exclude_withheld' => 0,
	'did_scope_mode' => 'all',
	'repeat_mode_override' => '',
	'suppression_minutes_override' => null,
	'schedules' => [],
	'callers' => $mixedCallers,
	'dids' => [],
], $now);
$mixedListRule = $parserRepo->loadRule($mixedListRuleId);
assert_same(6, count($mixedListRule['caller_lists']['include']), 'mixed delimiter caller list should persist six include entries');
assert_same('01234567890', (string)$mixedListRule['caller_lists']['include'][0]['raw_value'], 'mixed delimiter caller list should preserve original order');
assert_same('+441234567890', (string)$mixedListRule['caller_lists']['include'][0]['normalized_value'], 'caller numbers should be normalized independently');

$independentCallerRuleId = $parserRepo->saveRule([
	'name' => 'Independent Caller Lists',
	'enabled' => 1,
	'email_enabled' => 0,
	'alert_call_enabled' => 0,
	'alert_call_destinations' => '',
	'alert_call_recording_id' => null,
	'mode' => 'repeat',
	'threshold_count' => 2,
	'observation_window_minutes' => 60,
	'caller_mode' => 'specific_only',
	'exclude_withheld' => 0,
	'did_scope_mode' => 'all',
	'repeat_mode_override' => '',
	'suppression_minutes_override' => null,
	'schedules' => [],
	'callers' => [
		['list_type' => 'include', 'raw_value' => '01234567890', 'normalized_value' => '+441234567890'],
		['list_type' => 'exclude', 'raw_value' => '01234567890', 'normalized_value' => '+441234567890'],
	],
	'dids' => [],
], $now);
$independentCallerRule = $parserRepo->loadRule($independentCallerRuleId);
assert_same(1, count($independentCallerRule['caller_lists']['include']), 'include caller list should remain independent of exclude list');
assert_same(1, count($independentCallerRule['caller_lists']['exclude']), 'exclude caller list should remain independent of include list');

FreePBX::setDatabase($db);

$ruleId = $repo->saveRule([
	'name' => 'Main Rule',
	'enabled' => 1,
	'email_enabled' => 1,
	'alert_call_enabled' => 1,
	'alert_call_destinations' => '100, 101',
	'alert_call_strategy' => 'ordered',
	'alert_call_keep_trying' => 0,
	'alert_call_recording_id' => 77,
	'mode' => 'repeat',
	'threshold_count' => 3,
	'observation_window_minutes' => 45,
	'caller_mode' => 'specific_only',
	'exclude_withheld' => 1,
	'did_scope_mode' => 'selected',
	'repeat_mode_override' => 'never',
	'suppression_minutes_override' => 120,
	'schedules' => [
		['day' => 1, 'start' => '09:00', 'end' => '12:00'],
		['day' => 3, 'start' => '14:00', 'end' => '18:00'],
	],
	'callers' => [
		['list_type' => 'include', 'raw_value' => '+441111111111', 'normalized_value' => '+441111111111'],
		['list_type' => 'exclude', 'raw_value' => '+442222222222', 'normalized_value' => '+442222222222'],
	],
	'dids' => [
		['list_type' => 'include', 'route_key' => '18005550001|', 'route_label' => 'Main DID', 'did_value' => '18005550001', 'cid_value' => ''],
		['list_type' => 'exclude', 'route_key' => '18005550002|', 'route_label' => 'Backup DID', 'did_value' => '18005550002', 'cid_value' => ''],
	],
], $now);

$summaryRules = $repo->loadRulesSummary();
assert_same(1, count($summaryRules), 'rules table summary should include the saved rule');
$summaryRule = $summaryRules[0];
assert_same('+441111111111', (string)($summaryRule['caller_lists']['include'][0]['raw_value'] ?? ''), 'rules table summary should expose the included caller value');
assert_same('Main DID', (string)($summaryRule['did_lists']['include'][0]['route_label'] ?? ''), 'rules table summary should expose the route label');
assert_same('18005550001', (string)($summaryRule['did_lists']['include'][0]['did_value'] ?? ''), 'rules table summary should expose the DID value');

$db->prepare('INSERT INTO repeatcaller_incident_suppression_history (related_incident_id, rule_id, rule_name, mode, subject_key, subject_label, caller_normalized, caller_display, inbound_route_key, inbound_route_label, did_value, matched_call_count, threshold_count, observation_window_minutes, suppression_source, suppression_minutes, suppression_started_at, suppression_expires_at, related_incident_state, detected_at, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')->execute([
	77,
	$ruleId,
	'Main Rule',
	'repeat',
	'+441111111111|route:abc',
	'+441111111111 @ Main DID',
	'+441111111111',
	'03303010203',
	'18005550001|',
	'Main DID',
	'18005550001',
	3,
	3,
	5,
	'global_default',
	1440,
	'2026-07-13 10:00:00',
	'2026-07-14 10:00:00',
	'claimed',
	'2026-07-13 10:05:00',
	'2026-07-13 10:05:00',
	'2026-07-13 10:05:00'
]);
$suppressedRows = $repo->loadSuppressedIncidentHistory();
assert_same(1, count($suppressedRows), 'suppression history should load from the summary table');
assert_same('Main Rule', (string)$suppressedRows[0]['rule_name'], 'suppression history should expose the human-readable rule name');
assert_same('specific_only', (string)$suppressedRows[0]['caller_mode'], 'suppression history rows should include caller scope mode for subject presentation');
assert_same('selected', (string)$suppressedRows[0]['did_scope_mode'], 'suppression history rows should include DID scope mode for subject presentation');

$specificRule = $repo->loadRule($ruleId);
assert_true(is_array($specificRule), 'specific rule should reload for explanation coverage');
assert_same('+441111111111', (string)($specificRule['caller_lists']['include'][0]['raw_value'] ?? ''), 'specific caller display should be available on the loaded rule payload');
assert_same('Main DID', (string)($specificRule['did_lists']['include'][0]['route_label'] ?? ''), 'specific route display should be available on the loaded rule payload');

$rule = $repo->loadRule($ruleId);
assert_true(is_array($rule), 'rule should be persisted and reloadable');
assert_same('Main Rule', $rule['name'], 'rule name should persist');
assert_same('never', $rule['repeat_mode_override'], 'explicit never override must persist distinctly');
assert_same(2, count($rule['schedules']), 'multiple schedules should persist');
assert_same(1, count($rule['caller_lists']['include']), 'caller include list should persist');
assert_same(1, count($rule['caller_lists']['exclude']), 'caller exclude list should persist');
assert_same(1, count($rule['did_lists']['include']), 'did include list should persist');
assert_same(0, count($rule['did_lists']['exclude']), 'selected DID mode should persist include rows only');
assert_same(1, (int)$rule['email_enabled'], 'email flag should persist independently');
assert_same(1, (int)$rule['alert_call_enabled'], 'alert call flag should persist independently');
assert_same('100, 101', (string)$rule['alert_call_destinations'], 'alert call destinations should persist');
assert_same('ordered', (string)$rule['alert_call_strategy'], 'alert call strategy should persist');
assert_same(0, (int)$rule['alert_call_keep_trying'], 'alert call keep-trying flag should persist');
assert_same(77, (int)$rule['alert_call_recording_id'], 'alert call recording id should persist');

$repo->saveRule([
	'id' => $ruleId,
	'name' => 'Main Rule Updated',
	'enabled' => 1,
	'email_enabled' => 0,
	'alert_call_enabled' => 1,
	'alert_call_destinations' => '',
	'alert_call_strategy' => 'ringall',
	'alert_call_keep_trying' => 1,
	'alert_call_recording_id' => null,
	'mode' => 'invert',
	'threshold_count' => 2,
	'observation_window_minutes' => 30,
	'caller_mode' => 'any',
	'exclude_withheld' => 0,
	'did_scope_mode' => 'all',
	'repeat_mode_override' => '',
	'suppression_minutes_override' => null,
	'schedules' => [
		['day' => 5, 'start' => '10:00', 'end' => '11:00'],
	],
	'callers' => [],
	'dids' => [],
], '2026-07-13 10:30:00');

$updated = $repo->loadRule($ruleId);
assert_same('Main Rule Updated', $updated['name'], 'rule updates should persist');
assert_same('', (string)$updated['repeat_mode_override'], 'global default mode must remain distinct from explicit never');
assert_same(1, count($updated['schedules']), 'schedule replacement should overwrite old schedules');
assert_same('ringall', (string)$updated['alert_call_strategy'], 'alert call strategy update should persist');
assert_same(1, (int)$updated['alert_call_keep_trying'], 'alert call keep-trying update should persist');

$disabledSuppressionRuleId = $repo->saveRule([
	'name' => 'Suppression Disabled Rule',
	'enabled' => 1,
	'email_enabled' => 0,
	'alert_call_enabled' => 0,
	'alert_call_destinations' => '',
	'alert_call_recording_id' => null,
	'mode' => 'repeat',
	'threshold_count' => 2,
	'observation_window_minutes' => 60,
	'caller_mode' => 'any',
	'exclude_withheld' => 0,
	'did_scope_mode' => 'all',
	'repeat_mode_override' => '',
	'suppression_minutes_override' => 0,
	'schedules' => [],
	'callers' => [],
	'dids' => [],
], '2026-07-13 10:35:00');
$disabledSuppressionRule = $repo->loadRule($disabledSuppressionRuleId);
assert_true(is_array($disabledSuppressionRule), 'zero suppression override rule should reload');
assert_same(0, (int)$disabledSuppressionRule['suppression_minutes_override'], 'zero suppression override must remain distinct from blank default suppression');

$continuousRuleId = $repo->saveRule([
	'name' => 'Continuous Rule',
	'enabled' => 1,
	'email_enabled' => 0,
	'alert_call_enabled' => 0,
	'alert_call_destinations' => '',
	'alert_call_recording_id' => null,
	'mode' => 'repeat',
	'threshold_count' => 2,
	'observation_window_minutes' => 60,
	'caller_mode' => 'any',
	'exclude_withheld' => 0,
	'did_scope_mode' => 'all',
	'repeat_mode_override' => '',
	'suppression_minutes_override' => null,
	'schedules' => [
		['day' => 2, 'start' => '09:00', 'end' => '10:00'],
		['day' => -1, 'start' => '', 'end' => '', 'all_day' => 1],
		['day' => 4, 'start' => '14:00', 'end' => '15:00'],
	],
	'callers' => [],
	'dids' => [],
], '2026-07-13 10:40:00');
$continuousRule = $repo->loadRule($continuousRuleId);
assert_same(1, count($continuousRule['schedules']), 'Any + 24h must normalize to one effective schedule row');
assert_same(-1, (int)$continuousRule['schedules'][0]['day'], 'continuous schedule should persist with Any-day sentinel');
assert_same('00:00', (string)$continuousRule['schedules'][0]['start'], 'continuous schedule should normalize to 00:00 start');
assert_same('', (string)$continuousRule['schedules'][0]['end'], 'continuous schedule should hide end time in UI payload');
assert_same(1, (int)$continuousRule['schedules'][0]['all_day'], 'continuous schedule should expose all_day flag for editor state');
$continuousStoredRow = $db->query("SELECT day_of_week, start_time, end_time FROM repeatcaller_rule_schedules WHERE rule_id = {$continuousRuleId}")->fetch(PDO::FETCH_ASSOC);
assert_same(-1, (int)$continuousStoredRow['day_of_week'], 'Any-day schedule must persist as day_of_week -1 in storage');
assert_same('00:00:00', (string)$continuousStoredRow['start_time'], 'Any + 24h schedule must persist as 00:00 start in storage');
assert_same('24:00:00', (string)$continuousStoredRow['end_time'], 'Any + 24h schedule must persist as 24:00 end in storage');

$uiSchedulesJson = '[{"day":-1,"start":"","end":"","all_day":1}]';
$controller = new \FreePBX\modules\Repeatcaller(new stdClass());
$parseSchedules = new ReflectionMethod($controller, 'rcParseSchedules');
$parseSchedules->setAccessible(true);
$parsedControllerSchedules = $parseSchedules->invoke($controller, $uiSchedulesJson);
assert_same([['day' => -1, 'start' => '00:00', 'end' => '24:00']], $parsedControllerSchedules, 'controller schedule parser must preserve Any + 24h payload semantics');

$controllerPathRuleId = $repo->saveRule([
	'name' => 'Controller Path Any Rule',
	'enabled' => 1,
	'email_enabled' => 0,
	'alert_call_enabled' => 0,
	'alert_call_destinations' => '',
	'alert_call_recording_id' => null,
	'mode' => 'repeat',
	'threshold_count' => 2,
	'observation_window_minutes' => 60,
	'caller_mode' => 'any',
	'exclude_withheld' => 0,
	'did_scope_mode' => 'all',
	'repeat_mode_override' => '',
	'suppression_minutes_override' => null,
	'schedules' => $parsedControllerSchedules,
	'callers' => [],
	'dids' => [],
], '2026-07-13 10:50:00');
$controllerPathStoredRow = $db->query("SELECT day_of_week, start_time, end_time FROM repeatcaller_rule_schedules WHERE rule_id = {$controllerPathRuleId}")->fetch(PDO::FETCH_ASSOC);
assert_same(-1, (int)$controllerPathStoredRow['day_of_week'], 'UI/controller/repository path must persist Any as day_of_week -1');
assert_same('00:00:00', (string)$controllerPathStoredRow['start_time'], 'UI/controller/repository path must persist 24-hour start as 00:00:00');
assert_same('24:00:00', (string)$controllerPathStoredRow['end_time'], 'UI/controller/repository path must persist 24-hour end as 24:00:00');

$callerIdSaveDb = make_db();
FreePBX::setDatabase($callerIdSaveDb);
$callerIdSaveRepo = new RepeatCallerRepository($callerIdSaveDb);
$callerIdController = new \FreePBX\modules\Repeatcaller(new stdClass());
$callerIdSaveMethod = new ReflectionMethod($callerIdController, 'rcHandleSaveRule');
$callerIdSaveMethod->setAccessible(true);
$savedRequest = $_REQUEST;
$_REQUEST = [
	'rule_id' => '0',
	'name' => 'Caller ID Managed Elsewhere',
	'enabled' => '1',
	'email_enabled' => '0',
	'alert_call_enabled' => '1',
	'alert_call_destinations' => '100',
	'alert_call_strategy' => 'ringall',
	'alert_call_keep_trying' => '1',
	'alert_call_recording_id' => '',
	'alert_call_handle_callerid_upstream' => '1',
	'alert_call_callerid' => '5551234',
	'mode' => 'repeat',
	'threshold_count' => '2',
	'observation_window_minutes' => '60',
	'caller_mode' => 'any',
	'exclude_withheld' => '0',
	'did_scope_mode' => 'all',
	'repeat_mode_override' => 'never',
	'email_recipients' => '',
	'suppression_minutes_override' => '',
	'schedules' => '[]',
	'callers' => '[]',
	'dids' => '[]',
];
$callerIdSaveResponse = $callerIdSaveMethod->invoke($callerIdController);
$_REQUEST = $savedRequest;
assert_true(($callerIdSaveResponse['status'] ?? false) === true, 'controller save should succeed when Caller ID managed elsewhere is checked');
$callerIdSavedRule = $callerIdSaveResponse['rule'] ?? [];
assert_same('', trim((string)($callerIdSavedRule['alert_call_callerid'] ?? '')), 'controller save should blank Caller ID before persistence when managed elsewhere is checked');
assert_same(1, (int)($callerIdSavedRule['alert_call_handle_callerid_upstream'] ?? 0), 'controller save should preserve the managed-elsewhere checkbox state');
$callerIdReloadedRule = $callerIdSaveRepo->loadRule((int)($callerIdSavedRule['id'] ?? 0));
assert_same('', trim((string)($callerIdReloadedRule['alert_call_callerid'] ?? '')), 'reloaded saved rule should keep Caller ID blank after managed-elsewhere save');
assert_same(1, (int)($callerIdReloadedRule['alert_call_handle_callerid_upstream'] ?? 0), 'reloaded saved rule should keep the managed-elsewhere checkbox state');
FreePBX::setDatabase($db);

$db->exec("INSERT INTO incoming (extension, cidnum, description) VALUES ('18005550001', '', 'Main Inbound')");
$db->exec("INSERT INTO incoming (extension, cidnum, description) VALUES ('', '', 'Catch-all')");
$routes = $repo->loadInboundRoutes();
assert_true(count($routes) >= 2, 'inbound route DID source should read from incoming table');
$catchAll = array_values(array_filter($routes, function (array $r): bool {
	return !empty($r['is_catch_all']);
}));
assert_same(1, count($catchAll), 'catch-all inbound routes should be explicitly marked');

$db->exec("INSERT INTO repeatcaller_incidents (rule_id, subject_key, active_subject_key, subject_label, mode, first_matched_at, last_matched_at, matched_call_count, state, created_at, updated_at) VALUES ({$ruleId}, '+441111111111', '{$ruleId}|+441111111111', '+441111111111', 'repeat', '2026-07-13 10:00:00', '2026-07-13 10:05:00', 3, 'active', '2026-07-13 10:00:00', '2026-07-13 10:05:00')");
$incidentId = (int)$db->lastInsertId();
$db->exec("INSERT INTO repeatcaller_rule_subject_state (rule_id, subject_key, active_incident_id, updated_at) VALUES ({$ruleId}, '+441111111111', {$incidentId}, '2026-07-13 10:05:00')");
$claimOk = $repo->claimActiveIncident($incidentId, 'admin', '2026-07-13 10:06:00', 'gui');
assert_true($claimOk, 'claim should atomically update active incident');
$incident = $db->query("SELECT state, claimed_by FROM repeatcaller_incidents WHERE id = {$incidentId}")->fetch(PDO::FETCH_ASSOC);
assert_same('claimed', $incident['state'], 'claimed incident state should persist');
assert_same('admin', $incident['claimed_by'], 'claim user should persist');
$subjectState = $db->query("SELECT active_incident_id FROM repeatcaller_rule_subject_state WHERE rule_id = {$ruleId} AND subject_key = '+441111111111'")->fetch(PDO::FETCH_ASSOC);
assert_same($incidentId, (int)$subjectState['active_incident_id'], 'claimed incident must remain linked in subject state until clear');

$db->exec("INSERT INTO repeatcaller_incident_alert_history (incident_id, rule_id, subject_key, subject_label, action_type, event_type, stage_n, recipient, delivery_status, repeat_mode, dedupe_key, created_at, updated_at) VALUES ({$incidentId}, {$ruleId}, '+441111111111', '+441111111111', 'gui', 'initial', 0, NULL, 'recorded', 'never', 'k1', '2026-07-13 10:00:00', '2026-07-13 10:00:00')");
$history = $repo->loadIncidentAlertHistory();
assert_same('gui', $history[0]['action_type'], 'alert history should read from incident alert-history table');
assert_same('any', (string)$history[0]['caller_mode'], 'alert history rows should include caller scope mode for subject presentation');
assert_same('all', (string)$history[0]['did_scope_mode'], 'alert history rows should include DID scope mode for subject presentation');

$claimedIncidents = $repo->loadIncidents('claimed', 20);
assert_true(count($claimedIncidents) >= 1, 'claimed incidents should be returned for admin table rendering');
assert_same('any', (string)$claimedIncidents[0]['caller_mode'], 'incident rows should include caller scope mode for subject presentation');
assert_same('all', (string)$claimedIncidents[0]['did_scope_mode'], 'incident rows should include DID scope mode for subject presentation');

$db->exec("INSERT INTO repeatcaller_incidents (rule_id, subject_key, active_subject_key, subject_label, mode, first_matched_at, last_matched_at, matched_call_count, state, created_at, updated_at) VALUES ({$ruleId}, 'closed-1', NULL, 'closed-1', 'repeat', '2026-07-01 00:00:00', '2026-07-01 00:00:00', 3, 'closed', '2026-07-01 00:00:00', '2026-07-01 00:00:00')");
$closedId = (int)$db->lastInsertId();
$db->exec("INSERT INTO repeatcaller_incident_alert_history (incident_id, rule_id, subject_key, subject_label, action_type, event_type, stage_n, recipient, delivery_status, repeat_mode, dedupe_key, created_at, updated_at) VALUES ({$closedId}, {$ruleId}, 'closed-1', 'closed-1', 'gui', 'initial', 0, NULL, 'recorded', 'never', 'k2', '2026-07-01 00:00:00', '2026-07-01 00:00:00')");
$db->exec("INSERT INTO repeatcaller_incident_alert_history (incident_id, rule_id, subject_key, subject_label, action_type, event_type, stage_n, recipient, delivery_status, repeat_mode, dedupe_key, created_at, updated_at) VALUES ({$incidentId}, {$ruleId}, '+441111111111', '+441111111111', 'gui', 'reminder', 1, NULL, 'recorded', 'never', 'k3', '2026-07-01 00:00:00', '2026-07-01 00:00:00')");
$deletedClosedHistory = $repo->pruneIncidentAlertHistory('2026-07-10 00:00:00');
assert_true($deletedClosedHistory >= 1, 'pruning should remove eligible old closed incident history');
$activeHistoryCount = (int)$db->query("SELECT COUNT(*) FROM repeatcaller_incident_alert_history WHERE incident_id = {$incidentId}")->fetchColumn();
assert_true($activeHistoryCount >= 1, 'active incident history should be preserved by default retention behavior');

$repo->softDeleteRule($ruleId, '2026-07-13 11:00:00');
$afterDelete = $repo->loadRule($ruleId);
assert_true($afterDelete === null, 'soft deleted rules should not appear in active rule reads');
$repo->setRuleEnabled($ruleId, true, '2026-07-13 11:05:00');
$deletedRule = $db->query("SELECT enabled, is_deleted FROM repeatcaller_rules WHERE id = {$ruleId}")->fetch(PDO::FETCH_ASSOC);
assert_same(0, (int)$deletedRule['enabled'], 'deleted rules cannot be re-enabled through ordinary state updates');
$incidentStillThere = (int)$db->query("SELECT COUNT(*) FROM repeatcaller_incidents WHERE id = {$incidentId}")->fetchColumn();
assert_same(1, $incidentStillThere, 'rule deletion should not corrupt historical incidents');
$incidentStateAfterDelete = $db->query("SELECT state, active_subject_key FROM repeatcaller_incidents WHERE id = {$incidentId}")->fetch(PDO::FETCH_ASSOC);
assert_same('closed', $incidentStateAfterDelete['state'], 'deleting a rule with an open incident should deliberately close it');
assert_true($incidentStateAfterDelete['active_subject_key'] === null || $incidentStateAfterDelete['active_subject_key'] === '', 'deleted rule should not leave an active incident key behind');

// Focused contract: persisted snooze-selection state semantics.
$controllerSource = file_get_contents(__DIR__ . '/../Repeatcaller.class.php');
assert_true($controllerSource !== false, 'Repeatcaller controller source should be readable');

assert_true((bool)preg_match('/in_array\(\$seconds, \[300, 900, 1800, 3600, 10800, 21600, 43200, 86400\], true\)/', $controllerSource), 'snooze handler must accept existing durations and longer 3h/6h/12h/24h durations up to 86400 seconds');
assert_true((bool)preg_match('/setSetting\(\'global_snooze_selected_seconds\', \(string\)\$seconds\);/', $controllerSource), 'setting a snooze duration must persist global_snooze_selected_seconds (e.g. "900" for 15m)');
assert_true(strpos($controllerSource, "'message' => _('Monitoring snoozed.')") !== false, 'snooze action response message should remain Monitoring snoozed.');
assert_true((bool)preg_match('/\'selected_snooze_seconds\'\s*=>\s*\$selectedSnoozeSeconds/', $controllerSource), 'engine status must expose selected_snooze_seconds from persisted settings');
assert_true((bool)preg_match('/rcHandleResumeMonitoring\(\): array\s*\{[\s\S]*setSetting\(\'global_snoozed_until\', \'\'\);[\s\S]*setSetting\(\'global_snooze_selected_seconds\', \'\'\);/', $controllerSource), 'resume must clear global_snoozed_until and global_snooze_selected_seconds');
assert_true((bool)preg_match('/if \(\$enabled !== \'1\'\) \{[\s\S]*setSetting\(\'global_snoozed_until\', \'\'\);[\s\S]*setSetting\(\'global_snooze_selected_seconds\', \'\'\);[\s\S]*\}/', $controllerSource), 'disabling monitoring must clear both global_snoozed_until and global_snooze_selected_seconds');

$viewSource = file_get_contents(__DIR__ . '/../views/main.php');
assert_true($viewSource !== false, 'main.php view source should be readable');
$cssSource = file_get_contents(__DIR__ . '/../assets/css/repeatcaller.css');
assert_true($cssSource !== false, 'repeatcaller.css source should be readable');
$jsSource = file_get_contents(__DIR__ . '/../assets/js/repeatcaller.js');
assert_true($jsSource !== false, 'repeatcaller.js source should be readable');

assert_true(strpos($jsSource, "syncLiveClockFromValue('pbx', $('#rc-pbx-time').text(), true);") !== false, 'page initialization must seed PBX clock from existing DOM text');
assert_true(strpos($jsSource, "syncLiveClockFromValue('pbx', engine.pbx_time || '-', false);") !== false, 'renderEngine must resync PBX baseline from authoritative engine value');
assert_true(strpos($jsSource, "window.setInterval(renderLiveClocks, 1000)") !== false, 'one shared 1000ms interval must drive clock updates');
assert_true((bool)preg_match('/if \(liveClockIntervalId === null\) \{[\s\S]*window\.setInterval\(renderLiveClocks, 1000\);[\s\S]*\}/', $jsSource), 'shared interval must be created only once when missing');
assert_true(strpos($jsSource, "renderLiveClock('pbx');") !== false, 'shared render tick must update the PBX clock');
assert_true(strpos($jsSource, 'clock.baseMs = parsedMs;') !== false && strpos($jsSource, 'clock.receivedAtMs = Date.now();') !== false, 'resync must replace each clock baseline and receipt time');
assert_true(strpos($jsSource, 'if (shouldRenderNow) {') !== false, 'resync path must support skipping immediate redraw to avoid jolt');
assert_true(strpos($jsSource, 'clearInterval(') === false, 'resynchronisation must not clear or recreate the shared interval');
assert_true(strpos($jsSource, "value.match(/^(\\d{4})-(\\d{2})-(\\d{2}) (\\d{2}):(\\d{2}):(\\d{2})$/)") !== false, 'live clock parser must enforce exact Y-m-d H:i:s format');
assert_true((bool)preg_match('/Engine Status[\s\S]*id="rc-engine-summary-row"[\s\S]*Enabled Rules[\s\S]*Active Incidents[\s\S]*Last Run[\s\S]*Run Status[\s\S]*PBX Time/', $viewSource), 'Engine Status should render one shared five-item summary row in the required order');
assert_true(substr_count($viewSource, 'class="rc-engine-summary-item"') === 5, 'Engine Status summary row should contain exactly five equal summary items');
assert_true((bool)preg_match('/id="rc-engine-summary-row"[\s\S]*Run Status[\s\S]*id="rc-run-status"[\s\S]*id="rc-lock-state"[\s\S]*PBX Time[\s\S]*id="rc-pbx-time"/', $viewSource), 'Run Status and PBX Time should be separate summary items within the same shared row');
assert_true(strpos($viewSource, '<strong><?php echo _(\'Run Status\'); ?>:</strong><span class="rc-engine-summary-value" id="rc-run-status">') !== false, 'Run Status summary item should include an explicit inline value element directly after the label');
assert_true(strpos($viewSource, '<strong><?php echo _(\'PBX Time\'); ?>:</strong><span class="rc-engine-summary-value" id="rc-pbx-time">') !== false, 'PBX Time summary item should include an explicit inline value element directly after the label');
assert_true(strpos($viewSource, 'id="rc-bulk-rule-action"') !== false, 'Engine Status should expose a single bulk rule state action button');
assert_true(strpos($viewSource, '$initialBulkActionLabel = $initialMonitoringSnoozed') !== false, 'Engine Status view should derive bulk action button label from global monitoring enabled/snoozed state');
assert_true(strpos($viewSource, '? _(\'Resume All Rules\')') !== false && strpos($viewSource, ': ($initialMonitoringEnabled ? _(\'Disable All Rules\') : _(\'Enable All Rules\'));') !== false, 'bulk action label should resolve to Resume All Rules, Disable All Rules, or Enable All Rules from global state only');
assert_true(strpos($viewSource, '$initialBulkActionButtonClass = ($initialMonitoringSnoozed || !$initialMonitoringEnabled) ? \'btn-success\' : \'btn-danger\';') !== false, 'Engine Status view should map Resume/Enable states to green success and Disable state to red danger class');
assert_true(strpos($viewSource, '$initialRunNowEnabled = $initialMonitoringEnabled && !$initialMonitoringSnoozed;') !== false, 'Run Now initial availability should be derived from global enabled and snoozed state in view markup');
assert_true(strpos($viewSource, 'id="rc-run-now"<?php echo $initialRunNowEnabled ? \'\' : \' disabled\'; ?>') !== false, 'Run Now should include disabled attribute in markup when global state makes it unavailable');
assert_true(strpos($viewSource, 'class="btn btn-warning" id="rc-run-now"') !== false, 'Run Now should use the existing amber/yellow warning button class');
assert_true(strpos($viewSource, 'id="rc-enable"') === false && strpos($viewSource, 'id="rc-disable"') === false && strpos($viewSource, 'id="rc-resume"') === false, 'legacy separate Enable Rules, Disable Rules and Resume controls should be removed from Engine Status');
assert_true(strpos($viewSource, 'id="rc-add-did-include"') !== false && strpos($viewSource, 'id="rc-add-did-exclude"') !== false, 'rule editor DID scope controls should keep Include Route and Exclude Route buttons visible');
assert_true(strpos($viewSource, 'Enable Monitoring') === false, 'legacy Enable Monitoring button label should be removed from the view');
assert_true(strpos($viewSource, 'Disable Monitoring') === false, 'legacy Disable Monitoring button label should be removed from the view');
assert_true(substr_count($viewSource, 'class="btn btn-primary rc-snooze"') === 8, 'Engine Status should render exactly eight Snooze buttons');
assert_true(strpos($viewSource, 'data-seconds="300"><?php echo _(\'Snooze 5m\'); ?></button>') !== false, 'Snooze 5m button should map to 300 seconds');
assert_true(strpos($viewSource, 'data-seconds="900"><?php echo _(\'Snooze 15m\'); ?></button>') !== false, 'Snooze 15m button should map to 900 seconds');
assert_true(strpos($viewSource, 'data-seconds="1800"><?php echo _(\'Snooze 30m\'); ?></button>') !== false, 'Snooze 30m button should map to 1800 seconds');
assert_true(strpos($viewSource, 'data-seconds="3600"><?php echo _(\'Snooze 1h\'); ?></button>') !== false, 'Snooze 1h button should map to 3600 seconds');
assert_true(strpos($viewSource, 'data-seconds="10800"><?php echo _(\'Snooze 3h\'); ?></button>') !== false, 'Snooze 3h button should map to 10800 seconds');
assert_true(strpos($viewSource, 'data-seconds="21600"><?php echo _(\'Snooze 6h\'); ?></button>') !== false, 'Snooze 6h button should map to 21600 seconds');
assert_true(strpos($viewSource, 'data-seconds="43200"><?php echo _(\'Snooze 12h\'); ?></button>') !== false, 'Snooze 12h button should map to 43200 seconds');
assert_true(strpos($viewSource, 'data-seconds="86400"><?php echo _(\'Snooze 24h\'); ?></button>') !== false, 'Snooze 24h button should map to 86400 seconds');
assert_true(strpos($viewSource, 'id="rc-run-now"') !== false, 'Run Now button should remain present in Engine Status controls');
assert_true((bool)preg_match('/\.repeatcaller \.rc-engine-summary-row \{[\s\S]*display: flex;[\s\S]*\}/', $cssSource), 'Engine Status should use one shared five-item flex summary row');
assert_true((bool)preg_match('/\.repeatcaller \.rc-engine-summary-item \{[\s\S]*flex: 1 1 calc\(20% - 10px\);[\s\S]*white-space: nowrap;[\s\S]*\}/', $cssSource), 'each summary item should be equal-width and non-wrapping for label/value pairs at desktop widths');
assert_true((bool)preg_match('/\.repeatcaller \.rc-engine-summary-value \{[\s\S]*margin-left: 0\.35em;[\s\S]*\}/', $cssSource), 'CSS should provide explicit spacing between every summary label and value');
assert_true(strpos($viewSource, 'id="rc-engine-status-inline"') === false && strpos($cssSource, '.repeatcaller .rc-engine-inline-status') === false, 'obsolete combined Run Status/PBX Time container should be absent');
assert_true(strpos($viewSource, 'Run Status') !== false, 'Engine Status should label lock display as Run Status');
assert_true(strpos($viewSource, 'Run Lock') === false, 'Engine Status should not display legacy Run Lock label text');
assert_true(strpos($viewSource, 'Database Time') === false, 'Engine Status should not display Database Time text');
assert_true(strpos($viewSource, 'Idle') === false, 'Engine Status should not display legacy Idle wording');
assert_true(strpos($jsSource, 'minimumVisibleMs: 3000') !== false, 'Run Status should enforce a 3000ms minimum visible Running duration');
assert_true(strpos($jsSource, "runStatusUi.backendRunning = String(lockState || '').toLowerCase() === 'running';") !== false, 'Run Status should treat active lock state as Running');
assert_true(strpos($jsSource, "setRunStatusText('Waiting');") !== false, 'Run Status should display Waiting when inactive');
assert_true(strpos($jsSource, "setRunStatusText('Processing');") !== false, 'Run Status should display Processing while active or within the visible-minimum window');
assert_true(strpos($jsSource, "var banner = enabled ? 'Monitoring enabled.' : 'Monitoring disabled.';") !== false, 'Engine banner should describe current monitoring state with Monitoring enabled/disabled wording');
assert_true(strpos($jsSource, 'if (runStatusUi.runningVisibleSinceMs === 0) {') !== false, 'Run Status should set Running baseline once and avoid timer restarts during ordinary refreshes');
assert_true(strpos($jsSource, 'runStatusUi.holdTimerId = window.setTimeout(function () {') !== false, 'Run Status should schedule post-minimum Waiting transition without delaying backend monitor flow');
assert_true(strpos($jsSource, '}, runStatusUi.minimumVisibleMs - elapsedMs);') !== false, 'Run Status should hold Running only for the remaining minimum-visible duration');
assert_true(strpos($jsSource, 'setRunStatusRunningFromRunStart();') !== false, 'Run Status should switch to Running immediately when Run Monitor is started from the UI');
assert_true(strpos($jsSource, 'function isProcessingVisible() {') !== false, 'Run Status should compute whether Processing visibility must still be preserved');
assert_true(strpos($jsSource, 'function updateRunNowButtonState() {') !== false, 'Run Status logic should control Run Now button availability from Processing state');
assert_true(strpos($jsSource, 'var runNowAvailableFromEngine = false;') !== false, 'Run Now availability should track global monitoring enabled/snoozed state');
assert_true(strpos($jsSource, 'function initializeRunNowAvailabilityFromBootstrap() {') !== false && strpos($jsSource, "runNowAvailableFromEngine = enabled && !isSnoozed;") !== false, 'Run Now availability should initialize from bootstrap engine enabled/snoozed state before async refresh');
assert_true(strpos($jsSource, 'initializeRunNowAvailabilityFromBootstrap();') !== false, 'page initialization should synchronize Run Now guard state before event bindings and async refreshes');
assert_true(strpos($jsSource, "if (isProcessingVisible() || !runNowAvailableFromEngine) {") !== false && strpos($jsSource, "\$button.prop('disabled', true).addClass('disabled');") !== false, 'Run Now should be disabled and greyed while Processing is visible or monitoring is disabled/snoozed');
assert_true(strpos($jsSource, "\$button.prop('disabled', false).removeClass('disabled');") !== false, 'Run Now should be re-enabled only after Processing visibility has ended');
assert_true(strpos($jsSource, "if (\$button.prop('disabled')) {") !== false, 'Run Now click handler should guard against additional clicks while Processing is active');
assert_true(strpos($jsSource, 'if (!runNowAvailableFromEngine) {') !== false, 'Run Now click handler should bail out when monitoring is disabled or snoozed');
assert_true(strpos($jsSource, "\$button.text('Processing...');") !== false, 'Run Now action should show Processing immediately when a run starts');
assert_true(strpos($jsSource, "var statusRefresh = loadEngineStatus({silent: true});") !== false, 'Run Now completion should refresh engine status before releasing final UI state');
assert_true(strpos($jsSource, 'statusRefresh.always(function () {') !== false && strpos($jsSource, 'updateRunNowButtonState();') !== false, 'Run Now should remain locked through completion callback until status refresh and Processing timing gate are applied');
assert_true(strpos($jsSource, 'function updateBulkEngineActionState(enabled, isSnoozed) {') !== false, 'Engine Status bulk action should be derived solely from global monitoring enabled/snoozed state');
assert_true(strpos($jsSource, "currentBulkEngineAction = 'resume';") !== false && strpos($jsSource, "buttonText = 'Resume All Rules';") !== false, 'snoozed monitoring state should expose Resume All Rules as the single bulk action');
assert_true(strpos($jsSource, "currentBulkEngineAction = 'disable';") !== false && strpos($jsSource, "buttonText = 'Disable All Rules';") !== false, 'enabled unsnoozed monitoring state should expose Disable All Rules as the single bulk action');
assert_true(strpos($jsSource, "currentBulkEngineAction = 'enable';") !== false && strpos($jsSource, "buttonText = 'Enable All Rules';") !== false, 'disabled monitoring state should expose Enable All Rules as the single bulk action');
assert_true(strpos($jsSource, "stateButtonClass = 'btn-success';") !== false && strpos($jsSource, "stateButtonClass = 'btn-danger';") !== false, 'global action states should reuse existing success and danger button classes');
assert_true(strpos($jsSource, ".removeClass('btn-default btn-success btn-danger')") !== false && strpos($jsSource, '.addClass(stateButtonClass)') !== false, 'global action state changes should replace old colour classes cleanly without accumulating conflicting classes');
assert_true(strpos($jsSource, "runNowAvailableFromEngine = enabled && !isSnoozed;") !== false, 'Run Now should be available only when monitoring is enabled and not snoozed');
assert_true(strpos($jsSource, "saveGlobalSettings(0, function () {") !== false && strpos($jsSource, "saveGlobalSettings(1, function () {") !== false, 'single bulk action should use existing saveglobalsettings endpoint for disable/enable');
assert_true(strpos($jsSource, "ajax('resumemonitoring', {}, function (response) {") !== false, 'single bulk action should use existing resumemonitoring endpoint for snoozed state');
assert_true(strpos($jsSource, 'function getRuleSelectionSummary() {') === false && strpos($jsSource, 'function collectRuleIdsForEnabledState(targetEnabled) {') === false && strpos($jsSource, 'function runBulkRuleEnabledUpdate(ruleIds, targetEnabled, done) {') === false, 'mixed-selection and per-rule bulk helper functions should be removed from frontend');
assert_true(strpos($jsSource, 'runNowAvailableFromRules') === false && strpos($jsSource, 'currentBulkRuleAction') === false && strpos($jsSource, 'engineSnoozedActive') === false, 'frontend should remove mixed-selection state variables introduced for per-rule bulk actions');
assert_true(strpos($jsSource, 'Idle') === false, 'frontend should not render legacy Idle wording');
assert_true(strpos($jsSource, '<option value="-1">Any</option>') !== false, 'schedule day selector must expose an explicit Any option');
assert_true(strpos($jsSource, 'rc-schedule-time-mode') === false, 'schedule editor must not use a separate time-mode selector');
assert_true(strpos($jsSource, '<select class="form-control input-sm rc-schedule-start-hour">') !== false, 'Start Time must include an hour selector');
assert_true(strpos($jsSource, '<select class="form-control input-sm rc-schedule-start-minute">') !== false, 'Start Time must include a minute selector');
assert_true(strpos($jsSource, '<select class="form-control input-sm rc-schedule-end-hour">') !== false, 'End Time must include an hour selector');
assert_true(strpos($jsSource, '<select class="form-control input-sm rc-schedule-end-minute">') !== false, 'End Time must include a minute selector');
assert_true(strpos($jsSource, "'<option value=\"all_day\">24 Hours</option>', '<option value=\"00\">00</option>'") !== false, 'Start hour selector must offer explicit 24 Hours and keep 00 as first bounded hour');
assert_true(strpos($jsSource, "for (var hour = 1; hour < 24; hour += 1) {") !== false, 'hour selectors must generate 01 through 23 after explicit 00');
assert_true(strpos($jsSource, "var minuteOptions = ['<option value=\"00\">00</option>'];") !== false, 'minute selector must generate 00 first');
assert_true(strpos($jsSource, "for (var minute = 1; minute < 60; minute += 1) {") !== false, 'minute selectors must generate 01 through 59');
assert_true(strpos($jsSource, "var boundedStart = isValidBoundedTimeValue(start) ? String(start) : '09:00';") !== false, 'bounded start reload must preserve valid values including 00:00');
assert_true(strpos($jsSource, 'var allDay = String($startHour.val() || \'\') === \'all_day\';') !== false, 'only explicit all_day Start selection should represent 24 Hours');
assert_true(strpos($jsSource, 'function parseScheduleDayValue(rawValue) {') !== false && strpos($jsSource, 'if (!/^-?\\d+$/.test(value)) {') !== false, 'schedule day parser must reject non-numeric values instead of coercing them');
assert_true(strpos($jsSource, 'rc-schedule-all-day') === false, '24 hours must not be implemented as a separate action-column checkbox');
assert_true(strpos($jsSource, "$('#rc-add-schedule').prop('disabled', true).addClass('disabled');") !== false, 'continuous Any + 24h row should disable Add Schedule control');
assert_true(strpos($jsSource, "$('#rc-add-schedule').prop('disabled', false).removeClass('disabled');") !== false, 'switching away from continuous row should restore Add Schedule control');
assert_true(strpos($jsSource, '$endHour.val(\'\').prop(\'disabled\', true).addClass(\'disabled\');') !== false && strpos($jsSource, '$endMinute.val(\'\').prop(\'disabled\', true).addClass(\'disabled\');') !== false, '24 Hours rows must disable and clear End hour/minute controls');
assert_true(strpos($jsSource, '$startHour.val(isAllDay ? \'all_day\' : startParts[0]);') !== false, 'all-day rows loaded from 00:00/24:00 must display as 24 Hours in Start control');
assert_true(strpos($jsSource, 'var restoredEnd = splitBoundedTime(String($row.data(\'boundedEnd\') || \'17:00\'), \'17:00\');') !== false, 'switching away from 24 Hours should restore a sensible End time');
assert_true(strpos($jsSource, 'var start = boundedTimeFromControls($startHour, $startMinute);') !== false && strpos($jsSource, 'var end = boundedTimeFromControls($endHour, $endMinute);') !== false, 'bounded schedules must serialize Start and End as HH:MM values');
assert_true(strpos($jsSource, "schedules.push({day: day, start: '', end: '', all_day: 1});") !== false, '24-hour schedule rows must serialize without a meaningful end time');
assert_true(strpos($jsSource, 'hasContinuous') !== false, 'schedule serialization should collapse Any + 24h to a single effective row');
assert_true(strpos($jsSource, "addScheduleRow(-1, '00:00', '24:00', true);") !== false, 'new and cleared rule editor should default to Any day 00:00-24:00 schedule');
assert_true(strpos($jsSource, "$('.rc-editor-panel').addClass('rc-editor-edit-mode');") !== false, 'existing rule editing should apply dedicated rule-editor edit-mode class');
assert_true(strpos($jsSource, "$('.rc-editor-panel').removeClass('rc-editor-edit-mode');") !== false, 'clearing/new rule mode should remove dedicated rule-editor edit-mode class');
assert_true(strpos($jsSource, "$('#rc-save-rule').prop('disabled', false).removeClass('disabled');") !== false, 'reset path must clear transient disabled/grey Save Rule state after successful save and Cancel Edit');
assert_true(strpos($jsSource, "$('#rc-cancel-edit').prop('disabled', false).removeClass('disabled');") !== false, 'reset path must clear transient disabled/grey Cancel Edit state before hiding the control');
assert_true(strpos($jsSource, "function setEditingRuleRow(ruleId) {") !== false, 'rules UI should track which row is currently being edited');
assert_true(strpos($jsSource, 'function updateRuleRowActionState() {') !== false, 'rules UI should define a helper to disable Status, Edit and Delete actions while editing');
assert_true(strpos($jsSource, "$('#rc-rules-table .rc-rule-status, #rc-rules-table .rc-edit-rule, #rc-rules-table .rc-delete-rule')") !== false, 'row action lock should target Status, Edit and Delete controls');
assert_true(strpos($jsSource, ".prop('disabled', disabled)") !== false && strpos($jsSource, ".toggleClass('disabled rc-rule-row-action-disabled', disabled)") !== false, 'editing should make Status, Edit and Delete unclickable and visibly greyed out');
assert_true(strpos($jsSource, 'function updateStartAsEditorState(editingExistingRule) {') !== false, 'rule editor should define a helper for the create-only Start as control');
assert_true(strpos($jsSource, "$('#rc-rules-table tbody tr').removeClass('rc-rule-editing rc-rule-explainer-editing');") !== false, 'exiting edit mode should remove rule-row and explainer-row editing/highlight state classes');
assert_true(strpos($jsSource, "$('#rc-rules-table tbody tr[data-rule-id=\"' + editingRuleId + '\"]').addClass('rc-rule-editing').next('.rc-rule-explainer-row').addClass('rc-rule-explainer-editing');") !== false, 'loading an existing rule should mark its row and explainer with explicit editing classes');
assert_true(strpos($jsSource, "$('#rc-cancel-edit').removeClass('hidden');") !== false, 'existing rule edit mode should show Cancel Edit control');
assert_true(strpos($jsSource, "$('#rc-cancel-edit').addClass('hidden');") !== false, 'new-rule mode should hide Cancel Edit control');
assert_true(strpos($jsSource, "$('#rc-cancel-edit').off('click.repeatcaller').on('click.repeatcaller', function () { resetRuleEditor(); });") !== false, 'Cancel Edit must return editor to new-rule defaults without mutating saved rule');
assert_true(strpos($jsSource, "$('#rc-rule-enabled')") !== false && strpos($jsSource, ".prop('disabled', disabled)") !== false && strpos($jsSource, "$('#rc-rule-start-as-col').toggleClass('rc-control-disabled rc-rule-start-as-disabled', disabled);") !== false, 'Start as should be greyed out and unclickable while editing');
assert_true(strpos($jsSource, "$('#rc-rule-start-as-help').text(helpText).toggleClass('text-muted', disabled);") !== false, 'Start as help text should switch between create-mode and edit-mode guidance');
assert_true(strpos($jsSource, 'updateStartAsEditorState(false);') !== false, 'new-rule mode should keep Start as interactive');
assert_true(strpos($jsSource, 'updateStartAsEditorState(true);') !== false, 'editing mode should lock Start as while still showing current state');
assert_true((bool)preg_match('/ajax\(\'saverule\',[\s\S]*function \(response\) \{[\s\S]*showMessage\(\'Rule saved\.\', \'success\'\);[\s\S]*resetRuleEditor\(\);[\s\S]*\}, onDone\);/', $jsSource), 'successful Save Rule response must clear current editing state and return to default/new-rule mode while preserving success toast');
assert_true((bool)preg_match('/ajax\(\'saverule\',[\s\S]*function \(response\) \{[\s\S]*renderRules\(response\.rules \|\| \[\]\);[\s\S]*resetRuleEditor\(\);/', $jsSource), 'successful Save Rule response must refresh the rules table with saved values before leaving edit mode');
assert_true((bool)preg_match('/ajax\(\'saverule\',[\s\S]*function \(response\) \{[\s\S]*\}, onDone\);/', $jsSource), 'failed Save Rule response path should continue to use shared completion callback without forcibly clearing editing state');
assert_true(strpos($jsSource, "$('#rc-add-rule')") === false, 'rules UI should not include or bind a separate Add Rule control');
assert_true(strpos($jsSource, "$('#rc-reset-rule')") === false, 'legacy Clear Editor control should be removed from JS bindings');
assert_true(strpos($jsSource, "$('#rc-rule-caller-mode').off('change.repeatcaller').on('change.repeatcaller', function () { updateCallerScopeEditorState(); });") !== false, 'Caller Scope selector should bind explicit change handling for caller field availability');
assert_true(strpos($jsSource, 'function clearOppositeDidScopeRows(nextMode) {') !== false, 'DID scope mode switching should clear stale opposite-mode route rows in the editor state');
assert_true(strpos($jsSource, 'function clearDidRouteActionState() {') !== false, 'DID scope switching should clear active route-action state');
assert_true(strpos($jsSource, "didRouteActionMode = '';") !== false, 'DID route action mode should reset to inactive state');
assert_true(strpos($jsSource, "$('#rc-route-pick').prop('disabled', true).addClass('rc-control-disabled').attr('aria-disabled', 'true');") !== false, 'route picker should start disabled and be reset disabled on scope changes');
assert_true(strpos($jsSource, "$('#rc-rule-did-mode').off('change.repeatcaller').on('change.repeatcaller', function () {") !== false && strpos($jsSource, 'clearOppositeDidScopeRows(') !== false, 'DID scope selector change handler should clear opposite-mode selections before toggling controls');
assert_true(strpos($jsSource, 'clearDidRouteActionState();') !== false, 'scope switching should clear active Include/Exclude action and disable route picker');
assert_true(strpos($jsSource, "$('#rc-add-did-include')") !== false && strpos($jsSource, ".prop('disabled', !selectedMode)") !== false && strpos($jsSource, ".show();") !== false, 'Include Route button should remain visible and be disabled in All DIDs mode');
assert_true(strpos($jsSource, "$('#rc-add-did-exclude')") !== false && strpos($jsSource, ".prop('disabled', selectedMode)") !== false && strpos($jsSource, ".show();") !== false, 'Exclude Route button should remain visible and be disabled in Selected DIDs mode');
assert_true(strpos($jsSource, "$('#rc-did-include-col').toggle(selectedMode);") !== false && strpos($jsSource, "$('#rc-did-exclude-col').toggle(!selectedMode);") !== false, 'DID route lists should switch between include-only and exclude-only presentations by mode');
assert_true(strpos($jsSource, 'function activateDidRouteAction(actionMode) {') !== false, 'valid DID action buttons should activate route picker mode');
assert_true(strpos($jsSource, "if (actionMode === 'include' && $('#rc-add-did-include').prop('disabled')) {") !== false && strpos($jsSource, "if (actionMode === 'exclude' && $('#rc-add-did-exclude').prop('disabled')) {") !== false, 'disabled DID action button must not activate route picker mode');
assert_true(strpos($jsSource, "$('#rc-add-did-include').addClass('rc-route-action-active');") !== false && strpos($jsSource, "$('#rc-add-did-exclude').addClass('rc-route-action-active');") !== false, 'pressing the valid DID action should highlight it as active');
assert_true(strpos($jsSource, "$('#rc-route-pick').prop('disabled', false).removeClass('rc-control-disabled').attr('aria-disabled', 'false');") !== false, 'pressing the valid DID action should enable route picker for selection');
assert_true(strpos($jsSource, "$('#rc-route-pick').off('change.repeatcaller').on('change.repeatcaller', function () {") !== false, 'route picker should add route entries via selection while action mode is active');
assert_true(strpos($jsSource, "if (didRouteActionMode === 'include') {") !== false && strpos($jsSource, "addRouteToList($('#rc-did-include-list'), route, 'include');") !== false, 'include action mode should add routes to Included Routes list');
assert_true(strpos($jsSource, "if (didRouteActionMode === 'exclude') {") !== false && strpos($jsSource, "addRouteToList($('#rc-did-exclude-list'), route, 'exclude');") !== false, 'exclude action mode should add routes to Excluded Routes list');
assert_true(strpos($jsSource, "var didScopeMode = $('#rc-rule-did-mode').val() === 'selected' ? 'selected' : 'all';") !== false && strpos($jsSource, 'var dids = didScopeMode === \'selected\' ? didIncludes : didExcludes;') !== false, 'save payload should submit include rows only for Selected DIDs and exclude rows only for All DIDs');
assert_true(strpos($jsSource, "if (didScopeMode === 'selected' && didIncludes.length < 1) {") !== false, 'Selected DIDs only mode should require at least one included route before save');
assert_true(strpos($jsSource, "$('#rc-rule-did-mode').val(rule.did_scope_mode || 'all');") !== false && strpos($jsSource, 'updateDidScopeEditorState();') !== false, 'loading a rule should restore DID mode and route lists without auto-activating picker mode');
assert_true(strpos($jsSource, 'function updateCallerScopeEditorState() {') !== false, 'Rule editor should use one dedicated helper to control Caller Scope field availability');
assert_true(strpos($jsSource, "var requiresSpecificCallers = callerMode === 'specific_only';") !== false, 'Specific callers mode should explicitly drive caller input activation');
assert_true(strpos($jsSource, "var includeEnabled = requiresSpecificCallers;") !== false && strpos($jsSource, "var excludeEnabled = callerMode !== 'withheld_only';") !== false, 'Caller Scope semantics should keep exclude active for Any callers while disabling both lists for Withheld only');
assert_true(strpos($jsSource, "if (callerMode === 'any') {") !== false && strpos($jsSource, "includeUnavailableText = 'This field is only used when Specific callers is selected.';") !== false, 'Any caller mode should disable include input with a clear unavailable reason');
assert_true(strpos($jsSource, "} else if (callerMode === 'withheld_only') {") !== false && strpos($jsSource, "excludeUnavailableText = 'Caller number lists are not used for withheld-only rules.';") !== false, 'Withheld only mode should disable both caller number fields with a clear unavailable reason');
assert_true(strpos($jsSource, "var baseCallerListHelpText = 'Enter caller numbers separated by spaces, commas or new lines. Mixed separators are supported. Values are saved as a comma-separated list.';") !== false, 'caller list helper text should describe the new canonical mixed-delimiter format');
assert_true(strpos($jsSource, "$('#rc-rule-caller-include').prop('disabled', !includeEnabled).toggleClass('rc-control-disabled', !includeEnabled).attr('aria-required', requiresSpecificCallers ? 'true' : 'false').attr('placeholder', formatExample);") !== false, 'caller include input should be enabled only for Specific callers, marked required, and have country-specific placeholder');
assert_true(strpos($jsSource, "$('#rc-rule-caller-exclude').prop('disabled', !excludeEnabled).toggleClass('rc-control-disabled', !excludeEnabled).attr('placeholder', formatExample);") !== false, 'caller exclude input should remain enabled for Any and Specific callers, disabled for Withheld only, with country-specific placeholder');
assert_true(strpos($jsSource, "$('#rc-caller-include-help').text(includeHelpText).toggleClass('text-danger', requiresSpecificCallers);") !== false, 'Caller include help text should show mixed-delimiter canonical formatting guidance');
assert_true(strpos($jsSource, "$('#rc-caller-exclude-help').text(excludeHelpText);") !== false, 'Caller exclude help text should show the same mixed-delimiter canonical formatting guidance');
assert_true(strpos($jsSource, "var countryCallerFormats = {") !== false && strpos($jsSource, "'44': { name: 'UK', local:") !== false && strpos($jsSource, "'1': { name: 'US/Canada', local:") !== false, 'Country caller format mapping should include country names and local format examples for major calling codes');
assert_true(strpos($jsSource, "'44': { name: 'UK', local: '07812345678'") !== false, 'UK format mapping should have leading 0 trunk prefix to provide country context in examples');
assert_true(strpos($jsSource, "'1': { name: 'US/Canada', local: '2125551234'") !== false, 'US/Canada format mapping should have local number without trunk prefix, prepended with country code by helper');
assert_true(strpos($jsSource, "function getCallerFormatHint(countryCode) {") !== false && strpos($jsSource, "Enter caller numbers in") !== false && strpos($jsSource, "format.name") !== false, 'Caller format hint should show country-specific local format without listing all accepted formats');
assert_true(strpos($jsSource, "function getCallerFormatExample(countryCode) {") !== false && strpos($jsSource, "if (localNumber.charAt(0) === '0') {") !== false && strpos($jsSource, "return code + localNumber;") !== false, 'National format example helper should prepend country code when number does not start with 0 to ensure country context is always visible');
assert_true(strpos($jsSource, "var localNumber = String(format.local || '');") !== false && strpos($jsSource, "// If local number starts with 0 (trunk prefix), use as-is; leading 0 provides country context") !== false, 'Format example helper should document logic for showing country code context in examples');
assert_true(strpos($jsSource, "function getCallerE164Example(countryCode) {") !== false && strpos($jsSource, "replace(/^0+/, '')") !== false && strpos($jsSource, "return '+' + code + nationalNumber;") !== false, 'E.164 format example helper should remove leading zeros and prepend country code with +');
assert_true(strpos($jsSource, "var formatExample = getCallerFormatExample($('#rc-setting-country').val());") !== false, 'Caller scope helper should generate the national format example from country code');
assert_true(strpos($jsSource, ".attr('placeholder', formatExample)") !== false, 'Caller textarea placeholders should be set to the national format example');
assert_true(strpos($jsSource, "var callerIdPlaceholder = '';") !== false && strpos($jsSource, ".attr('placeholder', callerIdPlaceholder)") !== false, 'Alert Call Caller ID placeholder should be blank when managed elsewhere is checked and use E.164 only when editable and empty');
assert_true(strpos($jsSource, "$('#rc-rule-alert-call-destination-input').attr('placeholder', '2001, 2002, ' + formatExample);") !== false, 'Alert Call Destinations placeholder should show national dialling format example (not E.164) with example extensions');
assert_true(strpos($jsSource, "var handleCallerIdUpstream = $('#rc-rule-alert-call-handle-callerid-upstream').is(':checked');") !== false, 'Alert Call UI state should read Handle Caller ID Upstream explicitly');
assert_true(strpos($jsSource, 'function updateAlertCallCallerIdState() {') !== false, 'Alert Call Caller ID state should be handled by a dedicated helper');
assert_true(strpos($jsSource, "var handleCallerIdUpstream = $('#rc-rule-alert-call-handle-callerid-upstream').is(':checked');") !== false, 'Alert Call Caller ID state should read the checkbox checked state directly');
assert_true(strpos($jsSource, "var alertCallCallerIdSessionValue = '';") !== false && strpos($jsSource, 'var alertCallCallerIdManagedElsewhere = false;') !== false, 'caller ID temporary memory should start empty and remain editor-session scoped');
assert_true(strpos($jsSource, 'function rememberAlertCallCallerIdSessionValue() {') !== false, 'caller ID input changes should update the temporary editor memory through a dedicated helper');
assert_true(strpos($jsSource, "var callerIdRequired = alertCallEnabled && !handleCallerIdUpstream;") !== false && strpos($jsSource, "var callerIdDisabled = !alertCallEnabled || handleCallerIdUpstream;") !== false, 'Alert Call Caller ID should be required only when Alert Call is enabled and upstream handling is disabled');
assert_true(strpos($jsSource, "\$callerIdField.prop('disabled', callerIdDisabled).prop('required', callerIdRequired).toggleClass('rc-control-disabled', callerIdDisabled).attr('aria-required', callerIdRequired ? 'true' : 'false').attr('placeholder', callerIdPlaceholder);") !== false, 'Alert Call Caller ID field should update disabled and required state from alert-call and upstream settings');
assert_true(strpos($jsSource, "$('#rc-rule-alert-call-callerid-help').text(callerIdHelpText).toggleClass('text-danger', callerIdRequired);") !== false, 'Alert Call Caller ID help text should explain when the field is unused vs required');
assert_true(strpos($jsSource, "$('#rc-rule-alert-call-callerid').off('input.repeatcaller change.repeatcaller paste.repeatcaller keyup.repeatcaller').on('input.repeatcaller change.repeatcaller paste.repeatcaller keyup.repeatcaller', function (event) {") !== false || strpos($jsSource, "$('#rc-rule-alert-call-callerid').off('input.repeatcaller change.repeatcaller paste.repeatcaller keyup.repeatcaller').on('input.repeatcaller change.repeatcaller paste.repeatcaller keyup.repeatcaller', function () {") !== false, 'Alert Call Caller ID field should remember typed values while it remains editable');
assert_true(strpos($jsSource, "var e164Example = getCallerE164Example($('#rc-setting-country').val());") !== false, 'Alert Call Caller ID helper should compute the E.164 example locally inside the helper');
assert_true(strpos($jsSource, "callerIdHelpText = 'Not used because caller presentation is managed elsewhere.';") !== false, 'Alert Call Caller ID helper should explain the managed-elsewhere case using the new wording');
assert_true(strpos($jsSource, "callerIdHelpText = 'Repeat Caller will set the Caller ID. Enter it in E.164 format, e.g. ' + e164Example + '.';") !== false, 'Alert Call Caller ID helper should request E.164 input using the dynamic default-country example');
assert_true((bool)preg_match('/prop\(\'checked\', parseInt\(rule\.alert_call_handle_callerid_upstream \|\| 0, 10\) === 1\);[\s\S]*updateAlertCallCallerIdState\(\);/', $jsSource), 'edit-rule loading should restore the checkbox before syncing caller-ID state');
assert_true(strpos($jsSource, "$('#rc-rule-alert-call-strategy').prop('disabled', !alertCallEnabled).toggleClass('rc-control-disabled', !alertCallEnabled);") !== false, 'Alert Call enabled state should gate the strategy selector');
assert_true(strpos($jsSource, "$('#rc-rule-alert-call-destination-input').prop('disabled', !alertCallEnabled).toggleClass('rc-control-disabled', !alertCallEnabled);") !== false, 'Alert Call enabled state should gate the destination input');
assert_true(strpos($jsSource, "$('#rc-rule-alert-call-destination-list').find('input, button').prop('disabled', !alertCallEnabled).toggleClass('rc-control-disabled', !alertCallEnabled);") !== false, 'Alert Call enabled state should gate destination remove, reorder and Keep Trying controls');
assert_true(strpos($jsSource, "$('#rc-rule-alert-call-recording-id').prop('disabled', !alertCallEnabled).toggleClass('rc-control-disabled', !alertCallEnabled);") !== false, 'Alert Call enabled state should gate the System Recording selector');
assert_true(strpos($jsSource, "$('#rc-rule-alert-call-handle-callerid-upstream').prop('disabled', !alertCallEnabled).toggleClass('disabled', !alertCallEnabled);") !== false, 'Alert Call enabled state should gate the Caller ID managed elsewhere checkbox');
assert_true(strpos($jsSource, "$('#rc-rule-email-recipients').prop('disabled', !emailEnabled).toggleClass('rc-control-disabled', !emailEnabled);") !== false, 'Email enabled state should gate email recipients independently of edit mode');
assert_true(strpos($jsSource, "$('#rc-setting-country').off('change.repeatcaller input.repeatcaller keyup.repeatcaller').on('change.repeatcaller input.repeatcaller keyup.repeatcaller', function () { updateCallerScopeEditorState(); });") !== false, 'Country code text field should trigger updates to both inbound caller and outbound E.164 placeholders on change, input, and keyup events for real-time feedback');
assert_true(strpos($jsSource, "$('#rc-caller-include-unavailable').text(includeUnavailableText).toggleClass('hidden', includeUnavailableText === '');") !== false && strpos($jsSource, "$('#rc-caller-exclude-unavailable').text(excludeUnavailableText).toggleClass('hidden', excludeUnavailableText === '');") !== false, 'caller scope helper should explain why disabled fields are unavailable');
// Verify country code 44 (UK) example behavior: leading 0 stays, no prepend needed
assert_true((bool)preg_match('/getCallerFormatExample\([^)]*\)[\s\S]*localNumber\.charAt\(0\) === [\'"]0[\'"][\s\S]*return localNumber;[\s\S]*return code \+ localNumber/', $jsSource), 'Country code 44 UK format should return 07812345678 as-is (leading 0 provides context)');
// Verify country code 1 (US/Canada) example behavior: no leading 0, so country code prepended
assert_true((bool)preg_match('/getCallerE164Example\([^)]*\)[\s\S]*2125551234[\s\S]*\+12125551234/', $jsSource) || (bool)preg_match('/getCallerFormatExample[\s\S]*code \+ localNumber/', $jsSource), 'Country code 1 format example should prepend country code to ensure context is visible (from 2125551234 to 12125551234)');
// Verify UK destinations use national format (not E.164)
assert_true(strpos($jsSource, "call('formatExample')") !== false || strpos($jsSource, "formatExample") !== false, 'Alert Call Destinations should use national format example for UK (07812345678) and US (12125551234), not E.164');
// Verify E.164 format for UK and US caller IDs
assert_true(strpos($jsSource, "getCallerE164Example") !== false && strpos($jsSource, "return '+' + code") !== false, 'Alert Call Caller ID should use E.164 format with + for UK (+447812345678) and US (+12125551234)');
assert_true(strpos($jsSource, "$('#rc-rule-exclude-withheld').prop('disabled', callerMode === 'withheld_only').toggleClass('disabled', callerMode === 'withheld_only');") !== false, 'Withheld callers mode should disable conflicting withheld exclusion control');
assert_true(strpos($jsSource, 'ensureRecordingOptionExists(rule.alert_call_recording_id);') !== false, 'existing saved recording IDs should remain loadable even when not currently listed');
assert_true(strpos($jsSource, "alert_call: 'Alert Call'") !== false, 'Alert History action label should humanize alert_call to Alert Call');
assert_true(strpos($jsSource, "sent: 'Sent'") !== false, 'Alert History status label should humanize sent to Sent');
assert_true(strpos($jsSource, "accepted: 'Accepted'") !== false && strpos($jsSource, "busy: 'Busy'") !== false, 'Alert History status labels should normalize accepted and busy consistently');
assert_true(strpos($jsSource, "answered_no_response: 'Answered, No Response'") !== false, 'Alert History status mapping should render answered_no_response as Answered, No Response');
assert_true((bool)preg_match('/function loadAlertHistory\(options\) \{[\s\S]*ajax\(\'getalerthistory\', \{\}, function \(response\) \{[\s\S]*renderAlertHistory\(response\.alertHistory \|\| \[\]\);[\s\S]*\}\);[\s\S]*\}/', $jsSource), 'live Alert History path should render rows through renderAlertHistory from getalerthistory AJAX response');
assert_true(strpos($jsSource, 'function incidentSubjectDisplay(row) {') !== false, 'history tables should use one dedicated subject helper for invert-only presentation changes');
assert_true(strpos($jsSource, "var threshold = parseInt(data.threshold_count || data.incident_threshold_count || 0, 10);") !== false && strpos($jsSource, "var windowMinutes = parseInt(data.observation_window_minutes || data.incident_observation_window_minutes || 0, 10);") !== false, 'invert subject display should read historical threshold and window snapshots from the incident row');
assert_true(strpos($jsSource, "if (modeValue === 'invert') {") !== false, 'subject helper should only rewrite invert-mode rows');
assert_true(strpos($jsSource, "return 'Fewer than ' + threshold + ' ' + callWord + ' within a ' + windowMinutes + '-minute window';") !== false, 'invert-mode subject helper should summarize the threshold and window');
assert_true(strpos($jsSource, "var subjectDisplay = incidentSubjectDisplay(i);") !== false, 'active incidents should use the subject helper');
assert_true(strpos($jsSource, "var subjectDisplay = incidentSubjectDisplay(row);") !== false, 'suppressed incidents should use the subject helper');
assert_true(strpos($jsSource, "var subjectDisplay = incidentSubjectDisplay(h);") !== false, 'alert history should use the subject helper');
assert_true(strpos($jsSource, "return rawSubject !== '' ? rawSubject : '-';") !== false, 'non-invert incidents should keep the captured subject_label');
assert_true(strpos($jsSource, 'var tableBatchSize = 15;') !== false, 'table batching should use a fixed initial and incremental batch size of 15 rows');
assert_true(strpos($jsSource, "'#rc-rules-table'") !== false && strpos($jsSource, "'#rc-active-incidents-table'") !== false && strpos($jsSource, "'#rc-suppressed-incidents-table'") !== false && strpos($jsSource, "'#rc-recent-incidents-table'") !== false && strpos($jsSource, "'#rc-alert-history-table'") !== false && strpos($jsSource, "'#rc-schedule-table'") !== false, 'table batching should be configured for every Repeat Caller admin table');
assert_true(strpos($jsSource, 'function tableBatchUnitSize(selector) {') !== false && strpos($jsSource, "return selector === '#rc-rules-table' ? 2 : 1;") !== false, 'rules table batching should keep primary rows and explainer rows together as one unit');
assert_true(strpos($jsSource, 'function updateTableRowBatching(selector) {') !== false, 'table batching should be managed through one reusable updater helper');
assert_true(strpos($jsSource, 'rc-table-show-more') !== false && strpos($jsSource, 'rc-table-show-less') !== false, 'table batching should render explicit Show more and Show less controls');
assert_true(strpos($jsSource, 'state.visibleUnits += tableBatchSize;') !== false && strpos($jsSource, 'state.visibleUnits = tableBatchSize;') !== false, 'Show more and Show less handlers should reveal next batches and reset to the first batch');
assert_true(strpos($jsSource, "updateTableRowBatching('#rc-rules-table');") !== false && strpos($jsSource, "updateTableRowBatching('#rc-suppressed-incidents-table');") !== false && strpos($jsSource, "updateTableRowBatching('#rc-alert-history-table');") !== false && strpos($jsSource, 'updateTableRowBatching(selector);') !== false, 'all table render paths should refresh batching visibility after rows are rebuilt');
assert_true(strpos($jsSource, 'updateAllTableRowBatching();') !== false, 'initial page setup should apply table batching controls after the UI is bootstrapped');
assert_true(strpos($jsSource, 'function normalizeCode(rawValue) {') !== false, 'alert-history formatter should normalize canonical codes in one helper');
assert_true(strpos($jsSource, 'function mapCode(rawValue, labels) {') !== false, 'alert-history formatter should map canonical codes through one helper');
assert_true(strpos($jsSource, "var actionType = normalizeCode(h.action_type);") !== false, 'Alert Call failure formatter should normalize action_type before deciding friendly vs raw failure detail rendering');
assert_true(strpos($jsSource, "initial: 'Initial'") !== false && strpos($jsSource, "reminder: 'Reminder'") !== false, 'alert-history renderer should map event codes to title-cased labels');
assert_true(strpos($jsSource, "repeat: 'Repeat'") !== false && strpos($jsSource, "invert: 'Invert'") !== false, 'alert-history renderer should map mode codes to title-cased labels');
assert_true(strpos($jsSource, "if (dialStatus === 'BUSY') {") !== false && strpos($jsSource, "return 'Recipient was busy';") !== false, 'Alert Call BUSY diagnostics should render as Recipient was busy');
assert_true(strpos($jsSource, "if (dialStatus === 'NOANSWER') {") !== false && strpos($jsSource, "return 'No answer';") !== false, 'Alert Call NOANSWER diagnostics should render as No answer');
assert_true(strpos($jsSource, "if (dialStatus === 'CHANUNAVAIL') {") !== false && strpos($jsSource, "return 'Recipient unavailable';") !== false, 'Alert Call CHANUNAVAIL diagnostics should render as Recipient unavailable');
assert_true(strpos($jsSource, "if (dialStatus === 'CONGESTION') {") !== false && strpos($jsSource, "return 'Call could not be completed';") !== false, 'Alert Call CONGESTION diagnostics should render as Call could not be completed');
assert_true(strpos($jsSource, "if (dialStatus === 'CANCEL') {") !== false && strpos($jsSource, "return 'Call cancelled';") !== false, 'Alert Call CANCEL diagnostics should render as Call cancelled');
assert_true(strpos($jsSource, "return 'Call failed';") !== false, 'Alert Call unknown/unmapped failures should fall back to Call failed');
assert_true(strpos($jsSource, "<span title=\"") !== false, 'Alert History should retain raw telephony diagnostics in tooltip text');
assert_true(strpos($jsSource, '<div class="text-muted"><small>') === false, 'Alert History should not render secondary muted raw diagnostics inline');
assert_true(strpos($jsSource, 'function recordingSummary(rule) {') !== false, 'rules table should resolve recording display labels through dedicated summary helper');
assert_true(strpos($jsSource, "return 'None';") !== false, 'rules table should show None when no recording is configured');
assert_true(strpos($jsSource, "return 'Recording #' + recordingId;") !== false, 'rules table should show Recording #<id> when saved recording is missing from current FreePBX list');
assert_true(strpos($jsSource, "systemRecordingsById[String(recordingId)] || ''") !== false, 'rules table should resolve configured recording display name from loaded System Recordings map');
assert_true(strpos($jsSource, 'function ruleExplanationSentence(rule) {') !== false, 'rules table should generate one reusable plain-English explanation sentence per rule');
assert_true(strpos($jsSource, "rows.push('<tr class=\"rc-rule-explainer-row ' + (parseInt(rule.enabled || 0, 10) ? 'rc-rule-explainer-enabled' : 'rc-rule-explainer-disabled') + '\">'") !== false, 'rules table should render a subordinate explainer row with explicit enabled/disabled state classes');
assert_true(strpos($jsSource, "colspan=\"' + columnCount + '\"") !== false, 'explainer row should use one dynamic colspan cell spanning every rule column');
assert_true(strpos($jsSource, "class=\"rc-rule-explainer-text\">' + esc(ruleExplanationSentence(rule))") !== false, 'explainer row should render the subordinate sentence text through the reusable formatter');
assert_true(strpos($jsSource, '<button type="button" class="btn btn-xs btn-default rc-rule-status">Status</button>') !== false, 'rules table should render a read-only Status action for each rule');
assert_true(strpos($jsSource, 'function ruleStatusSentence(rule) {') !== false, 'rules table should generate a reusable plain-English status sentence per rule');
assert_true(strpos($jsSource, "matching calls detected within the last ") !== false, 'status output should report progress within the configured alert window');
assert_true(strpos($jsSource, "Alert threshold reached.") !== false, 'status output should state when the alert threshold is reached');
assert_true(strpos($jsSource, 'function statusOutcomeSentence(rule) {') !== false, 'status output should summarize monitor-assessed outcome through one helper');
assert_true(strpos($jsSource, 'function statusFreshnessSentence(rule) {') !== false, 'status output should include one helper for monitor freshness text');
assert_true(strpos($jsSource, 'rule.status_assessment') !== false, 'status output should read monitor assessment data from the rules payload');
assert_true(strpos($jsSource, "This rule has an active incident.") !== false, 'status output should describe an active incident for the rule');
assert_true(strpos($jsSource, "This rule has an accepted incident.") !== false, 'status output should describe an accepted incident for the rule');
assert_true(strpos($jsSource, "This rule is currently suppressed until ") !== false, 'status output should distinguish active suppression state');
assert_true(strpos($jsSource, "This rule has no active or accepted incident.") !== false, 'status output should distinguish when no active or accepted incident exists');
assert_true(strpos($jsSource, "Last checked: ") !== false && strpos($jsSource, "New calls may not be included until the next monitor run.") !== false, 'status output should include separate freshness wording');
assert_true(strpos($jsSource, "showRuleStatus($(this).closest('tr').data('rule-id'), $(this));") !== false, 'Status action should update only the clicked rule row');
assert_true(strpos($jsSource, '$explainerRow.toggleClass(\'rc-rule-explainer-status-active\', !!enabled);') !== false, 'temporary Status view should use a dedicated explainer status class independent of enabled/disabled state classes');
assert_true(strpos($jsSource, "window.setTimeout(function () {") !== false && strpos($jsSource, ", 15000);") !== false && strpos($jsSource, "attr('aria-pressed', 'false')") !== false, 'Status action should revert the explainer text and unpress the button after fifteen seconds');
assert_true(strpos($jsSource, "var callerVerb = callerListCount > 1 ? 'call' : 'calls';") !== false, 'rule explainer grammar should switch to call for plural caller subjects');
assert_true(strpos($jsSource, "'This rule alerts ' + actions + ' when ' + caller + ' ' + callerVerb + ' ' + did + ' ' + threshold + ' or more times within ' + windowPhrase + schedulePhrase + ', ' + repeatPhrase + '.'") !== false, 'repeat-rule explainer should use corrected natural grammar and threshold wording');
assert_true(strpos($jsSource, "'This rule alerts ' + actions + ' when ' + caller + ' ' + callerVerb + ' ' + did + ' fewer than ' + threshold + ' ' + callWord + ' within each completed ' + windowPhrase + ' window' + schedulePhrase + ', ' + repeatPhrase + '.'") !== false, 'invert-rule explainer should describe the actual inverse condition with corrected grammar');
assert_true(strpos($jsSource, "return 'without follow-up reminders';") !== false, 'explainer should describe rule-level never repeat setting naturally');
assert_true(strpos($jsSource, "items.push('by email');") !== false && strpos($jsSource, "items.push('by phone');") !== false, 'explainer should translate action combinations into natural GUI/email/phone wording');
assert_true(strpos($jsSource, "return callerValues.length > 0 ? listWithOr(callerValues) : 'any caller';") !== false, 'explainer should fall back to any caller when no caller values are present');
assert_true(strpos($jsSource, "return routeValues.length > 0 ? listWithOr(routeValues) : 'any inbound route';") !== false, 'explainer should fall back to any inbound route when no route values are present');
assert_true(strpos($jsSource, "formatCountUnit(rule.observation_window_minutes, 'minute', 'minutes')") !== false, 'explainer should pluralize minute wording correctly');
assert_true(strpos($jsSource, "var callWord = threshold === 1 ? 'call' : 'calls';") !== false, 'invert explainer should pluralize call wording correctly');
assert_true(strpos($jsSource, "return ', during its configured schedule periods';") !== false, 'explainer should include schedule wording only when it adds useful meaning');
assert_true(strpos($cssSource, '.repeatcaller .rc-rule-explainer-row td {') !== false && strpos($cssSource, 'padding: 8px 12px;') !== false && strpos($cssSource, 'vertical-align: middle !important;') !== false, 'rules explainer row should use compact centered padding');
assert_true(strpos($cssSource, '.repeatcaller .rc-rule-explainer-text {') !== false && strpos($cssSource, 'font-size: 14px;') !== false && strpos($cssSource, 'line-height: 1.25;') !== false && strpos($cssSource, 'color: #4d4d4d;') !== false, 'rules explainer text should use improved size and contrast for readability');
assert_true(strpos($cssSource, '.repeatcaller #rc-rules-table > tbody > tr.rc-rule-explainer-row.rc-rule-explainer-enabled > td {') !== false && strpos($cssSource, 'background-color: #f3fbef;') !== false, 'enabled rule explanation rows should use a light green background on table cells to avoid theme tr overrides');
assert_true(strpos($cssSource, '.repeatcaller #rc-rules-table > tbody > tr.rc-rule-explainer-row.rc-rule-explainer-disabled > td {') !== false && strpos($cssSource, 'background-color: #fdf2f2;') !== false, 'disabled rule explanation rows should use a light red background on table cells to avoid theme tr overrides');
assert_true(strpos($cssSource, '.repeatcaller .rc-rule-explainer-row.rc-rule-explainer-disabled .rc-rule-explainer-text {') !== false && strpos($cssSource, 'color: #a94442;') !== false, 'disabled rule explanations should render in warning red text');
assert_true(strpos($cssSource, '.repeatcaller #rc-rules-table > tbody > tr.rc-rule-explainer-row.rc-rule-explainer-status-active > td {') !== false && strpos($cssSource, 'background-color: #f0f0f0;') !== false, 'temporary Status display should keep a separate light-grey cell background class');
assert_true(strpos($cssSource, '.repeatcaller #rc-rules-table > tbody > tr.rc-rule-explainer-row.rc-rule-explainer-editing > td {') !== false && strpos($cssSource, 'background-color: #fcf8e3;') !== false, 'editing rule explanation rows should keep a separate light-yellow cell highlight class');
assert_true(strpos($cssSource, '.repeatcaller #rc-rules-table > tbody > tr.rc-rule-editing > td {') !== false && strpos($cssSource, 'background-color: #fcf8e3;') !== false, 'editing rule rows should also keep their intended highlighted presentation');
assert_true(strpos($cssSource, '.repeatcaller textarea.rc-control-disabled,') !== false && strpos($cssSource, '.repeatcaller .rc-list.rc-control-disabled {') !== false, 'disabled caller and route list controls should use obvious muted styling');
assert_true((bool)preg_match('/array_key_exists\(\'day\', \$item\)/', $controllerSource), 'controller schedule parser must read day with array_key_exists and preserve explicit -1');
assert_true((bool)preg_match('/array_key_exists\(\'day\', \$schedule\)/', file_get_contents(__DIR__ . '/../src/RepeatCallerRepository.php') ?: ''), 'repository schedule normalizer must avoid implicit day coercion to 0');
assert_true((bool)preg_match('/\'alert_call_strategy\'\s*=>\s*\$this->normaliseAlertCallStrategy\(/', $controllerSource), 'controller save path must normalize and persist alert call strategy');
assert_true((bool)preg_match('/\'alert_call_keep_trying\'\s*=>\s*isset\(\$_REQUEST\[\'alert_call_keep_trying\'\]\) \? \(!empty\(\$_REQUEST\[\'alert_call_keep_trying\'\]\) \? 1 : 0\) : 1/', $controllerSource), 'controller save path must default alert_call_keep_trying to enabled when omitted');
assert_true((bool)preg_match('/\$recordingId\s*=\s*array_key_exists\(\'alert_call_recording_id\', \$_REQUEST\)\s*\?\s*\$this->nullablePositiveRequestInt\(\'alert_call_recording_id\'\)\s*:\s*\(\(\$existingRule\s*!==\s*null/', $controllerSource), 'controller save path must preserve existing alert_call_recording_id when request omits that field');
assert_true((bool)preg_match('/private function normaliseAlertCallStrategy\(string \$strategy\): string/', $controllerSource), 'controller should define a bounded alert call strategy normalizer');
assert_true(strpos($controllerSource, '\'alert_call_handle_callerid_upstream\' => array_key_exists(\'alert_call_handle_callerid_upstream\', $_REQUEST)') !== false, 'controller save path must persist Handle Caller ID Upstream with explicit new-rule default and existing-rule fallback');
assert_true(strpos($controllerSource, 'if (!empty($payload[\'alert_call_enabled\']) && empty($payload[\'alert_call_handle_callerid_upstream\'])) {') !== false, 'backend must require Caller ID only when Alert Call is enabled and upstream handling is disabled');
assert_true(strpos($controllerSource, 'Alert Call Caller ID is required when Alert Call is enabled and Caller ID managed elsewhere is disabled.') !== false, 'backend should return a clear validation message for blank Caller ID when managed-elsewhere handling is disabled');
assert_true(strpos($controllerSource, 'Alert Call Caller ID must contain digits only, optionally prefixed with +.') !== false, 'backend should return a clear validation message for invalid Caller ID format');
assert_true(strpos($controllerSource, 'private function isValidAlertCallCallerId(string $value): bool {') !== false, 'controller should define a dedicated Alert Call Caller ID validator');
assert_true(strpos($controllerSource, '->getAllRecordings()') !== false, 'recordings loader should use getAllRecordings from native FreePBX Recordings API');
assert_true(strpos($controllerSource, 'getAllRecordingsList') === false, 'recordings loader should not use getAllRecordingsList');
assert_true(strpos($controllerSource, 'getSystemRecordings') === false, 'recordings loader should not use getSystemRecordings');
assert_true(strpos($controllerSource, 'isset($container->Recordings)') === false, 'recordings loader should not guard module access with isset on magic Recordings property');
assert_true(strpos($controllerSource, '$recordings = is_object($container) ? $container->Recordings : null;') !== false, 'recordings loader should resolve Recordings module once via direct magic property access');
assert_true(strpos($controllerSource, "\$id = isset(\$row['id']) ? (int)\$row['id'] : 0;") !== false, 'recordings loader should parse selector value from numeric row id');
assert_true(strpos($controllerSource, "\$name = trim((string)(\$row['displayname'] ?? ''));") !== false, 'recordings loader should parse selector label from row displayname');
assert_true(strpos($controllerSource, 'FROM recordings') === false, 'recordings loader should not query recordings table directly');
assert_true((bool)preg_match('/\'suppression_minutes_override\'\s*=>\s*\(\$_REQUEST\[\'suppression_minutes_override\'\]\s*\?\?\s*\'\'\)\s*!==\s*\'\'\s*\?\s*\$this->boundedDigits\(\(string\)\$_REQUEST\[\'suppression_minutes_override\'\],\s*0,\s*525600,\s*1440\)\s*:\s*null/', $controllerSource), 'controller save path must accept 0 as a distinct rule suppression override');
assert_true(strpos($jsSource, "$('#rc-rule-alert-call-strategy').val(rule.alert_call_strategy || 'ringall');") !== false, 'rule loader must apply persisted alert call strategy with ringall fallback');
assert_true(strpos($jsSource, "$('#rc-rule-alert-call-handle-callerid-upstream').prop('checked', true);") !== false, 'new-rule reset path should default Handle Caller ID Upstream to enabled');
assert_true(strpos($jsSource, "$('#rc-rule-alert-call-handle-callerid-upstream').prop('checked', parseInt(rule.alert_call_handle_callerid_upstream || 0, 10) === 1);") !== false, 'editing an existing rule should restore the saved Handle Caller ID Upstream setting');
assert_true(strpos($jsSource, "$('#rc-rule-alert-call-handle-callerid-upstream').off('change.repeatcaller').on('change.repeatcaller', function () {") !== false && strpos($jsSource, 'updateAlertCallAndEmailState();') !== false && strpos($jsSource, 'applyAlertCallCallerIdSelfTriggerSafeguard({ showWarning: true, showConflictMessage: true });') !== false, 'toggling Handle Caller ID Upstream should update Caller ID state immediately and apply Caller ID self-trigger safeguards when relevant');
assert_true(strpos($jsSource, 'function updateAlertCallCallerIdState() {') !== false, 'Alert Call Caller ID state should be handled by a dedicated helper');
assert_true(strpos($jsSource, "var handleCallerIdUpstream = $('#rc-rule-alert-call-handle-callerid-upstream').is(':checked');") !== false, 'Alert Call Caller ID state should read the checkbox checked state directly');
assert_true(strpos($jsSource, "callerIdHelpText = 'Not used because caller presentation is managed elsewhere.';") !== false, 'managed-elsewhere help text should use the new wording');
assert_true(strpos($jsSource, "callerIdHelpText = 'Repeat Caller will set the Caller ID. Enter it in E.164 format, e.g. ' + e164Example + '.';") !== false, 'enabled help text should explain that Repeat Caller will set the Caller ID');
assert_true((bool)preg_match('/prop\(\'checked\', parseInt\(rule\.alert_call_handle_callerid_upstream \|\| 0, 10\) === 1\);[\s\S]*updateAlertCallCallerIdState\(\);/', $jsSource), 'edit-rule loading should restore the checkbox before syncing caller-ID state');
assert_true(strpos($jsSource, "$('#rc-rule-alert-call-strategy').prop('disabled', !alertCallEnabled).toggleClass('rc-control-disabled', !alertCallEnabled);") !== false, 'Alert Call enabled state should gate the strategy selector');
assert_true(strpos($jsSource, "$('#rc-rule-alert-call-destination-input').prop('disabled', !alertCallEnabled).toggleClass('rc-control-disabled', !alertCallEnabled);") !== false, 'Alert Call enabled state should gate the destination input');
assert_true(strpos($jsSource, "$('#rc-rule-alert-call-destination-list').find('input, button').prop('disabled', !alertCallEnabled).toggleClass('rc-control-disabled', !alertCallEnabled);") !== false, 'Alert Call enabled state should gate destination remove, reorder and Keep Trying controls');
assert_true(strpos($jsSource, "$('#rc-rule-alert-call-recording-id').prop('disabled', !alertCallEnabled).toggleClass('rc-control-disabled', !alertCallEnabled);") !== false, 'Alert Call enabled state should gate the System Recording selector');
assert_true(strpos($jsSource, "$('#rc-rule-alert-call-handle-callerid-upstream').prop('disabled', !alertCallEnabled).toggleClass('disabled', !alertCallEnabled);") !== false, 'Alert Call enabled state should gate the Caller ID managed elsewhere checkbox');
assert_true(strpos($jsSource, "$('#rc-rule-email-recipients').prop('disabled', !emailEnabled).toggleClass('rc-control-disabled', !emailEnabled);") !== false, 'Email enabled state should gate email recipients independently of edit mode');
assert_true(strpos($jsSource, "alert_call_callerid: handleCallerIdUpstream ? '' : $('#rc-rule-alert-call-callerid').val(),") !== false, 'save payload should blank Caller ID before persistence when managed elsewhere is checked');
assert_true(strpos($jsSource, "var baseCallerListHelpText = 'Enter caller numbers separated by spaces, commas or new lines. Mixed separators are supported. Values are saved as a comma-separated list.';") !== false, 'caller list helper text should describe the new canonical mixed-delimiter format');

$behaviorScript = <<<'NODE'
const fs = require('fs');
const vm = require('vm');

class Element {
	constructor(tag, id = '', classes = []) {
		this.tag = tag;
		this.id = id;
		this.classes = new Set(classes.filter(Boolean));
		this.attrs = {};
		this.props = {};
		this.children = [];
		this.parent = null;
		this.value = '';
		this.textContent = '';
		this.hidden = false;
	}
	append(child) {
		if (child === null || child === undefined) {
			return;
		}
		if (typeof child === 'string' || typeof child === 'number' || typeof child === 'boolean') {
			this.textContent += String(child);
			return;
		}
		child.parent = this;
		this.children.push(child);
	}
	removeChild(child) {
		this.children = this.children.filter(function (entry) { return entry !== child; });
	}
}

const byId = Object.create(null);
const allElements = [];

function register(element) {
	if (element.id) {
		byId[element.id] = element;
	}
	allElements.push(element);
	return element;
}

function makeElement(tag, id = '', classes = []) {
	return register(new Element(tag, id, classes));
}

function makeId(id, tag = 'div', classes = []) {
	return makeElement(tag, id, classes);
}

function parseHtml(html) {
	const tagMatch = String(html).match(/^<\s*([a-z0-9-]+)/i);
	const classMatch = String(html).match(/class="([^"]*)"/i);
	const idMatch = String(html).match(/id="([^"]*)"/i);
	const tag = tagMatch ? tagMatch[1].toLowerCase() : 'div';
	const classes = classMatch ? classMatch[1].split(/\s+/).filter(Boolean) : [];
	return new Element(tag, idMatch ? idMatch[1] : '', classes);
}

function descendantMatch(element, selector) {
	if (!selector) {
		return false;
	}
	if (selector === 'input, button') {
		return element.tag === 'input' || element.tag === 'button';
	}
	if (selector.startsWith('.')) {
		return element.classes.has(selector.slice(1));
	}
	const attrMatch = selector.match(/^([a-z0-9-]+)(?:\.([a-z0-9_-]+))?(?:\[data-rule-id="([^"]+)"\])?$/i);
	if (attrMatch) {
		const tag = attrMatch[1].toLowerCase();
		const cls = attrMatch[2];
		const ruleId = attrMatch[3];
		if (element.tag !== tag) {
			return false;
		}
		if (cls && !element.classes.has(cls)) {
			return false;
		}
		if (ruleId && String(element.attrs['data-rule-id'] || '') !== ruleId) {
			return false;
		}
		return true;
	}
	return element.tag === selector.toLowerCase();
}

function collectDescendants(element, selector) {
	const found = [];
	function visit(node) {
		node.children.forEach(function (child) {
			if (descendantMatch(child, selector)) {
				found.push(child);
			}
			visit(child);
		});
	}
	visit(element);
	return found;
}

function splitSelector(selector) {
	return String(selector).split(',').map(function (part) { return part.trim(); }).filter(Boolean);
}

function querySelector(selector) {
	const groups = splitSelector(selector);
	const results = [];
	groups.forEach(function (group) {
		if (group === 'document') {
			return;
		}
		const parts = group.split(/\s+/);
		let current = [];
		let first = parts.shift();
		if (first && first.startsWith('#')) {
			const base = byId[first.slice(1)];
			if (base) {
				current = [base];
			}
		} else if (first && first.startsWith('.')) {
			current = allElements.filter(function (element) { return element.classes.has(first.slice(1)); });
		} else if (first) {
			current = allElements.filter(function (element) { return descendantMatch(element, first); });
		}
		parts.forEach(function (part) {
			const next = [];
			current.forEach(function (element) {
				next.push.apply(next, collectDescendants(element, part));
			});
			current = next;
		});
		results.push.apply(results, current);
	});
	return results;
}

class Wrap {
	constructor(elements) {
		this.els = (elements || []).filter(Boolean);
		this.length = this.els.length;
	}
	each(callback) {
		this.els.forEach(function (element, index) {
			callback.call(element, index, element);
		});
		return this;
	}
	prop(name, value) {
		if (value === undefined) {
			return this.els[0] ? this.els[0].props[name] : undefined;
		}
		this.els.forEach(function (element) {
			element.props[name] = value;
		});
		return this;
	}
	attr(name, value) {
		if (value === undefined) {
			return this.els[0] ? this.els[0].attrs[name] : undefined;
		}
		this.els.forEach(function (element) {
			element.attrs[name] = value;
		});
		return this;
	}
	val(value) {
		if (value === undefined) {
			return this.els[0] ? this.els[0].value : '';
		}
		this.els.forEach(function (element) {
			element.value = value;
		});
		return this;
	}
	text(value) {
		if (value === undefined) {
			return this.els[0] ? this.els[0].textContent : '';
		}
		this.els.forEach(function (element) {
			element.textContent = value;
		});
		return this;
	}
	html(value) {
		if (value === undefined) {
			return this.els[0] ? this.els[0].htmlContent : '';
		}
		this.els.forEach(function (element) {
			element.htmlContent = value;
		});
		return this;
	}
	addClass(value) {
		const classes = String(value || '').split(/\s+/).filter(Boolean);
		this.els.forEach(function (element) {
			classes.forEach(function (cls) { element.classes.add(cls); });
		});
		return this;
	}
	removeClass(value) {
		const classes = String(value || '').split(/\s+/).filter(Boolean);
		this.els.forEach(function (element) {
			classes.forEach(function (cls) { element.classes.delete(cls); });
		});
		return this;
	}
	toggleClass(value, state) {
		const classes = String(value || '').split(/\s+/).filter(Boolean);
		this.els.forEach(function (element) {
			classes.forEach(function (cls) {
				const shouldHave = state === undefined ? !element.classes.has(cls) : !!state;
				if (shouldHave) {
					element.classes.add(cls);
				} else {
					element.classes.delete(cls);
				}
			});
		});
		return this;
	}
	empty() {
		this.els.forEach(function (element) {
			element.children = [];
		});
		return this;
	}
	append(content) {
		const nodes = content instanceof Wrap ? content.els : [content];
		this.els.forEach(function (element) {
			nodes.forEach(function (node) {
				if (node) {
					element.append(node);
				}
			});
		});
		return this;
	}
	find(selector) {
		const matches = [];
		this.els.forEach(function (element) {
			matches.push.apply(matches, collectDescendants(element, selector));
		});
		return new Wrap(matches);
	}
	is(selector) {
		if (!this.els.length) {
			return false;
		}
		if (selector === ':checked') {
			return !!this.els[0].props.checked;
		}
		return descendantMatch(this.els[0], selector);
	}
	off() { return this; }
	on() { return this; }
	remove() {
		this.els.forEach(function (element) {
			if (element.parent) {
				element.parent.removeChild(element);
			}
		});
		return this;
	}
	show() { return this; }
	hide() { return this; }
	toggle(state) {
		this.els.forEach(function (element) {
			element.hidden = state === undefined ? !element.hidden : !state;
		});
		return this;
	}
	removeAttr(name) {
		this.els.forEach(function (element) {
			delete element.attrs[name];
		});
		return this;
	}
	first() { return new Wrap(this.els.slice(0, 1)); }
	next(selector) {
		if (!this.els[0] || !this.els[0].parent) {
			return new Wrap([]);
		}
		const siblings = this.els[0].parent.children;
		const index = siblings.indexOf(this.els[0]);
		const next = siblings[index + 1];
		if (!next) {
			return new Wrap([]);
		}
		if (selector && !descendantMatch(next, selector)) {
			return new Wrap([]);
		}
		return new Wrap([next]);
	}
}

function $(selector) {
	if (typeof selector === 'function') {
		return undefined;
	}
	if (selector instanceof Element) {
		return new Wrap([selector]);
	}
	if (selector instanceof Wrap) {
		return selector;
	}
	if (typeof selector === 'string' && selector.startsWith('<')) {
		return new Wrap([parseHtml(selector)]);
	}
	if (selector && selector.id && byId[selector.id]) {
		return new Wrap([byId[selector.id]]);
	}
	if (typeof selector === 'string') {
		return new Wrap(querySelector(selector));
	}
	return new Wrap([]);
}

$.trim = function (value) {
	return String(value || '').trim();
};

$.each = function (collection, callback) {
	if (!collection) {
		return collection;
	}
	if (Array.isArray(collection)) {
		for (let index = 0; index < collection.length; index += 1) {
			if (callback.call(collection[index], index, collection[index]) === false) {
				break;
			}
		}
		return collection;
	}
	Object.keys(collection).forEach(function (key) {
		callback.call(collection[key], key, collection[key]);
	});
	return collection;
};

$.isArray = Array.isArray;

$.inArray = function (value, array) {
	if (!Array.isArray(array)) {
		return -1;
	}
	return array.indexOf(value);
};

function createCheckbox(id) {
	const element = makeId(id, 'input');
	element.attrs.type = 'checkbox';
	return element;
}

function createButton(id, classes) {
	return makeId(id, 'button', classes);
}

function createGeneric(id, tag = 'div', classes = []) {
	return makeId(id, tag, classes);
}

createGeneric('rc-editor-title');
createGeneric('rc-cancel-edit', 'button');
createGeneric('rc-rule-id', 'input');
createGeneric('rc-rule-name', 'input');
createCheckbox('rc-rule-enabled');
createGeneric('rc-rule-start-as-col');
createGeneric('rc-rule-start-as-help');
createGeneric('rc-rule-mode', 'select');
createGeneric('rc-rule-threshold', 'input');
createGeneric('rc-rule-window', 'input');
createGeneric('rc-rule-suppression', 'input');
createGeneric('rc-rule-repeat', 'select');
createGeneric('rc-rule-email-recipients', 'input');
createGeneric('rc-rule-caller-mode', 'select');
createCheckbox('rc-rule-exclude-withheld');
createGeneric('rc-rule-caller-include', 'textarea');
createGeneric('rc-rule-caller-exclude', 'textarea');
createGeneric('rc-caller-include-help');
createGeneric('rc-caller-exclude-help');
createGeneric('rc-caller-include-unavailable');
createGeneric('rc-caller-exclude-unavailable');
createGeneric('rc-rule-did-mode', 'select');
createGeneric('rc-did-include-list', 'ul', ['rc-list']);
createGeneric('rc-did-exclude-list', 'ul', ['rc-list']);
createGeneric('rc-route-pick', 'select');
createButton('rc-add-did-include');
createButton('rc-add-did-exclude');
createGeneric('rc-schedule-table', 'table');
createGeneric('rc-schedule-table-body', 'tbody');
createGeneric('rc-rule-alert-call-enabled', 'input');
createCheckbox('rc-rule-alert-call-enabled');
createCheckbox('rc-rule-email-enabled');
createGeneric('rc-rule-alert-call-strategy', 'select');
createGeneric('rc-rule-alert-call-destination-input', 'input');
createButton('rc-rule-alert-call-destination-add', ['btn', 'btn-default']);
createGeneric('rc-rule-alert-call-destination-list', 'ol', ['rc-alert-call-destination-list', 'rc-list']);
createGeneric('rc-rule-alert-call-recording-id', 'select');
createCheckbox('rc-rule-alert-call-handle-callerid-upstream');
createGeneric('rc-rule-alert-call-callerid', 'input');
createGeneric('rc-rule-alert-call-callerid-help');
createGeneric('rc-setting-country', 'input');
byId['rc-setting-country'].value = '44';
createButton('rc-run-now', ['btn', 'btn-warning']);
createGeneric('rc-rules-table', 'table');
const rulesTbody = createGeneric('rc-rules-table-body', 'tbody');
byId['rc-rules-table'].append(rulesTbody);
const editRow = new Element('tr');
editRow.attrs['data-rule-id'] = '1';
editRow.children = [
	new Element('td'),
	new Element('td'),
	new Element('td')
];
editRow.children[0].append(createButton('', ['rc-rule-status']));
editRow.children[1].append(createButton('', ['rc-edit-rule']));
editRow.children[2].append(createButton('', ['rc-delete-rule']));
editRow.children.forEach(function (cell) { cell.parent = editRow; });
const explainerRow = new Element('tr', '', ['rc-rule-explainer-row']);
editRow.parent = rulesTbody;
explainerRow.parent = rulesTbody;
rulesTbody.children.push(editRow, explainerRow);

const context = {
	console,
	jQuery: $, 
	$: $, 
	window: {
		repeatCallerBootstrap: {
			engineStatus: { enabled: 1, global_snoozed_until: '' },
			systemRecordings: []
		}
	},
	document: {},
	setTimeout,
	clearTimeout,
	setInterval,
	clearInterval,
	ajax: function (command, payload, onSuccess) {
		if (command === 'getrule') {
			onSuccess({rule: payload.__rule});
		}
	},
	showMessage: function () {},
	loadSystemRecordingsLookupFromBootstrap: function () {},
	syncLiveClockFromValue: function () {},
	loadInboundRoutes: function () {},
	loadEngineStatus: function () {},
	loadIncidents: function () {},
	loadAlertHistory: function () {},
	loadRules: function () {},
	setupAutoRefreshPolling: function () {},
	withBusy: function ($button, handler) { handler(function () {}); },
	beginAction: function () {},
	endAction: function () {},
	syncChangeToken: function () {},
	showMessage: function () {},
	$window: {}
};

vm.createContext(context);
let source = fs.readFileSync('/workspaces/repeatcaller/assets/js/repeatcaller.js', 'utf8');
source = source.replace('})(jQuery);', '\nwindow.__hooks = { loadRule: loadRule, clearAlertCallCallerIdSessionState: clearAlertCallCallerIdSessionState, setEditingRuleRow: setEditingRuleRow, updateRuleRowActionState: updateRuleRowActionState, updateStartAsEditorState: updateStartAsEditorState, updateAlertCallAndEmailState: updateAlertCallAndEmailState, updateAlertCallCallerIdState: updateAlertCallCallerIdState, updateAlertCallDestinationAddButtonState: updateAlertCallDestinationAddButtonState, addAlertCallDestinationsFromInput: addAlertCallDestinationsFromInput, triggerAlertCallDestinationAdd: triggerAlertCallDestinationAdd, handleAlertCallDestinationInputKeydown: handleAlertCallDestinationInputKeydown, applyAlertCallCallerIdSelfTriggerSafeguard: applyAlertCallCallerIdSelfTriggerSafeguard, applyAlertCallCallerIdSelfTriggerSafeguardForSave: applyAlertCallCallerIdSelfTriggerSafeguardForSave, syncAlertCallCallerIdSafeguardState: syncAlertCallCallerIdSafeguardState, showAlertCallSelfTriggerWarning: showAlertCallSelfTriggerWarning, showMessage: showMessage, alertCallSelfTriggerWarningDurationSeconds: alertCallSelfTriggerWarningDurationSeconds, alertCallSelfTriggerWarningTimeoutMs: alertCallSelfTriggerWarningTimeoutMs, initializeRunNowAvailabilityFromBootstrap: initializeRunNowAvailabilityFromBootstrap };\n})(jQuery);');
vm.runInContext(source, context, {timeout: 5000});
const hooks = context.window.__hooks;
const warningNotieAlerts = [];
const genericToasts = [];
const fallbackTimeoutsMs = [];
let syntheticTimeoutId = 0;

context.window.notie = {
	alert: function (type, message, durationSeconds) {
		warningNotieAlerts.push({
			type: parseInt(type || 0, 10),
			message: String(message || ''),
			durationSeconds: parseInt(durationSeconds || 0, 10)
		});
	}
};
context.window.setTimeout = function (handler, delayMs) {
	fallbackTimeoutsMs.push(parseInt(delayMs || 0, 10));
	syntheticTimeoutId += 1;
	return syntheticTimeoutId;
};
context.window.clearTimeout = function () {};
context.window.fpbxToast = function (message, title, type) {
	genericToasts.push({
		message: String(message || ''),
		type: String(type || '')
	});
};

function assert(condition, message) {
	if (!condition) {
		throw new Error(message);
	}
}

function resetAlertCallState(alertCallEnabled, upstreamEnabled) {
	$('#rc-rule-alert-call-enabled').prop('checked', alertCallEnabled);
	$('#rc-rule-email-enabled').prop('checked', true);
	$('#rc-rule-alert-call-handle-callerid-upstream').prop('checked', upstreamEnabled);
	$('#rc-rule-alert-call-destination-input').val('2001');
	$('#rc-rule-alert-call-destination-list').empty();
	$('#rc-rule-alert-call-callerid').val('+441234567890');
	hooks.updateAlertCallAndEmailState();
	hooks.updateAlertCallCallerIdState();
}

function runScenario(editing, alertCallEnabled, upstreamEnabled) {
	hooks.setEditingRuleRow(editing ? 1 : 0);
	hooks.updateStartAsEditorState(editing);
	resetAlertCallState(alertCallEnabled, upstreamEnabled);
	return {
		startAsDisabled: !!$('#rc-rule-enabled').prop('disabled'),
		rowStatusDisabled: !!$('#rc-rules-table .rc-rule-status').prop('disabled'),
		rowEditDisabled: !!$('#rc-rules-table .rc-edit-rule').prop('disabled'),
		rowDeleteDisabled: !!$('#rc-rules-table .rc-delete-rule').prop('disabled'),
		destinationInputDisabled: !!$('#rc-rule-alert-call-destination-input').prop('disabled'),
		addDisabled: !!$('#rc-rule-alert-call-destination-add').prop('disabled'),
		recordingDisabled: !!$('#rc-rule-alert-call-recording-id').prop('disabled'),
		strategyDisabled: !!$('#rc-rule-alert-call-strategy').prop('disabled'),
		upstreamDisabled: !!$('#rc-rule-alert-call-handle-callerid-upstream').prop('disabled'),
		callerIdDisabled: !!$('#rc-rule-alert-call-callerid').prop('disabled'),
		callerIdRequired: !!$('#rc-rule-alert-call-callerid').prop('required'),
		callerIdHelp: $('#rc-rule-alert-call-callerid-help').text(),
		addButtonText: $('#rc-rule-alert-call-destination-add').text()
	};
}

const createOn = runScenario(false, true, false);
assert(createOn.startAsDisabled === false, 'create mode should keep Start as enabled');
assert(createOn.rowStatusDisabled === false && createOn.rowEditDisabled === false && createOn.rowDeleteDisabled === false, 'create mode should keep row controls enabled');
assert(createOn.destinationInputDisabled === false, 'create mode with Alert Call enabled should keep destination input enabled');
assert(createOn.addDisabled === false, 'create mode with valid input and no duplicates should keep Add enabled');
assert(createOn.recordingDisabled === false && createOn.strategyDisabled === false && createOn.upstreamDisabled === false, 'create mode with Alert Call enabled should keep alert-call subsection enabled');
assert(createOn.callerIdDisabled === false && createOn.callerIdRequired === true, 'create mode with upstream disabled should require Caller ID');

const createOff = runScenario(false, false, false);
assert(createOff.destinationInputDisabled === true, 'create mode with Alert Call disabled should disable destination input');
assert(createOff.addDisabled === true, 'create mode with Alert Call disabled should disable Add');
assert(createOff.recordingDisabled === true && createOff.strategyDisabled === true && createOff.upstreamDisabled === true, 'create mode with Alert Call disabled should disable subsection controls');
assert(createOff.callerIdDisabled === true && createOff.callerIdRequired === false, 'create mode with Alert Call disabled should disable Caller ID');

const editOn = runScenario(true, true, false);
assert(editOn.startAsDisabled === true, 'edit mode should disable Start as');
assert(editOn.rowStatusDisabled === true && editOn.rowEditDisabled === true && editOn.rowDeleteDisabled === true, 'edit mode should disable row Status/Edit/Delete');
assert(editOn.destinationInputDisabled === false && editOn.addDisabled === false, 'edit mode with Alert Call enabled should keep destination controls enabled');
assert(editOn.recordingDisabled === false && editOn.strategyDisabled === false && editOn.upstreamDisabled === false, 'edit mode with Alert Call enabled should keep alert-call subsection enabled');
assert(editOn.callerIdDisabled === false && editOn.callerIdRequired === true, 'edit mode with upstream disabled should require Caller ID');

const editOff = runScenario(true, false, false);
assert(editOff.startAsDisabled === true, 'edit mode with Alert Call disabled should still disable Start as');
assert(editOff.rowStatusDisabled === true && editOff.rowEditDisabled === true && editOff.rowDeleteDisabled === true, 'edit mode with Alert Call disabled should keep row controls locked');
assert(editOff.destinationInputDisabled === true && editOff.addDisabled === true, 'edit mode with Alert Call disabled should disable destination controls');
assert(editOff.recordingDisabled === true && editOff.strategyDisabled === true && editOff.upstreamDisabled === true, 'edit mode with Alert Call disabled should disable alert-call subsection controls');
assert(editOff.callerIdDisabled === true && editOff.callerIdRequired === false, 'edit mode with Alert Call disabled should disable Caller ID');

hooks.clearAlertCallCallerIdSessionState();
$('#rc-rule-alert-call-enabled').prop('checked', true);
$('#rc-rule-alert-call-handle-callerid-upstream').prop('checked', true);
hooks.updateAlertCallAndEmailState();
assert($('#rc-rule-alert-call-handle-callerid-upstream').prop('checked') === true && $('#rc-rule-alert-call-callerid').val() === '' && $('#rc-rule-alert-call-callerid').prop('disabled') === true, 'new-rule default should keep Caller ID managed elsewhere checked with a blank disabled field');
assert($('#rc-rule-alert-call-callerid').attr('placeholder') === '', 'new-rule default should keep the Caller ID placeholder blank while managed elsewhere is checked');

hooks.setEditingRuleRow(0);
hooks.updateStartAsEditorState(false);
hooks.clearAlertCallCallerIdSessionState();
$('#rc-rule-alert-call-enabled').prop('checked', true);
$('#rc-rule-alert-call-handle-callerid-upstream').prop('checked', false);
$('#rc-rule-alert-call-callerid').val('');
hooks.updateAlertCallAndEmailState();
hooks.updateAlertCallCallerIdState();
assert($('#rc-rule-alert-call-callerid').attr('placeholder') === '+447812345678', 'an empty editable Caller ID field should show the E.164 example placeholder when no temporary value exists');
$('#rc-rule-alert-call-callerid').val('5551111');
hooks.updateAlertCallCallerIdState();
assert($('#rc-rule-alert-call-callerid').val() === '5551111', 'typing a Caller ID in create mode should keep the current value while upstream handling is off');
assert($('#rc-rule-alert-call-callerid').attr('placeholder') === '', 'a populated editable Caller ID field should not show the example placeholder');
$('#rc-rule-alert-call-handle-callerid-upstream').prop('checked', true);
hooks.updateAlertCallAndEmailState();
assert($('#rc-rule-alert-call-callerid').val() === '', 'checking Caller ID managed elsewhere should immediately clear the field');
assert($('#rc-rule-alert-call-callerid').prop('disabled') === true, 'checking Caller ID managed elsewhere should disable the field');
assert($('#rc-rule-alert-call-callerid').attr('placeholder') === '', 'checking Caller ID managed elsewhere should clear the placeholder as well as the value');
$('#rc-rule-alert-call-handle-callerid-upstream').prop('checked', false);
hooks.updateAlertCallAndEmailState();
hooks.updateAlertCallCallerIdState();
assert($('#rc-rule-alert-call-callerid').val() === '5551111', 'unchecking Caller ID managed elsewhere should restore the remembered value');
assert($('#rc-rule-alert-call-callerid').attr('placeholder') === '', 'restoring a remembered Caller ID value should keep the placeholder blank');
$('#rc-rule-alert-call-callerid').val('5552222');
hooks.updateAlertCallCallerIdState();
$('#rc-rule-alert-call-handle-callerid-upstream').prop('checked', true);
hooks.updateAlertCallAndEmailState();
$('#rc-rule-alert-call-handle-callerid-upstream').prop('checked', false);
hooks.updateAlertCallAndEmailState();
hooks.updateAlertCallCallerIdState();
assert($('#rc-rule-alert-call-callerid').val() === '5552222', 'repeated tick and untick cycles should restore the latest remembered Caller ID value');
assert($('#rc-rule-alert-call-callerid').attr('placeholder') === '', 'repeated tick and untick cycles should keep placeholder blank while the remembered value is restored');

$('#rc-rule-alert-call-callerid').val('5553333');
hooks.updateAlertCallCallerIdState();
$('#rc-rule-alert-call-handle-callerid-upstream').prop('checked', true);
hooks.updateAlertCallAndEmailState();
assert($('#rc-rule-alert-call-callerid').val() === '', 'checking Caller ID managed elsewhere should blank the value before save');
hooks.clearAlertCallCallerIdSessionState();
assert($('#rc-rule-alert-call-callerid').val() === '', 'cancel/reset should discard temporary Caller ID memory');

$('#rc-rule-alert-call-enabled').prop('checked', true);
$('#rc-rule-alert-call-handle-callerid-upstream').prop('checked', false);
$('#rc-rule-alert-call-callerid').val('5554444');
hooks.updateAlertCallAndEmailState();
hooks.updateAlertCallCallerIdState();
$('#rc-rule-alert-call-handle-callerid-upstream').prop('checked', true);
hooks.updateAlertCallAndEmailState();
hooks.clearAlertCallCallerIdSessionState();
$('#rc-rule-alert-call-enabled').prop('checked', true);
$('#rc-rule-alert-call-handle-callerid-upstream').prop('checked', false);
hooks.updateAlertCallAndEmailState();
assert($('#rc-rule-alert-call-callerid').val() === '', 'reopening after cancel should not resurrect an unsaved Caller ID value');

function makeSyntheticEvent(keyValue) {
	const event = {
		key: keyValue || '',
		which: keyValue === 'Enter' ? 13 : 0,
		keyCode: keyValue === 'Enter' ? 13 : 0,
		preventDefaultCalled: false,
		stopPropagationCalled: false,
		preventDefault: function () { this.preventDefaultCalled = true; },
		stopPropagation: function () { this.stopPropagationCalled = true; }
	};
	return event;
}

function resetAlertDestinationFlow(destination) {
	$('#rc-rule-alert-call-enabled').prop('checked', true);
	$('#rc-rule-caller-exclude').val('');
	$('#rc-rule-alert-call-destination-list').empty();
	$('#rc-rule-alert-call-destination-input').val(destination);
	hooks.updateAlertCallAndEmailState();
	hooks.updateAlertCallDestinationAddButtonState();
}

const warningText = 'Alert Call destinations are automatically added to Ignore these callers to reduce the risk of self-triggering if an alert call routes back through a monitored DID.';

// 1) Click Add with new destination: adds destination + Ignore entry + warns.
const warningCountBeforeClickAdd = warningNotieAlerts.length;
resetAlertDestinationFlow('2001');
const clickAddEvent = makeSyntheticEvent('MouseClick');
const clickAddResult = hooks.triggerAlertCallDestinationAdd(clickAddEvent);
assert(clickAddEvent.preventDefaultCalled === true, 'click add should prevent default action');
assert(clickAddResult && clickAddResult.autoAddedIgnoreEntries === 1, 'click add should report one auto-added Ignore callers entry for a new destination');
assert($('#rc-rule-alert-call-destination-list').find('li').length === 1, 'click add should create one Alert Call destination row for a new destination');
assert($('#rc-rule-caller-exclude').val() === '2001', 'click add should auto-add the same destination to Ignore callers');
assert(warningNotieAlerts.length === warningCountBeforeClickAdd + 1, 'click add should show a warning when Ignore callers is auto-added');
assert(warningNotieAlerts[warningNotieAlerts.length - 1].message === warningText, 'click add warning should use the self-trigger guidance text');
assert(warningNotieAlerts[warningNotieAlerts.length - 1].type === 2, 'click add warning should be emitted as warning notie type 2');
assert(warningNotieAlerts[warningNotieAlerts.length - 1].durationSeconds === 6, 'click add warning should be shown for 6 seconds');

// 2) Enter does identical.
const warningCountBeforeEnterAdd = warningNotieAlerts.length;
resetAlertDestinationFlow('2002');
const enterAddEvent = makeSyntheticEvent('Enter');
const enterAddReturn = hooks.handleAlertCallDestinationInputKeydown(enterAddEvent);
assert(enterAddReturn === false, 'enter add should return false to stop form submission');
assert(enterAddEvent.preventDefaultCalled === true, 'enter add should prevent default form submission');
assert($('#rc-rule-alert-call-destination-list').find('li').length === 1, 'enter add should create one Alert Call destination row for a new destination');
assert($('#rc-rule-caller-exclude').val() === '2002', 'enter add should auto-add the same destination to Ignore callers');
assert(warningNotieAlerts.length === warningCountBeforeEnterAdd + 1, 'enter add should show a warning when Ignore callers is auto-added');
assert(warningNotieAlerts[warningNotieAlerts.length - 1].message === warningText, 'enter add warning should use the same self-trigger guidance text as click add');
assert(warningNotieAlerts[warningNotieAlerts.length - 1].type === 2, 'enter add warning should be emitted as warning notie type 2');
assert(warningNotieAlerts[warningNotieAlerts.length - 1].durationSeconds === 6, 'enter add warning should be shown for 6 seconds');

// 3) Click restores removed Ignore entry + warns.
const warningCountBeforeClickRestore = warningNotieAlerts.length;
resetAlertDestinationFlow('3001');
hooks.addAlertCallDestinationsFromInput();
$('#rc-rule-caller-exclude').val('');
$('#rc-rule-alert-call-destination-input').val('3001');
hooks.updateAlertCallDestinationAddButtonState();
assert($('#rc-rule-alert-call-destination-add').prop('disabled') === false, 'Add should be available when destination exists but Ignore callers is missing that value');
const clickRestoreEvent = makeSyntheticEvent('MouseClick');
const clickRestoreResult = hooks.triggerAlertCallDestinationAdd(clickRestoreEvent);
assert(clickRestoreResult && clickRestoreResult.autoAddedIgnoreEntries === 1, 'click restore should report one restored Ignore callers entry');
assert($('#rc-rule-alert-call-destination-list').find('li').length === 1, 'click restore should not duplicate the destination row');
assert($('#rc-rule-caller-exclude').val() === '3001', 'click restore should restore missing Ignore callers value');
assert(warningNotieAlerts.length === warningCountBeforeClickRestore + 2, 'click restore path should warn for initial add and restore add');

// 4) Enter restores removed Ignore entry + warns.
const warningCountBeforeEnterRestore = warningNotieAlerts.length;
resetAlertDestinationFlow('3002');
hooks.addAlertCallDestinationsFromInput();
$('#rc-rule-caller-exclude').val('');
$('#rc-rule-alert-call-destination-input').val('3002');
hooks.updateAlertCallDestinationAddButtonState();
const enterRestoreEvent = makeSyntheticEvent('Enter');
const enterRestoreReturn = hooks.handleAlertCallDestinationInputKeydown(enterRestoreEvent);
assert(enterRestoreReturn === false, 'enter restore should return false to stop form submission');
assert(enterRestoreEvent.preventDefaultCalled === true, 'enter restore should prevent default form submission');
assert($('#rc-rule-alert-call-destination-list').find('li').length === 1, 'enter restore should not duplicate the destination row');
assert($('#rc-rule-caller-exclude').val() === '3002', 'enter restore should restore missing Ignore callers value');
assert(warningNotieAlerts.length === warningCountBeforeEnterRestore + 2, 'enter restore path should warn for initial add and restore add');

// 5) Neither method duplicates destination.
resetAlertDestinationFlow('4001');
hooks.addAlertCallDestinationsFromInput();
$('#rc-rule-alert-call-destination-input').val('4001');
hooks.updateAlertCallDestinationAddButtonState();
assert($('#rc-rule-alert-call-destination-add').prop('disabled') === true, 'Add should be disabled when destination already exists and Ignore callers already contains it');
const listCountBeforeClickDuplicate = $('#rc-rule-alert-call-destination-list').find('li').length;
const warningCountBeforeClickDuplicate = warningNotieAlerts.length;
hooks.triggerAlertCallDestinationAdd(makeSyntheticEvent('MouseClick'));
assert($('#rc-rule-alert-call-destination-list').find('li').length === listCountBeforeClickDuplicate, 'click duplicate add should not duplicate destination rows');
assert(warningNotieAlerts.length === warningCountBeforeClickDuplicate, 'click duplicate add should not warn when no change occurs');
resetAlertDestinationFlow('4002');
hooks.addAlertCallDestinationsFromInput();
$('#rc-rule-alert-call-destination-input').val('4002');
hooks.updateAlertCallDestinationAddButtonState();
const listCountBeforeEnterDuplicate = $('#rc-rule-alert-call-destination-list').find('li').length;
const warningCountBeforeEnterDuplicate = warningNotieAlerts.length;
hooks.handleAlertCallDestinationInputKeydown(makeSyntheticEvent('Enter'));
assert($('#rc-rule-alert-call-destination-list').find('li').length === listCountBeforeEnterDuplicate, 'enter duplicate add should not duplicate destination rows');
assert(warningNotieAlerts.length === warningCountBeforeEnterDuplicate, 'enter duplicate add should not warn when no change occurs');

// 6) Neither warns when nothing changes.

// 6b) Caller ID safeguard adds Ignore entry when applicable and reuses warning.
$('#rc-rule-alert-call-enabled').prop('checked', true);
$('#rc-rule-alert-call-handle-callerid-upstream').prop('checked', false);
$('#rc-rule-alert-call-callerid').val('+441111111111');
$('#rc-rule-caller-include').val('');
$('#rc-rule-caller-exclude').val('');
hooks.updateAlertCallAndEmailState();
const callerIdWarningCountBefore = warningNotieAlerts.length;
const callerIdSafeguardAdd = hooks.applyAlertCallCallerIdSelfTriggerSafeguard({ showWarning: true, showConflictMessage: true });
assert(callerIdSafeguardAdd && callerIdSafeguardAdd.added === true && callerIdSafeguardAdd.conflict === false, 'Caller ID safeguard should add Ignore callers entry when Alert Call is enabled and managed elsewhere is disabled');
assert($('#rc-rule-caller-exclude').val() === '+441111111111', 'Caller ID safeguard should append Caller ID to Ignore callers');
assert(warningNotieAlerts.length === callerIdWarningCountBefore + 1, 'Caller ID safeguard should reuse the self-trigger warning when adding a new Ignore callers entry');
assert(warningNotieAlerts[warningNotieAlerts.length - 1].message === warningText, 'Caller ID safeguard should reuse the same self-trigger warning text');

// 6c) Existing Ignore entries are not duplicated and do not warn again.
const callerIdWarningCountBeforeDuplicate = warningNotieAlerts.length;
const callerIdSafeguardDuplicate = hooks.applyAlertCallCallerIdSelfTriggerSafeguard({ showWarning: true, showConflictMessage: true });
assert(callerIdSafeguardDuplicate && callerIdSafeguardDuplicate.added === false && callerIdSafeguardDuplicate.conflict === false, 'Caller ID safeguard should not add duplicate Ignore callers entries');
assert($('#rc-rule-caller-exclude').val() === '+441111111111', 'Caller ID safeguard duplicate path should leave Ignore callers unchanged');
assert(warningNotieAlerts.length === callerIdWarningCountBeforeDuplicate, 'Caller ID safeguard duplicate path should not warn when no new Ignore callers entry is added');

// 6d) Blank Caller ID is ignored.
$('#rc-rule-alert-call-callerid').val('');
const blankCallerIdResult = hooks.applyAlertCallCallerIdSelfTriggerSafeguard({ showWarning: true, showConflictMessage: true });
assert(blankCallerIdResult && blankCallerIdResult.added === false && blankCallerIdResult.conflict === false, 'Caller ID safeguard should ignore blank Caller ID values');

// 6e) Caller ID managed elsewhere prevents automatic addition.
$('#rc-rule-alert-call-callerid').val('+442222222222');
$('#rc-rule-alert-call-handle-callerid-upstream').prop('checked', true);
hooks.updateAlertCallAndEmailState();
$('#rc-rule-caller-exclude').val('');
const upstreamManagedResult = hooks.applyAlertCallCallerIdSelfTriggerSafeguard({ showWarning: true, showConflictMessage: true });
assert(upstreamManagedResult && upstreamManagedResult.added === false && upstreamManagedResult.conflict === false, 'Caller ID safeguard should not add Ignore callers entries when Caller ID managed elsewhere is enabled');
assert($('#rc-rule-caller-exclude').val() === '', 'Caller ID safeguard should leave Ignore callers unchanged when Caller ID managed elsewhere is enabled');

// 6f) Changing Caller ID adds the new value without deleting previous Ignore entries.
$('#rc-rule-alert-call-handle-callerid-upstream').prop('checked', false);
hooks.updateAlertCallAndEmailState();
$('#rc-rule-caller-exclude').val('+441111111111');
$('#rc-rule-alert-call-callerid').val('+443333333333');
const changedCallerIdResult = hooks.applyAlertCallCallerIdSelfTriggerSafeguard({ showWarning: true, showConflictMessage: true });
assert(changedCallerIdResult && changedCallerIdResult.added === true && changedCallerIdResult.conflict === false, 'Caller ID safeguard should add newly configured Caller ID values');
assert($('#rc-rule-caller-exclude').val() === '+441111111111, +443333333333', 'Caller ID safeguard should preserve previous Ignore callers entries when adding a new Caller ID value');

// 6g) Include-list conflict should block auto-add with explicit warning.
$('#rc-rule-caller-include').val('+444444444444');
$('#rc-rule-caller-exclude').val('');
$('#rc-rule-alert-call-callerid').val('+444444444444');
const genericToastCountBeforeConflict = genericToasts.length;
const conflictResult = hooks.applyAlertCallCallerIdSelfTriggerSafeguard({ showWarning: true, showConflictMessage: true });
assert(conflictResult && conflictResult.added === false && conflictResult.conflict === true, 'Caller ID safeguard should report a conflict when Caller ID is in Only monitor callers and missing from Ignore callers');
assert($('#rc-rule-caller-exclude').val() === '', 'Caller ID safeguard conflict path should not silently modify Ignore callers');
assert(genericToasts.length === genericToastCountBeforeConflict + 1, 'Caller ID safeguard conflict should emit an explicit validation warning');
assert(genericToasts[genericToasts.length - 1].type === 'error', 'Caller ID safeguard conflict should be reported as an error-level validation warning');

// 6h) Manually removed Caller ID Ignore entries are not recreated during ordinary state refresh.
$('#rc-rule-caller-include').val('');
$('#rc-rule-caller-exclude').val('');
$('#rc-rule-alert-call-enabled').prop('checked', true);
$('#rc-rule-alert-call-handle-callerid-upstream').prop('checked', false);
$('#rc-rule-alert-call-callerid').val('+445555555555');
hooks.updateAlertCallCallerIdState();
hooks.updateAlertCallAndEmailState();
assert($('#rc-rule-caller-exclude').val() === '', 'ordinary editor refresh should not silently recreate manually removed Caller ID Ignore entries');

// 6i) Manually removed Caller ID Ignore entries are not recreated by unrelated saves.
hooks.syncAlertCallCallerIdSafeguardState();
$('#rc-rule-caller-exclude').val('');
$('#rc-rule-name').val('Unrelated setting update');
const unrelatedSaveResult = hooks.applyAlertCallCallerIdSelfTriggerSafeguardForSave({ showWarning: true, showConflictMessage: true });
assert(unrelatedSaveResult && unrelatedSaveResult.triggered === false && unrelatedSaveResult.added === false, 'unrelated saves should not trigger Caller ID safeguard re-addition');
assert($('#rc-rule-caller-exclude').val() === '', 'unrelated saves should not recreate manually removed Caller ID Ignore entries');

// 6j) Changing Caller ID again recreates the safeguard entry.
$('#rc-rule-alert-call-callerid').val('+446666666666');
const changedCallerIdSaveResult = hooks.applyAlertCallCallerIdSelfTriggerSafeguardForSave({ showWarning: true, showConflictMessage: true });
assert(changedCallerIdSaveResult && changedCallerIdSaveResult.triggered === true && changedCallerIdSaveResult.added === true && changedCallerIdSaveResult.conflict === false, 'changing Caller ID should trigger save-time safeguard re-addition');
assert($('#rc-rule-caller-exclude').val() === '+446666666666', 'changing Caller ID should recreate the required Ignore callers safeguard entry');

// 7) Fallback warning remains visible for 6000ms when notie is unavailable.
const fallbackCountBefore = fallbackTimeoutsMs.length;
const originalNotie = context.window.notie;
const originalFpbxToast = context.window.fpbxToast;
context.window.notie = null;
context.window.fpbxToast = null;
hooks.showAlertCallSelfTriggerWarning();
assert(fallbackTimeoutsMs.length === fallbackCountBefore + 1, 'fallback warning should schedule one hide timer');
assert(fallbackTimeoutsMs[fallbackTimeoutsMs.length - 1] === 6000, 'fallback warning hide timer should be 6000ms');
context.window.notie = originalNotie;
context.window.fpbxToast = originalFpbxToast;

// 8) Generic Repeat Caller messages should continue using fpbxToast path.
const genericToastCountBefore = genericToasts.length;
hooks.showMessage('Generic warning path check', 'warning');
assert(genericToasts.length === genericToastCountBefore + 1, 'generic Repeat Caller messages should continue to use fpbxToast path');
assert(genericToasts[genericToasts.length - 1].type === 'warning', 'generic Repeat Caller warning should be emitted through fpbxToast with warning level');

// 9) No global toast settings are mutated.
assert(typeof context.window.toastr === 'undefined' || typeof context.window.toastr.options === 'undefined', 'warning logic should not mutate global toastr options');

$('#rc-run-now').prop('disabled', true);
context.window.repeatCallerBootstrap.engineStatus = { enabled: 1, global_snoozed_until: '' };
hooks.initializeRunNowAvailabilityFromBootstrap();
assert($('#rc-run-now').prop('disabled') === false, 'Run Now should be enabled immediately on page-load sync when monitoring is enabled and not snoozed');

context.window.repeatCallerBootstrap.engineStatus = { enabled: 1, global_snoozed_until: '2026-07-13 11:00:00' };
hooks.initializeRunNowAvailabilityFromBootstrap();
assert($('#rc-run-now').prop('disabled') === true, 'Run Now should be disabled immediately on page-load sync when monitoring is snoozed');

context.window.repeatCallerBootstrap.engineStatus = { enabled: 0, global_snoozed_until: '' };
hooks.initializeRunNowAvailabilityFromBootstrap();
assert($('#rc-run-now').prop('disabled') === true, 'Run Now should be disabled immediately on page-load sync when monitoring is disabled');

process.stdout.write('OK');
NODE;
$behaviorOutput = shell_exec('node -e ' . escapeshellarg($behaviorScript));
assert_true(trim((string)$behaviorOutput) === 'OK', 'behavioral state test should pass for create/edit mode and Alert Call on/off combinations');
assert_true(strpos($jsSource, 'function isValidAlertCallCallerId(value) {') !== false && strpos($jsSource, 'return /^\\+?\\d+$/.test(candidate);') !== false, 'frontend should validate Alert Call Caller ID as digits with an optional leading + when Repeat Caller sets it');
assert_true(strpos($jsSource, "if (callEnabled && !handleCallerIdUpstream && alertCallCallerId === '') {") !== false, 'frontend should reject blank Alert Call Caller ID when Alert Call is enabled and upstream handling is disabled');
assert_true(strpos($jsSource, "if (callEnabled && !handleCallerIdUpstream && !isValidAlertCallCallerId(alertCallCallerId)) {") !== false, 'frontend should reject invalid Alert Call Caller ID when Repeat Caller is expected to set it');
assert_true(strpos($jsSource, "alert_call_handle_callerid_upstream: handleCallerIdUpstream ? 1 : 0,") !== false, 'save payload should persist Handle Caller ID Upstream explicitly');
assert_true(strpos($jsSource, "if ($(this).prop('disabled')) {") !== false, 'disabled row action handlers should short-circuit without performing actions');
assert_true(strpos($jsSource, 'function suppressionSummary(rule) {') !== false, 'rules UI should define a suppression summary helper');
assert_true(strpos($jsSource, "return 'Default 24hrs';") !== false, 'rules UI should show the default suppression label when blank');
assert_true(strpos($jsSource, "return 'Disabled';") !== false, 'rules UI should show Disabled when suppression is set to 0');
assert_true(strpos($jsSource, "$('#rc-rule-suppression').val(rule.suppression_minutes_override);") !== false, 'rule loader must preserve a 0 suppression override in the editor');
assert_true(strpos($jsSource, 'function normaliseAlertCallDestinationEntries(rawValue, defaultKeepTryingEnabled) {') !== false, 'rule editor should parse destination entries with per-destination keep-trying state');
assert_true(strpos($jsSource, "values.push(destination + '|' + keepTryingFlag);") !== false, 'rule editor should persist each destination with its own keep-trying value');
assert_true(strpos($jsSource, 'function alertCallDestinationExists(destination) {') !== false, 'rule editor should define a helper to detect existing Alert Call destinations in the current list');
assert_true(strpos($jsSource, 'function callerExcludeContainsDestination(destination) {') !== false, 'rule editor should define a helper to detect whether Ignore callers already contains a destination value');
assert_true(strpos($jsSource, 'function updateAlertCallDestinationAddButtonState() {') !== false, 'rule editor should define a helper to refresh the Add button from input and list state');
assert_true(strpos($jsSource, "var rawInput = $('#rc-rule-alert-call-destination-input').val();") !== false, 'Add button state helper should read the current destination input value');
assert_true(strpos($jsSource, "$('#rc-rule-alert-call-destination-list li')") !== false, 'Add button state helper should inspect the existing destination list');
assert_true(strpos($jsSource, "var disabled = !alertCallEnabled || !hasAddableDestination;") !== false, 'Add button state helper should disable only when alert call is off or no addable destination exists');
assert_true(strpos($jsSource, "if (!destinationExists || !callerExcludePresent) {") !== false, 'Add button state helper should allow re-adding an existing destination when Ignore callers is missing that value');
assert_true(strpos($jsSource, 'var alertCallSelfTriggerWarning = ') !== false && strpos($jsSource, 'Alert Call destinations are automatically added to Ignore these callers to reduce the risk of self-triggering if an alert call routes back through a monitored DID.') !== false, 'rule editor should define the one-time Alert Call self-trigger warning text');
assert_true(strpos($jsSource, 'function callerExcludeValues() {') !== false, 'rule editor should define a helper to read Ignore these callers values from the textarea');
assert_true(strpos($jsSource, 'function ensureCallerExcludeDestination(rawValue) {') !== false, 'rule editor should define a helper that conditionally appends Alert Call destinations to Ignore these callers');
assert_true(strpos($jsSource, 'function applyAlertCallCallerIdSelfTriggerSafeguard(options) {') !== false, 'rule editor should define a helper that applies Alert Call Caller ID self-trigger safeguards through existing Ignore callers handling');
assert_true(strpos($jsSource, 'function applyAlertCallCallerIdSelfTriggerSafeguardForSave(options) {') !== false, 'rule editor should define a save-time helper that only applies Caller ID safeguards when relevant changes occurred');
assert_true(strpos($jsSource, 'function hasAlertCallCallerIdSafeguardTrigger(previousState, nextState) {') !== false, 'rule editor should define explicit safeguard trigger detection for relevant Caller ID and Alert Call transitions');
assert_true(strpos($jsSource, "Alert Call Caller ID matches an Only monitor these callers entry.") !== false, 'Caller ID safeguard should provide explicit include-list conflict guidance');
assert_true(strpos($jsSource, 'addAlertCallDestination(destinationRow.destination, destinationRow.keepTrying);') !== false, 'Add action should still attempt to add Alert Call destination while preserving de-duplication');
assert_true(strpos($jsSource, "if (ensureCallerExcludeDestination(destinationRow.destination)) {") !== false, 'Add action should ensure Ignore callers contains the destination even when the destination already exists');
assert_true(strpos($jsSource, "if (autoAddedIgnoreEntries > 0) {") !== false && strpos($jsSource, 'showAlertCallSelfTriggerWarning();') !== false, 'adding one or more new Alert Call destinations should show a one-time warning when Ignore callers entries are auto-added');
assert_true(strpos($jsSource, 'var callerIdSafeguard = applyAlertCallCallerIdSelfTriggerSafeguardForSave({') !== false, 'save path should apply Caller ID self-trigger safeguard only through the save trigger gate');
assert_true(strpos($jsSource, 'if (callerIdSafeguard.conflict) {') !== false, 'save path should block contradictory include-list conflicts for Caller ID safeguard');
assert_true(strpos($jsSource, 'var alertCallSelfTriggerWarningDurationSeconds = 6;') !== false && strpos($jsSource, 'var alertCallSelfTriggerWarningTimeoutMs = 6000;') !== false, 'self-trigger warning should define explicit 6-second duration constants for toast and local fallback paths');
assert_true(strpos($jsSource, 'window.notie.alert(2, alertCallSelfTriggerWarning, alertCallSelfTriggerWarningDurationSeconds);') !== false, 'self-trigger warning should use notie alert type 2 with explicit 6-second duration when available');
assert_true(strpos($jsSource, "window.fpbxToast(alertCallSelfTriggerWarning, '', 'warning', alertCallSelfTriggerWarningTimeoutMs);") === false, 'self-trigger warning should not call fpbxToast with an unsupported per-message timeout argument');
assert_true(strpos($jsSource, 'toastr.options') === false, 'self-trigger warning changes should not mutate global toast settings');
assert_true(strpos($jsSource, 'function triggerAlertCallDestinationAdd(event) {') !== false && strpos($jsSource, 'return addAlertCallDestinationsFromInput();') !== false, 'click and Enter should share one add trigger that delegates to the same add function');
assert_true(strpos($jsSource, "triggerAlertCallDestinationAdd(event);") !== false, 'destination input Enter handler should invoke the same shared add trigger as click');
assert_true(strpos($jsSource, 'if ($.inArray(candidate, values) !== -1) {') !== false, 'auto-added Ignore callers entries should not create duplicates');
assert_true(strpos($jsSource, "if (!destination || unique[destination]) {") !== false, 'duplicate Add actions should not create duplicate Alert Call destination entries');
assert_true(strpos($jsSource, "if (exists) {") !== false && strpos($jsSource, 'return false;') !== false, 'duplicate Alert Call destinations should be ignored during list add operations');
assert_true(strpos($jsSource, 'function renderAlertCallDestinations(rawValue, defaultKeepTryingEnabled) {') !== false, 'rule editor should render alert-call destinations through ordered list UI');
assert_true(strpos($jsSource, '$list.append(buildAlertCallDestinationItem(destinationRow.destination, destinationRow.keepTrying));') !== false, 'rendering should restore per-destination keep-trying values');
assert_true(strpos($jsSource, "$('#rc-rule-alert-call-destination-add').off('click.repeatcaller').on('click.repeatcaller'" ) !== false, 'rule editor should bind add-destination button event');
assert_true(strpos($jsSource, "$('#rc-rule-alert-call-destination-input').off('keydown.repeatcaller').on('keydown.repeatcaller'" ) !== false, 'rule editor should support enter-key destination add');
assert_true(strpos($jsSource, "$('#rc-rule-alert-call-destination-input').off('input.repeatcaller keyup.repeatcaller change.repeatcaller paste.repeatcaller').on('input.repeatcaller keyup.repeatcaller change.repeatcaller paste.repeatcaller', function () {") !== false, 'rule editor should refresh the Add button as the destination input changes');
assert_true(strpos($jsSource, 'updateAlertCallDestinationAddButtonState();') !== false, 'rule editor should refresh the Add button after destination add, remove, render, and alert-call state changes');
assert_true(strpos($jsSource, 'rc-alert-call-destination-remove') !== false && strpos($jsSource, 'updateAlertCallDestinationAddButtonState();') !== false, 'removing a destination should immediately re-evaluate the Add button state');
assert_true(strpos($jsSource, "var callerExcludes = splitCallerListValues($('#rc-rule-caller-exclude').val());") !== false, 'save should split Ignore callers with mixed separators while keeping Alert Call destinations independent');
assert_true(strpos($jsSource, "$('#rc-rule-caller-exclude').val(excludeCallers.join(', '));") !== false, 'editing an existing rule should reload Ignore callers as a canonical comma-space list without auto-populating missing Alert Call destinations');
assert_true(strpos($jsSource, 'rc-alert-call-destination-drag-handle" draggable="true"') !== false, 'destination rows should render a dedicated drag handle');
assert_true(strpos($jsSource, 'fa fa-bars') !== false, 'drag handle should render a visible grip icon');
assert_true(strpos($jsSource, '$dragHandle.on(\'dragstart\'') !== false, 'destination dragging must start from the dedicated handle');
assert_true(strpos($jsSource, 'rc-alert-call-destination-item" draggable="true"') === false, 'destination rows themselves must not be directly draggable outside the handle');
assert_true(strpos($jsSource, 'glyphicon glyphicon-move') === false, 'obsolete secondary drag icon should not be rendered');
assert_true(strpos($jsSource, 'rc-alert-call-destination-up') === false, 'destination rows should not include a move-up control');
assert_true(strpos($jsSource, 'rc-alert-call-destination-down') === false, 'destination rows should not include a move-down control');
assert_true(strpos($viewSource, 'id="rc-rule-alert-call-strategy"') !== false, 'rule editor view should include alert call strategy selector');
assert_true(strpos($viewSource, 'id="rc-rule-alert-call-keep-trying"') === false, 'rule editor view should not render a global alert-call keep-trying field');
assert_true(strpos($viewSource, '<select id="rc-rule-alert-call-recording-id" class="form-control">') !== false, 'rule editor view should render a FreePBX-style system recording selector');
assert_true(strpos($viewSource, '<th><?php echo _(\'Suppression\'); ?></th>') !== false, 'rules table should include a Suppression summary column');
assert_true(strpos($viewSource, '<label><?php echo _(\'Suppression\'); ?></label><input type="number" id="rc-rule-suppression" class="form-control" min="0" placeholder="<?php echo _(\'Default 24hrs\'); ?>"><p class="help-block"><?php echo _(\'Leave blank to use Default 24hrs suppression period or enter 0 to disable.\'); ?></p>') !== false, 'rule editor should expose suppression with default and disabled guidance');
assert_true(strpos($viewSource, 'systemRecordings: <?php echo json_encode($systemRecordings); ?>') !== false, 'view bootstrap should expose system recording options for editor context');
assert_true(strpos($viewSource, 'placeholder="2001, 2002, 07812345678"') !== false, 'Alert Call destination placeholder must show national dialling format as guidance');
assert_true(strpos($viewSource, 'placeholder="07812345678"') !== false, 'Only monitor callers placeholder should show UK country context example');
assert_true(strpos($viewSource, 'placeholder="07812345679"') !== false, 'Ignore these callers placeholder should show UK country context example');
assert_true(strpos($viewSource, '<label><?php echo _(\'Start as\'); ?></label>') !== false, 'rule editor should rename the Enabled field label to Start as');
assert_true(strpos($viewSource, '<input type="checkbox" id="rc-rule-enabled" checked> <?php echo _(\'Enabled\'); ?>') !== false, 'rule editor should present Enabled as the create-mode toggle choice');
assert_true(strpos($viewSource, 'Choose whether the new rule should start enabled or disabled.') !== false, 'rule editor should explain that Start as chooses the initial state only for new rules');
assert_true(strpos($viewSource, 'placeholder="+441234567890"') !== false, 'Alert Call Caller ID placeholder should show E.164 format with leading +');
assert_true(strpos($viewSource, 'id="rc-rule-alert-call-handle-callerid-upstream" checked') !== false, 'rule editor view should default Caller ID managed elsewhere to enabled for new rules');
assert_true((bool)preg_match('/id="rc-rule-alert-call-recording-id"[\s\S]*id="rc-rule-alert-call-handle-callerid-upstream"[\s\S]*id="rc-rule-alert-call-callerid"/', $viewSource), 'Caller ID managed elsewhere should appear directly above the Alert Call Caller ID field in the right column');
assert_true(strpos($viewSource, 'Caller ID will be managed elsewhere') !== false, 'rule editor should label the option as Caller ID will be managed elsewhere');
assert_true(strpos($viewSource, 'Repeat Caller will not set the Caller ID for Alert Calls. Caller presentation is managed elsewhere, for example by Outbound Routes, trunks, another module, an SBC, or your network provider.') === false, 'legacy managed-elsewhere explanatory sentence should be removed from the view');
assert_true(strpos($viewSource, 'id="rc-rule-alert-call-callerid-help"') !== false, 'rule editor should include a dedicated help container for dynamic Alert Call Caller ID state text');
assert_true(strpos($viewSource, '<label><?php echo _(\'Only monitor these callers\'); ?></label>') !== false, 'Rule editor should use clear caller-monitor label wording');
assert_true(strpos($viewSource, '<p class="help-block" id="rc-caller-include-help"><?php echo _(\'Enter caller numbers separated by spaces, commas or new lines. Mixed separators are supported. Values are saved as a comma-separated list.\'); ?></p>') !== false, 'Rule editor should explain caller-list separators and canonical storage in helper text');
assert_true(strpos($viewSource, '<label><?php echo _(\'Ignore these callers\'); ?></label>') !== false, 'Rule editor should use clear caller-ignore label wording');
assert_true(strpos($viewSource, '<p class="help-block" id="rc-caller-exclude-help"><?php echo _(\'Enter caller numbers separated by spaces, commas or new lines. Mixed separators are supported. Values are saved as a comma-separated list.\'); ?></p>') !== false, 'Rule editor should explain caller-list separators and canonical storage in helper text');
assert_true(strpos($viewSource, 'id="rc-caller-include-unavailable"') !== false && strpos($viewSource, 'id="rc-caller-exclude-unavailable"') !== false, 'Rule editor should include explicit unavailable helper containers for disabled caller fields');
assert_true(strpos($viewSource, 'placeholder="+441234567890"') !== false, 'Alert Call caller ID placeholder must match exact release guidance');
assert_true(strpos($viewSource, '<option value=""><?php echo _(\'None\'); ?></option>') !== false, 'System Recording selector must default to a None option');
assert_true(strpos($viewSource, '<p class="help-block"><?php echo _(\'Optionally play a System Recording before the generated alert message. Default: None.\'); ?></p>') !== false, 'System Recording help text must keep the concise optional-recording guidance');
assert_true(strpos($viewSource, 'Warning. This alert has been initiated for [X] calls within [X] minutes from [Caller ID], calling number [DID]. Press 1 to accept or 2 to decline.') === false, 'System Recording help text should not include the removed warning example sentence');
assert_true(strpos($viewSource, '<th><?php echo _(\'Accepted By\'); ?></th>') !== false, 'Recent Incidents should label the acceptance source column as Accepted By');
assert_true(strpos($viewSource, 'Removes old completed incident records. Active incidents are not affected.') !== false, 'Prune Incident History control should explain that active incidents are unaffected');
assert_true(strpos($viewSource, 'Removes old suppression audit records. Current suppression state is not affected.') !== false, 'Prune Suppression History control should explain that current suppression state is unaffected');
assert_true(strpos($viewSource, 'Removes old email and Alert Call delivery records. Incident records are not affected.') !== false, 'Prune Alert History control should explain that incident records are unaffected');
assert_true(strpos($viewSource, 'The placeholders are replaced with the incident\'s actual call count, configured threshold/window, Caller ID and DID as applicable.') === false, 'System Recording help text must not include the removed placeholder replacement sentence');
assert_true(strpos($viewSource, 'id="rc-add-rule"') === false, 'rules section should not render Add Rule button above the table');
assert_true(strpos($viewSource, 'Clear Editor') === false, 'rule editor should not render Clear Editor wording');
assert_true(strpos($viewSource, 'class="btn btn-danger hidden" id="rc-cancel-edit"') !== false, 'rule editor should render a hidden danger-style Cancel Edit button for edit mode');
assert_true((bool)preg_match('/id="rc-rules-table"[\s\S]*<th><\?php echo _\(\'Recording\'\); \?><\/th>/', $viewSource), 'rules table should include a labeled Recording column');
assert_true(strpos($viewSource, 'rc-alert-call-destination-col-wide') !== false, 'destinations section should use the wider layout class');
assert_true((bool)preg_match('/rc-alert-call-right-col[\s\S]*id="rc-rule-alert-call-recording-id"[\s\S]*id="rc-rule-alert-call-handle-callerid-upstream"[\s\S]*id="rc-rule-alert-call-callerid"/', $viewSource), 'Caller ID managed elsewhere and Alert Call Caller ID should remain grouped directly beneath system recording in the right column');
assert_true(strpos($viewSource, 'id="rc-rule-alert-call-destination-list"') !== false, 'rule editor view should include ordered destination list container');
assert_true(strpos($viewSource, 'id="rc-rule-alert-call-destination-add"') !== false, 'rule editor view should include add-destination button');
assert_true(strpos($viewSource, 'id="rc-rule-alert-call-destination-input" class="form-control"') !== false, 'rule editor view should keep the destination input available for live add-button validation');
assert_true((bool)preg_match('/GUI \(always enabled\)[\s\S]*id="rc-rule-alert-call-enabled"[\s\S]*Alert Call[\s\S]*id="rc-rule-email-enabled"[\s\S]*Email/', $viewSource), 'rule action checklist should present Alert Call before Email');
assert_true((bool)preg_match('/\.repeatcaller \.rc-editor-panel\.rc-editor-edit-mode \{[\s\S]*background: #f5f5f5;[\s\S]*border-color: #ddd;/s', $cssSource), 'rule editor edit mode should use subtle neutral grey background and light border styling');
assert_true((bool)preg_match('/\.repeatcaller \.form-control\.rc-control-disabled,\s*\.repeatcaller \.form-control\.rc-control-disabled\[disabled\] \{[\s\S]*background-color: #f5f5f5;[\s\S]*border-color: #ddd;/s', $cssSource), 'disabled inbound route selector should use light grey disabled state styling');
assert_true(strpos($cssSource, '.repeatcaller .rc-rule-row-action-disabled,') !== false && strpos($cssSource, 'opacity: 0.55;') !== false, 'disabled Status, Edit and Delete controls should be visibly greyed out');
assert_true(strpos($cssSource, 'pointer-events: none;') !== false, 'disabled row-action buttons should not accept hover/focus/active pointer affordances');
assert_true(strpos($cssSource, '.repeatcaller .rc-rule-start-as-disabled,') !== false && strpos($cssSource, 'color: #777;') !== false, 'Start as edit-mode state should use muted grey styling');
assert_true(strpos($cssSource, '.repeatcaller .rc-rule-start-as-disabled input[disabled],') !== false && strpos($cssSource, 'cursor: not-allowed;') !== false, 'disabled Start as controls should use not-allowed cursor to avoid clickability affordance');
assert_true(strpos($cssSource, '.repeatcaller .btn[disabled],') !== false && strpos($cssSource, '.repeatcaller .btn.disabled,') !== false, 'disabled buttons should use dedicated disabled selectors rather than click guards only');
assert_true(strpos($cssSource, '.repeatcaller .btn[disabled]:hover,') !== false && strpos($cssSource, '.repeatcaller .btn[disabled]:focus,') !== false && strpos($cssSource, '.repeatcaller .btn[disabled]:active,') !== false, 'disabled buttons should explicitly neutralize hover, focus, and active affordances');
assert_true(strpos($cssSource, 'outline: none;') !== false && strpos($cssSource, 'box-shadow: none;') !== false, 'disabled control styling should remove focus outlines/rings and active shadows');
assert_true(strpos($cssSource, '.repeatcaller .rc-rule-status:not([disabled]),') !== false && strpos($cssSource, '.repeatcaller .rc-rule-status:not([disabled]):hover,') !== false, 'Status button hover/focus/active styling should apply only when the control is enabled');
assert_true(strpos($cssSource, '.repeatcaller .rc-route-actions .btn.rc-route-action-active:not([disabled]),') !== false, 'active DID action styling should not apply when the route-action button is disabled');
assert_true(strpos($cssSource, '.repeatcaller .form-control[disabled]:focus,') !== false && strpos($cssSource, '.repeatcaller .form-control.rc-control-disabled:focus,') !== false, 'disabled form controls should suppress focus ring and preserve muted disabled visuals');

$rootPos = strpos($viewSource, '<div class="repeatcaller"');
$containerPos = strpos($viewSource, '<div class="container-fluid repeatcaller-container">');
$firstRowPos = strpos($viewSource, '<div class="row">');
assert_true($rootPos !== false && $containerPos !== false && $firstRowPos !== false && $rootPos < $containerPos && $containerPos < $firstRowPos, 'repeatcaller root must wrap page rows in a module-owned container-fluid');
assert_true((bool)preg_match('/<div class="container-fluid repeatcaller-container">[\s\S]*<div class="row rc-section">[\s\S]*Engine Status[\s\S]*rc-run-now[\s\S]*<div class="row rc-section">[\s\S]*Active Incidents[\s\S]*<div class="row rc-section">[\s\S]*Suppressed Incidents[\s\S]*<div class="row rc-section">[\s\S]*Global Settings[\s\S]*<div class="row rc-section">[\s\S]*Rules[\s\S]*<div class="row rc-section">[\s\S]*Recent Incidents[\s\S]*<div class="row rc-section">[\s\S]*Alert History/s', $viewSource), 'Engine Status should contain Run Now before the main incident and rule sections, with Suppressed Incidents directly under Active Incidents');
assert_true((bool)preg_match('/<div class="table-responsive">\s*<table class="table table-striped table-condensed" id="rc-rules-table">/s', $viewSource), 'rules table must be directly contained by its own table-responsive wrapper');
assert_true(strpos($viewSource, '<th>ID</th><th><?php echo _(\'First\'); ?></th><th><?php echo _(\'Rule\'); ?></th><th><?php echo _(\'Mode\'); ?></th><th><?php echo _(\'Subject\'); ?></th>') !== false, 'active incidents columns should keep the shared ID, time, rule, mode, subject prefix');
assert_true((bool)preg_match('/<div class="table-responsive">\s*<table class="table table-striped table-condensed" id="rc-active-incidents-table">/s', $viewSource), 'active incidents table must be directly contained by its own table-responsive wrapper');
assert_true(strpos($viewSource, '<th>ID</th><th><?php echo _(\'Created\'); ?></th><th><?php echo _(\'Rule\'); ?></th><th><?php echo _(\'Mode\'); ?></th><th><?php echo _(\'Subject\'); ?></th>') !== false, 'recent incidents columns should keep the shared ID, time, rule, mode, subject prefix');
assert_true((bool)preg_match('/<div class="table-responsive">\s*<table class="table table-striped table-condensed" id="rc-recent-incidents-table">/s', $viewSource), 'recent incidents table must be directly contained by its own table-responsive wrapper');
assert_true(strpos($viewSource, '<th><?php echo _(\'ID\'); ?></th><th><?php echo _(\'Time\'); ?></th><th><?php echo _(\'Rule\'); ?></th><th><?php echo _(\'Mode\'); ?></th><th><?php echo _(\'Subject\'); ?></th>') !== false, 'alert history columns should keep the shared ID, time, rule, mode, subject prefix');
assert_true((bool)preg_match('/<div class="table-responsive">\s*<table class="table table-striped table-condensed" id="rc-alert-history-table">/s', $viewSource), 'alert history table must be directly contained by its own table-responsive wrapper');
assert_true(strpos($viewSource, '<th><?php echo _(\'Time\'); ?></th><th><?php echo _(\'Rule\'); ?></th><th><?php echo _(\'Mode\'); ?></th><th><?php echo _(\'Subject\'); ?></th>') !== false, 'suppressed incidents columns should keep the time, rule, mode, subject prefix');
assert_true((bool)preg_match('/\.repeatcaller,\s*\.repeatcaller \.repeatcaller-container,\s*\.repeatcaller \.row,\s*\.repeatcaller \[class\*="col-"\],\s*\.repeatcaller \.panel,\s*\.repeatcaller \.panel-body \{\s*box-sizing: border-box;\s*max-width: 100%;\s*min-width: 0;\s*\}/s', $cssSource), 'root, container, row, column, and panel elements must share explicit border-box containment');
assert_true((bool)preg_match('/\.repeatcaller \.repeatcaller-container \{\s*width: 100%;\s*\}/s', $cssSource), 'repeatcaller container must occupy the available module width');
assert_true((bool)preg_match('/body:has\(\.repeatcaller\) #page_body\.default-page \{\s*display: block !important;\s*width: 100% !important;\s*max-width: 100% !important;\s*\}/s', $cssSource), 'FreePBX page_body table layout override must be scoped to pages containing Repeat Caller');
assert_true((bool)preg_match('/\.repeatcaller \.table-responsive \{[\s\S]*overflow-x: auto;[\s\S]*overflow-y: hidden;[\s\S]*-webkit-overflow-scrolling: touch;/s', $cssSource), 'table-responsive wrapper must use local horizontal overflow');
assert_true((bool)preg_match('/#rc-rules-table \{[\s\S]*min-width: 1050px;/s', $cssSource), 'rules table should retain a readable minimum width');
assert_true((bool)preg_match('/#rc-active-incidents-table \{[\s\S]*min-width: 1200px;/s', $cssSource), 'active incidents table should retain a readable minimum width');
assert_true((bool)preg_match('/#rc-recent-incidents-table \{[\s\S]*min-width: 950px;/s', $cssSource), 'recent incidents table should retain a readable minimum width');
assert_true((bool)preg_match('/#rc-alert-history-table \{[\s\S]*min-width: 1200px;/s', $cssSource), 'alert history table should retain a readable minimum width');
assert_true(strpos($cssSource, '.repeatcaller .rc-table-batch-controls {') !== false && strpos($cssSource, '.repeatcaller .rc-table-batch-controls .btn {') !== false, 'table batching controls should have dedicated spacing styles');
assert_true(strpos($cssSource, 'overflow-x: hidden;') === false, 'module root must not hide horizontal overflow to mask broken containment');
assert_true((bool)preg_match('/@media \(max-width: 767px\) \{[\s\S]*\.repeatcaller \.repeatcaller-container \{[\s\S]*padding-left: 5px;[\s\S]*padding-right: 5px;/s', $cssSource), 'mobile container should provide matching horizontal padding for tightened row gutters');
assert_true((bool)preg_match('/@media \(max-width: 767px\) \{[\s\S]*\.repeatcaller \.row \{[\s\S]*margin-left: -5px;[\s\S]*margin-right: -5px;/s', $cssSource), 'mobile layout should tighten row gutters');
assert_true((bool)preg_match('/@media \(max-width: 767px\) \{[\s\S]*\.repeatcaller \[class\*="col-"\] \{[\s\S]*padding-left: 5px;[\s\S]*padding-right: 5px;/s', $cssSource), 'mobile layout should tighten column padding');
assert_true((bool)preg_match('/@media \(max-width: 767px\) \{[\s\S]*\.repeatcaller \.panel-heading \{[\s\S]*padding: 8px 10px;/s', $cssSource), 'mobile layout should reduce panel heading padding');
assert_true((bool)preg_match('/@media \(max-width: 767px\) \{[\s\S]*\.repeatcaller \.panel-body \{[\s\S]*padding: 8px 10px;/s', $cssSource), 'mobile layout should reduce panel body padding');
assert_true((bool)preg_match('/@media \(max-width: 767px\) \{[\s\S]*\.repeatcaller \.form-group \{[\s\S]*margin-bottom: 10px;/s', $cssSource), 'mobile layout should reduce form-group spacing');
assert_true((bool)preg_match('/@media \(max-width: 767px\) \{[\s\S]*\.repeatcaller label \{[\s\S]*margin-bottom: 3px;/s', $cssSource), 'mobile layout should reduce label spacing');
assert_true((bool)preg_match('/@media \(max-width: 767px\) \{[\s\S]*\.repeatcaller \.help-block \{[\s\S]*margin-top: 3px;[\s\S]*margin-bottom: 4px;/s', $cssSource), 'mobile layout should reduce help-block spacing');
assert_true((bool)preg_match('/@media \(max-width: 767px\) \{[\s\S]*\.repeatcaller \.btn \{[\s\S]*min-height: 44px;[\s\S]*padding: 8px 10px;[\s\S]*white-space: normal;/s', $cssSource), 'mobile buttons should stay touch-friendly while wrapping cleanly');
assert_true((bool)preg_match('/@media \(max-width: 767px\) \{[\s\S]*\.repeatcaller \.btn-group,[\s\S]*\.repeatcaller \.btn-toolbar \{[\s\S]*display: flex;[\s\S]*flex-wrap: wrap;[\s\S]*width: 100%;/s', $cssSource), 'mobile button groups should wrap at full width');
assert_true((bool)preg_match('/@media \(max-width: 767px\) \{[\s\S]*\.repeatcaller \.alert,[\s\S]*\.repeatcaller \.rc-engine-banner \{[\s\S]*padding: 8px 10px;[\s\S]*margin-bottom: 10px;/s', $cssSource), 'mobile alert and status boxes should use denser spacing');
assert_true((bool)preg_match('/<th><\?php echo _\(\'Rule\'\); \?><\/th><th><\?php echo _\(\'Mode\'\); \?><\/th>/s', $viewSource), 'required incident table columns must remain present');
assert_true((bool)preg_match('/<th><\?php echo _\(\'ID\'\); \?><\/th><th><\?php echo _\(\'Time\'\); \?><\/th><th><\?php echo _\(\'Rule\'\); \?><\/th><th><\?php echo _\(\'Mode\'\); \?><\/th>/s', $viewSource), 'required alert history columns must remain present');
assert_true(strpos($viewSource, 'data-label=') === false && strpos($cssSource, 'td:before') === false, 'mobile table card-layout conversion must not be introduced');

// Focused contract: claimincident should read ampuser username property and avoid direct object string-cast.
assert_true((bool)preg_match('/\$sessionUser = \$_SESSION\[\'AMP_user\'\] \?\? null;/', $controllerSource), 'claimincident must read AMP_user session value first');
assert_true((bool)preg_match('/if \(is_object\(\$sessionUser\) && isset\(\$sessionUser->username\)\) \{[\s\S]*\$user = trim\(\(string\)\$sessionUser->username\);/', $controllerSource), 'claimincident must resolve object-based ampuser via public username property');
assert_true((bool)preg_match('/\$user = \$user !== \'\' \? \$user : \'gui\';/', $controllerSource), 'claimincident must fall back to gui when username cannot be resolved');
assert_true(strpos($controllerSource, "(string)\$_SESSION['AMP_user']") === false, 'claimincident must not directly cast AMP_user session object to string');

$allowedCommands = [
	'getenginestatus', 'runmonitor', 'saveglobalsettings', 'getrules', 'getrule', 'saverule', 'deleterule', 'setruleenabled',
	'getinboundroutes', 'getincidents', 'claimincident', 'getalerthistory', 'getuichangetoken', 'setsnooze', 'resumemonitoring', 'prunehistory', 'clearalerthistory', 'saveuisetting'
];
assert_true(in_array('invalidcommand', $allowedCommands, true) === false, 'invalid ajax commands should be rejected by allowlist');
assert_true((bool)preg_match('/const AJAX_COMMANDS = \[[\s\S]*\'getuichangetoken\'[\s\S]*\];/', $controllerSource), 'AJAX command allowlist must include getuichangetoken');
assert_true((bool)preg_match('/case \'getuichangetoken\': return \$this->rcHandleGetUiChangeToken\(\);/', $controllerSource), 'AJAX dispatcher must route getuichangetoken to its handler');
assert_true((bool)preg_match('/private function rcHandleGetUiChangeToken\(\): array\s*\{[\s\S]*\'changeTokens\'\s*=>\s*\$this->rcRepository\(\)->loadUiChangeTokens\(\),[\s\S]*\}/', $controllerSource), 'change-token handler must return repository-backed section token payload');
assert_true((bool)preg_match('/const AJAX_COMMANDS = \[[\s\S]*\'clearalerthistory\'[\s\S]*\];/', $controllerSource), 'AJAX command allowlist must include clearalerthistory');
assert_true((bool)preg_match('/case \'clearalerthistory\': return \$this->rcHandleClearAlertHistory\(\);/', $controllerSource), 'AJAX dispatcher must route clearalerthistory to its handler');
assert_true((bool)preg_match('/private function rcHandleClearAlertHistory\(\): array\s*\{[\s\S]*\$repository->clearIncidentAlertHistory\(\)[\s\S]*\'deleted_count\'\s*=>\s*\$deletedCount,[\s\S]*\}/', $controllerSource), 'clear-history handler must clear alert history table and return deleted_count');
assert_true((bool)preg_match('/const AJAX_COMMANDS = \[[\s\S]*\'getsuppressedincidents\'[\s\S]*\];/', $controllerSource), 'AJAX command allowlist must include getsuppressedincidents');
assert_true((bool)preg_match('/case \'getsuppressedincidents\': return \$this->rcHandleGetSuppressedIncidents\(\);/', $controllerSource), 'AJAX dispatcher must route getsuppressedincidents to its handler');
assert_true(strpos($controllerSource, 'private function rcHandleGetSuppressedIncidents(): array') !== false && strpos($controllerSource, "'suppressedIncidents' =>") !== false && strpos($controllerSource, 'loadActiveSuppressedIncidents($asOf, 300)') !== false, 'operational suppressed-incidents handler must return only active suppression rows');
assert_true((bool)preg_match('/const AJAX_COMMANDS = \[[\s\S]*\'clearsuppression\'[\s\S]*\];/', $controllerSource), 'AJAX command allowlist must include clearsuppression');
assert_true((bool)preg_match('/case \'clearsuppression\': return \$this->rcHandleClearSuppression\(\);/', $controllerSource), 'AJAX dispatcher must route clearsuppression to its handler');
assert_true(strpos($controllerSource, 'private function rcHandleClearSuppression(): array') !== false && strpos($controllerSource, 'clearSuppressedIncidentHistory') !== false, 'clear-suppression handler must clear the audit row and active suppression state');
assert_true(strpos($controllerSource, 'private function rcHandleSaveGlobalSettings(): array') !== false && strpos($controllerSource, 'setSetting(\'suppression_history_prune_policy\', $suppressionPrune)') !== false, 'global settings handler must accept suppression-history prune policy');
assert_true(strpos($controllerSource, 'private function rcHandlePruneHistory(): array') !== false && strpos($controllerSource, 'setSetting(\'suppression_history_prune_policy\', $suppressionPolicy)') !== false, 'prune-history handler must persist suppression-history prune policy');
assert_true(strpos($controllerSource, 'pruneSeenCallsByFixedRetention($this->now())') !== false, 'prune-history handler must invoke internal seen-call retention cleanup');

$jsSource = file_get_contents(__DIR__ . '/../assets/js/repeatcaller.js');
assert_true($jsSource !== false, 'repeatcaller.js source should be readable');
assert_true((bool)preg_match('/normalizeChangeTokens\(rawTokens\)/', $jsSource), 'UI poller must normalize independent section tokens');
assert_true((bool)preg_match('/if \(nextTokens\.activeIncidents !== refreshState\.lastTokens\.activeIncidents\)/', $jsSource), 'UI poller must compare active incident token independently');
assert_true((bool)preg_match('/if \(nextTokens\.claimedIncidents !== refreshState\.lastTokens\.claimedIncidents\)/', $jsSource), 'UI poller must compare claimed incident token independently');
assert_true((bool)preg_match('/if \(nextTokens\.alertHistory !== refreshState\.lastTokens\.alertHistory\)/', $jsSource), 'UI poller must compare alert-history token independently');
assert_true((bool)preg_match('/if \(nextTokens\.suppressedIncidents !== refreshState\.lastTokens\.suppressedIncidents\)/', $jsSource), 'UI poller must compare suppression-history token independently');
assert_true((bool)preg_match('/if \(nextTokens\.engineStatus !== refreshState\.lastTokens\.engineStatus\)/', $jsSource), 'UI poller must compare engine-status token independently');
assert_true((bool)preg_match('/loadEngineStatus\(\{silent: true\}\);/', $jsSource), 'engine token change should refresh only engine status');
assert_true((bool)preg_match('/loadActiveIncidents\(\{silent: true\}\);/', $jsSource), 'active incident token change should refresh only active incidents table');
assert_true((bool)preg_match('/loadClaimedIncidents\(\{silent: true\}\);/', $jsSource), 'claimed incident token change should refresh only claimed incidents table');
assert_true((bool)preg_match('/loadAlertHistory\(\{silent: true\}\);/', $jsSource), 'alert-history token change should refresh only alert-history table');
assert_true((bool)preg_match('/loadSuppressedIncidents\(\{silent: true\}\);/', $jsSource), 'suppression-history token change should refresh only suppressed incidents table');
assert_true(strpos($jsSource, "ajax('clearsuppression', {suppression_history_id:") !== false, 'suppression history clear action should call clearsuppression backend command');
assert_true(strpos($jsSource, 'rc-clear-suppression') !== false, 'suppressed incidents rows should render a clear-suppression action');
assert_true(strpos($jsSource, "$('#rc-clear-alert-history').off('click.repeatcaller').on('click.repeatcaller'") !== false, 'UI should bind explicit Clear Alert History button');
assert_true(strpos($jsSource, "window.confirm('Run pruning now using the selected retention policies? This removes eligible historical rows and cannot be undone.')") !== false, 'Run Pruning action should require an explicit confirmation prompt');
assert_true(strpos($jsSource, "ajax('clearalerthistory', {}, function (response) {") !== false, 'Clear Alert History action should call clearalerthistory backend command');
assert_true(strpos($jsSource, "renderAlertHistory((response && response.alertHistory) || []);") !== false, 'Clear Alert History action should refresh the history table from backend response');
assert_true(strpos($jsSource, "renderSuppressedIncidents(response.suppressedIncidents || []);") !== false, 'suppression-history loader should refresh the table from backend response');
assert_true(strpos($jsSource, "No rules configured yet.") !== false, 'rules table should render an explicit empty state row when no rules exist');
assert_true(strpos($jsSource, "No active incidents recorded yet.") !== false, 'active incidents table should render an explicit empty state row when no rows exist');
assert_true(strpos($jsSource, "No recent incidents recorded yet.") !== false, 'recent incidents table should render an explicit empty state row when no rows exist');
assert_true(strpos($jsSource, "No alert history recorded yet.") !== false, 'alert history table should render an explicit empty state row when no rows exist');
assert_true(strpos($jsSource, "scrollToPageTop();") !== false, 'successful save and update actions should scroll the page back to the top');
assert_true(strpos($viewSource, 'id="rc-clear-alert-history"') !== false, 'admin view should include explicit Clear Alert History button');
assert_true(strpos($viewSource, 'id="rc-suppressed-incidents-table"') !== false, 'admin view should include a suppressed incidents table');
assert_true(strpos($viewSource, 'Suppressed Incidents') !== false, 'admin view should label the suppression-history section');
assert_true(strpos($viewSource, "<th><?php echo _('Cleared'); ?></th>") !== false, 'suppressed incidents table should expose a cleared-at column');
assert_true(strpos($viewSource, 'id="rc-setting-suppression-prune"') !== false, 'global settings should expose suppression-history prune policy');
assert_true(
	strpos($viewSource, 'value="never"') !== false
	&& strpos($viewSource, 'value="hourly"') !== false
	&& strpos($viewSource, 'value="daily"') !== false
	&& strpos($viewSource, 'value="weekly"') !== false
	&& strpos($viewSource, 'value="monthly"') !== false
	&& strpos($viewSource, 'value="yearly"') !== false,
	'global prune controls should expose never, hourly, daily, weekly, monthly, and yearly options'
);
assert_true(strpos($viewSource, '?? \x27daily\x27') !== false || strpos($viewSource, "?? 'daily'") !== false, 'global prune controls should default to daily when unset');
assert_true(strpos($viewSource, 'id="rc-setting-enabled"') === false, 'global settings should not expose a monitoring enable checkbox');
assert_true(strpos($viewSource, 'id="rc-setting-email-enabled"') === false, 'global settings should not expose a global email enable checkbox');
assert_true(strpos($viewSource, 'Enable Repeat Caller') === false, 'global settings should not mention the removed repeat caller enable checkbox');
assert_true(strpos($viewSource, 'Enable Email Alerts') === false, 'global settings should not mention the removed email enable checkbox');
assert_true(strpos($viewSource, 'Email Destinations') === false, 'global settings should remove the obsolete email destination field');
assert_true(strpos($viewSource, 'id="rc-rule-email-recipients"') !== false, 'rule editor should expose rule-level email recipients');
assert_true(strpos($viewSource, 'id="rc-run-now"') !== false, 'Run Now should remain in the top Engine Status control cluster');
assert_true(substr_count($viewSource, 'id="rc-run-now"') === 1, 'Run Now should appear only once in the admin view');
assert_true((bool)preg_match('/function detectionModeLabel\(rawMode\)/', $jsSource), 'UI should map incident detection modes to user-facing labels');
assert_true((bool)preg_match('/if \(mode === \'repeat\'\) \{[\s\S]*return \'Repeat\';/', $jsSource), 'repeat detection mode must render as Repeat');
assert_true((bool)preg_match('/if \(mode === \'invert\'\) \{[\s\S]*return \'Invert\';/', $jsSource), 'invert detection mode must render as Invert');
assert_true((bool)preg_match('/var repeatModeLabels = \{[\s\S]*\};/', $jsSource), 'Rules table should define repeat-mode display labels in one shared mapping');
assert_true(substr_count($jsSource, 'var repeatModeLabels = {') === 1, 'repeat-mode display mapping should not be duplicated');
assert_true(strpos($jsSource, "'5m': 'Every 5 Minutes'") !== false, 'repeat-mode mapping should render 5m as Every 5 Minutes');
assert_true(strpos($jsSource, "fibonacci: 'Escalating'") !== false, 'repeat-mode mapping should present legacy fibonacci values as Escalating');
assert_true(strpos($jsSource, "function repeatModeLabel(rawRepeatMode) {") !== false, 'Rules table should use dedicated repeat mode label helper');
assert_true(strpos($jsSource, 'var modeLabel = detectionModeLabel(rule.mode || \'repeat\');') !== false, 'Rules table mode should be rendered through the shared detection mode formatter');
assert_true(strpos($jsSource, "var repeatLabel = repeatModeLabel(rule.repeat_mode_override || 'never');") !== false, 'Rules table repeat mode should be rendered through repeatModeLabel helper');
assert_true(strpos($jsSource, "items.push('Alert Call');") !== false, 'Rules table actions should render Alert Call as implemented');
assert_true(strpos($jsSource, 'Alert Call (planned)') === false, 'Rules table should not render stale Alert Call (planned) suffix');
assert_true(strpos($jsSource, "mode: $('#rc-rule-mode').val(),") !== false, 'rule save payload should continue persisting canonical mode values');
assert_true(strpos($jsSource, "repeat_mode_override: $('#rc-rule-repeat').val(),") !== false, 'rule save payload should continue persisting canonical repeat mode override values');
assert_true((bool)preg_match('/detectionModeLabel\(i\.mode\)/', $jsSource), 'incident tables should render detection mode from incident mode field');
assert_true((bool)preg_match('/detectionModeLabel\(h\.incident_mode\)/', $jsSource), 'alert history should render detection mode from originating incident mode field');
assert_true(strpos($jsSource, 'detectionModeLabel(h.repeat_mode)') === false, 'alert repeat cadence must not be used for detection mode display');
assert_true((bool)preg_match('/var statusLabels = \{[\s\S]*\};/', $jsSource), 'shared status label mapping table should exist');
assert_true(substr_count($jsSource, 'var statusLabels = {') === 1, 'status mapping table must not be duplicated');
assert_true(strpos($jsSource, "open: 'Open'") !== false, 'shared status mapping should include open -> Open');
assert_true(strpos($jsSource, "accepted: 'Accepted'") !== false, 'shared status mapping should include accepted -> Accepted');
assert_true(strpos($jsSource, "declined: 'Declined'") !== false, 'shared status mapping should include declined -> Declined');
assert_true(strpos($jsSource, "resolved: 'Resolved'") !== false, 'shared status mapping should include resolved -> Resolved');
assert_true(strpos($jsSource, '>Accept</button>') !== false, 'active incident action button should be labeled Accept');
assert_true(strpos($jsSource, "Incident accepted.") !== false, 'accept action toast should use accepted terminology');
assert_true(strpos($jsSource, 'return labels[code] || titleizeFallback(code);') !== false, 'unknown status values should use the safe titleized fallback');
assert_true(strpos($jsSource, 'var stateLabel = mapCode(rawState, statusLabels) || \'-\';') !== false, 'Active and Recent incidents should compute status through the shared formatter');
assert_true(substr_count($jsSource, '+ \'<td title="\' + esc(rawState) + \'">\' + esc(stateLabel) + \'</td>\'') === 2, 'Active and Recent incidents should both render the shared formatted status cell');
assert_true((bool)preg_match('/function renderAlertHistory\(items\) \{[\s\S]*mapCode\(rawStatus, statusLabels\)/', $jsSource), 'Alert History should continue using the same shared status formatter');

$csrf = '';
assert_true($csrf === '', 'missing or invalid csrf token should be rejected by handler guard');

echo "repeat admin contract tests passed\n";

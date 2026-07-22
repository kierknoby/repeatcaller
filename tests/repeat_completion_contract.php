<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/DetectionEngine.php';
require_once __DIR__ . '/../src/RepeatCallerRepository.php';
require_once __DIR__ . '/../src/CdrScanner.php';
require_once __DIR__ . '/../src/BackgroundProcessor.php';
require_once __DIR__ . '/../src/IncidentAlertProcessor.php';

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

use FreePBX\modules\Repeatcaller\BackgroundProcessor;
use FreePBX\modules\Repeatcaller\CdrScanner;
use FreePBX\modules\Repeatcaller\IncidentAlertProcessor;
use FreePBX\modules\Repeatcaller\RepeatCallerRepository;
use FreePBX\modules\Repeatcaller;

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

final class MutableClock {
	public string $now;

	public function __construct(string $now) {
		$this->now = $now;
	}

	public function now(): string {
		return $this->now;
	}
}

final class RecordingSender {
	public array $calls = [];

	public function __invoke(string $recipient, string $subject, string $message): array {
		$this->calls[] = ['recipient' => $recipient, 'subject' => $subject, 'message' => $message];
		return ['status' => true, 'message' => 'accepted'];
	}
}

function create_env(string $dbPath, MutableClock $clock): array {
	$db = new PDO('sqlite:' . $dbPath);
	$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

	$db->exec('CREATE TABLE IF NOT EXISTS cdr (
		linkedid TEXT,
		uniqueid TEXT,
		calldate TEXT NOT NULL,
		src TEXT,
		clid TEXT,
		dst TEXT,
		did TEXT,
		dcontext TEXT,
		disposition TEXT,
		channel TEXT,
		dstchannel TEXT,
		duration INTEGER,
		billsec INTEGER
	)');
	$db->exec('CREATE TABLE IF NOT EXISTS incoming (
		extension TEXT,
		cidnum TEXT,
		description TEXT
	)');
	$db->exec('CREATE TABLE IF NOT EXISTS repeatcaller_settings (
		setting_key TEXT PRIMARY KEY,
		setting_value TEXT,
		updated_at TEXT
	)');
	$db->exec('CREATE TABLE IF NOT EXISTS repeatcaller_rules (
		id INTEGER PRIMARY KEY AUTOINCREMENT,
		name TEXT NOT NULL,
		enabled INTEGER NOT NULL DEFAULT 1,
		email_enabled INTEGER NOT NULL DEFAULT 0,
		email_recipients TEXT,
		alert_call_enabled INTEGER NOT NULL DEFAULT 0,
		alert_call_destinations TEXT,
		alert_call_recording_id INTEGER,
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
	$db->exec('CREATE TABLE IF NOT EXISTS repeatcaller_incident_suppression_history (
		id INTEGER PRIMARY KEY AUTOINCREMENT,
		related_incident_id INTEGER,
		rule_id INTEGER NOT NULL,
		rule_name TEXT,
		mode TEXT,
		subject_key TEXT,
		subject_label TEXT,
		caller_normalized TEXT,
		caller_display TEXT,
		inbound_route_key TEXT,
		inbound_route_label TEXT,
		did_value TEXT,
		matched_call_count INTEGER,
		threshold_count INTEGER,
		observation_window_minutes INTEGER,
		suppression_source TEXT,
		suppression_minutes INTEGER,
		suppression_started_at TEXT,
		suppression_expires_at TEXT,
		cleared_at TEXT,
		related_incident_state TEXT,
		detected_at TEXT,
		created_at TEXT,
		updated_at TEXT
	)');
	$db->exec('CREATE TABLE IF NOT EXISTS repeatcaller_rule_schedules (
		id INTEGER PRIMARY KEY AUTOINCREMENT,
		rule_id INTEGER NOT NULL,
		day_of_week INTEGER NOT NULL,
		start_time TEXT NOT NULL,
		end_time TEXT NOT NULL,
		created_at TEXT
	)');
	$db->exec('CREATE TABLE IF NOT EXISTS repeatcaller_rule_callers (
		id INTEGER PRIMARY KEY AUTOINCREMENT,
		rule_id INTEGER NOT NULL,
		list_type TEXT NOT NULL,
		raw_value TEXT NOT NULL,
		normalized_value TEXT NOT NULL,
		created_at TEXT
	)');
	$db->exec('CREATE TABLE IF NOT EXISTS repeatcaller_rule_dids (
		id INTEGER PRIMARY KEY AUTOINCREMENT,
		rule_id INTEGER NOT NULL,
		list_type TEXT NOT NULL,
		route_key TEXT NOT NULL,
		route_label TEXT NOT NULL,
		did_value TEXT,
		cid_value TEXT,
		created_at TEXT
	)');
	$db->exec('CREATE TABLE IF NOT EXISTS repeatcaller_seen_calls (
		id INTEGER PRIMARY KEY AUTOINCREMENT,
		call_identity TEXT NOT NULL UNIQUE,
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
	$db->exec('CREATE TABLE IF NOT EXISTS repeatcaller_rule_subject_state (
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
	$db->exec('CREATE TABLE IF NOT EXISTS repeatcaller_incidents (
		id INTEGER PRIMARY KEY AUTOINCREMENT,
		rule_id INTEGER NOT NULL,
		subject_key TEXT NOT NULL,
		active_subject_key TEXT UNIQUE,
		subject_label TEXT NOT NULL,
		caller_normalized TEXT,
		caller_display TEXT,
		withheld_caller INTEGER NOT NULL DEFAULT 0,
		mode TEXT NOT NULL,
		threshold_count INTEGER UNSIGNED NOT NULL DEFAULT 0,
		observation_window_minutes INTEGER UNSIGNED NOT NULL DEFAULT 0,
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
	$db->exec('CREATE TABLE IF NOT EXISTS repeatcaller_incident_alert_state (
		id INTEGER PRIMARY KEY AUTOINCREMENT,
		incident_id INTEGER NOT NULL UNIQUE,
		rule_id INTEGER NOT NULL,
		repeat_mode TEXT NOT NULL,
		initial_sent_at TEXT,
		last_alert_at TEXT,
		reminders_sent INTEGER NOT NULL DEFAULT 0,
		next_due_at TEXT,
		created_at TEXT NOT NULL,
		updated_at TEXT NOT NULL
	)');
	$db->exec('CREATE TABLE IF NOT EXISTS repeatcaller_incident_alert_history (
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

	$repo = new RepeatCallerRepository($db);
	$scanner = new CdrScanner($db, [$clock, 'now']);
	$sender = new RecordingSender();
	$runtime = new BackgroundProcessor($db, $repo, $scanner, [$clock, 'now']);
	$alerts = new IncidentAlertProcessor($repo, $sender, [$clock, 'now']);

	return [$db, $repo, $runtime, $alerts, $sender];
}

function insert_route(PDO $db, string $did, string $description = 'Main'): void {
	$db->prepare('INSERT INTO incoming (extension, cidnum, description) VALUES (?, ?, ?)')->execute([$did, '', $description]);
}

function insert_rule(PDO $db, array $rule): int {
	$db->prepare('INSERT INTO repeatcaller_rules (name, enabled, email_enabled, email_recipients, alert_call_enabled, alert_call_destinations, alert_call_recording_id, is_deleted, mode, threshold_count, observation_window_minutes, caller_mode, exclude_withheld, did_scope_mode, repeat_mode_override, suppression_minutes_override, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, 0, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
		->execute([
			$rule['name'],
			$rule['enabled'] ?? 1,
			$rule['email_enabled'] ?? 0,
			$rule['email_recipients'] ?? ((int)($rule['email_enabled'] ?? 0) === 1 ? 'alerts@example.invalid' : null),
			$rule['alert_call_enabled'] ?? 0,
			$rule['alert_call_destinations'] ?? null,
			$rule['alert_call_recording_id'] ?? null,
			$rule['mode'] ?? 'repeat',
			$rule['threshold_count'] ?? 2,
			$rule['observation_window_minutes'] ?? 60,
			$rule['caller_mode'] ?? 'any',
			$rule['exclude_withheld'] ?? 0,
			$rule['did_scope_mode'] ?? 'all',
			$rule['repeat_mode_override'] ?? null,
			$rule['suppression_minutes_override'] ?? 30,
			$rule['created_at'] ?? '2026-07-13 09:00:00',
			$rule['updated_at'] ?? '2026-07-13 09:00:00',
		]);
	$ruleId = (int)$db->lastInsertId();
	foreach ($rule['schedules'] ?? [] as $schedule) {
		$db->prepare('INSERT INTO repeatcaller_rule_schedules (rule_id, day_of_week, start_time, end_time, created_at) VALUES (?, ?, ?, ?, ?)')
			->execute([$ruleId, $schedule['day'], $schedule['start'] . ':00', $schedule['end'] . ':00', '2026-07-13 09:00:00']);
	}
	return $ruleId;
}

function insert_cdr(PDO $db, array $row): void {
	$defaults = [
		'linkedid' => '',
		'uniqueid' => '',
		'calldate' => '2026-07-13 09:00:00',
		'src' => '01234567890',
		'clid' => '01234567890',
		'dst' => '18005550001',
		'did' => '18005550001',
		'dcontext' => 'from-trunk',
		'disposition' => 'ANSWERED',
		'channel' => 'PJSIP/provider-00000001',
		'dstchannel' => 'Local/100@from-queue-00000002',
		'duration' => 20,
		'billsec' => 10,
	];
	$row = array_merge($defaults, $row);
	$db->prepare('INSERT INTO cdr (linkedid, uniqueid, calldate, src, clid, dst, did, dcontext, disposition, channel, dstchannel, duration, billsec) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
		->execute([$row['linkedid'], $row['uniqueid'], $row['calldate'], $row['src'], $row['clid'], $row['dst'], $row['did'], $row['dcontext'], $row['disposition'], $row['channel'], $row['dstchannel'], $row['duration'], $row['billsec']]);
}

function repeat_route_subject_key(string $callerNormalized, string $routeKey): string {
	return $callerNormalized . '|route:' . hash('sha1', $routeKey);
}

$path = tempnam(sys_get_temp_dir(), 'repeatcaller_completion_');
if ($path === false) {
	throw new RuntimeException('Unable to create SQLite file');
}

try {
	$clock = new MutableClock('2026-07-13 10:06:00');
	[$db, $repo, $runtime, $alerts, $sender] = create_env($path, $clock);
	insert_route($db, '18005550001');
	$ruleId = insert_rule($db, [
		'name' => 'Claim Rule',
		'email_enabled' => 1,
		'alert_call_enabled' => 1,
		'alert_call_destinations' => '201',
		'alert_call_recording_id' => 55,
		'repeat_mode_override' => '5m',
		'suppression_minutes_override' => 30,
		'schedules' => [['day' => 1, 'start' => '09:00', 'end' => '17:00']],
	]);
	insert_cdr($db, ['linkedid' => 'C1', 'calldate' => '2026-07-13 10:00:00']);
	insert_cdr($db, ['linkedid' => 'C2', 'calldate' => '2026-07-13 10:05:00']);
	$runtime->run(['enabled' => '1', 'default_country_code' => '44']);
	$alerts->run(['alert_enabled' => '1', 'alert_history_prune_policy' => 'never']);
	$incidentId = (int)$db->query('SELECT id FROM repeatcaller_incidents WHERE rule_id = 1')->fetchColumn();
	$routeSubjectKey = repeat_route_subject_key('+441234567890', '18005550001|');
	assert_true($incidentId > 0, 'repeat runtime should create initial incident');
	$subjectStateBeforeClaim = $db->query("SELECT active_incident_id FROM repeatcaller_rule_subject_state WHERE rule_id = {$ruleId} AND subject_key = '" . $routeSubjectKey . "'")->fetch(PDO::FETCH_ASSOC);
	assert_same($incidentId, (int)$subjectStateBeforeClaim['active_incident_id'], 'active incident should be linked in subject state before claim');
	assert_true($repo->claimActiveIncident($incidentId, 'admin', '2026-07-13 10:06:00', 'gui'), 'claim should succeed for active incident');
	$claimed = $db->query('SELECT state, active_subject_key, matched_call_count FROM repeatcaller_incidents WHERE id = ' . $incidentId)->fetch(PDO::FETCH_ASSOC);
	assert_same('claimed', $claimed['state'], 'claimed incident should enter claimed state');
	assert_true((string)$claimed['active_subject_key'] !== '', 'claimed incident should retain active subject linkage');
	$subjectState = $db->query("SELECT active_incident_id FROM repeatcaller_rule_subject_state WHERE rule_id = {$ruleId} AND subject_key = '" . $routeSubjectKey . "'")->fetch(PDO::FETCH_ASSOC);
	assert_same($incidentId, (int)$subjectState['active_incident_id'], 'claimed incident remains linked in subject state');

	$clock->now = '2026-07-13 10:20:00';
	insert_cdr($db, ['linkedid' => 'C3', 'calldate' => '2026-07-13 10:20:00']);
	$summaryClaimed = $runtime->run(['enabled' => '1', 'default_country_code' => '44']);
	assert_same(0, $summaryClaimed['incidents_created'], 'later matching call after claim must not create a second incident');
	$claimedUpdated = $db->query('SELECT state, matched_call_count, last_matched_at FROM repeatcaller_incidents WHERE id = ' . $incidentId)->fetch(PDO::FETCH_ASSOC);
	assert_same('claimed', $claimedUpdated['state'], 'later matching call should keep same claimed incident');
	assert_same(3, (int)$claimedUpdated['matched_call_count'], 'later matching call should update the same claimed incident');
	assert_same(1, (int)$db->query('SELECT COUNT(*) FROM repeatcaller_incidents WHERE rule_id = 1')->fetchColumn(), 'no second incident should exist while claimed condition persists');
	$idempotentSummary = $runtime->run(['enabled' => '1', 'default_country_code' => '44']);
	assert_same(0, $idempotentSummary['incidents_created'], 'repeated processing without new journeys must not create incidents');
	assert_same(0, $idempotentSummary['incidents_updated'], 'repeated processing without new journeys must not update incidents');
	$claimedAfterIdempotentRun = $db->query('SELECT matched_call_count FROM repeatcaller_incidents WHERE id = ' . $incidentId)->fetch(PDO::FETCH_ASSOC);
	assert_same(3, (int)$claimedAfterIdempotentRun['matched_call_count'], 'repeated processing without new journeys should be idempotent for the claimed incident');

	insert_route($db, '18005550002', 'Backup');
	$clock->now = '2026-07-13 10:25:00';
	insert_cdr($db, ['linkedid' => 'C7', 'calldate' => '2026-07-13 10:25:00', 'dst' => '18005550002', 'did' => '18005550002']);
	$summaryDifferentRoute = $runtime->run(['enabled' => '1', 'default_country_code' => '44']);
	assert_same(0, $summaryDifferentRoute['incidents_created'], 'a single matching call on a different route must not create a new incident before threshold is met');
	$claimedAfterDifferentRoute = $db->query('SELECT matched_call_count FROM repeatcaller_incidents WHERE id = ' . $incidentId)->fetch(PDO::FETCH_ASSOC);
	assert_same(3, (int)$claimedAfterDifferentRoute['matched_call_count'], 'a call on a different route must not attach to the claimed incident');
	assert_same(1, (int)$db->query('SELECT COUNT(*) FROM repeatcaller_incidents WHERE rule_id = 1')->fetchColumn(), 'a different-route call below threshold must not create or attach to the claimed incident');
	$beforeReminderCount = (int)$db->query("SELECT COUNT(*) FROM repeatcaller_incident_alert_history WHERE incident_id = {$incidentId} AND event_type = 'reminder'")->fetchColumn();
	$beforeEmailReminderCount = (int)$db->query("SELECT COUNT(*) FROM repeatcaller_incident_alert_history WHERE incident_id = {$incidentId} AND event_type = 'reminder' AND action_type = 'email'")->fetchColumn();
	$beforeCallReminderCount = (int)$db->query("SELECT COUNT(*) FROM repeatcaller_incident_alert_history WHERE incident_id = {$incidentId} AND event_type = 'reminder' AND action_type = 'alert_call'")->fetchColumn();
	$alerts->run(['alert_enabled' => '1', 'alert_history_prune_policy' => 'never']);
	$afterReminderCount = (int)$db->query("SELECT COUNT(*) FROM repeatcaller_incident_alert_history WHERE incident_id = {$incidentId} AND event_type = 'reminder'")->fetchColumn();
	$afterEmailReminderCount = (int)$db->query("SELECT COUNT(*) FROM repeatcaller_incident_alert_history WHERE incident_id = {$incidentId} AND event_type = 'reminder' AND action_type = 'email'")->fetchColumn();
	$afterCallReminderCount = (int)$db->query("SELECT COUNT(*) FROM repeatcaller_incident_alert_history WHERE incident_id = {$incidentId} AND event_type = 'reminder' AND action_type = 'alert_call'")->fetchColumn();
	assert_same($beforeReminderCount + 3, $afterReminderCount, 'claimed incidents with new qualifying activity should emit one new reminder stage across GUI, email, and alert_call');
	assert_same($beforeEmailReminderCount + 1, $afterEmailReminderCount, 'claimed incidents with new qualifying activity should emit one new email reminder stage');
	assert_same($beforeCallReminderCount + 1, $afterCallReminderCount, 'claimed incidents with new qualifying activity should emit one new alert_call reminder stage');

	$clock->now = '2026-07-13 11:30:00';
	insert_cdr($db, ['linkedid' => 'C4', 'calldate' => '2026-07-13 11:30:00']);
	$runtime->run(['enabled' => '1', 'default_country_code' => '44']);
	$afterClear = $db->query('SELECT state, cleared_at FROM repeatcaller_incidents WHERE id = ' . $incidentId)->fetch(PDO::FETCH_ASSOC);
	assert_same('closed', $afterClear['state'], 'claimed incident should only re-arm after a genuine clear closes it');
	$clock->now = '2026-07-13 11:35:00';
	insert_cdr($db, ['linkedid' => 'C5', 'calldate' => '2026-07-13 11:35:00']);
	insert_cdr($db, ['linkedid' => 'C6', 'calldate' => '2026-07-13 11:36:00']);
	$runtime->run(['enabled' => '1', 'default_country_code' => '44']);
	assert_same(2, (int)$db->query('SELECT COUNT(*) FROM repeatcaller_incidents WHERE rule_id = 1')->fetchColumn(), 'new incident should only be possible after clear and re-arm');

	$ruleDeleteId = insert_rule($db, [
		'name' => 'Delete Rule',
		'email_enabled' => 1,
		'repeat_mode_override' => '5m',
		'suppression_minutes_override' => 30,
		'schedules' => [['day' => 1, 'start' => '09:00', 'end' => '17:00']],
	]);
	insert_cdr($db, ['linkedid' => 'D1', 'calldate' => '2026-07-13 14:00:00', 'src' => '01230000001', 'clid' => '01230000001']);
	insert_cdr($db, ['linkedid' => 'D2', 'calldate' => '2026-07-13 14:01:00', 'src' => '01230000001', 'clid' => '01230000001']);
	$clock->now = '2026-07-13 14:02:00';
	$runtime->run(['enabled' => '1', 'default_country_code' => '44']);
	$deleteIncidentId = (int)$db->query('SELECT id FROM repeatcaller_incidents WHERE rule_id = ' . $ruleDeleteId . ' ORDER BY id DESC LIMIT 1')->fetchColumn();
	assert_true($deleteIncidentId > 0, 'rule deletion scenario should create an incident before delete');
	$repo->softDeleteRule($ruleDeleteId, '2026-07-13 14:03:00');
	$deletedRuleListing = $repo->loadRulesSummary();
	foreach ($deletedRuleListing as $listedRule) {
		assert_true((int)$listedRule['id'] !== $ruleDeleteId, 'deleted rules should disappear from normal listings');
	}
	$deletedIncident = $db->query('SELECT state FROM repeatcaller_incidents WHERE id = ' . $deleteIncidentId)->fetch(PDO::FETCH_ASSOC);
	assert_same('closed', $deletedIncident['state'], 'deleting a rule with an active incident should leave no alert-eligible orphan');
	$deletedState = $db->query('SELECT active_incident_id FROM repeatcaller_rule_subject_state WHERE rule_id = ' . $ruleDeleteId . ' LIMIT 1')->fetch(PDO::FETCH_ASSOC);
	assert_true(!isset($deletedState['active_incident_id']) || $deletedState['active_incident_id'] === null || (int)$deletedState['active_incident_id'] === 0, 'deleted rule should clear subject active link');
	$clock->now = '2026-07-13 14:10:00';
	$alerts->run(['alert_enabled' => '1', 'alert_history_prune_policy' => 'never']);
	$deletedReminders = (int)$db->query("SELECT COUNT(*) FROM repeatcaller_incident_alert_history WHERE incident_id = {$deleteIncidentId} AND event_type = 'reminder'")->fetchColumn();
	assert_same(0, $deletedReminders, 'deleted rules must not emit reminders');
	insert_cdr($db, ['linkedid' => 'D3', 'calldate' => '2026-07-13 14:11:00', 'src' => '01230000001', 'clid' => '01230000001']);
	$summaryDeletedRule = $runtime->run(['enabled' => '1', 'default_country_code' => '44']);
	assert_same(0, $summaryDeletedRule['incidents_created'], 'deleted rules should be excluded from runtime evaluation');
	$historyRows = $repo->loadIncidents('recent', 50);
	$foundDeletedHistory = false;
	foreach ($historyRows as $row) {
		if ((int)$row['id'] === $deleteIncidentId) {
			$foundDeletedHistory = true;
			assert_same('Delete Rule', (string)$row['rule_name'], 'historical incidents should retain useful rule name after soft delete');
		}
	}
	assert_true($foundDeletedHistory, 'deleted rule incidents should remain visible in recent/history views');

	$reusedRuleId = $repo->saveRule([
		'name' => 'Delete Rule',
		'enabled' => 1,
		'email_enabled' => 0,
		'alert_call_enabled' => 1,
		'alert_call_destinations' => '',
		'alert_call_recording_id' => null,
		'mode' => 'repeat',
		'threshold_count' => 2,
		'observation_window_minutes' => 60,
		'caller_mode' => 'any',
		'exclude_withheld' => 0,
		'did_scope_mode' => 'all',
		'repeat_mode_override' => '',
		'suppression_minutes_override' => 30,
		'schedules' => [['day' => 1, 'start' => '09:00', 'end' => '17:00']],
		'callers' => [],
		'dids' => [],
	], '2026-07-13 14:20:00');
	assert_true($reusedRuleId !== $ruleDeleteId, 'reusing the same human-readable rule name should be allowed safely on a new rule row');
	assert_same(0, (int)$db->query("SELECT COUNT(*) FROM repeatcaller_incident_alert_history WHERE rule_id = {$reusedRuleId} AND action_type = 'alert_call'")->fetchColumn(), 'alert call selection must not create delivery-history rows');

	FreePBX::$database = $db;
	$module = new Repeatcaller(new stdClass());
	$parseSchedules = new ReflectionMethod($module, 'rcParseSchedules');
	$parseSchedules->setAccessible(true);
	try {
		$parseSchedules->invoke($module, [['day' => 1, 'start' => '23:00', 'end' => '02:00']]);
		throw new RuntimeException('overnight schedule should be rejected');
	} catch (ReflectionException $e) {
		throw $e;
	} catch (InvalidArgumentException $e) {
		assert_true(strpos($e->getMessage(), 'not supported') !== false, 'overnight schedule rejection should be explicit');
	}

	$prunePolicy = new ReflectionMethod($module, 'normalisePrunePolicy');
	$prunePolicy->setAccessible(true);
	assert_same('never', $prunePolicy->invoke($module, 'garbage'), 'invalid retention values should preserve data by falling back to never');

	$beforePassiveCounts = [
		'alerts' => (int)$db->query('SELECT COUNT(*) FROM repeatcaller_incident_alert_history')->fetchColumn(),
		'incidents' => (int)$db->query('SELECT COUNT(*) FROM repeatcaller_incidents')->fetchColumn(),
	];
	$repo->loadRulesSummary();
	$repo->loadIncidents('active', 50);
	$repo->loadIncidents('recent', 50);
	$repo->loadIncidentAlertHistory(50);
	$repo->loadInboundRoutes();
	$afterPassiveCounts = [
		'alerts' => (int)$db->query('SELECT COUNT(*) FROM repeatcaller_incident_alert_history')->fetchColumn(),
		'incidents' => (int)$db->query('SELECT COUNT(*) FROM repeatcaller_incidents')->fetchColumn(),
	];
	assert_same($beforePassiveCounts, $afterPassiveCounts, 'passive page-data reads must not run monitor or pruning side effects');
} finally {
	@unlink($path);
}

echo "repeat completion contract tests passed\n";

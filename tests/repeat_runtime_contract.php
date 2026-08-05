<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/DetectionEngine.php';
require_once __DIR__ . '/../src/RepeatCallerRepository.php';
require_once __DIR__ . '/../src/CdrScanner.php';
require_once __DIR__ . '/../src/BackgroundProcessor.php';
require_once __DIR__ . '/../src/IncidentAlertProcessor.php';

use FreePBX\modules\Repeatcaller\BackgroundProcessor;
use FreePBX\modules\Repeatcaller\CdrScanner;
use FreePBX\modules\Repeatcaller\IncidentAlertProcessor;
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

function logs_contain_message(array $logs, string $needle): bool {
	foreach ($logs as $entry) {
		if (strpos((string)($entry['message'] ?? ''), $needle) !== false) {
			return true;
		}
	}
	return false;
}

function create_runtime_environment(string $dbPath, string $now): array {
	$db = new PDO('sqlite:' . $dbPath);
	$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

	$db->exec(
		'CREATE TABLE IF NOT EXISTS cdr (
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
			accountcode TEXT,
			userfield TEXT,
			duration INTEGER,
			billsec INTEGER
		)'
	);
	$db->exec(
		'CREATE TABLE IF NOT EXISTS incoming (
			extension TEXT,
			cidnum TEXT,
			description TEXT
		)'
	);
	$db->exec(
		'CREATE TABLE IF NOT EXISTS repeatcaller_rules (
			id INTEGER PRIMARY KEY AUTOINCREMENT,
			name TEXT NOT NULL,
			enabled INTEGER NOT NULL DEFAULT 1,
			enabled_at TEXT,
			email_enabled INTEGER NOT NULL DEFAULT 0,
			alert_call_enabled INTEGER NOT NULL DEFAULT 0,
			alert_call_destinations TEXT,
			alert_call_recording_id INTEGER,
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
		)'
	);
	$db->exec(
		'CREATE TABLE IF NOT EXISTS repeatcaller_rule_schedules (
			id INTEGER PRIMARY KEY AUTOINCREMENT,
			rule_id INTEGER NOT NULL,
			day_of_week INTEGER NOT NULL,
			start_time TEXT NOT NULL,
			end_time TEXT NOT NULL,
			created_at TEXT
		)'
	);
	$db->exec(
		'CREATE TABLE IF NOT EXISTS repeatcaller_rule_callers (
			id INTEGER PRIMARY KEY AUTOINCREMENT,
			rule_id INTEGER NOT NULL,
			list_type TEXT NOT NULL,
			raw_value TEXT NOT NULL,
			normalized_value TEXT NOT NULL,
			created_at TEXT
		)'
	);
	$db->exec(
		'CREATE TABLE IF NOT EXISTS repeatcaller_rule_dids (
			id INTEGER PRIMARY KEY AUTOINCREMENT,
			rule_id INTEGER NOT NULL,
			list_type TEXT NOT NULL,
			route_key TEXT NOT NULL,
			route_label TEXT NOT NULL,
			did_value TEXT,
			cid_value TEXT,
			created_at TEXT
		)'
	);
	$db->exec(
		'CREATE TABLE IF NOT EXISTS repeatcaller_seen_calls (
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
		)'
	);
	$db->exec(
		'CREATE TABLE IF NOT EXISTS repeatcaller_rule_subject_state (
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
		)'
	);
	$db->exec(
		'CREATE TABLE IF NOT EXISTS repeatcaller_incident_alert_state (
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
		)'
	);
	$db->exec(
		'CREATE TABLE IF NOT EXISTS repeatcaller_incident_alert_history (
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
		)'
	);
	$db->exec(
		'CREATE TABLE IF NOT EXISTS repeatcaller_incident_suppression_history (
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
		)'
	);
	$db->exec(
		'CREATE TABLE IF NOT EXISTS repeatcaller_incidents (
			id INTEGER PRIMARY KEY AUTOINCREMENT,
			rule_id INTEGER NOT NULL,
			subject_key TEXT NOT NULL,
			active_subject_key TEXT UNIQUE,
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
			accepted_by TEXT,
			accepted_at TEXT,
			accept_source TEXT,
			suppression_expires_at TEXT,
			cleared_at TEXT,
			created_at TEXT,
			updated_at TEXT
		)'
	);

	$nowProvider = function () use ($now): string {
		return $now;
	};
	$repository = new RepeatCallerRepository($db);
	$scanner = new CdrScanner($db, $nowProvider);
	$processor = new BackgroundProcessor($db, $repository, $scanner, $nowProvider);

	return [$db, $repository, $scanner, $processor];
}

function insert_route(PDO $db, string $did, string $cid = '', string $description = ''): void {
	$db->prepare('INSERT INTO incoming (extension, cidnum, description) VALUES (?, ?, ?)')->execute([$did, $cid, $description]);
}

function insert_rule(PDO $db, array $rule): int {
	$db->prepare('INSERT INTO repeatcaller_rules (name, enabled, enabled_at, email_enabled, alert_call_enabled, alert_call_destinations, alert_call_recording_id, mode, threshold_count, observation_window_minutes, caller_mode, exclude_withheld, did_scope_mode, repeat_mode_override, suppression_minutes_override, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
		->execute([
			$rule['name'],
			$rule['enabled'] ?? 1,
			array_key_exists('enabled_at', $rule)
				? $rule['enabled_at']
				: (!empty($rule['enabled'] ?? 1) ? ($rule['created_at'] ?? '2026-07-13 09:00:00') : null),
			$rule['email_enabled'] ?? 0,
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
	foreach ($rule['include_callers'] ?? [] as $caller) {
		$db->prepare('INSERT INTO repeatcaller_rule_callers (rule_id, list_type, raw_value, normalized_value, created_at) VALUES (?, ?, ?, ?, ?)')
			->execute([$ruleId, 'include', $caller, $caller, '2026-07-13 09:00:00']);
	}
	foreach ($rule['exclude_callers'] ?? [] as $caller) {
		$db->prepare('INSERT INTO repeatcaller_rule_callers (rule_id, list_type, raw_value, normalized_value, created_at) VALUES (?, ?, ?, ?, ?)')
			->execute([$ruleId, 'exclude', $caller, $caller, '2026-07-13 09:00:00']);
	}
	foreach ($rule['include_routes'] ?? [] as $route) {
		$db->prepare('INSERT INTO repeatcaller_rule_dids (rule_id, list_type, route_key, route_label, did_value, cid_value, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)')
			->execute([$ruleId, 'include', $route, $route, explode('|', $route, 2)[0], explode('|', $route, 2)[1] ?? '', '2026-07-13 09:00:00']);
	}
	foreach ($rule['exclude_routes'] ?? [] as $route) {
		$db->prepare('INSERT INTO repeatcaller_rule_dids (rule_id, list_type, route_key, route_label, did_value, cid_value, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)')
			->execute([$ruleId, 'exclude', $route, $route, explode('|', $route, 2)[0], explode('|', $route, 2)[1] ?? '', '2026-07-13 09:00:00']);
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
		'accountcode' => '',
		'userfield' => '',
		'duration' => 20,
		'billsec' => 10,
	];
	$row = array_merge($defaults, $row);
	$db->prepare('INSERT INTO cdr (linkedid, uniqueid, calldate, src, clid, dst, did, dcontext, disposition, channel, dstchannel, accountcode, userfield, duration, billsec) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
		->execute([
			$row['linkedid'], $row['uniqueid'], $row['calldate'], $row['src'], $row['clid'], $row['dst'], $row['did'],
			$row['dcontext'], $row['disposition'], $row['channel'], $row['dstchannel'], $row['accountcode'], $row['userfield'], $row['duration'], $row['billsec'],
		]);
}

final class ThrowingMetadataPdo extends PDO {
	public function __construct() {
		parent::__construct('sqlite::memory:');
		$this->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
	}

	public function prepare($query, $options = []) {
		throw new RuntimeException('forced metadata prepare failure');
	}
}

final class FakeCallSender {
	public array $calls = [];
	public array $failuresByDestination = [];

	public function __invoke(string $destination, string $recordingId, string $callerId = '', array $context = []): array {
		$this->calls[] = [
			'destination' => $destination,
			'recording_id' => $recordingId,
			'caller_id' => $callerId,
			'context' => $context,
		];
		if (isset($this->failuresByDestination[$destination])) {
			return ['status' => false, 'message' => $this->failuresByDestination[$destination]];
		}
		return ['status' => true, 'message' => 'accepted'];
	}
}

$dbPath = tempnam(sys_get_temp_dir(), 'repeatcaller_runtime_');
if ($dbPath === false) {
	throw new RuntimeException('Unable to create runtime contract SQLite file');
}

try {
	[$db, $repository, $scanner, $processor] = create_runtime_environment($dbPath, '2026-07-13 11:00:00');
	insert_route($db, '18005550001', '', 'Main');
	insert_route($db, '18005550002', '', 'Secondary');
	insert_rule($db, [
		'name' => 'Repeat Any',
		'mode' => 'repeat',
		'threshold_count' => 2,
		'observation_window_minutes' => 60,
		'caller_mode' => 'any',
		'did_scope_mode' => 'all',
		'schedules' => [['day' => 1, 'start' => '09:00', 'end' => '17:00']],
	]);

	insert_cdr($db, ['linkedid' => 'L1', 'uniqueid' => 'U1a', 'calldate' => '2026-07-13 09:00:00']);
	insert_cdr($db, ['linkedid' => 'L1', 'uniqueid' => 'U1b', 'calldate' => '2026-07-13 09:00:05', 'dstchannel' => 'Local/200@from-queue-00000003']);
	insert_cdr($db, ['linkedid' => 'L2', 'uniqueid' => 'U2', 'calldate' => '2026-07-13 09:10:00']);
	insert_cdr($db, ['linkedid' => 'L3', 'uniqueid' => 'U3', 'calldate' => '2026-07-13 09:20:00']);
	insert_cdr($db, ['linkedid' => 'L4', 'uniqueid' => 'U4', 'calldate' => '2026-07-13 09:30:00', 'src' => '01230000001', 'clid' => '01230000001']);

	$summary = $processor->run([
		'enabled' => '1',
		'default_country_code' => '44',
	]);
	assert_same(5, $summary['scanned_rows'], 'scanner should read bounded recent CDR rows');
	assert_same(4, $summary['collapsed_rows'], 'scanner should collapse duplicate call legs into call journeys');
	assert_same(4, $summary['inbound_journeys'], 'scanner should resolve inbound call journeys via inbound routes');
	assert_same(4, $summary['new_journeys'], 'first run should persist four unseen call journeys');
	assert_same(1, $summary['incidents_created'], 'repeat mode should create one incident when threshold is reached');
	assert_same(1, $summary['incidents_updated'], 'further matching calls should update the same incident');

	$incident = $db->query('SELECT matched_call_count, state, mode FROM repeatcaller_incidents WHERE rule_id = 1')->fetch(PDO::FETCH_ASSOC);
	assert_same(3, (int)$incident['matched_call_count'], 'same caller should accumulate matching call journeys into one incident');
	assert_same('active', $incident['state'], 'repeat incident should remain active until accept or expiry');
	assert_same('repeat', $incident['mode'], 'repeat mode behavior should remain unchanged when threshold is reached');

	$repeatRun = $processor->run([
		'enabled' => '1',
		'default_country_code' => '44',
	]);
	assert_same(0, $repeatRun['new_journeys'], 'repeated runs should be idempotent once journeys are seen');
	assert_same(0, $repeatRun['incidents_created'], 'repeated runs should not duplicate incidents');
	assert_same(0, $repeatRun['incidents_updated'], 'repeated runs should not update incidents without new journeys');

	insert_cdr($db, ['linkedid' => 'L3', 'uniqueid' => 'U3-late', 'calldate' => '2026-07-13 09:20:05', 'dstchannel' => 'Local/999@from-queue-00000099']);
	$lateDuplicateRun = $processor->run([
		'enabled' => '1',
		'default_country_code' => '44',
	]);
	assert_same(0, $lateDuplicateRun['new_journeys'], 'late duplicate CDR legs should not create new seen journeys');

	$dbPathInternalMarker = tempnam(sys_get_temp_dir(), 'repeatcaller_runtime_');
	if ($dbPathInternalMarker === false) {
		throw new RuntimeException('Unable to create internal-marker runtime SQLite file');
	}
	[$dbInternalMarker, $repositoryInternalMarker, $scannerInternalMarker, $processorInternalMarker] = create_runtime_environment($dbPathInternalMarker, '2026-07-13 10:30:00');
	insert_route($dbInternalMarker, '18005550001', '', 'Main');
	insert_rule($dbInternalMarker, [
		'name' => 'Internal Marker Filter Rule',
		'mode' => 'repeat',
		'threshold_count' => 1,
		'observation_window_minutes' => 60,
		'caller_mode' => 'any',
		'did_scope_mode' => 'all',
		'schedules' => [['day' => 1, 'start' => '09:00', 'end' => '17:00']],
	]);
	insert_cdr($dbInternalMarker, [
		'linkedid' => 'IMARK-1',
		'uniqueid' => 'IMARK-1',
		'calldate' => '2026-07-13 09:00:00',
		'src' => '03330000011',
		'clid' => '03330000011',
		'did' => '18005550001',
		'dst' => '18005550001',
		'accountcode' => 'repeatcaller_alert_internal',
	]);
	$internalMarkerSummary = $processorInternalMarker->run([
		'enabled' => '1',
		'default_country_code' => '44',
	]);
	assert_same(0, $internalMarkerSummary['new_journeys'], 'internally marked alert-call CDR rows should be excluded before detection');
	assert_same(0, $internalMarkerSummary['incidents_created'], 'internally marked alert-call CDR rows should not create incidents');

	insert_cdr($dbInternalMarker, [
		'linkedid' => 'IMARK-2',
		'uniqueid' => 'IMARK-2',
		'calldate' => '2026-07-13 09:05:00',
		'src' => '03330000011',
		'clid' => '03330000011',
		'did' => '18005550001',
		'dst' => '18005550001',
		'accountcode' => '',
	]);
	$unmarkedInboundSummary = $processorInternalMarker->run([
		'enabled' => '1',
		'default_country_code' => '44',
	]);
	assert_same(1, $unmarkedInboundSummary['new_journeys'], 'equivalent unmarked inbound CDR rows should still be processed normally');
	assert_same(1, $unmarkedInboundSummary['incidents_created'], 'equivalent unmarked inbound CDR rows should still create incidents when rule conditions are met');

	$dbPathDidAll = tempnam(sys_get_temp_dir(), 'repeatcaller_runtime_');
	if ($dbPathDidAll === false) {
		throw new RuntimeException('Unable to create all-DID runtime SQLite file');
	}
	[$dbDidAll, $repositoryDidAll, $scannerDidAll, $processorDidAll] = create_runtime_environment($dbPathDidAll, '2026-07-13 10:30:00');
	insert_route($dbDidAll, '18005550001', '', 'Main');
	insert_route($dbDidAll, '18005550002', '', 'Secondary');
	insert_rule($dbDidAll, [
		'name' => 'All DID Rule',
		'mode' => 'repeat',
		'threshold_count' => 1,
		'observation_window_minutes' => 60,
		'caller_mode' => 'any',
		'did_scope_mode' => 'all',
		'include_routes' => [],
		'exclude_routes' => [],
		'schedules' => [['day' => 1, 'start' => '09:00', 'end' => '17:00']],
	]);
	insert_cdr($dbDidAll, ['linkedid' => 'DALL-1', 'calldate' => '2026-07-13 09:00:00', 'src' => '03330000001', 'clid' => '03330000001', 'did' => '18005550001', 'dst' => '18005550001']);
	insert_cdr($dbDidAll, ['linkedid' => 'DALL-2', 'calldate' => '2026-07-13 09:05:00', 'src' => '03330000001', 'clid' => '03330000001', 'did' => '18005550002', 'dst' => '18005550002']);
	$didAllSummary = $processorDidAll->run([
		'enabled' => '1',
		'default_country_code' => '44',
	]);
	assert_same(2, $didAllSummary['incidents_created'], 'all DID scope with no exclusions should match every inbound route');

	$dbPathDidExclude = tempnam(sys_get_temp_dir(), 'repeatcaller_runtime_');
	if ($dbPathDidExclude === false) {
		throw new RuntimeException('Unable to create all-DID exclusion runtime SQLite file');
	}
	[$dbDidExclude, $repositoryDidExclude, $scannerDidExclude, $processorDidExclude] = create_runtime_environment($dbPathDidExclude, '2026-07-13 10:30:00');
	insert_route($dbDidExclude, '18005550001', '', 'Main');
	insert_route($dbDidExclude, '18005550002', '', 'Secondary');
	insert_rule($dbDidExclude, [
		'name' => 'All DID Exclusion Rule',
		'mode' => 'repeat',
		'threshold_count' => 1,
		'observation_window_minutes' => 60,
		'caller_mode' => 'any',
		'did_scope_mode' => 'all',
		'include_routes' => [],
		'exclude_routes' => ['18005550002|'],
		'schedules' => [['day' => 1, 'start' => '09:00', 'end' => '17:00']],
	]);
	insert_cdr($dbDidExclude, ['linkedid' => 'DEXC-1', 'calldate' => '2026-07-13 09:00:00', 'src' => '03330000002', 'clid' => '03330000002', 'did' => '18005550001', 'dst' => '18005550001']);
	insert_cdr($dbDidExclude, ['linkedid' => 'DEXC-2', 'calldate' => '2026-07-13 09:05:00', 'src' => '03330000002', 'clid' => '03330000002', 'did' => '18005550002', 'dst' => '18005550002']);
	$didExcludeSummary = $processorDidExclude->run([
		'enabled' => '1',
		'default_country_code' => '44',
	]);
	assert_same(1, $didExcludeSummary['incidents_created'], 'all DID scope exclusions should prevent excluded routes from matching');

	$dbPathDidSelected = tempnam(sys_get_temp_dir(), 'repeatcaller_runtime_');
	if ($dbPathDidSelected === false) {
		throw new RuntimeException('Unable to create selected-DID runtime SQLite file');
	}
	[$dbDidSelected, $repositoryDidSelected, $scannerDidSelected, $processorDidSelected] = create_runtime_environment($dbPathDidSelected, '2026-07-13 10:30:00');
	insert_route($dbDidSelected, '18005550001', '', 'Main');
	insert_route($dbDidSelected, '18005550002', '', 'Secondary');
	insert_rule($dbDidSelected, [
		'name' => 'Selected DID Rule',
		'mode' => 'repeat',
		'threshold_count' => 2,
		'observation_window_minutes' => 60,
		'caller_mode' => 'any',
		'did_scope_mode' => 'selected',
		'include_routes' => ['18005550001|'],
		'exclude_routes' => ['18005550001|'],
		'schedules' => [['day' => 1, 'start' => '09:00', 'end' => '17:00']],
	]);
	insert_cdr($dbDidSelected, ['linkedid' => 'DSEL-1', 'calldate' => '2026-07-13 09:00:00', 'src' => '03330000003', 'clid' => '03330000003', 'did' => '18005550001', 'dst' => '18005550001']);
	insert_cdr($dbDidSelected, ['linkedid' => 'DSEL-2', 'calldate' => '2026-07-13 09:05:00', 'src' => '03330000003', 'clid' => '03330000003', 'did' => '18005550002', 'dst' => '18005550002']);
	insert_cdr($dbDidSelected, ['linkedid' => 'DSEL-3', 'calldate' => '2026-07-13 09:10:00', 'src' => '03330000003', 'clid' => '03330000003', 'did' => '18005550001', 'dst' => '18005550001']);
	$didSelectedSummary = $processorDidSelected->run([
		'enabled' => '1',
		'default_country_code' => '44',
	]);
	assert_same(1, $didSelectedSummary['incidents_created'], 'selected DID scope should use include routes only and ignore exclusion rows');

	$dbPathRouteNoActive = tempnam(sys_get_temp_dir(), 'repeatcaller_runtime_');
	if ($dbPathRouteNoActive === false) {
		throw new RuntimeException('Unable to create route no-active compatibility runtime SQLite file');
	}
	[$dbRouteNoActive, $repositoryRouteNoActive, $scannerRouteNoActive, $processorRouteNoActive] = create_runtime_environment($dbPathRouteNoActive, '2026-07-13 10:30:00');
	insert_route($dbRouteNoActive, '18005550001', '', 'Main');
	insert_rule($dbRouteNoActive, [
		'name' => 'Route Compatibility No Active',
		'mode' => 'repeat',
		'threshold_count' => 1,
		'observation_window_minutes' => 60,
		'caller_mode' => 'any',
		'did_scope_mode' => 'all',
		'schedules' => [['day' => 1, 'start' => '09:00', 'end' => '17:00']],
	]);
	$legacyNoActiveCallerKey = '+443334567821';
	$legacyNoActiveSubjectKey = $legacyNoActiveCallerKey;
	$mainNoActiveRouteSubjectKey = $legacyNoActiveCallerKey . '|route:' . hash('sha1', '18005550001|');
	$dbRouteNoActive->prepare('INSERT INTO repeatcaller_incidents (rule_id, subject_key, active_subject_key, subject_label, caller_normalized, caller_display, withheld_caller, mode, first_matched_at, last_matched_at, matched_call_count, state, suppression_expires_at, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
		->execute([
			1,
			$legacyNoActiveSubjectKey,
			null,
			$legacyNoActiveSubjectKey,
			$legacyNoActiveCallerKey,
			'03334567821',
			0,
			'repeat',
			'2026-07-13 08:50:00',
			'2026-07-13 08:50:00',
			1,
			'accepted',
			null,
			'2026-07-13 08:50:00',
			'2026-07-13 08:50:00',
		]);
	$legacyAcceptedId = (int)$dbRouteNoActive->lastInsertId();
	$dbRouteNoActive->prepare('INSERT INTO repeatcaller_rule_subject_state (rule_id, subject_key, current_window_started_at, current_window_ends_at, current_window_call_count, threshold_met, clear_observed_since_trigger, active_incident_id, suppression_expires_at, last_call_at, last_evaluated_at, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
		->execute([
			1,
			$legacyNoActiveSubjectKey,
			'2026-07-13 08:00:00',
			'2026-07-13 08:50:00',
			1,
			1,
			0,
			$legacyAcceptedId,
			null,
			'2026-07-13 08:50:00',
			'2026-07-13 08:50:00',
			'2026-07-13 08:50:00',
			'2026-07-13 08:50:00',
		]);
	insert_cdr($dbRouteNoActive, ['linkedid' => 'RNA1', 'calldate' => '2026-07-13 09:00:00', 'src' => '03334567821', 'clid' => '03334567821', 'did' => '18005550001', 'dst' => '18005550001']);

	$routeNoActiveSummary = $processorRouteNoActive->run([
		'enabled' => '1',
		'default_country_code' => '44',
	]);
	assert_same(1, $routeNoActiveSummary['incidents_created'], 'when only historical legacy incidents exist, runtime should create a fresh route-scoped active incident');
	$legacyStateStillExists = $repositoryRouteNoActive->loadSubjectState(1, $legacyNoActiveSubjectKey);
	assert_true(is_array($legacyStateStillExists), 'legacy caller-only state row with no active incident should remain unchanged');
	$routeNoActiveState = $repositoryRouteNoActive->loadSubjectState(1, $mainNoActiveRouteSubjectKey);
	assert_true(is_array($routeNoActiveState), 'fresh route-scoped subject state should be created normally when adoption is skipped');
	$legacyAcceptedStillExists = $dbRouteNoActive->query('SELECT id, subject_key, state FROM repeatcaller_incidents WHERE id = ' . $legacyAcceptedId)->fetch(PDO::FETCH_ASSOC);
	assert_same('accepted', (string)$legacyAcceptedStillExists['state'], 'legacy accepted incident should not be modified by adoption compatibility path');
	assert_same($legacyNoActiveSubjectKey, (string)$legacyAcceptedStillExists['subject_key'], 'legacy accepted incident subject key should remain caller-only when no active legacy incident exists');
	$freshRouteIncident = $dbRouteNoActive->query("SELECT id, state, subject_key FROM repeatcaller_incidents WHERE rule_id = 1 AND subject_key = '" . $mainNoActiveRouteSubjectKey . "' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
	assert_true(is_array($freshRouteIncident), 'fresh route-scoped active incident should be created when legacy adoption is skipped');
	assert_same('active', (string)$freshRouteIncident['state'], 'fresh route-scoped incident should be active');
	assert_true((int)$freshRouteIncident['id'] !== $legacyAcceptedId, 'fresh route-scoped incident should not reuse historical accepted incident id');

	$dbPathRoute = tempnam(sys_get_temp_dir(), 'repeatcaller_runtime_');
	if ($dbPathRoute === false) {
		throw new RuntimeException('Unable to create route-split runtime contract SQLite file');
	}
	[$dbRoute, $repositoryRoute, $scannerRoute, $processorRoute] = create_runtime_environment($dbPathRoute, '2026-07-13 10:30:00');
	insert_route($dbRoute, '18005550001', '', 'Main');
	insert_route($dbRoute, '18005550002', '', 'Secondary');
	insert_rule($dbRoute, [
		'name' => 'Route Scoped Repeat',
		'mode' => 'repeat',
		'threshold_count' => 1,
		'observation_window_minutes' => 60,
		'caller_mode' => 'any',
		'did_scope_mode' => 'all',
		'schedules' => [['day' => 1, 'start' => '09:00', 'end' => '17:00']],
	]);
	$legacyCallerKey = '+443334567820';
	$legacyCreatedAt = '2026-07-13 08:55:00';
	$legacySuppression = '2026-07-13 11:30:00';
	$dbRoute->prepare('INSERT INTO repeatcaller_incidents (rule_id, subject_key, active_subject_key, subject_label, caller_normalized, caller_display, withheld_caller, mode, first_matched_at, last_matched_at, matched_call_count, state, suppression_expires_at, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
		->execute([
			1,
			$legacyCallerKey,
			'1|' . $legacyCallerKey,
			$legacyCallerKey,
			$legacyCallerKey,
			'03334567820',
			0,
			'repeat',
			$legacyCreatedAt,
			$legacyCreatedAt,
			1,
			'active',
			$legacySuppression,
			$legacyCreatedAt,
			$legacyCreatedAt,
		]);
	$legacyIncidentId = (int)$dbRoute->lastInsertId();
	$dbRoute->prepare('INSERT INTO repeatcaller_rule_subject_state (rule_id, subject_key, current_window_started_at, current_window_ends_at, current_window_call_count, threshold_met, clear_observed_since_trigger, active_incident_id, suppression_expires_at, last_call_at, last_evaluated_at, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
		->execute([
			1,
			$legacyCallerKey,
			'2026-07-13 08:00:00',
			$legacyCreatedAt,
			1,
			1,
			0,
			$legacyIncidentId,
			$legacySuppression,
			$legacyCreatedAt,
			$legacyCreatedAt,
			$legacyCreatedAt,
			$legacyCreatedAt,
		]);
	$mainRouteSubjectKey = $legacyCallerKey . '|route:' . hash('sha1', '18005550001|');
	$secondaryRouteSubjectKey = $legacyCallerKey . '|route:' . hash('sha1', '18005550002|');
	insert_cdr($dbRoute, ['linkedid' => 'RS1', 'calldate' => '2026-07-13 09:00:00', 'src' => '03334567820', 'clid' => '03334567820', 'did' => '18005550001', 'dst' => '18005550001']);
	insert_cdr($dbRoute, ['linkedid' => 'RS2', 'calldate' => '2026-07-13 09:05:00', 'src' => '03334567820', 'clid' => '03334567820', 'did' => '18005550001', 'dst' => '18005550001']);
	insert_cdr($dbRoute, ['linkedid' => 'RS3', 'calldate' => '2026-07-13 09:10:00', 'src' => '03334567820', 'clid' => '03334567820', 'did' => '18005550002', 'dst' => '18005550002']);
	insert_cdr($dbRoute, ['linkedid' => 'RS4', 'calldate' => '2026-07-13 09:15:00', 'src' => '03334567820', 'clid' => '03334567820', 'did' => '18005550002', 'dst' => '18005550002']);
	insert_cdr($dbRoute, ['linkedid' => 'RS5', 'calldate' => '2026-07-13 09:20:00', 'src' => '03334567820', 'clid' => '03334567820', 'did' => '18005550001', 'dst' => '18005550001']);

	$routeSummary = $processorRoute->run([
		'enabled' => '1',
		'default_country_code' => '44',
	]);
	assert_same(1, $routeSummary['incidents_created'], 'a second route should create one separate incident while the adopted route keeps the legacy incident id');
	assert_true($routeSummary['incidents_updated'] >= 1, 'legacy adopted incident should continue receiving updates for its route');
	$incidentCountByRoute = (int)$dbRoute->query('SELECT COUNT(*) FROM repeatcaller_incidents WHERE rule_id = 1')->fetchColumn();
	assert_same(2, $incidentCountByRoute, 'route-scoped identity should leave one adopted route incident and one second-route incident');
	$mainIncident = $dbRoute->query("SELECT id, subject_key, active_subject_key, subject_label, matched_call_count FROM repeatcaller_incidents WHERE rule_id = 1 AND subject_key = '" . $mainRouteSubjectKey . "' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
	$secondaryIncident = $dbRoute->query("SELECT id, subject_key, active_subject_key, subject_label, matched_call_count FROM repeatcaller_incidents WHERE rule_id = 1 AND subject_key = '" . $secondaryRouteSubjectKey . "' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
	assert_true(is_array($mainIncident), 'same caller on main route should produce its own incident row');
	assert_true(is_array($secondaryIncident), 'same caller on secondary route should produce its own incident row');
	assert_same($legacyIncidentId, (int)$mainIncident['id'], 'legacy caller-only active incident should be adopted and preserve incident id on first matching route');
	assert_same($mainRouteSubjectKey, (string)$mainIncident['subject_key'], 'adopted incident should move to route-scoped subject key');
	assert_same('1|' . $mainRouteSubjectKey, (string)$mainIncident['active_subject_key'], 'adopted incident should move active subject key to the route-scoped identity');
	assert_true(strpos((string)$mainIncident['subject_label'], 'Main') !== false, 'adopted incident subject label should update to route-aware label');
	assert_true((int)$mainIncident['id'] !== (int)$secondaryIncident['id'], 'different routes must map to different incident IDs');
	assert_same(0, (int)$dbRoute->query("SELECT COUNT(*) FROM repeatcaller_incidents WHERE rule_id = 1 AND subject_key = '" . $legacyCallerKey . "' AND state = 'active'")->fetchColumn(), 'legacy caller-only active incident row should not remain active after route adoption');
	assert_same(0, (int)$dbRoute->query("SELECT COUNT(*) FROM repeatcaller_rule_subject_state WHERE rule_id = 1 AND subject_key = '" . $legacyCallerKey . "'")->fetchColumn(), 'legacy caller-only subject state should be moved to the adopted route key');

	$routeRepeat = $processorRoute->run([
		'enabled' => '1',
		'default_country_code' => '44',
	]);
	assert_same(0, $routeRepeat['incidents_created'], 'repeated runs without new journeys must not create duplicate incidents after adoption');
	assert_same(0, $routeRepeat['incidents_updated'], 'repeated runs without new journeys must not corrupt adoption state');

	insert_cdr($dbRoute, ['linkedid' => 'RS6', 'calldate' => '2026-07-13 09:25:00', 'src' => '03334567820', 'clid' => '03334567820', 'did' => '18005550001', 'dst' => '18005550001']);
	$routeFollowup = $processorRoute->run([
		'enabled' => '1',
		'default_country_code' => '44',
	]);
	assert_same(0, $routeFollowup['incidents_created'], 'additional same-route calls after adoption should update existing incident, not create duplicates');
	assert_true($routeFollowup['incidents_updated'] >= 1, 'additional same-route calls after adoption should update the preserved incident id');
	$mainUpdated = $dbRoute->query('SELECT matched_call_count FROM repeatcaller_incidents WHERE id = ' . (int)$mainIncident['id'])->fetch(PDO::FETCH_ASSOC);
	$secondaryUnchanged = $dbRoute->query('SELECT matched_call_count FROM repeatcaller_incidents WHERE id = ' . (int)$secondaryIncident['id'])->fetch(PDO::FETCH_ASSOC);
	assert_same(4, (int)$mainUpdated['matched_call_count'], 'same caller on the same adopted route should keep updating the preserved incident id');
	assert_same(2, (int)$secondaryUnchanged['matched_call_count'], 'same caller on a different route should not update the other route incident');
	assert_same(1, (int)$dbRoute->query("SELECT COUNT(*) FROM repeatcaller_incidents WHERE rule_id = 1 AND subject_key = '" . $mainRouteSubjectKey . "' AND state = 'active'")->fetchColumn(), 'no duplicate active incident should exist for the adopted route');
	assert_true($repositoryRoute->acceptActiveIncident((int)$mainIncident['id'], 'admin', '2026-07-13 10:31:00', 'gui'), 'main-route incident should remain independently acceptable');
	assert_true($repositoryRoute->acceptActiveIncident((int)$secondaryIncident['id'], 'admin', '2026-07-13 10:32:00', 'gui'), 'secondary-route incident should remain independently acceptable');
	assert_same(2, (int)$dbRoute->query("SELECT COUNT(*) FROM repeatcaller_incidents WHERE rule_id = 1 AND state = 'accepted'")->fetchColumn(), 'both route incidents should remain independently acceptable without overwriting each other');

	$dbPath2 = tempnam(sys_get_temp_dir(), 'repeatcaller_runtime_');
	if ($dbPath2 === false) {
		throw new RuntimeException('Unable to create second runtime contract SQLite file');
	}
	[$db2, $repository2, $scanner2, $processor2] = create_runtime_environment($dbPath2, '2026-07-13 15:30:00');
	insert_route($db2, '18005550001', '', 'Main');
	insert_route($db2, '18005550002', '', 'Secondary');
	insert_rule($db2, [
		'name' => 'Scheduled Rule',
		'mode' => 'repeat',
		'threshold_count' => 2,
		'observation_window_minutes' => 600,
		'caller_mode' => 'specific_only',
		'did_scope_mode' => 'all',
		'schedules' => [
			['day' => 1, 'start' => '09:00', 'end' => '10:00'],
			['day' => 1, 'start' => '14:00', 'end' => '15:00'],
		],
		'include_callers' => ['+441234567890'],
	]);
	insert_rule($db2, [
		'name' => 'DID Exclusion Rule',
		'mode' => 'repeat',
		'threshold_count' => 2,
		'observation_window_minutes' => 60,
		'caller_mode' => 'specific_only',
		'did_scope_mode' => 'selected',
		'schedules' => [['day' => 1, 'start' => '09:00', 'end' => '17:00']],
		'include_callers' => ['+441231111111'],
		'include_routes' => ['18005550001|', '18005550002|'],
		'exclude_routes' => ['18005550002|'],
	]);
	insert_rule($db2, [
		'name' => 'Caller Exclusion Rule',
		'mode' => 'repeat',
		'threshold_count' => 2,
		'observation_window_minutes' => 60,
		'caller_mode' => 'specific_only',
		'did_scope_mode' => 'all',
		'schedules' => [['day' => 1, 'start' => '09:00', 'end' => '17:00']],
		'include_callers' => ['+441299999999'],
		'exclude_callers' => ['+441299999999'],
	]);

	insert_cdr($db2, ['linkedid' => 'S1', 'calldate' => '2026-07-13 09:10:00', 'src' => '01234567890', 'clid' => '01234567890']);
	insert_cdr($db2, ['linkedid' => 'S2', 'calldate' => '2026-07-13 14:10:00', 'src' => '01234567890', 'clid' => '01234567890']);
	insert_cdr($db2, ['linkedid' => 'D1', 'calldate' => '2026-07-13 09:20:00', 'src' => '01231111111', 'clid' => '01231111111', 'did' => '18005550001', 'dst' => '18005550001']);
	insert_cdr($db2, ['linkedid' => 'D2', 'calldate' => '2026-07-13 09:30:00', 'src' => '01231111111', 'clid' => '01231111111', 'did' => '18005550002', 'dst' => '18005550002']);
	insert_cdr($db2, ['linkedid' => 'C1', 'calldate' => '2026-07-13 10:00:00', 'src' => '01299999999', 'clid' => '01299999999']);
	insert_cdr($db2, ['linkedid' => 'C2', 'calldate' => '2026-07-13 10:10:00', 'src' => '01299999999', 'clid' => '01299999999']);

	$summary2 = $processor2->run([
		'enabled' => '1',
		'default_country_code' => '44',
	]);
	assert_same(1, $summary2['incidents_created'], 'schedules, DID exclusions, and caller exclusions should leave only the scheduled rule incident');
	assert_same(1, (int)$db2->query('SELECT COUNT(*) FROM repeatcaller_incidents')->fetchColumn(), 'only one rule should create an incident in the exclusion scenario');

	$dbPath3 = tempnam(sys_get_temp_dir(), 'repeatcaller_runtime_');
	if ($dbPath3 === false) {
		throw new RuntimeException('Unable to create third runtime contract SQLite file');
	}
	[$db3, $repository3, $scanner3, $processor3] = create_runtime_environment($dbPath3, '2026-07-13 11:30:00');
	insert_route($db3, '18005550001', '', 'Main');
	insert_rule($db3, [
		'name' => 'Invert Rule',
		'mode' => 'invert',
		'threshold_count' => 2,
		'observation_window_minutes' => 60,
		'caller_mode' => 'any',
		'did_scope_mode' => 'all',
		'schedules' => [['day' => 1, 'start' => '09:00', 'end' => '12:00']],
	]);
	insert_cdr($db3, ['linkedid' => 'I1', 'calldate' => '2026-07-13 09:15:00', 'src' => '01234567890', 'clid' => '01234567890']);

	$invertFirst = $processor3->run([
		'enabled' => '1',
		'default_country_code' => '44',
	]);
	assert_same(1, $invertFirst['incidents_created'], 'invert mode should create an incident after the full window elapses without reaching threshold');
	$invertIncident = $db3->query('SELECT mode, matched_call_count FROM repeatcaller_incidents WHERE rule_id = 1')->fetch(PDO::FETCH_ASSOC);
	assert_same('invert', $invertIncident['mode'], 'invert incidents must persist with mode invert');
	assert_same(1, (int)$invertIncident['matched_call_count'], 'invert incidents should record one under-threshold call in the evaluated window');
	$invertSecond = $processor3->run([
		'enabled' => '1',
		'default_country_code' => '44',
	]);
	assert_same(0, $invertSecond['incidents_created'], 'invert mode should not duplicate the same active incident on repeated runs');

	$dbPath3Zero = tempnam(sys_get_temp_dir(), 'repeatcaller_runtime_');
	if ($dbPath3Zero === false) {
		throw new RuntimeException('Unable to create zero-call invert runtime contract SQLite file');
	}
	[$db3Zero, $repository3Zero, $scanner3Zero, $processor3Zero] = create_runtime_environment($dbPath3Zero, '2026-07-13 10:30:00');
	insert_route($db3Zero, '18005550001', '', 'Main');
	insert_rule($db3Zero, [
		'name' => 'Invert Zero Call Rule',
		'mode' => 'invert',
		'threshold_count' => 3,
		'observation_window_minutes' => 60,
		'caller_mode' => 'any',
		'did_scope_mode' => 'all',
		'schedules' => [['day' => 1, 'start' => '09:00', 'end' => '12:00']],
	]);
	$invertZero = $processor3Zero->run([
		'enabled' => '1',
		'default_country_code' => '44',
	]);
	assert_same(1, $invertZero['incidents_created'], 'invert mode should create an incident for a zero-call window after the observation window expires');
	$invertZeroIncident = $db3Zero->query('SELECT matched_call_count, mode FROM repeatcaller_incidents WHERE rule_id = 1')->fetch(PDO::FETCH_ASSOC);
	assert_same(0, (int)$invertZeroIncident['matched_call_count'], 'invert zero-call incidents should record zero matched calls');
	assert_same('invert', $invertZeroIncident['mode'], 'invert zero-call incidents must persist with mode invert');

	$dbPath3Met = tempnam(sys_get_temp_dir(), 'repeatcaller_runtime_');
	if ($dbPath3Met === false) {
		throw new RuntimeException('Unable to create threshold-met invert runtime contract SQLite file');
	}
	[$db3Met, $repository3Met, $scanner3Met, $processor3Met] = create_runtime_environment($dbPath3Met, '2026-07-13 10:30:00');
	insert_route($db3Met, '18005550001', '', 'Main');
	insert_rule($db3Met, [
		'name' => 'Invert Threshold Met Rule',
		'mode' => 'invert',
		'threshold_count' => 2,
		'observation_window_minutes' => 60,
		'caller_mode' => 'any',
		'did_scope_mode' => 'all',
		'schedules' => [['day' => 1, 'start' => '09:00', 'end' => '12:00']],
	]);
	insert_cdr($db3Met, ['linkedid' => 'IM1', 'calldate' => '2026-07-13 09:10:00', 'src' => '01222222222', 'clid' => '01222222222']);
	insert_cdr($db3Met, ['linkedid' => 'IM2', 'calldate' => '2026-07-13 09:20:00', 'src' => '01222222222', 'clid' => '01222222222']);
	$invertMet = $processor3Met->run([
		'enabled' => '1',
		'default_country_code' => '44',
	]);
	assert_same(0, $invertMet['incidents_created'], 'invert mode should not create an incident when the threshold is reached within the observation window');
	assert_same(0, (int)$db3Met->query('SELECT COUNT(*) FROM repeatcaller_incidents')->fetchColumn(), 'threshold-met invert windows should leave no incident behind');

	$dbPath3Continuous = tempnam(sys_get_temp_dir(), 'repeatcaller_runtime_');
	if ($dbPath3Continuous === false) {
		throw new RuntimeException('Unable to create continuous-schedule invert runtime contract SQLite file');
	}
	[$db3Continuous, $repository3Continuous, $scanner3Continuous, $processor3Continuous] = create_runtime_environment($dbPath3Continuous, '2026-07-13 10:30:00');
	$db3Continuous->prepare('INSERT INTO repeatcaller_rules (name, enabled, enabled_at, email_enabled, alert_call_enabled, alert_call_destinations, alert_call_recording_id, mode, threshold_count, observation_window_minutes, caller_mode, exclude_withheld, did_scope_mode, repeat_mode_override, suppression_minutes_override, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
		->execute(['Invert Continuous Schedule Rule', 1, '2026-07-13 09:00:00', 0, 0, null, null, 'invert', 3, 60, 'any', 0, 'all', null, null, '2026-07-13 09:00:00', '2026-07-13 09:00:00']);
	$continuousRuleId = (int)$db3Continuous->lastInsertId();
	$db3Continuous->prepare('INSERT INTO repeatcaller_rule_schedules (rule_id, day_of_week, start_time, end_time, created_at) VALUES (?, ?, ?, ?, ?)')
		->execute([$continuousRuleId, -1, '09:00:00', '24:00:00', '2026-07-13 09:00:00']);

	$continuousSummary = $processor3Continuous->run([
		'enabled' => '1',
		'default_country_code' => '44',
	]);
	assert_same(1, $continuousSummary['incidents_created'], 'invert mode should initialize from a continuous day_of_week schedule and create an incident once the window elapses');
	$continuousSubjectKey = '__invert_rule__' . $continuousRuleId;
	$continuousState = $repository3Continuous->loadSubjectState($continuousRuleId, $continuousSubjectKey);
	assert_true(is_array($continuousState), 'invert mode should persist subject state when schedule anchor is sourced from day_of_week/start_time keys');
	$continuousIncident = $db3Continuous->query('SELECT mode, matched_call_count FROM repeatcaller_incidents WHERE rule_id = ' . $continuousRuleId . " AND subject_key = '" . $continuousSubjectKey . "' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
	assert_true(is_array($continuousIncident), 'invert mode should create an incident for a completed continuous schedule window');
	assert_same('invert', (string)$continuousIncident['mode'], 'continuous schedule invert incident should persist with invert mode');
	assert_same(0, (int)$continuousIncident['matched_call_count'], 'continuous schedule invert incident should record zero calls when threshold is unmet');

	$dbPath3Direct = tempnam(sys_get_temp_dir(), 'repeatcaller_runtime_');
	if ($dbPath3Direct === false) {
		throw new RuntimeException('Unable to create direct-invert-repro runtime contract SQLite file');
	}
	[$db3Direct, $repository3Direct, $scanner3Direct, $processor3Direct] = create_runtime_environment($dbPath3Direct, '2026-08-05 15:47:00');
	insert_route($db3Direct, '18005550001', '', 'Main');
	$directInvertRuleId = insert_rule($db3Direct, [
		'name' => 'Invert Direct Repro Rule',
		'mode' => 'invert',
		'threshold_count' => 1,
		'observation_window_minutes' => 60,
		'caller_mode' => 'any',
		'did_scope_mode' => 'all',
		'schedules' => [['day' => -1, 'start' => '00:00', 'end' => '24:00']],
		'created_at' => '2026-08-05 15:47:00',
		'updated_at' => '2026-08-05 15:47:00',
		'enabled_at' => '2026-08-05 15:47:00',
	]);
	$directFirstRun = $processor3Direct->run([
		'enabled' => '1',
		'default_country_code' => '44',
	]);
	assert_same(0, $directFirstRun['incidents_created'], 'a newly enabled invert rule must not create an immediate historical incident on first monitor run');
	assert_same(0, (int)$db3Direct->query('SELECT COUNT(*) FROM repeatcaller_incidents WHERE rule_id = ' . $directInvertRuleId)->fetchColumn(), 'first run should not create an invert incident for the new rule');
	assert_same(0, (int)$db3Direct->query('SELECT COUNT(*) FROM repeatcaller_incident_alert_history WHERE rule_id = ' . $directInvertRuleId)->fetchColumn(), 'first run should not reserve or send alerts when no incident exists');
	$directSubject = '__invert_rule__' . $directInvertRuleId;
	$directState = $repository3Direct->loadSubjectState($directInvertRuleId, $directSubject);
	assert_true(is_array($directState), 'first run should persist invert subject state for the new rule');
	assert_same('2026-08-05 15:47:00', (string)$directState['current_window_started_at'], 'first run should anchor the invert window start at enabled_at for new enabled rules');
	assert_same('2026-08-05 16:47:00', (string)$directState['current_window_ends_at'], 'first run should set first invert window end one full observation window after enabled_at');

	$directNowProvider = function (): string {
		return '2026-08-05 16:48:00';
	};
	$processor3DirectFollowup = new BackgroundProcessor(
		$db3Direct,
		$repository3Direct,
		new CdrScanner($db3Direct, $directNowProvider),
		$directNowProvider
	);
	$directSecondRun = $processor3DirectFollowup->run([
		'enabled' => '1',
		'default_country_code' => '44',
	]);
	assert_same(1, $directSecondRun['incidents_created'], 'the first invert incident should be created only after the first full post-activation window completes');
	$directIncident = $db3Direct->query('SELECT first_matched_at, last_matched_at, matched_call_count FROM repeatcaller_incidents WHERE rule_id = ' . $directInvertRuleId . ' ORDER BY id DESC LIMIT 1')->fetch(PDO::FETCH_ASSOC);
	assert_true(is_array($directIncident), 'follow-up run should create one invert incident for the new rule');
	assert_same('2026-08-05 16:47:00', (string)$directIncident['first_matched_at'], 'new-rule invert incident first_matched_at should be the first completed post-activation window end');
	assert_same('2026-08-05 16:47:00', (string)$directIncident['last_matched_at'], 'new-rule invert incident last_matched_at should be the first completed post-activation window end');

	$dbPath3ModeTransition = tempnam(sys_get_temp_dir(), 'repeatcaller_runtime_');
	if ($dbPath3ModeTransition === false) {
		throw new RuntimeException('Unable to create mode-transition invert runtime contract SQLite file');
	}
	[$db3ModeTransition, $repository3ModeTransition, $scanner3ModeTransition, $processor3ModeTransition] = create_runtime_environment($dbPath3ModeTransition, '2026-08-05 15:47:00');
	insert_route($db3ModeTransition, '18005550001', '', 'Main');
	$modeTransitionRuleId = insert_rule($db3ModeTransition, [
		'name' => 'Repeat To Invert Runtime Rule',
		'mode' => 'repeat',
		'threshold_count' => 2,
		'observation_window_minutes' => 60,
		'caller_mode' => 'any',
		'did_scope_mode' => 'all',
		'schedules' => [['day' => -1, 'start' => '00:00', 'end' => '24:00']],
		'created_at' => '2026-08-05 09:00:00',
		'updated_at' => '2026-08-05 09:00:00',
		'enabled_at' => '2026-08-05 09:00:00',
	]);
	$repository3ModeTransition->saveRule([
		'id' => $modeTransitionRuleId,
		'name' => 'Repeat To Invert Runtime Rule',
		'enabled' => 1,
		'email_enabled' => 0,
		'alert_call_enabled' => 0,
		'alert_call_destinations' => '',
		'alert_call_strategy' => 'ringall',
		'alert_call_keep_trying' => 1,
		'alert_call_recording_id' => null,
		'alert_call_handle_callerid_upstream' => 0,
		'alert_call_callerid' => '',
		'mode' => 'invert',
		'threshold_count' => 1,
		'observation_window_minutes' => 60,
		'caller_mode' => 'any',
		'exclude_withheld' => 0,
		'did_scope_mode' => 'all',
		'repeat_mode_override' => null,
		'suppression_minutes_override' => null,
		'schedules' => [['day' => -1, 'start' => '00:00', 'end' => '24:00']],
		'callers' => [],
		'dids' => [],
	], '2026-08-05 15:47:00');
	$modeTransitionRule = $repository3ModeTransition->loadRule($modeTransitionRuleId);
	assert_same('2026-08-05 15:47:00', (string)$modeTransitionRule['enabled_at'], 'repeat-to-invert transition for an enabled rule should re-anchor enabled_at to transition time');
	$modeTransitionFirstRun = $processor3ModeTransition->run([
		'enabled' => '1',
		'default_country_code' => '44',
	]);
	assert_same(0, $modeTransitionFirstRun['incidents_created'], 'repeat-to-invert transition should not create a retrospective incident in the same monitor run');
	$modeTransitionSubject = '__invert_rule__' . $modeTransitionRuleId;
	$modeTransitionState = $repository3ModeTransition->loadSubjectState($modeTransitionRuleId, $modeTransitionSubject);
	assert_true(is_array($modeTransitionState), 'repeat-to-invert transition should seed a fresh invert subject window state');
	assert_same('2026-08-05 15:47:00', (string)$modeTransitionState['current_window_started_at'], 'repeat-to-invert transition should start the first invert window at transition time');

	$modeTransitionNowProvider = function (): string {
		return '2026-08-05 16:48:00';
	};
	$processor3ModeTransitionFollowup = new BackgroundProcessor(
		$db3ModeTransition,
		$repository3ModeTransition,
		new CdrScanner($db3ModeTransition, $modeTransitionNowProvider),
		$modeTransitionNowProvider
	);
	$modeTransitionSecondRun = $processor3ModeTransitionFollowup->run([
		'enabled' => '1',
		'default_country_code' => '44',
	]);
	assert_same(1, $modeTransitionSecondRun['incidents_created'], 'repeat-to-invert transition should create first invert incident only after the first full post-transition window');
	$modeTransitionIncident = $db3ModeTransition->query('SELECT first_matched_at, last_matched_at FROM repeatcaller_incidents WHERE rule_id = ' . $modeTransitionRuleId . ' ORDER BY id DESC LIMIT 1')->fetch(PDO::FETCH_ASSOC);
	assert_true(is_array($modeTransitionIncident), 'repeat-to-invert follow-up run should create a single invert incident');
	assert_same('2026-08-05 16:47:00', (string)$modeTransitionIncident['first_matched_at'], 'repeat-to-invert incident first_matched_at should use post-transition window boundary');
	assert_same('2026-08-05 16:47:00', (string)$modeTransitionIncident['last_matched_at'], 'repeat-to-invert incident last_matched_at should use post-transition window boundary');

	$dbPath3ReenableLinked = tempnam(sys_get_temp_dir(), 'repeatcaller_runtime_');
	if ($dbPath3ReenableLinked === false) {
		throw new RuntimeException('Unable to create invert re-enable linked-state runtime contract SQLite file');
	}
	[$db3ReenableLinked, $repository3ReenableLinked, $scanner3ReenableLinked, $processor3ReenableLinked] = create_runtime_environment($dbPath3ReenableLinked, '2026-08-05 15:47:00');
	insert_route($db3ReenableLinked, '18005550001', '', 'Main');
	$reenableLinkedRuleId = insert_rule($db3ReenableLinked, [
		'name' => 'Invert Re-enable Linked State Rule',
		'mode' => 'invert',
		'threshold_count' => 1,
		'observation_window_minutes' => 60,
		'caller_mode' => 'any',
		'did_scope_mode' => 'all',
		'schedules' => [['day' => -1, 'start' => '00:00', 'end' => '24:00']],
		'created_at' => '2026-08-05 09:00:00',
		'updated_at' => '2026-08-05 09:00:00',
		'enabled_at' => '2026-08-05 09:00:00',
	]);
	$reenableLinkedSubject = '__invert_rule__' . $reenableLinkedRuleId;
	$db3ReenableLinked->prepare('INSERT INTO repeatcaller_incidents (rule_id, subject_key, active_subject_key, subject_label, caller_normalized, caller_display, withheld_caller, mode, threshold_count, observation_window_minutes, first_matched_at, last_matched_at, matched_call_count, state, accepted_by, accepted_at, accept_source, suppression_expires_at, cleared_at, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
		->execute([
			$reenableLinkedRuleId,
			$reenableLinkedSubject,
			null,
			'Any caller',
			null,
			'Any caller',
			0,
			'invert',
			1,
			60,
			'2026-08-05 10:00:00',
			'2026-08-05 10:00:00',
			0,
			'accepted',
			'admin',
			'2026-08-05 10:05:00',
			'gui',
			'2026-08-05 17:00:00',
			null,
			'2026-08-05 10:00:00',
			'2026-08-05 10:05:00',
		]);
	$reenableLinkedIncidentId = (int)$db3ReenableLinked->lastInsertId();
	$repository3ReenableLinked->saveSubjectState($reenableLinkedRuleId, $reenableLinkedSubject, [
		'current_window_started_at' => '2026-08-05 14:00:00',
		'current_window_ends_at' => '2026-08-05 15:00:00',
		'current_window_call_count' => 0,
		'threshold_met' => 1,
		'clear_observed_since_trigger' => 1,
		'active_incident_id' => $reenableLinkedIncidentId,
		'suppression_expires_at' => '2026-08-05 17:00:00',
		'last_call_at' => null,
		'last_evaluated_at' => '2026-08-05 15:00:00',
		'created_at' => '2026-08-05 10:00:00',
		'updated_at' => '2026-08-05 15:00:00',
	]);
	$repository3ReenableLinked->setRuleEnabled($reenableLinkedRuleId, false, '2026-08-05 15:46:00');
	$repository3ReenableLinked->setRuleEnabled($reenableLinkedRuleId, true, '2026-08-05 15:47:00');
	$reenableRule = $repository3ReenableLinked->loadRule($reenableLinkedRuleId);
	assert_same('2026-08-05 15:47:00', (string)$reenableRule['enabled_at'], 'invert disable-to-enable transition should stamp a fresh enabled_at activation boundary');

	$reenableImmediate = $processor3ReenableLinked->run([
		'enabled' => '1',
		'default_country_code' => '44',
	]);
	assert_same(0, $reenableImmediate['incidents_created'], 're-enabled invert rule with linked incident state should not create an immediate duplicate incident');
	assert_same(1, (int)$db3ReenableLinked->query('SELECT COUNT(*) FROM repeatcaller_incidents WHERE rule_id = ' . $reenableLinkedRuleId)->fetchColumn(), 're-enabled invert rule should keep exactly one linked incident row on immediate run');
	assert_same(0, (int)$db3ReenableLinked->query('SELECT COUNT(*) FROM repeatcaller_incident_alert_history WHERE rule_id = ' . $reenableLinkedRuleId)->fetchColumn(), 're-enabled invert rule immediate run should not create alert history without new incident creation');
	$reenableStateImmediate = $repository3ReenableLinked->loadSubjectState($reenableLinkedRuleId, $reenableLinkedSubject);
	assert_true(is_array($reenableStateImmediate), 're-enabled invert rule should preserve a subject-state row');
	assert_same($reenableLinkedIncidentId, (int)$reenableStateImmediate['active_incident_id'], 're-enabled invert rule should keep existing incident linkage consistent after first run');
	assert_same('2026-08-05 17:00:00', (string)$reenableStateImmediate['suppression_expires_at'], 're-enabled invert rule should keep existing suppression linkage state after first run');

	$reenableNowProvider = function (): string {
		return '2026-08-05 16:48:00';
	};
	$processor3ReenableLinkedFollowup = new BackgroundProcessor(
		$db3ReenableLinked,
		$repository3ReenableLinked,
		new CdrScanner($db3ReenableLinked, $reenableNowProvider),
		$reenableNowProvider
	);
	$reenableAfterWindow = $processor3ReenableLinkedFollowup->run([
		'enabled' => '1',
		'default_country_code' => '44',
	]);
	assert_same(0, $reenableAfterWindow['incidents_created'], 'after the first full post-enable window, linked invert incident state should not create a duplicate incident');
	assert_same(1, (int)$db3ReenableLinked->query('SELECT COUNT(*) FROM repeatcaller_incidents WHERE rule_id = ' . $reenableLinkedRuleId)->fetchColumn(), 'after post-enable window processing, linked invert lifecycle should still have exactly one incident row');
	assert_same(0, (int)$db3ReenableLinked->query('SELECT COUNT(*) FROM repeatcaller_incident_alert_history WHERE rule_id = ' . $reenableLinkedRuleId)->fetchColumn(), 'post-enable window processing should not bypass suppression/linked state to create alerts from duplicate incidents');
	assert_same(1, (int)$db3ReenableLinked->query('SELECT COUNT(*) FROM repeatcaller_incidents WHERE rule_id = ' . $reenableLinkedRuleId . ' AND state IN (\'active\', \'accepted\')')->fetchColumn(), 'post-enable window processing should not create a second open incident when one is already linked');

	$dbPath3LegacyUpgrade = tempnam(sys_get_temp_dir(), 'repeatcaller_runtime_');
	if ($dbPath3LegacyUpgrade === false) {
		throw new RuntimeException('Unable to create legacy-upgrade invert runtime contract SQLite file');
	}
	[$db3LegacyUpgrade, $repository3LegacyUpgrade, $scanner3LegacyUpgrade, $processor3LegacyUpgrade] = create_runtime_environment($dbPath3LegacyUpgrade, '2026-07-13 15:47:00');
	insert_route($db3LegacyUpgrade, '18005550001', '', 'Main');
	$legacyUpgradeRuleId = insert_rule($db3LegacyUpgrade, [
		'name' => 'Invert Legacy Upgrade Rule',
		'mode' => 'invert',
		'threshold_count' => 1,
		'observation_window_minutes' => 60,
		'caller_mode' => 'any',
		'did_scope_mode' => 'all',
		'schedules' => [['day' => 1, 'start' => '00:00', 'end' => '23:59']],
		'created_at' => '2026-07-10 00:00:00',
		'updated_at' => '2026-07-10 00:00:00',
		'enabled_at' => null,
	]);
	$legacyInitFirst = $processor3LegacyUpgrade->run([
		'enabled' => '1',
		'default_country_code' => '44',
	]);
	assert_same(0, $legacyInitFirst['incidents_created'], 'legacy upgrade invert rules without enabled_at or window state should not replay historical completed windows on first run');
	assert_same(0, (int)$db3LegacyUpgrade->query('SELECT COUNT(*) FROM repeatcaller_incidents WHERE rule_id = ' . $legacyUpgradeRuleId)->fetchColumn(), 'legacy upgrade invert first run should not create an immediate incident');
	$legacyInitSubject = '__invert_rule__' . $legacyUpgradeRuleId;
	$legacyInitState = $repository3LegacyUpgrade->loadSubjectState($legacyUpgradeRuleId, $legacyInitSubject);
	assert_true(is_array($legacyInitState), 'legacy upgrade invert first run should persist subject window state for continuity');
	assert_same('2026-07-13 15:47:00', (string)$legacyInitState['current_window_started_at'], 'legacy upgrade invert first run should anchor the initial window at current monitor time when enabled_at is unavailable');
	assert_same('2026-07-13 16:47:00', (string)$legacyInitState['current_window_ends_at'], 'legacy upgrade invert first run should persist the first full-window end from the anchored boundary');

	$legacyUpgradeNowProvider = function (): string {
		return '2026-07-13 16:48:00';
	};
	$processor3LegacyUpgradeFollowup = new BackgroundProcessor(
		$db3LegacyUpgrade,
		$repository3LegacyUpgrade,
		new CdrScanner($db3LegacyUpgrade, $legacyUpgradeNowProvider),
		$legacyUpgradeNowProvider
	);
	$legacyInitSecond = $processor3LegacyUpgradeFollowup->run([
		'enabled' => '1',
		'default_country_code' => '44',
	]);
	assert_same(1, $legacyInitSecond['incidents_created'], 'legacy upgrade invert rules should create the first incident only after the first full post-boundary window elapses');
	$legacyIncident = $db3LegacyUpgrade->query('SELECT first_matched_at, last_matched_at, matched_call_count FROM repeatcaller_incidents WHERE rule_id = ' . $legacyUpgradeRuleId . ' ORDER BY id DESC LIMIT 1')->fetch(PDO::FETCH_ASSOC);
	assert_true(is_array($legacyIncident), 'legacy upgrade invert follow-up run should create one incident row');
	assert_same('2026-07-13 16:47:00', (string)$legacyIncident['first_matched_at'], 'legacy upgrade invert incident first_matched_at should align to the first completed post-boundary window end');
	assert_same('2026-07-13 16:47:00', (string)$legacyIncident['last_matched_at'], 'legacy upgrade invert incident last_matched_at should align to the first completed post-boundary window end');
	assert_same(0, (int)$legacyIncident['matched_call_count'], 'legacy upgrade invert incident should preserve zero-call semantics when threshold is unmet');

	$dbPath3LegacyState = tempnam(sys_get_temp_dir(), 'repeatcaller_runtime_');
	if ($dbPath3LegacyState === false) {
		throw new RuntimeException('Unable to create legacy-state-preservation invert runtime contract SQLite file');
	}
	[$db3LegacyState, $repository3LegacyState, $scanner3LegacyState, $processor3LegacyState] = create_runtime_environment($dbPath3LegacyState, '2026-07-13 14:30:00');
	insert_route($db3LegacyState, '18005550001', '', 'Main');
	$legacyStateRuleId = insert_rule($db3LegacyState, [
		'name' => 'Invert Legacy State Rule',
		'mode' => 'invert',
		'threshold_count' => 3,
		'observation_window_minutes' => 60,
		'caller_mode' => 'any',
		'did_scope_mode' => 'all',
		'schedules' => [['day' => 1, 'start' => '00:00', 'end' => '23:59']],
		'created_at' => '2026-07-10 00:00:00',
		'updated_at' => '2026-07-10 00:00:00',
		'enabled_at' => null,
	]);
	$legacyStateSubject = '__invert_rule__' . $legacyStateRuleId;
	$db3LegacyState->prepare('INSERT INTO repeatcaller_rule_subject_state (rule_id, subject_key, current_window_started_at, current_window_ends_at, current_window_call_count, threshold_met, clear_observed_since_trigger, active_incident_id, suppression_expires_at, last_call_at, last_evaluated_at, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
		->execute([
			$legacyStateRuleId,
			$legacyStateSubject,
			'2026-07-13 14:00:00',
			'2026-07-13 15:00:00',
			0,
			0,
			0,
			null,
			null,
			null,
			'2026-07-13 14:00:00',
			'2026-07-13 14:00:00',
			'2026-07-13 14:00:00',
		]);
	$legacyStateRun = $processor3LegacyState->run([
		'enabled' => '1',
		'default_country_code' => '44',
	]);
	assert_same(0, $legacyStateRun['incidents_created'], 'legacy invert rules with persisted window state should not be reinitialized during upgrade continuity');
	$legacyStatePersisted = $repository3LegacyState->loadSubjectState($legacyStateRuleId, $legacyStateSubject);
	assert_true(is_array($legacyStatePersisted), 'legacy persisted state should remain available after monitor run');
	assert_same('2026-07-13 14:00:00', (string)$legacyStatePersisted['current_window_started_at'], 'legacy persisted current_window_started_at should be preserved when no full window has elapsed');

	$dbPath3Call = tempnam(sys_get_temp_dir(), 'repeatcaller_runtime_');
	if ($dbPath3Call === false) {
		throw new RuntimeException('Unable to create call alert runtime contract SQLite file');
	}
	[$db3Call, $repository3Call, $scanner3Call, $processor3Call] = create_runtime_environment($dbPath3Call, '2026-07-13 10:10:00');
	insert_route($db3Call, '18005550001', '', 'Main');
	$ruleCallEnabled = insert_rule($db3Call, [
		'name' => 'Call Alert Rule',
		'mode' => 'repeat',
		'threshold_count' => 2,
		'observation_window_minutes' => 60,
		'caller_mode' => 'specific_only',
		'did_scope_mode' => 'all',
		'include_callers' => ['+441234567890'],
		'alert_call_enabled' => 1,
		'alert_call_destinations' => '100,200',
		'alert_call_recording_id' => 42,
		'repeat_mode_override' => 'never',
		'schedules' => [['day' => 1, 'start' => '09:00', 'end' => '17:00']],
	]);
	$ruleCallDisabled = insert_rule($db3Call, [
		'name' => 'No Call Alert Rule',
		'mode' => 'repeat',
		'threshold_count' => 2,
		'observation_window_minutes' => 60,
		'caller_mode' => 'specific_only',
		'did_scope_mode' => 'all',
		'include_callers' => ['+441233333333'],
		'alert_call_enabled' => 0,
		'alert_call_destinations' => '300',
		'alert_call_recording_id' => 77,
		'repeat_mode_override' => 'never',
		'schedules' => [['day' => 1, 'start' => '09:00', 'end' => '17:00']],
	]);
	insert_cdr($db3Call, ['linkedid' => 'CA1', 'calldate' => '2026-07-13 10:00:00', 'src' => '01234567890', 'clid' => '01234567890']);
	insert_cdr($db3Call, ['linkedid' => 'CA2', 'calldate' => '2026-07-13 10:05:00', 'src' => '01234567890', 'clid' => '01234567890']);
	insert_cdr($db3Call, ['linkedid' => 'CB1', 'calldate' => '2026-07-13 10:00:00', 'src' => '01233333333', 'clid' => '01233333333']);
	insert_cdr($db3Call, ['linkedid' => 'CB2', 'calldate' => '2026-07-13 10:05:00', 'src' => '01233333333', 'clid' => '01233333333']);
	$summaryCallRuntime = $processor3Call->run([
		'enabled' => '1',
		'default_country_code' => '44',
	]);
	assert_same(2, $summaryCallRuntime['incidents_created'], 'runtime should create incidents for both enabled and disabled call-alert rules when threshold is met');
	$callSender = new FakeCallSender();
	$callSender->failuresByDestination['200'] = 'originate failed';
	$alertsCall = new IncidentAlertProcessor($repository3Call, null, function (): string {
		return '2026-07-13 10:10:00';
	}, $callSender);
	$callSummary = $alertsCall->run([
		'alert_enabled' => '0',
						'global_snoozed_until' => '',
		'alert_history_prune_policy' => 'never',
		'incident_history_prune_policy' => 'never',
	]);
	assert_same(2, $callSummary['alert_call_queued'], 'enabled call alerts should reserve one alert_call history row per configured destination');
	assert_same(1, $callSummary['alert_call_sent'], 'successful call alerts should be marked sent');
	assert_same(1, $callSummary['alert_call_failed'], 'failed call alerts should be marked failed');
	assert_same(2, count($callSender->calls), 'call transport should be invoked once per enabled alert-call destination');
	assert_same('42', $callSender->calls[0]['recording_id'], 'call transport should receive the persisted recording id');
	assert_same(0, (int)$db3Call->query("SELECT COUNT(*) FROM repeatcaller_incident_alert_history WHERE rule_id = {$ruleCallDisabled} AND action_type = 'alert_call'")->fetchColumn(), 'disabled call alerts should not reserve alert_call history rows');
	assert_same(1, (int)$db3Call->query("SELECT COUNT(*) FROM repeatcaller_incident_alert_history WHERE rule_id = {$ruleCallEnabled} AND action_type = 'alert_call' AND delivery_status = 'sent'")->fetchColumn(), 'successful call alert delivery result should be stored in alert history');
	assert_same(1, (int)$db3Call->query("SELECT COUNT(*) FROM repeatcaller_incident_alert_history WHERE rule_id = {$ruleCallEnabled} AND action_type = 'alert_call' AND delivery_status = 'failed'")->fetchColumn(), 'failed call alert delivery result should be stored in alert history');

	$dbPath7 = tempnam(sys_get_temp_dir(), 'repeatcaller_runtime_');
	if ($dbPath7 === false) {
		throw new RuntimeException('Unable to create suppression runtime contract SQLite file');
	}
	$dbPath7Default = tempnam(sys_get_temp_dir(), 'repeatcaller_runtime_');
	if ($dbPath7Default === false) {
		throw new RuntimeException('Unable to create default suppression runtime contract SQLite file');
	}
	[$db7Default, $repository7Default, $scanner7Default, $processor7Default] = create_runtime_environment($dbPath7Default, '2026-07-13 09:20:00');
	insert_route($db7Default, '18005550001', '', 'Main');
	$db7Default->prepare('INSERT INTO repeatcaller_rules (name, enabled, enabled_at, email_enabled, alert_call_enabled, alert_call_destinations, alert_call_recording_id, mode, threshold_count, observation_window_minutes, caller_mode, exclude_withheld, did_scope_mode, repeat_mode_override, suppression_minutes_override, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
		->execute(['Default Suppression Rule', 1, '2026-07-13 09:00:00', 0, 0, null, null, 'repeat', 2, 60, 'any', 0, 'all', null, null, '2026-07-13 09:00:00', '2026-07-13 09:00:00']);
	$defaultSuppressionRuleId = (int)$db7Default->lastInsertId();
	$db7Default->prepare('INSERT INTO repeatcaller_rule_schedules (rule_id, day_of_week, start_time, end_time, created_at) VALUES (?, ?, ?, ?, ?)')
		->execute([$defaultSuppressionRuleId, 1, '09:00:00', '17:00:00', '2026-07-13 09:00:00']);
	insert_cdr($db7Default, ['linkedid' => 'DSP1', 'calldate' => '2026-07-13 09:00:00', 'src' => '01230000000', 'clid' => '01230000000']);
	insert_cdr($db7Default, ['linkedid' => 'DSP2', 'calldate' => '2026-07-13 09:05:00', 'src' => '01230000000', 'clid' => '01230000000']);
	$defaultSuppressionRun = $processor7Default->run([
		'enabled' => '1',
		'default_country_code' => '44',
	]);
	assert_same(1, $defaultSuppressionRun['incidents_created'], 'null suppression override should still allow incident creation once threshold is met');
	$defaultSuppressionIncident = $db7Default->query('SELECT suppression_expires_at FROM repeatcaller_incidents WHERE rule_id = ' . $defaultSuppressionRuleId . ' ORDER BY id DESC LIMIT 1')->fetch(PDO::FETCH_ASSOC);
	assert_true(is_array($defaultSuppressionIncident), 'default suppression fallback scenario should create an incident row');
	assert_same('2026-07-14 09:05:00', (string)$defaultSuppressionIncident['suppression_expires_at'], 'null suppression override should fall back to the default 1440 minute suppression period');

	$dbPath7Disabled = tempnam(sys_get_temp_dir(), 'repeatcaller_runtime_');
	if ($dbPath7Disabled === false) {
		throw new RuntimeException('Unable to create disabled suppression runtime contract SQLite file');
	}
	[$db7Disabled, $repository7Disabled, $scanner7Disabled, $processor7Disabled] = create_runtime_environment($dbPath7Disabled, '2026-07-13 09:20:00');
	insert_route($db7Disabled, '18005550001', '', 'Main');
	$db7Disabled->prepare('INSERT INTO repeatcaller_rules (name, enabled, enabled_at, email_enabled, alert_call_enabled, alert_call_destinations, alert_call_recording_id, mode, threshold_count, observation_window_minutes, caller_mode, exclude_withheld, did_scope_mode, repeat_mode_override, suppression_minutes_override, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
		->execute(['Disabled Suppression Rule', 1, '2026-07-13 09:00:00', 0, 0, null, null, 'repeat', 2, 60, 'any', 0, 'all', null, 0, '2026-07-13 09:00:00', '2026-07-13 09:00:00']);
	$disabledSuppressionRuleId = (int)$db7Disabled->lastInsertId();
	$db7Disabled->prepare('INSERT INTO repeatcaller_rule_schedules (rule_id, day_of_week, start_time, end_time, created_at) VALUES (?, ?, ?, ?, ?)')
		->execute([$disabledSuppressionRuleId, 1, '09:00:00', '17:00:00', '2026-07-13 09:00:00']);
	insert_cdr($db7Disabled, ['linkedid' => 'DSUP1', 'calldate' => '2026-07-13 09:00:00', 'src' => '01231111111', 'clid' => '01231111111']);
	insert_cdr($db7Disabled, ['linkedid' => 'DSUP2', 'calldate' => '2026-07-13 09:05:00', 'src' => '01231111111', 'clid' => '01231111111']);
	$disabledSuppressionRun = $processor7Disabled->run([
		'enabled' => '1',
		'default_country_code' => '44',
	]);
	assert_same(1, $disabledSuppressionRun['incidents_created'], '0 suppression override should still allow incident creation once threshold is met');
	$disabledSuppressionIncident = $db7Disabled->query('SELECT suppression_expires_at FROM repeatcaller_incidents WHERE rule_id = ' . $disabledSuppressionRuleId . ' ORDER BY id DESC LIMIT 1')->fetch(PDO::FETCH_ASSOC);
	assert_true(is_array($disabledSuppressionIncident), 'disabled suppression scenario should create an incident row');
	assert_same('2026-07-13 09:05:00', (string)$disabledSuppressionIncident['suppression_expires_at'], '0 suppression override should resolve and persist an immediate suppression expiry on the incident');

	[$db7, $repository7, $scanner7, $processor7] = create_runtime_environment($dbPath7, '2026-07-13 09:20:00');
	insert_route($db7, '18005550001', '', 'Main');
	insert_rule($db7, [
		'name' => 'Suppression Rule',
		'mode' => 'repeat',
		'threshold_count' => 2,
		'observation_window_minutes' => 60,
		'caller_mode' => 'any',
		'did_scope_mode' => 'all',
		'schedules' => [['day' => 1, 'start' => '09:00', 'end' => '17:00']],
	]);
	insert_cdr($db7, ['linkedid' => 'SP1', 'calldate' => '2026-07-13 09:00:00', 'src' => '01234567890', 'clid' => '01234567890']);
	insert_cdr($db7, ['linkedid' => 'SP2', 'calldate' => '2026-07-13 09:05:00', 'src' => '01234567890', 'clid' => '01234567890']);
	$firstSuppressionRun = $processor7->run([
		'enabled' => '1',
		'default_country_code' => '44',
	]);
	assert_same(1, $firstSuppressionRun['incidents_created'], 'first suppression scenario run should create the active incident');
	assert_same(0, count($repository7->loadSuppressedIncidentHistory()), 'no suppression row should exist after the initial incident is created');
	$suppressionIncidentRow = $db7->query('SELECT id, subject_key FROM repeatcaller_incidents ORDER BY id DESC LIMIT 1')->fetch(PDO::FETCH_ASSOC);
	assert_true(is_array($suppressionIncidentRow), 'suppression scenario should create an incident row');
	$suppressionSubjectKey = (string)($suppressionIncidentRow['subject_key'] ?? '');
	$repository7->markConditionCleared(1, $suppressionSubjectKey, '2026-07-13 09:06:00');
	$suppressionTokensBefore = $repository7->loadUiChangeTokens();
	insert_cdr($db7, ['linkedid' => 'SP3', 'calldate' => '2026-07-13 09:10:00', 'src' => '01234567890', 'clid' => '01234567890']);
	$secondSuppressionRun = $processor7->run([
		'enabled' => '1',
		'default_country_code' => '44',
	]);
	assert_same(0, $secondSuppressionRun['incidents_created'], 'suppressed follow-up calls should not create a second incident');
	assert_same(0, $secondSuppressionRun['incidents_updated'], 'suppressed follow-up calls should not update a closed incident');
	$suppressedHistoryRows = $repository7->loadSuppressedIncidentHistory();
	assert_same(1, count($suppressedHistoryRows), 'runtime suppression path should record one suppression-history row');
	assert_same('Suppression Rule', (string)$suppressedHistoryRows[0]['rule_name'], 'runtime suppression-history row should keep the rule name');
	assert_same(3, (int)$suppressedHistoryRows[0]['matched_call_count'], 'runtime suppression-history row should capture the actual qualifying call count for the blocked attempt');
	assert_same('2026-07-13 09:05:00', (string)$suppressedHistoryRows[0]['suppression_started_at'], 'runtime suppression-history row should persist the suppression start time');
	assert_same('2026-07-13 09:35:00', (string)$suppressedHistoryRows[0]['suppression_expires_at'], 'runtime suppression-history row should persist the suppression expiry time');
	assert_true((int)$suppressedHistoryRows[0]['related_incident_id'] > 0, 'runtime suppression-history row should point at the created incident');
	$operationalSuppressedRows = $repository7->loadActiveSuppressedIncidents('2026-07-13 09:11:00');
	assert_same(1, count($operationalSuppressedRows), 'operational suppressed-incidents view should include active uncleared suppression rows');
	$expiredOperationalSuppressedRows = $repository7->loadActiveSuppressedIncidents('2026-07-13 09:36:00');
	assert_same(0, count($expiredOperationalSuppressedRows), 'operational suppressed-incidents view should exclude expired suppression rows');
	$suppressionTokens = $repository7->loadUiChangeTokens();
	assert_true($suppressionTokens['suppressedIncidents'] !== $suppressionTokensBefore['suppressedIncidents'], 'suppression token should change after a new suppression-history row');
	assert_true($repository7->clearSuppressedIncidentHistory((int)$suppressedHistoryRows[0]['id'], '2026-07-13 09:12:00'), 'manual suppression clear should preserve the audit row');
	$clearedSuppressionRows = $repository7->loadSuppressedIncidentHistory();
	assert_same('2026-07-13 09:12:00', (string)$clearedSuppressionRows[0]['cleared_at'], 'manual clear should be persisted on the audit row');
	$operationalSuppressedAfterClear = $repository7->loadActiveSuppressedIncidents('2026-07-13 09:13:00');
	assert_same(0, count($operationalSuppressedAfterClear), 'operational suppressed-incidents view should exclude cleared suppression rows');
	$suppressionTokensAfterClear = $repository7->loadUiChangeTokens();
	assert_true($suppressionTokensAfterClear['suppressedIncidents'] !== $suppressionTokens['suppressedIncidents'], 'manual clear should change the suppression-history token');
	insert_cdr($db7, ['linkedid' => 'SP4', 'calldate' => '2026-07-13 09:15:00', 'src' => '01234567890', 'clid' => '01234567890']);
	$thirdSuppressionRun = $processor7->run([
		'enabled' => '1',
		'default_country_code' => '44',
	]);
	assert_same(1, $thirdSuppressionRun['incidents_created'], 'manual clear should allow the subject to trigger again immediately');
	assert_same(1, count($repository7->loadSuppressedIncidentHistory()), 'manual clear should preserve the audit row until a fresh refusal occurs');

	$dbPath7AcceptedLifecycle = tempnam(sys_get_temp_dir(), 'repeatcaller_runtime_');
	if ($dbPath7AcceptedLifecycle === false) {
		throw new RuntimeException('Unable to create accepted suppression lifecycle runtime contract SQLite file');
	}
	[$db7AcceptedLifecycle, $repository7AcceptedLifecycle, $scanner7AcceptedLifecycle, $processor7AcceptedLifecycle] = create_runtime_environment($dbPath7AcceptedLifecycle, '2026-07-13 09:11:00');
	insert_route($db7AcceptedLifecycle, '18005550001', '', 'Main');
	$acceptedLifecycleRuleId = insert_rule($db7AcceptedLifecycle, [
		'name' => 'Accepted Suppression Lifecycle Rule',
		'mode' => 'repeat',
		'threshold_count' => 3,
		'observation_window_minutes' => 30,
		'caller_mode' => 'any',
		'did_scope_mode' => 'all',
		'suppression_minutes_override' => '',
		'schedules' => [['day' => 1, 'start' => '09:00', 'end' => '17:00']],
	]);

	insert_cdr($db7AcceptedLifecycle, ['linkedid' => 'ASL1', 'calldate' => '2026-07-13 09:00:00', 'src' => '01234440000', 'clid' => '01234440000']);
	insert_cdr($db7AcceptedLifecycle, ['linkedid' => 'ASL2', 'calldate' => '2026-07-13 09:05:00', 'src' => '01234440000', 'clid' => '01234440000']);
	insert_cdr($db7AcceptedLifecycle, ['linkedid' => 'ASL3', 'calldate' => '2026-07-13 09:10:00', 'src' => '01234440000', 'clid' => '01234440000']);
	$acceptedLifecycleFirstRun = $processor7AcceptedLifecycle->run([
		'enabled' => '1',
		'default_country_code' => '44',
	]);
	assert_same(1, $acceptedLifecycleFirstRun['incidents_created'], 'first threshold crossing should create one incident for accepted lifecycle scenario');
	$acceptedLifecycleIncident = $db7AcceptedLifecycle->query('SELECT id, subject_key, state, first_matched_at, last_matched_at, matched_call_count, suppression_expires_at FROM repeatcaller_incidents WHERE rule_id = ' . $acceptedLifecycleRuleId . ' ORDER BY id DESC LIMIT 1')->fetch(PDO::FETCH_ASSOC);
	assert_true(is_array($acceptedLifecycleIncident), 'accepted lifecycle scenario should create an incident row');
	$acceptedLifecycleIncidentId = (int)$acceptedLifecycleIncident['id'];
	$acceptedLifecycleSubjectKey = (string)$acceptedLifecycleIncident['subject_key'];
	assert_same('active', (string)$acceptedLifecycleIncident['state'], 'initial accepted lifecycle incident should begin as active');
	assert_same('2026-07-13 09:00:00', (string)$acceptedLifecycleIncident['first_matched_at'], 'repeat incident first_matched_at should capture the earliest contributing call in the threshold window');
	assert_same('2026-07-13 09:10:00', (string)$acceptedLifecycleIncident['last_matched_at'], 'repeat incident last_matched_at should capture the latest contributing call at incident creation');
	assert_same('2026-07-14 09:10:00', (string)$acceptedLifecycleIncident['suppression_expires_at'], 'accepted lifecycle scenario should use the 24-hour default suppression window');
	assert_true($repository7AcceptedLifecycle->acceptActiveIncident($acceptedLifecycleIncidentId, 'tester', '2026-07-13 09:12:00', 'gui'), 'accepted lifecycle scenario should allow accepting the active incident');

	insert_cdr($db7AcceptedLifecycle, ['linkedid' => 'ASL4', 'calldate' => '2026-07-13 09:15:00', 'src' => '01234440000', 'clid' => '01234440000']);
	insert_cdr($db7AcceptedLifecycle, ['linkedid' => 'ASL5', 'calldate' => '2026-07-13 09:20:00', 'src' => '01234440000', 'clid' => '01234440000']);
	insert_cdr($db7AcceptedLifecycle, ['linkedid' => 'ASL6', 'calldate' => '2026-07-13 09:25:00', 'src' => '01234440000', 'clid' => '01234440000']);
	$acceptedLifecycleSecondProcessor = new BackgroundProcessor($db7AcceptedLifecycle, $repository7AcceptedLifecycle, $scanner7AcceptedLifecycle, static function (): string {
		return '2026-07-13 09:26:00';
	});
	$acceptedLifecycleSecondRun = $acceptedLifecycleSecondProcessor->run([
		'enabled' => '1',
		'default_country_code' => '44',
	]);
	assert_same(0, $acceptedLifecycleSecondRun['incidents_created'], 'continued qualifying calls while accepted should not create a new incident');
	assert_true($acceptedLifecycleSecondRun['incidents_updated'] > 0, 'continued qualifying calls while accepted should update the tracked incident');
	assert_same(0, count($repository7AcceptedLifecycle->loadSuppressedIncidentHistory()), 'continued qualifying calls while accepted should not create suppression-history rows');
	$acceptedLifecycleAfterUpdate = $db7AcceptedLifecycle->query('SELECT state, matched_call_count, last_matched_at FROM repeatcaller_incidents WHERE id = ' . $acceptedLifecycleIncidentId . ' LIMIT 1')->fetch(PDO::FETCH_ASSOC);
	assert_same('accepted', (string)$acceptedLifecycleAfterUpdate['state'], 'accepted lifecycle incident should remain accepted while threshold stays true');
	assert_true((int)$acceptedLifecycleAfterUpdate['matched_call_count'] >= 6, 'accepted lifecycle incident should continue accumulating matching call count while accepted');
	assert_same('2026-07-13 09:25:00', (string)$acceptedLifecycleAfterUpdate['last_matched_at'], 'later qualifying calls should continue advancing last_matched_at on the tracked incident');

	insert_cdr($db7AcceptedLifecycle, ['linkedid' => 'ASL7', 'calldate' => '2026-07-13 09:56:00', 'src' => '01234440000', 'clid' => '01234440000']);
	$acceptedLifecycleClearProcessor = new BackgroundProcessor($db7AcceptedLifecycle, $repository7AcceptedLifecycle, $scanner7AcceptedLifecycle, static function (): string {
		return '2026-07-13 09:57:00';
	});
	$acceptedLifecycleClearRun = $acceptedLifecycleClearProcessor->run([
		'enabled' => '1',
		'default_country_code' => '44',
	]);
	assert_same(0, $acceptedLifecycleClearRun['incidents_created'], 'clear-observation pass should not create a new incident');
	assert_same(0, $acceptedLifecycleClearRun['incidents_updated'], 'clear-observation pass should close prior incident instead of updating it');
	$acceptedLifecycleStateAfterClear = $repository7AcceptedLifecycle->loadSubjectState($acceptedLifecycleRuleId, $acceptedLifecycleSubjectKey);
	assert_true(is_array($acceptedLifecycleStateAfterClear), 'accepted lifecycle scenario should retain subject state after clear observation');
	assert_same(1, (int)$acceptedLifecycleStateAfterClear['clear_observed_since_trigger'], 'accepted lifecycle scenario should record clear_observed_since_trigger once threshold drops below 3 in 30 minutes');
	assert_same(0, (int)$acceptedLifecycleStateAfterClear['threshold_met'], 'accepted lifecycle scenario should clear threshold_met after falling below threshold');
	$acceptedLifecycleClosedIncident = $db7AcceptedLifecycle->query('SELECT state, suppression_expires_at, cleared_at FROM repeatcaller_incidents WHERE id = ' . $acceptedLifecycleIncidentId . ' LIMIT 1')->fetch(PDO::FETCH_ASSOC);
	assert_same('closed', (string)$acceptedLifecycleClosedIncident['state'], 'accepted lifecycle scenario should close the accepted incident once clear is observed');
	assert_same('2026-07-14 09:10:00', (string)$acceptedLifecycleClosedIncident['suppression_expires_at'], 'accepted lifecycle scenario should preserve suppression expiry on the closed incident');

	insert_cdr($db7AcceptedLifecycle, ['linkedid' => 'ASL8', 'calldate' => '2026-07-13 10:31:00', 'src' => '01234440000', 'clid' => '01234440000']);
	insert_cdr($db7AcceptedLifecycle, ['linkedid' => 'ASL9', 'calldate' => '2026-07-13 10:35:00', 'src' => '01234440000', 'clid' => '01234440000']);
	insert_cdr($db7AcceptedLifecycle, ['linkedid' => 'ASL10', 'calldate' => '2026-07-13 10:40:00', 'src' => '01234440000', 'clid' => '01234440000']);
	$acceptedLifecycleSuppressedProcessor = new BackgroundProcessor($db7AcceptedLifecycle, $repository7AcceptedLifecycle, $scanner7AcceptedLifecycle, static function (): string {
		return '2026-07-13 10:41:00';
	});
	$acceptedLifecycleSuppressedRun = $acceptedLifecycleSuppressedProcessor->run([
		'enabled' => '1',
		'default_country_code' => '44',
	]);
	assert_same(0, $acceptedLifecycleSuppressedRun['incidents_created'], 'fresh threshold before suppression expiry should be blocked from creating a new incident');
	assert_same(0, $acceptedLifecycleSuppressedRun['incidents_updated'], 'fresh blocked threshold should not update any tracked incident when none is active or accepted');
	$acceptedLifecycleSuppressedRows = $repository7AcceptedLifecycle->loadSuppressedIncidentHistory();
	assert_same(1, count($acceptedLifecycleSuppressedRows), 'fresh threshold before suppression expiry should create exactly one suppression-history row');
	assert_same($acceptedLifecycleIncidentId, (int)$acceptedLifecycleSuppressedRows[0]['related_incident_id'], 'suppression-history row should reference the previously accepted-and-closed incident');
	assert_same('closed', (string)$acceptedLifecycleSuppressedRows[0]['related_incident_state'], 'suppression-history row should capture the state of the related prior incident');
	assert_same(3, (int)$acceptedLifecycleSuppressedRows[0]['matched_call_count'], 'suppression-history row should capture the fresh qualifying threshold count');
	assert_same('2026-07-14 09:10:00', (string)$acceptedLifecycleSuppressedRows[0]['suppression_expires_at'], 'suppression-history row should preserve the active suppression expiry');
	$acceptedLifecycleOperationalSuppressed = $repository7AcceptedLifecycle->loadActiveSuppressedIncidents('2026-07-13 10:41:00');
	assert_same(1, count($acceptedLifecycleOperationalSuppressed), 'suppression-history row should be immediately visible in active suppressed incidents');
	assert_same($acceptedLifecycleIncidentId, (int)$acceptedLifecycleOperationalSuppressed[0]['related_incident_id'], 'operational suppressed-incidents row should reference the prior accepted incident');

	$matchBoundsInvariantMethod = new ReflectionMethod(BackgroundProcessor::class, 'incidentMatchBounds');
	$matchBoundsInvariantMethod->setAccessible(true);
	$invariantTriggered = false;
	try {
		$matchBoundsInvariantMethod->invoke($processor7AcceptedLifecycle, []);
	} catch (RuntimeException $e) {
		$invariantTriggered = strpos($e->getMessage(), 'empty contributing match set') !== false;
	}
	assert_true($invariantTriggered, 'repeat incident creation should treat an empty contributing match set as an invariant violation');

	$acceptedLifecycleStateAfterSuppressed = $repository7AcceptedLifecycle->loadSubjectState($acceptedLifecycleRuleId, $acceptedLifecycleSubjectKey);
	assert_true(is_array($acceptedLifecycleStateAfterSuppressed), 'accepted lifecycle scenario should retain subject state after blocked re-trigger');
	assert_same(1, (int)$acceptedLifecycleStateAfterSuppressed['threshold_met'], 'blocked re-trigger should set threshold_met back to true');
	assert_same(0, (int)$acceptedLifecycleStateAfterSuppressed['clear_observed_since_trigger'], 'blocked re-trigger should reset clear_observed_since_trigger after recording suppression history');

	$dbPath4 = tempnam(sys_get_temp_dir(), 'repeatcaller_runtime_');
	if ($dbPath4 === false) {
		throw new RuntimeException('Unable to create fourth runtime contract SQLite file');
	}
	[$db4a, $repository4a, $scanner4a, $processor4a] = create_runtime_environment($dbPath4, '2026-07-13 09:20:00');
	insert_route($db4a, '18005550001', '', 'Main');
	insert_rule($db4a, [
		'name' => 'Rearm Rule',
		'mode' => 'repeat',
		'threshold_count' => 2,
		'observation_window_minutes' => 60,
		'caller_mode' => 'any',
		'did_scope_mode' => 'all',
		'schedules' => [['day' => 1, 'start' => '09:00', 'end' => '17:00']],
		'suppression_minutes_override' => 10,
	]);
	insert_cdr($db4a, ['linkedid' => 'R1', 'calldate' => '2026-07-13 09:00:00']);
	insert_cdr($db4a, ['linkedid' => 'R2', 'calldate' => '2026-07-13 09:10:00']);
	$processor4a->run([
		'enabled' => '1',
		'default_country_code' => '44',
	]);

	[$db4b, $repository4b, $scanner4b, $processor4b] = create_runtime_environment($dbPath4, '2026-07-13 10:40:00');
	insert_cdr($db4b, ['linkedid' => 'R3', 'calldate' => '2026-07-13 10:40:00']);
	$processor4b->run([
		'enabled' => '1',
		'default_country_code' => '44',
	]);
	$rearmRouteSubjectKey = '+441234567890|route:' . hash('sha1', '18005550001|');
	$stateAfterClear = $repository4b->loadSubjectState(1, $rearmRouteSubjectKey);
	assert_true(is_array($stateAfterClear), 'suppression and clear state should be stored on the expected route-scoped caller key');
	assert_same(1, (int)$stateAfterClear['clear_observed_since_trigger'], 'suppression and clear state should be persisted when the condition falls below threshold');

	[$db4c, $repository4c, $scanner4c, $processor4c] = create_runtime_environment($dbPath4, '2026-07-13 10:50:00');
	insert_cdr($db4c, ['linkedid' => 'R4', 'calldate' => '2026-07-13 10:50:00']);
	$rearmSummary = $processor4c->run([
		'enabled' => '1',
		'default_country_code' => '44',
	]);
	assert_same(1, $rearmSummary['incidents_created'], 'the same caller should retrigger only after the condition clears and then crosses again');

	$dbPathFirstInstall = tempnam(sys_get_temp_dir(), 'repeatcaller_runtime_');
	if ($dbPathFirstInstall === false) {
		throw new RuntimeException('Unable to create first-install boundary contract SQLite file');
	}
	[$dbFirstInstall, $repositoryFirstInstall, $scannerFirstInstall, $processorFirstInstall] = create_runtime_environment($dbPathFirstInstall, '2026-07-13 10:00:00');
	insert_route($dbFirstInstall, '18005550001', '', 'Main');
	insert_rule($dbFirstInstall, [
		'name' => 'First Install Boundary Rule',
		'mode' => 'repeat',
		'threshold_count' => 2,
		'observation_window_minutes' => 120,
		'caller_mode' => 'any',
		'did_scope_mode' => 'all',
		'schedules' => [['day' => 1, 'start' => '09:00', 'end' => '17:00']],
	]);
	insert_cdr($dbFirstInstall, ['linkedid' => 'FI-HIST-1', 'calldate' => '2026-07-13 09:10:00']);
	insert_cdr($dbFirstInstall, ['linkedid' => 'FI-HIST-2', 'calldate' => '2026-07-13 09:20:00']);
	$firstInstallBoundarySettings = [
		'enabled' => '1',
		'default_country_code' => '44',
		'initial_processing_boundary_at' => '2026-07-13 09:30:00',
	];
	$firstInstallHistoricalRun = $processorFirstInstall->run($firstInstallBoundarySettings);
	assert_same(0, $firstInstallHistoricalRun['new_journeys'], 'historical CDR rows before first-install boundary must not be reserved as new journeys');
	assert_same(0, (int)$dbFirstInstall->query('SELECT COUNT(*) FROM repeatcaller_seen_calls')->fetchColumn(), 'historical CDR rows before first-install boundary must not be inserted into repeatcaller_seen_calls');
	assert_same(0, $firstInstallHistoricalRun['incidents_created'], 'historical CDR rows before first-install boundary must not create incidents');
	assert_same(0, (int)$dbFirstInstall->query('SELECT COUNT(*) FROM repeatcaller_incidents')->fetchColumn(), 'historical CDR rows before first-install boundary must not create incident records');
	assert_same(0, (int)$dbFirstInstall->query('SELECT COUNT(*) FROM repeatcaller_incident_alert_state')->fetchColumn(), 'historical CDR rows before first-install boundary must not create incident alert state records');
	assert_same(0, (int)$dbFirstInstall->query('SELECT COUNT(*) FROM repeatcaller_incident_alert_history')->fetchColumn(), 'historical CDR rows before first-install boundary must not create incident alert history records');
	assert_same(0, (int)$dbFirstInstall->query('SELECT COUNT(*) FROM repeatcaller_incident_suppression_history')->fetchColumn(), 'historical CDR rows before first-install boundary must not create suppression history rows');

	$dbPathBoundaryEquality = tempnam(sys_get_temp_dir(), 'repeatcaller_runtime_');
	if ($dbPathBoundaryEquality === false) {
		throw new RuntimeException('Unable to create boundary-equality contract SQLite file');
	}
	[$dbBoundaryEquality, $repositoryBoundaryEquality, $scannerBoundaryEquality, $processorBoundaryEquality] = create_runtime_environment($dbPathBoundaryEquality, '2026-07-13 10:00:00');
	insert_route($dbBoundaryEquality, '18005550001', '', 'Main');
	insert_rule($dbBoundaryEquality, [
		'name' => 'Boundary Equality Rule',
		'mode' => 'repeat',
		'threshold_count' => 1,
		'observation_window_minutes' => 120,
		'caller_mode' => 'any',
		'did_scope_mode' => 'all',
		'schedules' => [['day' => 1, 'start' => '09:00', 'end' => '17:00']],
	]);
	insert_cdr($dbBoundaryEquality, ['linkedid' => 'BE-HIST', 'calldate' => '2026-07-13 09:29:59']);
	insert_cdr($dbBoundaryEquality, ['linkedid' => 'BE-EQ', 'calldate' => '2026-07-13 09:30:00']);
	$boundaryEqualityRun = $processorBoundaryEquality->run([
		'enabled' => '1',
		'default_country_code' => '44',
		'initial_processing_boundary_at' => '2026-07-13 09:30:00',
	]);
	assert_same(1, $boundaryEqualityRun['new_journeys'], 'call_completed_at exactly equal to initial_processing_boundary_at must be included');
	assert_same(1, $boundaryEqualityRun['incidents_created'], 'boundary-equality inclusion should still trigger incident creation when threshold is met');
	assert_same(1, (int)$dbBoundaryEquality->query("SELECT COUNT(*) FROM repeatcaller_seen_calls WHERE call_identity = 'BE-EQ'")->fetchColumn(), 'boundary-equality call should be reserved as seen');
	assert_same(0, (int)$dbBoundaryEquality->query("SELECT COUNT(*) FROM repeatcaller_seen_calls WHERE call_identity = 'BE-HIST'")->fetchColumn(), 'pre-boundary historical call should remain excluded from seen-call reservations');

	insert_cdr($dbFirstInstall, ['linkedid' => 'FI-NEW-1', 'calldate' => '2026-07-13 09:40:00']);
	insert_cdr($dbFirstInstall, ['linkedid' => 'FI-NEW-2', 'calldate' => '2026-07-13 09:50:00']);
	$firstInstallLiveRun = $processorFirstInstall->run($firstInstallBoundarySettings);
	assert_same(2, $firstInstallLiveRun['new_journeys'], 'calls completed after first-install boundary must be processed normally');
	assert_same(1, $firstInstallLiveRun['incidents_created'], 'post-install calls must still trigger incidents when threshold is met');

	insert_cdr($dbFirstInstall, ['linkedid' => 'FI-LATE-HIST', 'calldate' => '2026-07-13 09:25:00']);
	$firstInstallRestartHistoricalRun = $processorFirstInstall->run($firstInstallBoundarySettings);
	assert_same(0, $firstInstallRestartHistoricalRun['new_journeys'], 'restarting the background job must not move boundary backwards to include historical calls');

	$dbPathReinstallA = tempnam(sys_get_temp_dir(), 'repeatcaller_runtime_');
	if ($dbPathReinstallA === false) {
		throw new RuntimeException('Unable to create reinstall boundary baseline SQLite file');
	}
	[$dbReinstallA, $repositoryReinstallA, $scannerReinstallA, $processorReinstallA] = create_runtime_environment($dbPathReinstallA, '2026-07-13 10:00:00');
	insert_route($dbReinstallA, '18005550001', '', 'Main');
	insert_rule($dbReinstallA, [
		'name' => 'Reinstall Boundary Baseline Rule',
		'mode' => 'repeat',
		'threshold_count' => 1,
		'observation_window_minutes' => 120,
		'caller_mode' => 'any',
		'did_scope_mode' => 'all',
		'schedules' => [['day' => 1, 'start' => '09:00', 'end' => '17:00']],
	]);
	insert_cdr($dbReinstallA, ['linkedid' => 'RE-A-HIST', 'calldate' => '2026-07-13 09:20:00']);
	$reinstallBaselineRun = $processorReinstallA->run([
		'enabled' => '1',
		'default_country_code' => '44',
		'initial_processing_boundary_at' => '2026-07-13 09:30:00',
	]);
	assert_same(0, $reinstallBaselineRun['new_journeys'], 'baseline install boundary should ignore pre-boundary history');

	$dbPathReinstallB = tempnam(sys_get_temp_dir(), 'repeatcaller_runtime_');
	if ($dbPathReinstallB === false) {
		throw new RuntimeException('Unable to create reinstall boundary replacement SQLite file');
	}
	[$dbReinstallB, $repositoryReinstallB, $scannerReinstallB, $processorReinstallB] = create_runtime_environment($dbPathReinstallB, '2026-07-13 10:30:00');
	insert_route($dbReinstallB, '18005550001', '', 'Main');
	insert_rule($dbReinstallB, [
		'name' => 'Reinstall Boundary Replacement Rule',
		'mode' => 'repeat',
		'threshold_count' => 1,
		'observation_window_minutes' => 120,
		'caller_mode' => 'any',
		'did_scope_mode' => 'all',
		'schedules' => [['day' => 1, 'start' => '09:00', 'end' => '17:00']],
	]);
	insert_cdr($dbReinstallB, ['linkedid' => 'RE-B-HIST', 'calldate' => '2026-07-13 10:10:00']);
	insert_cdr($dbReinstallB, ['linkedid' => 'RE-B-NEW', 'calldate' => '2026-07-13 10:20:00']);
	$reinstallReplacementRun = $processorReinstallB->run([
		'enabled' => '1',
		'default_country_code' => '44',
		'initial_processing_boundary_at' => '2026-07-13 10:15:00',
	]);
	assert_same(1, $reinstallReplacementRun['new_journeys'], 'a fresh reinstall boundary should be re-established and only process post-reinstall calls');
	assert_same(1, $reinstallReplacementRun['incidents_created'], 'post-reinstall calls should still create incidents as normal');

	$dbPathUpgradeContinuity = tempnam(sys_get_temp_dir(), 'repeatcaller_runtime_');
	if ($dbPathUpgradeContinuity === false) {
		throw new RuntimeException('Unable to create upgrade continuity SQLite file');
	}
	[$dbUpgradeContinuity, $repositoryUpgradeContinuity, $scannerUpgradeContinuity, $processorUpgradeContinuity] = create_runtime_environment($dbPathUpgradeContinuity, '2026-07-13 10:30:00');
	insert_route($dbUpgradeContinuity, '18005550001', '', 'Main');
	insert_rule($dbUpgradeContinuity, [
		'name' => 'Upgrade Continuity Rule',
		'mode' => 'repeat',
		'threshold_count' => 1,
		'observation_window_minutes' => 120,
		'caller_mode' => 'any',
		'did_scope_mode' => 'all',
		'schedules' => [['day' => 1, 'start' => '09:00', 'end' => '17:00']],
	]);
	$dbUpgradeContinuity->prepare('INSERT INTO repeatcaller_seen_calls (call_identity, identity_type, fingerprint, linkedid, uniqueid, caller_raw, caller_normalized, inbound_route_key, did_value, call_started_at, call_completed_at, disposition, source_context, processed_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
		->execute(['UPG-SEEN-1', 'linkedid', hash('sha256', 'UPG-SEEN-1'), 'UPG-SEEN-1', 'UPG-SEEN-1', '01234567890', '+441234567890', '18005550001|', '18005550001', '2026-07-13 10:05:00', '2026-07-13 10:05:00', 'ANSWERED', 'from-trunk', '2026-07-13 10:05:00']);
	insert_cdr($dbUpgradeContinuity, ['linkedid' => 'UPG-SEEN-1', 'uniqueid' => 'UPG-SEEN-1', 'calldate' => '2026-07-13 10:05:00']);
	insert_cdr($dbUpgradeContinuity, ['linkedid' => 'UPG-NEW-1', 'uniqueid' => 'UPG-NEW-1', 'calldate' => '2026-07-13 10:20:00']);
	$upgradeContinuityRun = $processorUpgradeContinuity->run([
		'enabled' => '1',
		'default_country_code' => '44',
	]);
	assert_same(1, $upgradeContinuityRun['new_journeys'], 'existing upgraded installs without a boundary setting should retain continuity and process legitimate new calls');
	assert_same(1, $upgradeContinuityRun['incidents_created'], 'existing upgraded installs should continue creating incidents for new qualifying calls');

	$dbPath5 = tempnam(sys_get_temp_dir(), 'repeatcaller_runtime_');
	if ($dbPath5 === false) {
		throw new RuntimeException('Unable to create fifth runtime contract SQLite file');
	}
	[$db5, $repository5, $scanner5, $processor5] = create_runtime_environment($dbPath5, '2026-07-13 12:00:00');
	insert_route($db5, '18005550001', '', 'Main');
	insert_rule($db5, [
		'name' => 'Cap Rule',
		'mode' => 'repeat',
		'threshold_count' => 2,
		'observation_window_minutes' => 60,
		'caller_mode' => 'any',
		'did_scope_mode' => 'all',
		'schedules' => [['day' => 1, 'start' => '09:00', 'end' => '17:00']],
	]);
	for ($i = 0; $i < 5101; $i++) {
		$time = date('Y-m-d H:i:s', strtotime('2026-07-13 10:00:00') + $i);
		insert_cdr($db5, [
			'linkedid' => 'CAP-' . $i,
			'uniqueid' => 'CAP-U-' . $i,
			'calldate' => $time,
			'src' => '01235550000',
			'clid' => '01235550000',
			'did' => '18005550001',
			'dst' => '18005550001',
		]);
	}

	$scanCap = $scanner5->scanRecentInboundJourneys(240);
	assert_same(5000, $scanCap['raw_rows'], 'scanner should cap CDR rows at 5000');
	assert_true(!empty($scanCap['row_cap_reached']), 'scanner should signal when row cap is reached');
	assert_same(5000, $scanCap['collapsed_rows'], 'cap test rows should remain one journey per row under unique linkedid');
	assert_same(5000, $scanCap['inbound_journeys'], 'all capped rows should resolve to inbound journeys in this scenario');
	$summaryCap = $processor5->run([
		'enabled' => '1',
		'default_country_code' => '44',
	]);
	assert_same(true, (bool)$summaryCap['scan_row_cap_reached'], 'runtime summary should report when scanner returns the maximum row count');
	$firstReturned = $scanCap['journeys'][0]['completed_at'] ?? '';
	assert_same('2026-07-13 10:01:41', $firstReturned, 'scanner should retain newest 5000 rows, excluding the oldest 101 rows');
	$prev = '';
	foreach ($scanCap['journeys'] as $journey) {
		$current = (string)($journey['completed_at'] ?? '');
		if ($prev !== '') {
			assert_true($current >= $prev, 'journeys must be restored to chronological ascending order after capped newest-row selection');
		}
		$prev = $current;
	}

	$dbPath6 = tempnam(sys_get_temp_dir(), 'repeatcaller_runtime_');
	if ($dbPath6 === false) {
		throw new RuntimeException('Unable to create sixth runtime contract SQLite file');
	}
	[$db6, $repository6, $scanner6, $processor6] = create_runtime_environment($dbPath6, '2026-07-13 12:00:00');
	insert_route($db6, '18005550001', '', 'Main');
	insert_rule($db6, [
		'name' => 'Zero Calls',
		'mode' => 'repeat',
		'threshold_count' => 2,
		'observation_window_minutes' => 60,
		'caller_mode' => 'any',
		'did_scope_mode' => 'all',
		'schedules' => [['day' => 1, 'start' => '09:00', 'end' => '17:00']],
	]);
	$zeroSummary = $processor6->run([
		'enabled' => '1',
		'default_country_code' => '44',
	]);
	assert_same(0, $zeroSummary['scanned_rows'], 'normal no-call windows should remain successful with zero scanned rows');
	assert_same(false, $zeroSummary['scan_row_cap_reached'], 'normal zero-call windows should not mark row cap reached');

	$runtimeQuiet = new BackgroundProcessor($db6, $repository6, $scanner6, function (): string {
		return '2026-07-13 12:00:00';
	});
	$runtimeQuietSummary = $runtimeQuiet->run([
		'enabled' => '1',
		'default_country_code' => '44',
	]);
	assert_same($zeroSummary, $runtimeQuietSummary, 'runtime execution should preserve runtime summary payload values after routine summary logging removal');
	assert_true(array_key_exists('scanned_rows', $runtimeQuietSummary), 'runtime processor should continue returning structured runtime summaries');

	$alertsWithLogger = new IncidentAlertProcessor($repository6, null, function (): string {
		return '2026-07-13 12:00:00';
	});
	$db6->prepare('INSERT INTO repeatcaller_seen_calls (call_identity, identity_type, fingerprint, linkedid, uniqueid, caller_raw, caller_normalized, inbound_route_key, did_value, call_started_at, call_completed_at, disposition, source_context, processed_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
		->execute(['PRUNE-OLD-1', 'linkedid', hash('sha256', 'PRUNE-OLD-1'), 'PRUNE-OLD-1', 'PRUNE-OLD-U1', '01230000000', '+441230000000', '18005550001|', '18005550001', '2026-03-01 10:00:00', '2026-03-01 10:00:00', 'ANSWERED', 'from-trunk', '2026-03-01 10:00:05']);
	$db6->prepare('INSERT INTO repeatcaller_seen_calls (call_identity, identity_type, fingerprint, linkedid, uniqueid, caller_raw, caller_normalized, inbound_route_key, did_value, call_started_at, call_completed_at, disposition, source_context, processed_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
		->execute(['PRUNE-NEW-1', 'linkedid', hash('sha256', 'PRUNE-NEW-1'), 'PRUNE-NEW-1', 'PRUNE-NEW-U1', '01231111111', '+441231111111', '18005550001|', '18005550001', '2026-07-10 10:00:00', '2026-07-10 10:00:00', 'ANSWERED', 'from-trunk', '2026-07-10 10:00:05']);
	$alertQuietSummary = $alertsWithLogger->run([
		'alert_enabled' => '0',
						'global_snoozed_until' => '',
		'alert_history_prune_policy' => 'never',
		'incident_history_prune_policy' => 'never',
	]);
	assert_same(0, $alertQuietSummary['incidents_considered'], 'alert execution should preserve alert summary payload values after routine summary logging removal');
	assert_true(array_key_exists('initial_events', $alertQuietSummary), 'alert processor should continue returning structured alert summaries');
	assert_true(array_key_exists('pruned_seen_calls', $alertQuietSummary), 'alert processor summary should expose internal seen-call cleanup count');
	assert_same(1, (int)$alertQuietSummary['pruned_seen_calls'], 'pruning execution should remove old internal seen-call rows by fixed retention');
	assert_same(0, (int)$db6->query("SELECT COUNT(*) FROM repeatcaller_seen_calls WHERE call_identity = 'PRUNE-OLD-1'")->fetchColumn(), 'old internal seen-call rows should be removed during pruning execution');
	assert_same(1, (int)$db6->query("SELECT COUNT(*) FROM repeatcaller_seen_calls WHERE call_identity = 'PRUNE-NEW-1'")->fetchColumn(), 'recent internal seen-call rows should be preserved during pruning execution');
	$alertSourceNoDeadHelper = file_get_contents(__DIR__ . '/../src/IncidentAlertProcessor.php');
	assert_true($alertSourceNoDeadHelper !== false, 'src/IncidentAlertProcessor.php should be readable for dead-helper checks');
	assert_true(strpos($alertSourceNoDeadHelper, 'private function log(') === false, 'alert processor should not keep dead logger helpers after consolidation');

	$throwingPdo = new ThrowingMetadataPdo();
	$throwingScanner = new CdrScanner($throwingPdo, function (): string { return '2026-07-13 12:00:00'; });
	$metadataFailureThrown = false;
	try {
		$throwingScanner->scanRecentInboundJourneys(240);
	} catch (RuntimeException $e) {
		$metadataFailureThrown = true;
		assert_true(strpos($e->getMessage(), 'CDR metadata probe failed while checking table existence') !== false, 'metadata probe failures must propagate as contextual runtime errors');
	}
	assert_true($metadataFailureThrown, 'scanner metadata probe failures must not be converted into successful zero-row scans');

	// CDR records live in the separate asteriskcdrdb database on real FreePBX
	// installs (the PDO connection Repeat Caller is given is pointed at
	// asterisk), while SQLite-backed contract tests use an unqualified cdr
	// table in the same file. cdrTable() must resolve correctly for both,
	// and production code must route every cdr reference through it.
	$cdrTableMethod = new ReflectionMethod(CdrScanner::class, 'cdrTable');
	$cdrTableMethod->setAccessible(true);
	assert_same('cdr', $cdrTableMethod->invoke($scanner4c), 'cdrTable() must resolve to the unqualified cdr table under the sqlite driver used by contract tests');

	$cdrScannerSource = file_get_contents(__DIR__ . '/../src/CdrScanner.php');
	assert_true($cdrScannerSource !== false, 'src/CdrScanner.php should be readable');
	assert_true(strpos($cdrScannerSource, "'asteriskcdrdb.cdr'") !== false, 'cdrTable() must resolve to the schema-qualified asteriskcdrdb.cdr table for MySQL/MariaDB');
	assert_true(strpos($cdrScannerSource, "FROM ' . \$this->cdrTable() . '") !== false, 'the production CDR scan query must select FROM the resolved, schema-qualified cdr table rather than a hardcoded unqualified name');
	assert_true(strpos($cdrScannerSource, 'tableExists($this->cdrTable())') !== false, 'the cdr existence check must use the schema-qualified table reference');
	assert_true(strpos($cdrScannerSource, 'tableColumns($this->cdrTable())') !== false, 'the cdr column introspection must use the schema-qualified table reference');
	assert_true((bool)preg_match('/\bFROM\s+cdr\b(?!\.)/', $cdrScannerSource) === false, 'no query may reference an unqualified, hardcoded cdr table');

	$jobSource = file_get_contents(__DIR__ . '/../Job.php');
	assert_true($jobSource !== false, 'Job.php should be readable');
	assert_true(strpos($jobSource, 'writeln(') === false, 'scheduled job entrypoint must not write to stdout/stderr (prevents cron mail spam)');
	assert_true(strpos($jobSource, 'runBackgroundMonitor($output)') !== false, 'scheduled job entrypoint must use the standard monitor callback signature');
	assert_true(strpos($jobSource, 'FreePBX::Log()->error(') !== false, 'scheduled job entrypoint must log unhandled failures through FreePBX logging');

	$controllerSource = file_get_contents(__DIR__ . '/../Repeatcaller.class.php');
	assert_true($controllerSource !== false, 'Repeatcaller.class.php should be readable');
	assert_true(strpos($controllerSource, 'runBackgroundMonitor($output = null): bool') !== false, 'background monitor API should keep the standard monitor callback signature');
	assert_true(strpos($controllerSource, 'runBackgroundMonitorDetailed($output = null): array') !== false, 'background monitor detailed path should use the standard signature');
	assert_true(strpos($controllerSource, '$runtimeSummary = $runtime->run($settings);') !== false, 'background monitor should continue returning runtime summary payloads from processor execution');
	assert_true(strpos($controllerSource, '$alertSummary = $alerts->run($settings);') !== false, 'background monitor should continue returning alert summary payloads from processor execution');
	assert_true(strpos($controllerSource, "setSetting('engine_last_success_at'") !== false, 'background monitor must keep persisting engine_last_success_at');
	assert_true(strpos($controllerSource, "setSetting('engine_last_summary_json'") !== false, 'background monitor must keep persisting engine_last_summary_json');
	assert_true(strpos($controllerSource, "output->writeln") === false, 'background monitor path must not emit scheduler output on success/failure/skip');
	assert_true(strpos($controllerSource, "logError('Background job failed:") !== false, 'background monitor failures must be logged via module logging');

	$runtimeSource = file_get_contents(__DIR__ . '/../src/BackgroundProcessor.php');
	assert_true($runtimeSource !== false, 'src/BackgroundProcessor.php should be readable');
	assert_true(strpos($runtimeSource, 'Repeat Caller runtime scan:') === false, 'runtime processor must not emit the removed routine runtime summary string');
	assert_true(strpos($runtimeSource, 'private $logger;') === false, 'runtime processor should not keep an unused logger property');
	assert_true(strpos($runtimeSource, 'private function log(') === false, 'runtime processor should not keep an unused logger helper');
	assert_true(strpos($runtimeSource, 'strtotime($boundary)') !== false, 'initial_processing_boundary_at should be evaluated using strtotime in the same server timezone context as call timestamps');
	assert_true(strpos($runtimeSource, 'strtotime($completedAt)') !== false, 'call_completed_at should be evaluated using strtotime in the same server timezone context as initial_processing_boundary_at');
	assert_true(strpos($runtimeSource, '$completedTs < $boundaryTs') !== false, 'boundary comparison should remain strictly less-than so equality is intentionally included');
	$alertSource = file_get_contents(__DIR__ . '/../src/IncidentAlertProcessor.php');
	assert_true($alertSource !== false, 'src/IncidentAlertProcessor.php should be readable');
	assert_true(strpos($alertSource, 'Repeat Caller incident alert pass:') === false, 'alert processor must not emit the removed routine alert summary string');
	assert_true(strpos($alertSource, 'function __construct(RepeatCallerRepository $repository') !== false, 'alert processor should keep a single repository-driven constructor without unused PDO/logger wiring');
	assert_true(strpos($alertSource, 'reserveStageEvents($incident, $repeatMode, \'initial\'') !== false, 'initial stage scheduling must flow through reserveStageEvents');
	assert_true(strpos($alertSource, 'reserveStageEvents($incident, $repeatMode, \'reminder\'') !== false, 'reminder stage scheduling must flow through reserveStageEvents');
	assert_true(strpos($alertSource, 'private function reserveTransportEvent(') !== false, 'GUI/email/alert_call stage reservations should share one transport reservation helper');
	assert_true(strpos($alertSource, 'private function log(') === false, 'dead alert-processor logger helper should be removed');

	if (!interface_exists('Symfony\\Component\\Console\\Input\\InputInterface')) {
		eval('namespace Symfony\\Component\\Console\\Input; interface InputInterface {}');
	}
	if (!interface_exists('Symfony\\Component\\Console\\Output\\OutputInterface')) {
		eval('namespace Symfony\\Component\\Console\\Output; interface OutputInterface { public function isQuiet(); }');
	}
	if (!interface_exists('FreePBX\\Job\\TaskInterface')) {
		eval('namespace FreePBX\\Job; interface TaskInterface { public static function run(\\Symfony\\Component\\Console\\Input\\InputInterface $input, \\Symfony\\Component\\Console\\Output\\OutputInterface $output); }');
	}
	if (!class_exists('RuntimeContractDummyInput')) {
		eval('class RuntimeContractDummyInput implements \\Symfony\\Component\\Console\\Input\\InputInterface {}');
	}
	if (!class_exists('RuntimeContractDummyOutput')) {
		eval('class RuntimeContractDummyOutput implements \\Symfony\\Component\\Console\\Output\\OutputInterface { public function isQuiet() { return true; } }');
	}
	if (!class_exists('FreePBX')) {
		eval('class FreePBX { public static $repeatcaller; public static $logger; public static function Repeatcaller() { return self::$repeatcaller; } public static function Log() { return self::$logger; } }');
	}
	if (!class_exists('RuntimeContractRepeatcallerStub')) {
		eval('class RuntimeContractRepeatcallerStub { public $calls = []; public $throwOnRun = false; public function runBackgroundMonitor($output = null): bool { $this->calls[] = ["output_is_quiet" => (is_object($output) && method_exists($output, "isQuiet") ? (bool)$output->isQuiet() : null)]; if ($this->throwOnRun) { throw new RuntimeException("forced scheduler exception"); } return true; } }');
	}
	if (!class_exists('RuntimeContractLoggerStub')) {
		eval('class RuntimeContractLoggerStub { public $errors = []; public function error($message): void { $this->errors[] = (string)$message; } }');
	}

	require_once __DIR__ . '/../Job.php';

	$jobModuleStub = new RuntimeContractRepeatcallerStub();
	$jobLoggerStub = new RuntimeContractLoggerStub();
	\FreePBX::$repeatcaller = $jobModuleStub;
	\FreePBX::$logger = $jobLoggerStub;
	$jobOk = \FreePBX\modules\Repeatcaller\Job::run(new RuntimeContractDummyInput(), new RuntimeContractDummyOutput());
	assert_true($jobOk === true, 'scheduled class callback should return success when monitor run succeeds');
	assert_same(0, count($jobLoggerStub->errors), 'scheduled class callback success path should be silent on the error logger');
	assert_same(1, count($jobModuleStub->calls), 'scheduled class callback should invoke runBackgroundMonitor exactly once');
	assert_same(true, (bool)($jobModuleStub->calls[0]['output_is_quiet'] ?? false), 'scheduled class callback should pass the console OutputInterface instance used by FreePBX jobs');

	$jobModuleStub->throwOnRun = true;
	$jobFail = \FreePBX\modules\Repeatcaller\Job::run(new RuntimeContractDummyInput(), new RuntimeContractDummyOutput());
	assert_true($jobFail === false, 'scheduled class callback should return false when monitor run throws');
	assert_true(!empty($jobLoggerStub->errors), 'scheduled callback failures must be logged through the job error logger path');
	assert_true(strpos((string)$jobLoggerStub->errors[0], 'repeatcaller job: unhandled scheduler exception: forced scheduler exception') !== false, 'scheduled callback failure log should include the scheduler exception detail');

	echo "repeat runtime contract tests passed\n";
} finally {
	foreach (['dbPath', 'dbPathRouteNoActive', 'dbPathRoute', 'dbPath2', 'dbPath3', 'dbPath4', 'dbPath5', 'dbPath6', 'dbPathFirstInstall', 'dbPathBoundaryEquality', 'dbPathReinstallA', 'dbPathReinstallB', 'dbPathUpgradeContinuity'] as $pathVar) {
		if (isset($$pathVar) && is_string($$pathVar) && file_exists($$pathVar)) {
			unlink($$pathVar);
		}
	}
}
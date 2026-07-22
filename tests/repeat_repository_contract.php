<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/RepeatCallerRepository.php';

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

function create_repository(PDO $db): RepeatCallerRepository {
	$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
	$db->exec(
		'CREATE TABLE repeatcaller_rules (
			id INTEGER PRIMARY KEY AUTOINCREMENT,
			name TEXT NOT NULL,
			enabled INTEGER NOT NULL DEFAULT 1,
			email_enabled INTEGER NOT NULL DEFAULT 0,
			alert_call_enabled INTEGER NOT NULL DEFAULT 0,
			alert_call_destinations TEXT,
			alert_call_strategy TEXT NOT NULL DEFAULT "ringall",
			alert_call_keep_trying INTEGER NOT NULL DEFAULT 1,
			alert_call_recording_id INTEGER,
			alert_call_callerid TEXT,
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
		'CREATE TABLE repeatcaller_rule_schedules (
			id INTEGER PRIMARY KEY AUTOINCREMENT,
			rule_id INTEGER NOT NULL,
			day_of_week INTEGER NOT NULL,
			start_time TEXT NOT NULL,
			end_time TEXT NOT NULL,
			created_at TEXT
		)'
	);
	$db->exec(
		'CREATE TABLE repeatcaller_rule_callers (
			id INTEGER PRIMARY KEY AUTOINCREMENT,
			rule_id INTEGER NOT NULL,
			list_type TEXT NOT NULL,
			raw_value TEXT NOT NULL,
			normalized_value TEXT NOT NULL,
			created_at TEXT
		)'
	);
	$db->exec(
		'CREATE TABLE repeatcaller_rule_dids (
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
		'CREATE TABLE repeatcaller_seen_calls (
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
		'CREATE TABLE repeatcaller_rule_subject_state (
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
		'CREATE TABLE repeatcaller_incidents (
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
			claimed_by TEXT,
			claimed_at TEXT,
			claim_source TEXT,
			suppression_expires_at TEXT,
			cleared_at TEXT,
			created_at TEXT,
			updated_at TEXT
		)'
	);
	$db->exec(
		'CREATE TABLE repeatcaller_incident_alert_history (
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
		'CREATE TABLE repeatcaller_incident_suppression_history (
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
		'CREATE TABLE repeatcaller_settings (
			setting_key TEXT PRIMARY KEY,
			setting_value TEXT,
			updated_at TEXT
		)'
	);

	return new RepeatCallerRepository($db);
}

$dbPath = tempnam(sys_get_temp_dir(), 'repeatcaller_repo_');
if ($dbPath === false) {
	throw new RuntimeException('Unable to create temporary SQLite file');
}

try {
	$db = new PDO('sqlite:' . $dbPath);
	$repo = create_repository($db);

	$db->prepare('INSERT INTO repeatcaller_rules (name, enabled, email_enabled, alert_call_enabled, alert_call_destinations, alert_call_strategy, alert_call_keep_trying, alert_call_recording_id, alert_call_callerid, mode, threshold_count, observation_window_minutes, caller_mode, exclude_withheld, did_scope_mode, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
		->execute(['Rule A', 1, 1, 1, '100,101', 'ordered', 1, 55, '5551234', 'repeat', 2, 60, 'any', 0, 'selected', '2026-07-13 09:00:00', '2026-07-13 09:00:00']);
	$ruleId = (int)$db->lastInsertId();
	$db->prepare('INSERT INTO repeatcaller_rules (name, enabled, email_enabled, alert_call_enabled, alert_call_destinations, alert_call_strategy, alert_call_keep_trying, alert_call_recording_id, alert_call_callerid, mode, threshold_count, observation_window_minutes, caller_mode, exclude_withheld, did_scope_mode, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
		->execute(['Rule Disabled', 0, 0, 0, null, 'ringall', 1, null, null, 'repeat', 2, 60, 'any', 0, 'all', '2026-07-13 09:00:00', '2026-07-13 09:00:00']);

	$db->prepare('INSERT INTO repeatcaller_rule_schedules (rule_id, day_of_week, start_time, end_time, created_at) VALUES (?, ?, ?, ?, ?)')->execute([$ruleId, 1, '09:00:00', '12:00:00', '2026-07-13 09:00:00']);
	$db->prepare('INSERT INTO repeatcaller_rule_schedules (rule_id, day_of_week, start_time, end_time, created_at) VALUES (?, ?, ?, ?, ?)')->execute([$ruleId, 1, '14:00:00', '18:00:00', '2026-07-13 09:00:00']);
	$db->prepare('INSERT INTO repeatcaller_rule_callers (rule_id, list_type, raw_value, normalized_value, created_at) VALUES (?, ?, ?, ?, ?)')->execute([$ruleId, 'include', '01234567890', '+441234567890', '2026-07-13 09:00:00']);
	$db->prepare('INSERT INTO repeatcaller_rule_callers (rule_id, list_type, raw_value, normalized_value, created_at) VALUES (?, ?, ?, ?, ?)')->execute([$ruleId, 'exclude', '01230000001', '+441230000001', '2026-07-13 09:00:00']);
	$db->prepare('INSERT INTO repeatcaller_rule_dids (rule_id, list_type, route_key, route_label, did_value, cid_value, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)')->execute([$ruleId, 'include', '18005550001|', 'Main DID', '18005550001', '', '2026-07-13 09:00:00']);
	$db->prepare('INSERT INTO repeatcaller_rule_dids (rule_id, list_type, route_key, route_label, did_value, cid_value, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)')->execute([$ruleId, 'exclude', '18005550002|', 'Excluded DID', '18005550002', '', '2026-07-13 09:00:00']);

	$enabledRules = $repo->loadEnabledRules();
	assert_same(1, count($enabledRules), 'only enabled rules should be loaded');
	assert_same('Rule A', $enabledRules[0]['name'], 'enabled rule should round-trip');

	$invertRuleId = $repo->saveRule([
		'name' => 'Invert Recording Rule',
		'enabled' => 1,
		'email_enabled' => 0,
		'alert_call_enabled' => 1,
		'alert_call_destinations' => '300',
		'alert_call_strategy' => 'ringall',
		'alert_call_keep_trying' => 1,
		'alert_call_recording_id' => 77,
		'alert_call_callerid' => '',
		'mode' => 'invert',
		'threshold_count' => 2,
		'observation_window_minutes' => 60,
		'caller_mode' => 'any',
		'exclude_withheld' => 0,
		'did_scope_mode' => 'all',
		'repeat_mode_override' => 'never',
		'suppression_minutes_override' => null,
		'schedules' => [],
		'callers' => [],
		'dids' => [],
	], '2026-07-13 09:05:00');
	$invertRule = $repo->loadRule($invertRuleId);
	assert_same(77, (int)($invertRule['alert_call_recording_id'] ?? 0), 'invert rules should persist alert_call_recording_id the same as repeat rules');

	$schedules = $repo->loadSchedules([$ruleId]);
	assert_same(2, count($schedules[$ruleId]), 'multiple schedules should round-trip correctly');
	assert_same('09:00', $schedules[$ruleId][0]['start'], 'schedule start time should round-trip');
	assert_same('14:00', $schedules[$ruleId][1]['start'], 'second schedule should round-trip');

	$callers = $repo->loadCallerLists([$ruleId]);
	assert_same('+441234567890', $callers[$ruleId]['include'][0]['normalized_value'], 'caller includes should round-trip correctly');
	assert_same('+441230000001', $callers[$ruleId]['exclude'][0]['normalized_value'], 'caller exclusions should round-trip correctly');

	$dids = $repo->loadDidLists([$ruleId]);
	assert_same('18005550001|', $dids[$ruleId]['include'][0]['route_key'], 'selected DIDs should round-trip correctly');
	assert_same('18005550002|', $dids[$ruleId]['exclude'][0]['route_key'], 'DID exclusions should round-trip correctly');

	$firstSeen = [
		'call_identity' => 'linkedid-100',
		'identity_type' => 'linkedid',
		'fingerprint' => hash('sha256', 'linkedid-100'),
		'linkedid' => 'linkedid-100',
		'uniqueid' => 'uniqueid-100',
		'caller_raw' => '01234567890',
		'caller_normalized' => '+441234567890',
		'inbound_route_key' => '18005550001|',
		'did_value' => '18005550001',
		'call_started_at' => '2026-07-13 09:00:00',
		'call_completed_at' => '2026-07-13 09:00:00',
		'disposition' => 'ANSWERED',
		'source_context' => 'from-trunk',
		'processed_at' => '2026-07-13 09:00:05',
	];
	assert_true($repo->reserveSeenCallJourney($firstSeen), 'first seen call should be reserved');
	assert_true(!$repo->reserveSeenCallJourney($firstSeen), 'duplicate call identity cannot be inserted twice');

	$secondSeen = $firstSeen;
	$secondSeen['call_identity'] = 'linkedid-101';
	$secondSeen['fingerprint'] = hash('sha256', 'linkedid-101');
	$secondSeen['caller_raw'] = '01230000002';
	$secondSeen['caller_normalized'] = '+441230000002';
	$secondSeen['call_started_at'] = '2026-07-13 09:10:00';
	$secondSeen['call_completed_at'] = '2026-07-13 09:10:00';
	$secondSeen['processed_at'] = '2026-07-13 09:10:05';
	assert_true($repo->reserveSeenCallJourney($secondSeen), 'separate caller journey should be reserved independently');

	$firstCallerRows = $repo->loadRecentSeenCalls('+441234567890', '2026-07-13 08:00:00', '2026-07-13 10:00:00');
	$secondCallerRows = $repo->loadRecentSeenCalls('+441230000002', '2026-07-13 08:00:00', '2026-07-13 10:00:00');
	assert_same(1, count($firstCallerRows), 'recent seen calls should remain caller-specific');
	assert_same(1, count($secondCallerRows), 'separate callers should remain independent');
	assert_true(method_exists($repo, 'pruneSeenCallsByFixedRetention'), 'repository should expose fixed-retention seen-call cleanup');

	$db->prepare('INSERT INTO repeatcaller_seen_calls (call_identity, identity_type, fingerprint, linkedid, uniqueid, caller_raw, caller_normalized, inbound_route_key, did_value, call_started_at, call_completed_at, disposition, source_context, processed_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
		->execute(['linkedid-old', 'linkedid', hash('sha256', 'linkedid-old'), 'linkedid-old', 'uniqueid-old', '01239999999', '+441239999999', '18005550001|', '18005550001', '2026-03-10 08:00:00', '2026-03-10 08:00:00', 'ANSWERED', 'from-trunk', '2026-03-10 08:00:05']);
	$db->prepare('INSERT INTO repeatcaller_seen_calls (call_identity, identity_type, fingerprint, linkedid, uniqueid, caller_raw, caller_normalized, inbound_route_key, did_value, call_started_at, call_completed_at, disposition, source_context, processed_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
		->execute(['linkedid-current', 'linkedid', hash('sha256', 'linkedid-current'), 'linkedid-current', 'uniqueid-current', '01238888888', '+441238888888', '18005550001|', '18005550001', '2026-07-10 08:00:00', '2026-07-10 08:00:00', 'ANSWERED', 'from-trunk', '2026-07-10 08:00:05']);
	$prunedSeen = $repo->pruneSeenCallsByFixedRetention('2026-07-13 12:30:00');
	assert_same(1, $prunedSeen, 'fixed-retention seen-call cleanup should remove only rows older than retention cutoff');
	assert_same(0, (int)$db->query("SELECT COUNT(*) FROM repeatcaller_seen_calls WHERE call_identity = 'linkedid-old'")->fetchColumn(), 'old seen-call rows should be removed by fixed retention cleanup');
	assert_same(1, (int)$db->query("SELECT COUNT(*) FROM repeatcaller_seen_calls WHERE call_identity = 'linkedid-current'")->fetchColumn(), 'recent seen-call rows should be preserved by fixed retention cleanup');

	$repo->saveSubjectState($ruleId, '+441234567890', [
		'current_window_started_at' => '2026-07-13 09:00:00',
		'current_window_ends_at' => '2026-07-13 10:00:00',
		'current_window_call_count' => 2,
		'threshold_met' => 1,
		'clear_observed_since_trigger' => 0,
		'last_call_at' => '2026-07-13 09:10:00',
		'last_evaluated_at' => '2026-07-13 09:10:05',
		'created_at' => '2026-07-13 09:10:05',
		'updated_at' => '2026-07-13 09:10:05',
	]);

	$incidentId = $repo->createIncident([
		'rule_id' => $ruleId,
		'subject_key' => '+441234567890',
		'subject_label' => '+441234567890',
		'caller_normalized' => '+441234567890',
		'caller_display' => '01234567890',
		'withheld_caller' => 0,
		'mode' => 'repeat',
		'threshold_count' => 2,
		'observation_window_minutes' => 60,
		'first_matched_at' => '2026-07-13 09:10:00',
		'last_matched_at' => '2026-07-13 09:10:00',
		'matched_call_count' => 2,
		'state' => 'active',
		'suppression_expires_at' => '2026-07-13 09:40:00',
		'created_at' => '2026-07-13 09:10:05',
		'updated_at' => '2026-07-13 09:10:05',
	]);
	$repo->saveSubjectState($ruleId, '+441234567890', [
		'current_window_started_at' => '2026-07-13 09:00:00',
		'current_window_ends_at' => '2026-07-13 10:00:00',
		'current_window_call_count' => 2,
		'threshold_met' => 1,
		'clear_observed_since_trigger' => 0,
		'active_incident_id' => $incidentId,
		'suppression_expires_at' => '2026-07-13 09:40:00',
		'last_call_at' => '2026-07-13 09:10:00',
		'last_evaluated_at' => '2026-07-13 09:10:05',
		'updated_at' => '2026-07-13 09:10:05',
	]);

	$activeIncident = $repo->loadActiveIncident($ruleId, '+441234567890');
	assert_true(is_array($activeIncident), 'active incident should be loadable');
	assert_same(2, (int)$activeIncident['matched_call_count'], 'active incident should retain initial matched count');
	assert_same(2, (int)$activeIncident['threshold_count'], 'active incident should retain the snapshot threshold count');
	assert_same(60, (int)$activeIncident['observation_window_minutes'], 'active incident should retain the snapshot observation window');

	$duplicateActiveFailed = false;
	try {
		$repo->createIncident([
			'rule_id' => $ruleId,
			'subject_key' => '+441234567890',
			'subject_label' => '+441234567890',
			'caller_normalized' => '+441234567890',
			'caller_display' => '01234567890',
			'withheld_caller' => 0,
			'mode' => 'repeat',
			'threshold_count' => 2,
			'observation_window_minutes' => 60,
			'first_matched_at' => '2026-07-13 09:20:00',
			'last_matched_at' => '2026-07-13 09:20:00',
			'matched_call_count' => 3,
			'state' => 'active',
			'suppression_expires_at' => '2026-07-13 09:50:00',
			'created_at' => '2026-07-13 09:20:05',
			'updated_at' => '2026-07-13 09:20:05',
		]);
	} catch (Throwable $e) {
		$duplicateActiveFailed = true;
	}
	assert_true($duplicateActiveFailed, 'only one active incident should exist for the same rule and subject');

	$repo->updateIncidentWithCall($incidentId, '2026-07-13 09:20:00', 3);
	$updatedIncident = $repo->loadActiveIncident($ruleId, '+441234567890');
	assert_same(3, (int)$updatedIncident['matched_call_count'], 'further matching calls should update the same active incident');
	assert_same('2026-07-13 09:20:00', $updatedIncident['last_matched_at'], 'updated incident should persist latest matched time');

	assert_true($repo->claimActiveIncident($incidentId, 'admin', '2026-07-13 09:25:00', 'gui'), 'claiming an active incident should succeed');
	assert_true($repo->loadActiveIncident($ruleId, '+441234567890') === null, 'claim should move the incident out of the active state');
	assert_true(is_array($repo->loadTrackedIncident($ruleId, '+441234567890')), 'claimed incident should remain tracked so later matching calls update it');
	$claimedState = $repo->loadSubjectState($ruleId, '+441234567890');
	assert_same($incidentId, (int)$claimedState['active_incident_id'], 'claim must not clear the subject-state link; later matching calls update the same claimed incident');
	$claimedIncidentRow = $db->query('SELECT active_subject_key FROM repeatcaller_incidents WHERE id = ' . (int)$incidentId)->fetch(PDO::FETCH_ASSOC);
	assert_true((string)$claimedIncidentRow['active_subject_key'] !== '', 'claimed incident should retain its active subject linkage to block a duplicate active incident');

	$otherIncidentId = $repo->createIncident([
		'rule_id' => $ruleId,
		'subject_key' => '+441234567891',
		'subject_label' => '+441234567891',
		'caller_normalized' => '+441234567891',
		'caller_display' => '01234567891',
		'withheld_caller' => 0,
		'mode' => 'repeat',
		'first_matched_at' => '2026-07-13 09:26:00',
		'last_matched_at' => '2026-07-13 09:26:00',
		'matched_call_count' => 1,
		'state' => 'active',
		'suppression_expires_at' => '2026-07-13 10:00:00',
		'created_at' => '2026-07-13 09:26:05',
		'updated_at' => '2026-07-13 09:26:05',
	]);
	assert_true($repo->claimActiveIncident($otherIncidentId, 'admin', '2026-07-13 09:27:00', 'gui'), 'claiming a second active incident should succeed independently');
	$claimedRows = $repo->loadIncidents('claimed', 50);
	assert_same(2, count($claimedRows), 'claimed incident retrieval should return all currently claimed incidents');
	assert_same($otherIncidentId, (int)$claimedRows[0]['id'], 'claimed incident retrieval should order newest claim first');
	assert_same($incidentId, (int)$claimedRows[1]['id'], 'earlier claimed incident should remain visible after later claims');

	$duplicateWhileClaimedFailed = false;
	try {
		$repo->createIncident([
			'rule_id' => $ruleId,
			'subject_key' => '+441234567890',
			'subject_label' => '+441234567890',
			'caller_normalized' => '+441234567890',
			'caller_display' => '01234567890',
			'withheld_caller' => 0,
			'mode' => 'repeat',
			'threshold_count' => 2,
			'observation_window_minutes' => 60,
			'first_matched_at' => '2026-07-13 09:30:00',
			'last_matched_at' => '2026-07-13 09:30:00',
			'matched_call_count' => 1,
			'state' => 'active',
			'suppression_expires_at' => '2026-07-13 10:00:00',
			'created_at' => '2026-07-13 09:30:05',
			'updated_at' => '2026-07-13 09:30:05',
		]);
	} catch (Throwable $e) {
		$duplicateWhileClaimedFailed = true;
	}
	assert_true($duplicateWhileClaimedFailed, 'no second incident should be creatable while the claimed incident has not genuinely cleared');

	$repo->markConditionCleared($ruleId, '+441234567890', '2026-07-13 10:00:00');
	$rearmedState = $repo->loadSubjectState($ruleId, '+441234567890');
	assert_same(1, (int)$rearmedState['clear_observed_since_trigger'], 'condition must genuinely clear before re-arm is permitted');

	$secondIncidentId = $repo->createIncident([
		'rule_id' => $ruleId,
		'subject_key' => '+441234567890',
		'subject_label' => '+441234567890',
		'caller_normalized' => '+441234567890',
		'caller_display' => '01234567890',
		'withheld_caller' => 0,
		'mode' => 'repeat',
		'threshold_count' => 2,
		'observation_window_minutes' => 60,
		'first_matched_at' => '2026-07-13 11:10:00',
		'last_matched_at' => '2026-07-13 11:10:00',
		'matched_call_count' => 2,
		'state' => 'active',
		'suppression_expires_at' => '2026-07-13 11:40:00',
		'created_at' => '2026-07-13 11:10:05',
		'updated_at' => '2026-07-13 11:10:05',
	]);
	$repo->saveSubjectState($ruleId, '+441234567890', [
		'current_window_started_at' => '2026-07-13 11:00:00',
		'current_window_ends_at' => '2026-07-13 12:00:00',
		'current_window_call_count' => 2,
		'threshold_met' => 1,
		'clear_observed_since_trigger' => 0,
		'active_incident_id' => $secondIncidentId,
		'suppression_expires_at' => '2026-07-13 11:40:00',
		'last_call_at' => '2026-07-13 11:10:00',
		'last_evaluated_at' => '2026-07-13 11:10:05',
		'updated_at' => '2026-07-13 11:10:05',
	]);
	$repo->suppressIncident($ruleId, '+441234567890', $secondIncidentId, '2026-07-13 11:40:00', '2026-07-13 11:15:00');
	$subjectStateAfterSuppression = $repo->loadSubjectState($ruleId, '+441234567890');
	assert_same('2026-07-13 11:40:00', $subjectStateAfterSuppression['suppression_expires_at'], 'suppression expiry should be persisted in subject state');
	$incidentState = $db->query('SELECT state, suppression_expires_at FROM repeatcaller_incidents WHERE id = ' . (int)$secondIncidentId)->fetch(PDO::FETCH_ASSOC);
	assert_same('suppressed', $incidentState['state'], 'suppression should be persisted on the incident');
	assert_same('2026-07-13 11:40:00', $incidentState['suppression_expires_at'], 'incident suppression expiry should round-trip');

	$repo->markConditionCleared($ruleId, '+441234567890', '2026-07-13 12:10:00');

	$db->prepare('INSERT INTO repeatcaller_settings (setting_key, setting_value, updated_at) VALUES (?, ?, ?)')->execute(['enabled', '1', '2026-07-13 12:10:00']);
	$db->prepare('INSERT INTO repeatcaller_settings (setting_key, setting_value, updated_at) VALUES (?, ?, ?)')->execute(['engine_last_success_at', '2026-07-13 12:10:00', '2026-07-13 12:10:00']);
	$db->prepare('INSERT INTO repeatcaller_settings (setting_key, setting_value, updated_at) VALUES (?, ?, ?)')->execute(['engine_last_summary_json', '{"runtime":[]}', '2026-07-13 12:10:00']);

	$tokensBaseline = $repo->loadUiChangeTokens();
	$tokensStable = $repo->loadUiChangeTokens();
	assert_same($tokensBaseline, $tokensStable, 'identical repository state should return identical section tokens');

	$activeProbeIncidentId = $repo->createIncident([
		'rule_id' => $ruleId,
		'subject_key' => '+441234567892',
		'subject_label' => '+441234567892',
		'caller_normalized' => '+441234567892',
		'caller_display' => '01234567892',
		'withheld_caller' => 0,
		'mode' => 'repeat',
		'first_matched_at' => '2026-07-13 12:11:00',
		'last_matched_at' => '2026-07-13 12:11:00',
		'matched_call_count' => 1,
		'state' => 'active',
		'suppression_expires_at' => '2026-07-13 13:11:00',
		'created_at' => '2026-07-13 12:11:00',
		'updated_at' => '2026-07-13 12:11:00',
	]);
	$tokensWithActiveProbe = $repo->loadUiChangeTokens();
	$repo->updateIncidentWithCall($activeProbeIncidentId, '2026-07-13 12:12:00', 2);
	$tokensAfterActiveUpdate = $repo->loadUiChangeTokens();
	assert_true($tokensAfterActiveUpdate['activeIncidents'] !== $tokensWithActiveProbe['activeIncidents'], 'active incident updates should change only activeIncidents token');
	assert_same($tokensWithActiveProbe['claimedIncidents'], $tokensAfterActiveUpdate['claimedIncidents'], 'active incident updates should not change claimedIncidents token');
	assert_same($tokensWithActiveProbe['alertHistory'], $tokensAfterActiveUpdate['alertHistory'], 'active incident updates should not change alertHistory token');
	assert_same($tokensWithActiveProbe['engineStatus'], $tokensAfterActiveUpdate['engineStatus'], 'active incident updates should not change engineStatus token');

	assert_true($repo->claimActiveIncident($activeProbeIncidentId, 'admin', '2026-07-13 12:13:00', 'gui'), 'claiming the active probe incident should succeed');
	$tokensAfterClaim = $repo->loadUiChangeTokens();
	assert_true($tokensAfterClaim['activeIncidents'] !== $tokensAfterActiveUpdate['activeIncidents'], 'claiming should change activeIncidents token');
	assert_true($tokensAfterClaim['claimedIncidents'] !== $tokensAfterActiveUpdate['claimedIncidents'], 'claiming should change claimedIncidents token');
	assert_same($tokensAfterActiveUpdate['alertHistory'], $tokensAfterClaim['alertHistory'], 'claiming should not change alertHistory token');
	assert_same($tokensAfterActiveUpdate['engineStatus'], $tokensAfterClaim['engineStatus'], 'claiming should not change engineStatus token');

	$db->prepare('INSERT INTO repeatcaller_incident_alert_history (incident_id, rule_id, subject_key, subject_label, action_type, event_type, stage_n, recipient, delivery_status, repeat_mode, dedupe_key, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
		->execute([$secondIncidentId, $ruleId, '+441234567890', '+441234567890', 'gui', 'initial', 0, null, 'recorded', 'never', 'repo-token-1', '2026-07-13 12:16:00', '2026-07-13 12:16:00']);
	$historyWithIncidentMode = $repo->loadIncidentAlertHistory(20);
	assert_same('repeat', (string)$historyWithIncidentMode[0]['incident_mode'], 'alert history should include originating incident detection mode when incident exists');
	assert_same(2, (int)$historyWithIncidentMode[0]['incident_threshold_count'], 'alert history should include the incident snapshot threshold count');
	assert_same(60, (int)$historyWithIncidentMode[0]['incident_observation_window_minutes'], 'alert history should include the incident snapshot observation window');

	$db->prepare('INSERT INTO repeatcaller_incident_alert_history (incident_id, rule_id, subject_key, subject_label, action_type, event_type, stage_n, recipient, delivery_status, repeat_mode, dedupe_key, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
		->execute([999999, $ruleId, 'orphan-subject', 'orphan-subject', 'gui', 'initial', 0, null, 'recorded', 'hourly', 'repo-token-orphan', '2026-07-13 12:16:00', '2026-07-13 12:16:00']);
	$historyWithOrphan = $repo->loadIncidentAlertHistory(20);
	$orphanRows = array_values(array_filter($historyWithOrphan, static function (array $row): bool {
		return (int)$row['incident_id'] === 999999 && (string)$row['subject_key'] === 'orphan-subject';
	}));
	assert_same(1, count($orphanRows), 'orphan alert-history row should be queryable');
	assert_true($orphanRows[0]['incident_mode'] === null, 'alert history should return null incident mode when originating incident row is missing');
	$tokensAfterAlertInsert = $repo->loadUiChangeTokens();
	assert_true($tokensAfterAlertInsert['alertHistory'] !== $tokensAfterClaim['alertHistory'], 'alert-history inserts should change alertHistory token');
	assert_same($tokensAfterClaim['activeIncidents'], $tokensAfterAlertInsert['activeIncidents'], 'alert-history inserts should not change activeIncidents token');
	assert_same($tokensAfterClaim['claimedIncidents'], $tokensAfterAlertInsert['claimedIncidents'], 'alert-history inserts should not change claimedIncidents token');
	assert_same($tokensAfterClaim['engineStatus'], $tokensAfterAlertInsert['engineStatus'], 'alert-history inserts should not change engineStatus token');

	$db->prepare('UPDATE repeatcaller_settings SET setting_value = ?, updated_at = ? WHERE setting_key = ?')->execute(['2026-07-13 12:20:00', '2026-07-13 12:20:00', 'engine_last_success_at']);
	$tokensAfterEngineUpdate = $repo->loadUiChangeTokens();
	assert_true($tokensAfterEngineUpdate['engineStatus'] !== $tokensAfterAlertInsert['engineStatus'], 'engine last-run updates should change only engineStatus token');
	assert_same($tokensAfterAlertInsert['activeIncidents'], $tokensAfterEngineUpdate['activeIncidents'], 'engine-only updates should not change activeIncidents token');
	assert_same($tokensAfterAlertInsert['claimedIncidents'], $tokensAfterEngineUpdate['claimedIncidents'], 'engine-only updates should not change claimedIncidents token');
	assert_same($tokensAfterAlertInsert['alertHistory'], $tokensAfterEngineUpdate['alertHistory'], 'engine-only updates should not change alertHistory token');

	$db->prepare('INSERT INTO repeatcaller_incident_alert_history (incident_id, rule_id, subject_key, subject_label, action_type, event_type, stage_n, recipient, delivery_status, repeat_mode, dedupe_key, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
		->execute([$secondIncidentId, $ruleId, '+441234567890', '+441234567890', 'gui', 'reminder', 1, null, 'recorded', 'never', 'repo-token-2', '2026-07-13 12:16:00', '2026-07-13 12:16:00']);
	$tokensBeforeDelete = $repo->loadUiChangeTokens();
	$db->exec("DELETE FROM repeatcaller_incident_alert_history WHERE dedupe_key = 'repo-token-1'");
	$tokensAfterDelete = $repo->loadUiChangeTokens();
	assert_true($tokensAfterDelete['alertHistory'] !== $tokensBeforeDelete['alertHistory'], 'alert-history deletion must change alertHistory token even when max timestamp is unchanged');
	assert_same($tokensBeforeDelete['activeIncidents'], $tokensAfterDelete['activeIncidents'], 'alert-history deletion should not change activeIncidents token');
	assert_same($tokensBeforeDelete['claimedIncidents'], $tokensAfterDelete['claimedIncidents'], 'alert-history deletion should not change claimedIncidents token');
	assert_same($tokensBeforeDelete['engineStatus'], $tokensAfterDelete['engineStatus'], 'alert-history deletion should not change engineStatus token');

	$clearCount = $repo->clearIncidentAlertHistory();
	assert_true($clearCount > 0, 'explicit clearIncidentAlertHistory should delete all rows from alert history');
	$tokensAfterClear = $repo->loadUiChangeTokens();
	assert_true($tokensAfterClear['alertHistory'] !== $tokensAfterDelete['alertHistory'], 'clearIncidentAlertHistory should change alertHistory token');
	assert_same($tokensAfterDelete['activeIncidents'], $tokensAfterClear['activeIncidents'], 'clearIncidentAlertHistory should not change activeIncidents token');
	assert_same($tokensAfterDelete['claimedIncidents'], $tokensAfterClear['claimedIncidents'], 'clearIncidentAlertHistory should not change claimedIncidents token');
	assert_same($tokensAfterDelete['engineStatus'], $tokensAfterClear['engineStatus'], 'clearIncidentAlertHistory should not change engineStatus token');

	assert_true($repo->reserveSuppressedIncidentHistory([
		'related_incident_id' => 808,
		'rule_id' => $ruleId,
		'rule_name' => 'Rule A',
		'mode' => 'repeat',
		'subject_key' => '+441234567890|route:abc',
		'subject_label' => '+441234567890 @ Main DID',
		'caller_normalized' => '+441234567890',
		'caller_display' => '01234567890',
		'inbound_route_key' => '18005550001|',
		'inbound_route_label' => 'Main DID',
		'did_value' => '18005550001',
		'matched_call_count' => 3,
		'threshold_count' => 2,
		'observation_window_minutes' => 60,
		'suppression_source' => 'global_default',
		'suppression_minutes' => 30,
		'suppression_started_at' => '2026-07-13 12:20:00',
		'suppression_expires_at' => '2026-07-13 12:50:00',
		'related_incident_state' => 'claimed',
		'detected_at' => '2026-07-13 12:20:00',
		'created_at' => '2026-07-13 12:20:00',
		'updated_at' => '2026-07-13 12:20:00',
	]), 'suppression history should reserve a new row');
	assert_true(!$repo->reserveSuppressedIncidentHistory([
		'related_incident_id' => 808,
		'rule_id' => $ruleId,
		'rule_name' => 'Rule A',
		'mode' => 'repeat',
		'subject_key' => '+441234567890|route:abc',
		'subject_label' => '+441234567890 @ Main DID',
		'caller_normalized' => '+441234567890',
		'caller_display' => '01234567890',
		'inbound_route_key' => '18005550001|',
		'inbound_route_label' => 'Main DID',
		'did_value' => '18005550001',
		'matched_call_count' => 3,
		'threshold_count' => 2,
		'observation_window_minutes' => 60,
		'suppression_source' => 'global_default',
		'suppression_minutes' => 30,
		'suppression_started_at' => '2026-07-13 12:20:00',
		'suppression_expires_at' => '2026-07-13 12:50:00',
		'related_incident_state' => 'claimed',
		'detected_at' => '2026-07-13 12:20:00',
		'created_at' => '2026-07-13 12:20:00',
		'updated_at' => '2026-07-13 12:20:00',
	]), 'suppression history should reject duplicate related incident ids');
	$suppressedHistory = $repo->loadSuppressedIncidentHistory();
	assert_same(1, count($suppressedHistory), 'suppression history should be queryable after reserve');
	assert_same('Rule A', (string)$suppressedHistory[0]['rule_name'], 'suppression history should preserve the rule name');
	assert_same('2026-07-13 12:50:00', (string)$suppressedHistory[0]['suppression_expires_at'], 'suppression history should preserve the expiry timestamp');
	assert_same(808, (int)$suppressedHistory[0]['related_incident_id'], 'suppression history should preserve the related incident id');
	$tokensAfterSuppressionInsert = $repo->loadUiChangeTokens();
	assert_true($repo->clearSuppressedIncidentHistory((int)$suppressedHistory[0]['id'], '2026-07-13 12:55:00'), 'suppression history should be clearable without deleting the audit row');
	$clearedSuppressedHistory = $repo->loadSuppressedIncidentHistory();
	assert_same('2026-07-13 12:55:00', (string)$clearedSuppressedHistory[0]['cleared_at'], 'suppression history should preserve the cleared timestamp');
	$tokensAfterSuppressionClear = $repo->loadUiChangeTokens();
	assert_true($tokensAfterSuppressionClear['suppressedIncidents'] !== $tokensAfterSuppressionInsert['suppressedIncidents'], 'suppression history clear should change suppressedIncidents token');
	assert_same($tokensAfterClear['activeIncidents'], $tokensAfterSuppressionInsert['activeIncidents'], 'suppression history inserts should not change activeIncidents token');
	assert_same($tokensAfterClear['claimedIncidents'], $tokensAfterSuppressionInsert['claimedIncidents'], 'suppression history inserts should not change claimedIncidents token');
	assert_same($tokensAfterClear['alertHistory'], $tokensAfterSuppressionInsert['alertHistory'], 'suppression history inserts should not change alertHistory token');
	assert_same($tokensAfterClear['engineStatus'], $tokensAfterSuppressionInsert['engineStatus'], 'suppression history inserts should not change engineStatus token');
	assert_same(1, $repo->pruneSuppressedIncidentHistory('2026-07-13 12:40:00'), 'suppression history pruning should delete stale rows');
	assert_same(0, count($repo->loadSuppressedIncidentHistory()), 'suppression history pruning should remove old rows');

	$alertHistoryInsert = $db->prepare('INSERT INTO repeatcaller_incident_alert_history (incident_id, rule_id, subject_key, subject_label, action_type, event_type, stage_n, recipient, delivery_status, repeat_mode, dedupe_key, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
	$alertHistoryInsert->execute([$secondIncidentId, $ruleId, '+441234567890', '+441234567890', 'alert_call', 'initial', 0, '100', 'sent', 'never', 'repo-alert-call-1', '2026-07-13 12:20:00', '2026-07-13 12:20:00']);
	$dialResult = $repo->recordAlertCallDialDisposition((int)$db->lastInsertId(), $secondIncidentId, '100', 'BUSY', '17', '2026-07-13 12:20:10');
	assert_true(!empty($dialResult['status']), 'recordAlertCallDialDisposition should update alert_call rows');
	$dialRow = $db->query("SELECT delivery_status, failure_detail FROM repeatcaller_incident_alert_history WHERE dedupe_key = 'repo-alert-call-1'")->fetch(PDO::FETCH_ASSOC);
	assert_same('busy', (string)$dialRow['delivery_status'], 'BUSY dialstatus should map to busy');
	assert_true(strpos((string)$dialRow['failure_detail'], 'DIALSTATUS=BUSY; HANGUPCAUSE=17') !== false, 'dial callback should persist raw status detail');

	$alertHistoryInsert->execute([$secondIncidentId, $ruleId, '+441234567890', '+441234567890', 'alert_call', 'initial', 0, '101', 'declined', 'never', 'repo-alert-call-2', '2026-07-13 12:20:00', '2026-07-13 12:20:00']);
	$eligibleAfterDecline = $repo->eligibleAlertCallDestinationsForStage($secondIncidentId, ['100', '101'], true, 'initial', 0);
	assert_same([], $eligibleAfterDecline, 'declined and already-attempted recipients should be ineligible for same stage retries');
	$eligibleWithDestinationKeepFlags = $repo->eligibleAlertCallDestinationsForStage($secondIncidentId, [
		['destination' => '100', 'keep_trying' => 0],
		['destination' => '102', 'keep_trying' => 1],
	], true, 'reminder', 1);
	assert_same(['102'], $eligibleWithDestinationKeepFlags, 'destination-specific keep-trying flags should control future-stage recipient eligibility');

	$followupIncidentId = $repo->createIncident([
		'rule_id' => $ruleId,
		'subject_key' => '+441234567893',
		'subject_label' => '+441234567893',
		'caller_normalized' => '+441234567893',
		'caller_display' => '01234567893',
		'withheld_caller' => 0,
		'mode' => 'repeat',
		'threshold_count' => 2,
		'observation_window_minutes' => 60,
		'first_matched_at' => '2026-07-13 12:21:00',
		'last_matched_at' => '2026-07-13 12:21:00',
		'matched_call_count' => 1,
		'state' => 'active',
		'suppression_expires_at' => '2026-07-13 13:21:00',
		'created_at' => '2026-07-13 12:21:00',
		'updated_at' => '2026-07-13 12:21:00',
	]);
	$alertHistoryInsert->execute([$followupIncidentId, $ruleId, '+441234567893', '+441234567893', 'alert_call', 'initial', 0, '102', 'pending', 'never', 'repo-alert-call-pending', '2026-07-13 12:21:00', '2026-07-13 12:21:00']);
	$pendingHistoryId = (int)$db->lastInsertId();
	$deliverable = $repo->loadDeliverableCallAlertByHistoryId($pendingHistoryId, '2026-07-13 12:21:00');
	assert_true(is_array($deliverable), 'pending alert_call row should be loadable as a deliverable single-row follow-up target');
	assert_same('102', (string)$deliverable['recipient'], 'single-row deliverable lookup should return the reserved recipient');
	$repo->claimActiveIncident($followupIncidentId, 'qa', '2026-07-13 12:21:10', 'gui');
	$deliverableAfterClaim = $repo->loadDeliverableCallAlertByHistoryId($pendingHistoryId, '2026-07-13 12:21:11');
	assert_true($deliverableAfterClaim === null, 'single-row deliverable lookup should stop once incident is no longer active');
	unset($repo, $db);

	$dbReloaded = new PDO('sqlite:' . $dbPath);
	$repoReloaded = new RepeatCallerRepository($dbReloaded);
	$reloadedState = $repoReloaded->loadSubjectState($ruleId, '+441234567890');
	assert_same(1, (int)$reloadedState['clear_observed_since_trigger'], 'clear and re-arm state should survive a new repository instance');
	assert_same(null, $reloadedState['active_incident_id'], 're-armed state should not carry an active incident reference');

	echo "repeat repository contract tests passed\n";
} finally {
	if (isset($dbPath) && is_string($dbPath) && file_exists($dbPath)) {
		unlink($dbPath);
	}
}
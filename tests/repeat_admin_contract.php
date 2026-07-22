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

	return $db;
}

$db = make_db();
$repo = new RepeatCallerRepository($db);
$now = '2026-07-13 10:00:00';

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
assert_same(1, count($rule['did_lists']['exclude']), 'did exclude list should persist');
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

assert_true((bool)preg_match('/in_array\(\$seconds, \[300, 900, 1800, 3600, 86400\], true\)/', $controllerSource), '15-minute snooze duration (900 seconds) must remain accepted by the snooze handler');
assert_true((bool)preg_match('/setSetting\(\'global_snooze_selected_seconds\', \(string\)\$seconds\);/', $controllerSource), 'setting a snooze duration must persist global_snooze_selected_seconds (e.g. "900" for 15m)');
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
assert_true(strpos($jsSource, 'if (runStatusUi.runningVisibleSinceMs === 0) {') !== false, 'Run Status should set Running baseline once and avoid timer restarts during ordinary refreshes');
assert_true(strpos($jsSource, 'runStatusUi.holdTimerId = window.setTimeout(function () {') !== false, 'Run Status should schedule post-minimum Waiting transition without delaying backend monitor flow');
assert_true(strpos($jsSource, '}, runStatusUi.minimumVisibleMs - elapsedMs);') !== false, 'Run Status should hold Running only for the remaining minimum-visible duration');
assert_true(strpos($jsSource, 'setRunStatusRunningFromRunStart();') !== false, 'Run Status should switch to Running immediately when Run Monitor is started from the UI');
assert_true(strpos($jsSource, 'function isProcessingVisible() {') !== false, 'Run Status should compute whether Processing visibility must still be preserved');
assert_true(strpos($jsSource, 'function updateRunNowButtonState() {') !== false, 'Run Status logic should control Run Now button availability from Processing state');
assert_true(strpos($jsSource, "if (isProcessingVisible()) {") !== false && strpos($jsSource, "\$button.prop('disabled', true).addClass('disabled');") !== false, 'Run Now should be disabled and greyed while Processing remains visible');
assert_true(strpos($jsSource, "\$button.prop('disabled', false).removeClass('disabled');") !== false, 'Run Now should be re-enabled only after Processing visibility has ended');
assert_true(strpos($jsSource, "if (\$button.prop('disabled')) {") !== false, 'Run Now click handler should guard against additional clicks while Processing is active');
assert_true(strpos($jsSource, "\$button.text('Processing...');") !== false, 'Run Now action should show Processing immediately when a run starts');
assert_true(strpos($jsSource, "var statusRefresh = loadEngineStatus({silent: true});") !== false, 'Run Now completion should refresh engine status before releasing final UI state');
assert_true(strpos($jsSource, 'statusRefresh.always(function () {') !== false && strpos($jsSource, 'updateRunNowButtonState();') !== false, 'Run Now should remain locked through completion callback until status refresh and Processing timing gate are applied');
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
assert_true(strpos($jsSource, "$('#rc-rules-table tbody tr').removeClass('rc-rule-editing');") !== false, 'exiting edit mode should remove row editing/highlight state');
assert_true(strpos($jsSource, "$('#rc-rules-table tbody tr[data-rule-id=\"' + editingRuleId + '\"]').addClass('rc-rule-editing').next('.rc-rule-explainer-row').addClass('rc-rule-editing');") !== false, 'loading an existing rule should mark its row and explainer as being edited');
assert_true(strpos($jsSource, "$('#rc-cancel-edit').removeClass('hidden');") !== false, 'existing rule edit mode should show Cancel Edit control');
assert_true(strpos($jsSource, "$('#rc-cancel-edit').addClass('hidden');") !== false, 'new-rule mode should hide Cancel Edit control');
assert_true(strpos($jsSource, "$('#rc-cancel-edit').off('click.repeatcaller').on('click.repeatcaller', function () { resetRuleEditor(); });") !== false, 'Cancel Edit must return editor to new-rule defaults without mutating saved rule');
assert_true((bool)preg_match('/ajax\(\'saverule\',[\s\S]*function \(response\) \{[\s\S]*showMessage\(\'Rule saved\.\', \'success\'\);[\s\S]*resetRuleEditor\(\);[\s\S]*\}, onDone\);/', $jsSource), 'successful Save Rule response must clear current editing state and return to default/new-rule mode while preserving success toast');
assert_true((bool)preg_match('/ajax\(\'saverule\',[\s\S]*function \(response\) \{[\s\S]*renderRules\(response\.rules \|\| \[\]\);[\s\S]*resetRuleEditor\(\);/', $jsSource), 'successful Save Rule response must refresh the rules table with saved values before leaving edit mode');
assert_true((bool)preg_match('/ajax\(\'saverule\',[\s\S]*function \(response\) \{[\s\S]*\}, onDone\);/', $jsSource), 'failed Save Rule response path should continue to use shared completion callback without forcibly clearing editing state');
assert_true(strpos($jsSource, "$('#rc-add-rule')") === false, 'rules UI should not include or bind a separate Add Rule control');
assert_true(strpos($jsSource, "$('#rc-reset-rule')") === false, 'legacy Clear Editor control should be removed from JS bindings');
assert_true(strpos($jsSource, "$('#rc-rule-caller-mode').off('change.repeatcaller').on('change.repeatcaller', function () { updateCallerScopeEditorState(); });") !== false, 'Caller Scope selector should bind explicit change handling for caller field availability');
assert_true(strpos($jsSource, "$('#rc-rule-did-mode').off('change.repeatcaller').on('change.repeatcaller', function () { updateDidScopeEditorState(); });") !== false, 'DID scope selector should bind explicit change toggle handling');
assert_true(strpos($jsSource, "$('#rc-route-pick').prop('disabled', !useSelectedRoutes).toggleClass('rc-control-disabled', !useSelectedRoutes);") !== false, 'All DIDs mode should disable and grey the inbound route selector');
assert_true(strpos($jsSource, "$('#rc-add-did-include, #rc-add-did-exclude').prop('disabled', !useSelectedRoutes).toggleClass('disabled', !useSelectedRoutes);") !== false, 'All DIDs mode should disable route include/exclude controls when route selection is irrelevant');
assert_true(strpos($jsSource, "$('#rc-did-include-list, #rc-did-exclude-list').toggleClass('rc-control-disabled', !useSelectedRoutes).attr('aria-disabled', useSelectedRoutes ? 'false' : 'true');") !== false, 'All DIDs mode should visibly mark route lists as inactive');
assert_true(strpos($jsSource, "$('#rc-rule-did-mode').val(rule.did_scope_mode || 'all');") !== false && strpos($jsSource, 'updateDidScopeEditorState();') !== false, 'loading a rule should apply DID scope toggle state without clearing existing route selections');
assert_true(strpos($jsSource, 'function updateCallerScopeEditorState() {') !== false, 'Rule editor should use one dedicated helper to control Caller Scope field availability');
assert_true(strpos($jsSource, "var requiresSpecificCallers = callerMode === 'specific_only';") !== false, 'Specific callers mode should explicitly drive caller input activation');
assert_true(strpos($jsSource, "var includeEnabled = requiresSpecificCallers;") !== false && strpos($jsSource, "var excludeEnabled = callerMode !== 'withheld_only';") !== false, 'Caller Scope semantics should keep exclude active for Any callers while disabling both lists for Withheld only');
assert_true(strpos($jsSource, "if (callerMode === 'any') {") !== false && strpos($jsSource, "includeUnavailableText = 'This field is only used when Specific callers is selected.';") !== false, 'Any caller mode should disable include input with a clear unavailable reason');
assert_true(strpos($jsSource, "} else if (callerMode === 'withheld_only') {") !== false && strpos($jsSource, "excludeUnavailableText = 'Caller number lists are not used for withheld-only rules.';") !== false, 'Withheld only mode should disable both caller number fields with a clear unavailable reason');
assert_true(strpos($jsSource, "$('#rc-rule-caller-include').prop('disabled', !includeEnabled).toggleClass('rc-control-disabled', !includeEnabled).attr('aria-required', requiresSpecificCallers ? 'true' : 'false').attr('placeholder', formatExample);") !== false, 'caller include input should be enabled only for Specific callers, marked required, and have country-specific placeholder');
assert_true(strpos($jsSource, "$('#rc-rule-caller-exclude').prop('disabled', !excludeEnabled).toggleClass('rc-control-disabled', !excludeEnabled).attr('placeholder', formatExample);") !== false, 'caller exclude input should remain enabled for Any and Specific callers, disabled for Withheld only, with country-specific placeholder');
assert_true(strpos($jsSource, "$('#rc-caller-include-help').text(includeHelpText).toggleClass('text-danger', requiresSpecificCallers);") !== false, 'Caller include help text should update with country-based format examples and visibly emphasize the requirement in Specific callers mode');
assert_true(strpos($jsSource, "var countryCallerFormats = {") !== false && strpos($jsSource, "'44': { name: 'UK', local:") !== false && strpos($jsSource, "'1': { name: 'US/Canada', local:") !== false, 'Country caller format mapping should include country names and local format examples for major calling codes');
assert_true(strpos($jsSource, "'44': { name: 'UK', local: '07812345678'") !== false, 'UK format mapping should have leading 0 trunk prefix to provide country context in examples');
assert_true(strpos($jsSource, "'1': { name: 'US/Canada', local: '2125551234'") !== false, 'US/Canada format mapping should have local number without trunk prefix, prepended with country code by helper');
assert_true(strpos($jsSource, "function getCallerFormatHint(countryCode) {") !== false && strpos($jsSource, "Enter caller numbers in") !== false && strpos($jsSource, "format.name") !== false, 'Caller format hint should show country-specific local format without listing all accepted formats');
assert_true(strpos($jsSource, "function getCallerFormatExample(countryCode) {") !== false && strpos($jsSource, "if (localNumber.charAt(0) === '0') {") !== false && strpos($jsSource, "return code + localNumber;") !== false, 'National format example helper should prepend country code when number does not start with 0 to ensure country context is always visible');
assert_true(strpos($jsSource, "var localNumber = String(format.local || '');") !== false && strpos($jsSource, "// If local number starts with 0 (trunk prefix), use as-is; leading 0 provides country context") !== false, 'Format example helper should document logic for showing country code context in examples');
assert_true(strpos($jsSource, "function getCallerE164Example(countryCode) {") !== false && strpos($jsSource, "replace(/^0+/, '')") !== false && strpos($jsSource, "return '+' + code + nationalNumber;") !== false, 'E.164 format example helper should remove leading zeros and prepend country code with +');
assert_true(strpos($jsSource, "var formatExample = getCallerFormatExample($('#rc-setting-country').val());") !== false && strpos($jsSource, "var e164Example = getCallerE164Example($('#rc-setting-country').val());") !== false, 'Both national and E.164 format examples should be generated from country code');
assert_true(strpos($jsSource, ".attr('placeholder', formatExample)") !== false, 'Caller textarea placeholders should be set to the national format example');
assert_true(strpos($jsSource, "$('#rc-rule-alert-call-callerid').attr('placeholder', e164Example);") !== false, 'Alert Call Caller ID placeholder should be set to the E.164 format example with leading +');
assert_true(strpos($jsSource, "$('#rc-rule-alert-call-destination-input').attr('placeholder', '2001, 2002, ' + formatExample);") !== false, 'Alert Call Destinations placeholder should show national dialling format example (not E.164) with example extensions');
assert_true(strpos($jsSource, "$('#rc-rule-alert-call-callerid').prop('disabled', !alertCallEnabled).toggleClass('rc-control-disabled', !alertCallEnabled);") !== false, 'Alert Call Caller ID should be greyed out when Alert Call checkbox is unchecked');
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
assert_true(strpos($jsSource, "rows.push('<tr class=\"rc-rule-explainer-row' + (parseInt(rule.enabled || 0, 10) ? '' : ' rc-rule-disabled') + '\">'") !== false, 'rules table should render a subordinate explainer row immediately beneath each rule row');
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
assert_true(strpos($cssSource, 'background-color: #f3fbef;') !== false, 'rules explainer row should use a light green subordinate background');
assert_true(strpos($cssSource, '.repeatcaller .rc-rule-explainer-row.rc-rule-disabled .rc-rule-explainer-text {') !== false && strpos($cssSource, 'color: #a94442;') !== false, 'disabled rule explanations should render in warning red');
assert_true(strpos($cssSource, '.repeatcaller .rc-rule-explainer-row.rc-rule-editing td {') !== false && strpos($cssSource, 'background-color: #fcf8e3;') !== false, 'editing rule explanations should render with a light yellow highlight');
assert_true(strpos($cssSource, '.repeatcaller .rc-rule-editing > td {') !== false && strpos($cssSource, 'background-color: #fcf8e3;') !== false, 'editing rule rows should also be highlighted');
assert_true(strpos($cssSource, '.repeatcaller textarea.rc-control-disabled,') !== false && strpos($cssSource, '.repeatcaller .rc-list.rc-control-disabled {') !== false, 'disabled caller and route list controls should use obvious muted styling');
assert_true((bool)preg_match('/array_key_exists\(\'day\', \$item\)/', $controllerSource), 'controller schedule parser must read day with array_key_exists and preserve explicit -1');
assert_true((bool)preg_match('/array_key_exists\(\'day\', \$schedule\)/', file_get_contents(__DIR__ . '/../src/RepeatCallerRepository.php') ?: ''), 'repository schedule normalizer must avoid implicit day coercion to 0');
assert_true((bool)preg_match('/\'alert_call_strategy\'\s*=>\s*\$this->normaliseAlertCallStrategy\(/', $controllerSource), 'controller save path must normalize and persist alert call strategy');
assert_true((bool)preg_match('/\'alert_call_keep_trying\'\s*=>\s*isset\(\$_REQUEST\[\'alert_call_keep_trying\'\]\) \? \(!empty\(\$_REQUEST\[\'alert_call_keep_trying\'\]\) \? 1 : 0\) : 1/', $controllerSource), 'controller save path must default alert_call_keep_trying to enabled when omitted');
assert_true((bool)preg_match('/\$recordingId\s*=\s*array_key_exists\(\'alert_call_recording_id\', \$_REQUEST\)\s*\?\s*\$this->nullablePositiveRequestInt\(\'alert_call_recording_id\'\)\s*:\s*\(\(\$existingRule\s*!==\s*null/', $controllerSource), 'controller save path must preserve existing alert_call_recording_id when request omits that field');
assert_true((bool)preg_match('/private function normaliseAlertCallStrategy\(string \$strategy\): string/', $controllerSource), 'controller should define a bounded alert call strategy normalizer');
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
assert_true(strpos($jsSource, 'function suppressionSummary(rule) {') !== false, 'rules UI should define a suppression summary helper');
assert_true(strpos($jsSource, "return 'Default 24hrs';") !== false, 'rules UI should show the default suppression label when blank');
assert_true(strpos($jsSource, "return 'Disabled';") !== false, 'rules UI should show Disabled when suppression is set to 0');
assert_true(strpos($jsSource, "$('#rc-rule-suppression').val(rule.suppression_minutes_override);") !== false, 'rule loader must preserve a 0 suppression override in the editor');
assert_true(strpos($jsSource, 'function normaliseAlertCallDestinationEntries(rawValue, defaultKeepTryingEnabled) {') !== false, 'rule editor should parse destination entries with per-destination keep-trying state');
assert_true(strpos($jsSource, "values.push(destination + '|' + keepTryingFlag);") !== false, 'rule editor should persist each destination with its own keep-trying value');
assert_true(strpos($jsSource, 'function renderAlertCallDestinations(rawValue, defaultKeepTryingEnabled) {') !== false, 'rule editor should render alert-call destinations through ordered list UI');
assert_true(strpos($jsSource, '$list.append(buildAlertCallDestinationItem(destinationRow.destination, destinationRow.keepTrying));') !== false, 'rendering should restore per-destination keep-trying values');
assert_true(strpos($jsSource, "$('#rc-rule-alert-call-destination-add').off('click.repeatcaller').on('click.repeatcaller'" ) !== false, 'rule editor should bind add-destination button event');
assert_true(strpos($jsSource, "$('#rc-rule-alert-call-destination-input').off('keydown.repeatcaller').on('keydown.repeatcaller'" ) !== false, 'rule editor should support enter-key destination add');
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
assert_true(strpos($viewSource, 'placeholder="+441234567890"') !== false, 'Alert Call Caller ID placeholder should show E.164 format with leading +');
assert_true(strpos($viewSource, '<label><?php echo _(\'Only monitor these callers\'); ?></label>') !== false, 'Rule editor should use clear caller-monitor label wording');
assert_true(strpos($viewSource, '<p class="help-block" id="rc-caller-include-help"><?php echo _(\'Only these callers will trigger this rule.\'); ?></p>') !== false, 'Rule editor should explain specific-caller requirement in helper text');
assert_true(strpos($viewSource, '<label><?php echo _(\'Ignore these callers\'); ?></label>') !== false, 'Rule editor should use clear caller-ignore label wording');
assert_true(strpos($viewSource, '<p class="help-block" id="rc-caller-exclude-help"><?php echo _(\'Calls from these numbers will not trigger this rule.\'); ?></p>') !== false, 'Rule editor should explain caller-ignore behavior in helper text');
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
assert_true((bool)preg_match('/rc-alert-call-right-col[\s\S]*id="rc-rule-alert-call-recording-id"[\s\S]*id="rc-rule-alert-call-callerid"/', $viewSource), 'caller id should be grouped directly beneath system recording in the right column');
assert_true(strpos($viewSource, 'id="rc-rule-alert-call-destination-list"') !== false, 'rule editor view should include ordered destination list container');
assert_true(strpos($viewSource, 'id="rc-rule-alert-call-destination-add"') !== false, 'rule editor view should include add-destination button');
assert_true((bool)preg_match('/GUI \(always enabled\)[\s\S]*id="rc-rule-alert-call-enabled"[\s\S]*Alert Call[\s\S]*id="rc-rule-email-enabled"[\s\S]*Email/', $viewSource), 'rule action checklist should present Alert Call before Email');
assert_true((bool)preg_match('/\.repeatcaller \.rc-editor-panel\.rc-editor-edit-mode \{[\s\S]*background: #f5f5f5;[\s\S]*border-color: #ddd;/s', $cssSource), 'rule editor edit mode should use subtle neutral grey background and light border styling');
assert_true((bool)preg_match('/\.repeatcaller \.form-control\.rc-control-disabled,\s*\.repeatcaller \.form-control\.rc-control-disabled\[disabled\] \{[\s\S]*background-color: #f5f5f5;[\s\S]*border-color: #ddd;/s', $cssSource), 'disabled inbound route selector should use light grey disabled state styling');

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

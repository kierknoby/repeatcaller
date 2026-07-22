<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/DetectionEngine.php';

use FreePBX\modules\Repeatcaller\DetectionEngine;

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

function build_rule(array $overrides = []): array {
	$rule = [
		'enabled' => 1,
		'mode' => 'repeat',
		'threshold_count' => 2,
		'observation_window_minutes' => 60,
		'schedules' => [
			['day' => 1, 'start' => '09:00', 'end' => '17:00'],
		],
		'caller_mode' => 'any',
		'include_callers' => [],
		'exclude_callers' => [],
		'exclude_withheld' => 0,
		'did_scope_mode' => 'all',
		'include_routes' => [],
		'exclude_routes' => [],
		'suppression_minutes' => 30,
	];

	foreach ($overrides as $key => $value) {
		$rule[$key] = $value;
	}

	return $rule;
}

function build_cdr(array $overrides = []): array {
	$row = [
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
		'duration' => '20',
		'billsec' => '10',
		'route_key' => '18005550001|',
	];

	foreach ($overrides as $key => $value) {
		$row[$key] = $value;
	}

	return $row;
}

$linkedRows = [
	build_cdr(['linkedid' => 'L-100', 'uniqueid' => 'U-100-a']),
	build_cdr(['linkedid' => 'L-100', 'uniqueid' => 'U-100-b', 'dstchannel' => 'Local/200@from-queue-00000003']),
];
$linkedJourneys = DetectionEngine::collapseCallJourneys($linkedRows);
assert_same(1, count($linkedJourneys), 'one linkedid should count as one call journey');
assert_same('linkedid', $linkedJourneys[0]['identity_type'], 'linkedid should be primary call identity');

$uniqueRows = [
	build_cdr(['linkedid' => '', 'uniqueid' => 'U-200']),
	build_cdr(['linkedid' => '', 'uniqueid' => 'U-200', 'dstchannel' => 'Local/300@from-ringgroup-00000004']),
];
$uniqueJourneys = DetectionEngine::collapseCallJourneys($uniqueRows);
assert_same(1, count($uniqueJourneys), 'uniqueid should deduplicate when linkedid is unavailable');
assert_same('uniqueid', $uniqueJourneys[0]['identity_type'], 'uniqueid should be secondary call identity');

$fingerprintRows = [
	build_cdr(['linkedid' => '', 'uniqueid' => '', 'dstchannel' => 'Local/400@from-queue-00000005']),
	build_cdr(['linkedid' => '', 'uniqueid' => '', 'dstchannel' => 'Local/500@from-queue-00000006']),
];
$fingerprintJourneys = DetectionEngine::collapseCallJourneys($fingerprintRows);
assert_same(1, count($fingerprintJourneys), 'conservative fingerprint should deduplicate only when both ids are unavailable');
assert_same('fingerprint', $fingerprintJourneys[0]['identity_type'], 'fingerprint should be the last-resort identity type');

$independentRule = build_rule();
$independentResult = DetectionEngine::evaluateRepeat([
	build_cdr(['linkedid' => 'C1-A', 'uniqueid' => 'C1-A', 'calldate' => '2026-07-13 09:05:00', 'src' => '01230000001', 'clid' => '01230000001']),
	build_cdr(['linkedid' => 'C1-B', 'uniqueid' => 'C1-B', 'calldate' => '2026-07-13 09:10:00', 'src' => '01230000001', 'clid' => '01230000001']),
	build_cdr(['linkedid' => 'C2-A', 'uniqueid' => 'C2-A', 'calldate' => '2026-07-13 09:15:00', 'src' => '01230000002', 'clid' => '01230000002']),
], $independentRule, '44');
assert_same(1, count($independentResult['incidents']), 'different callers should be counted independently');
assert_same('+441230000001', $independentResult['incidents'][0]['caller_normalized'], 'only the caller reaching threshold should create an incident');

$combinedDidRule = build_rule([
	'did_scope_mode' => 'selected',
	'include_routes' => ['18005550001|', '18005550002|'],
]);
$combinedDidResult = DetectionEngine::evaluateRepeat([
	build_cdr(['linkedid' => 'DID-A', 'calldate' => '2026-07-13 09:00:00', 'route_key' => '18005550001|', 'did' => '18005550001']),
	build_cdr(['linkedid' => 'DID-B', 'calldate' => '2026-07-13 09:20:00', 'route_key' => '18005550002|', 'did' => '18005550002']),
], $combinedDidRule, '44');
assert_same(1, count($combinedDidResult['incidents']), 'selected DIDs should be combined within one rule');

$excludedDidRule = build_rule([
	'did_scope_mode' => 'selected',
	'include_routes' => ['18005550001|', '18005550002|'],
	'exclude_routes' => ['18005550002|'],
]);
$excludedDidResult = DetectionEngine::evaluateRepeat([
	build_cdr(['linkedid' => 'XDID-A', 'calldate' => '2026-07-13 09:00:00', 'route_key' => '18005550001|', 'did' => '18005550001']),
	build_cdr(['linkedid' => 'XDID-B', 'calldate' => '2026-07-13 09:20:00', 'route_key' => '18005550002|', 'did' => '18005550002']),
], $excludedDidRule, '44');
assert_same(0, count($excludedDidResult['incidents']), 'DID exclusions should be applied after inclusions');

$excludedCallerRule = build_rule([
	'exclude_callers' => ['+441230000003'],
]);
$excludedCallerResult = DetectionEngine::evaluateRepeat([
	build_cdr(['linkedid' => 'XCALL-A', 'calldate' => '2026-07-13 09:00:00', 'src' => '01230000003', 'clid' => '01230000003']),
	build_cdr(['linkedid' => 'XCALL-B', 'calldate' => '2026-07-13 09:10:00', 'src' => '01230000003', 'clid' => '01230000003']),
], $excludedCallerRule, '44');
assert_same(0, count($excludedCallerResult['incidents']), 'caller exclusions should be applied after inclusions');

$specificCallerRule = build_rule([
	'caller_mode' => 'specific_only',
	'include_callers' => ['+441234567890'],
]);
$specificCallerResult = DetectionEngine::evaluateRepeat([
	build_cdr(['linkedid' => 'NAT-A', 'calldate' => '2026-07-13 09:00:00', 'src' => '01234567890', 'clid' => '01234567890']),
	build_cdr(['linkedid' => 'NAT-B', 'calldate' => '2026-07-13 09:15:00', 'src' => '+441234567890', 'clid' => '+441234567890']),
], $specificCallerRule, '44');
assert_same(1, count($specificCallerResult['incidents']), 'national and E.164 variants should match using the configured default country');

$withheldAnyResult = DetectionEngine::evaluateRepeat([
	build_cdr(['linkedid' => 'W-A', 'calldate' => '2026-07-13 09:00:00', 'src' => '', 'clid' => 'Anonymous']),
	build_cdr(['linkedid' => 'W-B', 'calldate' => '2026-07-13 09:10:00', 'src' => '', 'clid' => 'Private']),
], build_rule(), '44');
assert_same(0, count($withheldAnyResult['incidents']), 'withheld calls should not count unless explicitly selected');

$withheldOnlyResult = DetectionEngine::evaluateRepeat([
	build_cdr(['linkedid' => 'WW-A', 'calldate' => '2026-07-13 09:00:00', 'src' => '', 'clid' => 'Anonymous']),
	build_cdr(['linkedid' => 'WW-B', 'calldate' => '2026-07-13 09:10:00', 'src' => '', 'clid' => 'Private']),
], build_rule(['caller_mode' => 'withheld_only']), '44');
assert_same(1, count($withheldOnlyResult['incidents']), 'withheld calls should count when explicitly selected');
assert_true((bool)$withheldOnlyResult['incidents'][0]['withheld'], 'withheld incidents should be marked as such');

$repeatTriggerResult = DetectionEngine::evaluateRepeat([
	build_cdr(['linkedid' => 'R-A', 'calldate' => '2026-07-13 09:00:00']),
	build_cdr(['linkedid' => 'R-B', 'calldate' => '2026-07-13 09:10:00']),
], build_rule(), '44');
assert_same(1, count($repeatTriggerResult['incidents']), 'repeat mode should trigger as soon as threshold is reached within the window');
assert_same('2026-07-13 09:10:00', $repeatTriggerResult['incidents'][0]['first_matched_at'], 'incident should trigger at the threshold-crossing call');

$belowThresholdResult = DetectionEngine::evaluateRepeat([
	build_cdr(['linkedid' => 'B-A', 'calldate' => '2026-07-13 09:00:00']),
], build_rule(), '44');
assert_same(0, count($belowThresholdResult['incidents']), 'repeat mode should not trigger below threshold');

$invertRule = build_rule([
	'mode' => 'invert',
	'caller_mode' => 'specific_only',
	'include_callers' => ['+441234567890'],
	'threshold_count' => 2,
	'observation_window_minutes' => 60,
]);
$invertEarly = DetectionEngine::evaluateInvert([
	build_cdr(['linkedid' => 'I-A', 'calldate' => '2026-07-13 09:15:00', 'src' => '01234567890', 'clid' => '01234567890']),
], $invertRule, '44', ['+441234567890'], '2026-07-13 09:00:00', '2026-07-13 09:30:00');
assert_true(!$invertEarly['evaluated'], 'invert mode should evaluate only after the full window has elapsed');

$invertTriggered = DetectionEngine::evaluateInvert([
	build_cdr(['linkedid' => 'I-B', 'calldate' => '2026-07-13 09:15:00', 'src' => '01234567890', 'clid' => '01234567890']),
], $invertRule, '44', ['+441234567890'], '2026-07-13 09:00:00', '2026-07-13 10:00:00');
assert_true($invertTriggered['evaluated'], 'invert mode should evaluate once the full window has elapsed');
assert_same(1, count($invertTriggered['incidents']), 'invert mode should trigger when the threshold has not been met');

$outsideScheduleRule = build_rule([
	'schedules' => [
		['day' => 1, 'start' => '10:00', 'end' => '11:00'],
	],
]);
$outsideScheduleResult = DetectionEngine::evaluateRepeat([
	build_cdr(['linkedid' => 'OS-A', 'calldate' => '2026-07-13 09:00:00']),
	build_cdr(['linkedid' => 'OS-B', 'calldate' => '2026-07-13 09:10:00']),
], $outsideScheduleRule, '44');
assert_same(0, count($outsideScheduleResult['incidents']), 'calls outside the configured schedule should not count');

$anyDayRule = build_rule([
	'schedules' => [
		['day' => -1, 'start' => '09:00', 'end' => '10:00'],
	],
]);
$anyDayResult = DetectionEngine::evaluateRepeat([
	build_cdr(['linkedid' => 'ANY-A', 'calldate' => '2026-07-12 09:05:00']),
	build_cdr(['linkedid' => 'ANY-B', 'calldate' => '2026-07-12 09:10:00']),
], $anyDayRule, '44');
assert_same(1, count($anyDayResult['incidents']), 'Any-day schedules should match every day of week');

$allDayRule = build_rule([
	'schedules' => [
		['day' => 1, 'start' => '00:00', 'end' => '24:00'],
	],
]);
$allDayResult = DetectionEngine::evaluateRepeat([
	build_cdr(['linkedid' => 'AD-A', 'calldate' => '2026-07-13 23:50:00']),
	build_cdr(['linkedid' => 'AD-B', 'calldate' => '2026-07-13 23:55:00']),
], $allDayRule, '44');
assert_same(1, count($allDayResult['incidents']), '24-hour schedules should stay active for the whole selected day');

$continuousRule = build_rule([
	'observation_window_minutes' => 2000,
	'schedules' => [
		['day' => -1, 'start' => '00:00', 'end' => '24:00'],
	],
]);
$continuousResult = DetectionEngine::evaluateRepeat([
	build_cdr(['linkedid' => 'CT-A', 'calldate' => '2026-07-12 23:00:00']),
	build_cdr(['linkedid' => 'CT-B', 'calldate' => '2026-07-13 10:00:00']),
], $continuousRule, '44');
assert_same(1, count($continuousResult['incidents']), 'Any + 24-hour schedules should remain always eligible');

$canonicalStoredAnyAllDayRule = build_rule([
	'threshold_count' => 3,
	'observation_window_minutes' => 5,
	'schedules' => [
		['day_of_week' => -1, 'start_time' => '00:00:00', 'end_time' => '24:00:00'],
	],
]);
$canonicalStoredAnyAllDayResult = DetectionEngine::evaluateRepeat([
	build_cdr(['linkedid' => 'DB-A', 'calldate' => '2026-07-19 10:12:26']),
	build_cdr(['linkedid' => 'DB-B', 'calldate' => '2026-07-19 10:12:30']),
	build_cdr(['linkedid' => 'DB-C', 'calldate' => '2026-07-19 10:12:35']),
], $canonicalStoredAnyAllDayRule, '44');
assert_same(1, count($canonicalStoredAnyAllDayResult['incidents']), 'canonical stored Any + 24-hour schedule rows should be runtime-eligible and trigger threshold incidents');
assert_same(3, (int)$canonicalStoredAnyAllDayResult['incidents'][0]['matched_call_count'], 'eligible canonical stored Any + 24-hour schedules should accumulate all calls within the five-minute window');
assert_same(1, count($canonicalStoredAnyAllDayResult['subject_states']), 'runtime should create subject state once canonical Any + 24-hour schedules reach threshold');

$multiPeriodRule = build_rule([
	'observation_window_minutes' => 600,
	'schedules' => [
		['day' => 1, 'start' => '09:00', 'end' => '10:00'],
		['day' => 1, 'start' => '17:00', 'end' => '18:00'],
	],
]);
$multiPeriodResult = DetectionEngine::evaluateRepeat([
	build_cdr(['linkedid' => 'MP-A', 'calldate' => '2026-07-13 09:15:00']),
	build_cdr(['linkedid' => 'MP-B', 'calldate' => '2026-07-13 17:10:00']),
], $multiPeriodRule, '44');
assert_same(1, count($multiPeriodResult['incidents']), 'multiple active periods should be respected when both calls fall within active periods');

$singleIncidentResult = DetectionEngine::evaluateRepeat([
	build_cdr(['linkedid' => 'SI-A', 'calldate' => '2026-07-13 09:00:00']),
	build_cdr(['linkedid' => 'SI-B', 'calldate' => '2026-07-13 09:10:00']),
	build_cdr(['linkedid' => 'SI-C', 'calldate' => '2026-07-13 09:20:00']),
], build_rule(), '44');
assert_same(1, count($singleIncidentResult['incidents']), 'once triggered, further matching calls should update the same incident');
assert_same(3, $singleIncidentResult['incidents'][0]['matched_call_count'], 'same active incident should accumulate further matching calls');

$suppressionRule = build_rule([
	'suppression_minutes' => 10,
]);
$suppressionResult = DetectionEngine::evaluateRepeat([
	build_cdr(['linkedid' => 'SUP-A', 'calldate' => '2026-07-13 09:00:00']),
	build_cdr(['linkedid' => 'SUP-B', 'calldate' => '2026-07-13 09:05:00']),
	build_cdr(['linkedid' => 'SUP-C', 'calldate' => '2026-07-13 09:20:00']),
], $suppressionRule, '44');
assert_same(1, count($suppressionResult['incidents']), 'suppression should stop alerting after the configured duration');
assert_same('expired', $suppressionResult['incidents'][0]['state'], 'incident should expire when suppression duration elapses');

$rearmRule = build_rule([
	'suppression_minutes' => 10,
	'observation_window_minutes' => 60,
]);
$rearmResult = DetectionEngine::evaluateRepeat([
	build_cdr(['linkedid' => 'RE-A', 'calldate' => '2026-07-13 09:00:00']),
	build_cdr(['linkedid' => 'RE-B', 'calldate' => '2026-07-13 09:05:00']),
	build_cdr(['linkedid' => 'RE-C', 'calldate' => '2026-07-13 09:20:00']),
	build_cdr(['linkedid' => 'RE-D', 'calldate' => '2026-07-13 11:30:00']),
	build_cdr(['linkedid' => 'RE-E', 'calldate' => '2026-07-13 11:35:00']),
], $rearmRule, '44');
assert_same(2, count($rearmResult['incidents']), 'same caller should retrigger only after the condition clears and then crosses again');

echo "repeat detection contract tests passed\n";
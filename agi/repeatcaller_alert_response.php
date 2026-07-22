#!/usr/bin/env php
<?php

declare(strict_types=1);

const REPEATCALLER_AGI_EXIT_OK = 0;
const REPEATCALLER_AGI_EXIT_BOOTSTRAP_FAILED = 10;
const REPEATCALLER_AGI_EXIT_RESOLVE_FAILED = 11;
const REPEATCALLER_AGI_EXIT_REPOSITORY_MISSING = 12;
const REPEATCALLER_AGI_EXIT_PERSISTENCE_FAILED = 20;

function repeatcallerAgiLog(string $message, array $context = []): void {
	if ($context !== []) {
		$pairs = [];
		foreach ($context as $key => $value) {
			$pairs[] = (string)$key . '=' . str_replace(["\n", "\r"], ' ', (string)$value);
		}
		$message .= ' [' . implode(' ', $pairs) . ']';
	}

	if (class_exists('FreePBX') && method_exists('FreePBX', 'Log')) {
		try {
			$logger = \FreePBX::Log();
			if (is_object($logger) && method_exists($logger, 'error')) {
				$logger->error($message);
				return;
			}
		} catch (\Throwable $e) {
		}
	}

	error_log($message);
}

function repeatcallerBootstrapPath(): string {
	$override = trim((string)getenv('REPEATCALLER_AGI_BOOTSTRAP'));
	if ($override !== '') {
		return $override;
	}

	return '/etc/freepbx.conf';
}

function repeatcallerResolveModuleRoot(): ?string {
	$override = trim((string)getenv('REPEATCALLER_MODULE_ROOT'));
	if ($override !== '') {
		$candidate = rtrim($override, '/');
		if (is_dir($candidate)) {
			return rtrim($override, '/');
		}
	}

	$candidates = [];

	if (!empty($GLOBALS['amp_conf']) && is_array($GLOBALS['amp_conf'])) {
		$ampWebRoot = rtrim((string)($GLOBALS['amp_conf']['AMPWEBROOT'] ?? ''), '/');
		if ($ampWebRoot !== '') {
			$candidates[] = $ampWebRoot . '/admin/modules/repeatcaller';
			if (substr($ampWebRoot, -6) === '/admin') {
				$candidates[] = $ampWebRoot . '/modules/repeatcaller';
			}
		}
	}

	if (class_exists('FreePBX') && method_exists('FreePBX', 'Config')) {
		try {
			$ampWebRoot = rtrim((string)\FreePBX::Config()->get('AMPWEBROOT'), '/');
			if ($ampWebRoot !== '') {
				$candidates[] = $ampWebRoot . '/admin/modules/repeatcaller';
				if (substr($ampWebRoot, -6) === '/admin') {
					$candidates[] = $ampWebRoot . '/modules/repeatcaller';
				}
			}
		} catch (\Throwable $e) {
		}
	}

	$candidates = array_merge($candidates, [
		'/var/www/html/admin/modules/repeatcaller',
		'/var/www/html/admin/modules/repeatcaller/',
		'/usr/src/freepbx/admin/modules/repeatcaller',
	]);

	$candidates = array_values(array_unique(array_map(function (string $path): string {
		return rtrim($path, '/');
	}, $candidates)));

	foreach ($candidates as $candidate) {
		if (is_dir($candidate)) {
			return $candidate;
		}
	}

	return null;
}

function repeatcallerDigitsOnly(string $value): string {
	$digits = preg_replace('/\D+/', '', trim($value));
	return is_string($digits) ? $digits : '';
}

function repeatcallerSummaryContextFromCallRow(array $callRow): array {
	$summaryMode = 'repeat';

	$callerSource = trim((string)($callRow['caller_display'] ?? ''));
	if ($callerSource === '') {
		$callerSource = trim((string)($callRow['caller_normalized'] ?? ''));
	}
	$callerDigits = repeatcallerDigitsOnly($callerSource);
	$callerKind = $callerDigits !== '' ? 'numeric' : (!empty($callRow['withheld_caller']) ? 'unknown' : 'none');
	if (strpos((string)($callRow['subject_key'] ?? ''), '__invert_rule__') === 0) {
		$callerKind = 'none';
		$callerDigits = '';
	}

	$subjectLabel = (string)($callRow['subject_label'] ?? '');
	$didValue = '';
	if (strpos($subjectLabel, ' @ ') !== false) {
		$parts = explode(' @ ', $subjectLabel, 2);
		$didValue = repeatcallerDigitsOnly((string)($parts[1] ?? ''));
	}

	return [
		'summary_mode' => $summaryMode,
		'summary_call_count' => (string)max(0, (int)($callRow['matched_call_count'] ?? 0)),
		'summary_threshold' => (string)max(0, (int)($callRow['threshold_count'] ?? 0)),
		'summary_window_minutes' => (string)max(0, (int)($callRow['observation_window_minutes'] ?? 0)),
		'summary_caller_kind' => $callerKind,
		'summary_caller_value' => $callerDigits,
		'summary_did_value' => $didValue,
	];
}

function repeatcallerTryImmediateOrderedFollowUp(\FreePBX\modules\Repeatcaller\RepeatCallerRepository $repository, string $moduleRoot, array $context, array $result, string $now): void {
	$nextHistoryId = isset($result['next_history_id']) ? (int)$result['next_history_id'] : 0;
	if ($nextHistoryId <= 0) {
		return;
	}

	$nextCall = $repository->loadDeliverableCallAlertByHistoryId($nextHistoryId, $now);
	if (!is_array($nextCall)) {
		return;
	}

	if (!$repository->markCallAlertSending($nextHistoryId, $now)) {
		return;
	}

	require_once $moduleRoot . '/Repeatcaller.class.php';
	$module = new \FreePBX\modules\Repeatcaller(new \stdClass());
	$sendResult = $module->sendAlertCall(
		(string)$nextCall['recipient'],
		(string)($nextCall['alert_call_recording_id'] ?? ''),
		(string)($nextCall['alert_call_callerid'] ?? ''),
		[
			'history_id' => (int)$nextCall['id'],
			'incident_id' => (int)$nextCall['incident_id'],
			'recipient' => (string)$nextCall['recipient'],
		] + repeatcallerSummaryContextFromCallRow($nextCall)
	);

	if (!empty($sendResult['status'])) {
		$repository->markCallAlertSent($nextHistoryId, $now);
		return;
	}

	$repository->markCallAlertSnoozed($nextHistoryId, $now);
	repeatcallerAgiLog('repeatcaller AGI: immediate ordered follow-up send failed; left deliverable for monitor retry', $context + [
		'next_history_id' => (string)$nextHistoryId,
		'send_error' => (string)($sendResult['message'] ?? 'unknown'),
	]);
}

$historyId = isset($argv[1]) && ctype_digit((string)$argv[1]) ? (int)$argv[1] : 0;
$incidentId = isset($argv[2]) && ctype_digit((string)$argv[2]) ? (int)$argv[2] : 0;
$response = strtolower(trim((string)($argv[3] ?? '')));
$recipient = preg_replace('/[^0-9A-Za-z_.+@:-]/', '', trim((string)($argv[4] ?? ''))) ?? '';
$digit = substr(preg_replace('/[^0-9A-Za-z*#]/', '', trim((string)($argv[5] ?? ''))) ?? '', 0, 8);
$dialStatus = strtoupper(trim((string)($argv[5] ?? '')));
$hangupCause = trim((string)($argv[6] ?? ''));

$context = [
	'history_id' => (string)$historyId,
	'incident_id' => (string)$incidentId,
	'outcome' => $response,
	'recipient' => $recipient,
	'digit' => $digit,
	'dial_status' => $dialStatus,
	'hangup_cause' => $hangupCause,
	'script_dir' => __DIR__,
];

$bootstrapPath = repeatcallerBootstrapPath();
if (!is_file($bootstrapPath)) {
	repeatcallerAgiLog('repeatcaller AGI: FreePBX bootstrap file not found', $context + ['bootstrap_path' => $bootstrapPath]);
	exit(REPEATCALLER_AGI_EXIT_BOOTSTRAP_FAILED);
}

try {
	require_once $bootstrapPath;
} catch (\Throwable $e) {
	repeatcallerAgiLog('repeatcaller AGI: failed to bootstrap FreePBX: ' . get_class($e) . ': ' . $e->getMessage(), $context + ['bootstrap_path' => $bootstrapPath]);
	exit(REPEATCALLER_AGI_EXIT_BOOTSTRAP_FAILED);
}

$moduleRoot = repeatcallerResolveModuleRoot();
if ($moduleRoot === null) {
	$attempted = [];
	if (!empty($GLOBALS['amp_conf']) && is_array($GLOBALS['amp_conf'])) {
		$attempted[] = 'amp_conf.AMPWEBROOT=' . (string)($GLOBALS['amp_conf']['AMPWEBROOT'] ?? '');
	}
	if (class_exists('FreePBX') && method_exists('FreePBX', 'Config')) {
		try {
			$attempted[] = 'FreePBX::Config(AMPWEBROOT)=' . (string)\FreePBX::Config()->get('AMPWEBROOT');
		} catch (\Throwable $ignored) {
			$attempted[] = 'FreePBX::Config(AMPWEBROOT)=<error>';
		}
	}
	repeatcallerAgiLog('repeatcaller AGI: unable to resolve module root path for repeatcaller', $context + ['bootstrap_path' => $bootstrapPath, 'attempted_roots' => implode(' | ', $attempted)]);
	exit(REPEATCALLER_AGI_EXIT_RESOLVE_FAILED);
}

$repositoryPath = $moduleRoot . '/src/RepeatCallerRepository.php';
if (!is_file($repositoryPath)) {
	repeatcallerAgiLog('repeatcaller AGI: RepeatCallerRepository class file is missing', $context + ['bootstrap_path' => $bootstrapPath, 'module_root' => $moduleRoot, 'repository_path' => $repositoryPath]);
	exit(REPEATCALLER_AGI_EXIT_REPOSITORY_MISSING);
}

require_once $repositoryPath;

use FreePBX\modules\Repeatcaller\RepeatCallerRepository;

if ($historyId <= 0 || $incidentId <= 0 || !in_array($response, ['accepted', 'declined', 'timeout', 'hangup', 'answered_no_response', 'dialstatus'], true)) {
	repeatcallerAgiLog('repeatcaller AGI: invalid alert response args received', $context);
	exit(REPEATCALLER_AGI_EXIT_PERSISTENCE_FAILED);
}

try {
	$repository = new RepeatCallerRepository(\FreePBX::Database());
	$now = date('Y-m-d H:i:s');
	if ($response === 'dialstatus') {
		$result = $repository->recordAlertCallDialDisposition($historyId, $incidentId, $recipient, $dialStatus, $hangupCause, $now);
		repeatcallerTryImmediateOrderedFollowUp($repository, $moduleRoot, $context, $result, $now);
	} else {
		$result = $repository->recordAlertCallDtmfResponse($historyId, $incidentId, $response, $recipient, $digit, $now);
		repeatcallerTryImmediateOrderedFollowUp($repository, $moduleRoot, $context, $result, $now);
		if (!empty($result['status']) && $response === 'declined') {
			$notice = $repository->buildDeclineNotificationContext($historyId, $incidentId);
			if (is_array($notice) && !empty($notice['email_enabled']) && !empty($notice['recipients']) && is_array($notice['recipients'])) {
				$subject = 'Repeat Caller alert declined';
				$callerLabel = trim((string)($notice['caller_display'] ?? ''));
				if ($callerLabel === '') {
					$callerLabel = trim((string)($notice['caller_normalized'] ?? ''));
				}
				if ($callerLabel === '') {
					$callerLabel = trim((string)($notice['subject_label'] ?? 'unknown'));
				}
				$body = [];
				$body[] = 'Repeat Caller alert declined';
				$body[] = '';
				$body[] = 'Alert Call recipient: ' . (string)($notice['recipient'] ?? '-');
				$body[] = 'Caller: ' . $callerLabel;
				$body[] = 'Rule: ' . (string)($notice['rule_name'] ?? '-');
				$body[] = 'Incident ID: ' . (int)($notice['incident_id'] ?? 0);
				$body[] = 'Incident remains unaccepted: yes';
				$body[] = 'Another eligible Alert Call recipient remains: ' . (!empty($notice['has_remaining_eligible']) ? 'yes' : 'no');
				if (!empty($notice['ordered_continuing'])) {
					$body[] = 'Ordered routing continues to the next eligible recipient.';
				}
				$message = implode("\n", $body);

				require_once $moduleRoot . '/Repeatcaller.class.php';
				$module = new \FreePBX\modules\Repeatcaller(new \stdClass());
				foreach ($notice['recipients'] as $emailRecipient) {
					$sendResult = $module->sendEmail((string)$emailRecipient, $subject, $message);
					if (empty($sendResult['status'])) {
						repeatcallerAgiLog('repeatcaller AGI: failed to send decline notification email', $context + ['email_recipient' => (string)$emailRecipient, 'send_error' => (string)($sendResult['message'] ?? 'unknown')]);
					}
				}
			}
		}
	}
	if (empty($result['status'])) {
		$message = trim((string)($result['message'] ?? 'unknown failure'));
		repeatcallerAgiLog('repeatcaller AGI: DTMF response was not persisted: ' . $message, $context);
		exit(REPEATCALLER_AGI_EXIT_PERSISTENCE_FAILED);
	}
} catch (\Throwable $e) {
	repeatcallerAgiLog('repeatcaller AGI: exception while recording DTMF response: ' . get_class($e) . ': ' . $e->getMessage(), $context);
	exit(REPEATCALLER_AGI_EXIT_PERSISTENCE_FAILED);
}

exit(REPEATCALLER_AGI_EXIT_OK);

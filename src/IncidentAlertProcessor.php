<?php

declare(strict_types=1);

namespace FreePBX\modules\Repeatcaller;

final class IncidentAlertProcessor {
	private const REPEAT_MODE_NEVER = 'never';
	private const REPEAT_MODE_FIVE_MINUTES = '5m';
	private const REPEAT_MODE_HOURLY = 'hourly';
	private const REPEAT_MODE_DAILY = 'daily';
	private const REPEAT_MODE_ESCALATING = 'escalating';
	private const REPEAT_MODE_FIBONACCI = 'fibonacci';
	private const REPEAT_ESCALATING_BASE_SECONDS = 300;
	private const REPEAT_ESCALATING_CEILING_SECONDS = 86400;

	private RepeatCallerRepository $repository;
	/** @var callable */
	private $emailSender;
	/** @var callable */
	private $callAlertSender;
	/** @var callable */
	private $nowProvider;

	public function __construct(RepeatCallerRepository $repository, ?callable $emailSender = null, ?callable $nowProvider = null, ?callable $callAlertSender = null) {
		$this->repository = $repository;
		$this->emailSender = $emailSender ?? function (string $recipient, string $subject, string $message): array {
			return ['status' => false, 'message' => 'Email transport not configured'];
		};
		$this->callAlertSender = $callAlertSender ?? function (string $destination, string $recordingId, string $callerId = '', array $context = []): array {
			return ['status' => false, 'message' => 'Alert call transport not configured'];
		};
		$this->nowProvider = $nowProvider ?? function (): string {
			return date('Y-m-d H:i:s');
		};
	}

	public function run(array $settings): array {
		$now = $this->now();
		$summary = [
			'incidents_considered' => 0,
			'initial_events' => 0,
			'reminder_events' => 0,
			'email_queued' => 0,
			'email_sent' => 0,
			'email_failed' => 0,
			'email_deferred_snooze' => 0,
			'alert_call_queued' => 0,
			'alert_call_sent' => 0,
			'alert_call_failed' => 0,
			'alert_call_deferred_snooze' => 0,
			'expired_incidents' => 0,
			'pruned_alert_history' => 0,
			'pruned_suppression_history' => 0,
			'pruned_alert_state' => 0,
			'pruned_incidents' => 0,
			'pruned_seen_calls' => 0,
		];

		$summary['expired_incidents'] = $this->repository->expireActiveIncidents($now);
		$incidents = $this->repository->loadAlertEligibleIncidents($now, 500);
		$summary['incidents_considered'] = count($incidents);

		foreach ($incidents as $incident) {
			$this->processIncidentStage($incident, $summary, $now);
		}

		$snoozed = $this->isGloballySnoozed($settings, $now);
		$emailAlerts = $this->repository->loadDeliverableEmailAlerts($now, 500);
		foreach ($emailAlerts as $emailAlert) {
			if ($snoozed) {
				$this->repository->markEmailAlertSnoozed((int)$emailAlert['id'], $now);
				$summary['email_deferred_snooze']++;
				continue;
			}

			if (!$this->repository->markEmailAlertSending((int)$emailAlert['id'], $now)) {
				continue;
			}

			$subject = $this->buildEmailSubject($emailAlert);
			$message = $this->buildEmailMessage($emailAlert, $now);
			$result = call_user_func($this->emailSender, (string)$emailAlert['recipient'], $subject, $message);

			if (!empty($result['status'])) {
				$this->repository->markEmailAlertSent((int)$emailAlert['id'], $now);
				$summary['email_sent']++;
				continue;
			}

			$error = trim((string)($result['message'] ?? 'Unknown email error'));
			$retryAt = date('Y-m-d H:i:s', strtotime($now) + 300);
			$this->repository->markEmailAlertFailed((int)$emailAlert['id'], $error, $retryAt, $now);
			$summary['email_failed']++;
		}

		$callAlerts = $this->repository->loadDeliverableCallAlerts($now, 500);
		foreach ($callAlerts as $callAlert) {
			if ($snoozed) {
				$this->repository->markCallAlertSnoozed((int)$callAlert['id'], $now);
				$summary['alert_call_deferred_snooze']++;
				continue;
			}

			if (!$this->repository->markCallAlertSending((int)$callAlert['id'], $now)) {
				continue;
			}

			$result = $this->sendAlertCall($callAlert);

			if (!empty($result['status'])) {
				$this->repository->markCallAlertSent((int)$callAlert['id'], $now);
				$summary['alert_call_sent']++;
				continue;
			}

			$error = trim((string)($result['message'] ?? 'Unknown alert call error'));
			$this->repository->markCallAlertFailed((int)$callAlert['id'], $error, $now);
			$summary['alert_call_failed']++;
		}

		$alertCutoff = $this->pruneCutoff((string)($settings['alert_history_prune_policy'] ?? 'never'), $now);
		if ($alertCutoff !== null) {
			$summary['pruned_alert_history'] = $this->repository->pruneIncidentAlertHistory($alertCutoff);
		}

		$suppressionCutoff = $this->pruneCutoff((string)($settings['suppression_history_prune_policy'] ?? 'never'), $now);
		if ($suppressionCutoff !== null) {
			$summary['pruned_suppression_history'] = $this->repository->pruneSuppressedIncidentHistory($suppressionCutoff);
		}

		// Incident retention is a policy independent of alert-history retention: pruning old
		// alert history must never be a back door for deleting incident rows or their alert
		// state. Only an explicit, non-"never" incident_history_prune_policy may do that.
		$incidentCutoff = $this->pruneCutoff((string)($settings['incident_history_prune_policy'] ?? 'never'), $now);
		if ($incidentCutoff !== null) {
			$summary['pruned_alert_state'] = $this->repository->pruneIncidentAlertState($incidentCutoff);
			$summary['pruned_incidents'] = $this->repository->pruneClosedIncidents($incidentCutoff);
		}

		$summary['pruned_seen_calls'] = $this->repository->pruneSeenCallsByFixedRetention($now);

		return $summary;
	}

	private function processIncidentStage(array $incident, array &$summary, string $now): void {
		$incidentId = (int)$incident['id'];
		$ruleId = (int)$incident['rule_id'];
		$incidentState = strtolower(trim((string)($incident['state'] ?? 'active')));
		$recipients = $this->normaliseRecipients((string)($incident['email_recipients'] ?? ''));
		$repeatMode = $this->resolveRepeatMode(
			isset($incident['repeat_mode_override']) ? (string)$incident['repeat_mode_override'] : null
		);
		$firstDueAt = (string)$incident['first_matched_at'];
		$lastMatchedAt = trim((string)($incident['last_matched_at'] ?? ''));
		$state = $this->repository->ensureIncidentAlertState($incidentId, $ruleId, $repeatMode, $firstDueAt, $now);
		$initialSentAt = isset($state['initial_sent_at']) ? (string)$state['initial_sent_at'] : '';
		$lastAlertAt = isset($state['last_alert_at']) ? trim((string)$state['last_alert_at']) : '';

		if ($incidentState === 'claimed') {
			if ($lastMatchedAt === '' || $lastAlertAt === '' || !$this->isAfter($lastMatchedAt, $lastAlertAt)) {
				return;
			}

			$remindersSent = max(0, (int)($state['reminders_sent'] ?? 0));
			$reminderN = $remindersSent + 1;
			if (!$this->reserveStageEvents($incident, $repeatMode, 'reminder', $reminderN, $recipients, $summary, $now)) {
				return;
			}
			$nextDue = $this->nextRepeatDueAt($now, $repeatMode, $reminderN);
			$this->repository->markReminderAlertSent($incidentId, $remindersSent, $reminderN, $now, $nextDue, $now);
			$summary['reminder_events']++;
			return;
		}

		if ($initialSentAt === '') {
			$dueAt = (string)($state['next_due_at'] ?? $firstDueAt);
			if (!$this->isDue($dueAt, $now)) {
				return;
			}

			if (!$this->reserveStageEvents($incident, $repeatMode, 'initial', 0, $recipients, $summary, $now)) {
				return;
			}
			$nextDue = $this->nextRepeatDueAt($now, $repeatMode, 0);
			$this->repository->markInitialAlertSent($incidentId, $now, $nextDue, $now);
			$summary['initial_events']++;
			return;
		}

		if ($repeatMode === self::REPEAT_MODE_NEVER) {
			return;
		}

		$nextDueAt = isset($state['next_due_at']) ? (string)$state['next_due_at'] : '';
		if ($nextDueAt === '' || !$this->isDue($nextDueAt, $now)) {
			return;
		}

		$remindersSent = max(0, (int)($state['reminders_sent'] ?? 0));
		$reminderN = $remindersSent + 1;
		if (!$this->reserveStageEvents($incident, $repeatMode, 'reminder', $reminderN, $recipients, $summary, $now)) {
			return;
		}
		$nextDue = $this->nextRepeatDueAt($now, $repeatMode, $reminderN);
		$this->repository->markReminderAlertSent($incidentId, $remindersSent, $reminderN, $now, $nextDue, $now);
		$summary['reminder_events']++;
	}

	private function isAfter(string $left, string $right): bool {
		$leftTs = strtotime($left);
		$rightTs = strtotime($right);
		if ($leftTs === false || $rightTs === false) {
			return false;
		}
		return $leftTs > $rightTs;
	}

	private function reserveStageEvents(array $incident, string $repeatMode, string $eventType, int $stageN, array $recipients, array &$summary, string $now): bool {
		$incidentId = (int)$incident['id'];
		$ruleId = (int)$incident['rule_id'];
		$subjectKey = (string)$incident['subject_key'];
		$subjectLabel = (string)$incident['subject_label'];
		$reservedAny = false;

		$reservedAny = $this->reserveTransportEvent($incidentId, $ruleId, $subjectKey, $subjectLabel, $repeatMode, $eventType, $stageN, 'gui', null, 'recorded', $now, $now, null, null, $now) || $reservedAny;

		$ruleEmailEnabled = !empty($incident['email_enabled']) && (string)$incident['email_enabled'] !== '0';
		if ($ruleEmailEnabled && $recipients) {
			foreach ($recipients as $recipient) {
				$reserved = $this->reserveTransportEvent($incidentId, $ruleId, $subjectKey, $subjectLabel, $repeatMode, $eventType, $stageN, 'email', $recipient, 'pending', null, null, $now, null, $now);
				if ($reserved) {
					$reservedAny = true;
					$summary['email_queued']++;
				}
			}
		}

		$ruleCallEnabled = !empty($incident['alert_call_enabled']) && (string)$incident['alert_call_enabled'] !== '0';
		$callDestinationEntries = $this->normaliseAlertCallDestinationEntries((string)($incident['alert_call_destinations'] ?? ''));
		if (!$ruleCallEnabled || !$callDestinationEntries) {
			return $reservedAny;
		}

		$strategy = $this->normaliseAlertCallStrategy((string)($incident['alert_call_strategy'] ?? 'ringall'));
		$eligibleDestinations = $this->repository->eligibleAlertCallDestinationsForStage(
			$incidentId,
			$callDestinationEntries,
			true,
			$eventType,
			$stageN
		);
		if (!$eligibleDestinations) {
			return $reservedAny;
		}

		$toReserve = $strategy === 'ordered' ? [reset($eligibleDestinations)] : $eligibleDestinations;
		foreach ($toReserve as $destination) {
			$reserved = $this->reserveTransportEvent($incidentId, $ruleId, $subjectKey, $subjectLabel, $repeatMode, $eventType, $stageN, 'alert_call', $destination, 'pending', null, null, $now, null, $now);
			if ($reserved) {
				$reservedAny = true;
				$summary['alert_call_queued']++;
			}
		}

		return $reservedAny;
	}

	private function reserveTransportEvent(int $incidentId, int $ruleId, string $subjectKey, string $subjectLabel, string $repeatMode, string $eventType, int $stageN, string $actionType, ?string $recipient, string $deliveryStatus, ?string $attemptedAt, ?string $successfulAt, ?string $nextRetryAt, ?string $failureDetail, string $now): bool {
		return $this->repository->reserveIncidentAlertHistory([
			'incident_id' => $incidentId,
			'rule_id' => $ruleId,
			'subject_key' => $subjectKey,
			'subject_label' => $subjectLabel,
			'action_type' => $actionType,
			'event_type' => $eventType,
			'stage_n' => $stageN,
			'recipient' => $recipient,
			'delivery_status' => $deliveryStatus,
			'attempted_at' => $attemptedAt,
			'successful_at' => $successfulAt,
			'next_retry_at' => $nextRetryAt,
			'failure_detail' => $failureDetail,
			'repeat_mode' => $repeatMode,
			'dedupe_key' => $this->dedupeKey($incidentId, $eventType, $stageN, $actionType, $recipient),
			'created_at' => $now,
			'updated_at' => $now,
		]);
	}

	private function sendAlertCall(array $callAlert): array {
		$summaryContext = $this->buildAlertCallSummaryContext($callAlert);
		$recordingId = trim((string)($callAlert['alert_call_recording_id'] ?? ''));
		if ($recordingId === '') {
			$ruleId = (int)($callAlert['rule_id'] ?? 0);
			if ($ruleId > 0) {
				$rule = $this->repository->loadRule($ruleId);
				if (is_array($rule) && ($rule['alert_call_recording_id'] ?? null) !== null && ($rule['alert_call_recording_id'] ?? '') !== '') {
					$recordingId = trim((string)$rule['alert_call_recording_id']);
				}
			}
		}
		$context = [
			'history_id' => (int)$callAlert['id'],
			'incident_id' => (int)$callAlert['incident_id'],
			'recipient' => (string)$callAlert['recipient'],
			// Alert-call execution should remain identical across incident modes.
			// Keep summary-mode on the repeat path so DTMF interruption behaviour
			// follows the same dialplan branch for repeat and invert incidents.
			'summary_mode' => 'repeat',
			'summary_call_count' => (string)$summaryContext['call_count'],
			'summary_threshold' => (string)$summaryContext['threshold'],
			'summary_window_minutes' => (string)$summaryContext['window_minutes'],
			'summary_caller_kind' => (string)$summaryContext['caller_kind'],
			'summary_caller_value' => (string)$summaryContext['caller'],
			'summary_did_value' => (string)$summaryContext['did'],
		];

		return call_user_func(
			$this->callAlertSender,
			(string)$callAlert['recipient'],
			$recordingId,
			(string)($callAlert['alert_call_callerid'] ?? ''),
			$context
		);
	}

	private function buildAlertCallSummaryContext(array $callAlert): array {
		$mode = strtolower((string)($callAlert['mode'] ?? 'repeat')) === 'invert' ? 'invert' : 'repeat';
		$rule = $this->repository->loadRule((int)($callAlert['rule_id'] ?? 0)) ?? [];
		$triggeringCalls = $this->loadAlertCallSummaryCalls($callAlert, $rule, $mode);
		$identity = $this->deriveAlertCallSummaryIdentity($callAlert, $triggeringCalls);
		$subjectKey = trim((string)($callAlert['subject_key'] ?? ''));
		$subjectLabel = strtolower(trim((string)($callAlert['subject_label'] ?? '')));
		if ($mode === 'invert' && (strpos($subjectKey, '__invert_rule__') === 0 || $subjectLabel === 'any caller')) {
			$identity['caller_kind'] = 'none';
			$identity['caller'] = '';
		}
		$callCount = max(0, (int)($callAlert['matched_call_count'] ?? 0));
		$threshold = max(0, (int)($callAlert['threshold_count'] ?? 0));
		$windowMinutes = max(0, (int)($callAlert['observation_window_minutes'] ?? 0));

		return [
			'mode' => $mode,
			'call_count' => (string)$callCount,
			'threshold' => (string)$threshold,
			'window_minutes' => (string)$windowMinutes,
			'caller_kind' => (string)$identity['caller_kind'],
			'caller' => (string)$identity['caller'],
			'did' => (string)$identity['did'],
		];
	}

	private function loadAlertCallSummaryCalls(array $callAlert, array $rule, string $mode): array {
		$windowEnd = trim((string)($callAlert['last_matched_at'] ?? $callAlert['first_matched_at'] ?? ''));
		if ($windowEnd === '') {
			return [];
		}

		$windowEndTs = strtotime($windowEnd);
		if ($windowEndTs === false) {
			return [];
		}

		$windowMinutes = max(0, (int)($callAlert['observation_window_minutes'] ?? 0));
		$windowStart = date('Y-m-d H:i:s', $windowEndTs - ($windowMinutes * 60));
		$subjectKey = trim((string)($callAlert['subject_key'] ?? ''));
		if ($subjectKey === '' && $mode !== 'invert') {
			return [];
		}

		if ($mode === 'invert' && strpos($subjectKey, '__invert_rule__') === 0) {
			$candidateCalls = $this->repository->loadRecentSeenCallsInWindow($windowStart, $windowEnd);
		} else {
			$callerSubjectKey = $this->extractAlertCallCallerSubjectKey($subjectKey);
			if ($callerSubjectKey === '') {
				return [];
			}
			$candidateCalls = $this->repository->loadRecentSeenCalls($callerSubjectKey, $windowStart, $windowEnd);
		}

		return $this->filterAlertCallSummaryCalls($candidateCalls, $rule, $callAlert, $mode, $windowEnd);
	}

	private function filterAlertCallSummaryCalls(array $candidateCalls, array $rule, array $callAlert, string $mode, string $windowEnd): array {
		$filtered = [];
		$schedules = isset($rule['schedules']) && is_array($rule['schedules']) ? $rule['schedules'] : [];
		$includeRoutes = [];
		$excludeRoutes = [];
		if (isset($rule['did_lists']) && is_array($rule['did_lists'])) {
			foreach (($rule['did_lists']['include'] ?? []) as $route) {
				$routeKey = trim((string)($route['route_key'] ?? ''));
				if ($routeKey !== '') {
					$includeRoutes[] = $routeKey;
				}
			}
			foreach (($rule['did_lists']['exclude'] ?? []) as $route) {
				$routeKey = trim((string)($route['route_key'] ?? ''));
				if ($routeKey !== '') {
					$excludeRoutes[] = $routeKey;
				}
			}
		}

		$routeScopeHash = $this->extractAlertCallRouteScopeHash((string)($callAlert['subject_key'] ?? ''), $mode);
		$didScopeMode = strtolower((string)($callAlert['did_scope_mode'] ?? 'all'));
		foreach ($candidateCalls as $row) {
			$completedAt = (string)($row['call_completed_at'] ?? $windowEnd);
			if ($schedules && !DetectionEngine::callInActiveSchedule($completedAt, $schedules)) {
				continue;
			}

			$routeKey = trim((string)($row['inbound_route_key'] ?? ''));
			if ($didScopeMode === 'selected' && $includeRoutes && !in_array($routeKey, $includeRoutes, true)) {
				continue;
			}
			if ($excludeRoutes && in_array($routeKey, $excludeRoutes, true)) {
				continue;
			}
			if ($routeScopeHash !== '') {
				if ($routeKey === '' || hash('sha1', $routeKey) !== $routeScopeHash) {
					continue;
				}
			}

			$filtered[] = $row;
		}

		return $filtered;
	}

	private function deriveAlertCallSummaryIdentity(array $callAlert, array $triggeringCalls): array {
		if ($triggeringCalls) {
			$numericCallers = [];
			$nonNumericCallerSignatures = [];
			$didValues = [];

			foreach ($triggeringCalls as $row) {
				$callerSource = trim((string)($row['caller_raw'] ?? ''));
				if ($callerSource === '') {
					$callerSource = trim((string)($row['caller_normalized'] ?? ''));
				}

				$callerDigits = $this->normaliseDigitsOnly($callerSource);
				if ($callerDigits !== '') {
					$numericCallers[$callerDigits] = true;
				} else {
					$nonNumericCallerSignatures[$this->alertCallCallerSignature($callerSource)] = true;
				}

				$didDigits = $this->normaliseDigitsOnly((string)($row['did_value'] ?? ''));
				if ($didDigits !== '') {
					$didValues[$didDigits] = true;
				}
			}

			$callerKind = 'none';
			$callerValue = '';
			if (count($numericCallers) === 1 && !$nonNumericCallerSignatures) {
				$callerKind = 'numeric';
				$callerValue = (string)array_key_first($numericCallers);
			} elseif (!$numericCallers && count($nonNumericCallerSignatures) === 1) {
				$callerKind = 'unknown';
			}

			return [
				'caller_kind' => $callerKind,
				'caller' => $callerValue,
				'did' => count($didValues) === 1 ? (string)array_key_first($didValues) : '',
			];
		}

		$subjectLabel = (string)($callAlert['subject_label'] ?? '');
		$subjectCallerPart = $subjectLabel;
		$routePart = '';
		if (strpos($subjectLabel, ' @ ') !== false) {
			$parts = explode(' @ ', $subjectLabel, 2);
			$subjectCallerPart = (string)($parts[0] ?? '');
			$routePart = (string)($parts[1] ?? '');
		}

		$callerSource = trim((string)($callAlert['caller_display'] ?? $callAlert['caller_normalized'] ?? $subjectCallerPart ?? $callAlert['subject_key'] ?? ''));
		$callerDigits = $this->normaliseDigitsOnly($callerSource);
		$callerKind = 'none';
		$callerValue = '';
		if ($callerDigits !== '') {
			$callerKind = 'numeric';
			$callerValue = $callerDigits;
		} elseif (!empty($callAlert['withheld_caller']) || $callerSource !== '') {
			$callerKind = 'unknown';
		}

		return [
			'caller_kind' => $callerKind,
			'caller' => $callerValue,
			'did' => $this->normaliseDigitsOnly($routePart),
		];
	}

	private function extractAlertCallCallerSubjectKey(string $subjectKey): string {
		$subjectKey = trim($subjectKey);
		$delimiterPos = strpos($subjectKey, '|route:');
		if ($delimiterPos === false) {
			return $subjectKey;
		}

		return substr($subjectKey, 0, $delimiterPos);
	}

	private function extractAlertCallRouteScopeHash(string $subjectKey, string $mode): string {
		if ($mode !== 'repeat') {
			return '';
		}

		if (!preg_match('/\|route:([a-f0-9]{40})$/', trim($subjectKey), $matches)) {
			return '';
		}

		return (string)$matches[1];
	}

	private function alertCallCallerSignature(string $callerSource): string {
		return strtolower(trim($callerSource));
	}

	private function normaliseDigitsOnly(string $value): string {
		$digits = preg_replace('/\D+/', '', trim($value));
		return is_string($digits) ? $digits : '';
	}

	private function normaliseAlertCallDestinationEntries(string $raw): array {
		$parts = preg_split('/[,;\s]+/', trim($raw));
		$destinations = [];
		foreach ($parts as $part) {
			$token = trim((string)$part);
			if ($token === '') {
				continue;
			}

			$destination = $token;
			$keepTrying = 1;
			if (preg_match('/^(.*)\|([01])$/', $token, $matches)) {
				$destination = trim((string)$matches[1]);
				$keepTrying = ((int)$matches[2]) === 1 ? 1 : 0;
			}

			if ($destination === '' || isset($destinations[$destination])) {
				continue;
			}

			$destinations[$destination] = [
				'destination' => $destination,
				'keep_trying' => $keepTrying,
			];
		}
		return array_values($destinations);
	}

	private function normaliseAlertCallDestinations(string $raw): array {
		$entries = $this->normaliseAlertCallDestinationEntries($raw);
		return array_map(static function (array $entry): string {
			return (string)$entry['destination'];
		}, $entries);
	}

	private function normaliseAlertCallStrategy(string $strategy): string {
		$strategy = strtolower(trim($strategy));
		if ($strategy === 'ordered') {
			return 'ordered';
		}
		return 'ringall';
	}

	private function alertCallKeepTrying(string $value): bool {
		$norm = strtolower(trim($value));
		return !in_array($norm, ['0', 'false', 'off', 'no'], true);
	}

	private function dedupeKey(int $incidentId, string $eventType, int $stageN, string $actionType, ?string $recipient): string {
		$recipientKey = $recipient === null ? '-' : strtolower(trim($recipient));
		return sprintf('incident:%d|event:%s|stage:%d|action:%s|recipient:%s', $incidentId, $eventType, $stageN, $actionType, $recipientKey);
	}

	private function buildEmailSubject(array $row): string {
		$rule = trim((string)($row['rule_name'] ?? 'Rule'));
		$subject = $this->buildIncidentSubjectForEmail($row);
		$eventType = (string)($row['event_type'] ?? 'initial');
		if ($eventType === 'initial') {
			return 'Repeat Caller: incident started [' . $rule . '] ' . $subject;
		}
		return 'Repeat Caller: incident reminder [' . $rule . '] ' . $subject;
	}

	private function buildIncidentSubjectForEmail(array $row): string {
		$mode = strtolower(trim((string)($row['mode'] ?? $row['incident_mode'] ?? '')));
		$subject = trim((string)($row['subject_label'] ?? $row['subject_key'] ?? 'unknown'));
		if ($mode !== 'invert') {
			return $subject !== '' ? $subject : 'unknown';
		}

		$threshold = (int)($row['threshold_count'] ?? $row['incident_threshold_count'] ?? 0);
		$windowMinutes = (int)($row['observation_window_minutes'] ?? $row['incident_observation_window_minutes'] ?? 0);
		if ($threshold < 0) {
			$threshold = 0;
		}
		if ($windowMinutes < 0) {
			$windowMinutes = 0;
		}

		$callWord = $threshold === 1 ? 'call' : 'calls';
		return 'Fewer than ' . $threshold . ' ' . $callWord . ' within a ' . $windowMinutes . '-minute window';
	}

	private function buildEmailMessage(array $row, string $now): string {
		$lines = [];
		$lines[] = 'Repeat Caller incident alert';
		$lines[] = '';
		$lines[] = 'Rule: ' . (string)($row['rule_name'] ?? '-');
		$lines[] = 'Subject: ' . (string)($row['subject_label'] ?? $row['subject_key'] ?? '-');
		$lines[] = 'Event: ' . $this->formatAlertTextLabel((string)($row['event_type'] ?? ''), [
			'initial' => 'Initial',
			'reminder' => 'Reminder',
		], '-');
		$lines[] = 'Stage: ' . (int)($row['stage_n'] ?? 0);
		$lines[] = 'Mode: ' . $this->formatIncidentModeLabel((string)($row['mode'] ?? ''));
		$lines[] = 'Rule Repeat Mode: ' . $this->formatRuleRepeatModeLabel(isset($row['rule_repeat_mode_override']) ? (string)$row['rule_repeat_mode_override'] : '');
		$lines[] = 'Effective Repeat Mode: ' . $this->formatRepeatModeLabel((string)($row['repeat_mode'] ?? ''));
		$lines[] = 'Matched Calls: ' . (int)($row['matched_call_count'] ?? 0);
		$lines[] = 'First Matched: ' . (string)($row['first_matched_at'] ?? '-');
		$lines[] = 'Last Matched: ' . (string)($row['last_matched_at'] ?? '-');
		$lines[] = 'Generated At: ' . $now;
		$lines[] = '';
		$lines[] = 'This alert is currently unaccepted. You will receive a notification once it is accepted by phone or through the GUI.';

		return implode("\n", $lines);
	}

	private function formatIncidentModeLabel(string $mode): string {
		return $this->formatAlertTextLabel($mode, [
			'repeat' => 'Repeat',
			'invert' => 'Invert',
		], 'Unknown');
	}

	private function formatRuleRepeatModeLabel(string $repeatModeOverride): string {
		$raw = trim($repeatModeOverride);
		if ($raw === '') {
			return 'Never';
		}

		return $this->formatRepeatModeLabel($raw);
	}

	private function formatRepeatModeLabel(string $mode): string {
		return $this->formatAlertTextLabel($mode, [
			'never' => 'Never',
			'5m' => 'Every 5 Minutes',
			'hourly' => 'Hourly',
			'daily' => 'Daily',
			'escalating' => 'Escalating',
			'fibonacci' => 'Escalating',
		], 'Never');
	}

	private function formatAlertTextLabel(string $value, array $labels, string $defaultLabel): string {
		$normalized = strtolower(trim($value));
		if ($normalized === '') {
			return $defaultLabel;
		}

		if (isset($labels[$normalized])) {
			return (string)$labels[$normalized];
		}

		return ucwords(str_replace(['_', '-'], ' ', $normalized));
	}

	private function resolveRepeatMode(?string $ruleOverride): string {
		$candidate = trim((string)$ruleOverride);
		if ($candidate === '') {
			$candidate = self::REPEAT_MODE_NEVER;
		}
		return $this->normaliseRepeatMode($candidate);
	}

	private function normaliseRepeatMode(string $mode): string {
		$mode = strtolower(trim($mode));
		if ($mode === self::REPEAT_MODE_FIBONACCI) {
			return self::REPEAT_MODE_ESCALATING;
		}
		if (in_array($mode, [self::REPEAT_MODE_NEVER, self::REPEAT_MODE_FIVE_MINUTES, self::REPEAT_MODE_HOURLY, self::REPEAT_MODE_DAILY, self::REPEAT_MODE_ESCALATING], true)) {
			return $mode;
		}
		return self::REPEAT_MODE_NEVER;
	}

	private function nextRepeatDueAt(string $lastAlertAt, string $repeatMode, int $remindersSent): ?string {
		$interval = $this->repeatIntervalSeconds($repeatMode, $remindersSent);
		if ($interval === null) {
			return null;
		}

		$timestamp = strtotime($lastAlertAt);
		if ($timestamp === false) {
			$timestamp = strtotime($this->now());
		}
		return date('Y-m-d H:i:s', $timestamp + $interval);
	}

	private function repeatIntervalSeconds(string $repeatMode, int $remindersSent): ?int {
		switch ($this->normaliseRepeatMode($repeatMode)) {
			case self::REPEAT_MODE_FIVE_MINUTES:
				return 300;
			case self::REPEAT_MODE_HOURLY:
				return 3600;
			case self::REPEAT_MODE_DAILY:
				return 86400;
			case self::REPEAT_MODE_ESCALATING:
				return $this->escalatingRepeatIntervalSeconds($remindersSent);
			case self::REPEAT_MODE_NEVER:
			default:
				return null;
		}
	}

	private function escalatingRepeatIntervalSeconds(int $remindersSent): int {
		$step = max(1, $remindersSent + 1);
		$previous = 0;
		$current = 1;

		for ($i = 1; $i < $step; $i++) {
			$next = $previous + $current;
			$previous = $current;
			$current = $next;
			if ($current * self::REPEAT_ESCALATING_BASE_SECONDS >= self::REPEAT_ESCALATING_CEILING_SECONDS) {
				return self::REPEAT_ESCALATING_CEILING_SECONDS;
			}
		}

		return min(self::REPEAT_ESCALATING_CEILING_SECONDS, $current * self::REPEAT_ESCALATING_BASE_SECONDS);
	}

	private function pruneCutoff(string $policy, string $now): ?string {
		$policy = strtolower(trim($policy));
		if ($policy === '' || $policy === 'never') {
			return null;
		}

		$timestamp = strtotime($now);
		if ($timestamp === false) {
			$timestamp = time();
		}

		switch ($policy) {
			case 'hourly':
				return date('Y-m-d H:i:s', $timestamp - 3600);
			case 'daily':
				return date('Y-m-d H:i:s', $timestamp - 86400);
			case 'weekly':
				return date('Y-m-d H:i:s', strtotime('-1 week', $timestamp));
			case 'monthly':
				return date('Y-m-d H:i:s', strtotime('-1 month', $timestamp));
			case 'yearly':
				return date('Y-m-d H:i:s', strtotime('-1 year', $timestamp));
			default:
				return null;
		}
	}

	private function isGloballySnoozed(array $settings, string $now): bool {
		$until = trim((string)($settings['global_snoozed_until'] ?? ''));
		if ($until === '') {
			return false;
		}

		$untilTs = strtotime($until);
		$nowTs = strtotime($now);
		return $untilTs !== false && $nowTs !== false && $untilTs > $nowTs;
	}

	private function normaliseRecipients(string $raw): array {
		$parts = preg_split('/[,;\s]+/', trim($raw));
		$recipients = [];
		foreach ($parts as $part) {
			$email = trim((string)$part);
			if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
				continue;
			}
			$recipients[strtolower($email)] = $email;
		}
		return array_values($recipients);
	}

	private function isDue(string $dueAt, string $now): bool {
		$dueTs = strtotime($dueAt);
		$nowTs = strtotime($now);
		if ($dueTs === false || $nowTs === false) {
			return false;
		}
		return $dueTs <= $nowTs;
	}

	private function now(): string {
		return (string)call_user_func($this->nowProvider);
	}
}

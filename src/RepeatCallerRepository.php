<?php

declare(strict_types=1);

namespace FreePBX\modules\Repeatcaller;

use PDO;
use PDOException;

final class RepeatCallerRepository {
	private const SEEN_CALL_RETENTION_DAYS = 8;
	private const ALERT_CALL_OUTCOME_ACCEPTED = 'accepted';
	private const ALERT_CALL_OUTCOME_DECLINED = 'declined';
	private const ALERT_CALL_OUTCOME_ANSWERED_NO_RESPONSE = 'answered_no_response';
	private const ALERT_CALL_OUTCOME_NO_ANSWER = 'no_answer';
	private const ALERT_CALL_OUTCOME_BUSY = 'busy';
	private const ALERT_CALL_OUTCOME_UNREACHABLE = 'unreachable';
	private const ALERT_CALL_OUTCOME_CONGESTION = 'congestion';
	private const ALERT_CALL_OUTCOME_FAILED = 'failed';

	private PDO $pdo;

	public function __construct(PDO $pdo) {
		$this->pdo = $pdo;
		$this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
	}

	public function loadEnabledRules(): array {
		$emailEnabledExpr = $this->columnExpr('repeatcaller_rules', 'email_enabled', '0');
		$emailRecipientsExpr = $this->columnExpr('repeatcaller_rules', 'email_recipients', 'NULL');
		$alertCallExpr = $this->columnExpr('repeatcaller_rules', 'alert_call_enabled', '0');
		$alertCallDestinationsExpr = $this->columnExpr('repeatcaller_rules', 'alert_call_destinations', 'NULL');
		$alertCallStrategyExpr = $this->columnExpr('repeatcaller_rules', 'alert_call_strategy', "'ringall'");
		$alertCallKeepTryingExpr = $this->columnExpr('repeatcaller_rules', 'alert_call_keep_trying', '1');
		$alertCallRecordingExpr = $this->columnExpr('repeatcaller_rules', 'alert_call_recording_id', 'NULL');
		$alertCallCallerIdExpr = $this->columnExpr('repeatcaller_rules', 'alert_call_callerid', 'NULL');
		$isDeletedExpr = $this->columnExpr('repeatcaller_rules', 'is_deleted', '0');
		$windowMinutesExpr = $this->columnExpr('repeatcaller_rules', 'observation_window_minutes', '0');
		$stmt = $this->pdo->query(
			'SELECT id, name, enabled, ' . $emailEnabledExpr . ' AS email_enabled, ' . $emailRecipientsExpr . ' AS email_recipients, ' . $alertCallExpr . ' AS alert_call_enabled,
				' . $alertCallDestinationsExpr . ' AS alert_call_destinations, ' . $alertCallRecordingExpr . ' AS alert_call_recording_id,
				' . $alertCallCallerIdExpr . ' AS alert_call_callerid,
				' . $alertCallStrategyExpr . ' AS alert_call_strategy, ' . $alertCallKeepTryingExpr . ' AS alert_call_keep_trying,
				' . $isDeletedExpr . ' AS is_deleted, mode, threshold_count, ' . $windowMinutesExpr . ' AS observation_window_minutes,
				caller_mode, exclude_withheld, did_scope_mode, repeat_mode_override,
				suppression_minutes_override, created_at, updated_at
			 FROM repeatcaller_rules
			 WHERE enabled = 1
				AND ' . $isDeletedExpr . ' = 0
			 ORDER BY id ASC'
		);

		return $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
	}

	public function loadRulesSummary(): array {
		$emailEnabledExpr = $this->columnExpr('repeatcaller_rules', 'email_enabled', '0');
		$emailRecipientsExpr = $this->columnExpr('repeatcaller_rules', 'email_recipients', 'NULL');
		$alertCallExpr = $this->columnExpr('repeatcaller_rules', 'alert_call_enabled', '0');
		$alertCallDestinationsExpr = $this->columnExpr('repeatcaller_rules', 'alert_call_destinations', 'NULL');
		$alertCallStrategyExpr = $this->columnExpr('repeatcaller_rules', 'alert_call_strategy', "'ringall'");
		$alertCallKeepTryingExpr = $this->columnExpr('repeatcaller_rules', 'alert_call_keep_trying', '1');
		$alertCallRecordingExpr = $this->columnExpr('repeatcaller_rules', 'alert_call_recording_id', 'NULL');
		$alertCallCallerIdExpr = $this->columnExpr('repeatcaller_rules', 'alert_call_callerid', 'NULL');
		$isDeletedExpr = $this->columnExpr('repeatcaller_rules', 'is_deleted', '0');
		$windowMinutesExpr = $this->columnExpr('repeatcaller_rules', 'observation_window_minutes', '0');
		$stmt = $this->pdo->query(
			'SELECT r.id, r.name, r.enabled, ' . $emailEnabledExpr . ' AS email_enabled, ' . $emailRecipientsExpr . ' AS email_recipients, ' . $alertCallExpr . ' AS alert_call_enabled,
				' . $alertCallDestinationsExpr . ' AS alert_call_destinations, ' . $alertCallRecordingExpr . ' AS alert_call_recording_id,
				' . $alertCallCallerIdExpr . ' AS alert_call_callerid,
				' . $alertCallStrategyExpr . ' AS alert_call_strategy, ' . $alertCallKeepTryingExpr . ' AS alert_call_keep_trying, r.mode,
				r.threshold_count, r.observation_window_minutes, r.caller_mode,
				r.exclude_withheld, r.did_scope_mode, r.repeat_mode_override,
				r.suppression_minutes_override, r.created_at, r.updated_at,
				(SELECT COUNT(*) FROM repeatcaller_rule_schedules s WHERE s.rule_id = r.id) AS schedule_count,
				(SELECT COUNT(*) FROM repeatcaller_rule_callers c WHERE c.rule_id = r.id AND c.list_type = "include") AS caller_include_count,
				(SELECT COUNT(*) FROM repeatcaller_rule_callers c WHERE c.rule_id = r.id AND c.list_type = "exclude") AS caller_exclude_count,
				(SELECT COUNT(*) FROM repeatcaller_rule_dids d WHERE d.rule_id = r.id AND d.list_type = "include") AS did_include_count,
				(SELECT COUNT(*) FROM repeatcaller_rule_dids d WHERE d.rule_id = r.id AND d.list_type = "exclude") AS did_exclude_count,
				(SELECT COUNT(*) FROM repeatcaller_incidents i WHERE i.rule_id = r.id AND i.state = "active") AS active_incident_count
			 FROM repeatcaller_rules r
			 WHERE ' . $isDeletedExpr . ' = 0
			 ORDER BY r.enabled DESC, r.id ASC'
		);

		$rules = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
		if (!$rules) {
			return [];
		}

		$ruleIds = array_map(function (array $rule): int {
			return (int)$rule['id'];
		}, $rules);
		$callerLists = $this->loadCallerLists($ruleIds);
		$didLists = $this->loadDidLists($ruleIds);
		$latestAssessments = $this->loadLatestRuleAssessments($ruleIds);
		$subjectKeys = [];
		foreach ($latestAssessments as $assessment) {
			$subjectKey = trim((string)($assessment['subject_key'] ?? ''));
			if ($subjectKey !== '') {
				$subjectKeys[] = $subjectKey;
			}
		}
		$peerIncidentsBySubject = $this->loadOpenIncidentsBySubjectKeys($subjectKeys);
		$ruleNamesById = [];
		foreach ($rules as $row) {
			$ruleNamesById[(int)$row['id']] = (string)($row['name'] ?? '');
		}

		foreach ($rules as &$rule) {
			$ruleId = (int)$rule['id'];
			$rule['caller_lists'] = $callerLists[$ruleId] ?? ['include' => [], 'exclude' => []];
			$rule['did_lists'] = $didLists[$ruleId] ?? ['include' => [], 'exclude' => []];

			$assessment = $latestAssessments[$ruleId] ?? null;
			$matchedCallCount = is_array($assessment) ? (int)($assessment['current_window_call_count'] ?? 0) : 0;
			$thresholdReached = is_array($assessment)
				? (!empty($assessment['threshold_met']) || $matchedCallCount >= (int)($rule['threshold_count'] ?? 0))
				: false;
			$activeIncidentId = is_array($assessment) && !empty($assessment['active_incident_id']) ? (int)$assessment['active_incident_id'] : null;
			$suppressionExpiresAt = is_array($assessment) ? trim((string)($assessment['suppression_expires_at'] ?? '')) : '';
			$subjectKey = is_array($assessment) ? trim((string)($assessment['subject_key'] ?? '')) : '';

			$outcome = 'no_recent_activity';
			$handledByRuleId = null;
			$handledByRuleName = null;
			$handledIncidentId = null;

			if ($activeIncidentId !== null && $activeIncidentId > 0) {
				$outcome = 'generated_by_this_rule';
			} elseif ($thresholdReached) {
				if ($suppressionExpiresAt !== '') {
					$outcome = 'suppressed';
				} else {
					$peer = ($subjectKey !== '' && isset($peerIncidentsBySubject[$subjectKey])) ? $peerIncidentsBySubject[$subjectKey] : null;
					if (is_array($peer) && (int)($peer['rule_id'] ?? 0) !== $ruleId) {
						$outcome = 'handled_by_other_rule';
						$handledByRuleId = (int)($peer['rule_id'] ?? 0);
						$handledByRuleName = $ruleNamesById[$handledByRuleId] ?? ('Rule #' . $handledByRuleId);
						$handledIncidentId = (int)($peer['id'] ?? 0);
					} else {
						$outcome = 'threshold_reached';
					}
				}
			} elseif ($matchedCallCount > 0) {
				$outcome = 'threshold_not_reached';
			}

			$rule['status_assessment'] = [
				'subject_key' => $subjectKey !== '' ? $subjectKey : null,
				'matched_call_count' => $matchedCallCount,
				'threshold_reached' => $thresholdReached,
				'active_incident_id' => $activeIncidentId,
				'last_evaluated_at' => is_array($assessment) ? ($assessment['last_evaluated_at'] ?? null) : null,
				'suppression_expires_at' => $suppressionExpiresAt !== '' ? $suppressionExpiresAt : null,
				'outcome' => $outcome,
				'handled_by_rule_id' => $handledByRuleId,
				'handled_by_rule_name' => $handledByRuleName,
				'handled_incident_id' => $handledIncidentId,
			];
		}
		unset($rule);

		return $rules;
	}

	private function loadLatestRuleAssessments(array $ruleIds): array {
		if (!$ruleIds) {
			return [];
		}

		$stmt = $this->pdo->prepare(
			'SELECT id, rule_id, subject_key, current_window_call_count, threshold_met, active_incident_id,
				suppression_expires_at, last_evaluated_at, updated_at
			 FROM repeatcaller_rule_subject_state
			 WHERE rule_id IN (' . $this->placeholders($ruleIds) . ')
			 ORDER BY rule_id ASC, last_evaluated_at DESC, updated_at DESC, id DESC'
		);
		$stmt->execute(array_values($ruleIds));

		$latestByRule = [];
		foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
			$ruleId = (int)($row['rule_id'] ?? 0);
			if ($ruleId <= 0 || isset($latestByRule[$ruleId])) {
				continue;
			}
			$latestByRule[$ruleId] = $row;
		}

		return $latestByRule;
	}

	private function loadOpenIncidentsBySubjectKeys(array $subjectKeys): array {
		$subjectKeys = array_values(array_unique(array_filter(array_map('strval', $subjectKeys), function (string $value): bool {
			return trim($value) !== '';
		})));
		if (!$subjectKeys) {
			return [];
		}

		$stmt = $this->pdo->prepare(
			'SELECT id, rule_id, subject_key, state, created_at, updated_at
			 FROM repeatcaller_incidents
			 WHERE subject_key IN (' . $this->placeholders($subjectKeys) . ')
			   AND state IN (?, ?)
			 ORDER BY subject_key ASC, created_at DESC, id DESC'
		);
		$params = array_merge($subjectKeys, ['active', 'claimed']);
		$stmt->execute($params);

		$bySubject = [];
		foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
			$subjectKey = trim((string)($row['subject_key'] ?? ''));
			if ($subjectKey === '' || isset($bySubject[$subjectKey])) {
				continue;
			}
			$bySubject[$subjectKey] = $row;
		}

		return $bySubject;
	}

	public function loadRule(int $ruleId): ?array {
		$emailEnabledExpr = $this->columnExpr('repeatcaller_rules', 'email_enabled', '0');
		$emailRecipientsExpr = $this->columnExpr('repeatcaller_rules', 'email_recipients', 'NULL');
		$alertCallExpr = $this->columnExpr('repeatcaller_rules', 'alert_call_enabled', '0');
		$alertCallDestinationsExpr = $this->columnExpr('repeatcaller_rules', 'alert_call_destinations', 'NULL');
		$alertCallStrategyExpr = $this->columnExpr('repeatcaller_rules', 'alert_call_strategy', "'ringall'");
		$alertCallKeepTryingExpr = $this->columnExpr('repeatcaller_rules', 'alert_call_keep_trying', '1');
		$alertCallRecordingExpr = $this->columnExpr('repeatcaller_rules', 'alert_call_recording_id', 'NULL');
		$alertCallCallerIdExpr = $this->columnExpr('repeatcaller_rules', 'alert_call_callerid', 'NULL');
		$isDeletedExpr = $this->columnExpr('repeatcaller_rules', 'is_deleted', '0');
		$deletedAtExpr = $this->columnExpr('repeatcaller_rules', 'deleted_at', 'NULL');
		$stmt = $this->pdo->prepare(
			'SELECT id, name, enabled, ' . $emailEnabledExpr . ' AS email_enabled, ' . $emailRecipientsExpr . ' AS email_recipients, ' . $alertCallExpr . ' AS alert_call_enabled,
				' . $alertCallDestinationsExpr . ' AS alert_call_destinations, ' . $alertCallRecordingExpr . ' AS alert_call_recording_id,
				' . $alertCallCallerIdExpr . ' AS alert_call_callerid,
				' . $alertCallStrategyExpr . ' AS alert_call_strategy, ' . $alertCallKeepTryingExpr . ' AS alert_call_keep_trying,
				' . $isDeletedExpr . ' AS is_deleted, mode,
				threshold_count, observation_window_minutes, caller_mode, exclude_withheld,
				did_scope_mode, repeat_mode_override, suppression_minutes_override,
				' . $deletedAtExpr . ' AS deleted_at, created_at, updated_at
			 FROM repeatcaller_rules
			 WHERE id = ? AND ' . $isDeletedExpr . ' = 0
			 LIMIT 1'
		);
		$stmt->execute([$ruleId]);
		$rule = $stmt->fetch(PDO::FETCH_ASSOC);
		if (!is_array($rule)) {
			return null;
		}

		$rule['schedules'] = $this->loadSchedules([$ruleId])[$ruleId] ?? [];
		$rule['caller_lists'] = $this->loadCallerLists([$ruleId])[$ruleId] ?? ['include' => [], 'exclude' => []];
		$rule['did_lists'] = $this->loadDidLists([$ruleId])[$ruleId] ?? ['include' => [], 'exclude' => []];

		return $rule;
	}

	public function saveRule(array $payload, string $now): int {
		$ruleId = isset($payload['id']) && (int)$payload['id'] > 0 ? (int)$payload['id'] : 0;
		$hasEmailEnabled = $this->hasColumn('repeatcaller_rules', 'email_enabled');
		$hasEmailRecipients = $this->hasColumn('repeatcaller_rules', 'email_recipients');
		$hasAlertCall = $this->hasColumn('repeatcaller_rules', 'alert_call_enabled');
		$hasAlertCallDestinations = $this->hasColumn('repeatcaller_rules', 'alert_call_destinations');
		$hasAlertCallStrategy = $this->hasColumn('repeatcaller_rules', 'alert_call_strategy');
		$hasAlertCallKeepTrying = $this->hasColumn('repeatcaller_rules', 'alert_call_keep_trying');
		$hasAlertCallRecording = $this->hasColumn('repeatcaller_rules', 'alert_call_recording_id');
		$hasAlertCallCallerId = $this->hasColumn('repeatcaller_rules', 'alert_call_callerid');
		$hasIsDeleted = $this->hasColumn('repeatcaller_rules', 'is_deleted');
		$hasDeletedAt = $this->hasColumn('repeatcaller_rules', 'deleted_at');
		$alertCallStrategy = $this->normaliseAlertCallStrategy((string)($payload['alert_call_strategy'] ?? 'ringall'));
		$alertCallKeepTrying = $this->alertCallKeepTryingFlag($payload, true);
		if ($ruleId > 0) {
			$set = [
				'name = ?',
				'enabled = ?',
				'mode = ?',
				'threshold_count = ?',
				'observation_window_minutes = ?',
				'caller_mode = ?',
				'exclude_withheld = ?',
				'did_scope_mode = ?',
				'repeat_mode_override = ?',
				'suppression_minutes_override = ?',
				'updated_at = ?',
			];
			$params = [
				(string)$payload['name'],
				!empty($payload['enabled']) ? 1 : 0,
				(string)$payload['mode'],
				(int)$payload['threshold_count'],
				(int)$payload['observation_window_minutes'],
				(string)$payload['caller_mode'],
				!empty($payload['exclude_withheld']) ? 1 : 0,
				(string)$payload['did_scope_mode'],
				$this->nullableString($payload['repeat_mode_override'] ?? null),
				$this->nullableInt($payload['suppression_minutes_override'] ?? null),
				$now,
			];
			if ($hasEmailEnabled) {
				$set[] = 'email_enabled = ?';
				$params[] = !empty($payload['email_enabled']) ? 1 : 0;
			}
			if ($hasEmailRecipients) {
				$set[] = 'email_recipients = ?';
				$params[] = $this->nullableString($payload['email_recipients'] ?? null);
			}
			if ($hasAlertCall) {
				$set[] = 'alert_call_enabled = ?';
				$params[] = !empty($payload['alert_call_enabled']) ? 1 : 0;
			}
			if ($hasAlertCallDestinations) {
				$set[] = 'alert_call_destinations = ?';
				$params[] = $this->nullableString($payload['alert_call_destinations'] ?? null);
			}
			if ($hasAlertCallStrategy) {
				$set[] = 'alert_call_strategy = ?';
				$params[] = $alertCallStrategy;
			}
			if ($hasAlertCallKeepTrying) {
				$set[] = 'alert_call_keep_trying = ?';
				$params[] = $alertCallKeepTrying;
			}
			if ($hasAlertCallRecording) {
				$set[] = 'alert_call_recording_id = ?';
				$params[] = $this->nullableInt($payload['alert_call_recording_id'] ?? null);
			}
			if ($hasAlertCallCallerId) {
				$set[] = 'alert_call_callerid = ?';
				$params[] = $this->nullableString($payload['alert_call_callerid'] ?? null);
			}
			if ($hasIsDeleted) {
				$set[] = 'is_deleted = 0';
			}
			if ($hasDeletedAt) {
				$set[] = 'deleted_at = NULL';
			}
			$params[] = $ruleId;
			$stmt = $this->pdo->prepare('UPDATE repeatcaller_rules SET ' . implode(', ', $set) . ' WHERE id = ?');
			$stmt->execute($params);
		} else {
			$columns = [
				'name', 'enabled', 'mode', 'threshold_count', 'observation_window_minutes',
				'caller_mode', 'exclude_withheld', 'did_scope_mode', 'repeat_mode_override',
				'suppression_minutes_override', 'created_at', 'updated_at',
			];
			$values = [
				(string)$payload['name'],
				!empty($payload['enabled']) ? 1 : 0,
				(string)$payload['mode'],
				(int)$payload['threshold_count'],
				(int)$payload['observation_window_minutes'],
				(string)$payload['caller_mode'],
				!empty($payload['exclude_withheld']) ? 1 : 0,
				(string)$payload['did_scope_mode'],
				$this->nullableString($payload['repeat_mode_override'] ?? null),
				$this->nullableInt($payload['suppression_minutes_override'] ?? null),
				$now,
				$now,
			];
			if ($hasEmailEnabled) {
				$columns[] = 'email_enabled';
				$values[] = !empty($payload['email_enabled']) ? 1 : 0;
			}
			if ($hasEmailRecipients) {
				$columns[] = 'email_recipients';
				$values[] = $this->nullableString($payload['email_recipients'] ?? null);
			}
			if ($hasAlertCall) {
				$columns[] = 'alert_call_enabled';
				$values[] = !empty($payload['alert_call_enabled']) ? 1 : 0;
			}
			if ($hasAlertCallDestinations) {
				$columns[] = 'alert_call_destinations';
				$values[] = $this->nullableString($payload['alert_call_destinations'] ?? null);
			}
			if ($hasAlertCallStrategy) {
				$columns[] = 'alert_call_strategy';
				$values[] = $alertCallStrategy;
			}
			if ($hasAlertCallKeepTrying) {
				$columns[] = 'alert_call_keep_trying';
				$values[] = $alertCallKeepTrying;
			}
			if ($hasAlertCallRecording) {
				$columns[] = 'alert_call_recording_id';
				$values[] = $this->nullableInt($payload['alert_call_recording_id'] ?? null);
			}
			if ($hasAlertCallCallerId) {
				$columns[] = 'alert_call_callerid';
				$values[] = $this->nullableString($payload['alert_call_callerid'] ?? null);
			}
			if ($hasIsDeleted) {
				$columns[] = 'is_deleted';
				$values[] = 0;
			}
			if ($hasDeletedAt) {
				$columns[] = 'deleted_at';
				$values[] = null;
			}
			$placeholders = implode(', ', array_fill(0, count($columns), '?'));
			$stmt = $this->pdo->prepare('INSERT INTO repeatcaller_rules (' . implode(', ', $columns) . ') VALUES (' . $placeholders . ')');
			$stmt->execute($values);
			$ruleId = (int)$this->pdo->lastInsertId();
		}

		$this->replaceRuleSchedules($ruleId, self::normalizeSchedules($payload['schedules'] ?? []), $now);
		$this->replaceRuleCallers($ruleId, $payload['callers'] ?? [], $now);
		$this->replaceRuleDids($ruleId, $payload['dids'] ?? [], $now);

		return $ruleId;
	}

	public function setRuleEnabled(int $ruleId, bool $enabled, string $now): void {
		$isDeletedExpr = $this->columnExpr('repeatcaller_rules', 'is_deleted', '0');
		$stmt = $this->pdo->prepare('UPDATE repeatcaller_rules SET enabled = ?, updated_at = ? WHERE id = ? AND ' . $isDeletedExpr . ' = 0');
		$stmt->execute([$enabled ? 1 : 0, $now, $ruleId]);
	}

	public function softDeleteRule(int $ruleId, string $now): void {
		$hasIsDeleted = $this->hasColumn('repeatcaller_rules', 'is_deleted');
		$hasDeletedAt = $this->hasColumn('repeatcaller_rules', 'deleted_at');
		$set = ['enabled = 0', 'updated_at = ?'];
		$params = [$now];
		if ($hasIsDeleted) {
			$set[] = 'is_deleted = 1';
		}
		if ($hasDeletedAt) {
			$set[] = 'deleted_at = ?';
			$params[] = $now;
		}
		$params[] = $ruleId;
		$stmt = $this->pdo->prepare('UPDATE repeatcaller_rules SET ' . implode(', ', $set) . ' WHERE id = ?');
		$stmt->execute($params);

		$closeIncidents = $this->pdo->prepare(
			'UPDATE repeatcaller_incidents
			 SET state = ?,
				 active_subject_key = NULL,
				 cleared_at = COALESCE(cleared_at, ?),
				 updated_at = ?
			 WHERE rule_id = ?
				AND state IN (?, ?)' 
		);
		$closeIncidents->execute(['closed', $now, $now, $ruleId, 'active', 'claimed']);

		$clearState = $this->pdo->prepare(
			'UPDATE repeatcaller_rule_subject_state
			 SET active_incident_id = NULL,
				 threshold_met = 0,
				 clear_observed_since_trigger = 1,
				 updated_at = ?
			 WHERE rule_id = ?'
		);
		$clearState->execute([$now, $ruleId]);
	}

	public function loadInboundRoutes(): array {
		$stmt = $this->pdo->query(
			'SELECT extension, cidnum, description
			 FROM incoming
			 ORDER BY description ASC, extension ASC, cidnum ASC'
		);
		if (!$stmt) {
			return [];
		}
		$rows = [];
		foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
			$did = trim((string)($row['extension'] ?? ''));
			$cid = trim((string)($row['cidnum'] ?? ''));
			$desc = trim((string)($row['description'] ?? ''));
			$routeKey = $did . '|' . $cid;
			$label = $desc !== '' ? $desc : ($did !== '' ? $did : 'Catch-all');
			$rows[] = [
				'route_key' => $routeKey,
				'route_label' => $label,
				'did_value' => $did,
				'cid_value' => $cid,
				'is_catch_all' => $did === '' && $cid === '',
			];
		}

		return $rows;
	}

	public function loadIncidents(string $view = 'active', int $limit = 200): array {
		if ($view === 'active') {
			$states = ['active'];
		} elseif ($view === 'claimed') {
			$states = ['claimed'];
		} else {
			$states = ['active', 'claimed', 'suppressed', 'expired', 'closed'];
		}
		$placeholders = $this->placeholders($states);
		$stmt = $this->pdo->prepare(
			'SELECT i.id, i.rule_id, i.subject_key, i.subject_label, i.caller_normalized, i.caller_display,
				i.withheld_caller, i.mode, i.threshold_count, i.observation_window_minutes, i.first_matched_at, i.last_matched_at, i.matched_call_count,
				i.state, i.claimed_by, i.claimed_at, i.claim_source, i.suppression_expires_at,
				i.cleared_at, i.created_at, i.updated_at,
				r.name AS rule_name,
				r.caller_mode, r.did_scope_mode
			 FROM repeatcaller_incidents i
			 JOIN repeatcaller_rules r ON r.id = i.rule_id
			 WHERE i.state IN (' . $placeholders . ')
			 ORDER BY i.updated_at DESC, i.id DESC
			 LIMIT ' . (int)$limit
		);
		$stmt->execute($states);

		return $stmt->fetchAll(PDO::FETCH_ASSOC);
	}

	public function claimActiveIncident(int $incidentId, string $claimedBy, string $claimedAt, string $claimSource = 'gui'): bool {
		$select = $this->pdo->prepare('SELECT id, rule_id, subject_key FROM repeatcaller_incidents WHERE id = ? AND state = ? LIMIT 1');
		$select->execute([$incidentId, 'active']);
		$row = $select->fetch(PDO::FETCH_ASSOC);
		if (!is_array($row)) {
			return false;
		}

		$update = $this->pdo->prepare(
			'UPDATE repeatcaller_incidents
			 SET state = ?, claimed_by = ?, claimed_at = ?, claim_source = ?, updated_at = ?
			 WHERE id = ? AND state = ?'
		);
		$update->execute(['claimed', $claimedBy, $claimedAt, $claimSource, $claimedAt, $incidentId, 'active']);
		$verify = $this->pdo->prepare('SELECT state FROM repeatcaller_incidents WHERE id = ? LIMIT 1');
		$verify->execute([$incidentId]);
		if ((string)$verify->fetchColumn() !== 'claimed') {
			return false;
		}

		$state = $this->loadSubjectState((int)$row['rule_id'], (string)$row['subject_key']) ?? [];
		$state['active_incident_id'] = $incidentId;
		$state['last_evaluated_at'] = $claimedAt;
		$this->saveSubjectState((int)$row['rule_id'], (string)$row['subject_key'], $state);

		return true;
	}

	public function loadIncidentAlertHistory(int $limit = 200): array {
		$stmt = $this->pdo->prepare(
			'SELECT h.id, h.incident_id, h.rule_id, h.subject_key, h.subject_label,
				h.action_type, h.event_type, h.stage_n, h.recipient, h.delivery_status,
				h.attempted_at, h.successful_at, h.failure_detail, h.repeat_mode,
				h.created_at, h.updated_at,
				i.mode AS incident_mode,
				i.threshold_count AS incident_threshold_count,
				i.observation_window_minutes AS incident_observation_window_minutes,
				i.caller_normalized AS incident_caller_normalized,
				i.caller_display AS incident_caller_display,
				r.name AS rule_name,
				r.caller_mode,
				r.did_scope_mode
			 FROM repeatcaller_incident_alert_history h
			 LEFT JOIN repeatcaller_incidents i ON i.id = h.incident_id
			 LEFT JOIN repeatcaller_rules r ON r.id = h.rule_id
			 ORDER BY h.created_at DESC, h.id DESC
			 LIMIT ' . (int)$limit
		);
		$stmt->execute();

		return $stmt->fetchAll(PDO::FETCH_ASSOC);
	}

	public function loadSuppressedIncidentHistory(int $limit = 200): array {
		$stmt = $this->pdo->prepare(
			'SELECT h.id, h.related_incident_id, h.rule_id, h.rule_name, h.mode, h.subject_key, h.subject_label,
				h.caller_normalized, h.caller_display, h.inbound_route_key, h.inbound_route_label, h.did_value,
				h.matched_call_count, h.threshold_count, h.observation_window_minutes, h.suppression_source,
				h.suppression_minutes, h.suppression_started_at, h.suppression_expires_at, h.cleared_at,
				h.related_incident_state, h.detected_at, h.created_at, h.updated_at,
				r.caller_mode, r.did_scope_mode
			 FROM repeatcaller_incident_suppression_history h
			 LEFT JOIN repeatcaller_rules r ON r.id = h.rule_id
			 ORDER BY h.created_at DESC, h.id DESC
			 LIMIT ' . (int)$limit
		);
		$stmt->execute();

		return $stmt->fetchAll(PDO::FETCH_ASSOC);
	}

	public function loadActiveSuppressedIncidents(string $asOf, int $limit = 200): array {
		$stmt = $this->pdo->prepare(
			'SELECT h.id, h.related_incident_id, h.rule_id, h.rule_name, h.mode, h.subject_key, h.subject_label,
				h.caller_normalized, h.caller_display, h.inbound_route_key, h.inbound_route_label, h.did_value,
				h.matched_call_count, h.threshold_count, h.observation_window_minutes, h.suppression_source,
				h.suppression_minutes, h.suppression_started_at, h.suppression_expires_at, h.cleared_at,
				h.related_incident_state, h.detected_at, h.created_at, h.updated_at,
				r.caller_mode, r.did_scope_mode
			 FROM repeatcaller_incident_suppression_history h
			 LEFT JOIN repeatcaller_rules r ON r.id = h.rule_id
			 WHERE (h.cleared_at IS NULL OR h.cleared_at IN (\'\', \'0000-00-00\', \'0000-00-00 00:00:00\'))
			   AND h.suppression_expires_at > ?
			 ORDER BY h.created_at DESC, h.id DESC
			 LIMIT ' . (int)$limit
		);
		$stmt->execute([$asOf]);

		return $stmt->fetchAll(PDO::FETCH_ASSOC);
	}

	public function loadUiChangeTokens(): array {
		$incidentActive = $this->aggregateStateSnapshot('repeatcaller_incidents', "state = 'active'");
		$incidentClaimed = $this->aggregateStateSnapshot('repeatcaller_incidents', "state = 'claimed'");
		$alertHistory = $this->aggregateStateSnapshot('repeatcaller_incident_alert_history', '1 = 1');
		$suppressionHistory = $this->aggregateStateSnapshot('repeatcaller_incident_suppression_history', '1 = 1');

		$isDeletedExpr = $this->columnExpr('repeatcaller_rules', 'is_deleted', '0');
		$enabledRules = $this->aggregateStateSnapshot('repeatcaller_rules', 'enabled = 1 AND ' . $isDeletedExpr . ' = 0');

		$settingsHash = '';
		$settingRows = $this->selectRowsSafe(
			'SELECT setting_key, setting_value
			 FROM repeatcaller_settings
			 WHERE setting_key IN (?, ?, ?, ?, ?)
			 ORDER BY setting_key ASC',
			['enabled', 'global_snoozed_until', 'global_snooze_selected_seconds', 'engine_last_success_at', 'engine_last_summary_json']
		);
		if ($settingRows !== null) {
			$pairs = [];
			foreach ($settingRows as $row) {
				$key = (string)($row['setting_key'] ?? '');
				$value = (string)($row['setting_value'] ?? '');
				if ($key !== '') {
					$pairs[] = $key . '=' . $value;
				}
			}
			$settingsHash = hash('sha256', implode('|', $pairs));
		}

		$activeSnapshot = [
			'v' => '1',
			'active' => $incidentActive,
		];
		$claimedSnapshot = [
			'v' => '1',
			'claimed' => $incidentClaimed,
		];
		$alertSnapshot = [
			'v' => '1',
			'alerts' => $alertHistory,
		];
		$suppressionSnapshot = [
			'v' => '1',
			'suppression_history' => $suppressionHistory,
		];
		$engineSnapshot = [
			'v' => '1',
			'enabled_rules' => $enabledRules,
			'settings_hash' => $settingsHash,
		];

		return [
			'activeIncidents' => hash('sha256', json_encode($activeSnapshot)),
			'claimedIncidents' => hash('sha256', json_encode($claimedSnapshot)),
			'alertHistory' => hash('sha256', json_encode($alertSnapshot)),
			'suppressedIncidents' => hash('sha256', json_encode($suppressionSnapshot)),
			'engineStatus' => hash('sha256', json_encode($engineSnapshot)),
		];
	}

	public function loadSchedules(array $ruleIds): array {
		if (!$ruleIds) {
			return [];
		}

		$stmt = $this->pdo->prepare(
			'SELECT rule_id, day_of_week, start_time, end_time
			 FROM repeatcaller_rule_schedules
			 WHERE rule_id IN (' . $this->placeholders($ruleIds) . ')
			 ORDER BY rule_id ASC, day_of_week ASC, start_time ASC, id ASC'
		);
		$stmt->execute(array_values($ruleIds));

		$grouped = [];
		foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
			$ruleId = (int)$row['rule_id'];
			$start = substr((string)$row['start_time'], 0, 5);
			$end = substr((string)$row['end_time'], 0, 5);
			$allDay = ($start === '00:00' && $end === '24:00') ? 1 : 0;
			$grouped[$ruleId][] = [
				'day' => (int)$row['day_of_week'],
				'start' => $start,
				'end' => $allDay ? '' : $end,
				'all_day' => $allDay,
			];
		}

		return $grouped;
	}

	public function loadCallerLists(array $ruleIds): array {
		if (!$ruleIds) {
			return [];
		}

		$stmt = $this->pdo->prepare(
			'SELECT rule_id, list_type, raw_value, normalized_value
			 FROM repeatcaller_rule_callers
			 WHERE rule_id IN (' . $this->placeholders($ruleIds) . ')
			 ORDER BY rule_id ASC, list_type ASC, id ASC'
		);
		$stmt->execute(array_values($ruleIds));

		$grouped = [];
		foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
			$ruleId = (int)$row['rule_id'];
			$listType = (string)$row['list_type'];
			if (!isset($grouped[$ruleId])) {
				$grouped[$ruleId] = ['include' => [], 'exclude' => []];
			}
			$grouped[$ruleId][$listType][] = [
				'raw_value' => (string)$row['raw_value'],
				'normalized_value' => (string)$row['normalized_value'],
			];
		}

		return $grouped;
	}

	public function loadDidLists(array $ruleIds): array {
		if (!$ruleIds) {
			return [];
		}

		$stmt = $this->pdo->prepare(
			'SELECT rule_id, list_type, route_key, route_label, did_value, cid_value
			 FROM repeatcaller_rule_dids
			 WHERE rule_id IN (' . $this->placeholders($ruleIds) . ')
			 ORDER BY rule_id ASC, list_type ASC, id ASC'
		);
		$stmt->execute(array_values($ruleIds));

		$grouped = [];
		foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
			$ruleId = (int)$row['rule_id'];
			$listType = (string)$row['list_type'];
			if (!isset($grouped[$ruleId])) {
				$grouped[$ruleId] = ['include' => [], 'exclude' => []];
			}
			$grouped[$ruleId][$listType][] = [
				'route_key' => (string)$row['route_key'],
				'route_label' => (string)$row['route_label'],
				'did_value' => $row['did_value'] !== null ? (string)$row['did_value'] : null,
				'cid_value' => $row['cid_value'] !== null ? (string)$row['cid_value'] : null,
			];
		}

		return $grouped;
	}

	public function reserveSeenCallJourney(array $journey): bool {
		$stmt = $this->pdo->prepare('SELECT COUNT(*) FROM repeatcaller_seen_calls WHERE call_identity = ?');
		$stmt->execute([(string)$journey['call_identity']]);
		if ((int)$stmt->fetchColumn() > 0) {
			return false;
		}

		$insert = $this->pdo->prepare(
			'INSERT INTO repeatcaller_seen_calls
				(call_identity, identity_type, fingerprint, linkedid, uniqueid, caller_raw, caller_normalized,
				 inbound_route_key, did_value, call_started_at, call_completed_at, disposition, source_context, processed_at)
			 VALUES
				(?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
		);
		$insert->execute([
			(string)$journey['call_identity'],
			(string)$journey['identity_type'],
			(string)$journey['fingerprint'],
			$this->nullableString($journey['linkedid'] ?? null),
			$this->nullableString($journey['uniqueid'] ?? null),
			$this->nullableString($journey['caller_raw'] ?? null),
			$this->nullableString($journey['caller_normalized'] ?? null),
			$this->nullableString($journey['inbound_route_key'] ?? null),
			$this->nullableString($journey['did_value'] ?? null),
			(string)$journey['call_started_at'],
			$this->nullableString($journey['call_completed_at'] ?? null),
			$this->nullableString($journey['disposition'] ?? null),
			$this->nullableString($journey['source_context'] ?? null),
			(string)$journey['processed_at'],
		]);

		return true;
	}

	public function loadRecentSeenCalls(string $callerNormalized, string $windowStartedAt, string $windowEndedAt): array {
		$stmt = $this->pdo->prepare(
			'SELECT id, call_identity, identity_type, fingerprint, linkedid, uniqueid, caller_raw, caller_normalized,
				inbound_route_key, did_value, call_started_at, call_completed_at, disposition, source_context, processed_at
			 FROM repeatcaller_seen_calls
			 WHERE caller_normalized = ?
				AND call_completed_at IS NOT NULL
				AND call_completed_at >= ?
				AND call_completed_at <= ?
			 ORDER BY call_completed_at ASC, id ASC'
		);
		$stmt->execute([$callerNormalized, $windowStartedAt, $windowEndedAt]);

		return $stmt->fetchAll(PDO::FETCH_ASSOC);
	}

	public function loadRecentSeenCallsInWindow(string $windowStartedAt, string $windowEndedAt): array {
		$stmt = $this->pdo->prepare(
			'SELECT id, call_identity, identity_type, fingerprint, linkedid, uniqueid, caller_raw, caller_normalized,
				inbound_route_key, did_value, call_started_at, call_completed_at, disposition, source_context, processed_at
			 FROM repeatcaller_seen_calls
			 WHERE call_completed_at IS NOT NULL
				AND call_completed_at >= ?
				AND call_completed_at <= ?
			 ORDER BY call_completed_at ASC, id ASC'
		);
		$stmt->execute([$windowStartedAt, $windowEndedAt]);

		return $stmt->fetchAll(PDO::FETCH_ASSOC);
	}

	public function loadSubjectState(int $ruleId, string $subjectKey): ?array {
		$stmt = $this->pdo->prepare(
			'SELECT id, rule_id, subject_key, current_window_started_at, current_window_ends_at,
				current_window_call_count, threshold_met, clear_observed_since_trigger,
				active_incident_id, suppression_expires_at, last_call_at, last_evaluated_at, created_at, updated_at
			 FROM repeatcaller_rule_subject_state
			 WHERE rule_id = ? AND subject_key = ?
			 LIMIT 1'
		);
		$stmt->execute([$ruleId, $subjectKey]);
		$row = $stmt->fetch(PDO::FETCH_ASSOC);

		return is_array($row) ? $row : null;
	}

	public function saveSubjectState(int $ruleId, string $subjectKey, array $state): void {
		$existing = $this->loadSubjectState($ruleId, $subjectKey);
		$now = (string)($state['updated_at'] ?? date('Y-m-d H:i:s'));

		if ($existing) {
			$stmt = $this->pdo->prepare(
				'UPDATE repeatcaller_rule_subject_state
				 SET current_window_started_at = ?,
					 current_window_ends_at = ?,
					 current_window_call_count = ?,
					 threshold_met = ?,
					 clear_observed_since_trigger = ?,
					 active_incident_id = ?,
					 suppression_expires_at = ?,
					 last_call_at = ?,
					 last_evaluated_at = ?,
					 updated_at = ?
				 WHERE rule_id = ? AND subject_key = ?'
			);
			$stmt->execute([
				$this->nullableString($state['current_window_started_at'] ?? null),
				$this->nullableString($state['current_window_ends_at'] ?? null),
				(int)($state['current_window_call_count'] ?? 0),
				!empty($state['threshold_met']) ? 1 : 0,
				!empty($state['clear_observed_since_trigger']) ? 1 : 0,
				$this->nullableInt($state['active_incident_id'] ?? null),
				$this->nullableString($state['suppression_expires_at'] ?? null),
				$this->nullableString($state['last_call_at'] ?? null),
				$this->nullableString($state['last_evaluated_at'] ?? null),
				$now,
				$ruleId,
				$subjectKey,
			]);
			return;
		}

		$stmt = $this->pdo->prepare(
			'INSERT INTO repeatcaller_rule_subject_state
				(rule_id, subject_key, current_window_started_at, current_window_ends_at,
				 current_window_call_count, threshold_met, clear_observed_since_trigger,
				 active_incident_id, suppression_expires_at, last_call_at, last_evaluated_at, created_at, updated_at)
			 VALUES
				(?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
		);
		$stmt->execute([
			$ruleId,
			$subjectKey,
			$this->nullableString($state['current_window_started_at'] ?? null),
			$this->nullableString($state['current_window_ends_at'] ?? null),
			(int)($state['current_window_call_count'] ?? 0),
			!empty($state['threshold_met']) ? 1 : 0,
			!empty($state['clear_observed_since_trigger']) ? 1 : 0,
			$this->nullableInt($state['active_incident_id'] ?? null),
			$this->nullableString($state['suppression_expires_at'] ?? null),
			$this->nullableString($state['last_call_at'] ?? null),
			$this->nullableString($state['last_evaluated_at'] ?? null),
			(string)($state['created_at'] ?? $now),
			$now,
		]);
	}

	public function adoptLegacyRepeatIdentity(int $ruleId, string $legacySubjectKey, string $routeSubjectKey, string $routeSubjectLabel, string $updatedAt): bool {
		if ($legacySubjectKey === '' || $routeSubjectKey === '' || $legacySubjectKey === $routeSubjectKey) {
			return false;
		}

		if ($this->loadSubjectState($ruleId, $routeSubjectKey) !== null) {
			return false;
		}
		if ($this->loadTrackedIncident($ruleId, $routeSubjectKey) !== null) {
			return false;
		}

		if (!$this->pdo->inTransaction()) {
			$this->pdo->beginTransaction();
			$ownsTransaction = true;
		} else {
			$ownsTransaction = false;
		}

		try {
			$legacyActive = $this->pdo->prepare(
				'SELECT id
				 FROM repeatcaller_incidents
				 WHERE rule_id = ? AND subject_key = ? AND state = ?
				 LIMIT 1'
			);
			$legacyActive->execute([$ruleId, $legacySubjectKey, 'active']);
			$legacyActiveId = (int)($legacyActive->fetchColumn() ?: 0);
			if ($legacyActiveId <= 0) {
				if ($ownsTransaction) {
					$this->pdo->commit();
				}
				return false;
			}

			$legacyState = $this->loadSubjectState($ruleId, $legacySubjectKey);
			if (!is_array($legacyState)) {
				if ($ownsTransaction) {
					$this->pdo->commit();
				}
				return false;
			}

			if ($this->loadSubjectState($ruleId, $routeSubjectKey) !== null) {
				if ($ownsTransaction) {
					$this->pdo->commit();
				}
				return false;
			}
			if ($this->loadTrackedIncident($ruleId, $routeSubjectKey) !== null) {
				if ($ownsTransaction) {
					$this->pdo->commit();
				}
				return false;
			}

			$moveState = $this->pdo->prepare(
				'UPDATE repeatcaller_rule_subject_state
				 SET subject_key = ?, updated_at = ?
				 WHERE rule_id = ? AND subject_key = ?'
			);
			$moveState->execute([$routeSubjectKey, $updatedAt, $ruleId, $legacySubjectKey]);

			if ($moveState->rowCount() === 0) {
				if ($ownsTransaction) {
					$this->pdo->commit();
				}
				return false;
			}

			$moveIncident = $this->pdo->prepare(
				'UPDATE repeatcaller_incidents
				 SET subject_key = ?,
					 active_subject_key = ?,
					 subject_label = ?,
					 updated_at = ?
				 WHERE id = ? AND state = ? AND subject_key = ?'
			);
			$moveIncident->execute([
				$routeSubjectKey,
				$this->activeSubjectKey($ruleId, $routeSubjectKey),
				$routeSubjectLabel,
				$updatedAt,
				$legacyActiveId,
				'active',
				$legacySubjectKey,
			]);
			if ($moveIncident->rowCount() === 0) {
				if ($ownsTransaction) {
					$this->pdo->rollBack();
				}
				return false;
			}

			if ($ownsTransaction) {
				$this->pdo->commit();
			}
			return true;
		} catch (PDOException $e) {
			if ($ownsTransaction && $this->pdo->inTransaction()) {
				$this->pdo->rollBack();
			}
			if ($this->isUniqueViolation($e)) {
				return false;
			}
			throw $e;
		} catch (Throwable $e) {
			if ($ownsTransaction && $this->pdo->inTransaction()) {
				$this->pdo->rollBack();
			}
			throw $e;
		}
	}

	public function loadActiveIncident(int $ruleId, string $subjectKey): ?array {
		return $this->loadOpenIncident($ruleId, $subjectKey, ['active']);
	}

	public function loadTrackedIncident(int $ruleId, string $subjectKey): ?array {
		return $this->loadOpenIncident($ruleId, $subjectKey, ['active', 'claimed']);
	}

	public function loadMostRecentIncidentForSubject(int $ruleId, string $subjectKey): ?array {
		$stmt = $this->pdo->prepare(
			'SELECT id, rule_id, subject_key, active_subject_key, subject_label, caller_normalized, caller_display,
				withheld_caller, mode, threshold_count, observation_window_minutes, first_matched_at, last_matched_at, matched_call_count, state,
				claimed_by, claimed_at, claim_source, suppression_expires_at, cleared_at, created_at, updated_at
			 FROM repeatcaller_incidents
			 WHERE rule_id = ? AND subject_key = ?
			 ORDER BY created_at DESC, id DESC
			 LIMIT 1'
		);
		$stmt->execute([$ruleId, $subjectKey]);
		$row = $stmt->fetch(PDO::FETCH_ASSOC);

		return is_array($row) ? $row : null;
	}

	private function loadOpenIncident(int $ruleId, string $subjectKey, array $states): ?array {
		$placeholders = $this->placeholders($states);
		$stmt = $this->pdo->prepare(
			'SELECT id, rule_id, subject_key, active_subject_key, subject_label, caller_normalized, caller_display,
				withheld_caller, mode, threshold_count, observation_window_minutes, first_matched_at, last_matched_at, matched_call_count, state,
				claimed_by, claimed_at, claim_source, suppression_expires_at, cleared_at, created_at, updated_at
			 FROM repeatcaller_incidents
			 WHERE rule_id = ? AND subject_key = ? AND state IN (' . $placeholders . ')
			 LIMIT 1'
		);
		$stmt->execute(array_merge([$ruleId, $subjectKey], $states));
		$row = $stmt->fetch(PDO::FETCH_ASSOC);

		return is_array($row) ? $row : null;
	}

	public function createIncident(array $incident): int {
		$state = (string)($incident['state'] ?? 'active');
		$activeSubjectKey = $state === 'active' ? $this->activeSubjectKey((int)$incident['rule_id'], (string)$incident['subject_key']) : null;
		$thresholdCount = array_key_exists('threshold_count', $incident) ? (int)$incident['threshold_count'] : null;
		$observationWindowMinutes = array_key_exists('observation_window_minutes', $incident) ? (int)$incident['observation_window_minutes'] : null;
		if ($thresholdCount === null || $observationWindowMinutes === null) {
			$stmt = $this->pdo->prepare('SELECT threshold_count, observation_window_minutes FROM repeatcaller_rules WHERE id = ? LIMIT 1');
			$stmt->execute([(int)$incident['rule_id']]);
			$ruleRow = $stmt->fetch(PDO::FETCH_ASSOC);
			if (is_array($ruleRow)) {
				if ($thresholdCount === null) {
					$thresholdCount = (int)($ruleRow['threshold_count'] ?? 0);
				}
				if ($observationWindowMinutes === null) {
					$observationWindowMinutes = (int)($ruleRow['observation_window_minutes'] ?? 0);
				}
			}
		}
		$thresholdCount = $thresholdCount === null ? 0 : $thresholdCount;
		$observationWindowMinutes = $observationWindowMinutes === null ? 0 : $observationWindowMinutes;

		$stmt = $this->pdo->prepare(
			'INSERT INTO repeatcaller_incidents
				(rule_id, subject_key, active_subject_key, subject_label, caller_normalized, caller_display,
				 withheld_caller, mode, threshold_count, observation_window_minutes, first_matched_at, last_matched_at, matched_call_count, state,
				 claimed_by, claimed_at, claim_source, suppression_expires_at, cleared_at, created_at, updated_at)
			 VALUES
				(?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
		);
		$stmt->execute([
			(int)$incident['rule_id'],
			(string)$incident['subject_key'],
			$this->nullableString($activeSubjectKey),
			(string)$incident['subject_label'],
			$this->nullableString($incident['caller_normalized'] ?? null),
			$this->nullableString($incident['caller_display'] ?? null),
			!empty($incident['withheld_caller']) ? 1 : 0,
			(string)$incident['mode'],
			$thresholdCount,
			$observationWindowMinutes,
			(string)$incident['first_matched_at'],
			(string)$incident['last_matched_at'],
			(int)($incident['matched_call_count'] ?? 0),
			$state,
			$this->nullableString($incident['claimed_by'] ?? null),
			$this->nullableString($incident['claimed_at'] ?? null),
			$this->nullableString($incident['claim_source'] ?? null),
			$this->nullableString($incident['suppression_expires_at'] ?? null),
			$this->nullableString($incident['cleared_at'] ?? null),
			(string)$incident['created_at'],
			(string)$incident['updated_at'],
		]);

		return (int)$this->pdo->lastInsertId();
	}

	public function updateIncidentWithCall(int $incidentId, string $lastMatchedAt, int $matchedCallCount): void {
		$stmt = $this->pdo->prepare(
			'UPDATE repeatcaller_incidents
			 SET last_matched_at = ?,
				 matched_call_count = ?,
				 updated_at = ?
			 WHERE id = ?'
		);
		$stmt->execute([$lastMatchedAt, $matchedCallCount, $lastMatchedAt, $incidentId]);
	}

	public function suppressIncident(int $ruleId, string $subjectKey, int $incidentId, string $suppressionExpiresAt, string $updatedAt): void {
		$stmt = $this->pdo->prepare(
			'UPDATE repeatcaller_incidents
			 SET state = ?,
				 active_subject_key = NULL,
				 suppression_expires_at = ?,
				 updated_at = ?
			 WHERE id = ?'
		);
		$stmt->execute(['suppressed', $suppressionExpiresAt, $updatedAt, $incidentId]);

		$state = $this->loadSubjectState($ruleId, $subjectKey) ?? [];
		$state['active_incident_id'] = null;
		$state['suppression_expires_at'] = $suppressionExpiresAt;
		$state['last_evaluated_at'] = $updatedAt;
		$this->saveSubjectState($ruleId, $subjectKey, $state);
	}

	public function markConditionCleared(int $ruleId, string $subjectKey, string $clearedAt): void {
		$state = $this->loadSubjectState($ruleId, $subjectKey) ?? [];
		$state['clear_observed_since_trigger'] = 1;
		$state['active_incident_id'] = null;
		$state['last_evaluated_at'] = $clearedAt;
		$this->saveSubjectState($ruleId, $subjectKey, $state);

		$stmt = $this->pdo->prepare(
			'UPDATE repeatcaller_incidents
			 SET state = ?,
				 active_subject_key = NULL,
				 cleared_at = ?,
				 updated_at = ?
			 WHERE rule_id = ? AND subject_key = ? AND state IN (?, ?, ?, ?)' 
		);
		$stmt->execute(['closed', $clearedAt, $clearedAt, $ruleId, $subjectKey, 'active', 'claimed', 'suppressed', 'expired']);
	}

	public function expireActiveIncidents(string $now): int {
		$stmt = $this->pdo->prepare(
			'UPDATE repeatcaller_incidents
			 SET state = ?,
				 active_subject_key = NULL,
				 updated_at = ?
			 WHERE state = ?
				AND suppression_expires_at IS NOT NULL
				AND suppression_expires_at <= ?'
		);
		$stmt->execute(['expired', $now, 'active', $now]);

		return $stmt->rowCount();
	}

	public function loadAlertEligibleIncidents(string $now, int $limit = 200): array {
		$isDeletedExpr = $this->columnExpr('repeatcaller_rules', 'is_deleted', '0');
		$emailEnabledExpr = $this->columnExpr('repeatcaller_rules', 'email_enabled', '0');
		$emailRecipientsExpr = $this->columnExpr('repeatcaller_rules', 'email_recipients', 'NULL');
		$alertCallEnabledExpr = $this->columnExpr('repeatcaller_rules', 'alert_call_enabled', '0');
		$alertCallDestinationsExpr = $this->columnExpr('repeatcaller_rules', 'alert_call_destinations', 'NULL');
		$alertCallStrategyExpr = $this->columnExpr('repeatcaller_rules', 'alert_call_strategy', "'ringall'");
		$alertCallKeepTryingExpr = $this->columnExpr('repeatcaller_rules', 'alert_call_keep_trying', '1');
		$alertCallRecordingExpr = $this->columnExpr('repeatcaller_rules', 'alert_call_recording_id', 'NULL');
		$alertCallCallerIdExpr = $this->columnExpr('repeatcaller_rules', 'alert_call_callerid', 'NULL');
		$stmt = $this->pdo->prepare(
			'SELECT i.id, i.rule_id, i.subject_key, i.subject_label, i.caller_normalized, i.caller_display,
				i.withheld_caller, i.mode, i.first_matched_at, i.last_matched_at, i.matched_call_count,
				i.state, i.claimed_by, i.claimed_at, i.claim_source, i.suppression_expires_at,
				r.name AS rule_name, r.enabled AS rule_enabled, ' . $emailEnabledExpr . ' AS email_enabled,
				' . $emailRecipientsExpr . ' AS email_recipients,
				' . $alertCallEnabledExpr . ' AS alert_call_enabled, ' . $alertCallDestinationsExpr . ' AS alert_call_destinations,
				' . $alertCallStrategyExpr . ' AS alert_call_strategy, ' . $alertCallKeepTryingExpr . ' AS alert_call_keep_trying,
				' . $alertCallRecordingExpr . ' AS alert_call_recording_id, ' . $alertCallCallerIdExpr . ' AS alert_call_callerid, r.repeat_mode_override
			 FROM repeatcaller_incidents i
			 JOIN repeatcaller_rules r ON r.id = i.rule_id
			 LEFT JOIN repeatcaller_incident_alert_state s ON s.incident_id = i.id
			 WHERE (
				i.state = ?
				OR (
					i.state = ?
					AND s.last_alert_at IS NOT NULL
					AND i.last_matched_at > s.last_alert_at
				)
			 )
				AND r.enabled = 1
				AND ' . $isDeletedExpr . ' = 0
				AND (i.suppression_expires_at IS NULL OR i.suppression_expires_at > ?)
			 ORDER BY i.first_matched_at ASC, i.id ASC
			 LIMIT ' . (int)$limit
		);
		$stmt->execute(['active', 'claimed', $now]);

		return $stmt->fetchAll(PDO::FETCH_ASSOC);
	}

	public function loadIncidentAlertState(int $incidentId): ?array {
		$stmt = $this->pdo->prepare(
			'SELECT id, incident_id, rule_id, repeat_mode, initial_sent_at, last_alert_at,
				reminders_sent, next_due_at, created_at, updated_at
			 FROM repeatcaller_incident_alert_state
			 WHERE incident_id = ?
			 LIMIT 1'
		);
		$stmt->execute([$incidentId]);
		$row = $stmt->fetch(PDO::FETCH_ASSOC);

		return is_array($row) ? $row : null;
	}

	public function ensureIncidentAlertState(int $incidentId, int $ruleId, string $repeatMode, string $firstDueAt, string $now): array {
		$existing = $this->loadIncidentAlertState($incidentId);
		if ($existing !== null) {
			if ((string)$existing['repeat_mode'] !== $repeatMode) {
				$update = $this->pdo->prepare(
					'UPDATE repeatcaller_incident_alert_state
					 SET repeat_mode = ?,
						 updated_at = ?
					 WHERE incident_id = ?'
				);
				$update->execute([$repeatMode, $now, $incidentId]);
				$existing['repeat_mode'] = $repeatMode;
			}
			return $existing;
		}

		$insert = $this->pdo->prepare(
			'INSERT INTO repeatcaller_incident_alert_state
				(incident_id, rule_id, repeat_mode, initial_sent_at, last_alert_at, reminders_sent, next_due_at, created_at, updated_at)
			 VALUES
				(?, ?, ?, NULL, NULL, 0, ?, ?, ?)'
		);

		try {
			$insert->execute([$incidentId, $ruleId, $repeatMode, $firstDueAt, $now, $now]);
		} catch (PDOException $e) {
			if (!$this->isUniqueViolation($e)) {
				throw $e;
			}
		}

		return $this->loadIncidentAlertState($incidentId) ?? [
			'incident_id' => $incidentId,
			'rule_id' => $ruleId,
			'repeat_mode' => $repeatMode,
			'initial_sent_at' => null,
			'last_alert_at' => null,
			'reminders_sent' => 0,
			'next_due_at' => $firstDueAt,
			'created_at' => $now,
			'updated_at' => $now,
		];
	}

	public function markInitialAlertSent(int $incidentId, string $lastAlertAt, ?string $nextDueAt, string $now): bool {
		$stmt = $this->pdo->prepare(
			'UPDATE repeatcaller_incident_alert_state
			 SET initial_sent_at = ?,
				 last_alert_at = ?,
				 next_due_at = ?,
				 updated_at = ?
			 WHERE incident_id = ?
				AND initial_sent_at IS NULL'
		);
		$stmt->execute([$lastAlertAt, $lastAlertAt, $nextDueAt, $now, $incidentId]);

		return $stmt->rowCount() > 0;
	}

	public function markReminderAlertSent(int $incidentId, int $expectedRemindersSent, int $newRemindersSent, string $lastAlertAt, ?string $nextDueAt, string $now): bool {
		$stmt = $this->pdo->prepare(
			'UPDATE repeatcaller_incident_alert_state
			 SET reminders_sent = ?,
				 last_alert_at = ?,
				 next_due_at = ?,
				 updated_at = ?
			 WHERE incident_id = ?
				AND initial_sent_at IS NOT NULL
				AND reminders_sent = ?'
		);
		$stmt->execute([$newRemindersSent, $lastAlertAt, $nextDueAt, $now, $incidentId, $expectedRemindersSent]);

		return $stmt->rowCount() > 0;
	}

	public function reserveIncidentAlertHistory(array $row): bool {
		$stmt = $this->pdo->prepare(
			'INSERT INTO repeatcaller_incident_alert_history
				(incident_id, rule_id, subject_key, subject_label, action_type, event_type, stage_n, recipient,
				 delivery_status, attempted_at, successful_at, next_retry_at, failure_detail, repeat_mode,
				 dedupe_key, created_at, updated_at)
			 VALUES
				(?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
		);

		try {
			$stmt->execute([
				(int)$row['incident_id'],
				(int)$row['rule_id'],
				(string)$row['subject_key'],
				(string)$row['subject_label'],
				(string)$row['action_type'],
				(string)$row['event_type'],
				(int)$row['stage_n'],
				$this->nullableString($row['recipient'] ?? null),
				(string)$row['delivery_status'],
				$this->nullableString($row['attempted_at'] ?? null),
				$this->nullableString($row['successful_at'] ?? null),
				$this->nullableString($row['next_retry_at'] ?? null),
				$this->nullableString($row['failure_detail'] ?? null),
				(string)$row['repeat_mode'],
				(string)$row['dedupe_key'],
				(string)$row['created_at'],
				(string)$row['updated_at'],
			]);
			return true;
		} catch (PDOException $e) {
			if ($this->isUniqueViolation($e)) {
				return false;
			}
			throw $e;
		}
	}

	public function reserveSuppressedIncidentHistory(array $row): bool {
		$stmt = $this->pdo->prepare(
			'INSERT INTO repeatcaller_incident_suppression_history
				(related_incident_id, rule_id, rule_name, mode, subject_key, subject_label,
				 caller_normalized, caller_display, inbound_route_key, inbound_route_label, did_value,
				 matched_call_count, threshold_count, observation_window_minutes,
				 suppression_source, suppression_minutes, suppression_started_at, suppression_expires_at,
				 cleared_at, related_incident_state, detected_at, created_at, updated_at)
			 VALUES
			(?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)' 
		);

		try {
			$stmt->execute([
				(int)$row['related_incident_id'],
				(int)$row['rule_id'],
				(string)$row['rule_name'],
				(string)$row['mode'],
				(string)$row['subject_key'],
				(string)$row['subject_label'],
				$this->nullableString($row['caller_normalized'] ?? null),
				$this->nullableString($row['caller_display'] ?? null),
				$this->nullableString($row['inbound_route_key'] ?? null),
				$this->nullableString($row['inbound_route_label'] ?? null),
				$this->nullableString($row['did_value'] ?? null),
				(int)$row['matched_call_count'],
				(int)$row['threshold_count'],
				(int)$row['observation_window_minutes'],
				(string)$row['suppression_source'],
				(int)$row['suppression_minutes'],
				(string)$row['suppression_started_at'],
				(string)$row['suppression_expires_at'],
				$this->nullableString($row['cleared_at'] ?? null),
				(string)$row['related_incident_state'],
				(string)$row['detected_at'],
				(string)$row['created_at'],
				(string)$row['updated_at'],
			]);
			return true;
		} catch (PDOException $e) {
			if ($this->isUniqueViolation($e)) {
				return false;
			}
			throw $e;
		}
	}

	public function clearSuppressedIncidentHistory(int $historyId, string $clearedAt): bool {
		$rowStmt = $this->pdo->prepare(
			'SELECT id, related_incident_id, rule_id, subject_key
			 FROM repeatcaller_incident_suppression_history
			 WHERE id = ?
			 LIMIT 1'
		);
		$rowStmt->execute([$historyId]);
		$row = $rowStmt->fetch(PDO::FETCH_ASSOC);
		if (!is_array($row)) {
			return false;
		}

		$updateStmt = $this->pdo->prepare(
			'UPDATE repeatcaller_incident_suppression_history
			 SET cleared_at = COALESCE(cleared_at, ?),
				 updated_at = ?
			 WHERE id = ?'
		);
		$updateStmt->execute([$clearedAt, $clearedAt, $historyId]);

		$state = $this->loadSubjectState((int)$row['rule_id'], (string)$row['subject_key']) ?? [];
		$state['active_incident_id'] = null;
		$state['threshold_met'] = 0;
		$state['clear_observed_since_trigger'] = 1;
		$state['suppression_expires_at'] = null;
		$state['last_evaluated_at'] = $clearedAt;
		$this->saveSubjectState((int)$row['rule_id'], (string)$row['subject_key'], $state);

		return true;
	}

	public function markEmailAlertSending(int $historyId, string $attemptedAt): bool {
		$stmt = $this->pdo->prepare(
			'UPDATE repeatcaller_incident_alert_history
			 SET delivery_status = ?,
				 attempted_at = ?,
				 updated_at = ?
			 WHERE id = ?
				AND action_type = ?
				AND delivery_status IN (?, ?, ?)'
		);
		$stmt->execute(['sending', $attemptedAt, $attemptedAt, $historyId, 'email', 'pending', 'failed', 'snoozed']);

		return $stmt->rowCount() > 0;
	}

	public function markEmailAlertSnoozed(int $historyId, string $now): void {
		$stmt = $this->pdo->prepare(
			'UPDATE repeatcaller_incident_alert_history
			 SET delivery_status = ?,
				 next_retry_at = ?,
				 updated_at = ?
			 WHERE id = ?
				AND action_type = ?
				AND delivery_status IN (?, ?, ?, ?)'
		);
		$stmt->execute(['snoozed', $now, $now, $historyId, 'email', 'pending', 'failed', 'snoozed', 'sending']);
	}

	public function markEmailAlertSent(int $historyId, string $successfulAt): void {
		$stmt = $this->pdo->prepare(
			'UPDATE repeatcaller_incident_alert_history
			 SET delivery_status = ?,
				 successful_at = ?,
				 next_retry_at = NULL,
				 failure_detail = NULL,
				 updated_at = ?
			 WHERE id = ?
				AND action_type = ?'
		);
		$stmt->execute(['sent', $successfulAt, $successfulAt, $historyId, 'email']);
	}

	public function markEmailAlertFailed(int $historyId, string $failureDetail, string $nextRetryAt, string $now): void {
		$stmt = $this->pdo->prepare(
			'UPDATE repeatcaller_incident_alert_history
			 SET delivery_status = ?,
				 failure_detail = ?,
				 next_retry_at = ?,
				 updated_at = ?
			 WHERE id = ?
				AND action_type = ?'
		);
		$stmt->execute(['failed', $failureDetail, $nextRetryAt, $now, $historyId, 'email']);
	}

	public function loadDeliverableEmailAlerts(string $now, int $limit = 200): array {
		$isDeletedExpr = $this->columnExpr('repeatcaller_rules', 'is_deleted', '0');
		$stmt = $this->pdo->prepare(
			'SELECT h.id, h.incident_id, h.rule_id, h.subject_key, h.subject_label, h.event_type, h.stage_n,
				h.recipient, h.delivery_status, h.repeat_mode,
				i.caller_display, i.caller_normalized, i.withheld_caller, i.mode,
				i.threshold_count, i.observation_window_minutes, i.first_matched_at, i.last_matched_at, i.matched_call_count, i.state, i.suppression_expires_at,
				r.name AS rule_name,
				r.repeat_mode_override AS rule_repeat_mode_override
			 FROM repeatcaller_incident_alert_history h
			 JOIN repeatcaller_incidents i ON i.id = h.incident_id
			 JOIN repeatcaller_rules r ON r.id = h.rule_id
			 WHERE h.action_type = ?
				AND h.delivery_status IN (?, ?, ?)
				AND (h.next_retry_at IS NULL OR h.next_retry_at <= ?)
				AND i.state = ?
				AND ' . $isDeletedExpr . ' = 0
				AND (i.suppression_expires_at IS NULL OR i.suppression_expires_at > ?)
			 ORDER BY h.created_at ASC, h.id ASC
			 LIMIT ' . (int)$limit
		);
		$stmt->execute(['email', 'pending', 'failed', 'snoozed', $now, 'active', $now]);

		return $stmt->fetchAll(PDO::FETCH_ASSOC);
	}

	public function markCallAlertSending(int $historyId, string $attemptedAt): bool {
		$stmt = $this->pdo->prepare(
			'UPDATE repeatcaller_incident_alert_history
			 SET delivery_status = ?,
				 attempted_at = ?,
				 updated_at = ?
			 WHERE id = ?
				AND action_type = ?
				AND delivery_status IN (?, ?)'
		);
		$stmt->execute(['sending', $attemptedAt, $attemptedAt, $historyId, 'alert_call', 'pending', 'snoozed']);

		return $stmt->rowCount() > 0;
	}

	public function markCallAlertSnoozed(int $historyId, string $now): void {
		$stmt = $this->pdo->prepare(
			'UPDATE repeatcaller_incident_alert_history
			 SET delivery_status = ?,
				 next_retry_at = ?,
				 updated_at = ?
			 WHERE id = ?
				AND action_type = ?
				AND delivery_status IN (?, ?, ?)' 
		);
		$stmt->execute(['snoozed', $now, $now, $historyId, 'alert_call', 'pending', 'snoozed', 'sending']);
	}

	public function markCallAlertSent(int $historyId, string $successfulAt): void {
		$stmt = $this->pdo->prepare(
			'UPDATE repeatcaller_incident_alert_history
			 SET delivery_status = ?,
				 successful_at = ?,
				 next_retry_at = NULL,
				 failure_detail = NULL,
				 updated_at = ?
			 WHERE id = ?
				AND action_type = ?'
		);
		$stmt->execute(['sent', $successfulAt, $successfulAt, $historyId, 'alert_call']);
	}

	public function markCallAlertFailed(int $historyId, string $failureDetail, string $now): void {
		// Alert Call failures never schedule same-stage retries. The next attempt,
		// if any, is created only by the next normal reminder stage reservation.
		$stmt = $this->pdo->prepare(
			'UPDATE repeatcaller_incident_alert_history
			 SET delivery_status = ?,
				 failure_detail = ?,
				 next_retry_at = NULL,
				 updated_at = ?
			 WHERE id = ?
				AND action_type = ?'
		);
		$stmt->execute(['failed', $failureDetail, $now, $historyId, 'alert_call']);
	}

	public function recordAlertCallDtmfResponse(int $historyId, int $incidentId, string $response, string $recipient, string $digit, string $now): array {
		$row = $this->loadAlertCallAttempt($historyId, $incidentId);
		if ($row === null) {
			return ['status' => false, 'claimed' => false, 'message' => 'alert call attempt not found'];
		}

		$storedRecipient = trim((string)($row['recipient'] ?? ''));
		if ($recipient !== '' && $storedRecipient !== '' && $recipient !== $storedRecipient) {
			return ['status' => false, 'claimed' => false, 'message' => 'alert call recipient mismatch'];
		}

		$response = strtolower(trim($response));
		$digit = trim($digit);
		$claimed = false;
		$deliveryStatus = self::ALERT_CALL_OUTCOME_ANSWERED_NO_RESPONSE;
		$successfulAt = null;
		$failureDetail = null;

		if ($response === self::ALERT_CALL_OUTCOME_ACCEPTED) {
			$claimedBy = 'alert-call' . ($storedRecipient !== '' ? ':' . $storedRecipient : '');
			$claimed = $this->claimActiveIncident($incidentId, $claimedBy, $now, 'alert_call');
			$deliveryStatus = self::ALERT_CALL_OUTCOME_ACCEPTED;
			$successfulAt = $now;
			$failureDetail = $claimed ? 'incident accepted' : 'accepted after incident was already accepted or no longer active';
		} elseif ($response === self::ALERT_CALL_OUTCOME_DECLINED) {
			$deliveryStatus = self::ALERT_CALL_OUTCOME_DECLINED;
			$successfulAt = $now;
			$failureDetail = 'Recipient declined the Alert Call';
		} elseif ($response === self::ALERT_CALL_OUTCOME_ANSWERED_NO_RESPONSE || $response === 'hangup') {
			$failureDetail = 'answered call ended without a valid DTMF response';
		} elseif ($digit !== '' && $digit !== '1' && $digit !== '2') {
			$failureDetail = 'no valid DTMF response after 3 attempts; last digit: ' . substr($digit, 0, 8);
		} else {
			$failureDetail = 'no DTMF response after 3 attempts';
		}

		$updated = $this->updateAlertCallAttemptResult($historyId, $deliveryStatus, $successfulAt, $failureDetail, $now);
		$reservedNextHistoryId = null;
		if ($updated && !$claimed && $deliveryStatus !== self::ALERT_CALL_OUTCOME_ACCEPTED) {
			$reservedNextHistoryId = $this->reserveNextOrderedAlertCallAttempt($historyId, $now);
		}

		return ['status' => $updated, 'claimed' => $claimed, 'delivery_status' => $deliveryStatus, 'next_history_id' => $reservedNextHistoryId];
	}

	public function recordAlertCallDialDisposition(int $historyId, int $incidentId, string $recipient, string $dialStatus, string $hangupCause, string $now): array {
		$row = $this->loadAlertCallAttempt($historyId, $incidentId);
		if ($row === null) {
			return ['status' => false, 'message' => 'alert call attempt not found'];
		}

		$storedRecipient = trim((string)($row['recipient'] ?? ''));
		if ($recipient !== '' && $storedRecipient !== '' && $recipient !== $storedRecipient) {
			return ['status' => false, 'message' => 'alert call recipient mismatch'];
		}

		$currentStatus = strtolower(trim((string)($row['delivery_status'] ?? '')));
		$mapped = $this->mapDialStatusOutcome($dialStatus);
		$dialStatus = strtoupper(trim($dialStatus));
		$hangupCause = trim($hangupCause);
		$detail = 'DIALSTATUS=' . ($dialStatus !== '' ? $dialStatus : 'UNKNOWN') . '; HANGUPCAUSE=' . ($hangupCause !== '' ? $hangupCause : '-');

		if ($dialStatus === 'ANSWER' && in_array($currentStatus, [
			self::ALERT_CALL_OUTCOME_ACCEPTED,
			self::ALERT_CALL_OUTCOME_DECLINED,
			self::ALERT_CALL_OUTCOME_ANSWERED_NO_RESPONSE,
		], true)) {
			return ['status' => true, 'delivery_status' => $currentStatus, 'preserved' => true];
		}

		if (!in_array($currentStatus, ['pending', 'snoozed', 'sending', 'sent', self::ALERT_CALL_OUTCOME_FAILED], true)) {
			return ['status' => true, 'delivery_status' => $currentStatus, 'preserved' => true];
		}

		$updated = $this->updateAlertCallAttemptResult($historyId, $mapped, null, $detail, $now);
		$reservedNextHistoryId = null;
		if ($updated && $mapped !== self::ALERT_CALL_OUTCOME_ACCEPTED) {
			$reservedNextHistoryId = $this->reserveNextOrderedAlertCallAttempt($historyId, $now);
		}

		return ['status' => $updated, 'delivery_status' => $mapped, 'next_history_id' => $reservedNextHistoryId];
	}

	private function loadAlertCallAttempt(int $historyId, int $incidentId): ?array {
		$stmt = $this->pdo->prepare(
			'SELECT id, incident_id, rule_id, event_type, stage_n, repeat_mode, recipient, delivery_status
			 FROM repeatcaller_incident_alert_history
			 WHERE id = ?
				AND incident_id = ?
				AND action_type = ?
			 LIMIT 1'
		);
		$stmt->execute([$historyId, $incidentId, 'alert_call']);
		$row = $stmt->fetch(PDO::FETCH_ASSOC);

		return is_array($row) ? $row : null;
	}

	private function updateAlertCallAttemptResult(int $historyId, string $deliveryStatus, ?string $successfulAt, ?string $failureDetail, string $now): bool {
		$stmt = $this->pdo->prepare(
			'UPDATE repeatcaller_incident_alert_history
			 SET delivery_status = ?,
				 successful_at = ?,
				 next_retry_at = NULL,
				 failure_detail = ?,
				 updated_at = ?
			 WHERE id = ?
				AND action_type = ?
				AND delivery_status IN (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
		);
		$stmt->execute([
			$deliveryStatus,
			$this->nullableString($successfulAt),
			$this->nullableString($failureDetail),
			$now,
			$historyId,
			'alert_call',
			'pending',
			'snoozed',
			'sending',
			'sent',
			self::ALERT_CALL_OUTCOME_BUSY,
			self::ALERT_CALL_OUTCOME_NO_ANSWER,
			self::ALERT_CALL_OUTCOME_UNREACHABLE,
			self::ALERT_CALL_OUTCOME_CONGESTION,
			self::ALERT_CALL_OUTCOME_FAILED,
			self::ALERT_CALL_OUTCOME_ANSWERED_NO_RESPONSE,
			self::ALERT_CALL_OUTCOME_DECLINED,
			self::ALERT_CALL_OUTCOME_ACCEPTED,
		]);

		return $stmt->rowCount() > 0;
	}

	public function loadDeliverableCallAlerts(string $now, int $limit = 200): array {
		$isDeletedExpr = $this->columnExpr('repeatcaller_rules', 'is_deleted', '0');
		$alertCallRecordingExpr = $this->columnExpr('repeatcaller_rules', 'alert_call_recording_id', 'NULL');
		$alertCallCallerIdExpr = $this->columnExpr('repeatcaller_rules', 'alert_call_callerid', 'NULL');
		$callerModeExpr = $this->columnExpr('repeatcaller_rules', 'caller_mode', "'any'");
		$didScopeModeExpr = $this->columnExpr('repeatcaller_rules', 'did_scope_mode', "'all'");
		$thresholdCountExpr = $this->columnExpr('repeatcaller_rules', 'threshold_count', '0');
		$windowMinutesExpr = $this->columnExpr('repeatcaller_rules', 'observation_window_minutes', '0');
		// Only pending/snoozed call stages are deliverable. Failed call outcomes are
		// terminal for that stage and must wait for the next due reminder stage.
		$stmt = $this->pdo->prepare(
			'SELECT h.id, h.incident_id, h.rule_id, h.subject_key, h.subject_label, h.event_type, h.stage_n,
				h.recipient, h.delivery_status, h.repeat_mode,
				i.caller_display, i.caller_normalized, i.withheld_caller, i.mode,
				i.threshold_count, i.observation_window_minutes, i.first_matched_at, i.last_matched_at, i.matched_call_count, i.state, i.suppression_expires_at,
				r.name AS rule_name, ' . $alertCallRecordingExpr . ' AS alert_call_recording_id, ' . $alertCallCallerIdExpr . ' AS alert_call_callerid,
				' . $callerModeExpr . ' AS caller_mode, ' . $didScopeModeExpr . ' AS did_scope_mode
			 FROM repeatcaller_incident_alert_history h
			 JOIN repeatcaller_incidents i ON i.id = h.incident_id
			 JOIN repeatcaller_rules r ON r.id = h.rule_id
			 WHERE h.action_type = ?
				AND h.delivery_status IN (?, ?)
				AND (h.next_retry_at IS NULL OR h.next_retry_at <= ?)
				AND i.state = ?
				AND ' . $isDeletedExpr . ' = 0
				AND (i.suppression_expires_at IS NULL OR i.suppression_expires_at > ?)
			 ORDER BY h.created_at ASC, h.id ASC
			 LIMIT ' . (int)$limit
		);
		$stmt->execute(['alert_call', 'pending', 'snoozed', $now, 'active', $now]);

		return $stmt->fetchAll(PDO::FETCH_ASSOC);
	}

	public function loadDeliverableCallAlertByHistoryId(int $historyId, string $now): ?array {
		$isDeletedExpr = $this->columnExpr('repeatcaller_rules', 'is_deleted', '0');
		$alertCallRecordingExpr = $this->columnExpr('repeatcaller_rules', 'alert_call_recording_id', 'NULL');
		$alertCallCallerIdExpr = $this->columnExpr('repeatcaller_rules', 'alert_call_callerid', 'NULL');
		$callerModeExpr = $this->columnExpr('repeatcaller_rules', 'caller_mode', "'any'");
		$didScopeModeExpr = $this->columnExpr('repeatcaller_rules', 'did_scope_mode', "'all'");
		$thresholdCountExpr = $this->columnExpr('repeatcaller_rules', 'threshold_count', '0');
		$windowMinutesExpr = $this->columnExpr('repeatcaller_rules', 'observation_window_minutes', '0');
		$stmt = $this->pdo->prepare(
			'SELECT h.id, h.incident_id, h.rule_id, h.subject_key, h.subject_label, h.event_type, h.stage_n,
				h.recipient, h.delivery_status, h.repeat_mode,
				i.caller_display, i.caller_normalized, i.withheld_caller, i.mode,
				i.first_matched_at, i.last_matched_at, i.matched_call_count, i.state, i.suppression_expires_at,
				r.name AS rule_name, ' . $alertCallRecordingExpr . ' AS alert_call_recording_id, ' . $alertCallCallerIdExpr . ' AS alert_call_callerid,
				' . $callerModeExpr . ' AS caller_mode, ' . $didScopeModeExpr . ' AS did_scope_mode
			 FROM repeatcaller_incident_alert_history h
			 JOIN repeatcaller_incidents i ON i.id = h.incident_id
			 JOIN repeatcaller_rules r ON r.id = h.rule_id
			 WHERE h.id = ?
				AND h.action_type = ?
				AND h.delivery_status IN (?, ?)
				AND (h.next_retry_at IS NULL OR h.next_retry_at <= ?)
				AND i.state = ?
				AND ' . $isDeletedExpr . ' = 0
				AND (i.suppression_expires_at IS NULL OR i.suppression_expires_at > ?)
			 LIMIT 1'
		);
		$stmt->execute([$historyId, 'alert_call', 'pending', 'snoozed', $now, 'active', $now]);
		$row = $stmt->fetch(PDO::FETCH_ASSOC);

		return is_array($row) ? $row : null;
	}

	public function loadAlertCallAttemptHistoryByIncident(int $incidentId): array {
		$stmt = $this->pdo->prepare(
			'SELECT id, event_type, stage_n, recipient, delivery_status, created_at, updated_at
			 FROM repeatcaller_incident_alert_history
			 WHERE incident_id = ?
				AND action_type = ?
			 ORDER BY id ASC'
		);
		$stmt->execute([$incidentId, 'alert_call']);

		return $stmt->fetchAll(PDO::FETCH_ASSOC);
	}

	public function reserveNextOrderedAlertCallAttempt(int $historyId, string $now): ?int {
		$attempt = $this->loadOrderedAttemptContext($historyId);
		if ($attempt === null) {
			return null;
		}

		if ((string)$attempt['strategy'] !== 'ordered') {
			return null;
		}

		if ((string)$attempt['incident_state'] !== 'active') {
			return null;
		}

		$currentRecipient = trim((string)$attempt['recipient']);
		if ($currentRecipient === '') {
			return null;
		}

		$destinationEntries = $this->parseAlertCallDestinationEntries((string)$attempt['alert_call_destinations']);
		if (!$destinationEntries) {
			return null;
		}
		$destinations = array_map(static function (array $entry): string {
			return (string)$entry['destination'];
		}, $destinationEntries);

		$eligible = $this->eligibleAlertCallDestinationsForStage(
			(int)$attempt['incident_id'],
			$destinationEntries,
			((int)$attempt['keep_trying']) === 1,
			(string)$attempt['event_type'],
			(int)$attempt['stage_n']
		);

		$currentIndex = array_search($currentRecipient, $destinations, true);
		if ($currentIndex === false) {
			return null;
		}

		$nextRecipient = null;
		for ($i = $currentIndex + 1; $i < count($destinations); $i++) {
			if (in_array($destinations[$i], $eligible, true)) {
				$nextRecipient = $destinations[$i];
				break;
			}
		}

		if ($nextRecipient === null) {
			return null;
		}

		$inserted = $this->reserveIncidentAlertHistory([
			'incident_id' => (int)$attempt['incident_id'],
			'rule_id' => (int)$attempt['rule_id'],
			'subject_key' => (string)$attempt['subject_key'],
			'subject_label' => (string)$attempt['subject_label'],
			'action_type' => 'alert_call',
			'event_type' => (string)$attempt['event_type'],
			'stage_n' => (int)$attempt['stage_n'],
			'recipient' => $nextRecipient,
			'delivery_status' => 'pending',
			'attempted_at' => null,
			'successful_at' => null,
			'next_retry_at' => null,
			'failure_detail' => null,
			'repeat_mode' => (string)$attempt['repeat_mode'],
			'dedupe_key' => $this->alertDedupeKey((int)$attempt['incident_id'], (string)$attempt['event_type'], (int)$attempt['stage_n'], 'alert_call', $nextRecipient),
			'created_at' => $now,
			'updated_at' => $now,
		]);

		if (!$inserted) {
			return null;
		}

		return (int)$this->pdo->lastInsertId();
	}

	public function eligibleAlertCallDestinationsForStage(int $incidentId, array $destinations, bool $keepTrying, string $eventType, int $stageN): array {
		$history = $this->loadAlertCallAttemptHistoryByIncident($incidentId);
		$declined = [];
		$attemptedAny = [];
		$attemptedThisStage = [];

		foreach ($history as $row) {
			$recipient = trim((string)($row['recipient'] ?? ''));
			if ($recipient === '') {
				continue;
			}
			$status = strtolower(trim((string)($row['delivery_status'] ?? '')));
			$attemptedAny[$recipient] = true;
			if ((string)$row['event_type'] === $eventType && (int)$row['stage_n'] === $stageN) {
				$attemptedThisStage[$recipient] = true;
			}
			if ($status === self::ALERT_CALL_OUTCOME_DECLINED) {
				$declined[$recipient] = true;
			}
		}

		$eligible = [];
		foreach ($destinations as $destinationItem) {
			$destinationKeepTrying = $keepTrying;
			if (is_array($destinationItem)) {
				$destination = trim((string)($destinationItem['destination'] ?? ''));
				if (array_key_exists('keep_trying', $destinationItem)) {
					$destinationKeepTrying = ((int)$destinationItem['keep_trying']) === 1;
				}
			} else {
				$destination = trim((string)$destinationItem);
			}
			if ($destination === '') {
				continue;
			}
			if (isset($declined[$destination])) {
				continue;
			}
			if (!$destinationKeepTrying && isset($attemptedAny[$destination])) {
				continue;
			}
			if (isset($attemptedThisStage[$destination])) {
				continue;
			}
			$eligible[] = $destination;
		}

		return $eligible;
	}

	public function buildDeclineNotificationContext(int $historyId, int $incidentId): ?array {
		$stmt = $this->pdo->prepare(
			'SELECT h.id, h.incident_id, h.rule_id, h.recipient, h.event_type, h.stage_n,
				h.subject_key, h.subject_label,
				i.state AS incident_state, i.caller_display, i.caller_normalized,
				r.name AS rule_name,
				' . $this->columnExpr('repeatcaller_rules', 'email_enabled', '0') . ' AS email_enabled,
				' . $this->columnExpr('repeatcaller_rules', 'email_recipients', 'NULL') . ' AS email_recipients,
				' . $this->columnExpr('repeatcaller_rules', 'alert_call_destinations', 'NULL') . ' AS alert_call_destinations,
				' . $this->columnExpr('repeatcaller_rules', 'alert_call_strategy', "'ringall'") . ' AS alert_call_strategy,
				' . $this->columnExpr('repeatcaller_rules', 'alert_call_keep_trying', '1') . ' AS alert_call_keep_trying
			 FROM repeatcaller_incident_alert_history h
			 JOIN repeatcaller_incidents i ON i.id = h.incident_id
			 JOIN repeatcaller_rules r ON r.id = h.rule_id
			 WHERE h.id = ?
				AND h.incident_id = ?
				AND h.action_type = ?
			 LIMIT 1'
		);
		$stmt->execute([$historyId, $incidentId, 'alert_call']);
		$row = $stmt->fetch(PDO::FETCH_ASSOC);
		if (!is_array($row)) {
			return null;
		}

		$ruleEmailEnabled = ((string)($row['email_enabled'] ?? '0') === '1');
		$recipients = $this->normaliseEmailRecipients((string)($row['email_recipients'] ?? ''));

		$strategy = $this->normaliseAlertCallStrategy((string)($row['alert_call_strategy'] ?? 'ringall'));
		$keepTrying = ((int)($row['alert_call_keep_trying'] ?? 1)) === 1;
		$destinations = $this->parseAlertCallDestinationEntries((string)($row['alert_call_destinations'] ?? ''));
		$eligible = $this->eligibleAlertCallDestinationsForStage(
			(int)$row['incident_id'],
			$destinations,
			$keepTrying,
			(string)$row['event_type'],
			(int)$row['stage_n']
		);

		return [
			'email_enabled' => $ruleEmailEnabled && !empty($recipients),
			'recipients' => $recipients,
			'recipient' => (string)($row['recipient'] ?? ''),
			'incident_id' => (int)$row['incident_id'],
			'rule_name' => (string)($row['rule_name'] ?? ''),
			'subject_label' => (string)($row['subject_label'] ?? $row['subject_key'] ?? ''),
			'caller_display' => (string)($row['caller_display'] ?? ''),
			'caller_normalized' => (string)($row['caller_normalized'] ?? ''),
			'strategy' => $strategy,
			'has_remaining_eligible' => !empty($eligible),
			'ordered_continuing' => $strategy === 'ordered' && !empty($eligible),
		];
	}

	public function pruneIncidentAlertHistory(string $cutoff): int {
		$stmt = $this->pdo->prepare(
			'DELETE FROM repeatcaller_incident_alert_history
			 WHERE created_at < ?
				AND incident_id IN (
					SELECT id FROM repeatcaller_incidents WHERE state <> ?
				)'
		);
		$stmt->execute([$cutoff, 'active']);

		return $stmt->rowCount();
	}

	public function pruneSuppressedIncidentHistory(string $cutoff): int {
		$stmt = $this->pdo->prepare(
			'DELETE FROM repeatcaller_incident_suppression_history
			 WHERE created_at < ?'
		);
		$stmt->execute([$cutoff]);

		return $stmt->rowCount();
	}

	public function clearIncidentAlertHistory(): int {
		$stmt = $this->pdo->prepare('DELETE FROM repeatcaller_incident_alert_history');
		$stmt->execute();

		return $stmt->rowCount();
	}

	public function pruneIncidentAlertState(string $cutoff): int {
		$stmt = $this->pdo->prepare(
			'DELETE FROM repeatcaller_incident_alert_state
			 WHERE updated_at < ?
				AND incident_id IN (
					SELECT id FROM repeatcaller_incidents WHERE state <> ?
				)'
		);
		$stmt->execute([$cutoff, 'active']);

		return $stmt->rowCount();
	}

	public function pruneClosedIncidents(string $cutoff): int {
		$stmt = $this->pdo->prepare(
			'DELETE FROM repeatcaller_incidents
			 WHERE state <> ?
				AND updated_at < ?
				AND id NOT IN (SELECT incident_id FROM repeatcaller_incident_alert_history)'
		);
		$stmt->execute(['active', $cutoff]);

		return $stmt->rowCount();
	}

	public function pruneSeenCallsOlderThan(string $cutoff): int {
		$stmt = $this->pdo->prepare(
			'DELETE FROM repeatcaller_seen_calls
			 WHERE processed_at < ?'
		);
		$stmt->execute([$cutoff]);

		return $stmt->rowCount();
	}

	public function pruneSeenCallsByFixedRetention(string $now): int {
		try {
			$cutoff = (new \DateTimeImmutable($now))
				->modify('-' . self::SEEN_CALL_RETENTION_DAYS . ' days')
				->format('Y-m-d H:i:s');
		} catch (\Throwable $e) {
			$cutoff = date('Y-m-d H:i:s', strtotime($now) - (self::SEEN_CALL_RETENTION_DAYS * 86400));
		}

		return $this->pruneSeenCallsOlderThan($cutoff);
	}

	private function replaceRuleSchedules(int $ruleId, array $schedules, string $now): void {
		$delete = $this->pdo->prepare('DELETE FROM repeatcaller_rule_schedules WHERE rule_id = ?');
		$delete->execute([$ruleId]);
		if (!$schedules) {
			return;
		}

		$insert = $this->pdo->prepare(
			'INSERT INTO repeatcaller_rule_schedules (rule_id, day_of_week, start_time, end_time, created_at)
			 VALUES (?, ?, ?, ?, ?)'
		);
		foreach ($schedules as $schedule) {
			$insert->execute([
				$ruleId,
				(int)$schedule['day'],
				substr((string)$schedule['start'], 0, 5) . ':00',
				substr((string)$schedule['end'], 0, 5) . ':00',
				$now,
			]);
		}
	}

	public static function normalizeSchedules(array $schedules): array {
		$seen = [];
		$normalized = [];
		foreach ($schedules as $schedule) {
			$day = -2;
			if (array_key_exists('day', $schedule)) {
				$dayRaw = $schedule['day'];
				if (is_int($dayRaw)) {
					$day = $dayRaw;
				} elseif (is_string($dayRaw) && preg_match('/^-?\d+$/', trim($dayRaw))) {
					$day = (int)trim($dayRaw);
				} elseif ($dayRaw === null || $dayRaw === '') {
					$day = -1;
				}
			}
			if ($day < -1 || $day > 6) {
				continue;
			}

			$allDay = !empty($schedule['all_day']);
			$start = substr(trim((string)($schedule['start'] ?? '')), 0, 5);
			$end = substr(trim((string)($schedule['end'] ?? '')), 0, 5);
			if ($allDay) {
				$start = '00:00';
				$end = '24:00';
			}

			if (!preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $start)) {
				continue;
			}
			if (!preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$|^24:00$/', $end)) {
				continue;
			}
			if ($end === '24:00' && $start !== '00:00') {
				continue;
			}
			if ($end !== '24:00' && $start >= $end) {
				continue;
			}

			if ($day === -1 && $start === '00:00' && $end === '24:00') {
				return [['day' => -1, 'start' => '00:00', 'end' => '24:00']];
			}

			$key = $day . '|' . $start . '|' . $end;
			if (isset($seen[$key])) {
				continue;
			}
			$seen[$key] = true;
			$normalized[] = ['day' => $day, 'start' => $start, 'end' => $end];
		}

		usort($normalized, function (array $left, array $right): int {
			if ((int)$left['day'] !== (int)$right['day']) {
				return (int)$left['day'] <=> (int)$right['day'];
			}
			if ((string)$left['start'] !== (string)$right['start']) {
				return strcmp((string)$left['start'], (string)$right['start']);
			}
			return strcmp((string)$left['end'], (string)$right['end']);
		});

		return $normalized;
	}

	private function replaceRuleCallers(int $ruleId, array $callers, string $now): void {
		$delete = $this->pdo->prepare('DELETE FROM repeatcaller_rule_callers WHERE rule_id = ?');
		$delete->execute([$ruleId]);
		if (!$callers) {
			return;
		}

		$insert = $this->pdo->prepare(
			'INSERT INTO repeatcaller_rule_callers (rule_id, list_type, raw_value, normalized_value, created_at)
			 VALUES (?, ?, ?, ?, ?)'
		);
		foreach ($callers as $caller) {
			$insert->execute([
				$ruleId,
				(string)$caller['list_type'],
				(string)$caller['raw_value'],
				(string)$caller['normalized_value'],
				$now,
			]);
		}
	}

	private function replaceRuleDids(int $ruleId, array $dids, string $now): void {
		$delete = $this->pdo->prepare('DELETE FROM repeatcaller_rule_dids WHERE rule_id = ?');
		$delete->execute([$ruleId]);
		if (!$dids) {
			return;
		}

		$insert = $this->pdo->prepare(
			'INSERT INTO repeatcaller_rule_dids (rule_id, list_type, route_key, route_label, did_value, cid_value, created_at)
			 VALUES (?, ?, ?, ?, ?, ?, ?)'
		);
		foreach ($dids as $did) {
			$insert->execute([
				$ruleId,
				(string)$did['list_type'],
				(string)$did['route_key'],
				(string)$did['route_label'],
				$this->nullableString($did['did_value'] ?? null),
				$this->nullableString($did['cid_value'] ?? null),
				$now,
			]);
		}
	}

	private function placeholders(array $values): string {
		return implode(', ', array_fill(0, count($values), '?'));
	}

	private function loadOrderedAttemptContext(int $historyId): ?array {
		$stmt = $this->pdo->prepare(
			'SELECT h.id, h.incident_id, h.rule_id, h.subject_key, h.subject_label, h.event_type, h.stage_n, h.repeat_mode, h.recipient,
				i.state AS incident_state,
				' . $this->columnExpr('repeatcaller_rules', 'alert_call_destinations', 'NULL') . ' AS alert_call_destinations,
				' . $this->columnExpr('repeatcaller_rules', 'alert_call_strategy', "'ringall'") . ' AS strategy,
				' . $this->columnExpr('repeatcaller_rules', 'alert_call_keep_trying', '1') . ' AS keep_trying
			 FROM repeatcaller_incident_alert_history h
			 JOIN repeatcaller_incidents i ON i.id = h.incident_id
			 JOIN repeatcaller_rules r ON r.id = h.rule_id
			 WHERE h.id = ?
				AND h.action_type = ?
			 LIMIT 1'
		);
		$stmt->execute([$historyId, 'alert_call']);
		$row = $stmt->fetch(PDO::FETCH_ASSOC);

		return is_array($row) ? $row : null;
	}

	private function alertDedupeKey(int $incidentId, string $eventType, int $stageN, string $actionType, ?string $recipient): string {
		$recipientKey = $recipient === null ? '-' : strtolower(trim($recipient));
		return sprintf('incident:%d|event:%s|stage:%d|action:%s|recipient:%s', $incidentId, $eventType, $stageN, $actionType, $recipientKey);
	}

	private function mapDialStatusOutcome(string $dialStatus): string {
		$status = strtoupper(trim($dialStatus));
		if ($status === 'BUSY') {
			return self::ALERT_CALL_OUTCOME_BUSY;
		}
		if ($status === 'NOANSWER') {
			return self::ALERT_CALL_OUTCOME_NO_ANSWER;
		}
		if ($status === 'CHANUNAVAIL') {
			return self::ALERT_CALL_OUTCOME_UNREACHABLE;
		}
		if ($status === 'CONGESTION') {
			return self::ALERT_CALL_OUTCOME_CONGESTION;
		}
		if ($status === 'ANSWER') {
			return self::ALERT_CALL_OUTCOME_ANSWERED_NO_RESPONSE;
		}
		return self::ALERT_CALL_OUTCOME_FAILED;
	}

	private function parseAlertCallDestinations(string $raw): array {
		$entries = $this->parseAlertCallDestinationEntries($raw);
		return array_map(static function (array $entry): string {
			return (string)$entry['destination'];
		}, $entries);
	}

	private function parseAlertCallDestinationEntries(string $raw): array {
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

	private function normaliseEmailRecipients(string $raw): array {
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

	private function aggregateStateSnapshot(string $table, string $whereClause): array {
		$rows = $this->selectRowsSafe(
			'SELECT COUNT(*) AS row_count,
					COALESCE(MAX(updated_at), \'\') AS max_updated_at,
					COALESCE(MAX(id), 0) AS max_id
			 FROM ' . $table . '
			 WHERE ' . $whereClause
		);
		if ($rows === null || !isset($rows[0])) {
			return ['count' => 0, 'max_updated_at' => '', 'max_id' => 0];
		}

		$row = $rows[0];
		return [
			'count' => (int)($row['row_count'] ?? 0),
			'max_updated_at' => (string)($row['max_updated_at'] ?? ''),
			'max_id' => (int)($row['max_id'] ?? 0),
		];
	}

	private function selectRowsSafe(string $sql, array $params = []): ?array {
		try {
			$stmt = $this->pdo->prepare($sql);
			$stmt->execute($params);
			return $stmt->fetchAll(PDO::FETCH_ASSOC);
		} catch (PDOException $e) {
			return null;
		}
	}

	private function activeSubjectKey(int $ruleId, string $subjectKey): string {
		return $ruleId . '|' . $subjectKey;
	}

	private function nullableString($value): ?string {
		if ($value === null) {
			return null;
		}
		$value = (string)$value;
		return $value === '' ? null : $value;
	}

	private function nullableInt($value): ?int {
		if ($value === null || $value === '') {
			return null;
		}
		return (int)$value;
	}

	private function normaliseAlertCallStrategy(string $strategy): string {
		$strategy = strtolower(trim($strategy));
		if ($strategy === 'ordered') {
			return 'ordered';
		}
		return 'ringall';
	}

	private function alertCallKeepTryingFlag(array $payload, bool $default): int {
		if (!array_key_exists('alert_call_keep_trying', $payload)) {
			return $default ? 1 : 0;
		}
		$value = $payload['alert_call_keep_trying'];
		if (is_bool($value)) {
			return $value ? 1 : 0;
		}
		if (is_numeric($value)) {
			return ((int)$value) !== 0 ? 1 : 0;
		}
		if (is_string($value)) {
			$norm = strtolower(trim($value));
			return in_array($norm, ['1', 'true', 'yes', 'on'], true) ? 1 : 0;
		}
		return 0;
	}

	private function isUniqueViolation(PDOException $e): bool {
		$code = (string)$e->getCode();
		if ($code === '23000' || $code === '23505') {
			return true;
		}

		$message = strtolower($e->getMessage());
		return strpos($message, 'unique') !== false || strpos($message, 'duplicate') !== false;
	}

	private function hasColumn(string $table, string $column): bool {
		try {
			$stmt = $this->pdo->query('PRAGMA table_info(' . $table . ')');
			if ($stmt) {
				foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
					if (isset($row['name']) && strtolower((string)$row['name']) === strtolower($column)) {
						return true;
					}
				}
			}
		} catch (\Throwable $e) {
		}

		try {
			$stmt = $this->pdo->prepare(
				"SELECT COUNT(*)
				 FROM information_schema.COLUMNS
				 WHERE TABLE_SCHEMA = DATABASE()
					AND TABLE_NAME = ?
					AND COLUMN_NAME = ?"
			);
			$stmt->execute([$table, $column]);
			return (int)$stmt->fetchColumn() > 0;
		} catch (\Throwable $e) {
			return false;
		}
	}

	private function columnExpr(string $table, string $column, string $fallback): string {
		return $this->hasColumn($table, $column) ? $column : $fallback;
	}
}
<?php

declare(strict_types=1);

namespace FreePBX\modules\Repeatcaller;

use PDO;

final class BackgroundProcessor {
	private PDO $pdo;
	private RepeatCallerRepository $repository;
	private CdrScanner $scanner;
	/** @var callable */
	private $nowProvider;

	public function __construct(PDO $pdo, RepeatCallerRepository $repository, CdrScanner $scanner, ?callable $nowProvider = null) {
		$this->pdo = $pdo;
		$this->repository = $repository;
		$this->scanner = $scanner;
		$this->nowProvider = $nowProvider ?? function (): string {
			return date('Y-m-d H:i:s');
		};
	}

	public function run(array $settings = []): array {
		$enabled = !empty($settings['enabled']) && (string)$settings['enabled'] !== '0';
		if (!$enabled) {
			return [
				'scanned_rows' => 0,
				'scan_row_cap_reached' => false,
				'collapsed_rows' => 0,
				'inbound_journeys' => 0,
				'new_journeys' => 0,
				'incidents_created' => 0,
				'incidents_updated' => 0,
			];
		}

		$rules = $this->hydrateRules($this->repository->loadEnabledRules());
		if (!$rules) {
			return [
				'scanned_rows' => 0,
				'scan_row_cap_reached' => false,
				'collapsed_rows' => 0,
				'inbound_journeys' => 0,
				'new_journeys' => 0,
				'incidents_created' => 0,
				'incidents_updated' => 0,
			];
		}

		$lookbackMinutes = $this->lookbackMinutes($rules);
		$scan = $this->scanner->scanRecentInboundJourneys($lookbackMinutes);
		$newJourneys = $this->reserveNewJourneys($scan['journeys'], (string)($settings['default_country_code'] ?? ''));

		$summary = [
			'scanned_rows' => $scan['raw_rows'],
			'scan_row_cap_reached' => !empty($scan['row_cap_reached']),
			'collapsed_rows' => $scan['collapsed_rows'],
			'inbound_journeys' => $scan['inbound_journeys'],
			'new_journeys' => count($newJourneys),
			'incidents_created' => 0,
			'incidents_updated' => 0,
		];

		foreach ($rules as $rule) {
			if (($rule['mode'] ?? 'repeat') === 'invert') {
				$this->processInvertRule($rule, $newJourneys, $summary, (string)($settings['default_country_code'] ?? ''));
				continue;
			}
			$this->processRepeatRule($rule, $newJourneys, $summary, (string)($settings['default_country_code'] ?? ''));
		}

		return $summary;
	}

	private function processRepeatRule(array $rule, array $newJourneys, array &$summary, string $defaultCountryCode): void {
		foreach ($newJourneys as $journey) {
			$matched = DetectionEngine::matchJourneyToRule($journey, $rule, $defaultCountryCode);
			if ($matched === null) {
				continue;
			}

			$callerSubjectKey = (string)$matched['subject_key'];
			$routeKey = trim((string)($matched['route_key'] ?? ''));
			$subjectKey = $this->repeatRouteSubjectKey($callerSubjectKey, $routeKey);
			$subjectLabel = $this->repeatRouteSubjectLabel(
				$callerSubjectKey,
				$routeKey,
				trim((string)($matched['route_label'] ?? ''))
			);
			$ruleId = (int)$rule['id'];
			$suppressionMinutes = $this->ruleSuppressionMinutes($rule);
			$state = $this->repository->loadSubjectState($ruleId, $subjectKey) ?? [];
			$activeIncident = $this->repository->loadTrackedIncident($ruleId, $subjectKey);
			if ($state === [] && !is_array($activeIncident)) {
				$adopted = $this->repository->adoptLegacyRepeatIdentity(
					$ruleId,
					$callerSubjectKey,
					$subjectKey,
					$subjectLabel,
					(string)$matched['completed_at']
				);
				if ($adopted) {
					$state = $this->repository->loadSubjectState($ruleId, $subjectKey) ?? [];
					$activeIncident = $this->repository->loadTrackedIncident($ruleId, $subjectKey);
				}
			}
			if (is_array($activeIncident)
				&& (string)($activeIncident['state'] ?? '') === 'active'
				&& !empty($activeIncident['suppression_expires_at'])
				&& strtotime((string)$matched['completed_at']) > strtotime((string)$activeIncident['suppression_expires_at'])) {
				$this->repository->suppressIncident($ruleId, $subjectKey, (int)$activeIncident['id'], (string)$activeIncident['suppression_expires_at'], (string)$matched['completed_at']);
				$activeIncident = null;
				$state = $this->repository->loadSubjectState($ruleId, $subjectKey) ?? $state;
			}

			$windowStart = date('Y-m-d H:i:s', strtotime((string)$matched['completed_at']) - (((int)$rule['observation_window_minutes']) * 60));
			$recent = $this->repository->loadRecentSeenCalls($callerSubjectKey, $windowStart, (string)$matched['completed_at']);
			$currentCount = count($this->filterStoredCallsForRule($recent, $rule, (string)$matched['completed_at'], $routeKey));
			$conditionMet = $currentCount >= (int)$rule['threshold_count'];

			$state['current_window_started_at'] = $windowStart;
			$state['current_window_ends_at'] = (string)$matched['completed_at'];
			$state['current_window_call_count'] = $currentCount;
			$state['last_call_at'] = (string)$matched['completed_at'];
			$state['last_evaluated_at'] = (string)$matched['completed_at'];

			if (!$conditionMet) {
				if (!empty($state['threshold_met'])) {
					$state['clear_observed_since_trigger'] = 1;
					$this->repository->markConditionCleared($ruleId, $subjectKey, (string)$matched['completed_at']);
				}
				$state['threshold_met'] = 0;
				$state['active_incident_id'] = null;
				$this->repository->saveSubjectState($ruleId, $subjectKey, $state);
				continue;
			}

			if (is_array($activeIncident)) {
				$this->repository->updateIncidentWithCall((int)$activeIncident['id'], (string)$matched['completed_at'], $currentCount);
				$state['threshold_met'] = 1;
				$state['active_incident_id'] = (int)$activeIncident['id'];
				$state['suppression_expires_at'] = (string)($activeIncident['suppression_expires_at'] ?? '');
				$this->repository->saveSubjectState($ruleId, $subjectKey, $state);
				$summary['incidents_updated']++;
				continue;
			}

			if (!empty($state['threshold_met']) && empty($state['clear_observed_since_trigger'])) {
				$state['threshold_met'] = 1;
				$this->repository->saveSubjectState($ruleId, $subjectKey, $state);
				continue;
			}

			$suppressionExpiresAtForState = trim((string)($state['suppression_expires_at'] ?? ''));
			if ($suppressionExpiresAtForState !== '' && strtotime((string)$matched['completed_at']) <= strtotime($suppressionExpiresAtForState)) {
				$referenceIncident = $this->repository->loadMostRecentIncidentForSubject($ruleId, $subjectKey);
				if (is_array($referenceIncident) && !empty($referenceIncident['id'])) {
					$this->repository->reserveSuppressedIncidentHistory([
						'related_incident_id' => (int)$referenceIncident['id'],
						'rule_id' => $ruleId,
						'rule_name' => (string)($rule['name'] ?? ''),
						'mode' => (string)($rule['mode'] ?? 'repeat'),
						'subject_key' => $subjectKey,
						'subject_label' => $subjectLabel,
						'caller_normalized' => $matched['normalized_caller'] ?? null,
						'caller_display' => $matched['caller_raw'] !== '' ? $matched['caller_raw'] : ($matched['caller_clid'] ?? null),
						'inbound_route_key' => $routeKey !== '' ? $routeKey : null,
						'inbound_route_label' => (string)($matched['route_label'] ?? ''),
						'did_value' => $matched['did_value'] ?? null,
						'matched_call_count' => $currentCount,
						'threshold_count' => (int)$rule['threshold_count'],
						'observation_window_minutes' => (int)$rule['observation_window_minutes'],
						'suppression_source' => isset($rule['suppression_minutes_override']) && $rule['suppression_minutes_override'] !== null && $rule['suppression_minutes_override'] !== '' ? 'rule_override' : 'global_default',
						'suppression_minutes' => $suppressionMinutes,
						'suppression_started_at' => (string)($referenceIncident['created_at'] ?? $referenceIncident['first_matched_at'] ?? $matched['completed_at']),
						'suppression_expires_at' => $suppressionExpiresAtForState,
						'cleared_at' => null,
						'related_incident_state' => (string)($referenceIncident['state'] ?? 'active'),
						'detected_at' => (string)$matched['completed_at'],
						'created_at' => (string)$matched['completed_at'],
						'updated_at' => (string)$matched['completed_at'],
					]);
				}
				$state['threshold_met'] = 1;
				$state['clear_observed_since_trigger'] = 0;
				$state['active_incident_id'] = null;
				$this->repository->saveSubjectState($ruleId, $subjectKey, $state);
				continue;
			}

			$suppressionExpiresAt = date('Y-m-d H:i:s', strtotime((string)$matched['completed_at']) + ($suppressionMinutes * 60));
			$incidentId = $this->repository->createIncident([
				'rule_id' => $ruleId,
				'subject_key' => $subjectKey,
				'subject_label' => $subjectLabel,
				'threshold_count' => (int)$rule['threshold_count'],
				'observation_window_minutes' => (int)$rule['observation_window_minutes'],
				'caller_normalized' => $matched['normalized_caller'] ?? null,
				'caller_display' => $matched['caller_raw'] !== '' ? $matched['caller_raw'] : ($matched['caller_clid'] ?? null),
				'withheld_caller' => !empty($matched['withheld']) ? 1 : 0,
				'mode' => 'repeat',
				'first_matched_at' => (string)$matched['completed_at'],
				'last_matched_at' => (string)$matched['completed_at'],
				'matched_call_count' => $currentCount,
				'state' => 'active',
				'suppression_expires_at' => $suppressionExpiresAt,
				'created_at' => (string)$matched['completed_at'],
				'updated_at' => (string)$matched['completed_at'],
			]);

			$state['threshold_met'] = 1;
			$state['clear_observed_since_trigger'] = 0;
			$state['active_incident_id'] = $incidentId;
			$state['suppression_expires_at'] = $suppressionExpiresAt;
			$this->repository->saveSubjectState($ruleId, $subjectKey, $state);
			$summary['incidents_created']++;
		}
	}

	private function processInvertRule(array $rule, array $newJourneys, array &$summary, string $defaultCountryCode): void {
		$subjects = $this->invertSubjects($rule);
		if (!$subjects) {
			return;
		}

		$now = $this->now();
		$suppressionMinutes = $this->ruleSuppressionMinutes($rule);
		foreach ($subjects as $subject) {
			$state = $this->repository->loadSubjectState((int)$rule['id'], $subject) ?? [];
			$currentWindowStart = $state['current_window_started_at'] ?? $this->latestScheduleAnchor($rule['schedules'], $now);
			if ($currentWindowStart === null) {
				continue;
			}

			$currentCount = 0;
			$currentWindowEnd = date('Y-m-d H:i:s', strtotime($currentWindowStart) + (((int)$rule['observation_window_minutes']) * 60));
			while (strtotime($now) >= strtotime($currentWindowEnd)) {
				$recent = $subject === $this->invertAggregateSubject((int)$rule['id'])
					? $this->repository->loadRecentSeenCallsInWindow($currentWindowStart, $currentWindowEnd)
					: $this->repository->loadRecentSeenCalls($subject, $currentWindowStart, $currentWindowEnd);
				$filtered = $this->filterStoredCallsForRule($recent, $rule, $currentWindowEnd);
				$currentCount = count($filtered);
				$conditionMet = $currentCount >= (int)$rule['threshold_count'];

				if ($conditionMet) {
					$state['threshold_met'] = 1;
					$state['clear_observed_since_trigger'] = 1;
					$state['active_incident_id'] = null;
					$this->repository->markConditionCleared((int)$rule['id'], $subject, $currentWindowEnd);
				} else {
					$activeIncident = $this->repository->loadTrackedIncident((int)$rule['id'], $subject);
					if (!is_array($activeIncident) && (empty($state['threshold_met']) || !empty($state['clear_observed_since_trigger']))) {
						$suppressionExpiresAt = date('Y-m-d H:i:s', strtotime($currentWindowEnd) + ($suppressionMinutes * 60));
						$subjectLabel = $subject === $this->invertAggregateSubject((int)$rule['id']) ? 'Any caller' : $subject;
						$incidentId = $this->repository->createIncident([
							'rule_id' => (int)$rule['id'],
							'subject_key' => $subject,
							'subject_label' => $subjectLabel,
							'threshold_count' => (int)$rule['threshold_count'],
							'observation_window_minutes' => (int)$rule['observation_window_minutes'],
							'caller_normalized' => $subject === 'withheld' || $subject === $this->invertAggregateSubject((int)$rule['id']) ? null : $subject,
							'caller_display' => $subjectLabel,
							'withheld_caller' => $subject === 'withheld' ? 1 : 0,
							'mode' => 'invert',
							'first_matched_at' => $currentWindowEnd,
							'last_matched_at' => $currentWindowEnd,
							'matched_call_count' => $currentCount,
							'state' => 'active',
							'suppression_expires_at' => $suppressionExpiresAt,
							'created_at' => $currentWindowEnd,
							'updated_at' => $currentWindowEnd,
						]);
						$state['active_incident_id'] = $incidentId;
						$state['suppression_expires_at'] = $suppressionExpiresAt;
						$summary['incidents_created']++;
					}
					$state['threshold_met'] = 0;
					$state['clear_observed_since_trigger'] = 0;
				}

				$currentWindowStart = $currentWindowEnd;
				$currentWindowEnd = date('Y-m-d H:i:s', strtotime($currentWindowStart) + (((int)$rule['observation_window_minutes']) * 60));
			}

			$state['current_window_started_at'] = $currentWindowStart;
			$state['current_window_ends_at'] = $currentWindowEnd;
			$state['current_window_call_count'] = $currentCount;
			$state['last_evaluated_at'] = $now;
			$this->repository->saveSubjectState((int)$rule['id'], $subject, $state);
		}
	}

	private function hydrateRules(array $rules): array {
		if (!$rules) {
			return [];
		}
		$ids = array_map(function ($rule) {
			return (int)$rule['id'];
		}, $rules);
		$schedules = $this->repository->loadSchedules($ids);
		$callers = $this->repository->loadCallerLists($ids);
		$dids = $this->repository->loadDidLists($ids);

		foreach ($rules as &$rule) {
			$ruleId = (int)$rule['id'];
			$rule['schedules'] = $schedules[$ruleId] ?? [];
			$rule['include_callers'] = array_values(array_map(function ($row) {
				return (string)$row['normalized_value'];
			}, $callers[$ruleId]['include'] ?? []));
			$rule['exclude_callers'] = array_values(array_map(function ($row) {
				return (string)$row['normalized_value'];
			}, $callers[$ruleId]['exclude'] ?? []));
			$rule['include_routes'] = array_values(array_map(function ($row) {
				return (string)$row['route_key'];
			}, $dids[$ruleId]['include'] ?? []));
			$rule['exclude_routes'] = array_values(array_map(function ($row) {
				return (string)$row['route_key'];
			}, $dids[$ruleId]['exclude'] ?? []));
		}
		unset($rule);

		return $rules;
	}

	private function reserveNewJourneys(array $journeys, string $defaultCountryCode): array {
		$newJourneys = [];
		foreach ($journeys as $journey) {
			$withheld = DetectionEngine::isWithheldCaller($journey['caller_raw'] ?? '') && DetectionEngine::isWithheldCaller($journey['caller_clid'] ?? '');
			$callerNormalized = $withheld
				? 'withheld'
				: DetectionEngine::normaliseCaller(($journey['caller_raw'] ?? '') !== '' ? (string)$journey['caller_raw'] : (string)($journey['caller_clid'] ?? ''), $defaultCountryCode);
			if (!$withheld && ($callerNormalized === null || $callerNormalized === '')) {
				continue;
			}

			$journey['caller_normalized'] = $callerNormalized;
			if ($this->repository->reserveSeenCallJourney($journey)) {
				$newJourneys[] = $journey;
			}
		}

		return $newJourneys;
	}

	private function filterStoredCallsForRule(array $rows, array $rule, string $asOf, ?string $routeScopeKey = null): array {
		$matched = [];
		$routeScopeKey = trim((string)$routeScopeKey);
		foreach ($rows as $row) {
			$journey = [
				'caller_raw' => (string)($row['caller_raw'] ?? ''),
				'caller_clid' => (string)($row['caller_raw'] ?? ''),
				'route_key' => (string)($row['inbound_route_key'] ?? ''),
				'did_value' => (string)($row['did_value'] ?? ''),
				'completed_at' => (string)($row['call_completed_at'] ?? $asOf),
				'dcontext' => (string)($row['source_context'] ?? ''),
				'disposition' => (string)($row['disposition'] ?? ''),
			];
			if (!DetectionEngine::callInActiveSchedule($journey['completed_at'], $rule['schedules'])) {
				continue;
			}
			$route = (string)$journey['route_key'];
			if (($rule['did_scope_mode'] ?? 'all') === 'selected' && !in_array($route, $rule['include_routes'] ?? [], true)) {
				continue;
			}
			if (in_array($route, $rule['exclude_routes'] ?? [], true)) {
				continue;
			}
			if ($routeScopeKey !== '' && $route !== $routeScopeKey) {
				continue;
			}
			$matched[] = $row;
		}

		return $matched;
	}

	private function repeatRouteSubjectKey(string $callerSubjectKey, string $routeKey): string {
		$routeKey = trim($routeKey);
		if ($routeKey === '') {
			$routeKey = '__unknown_route__';
		}
		return $callerSubjectKey . '|route:' . hash('sha1', $routeKey);
	}

	private function repeatRouteSubjectLabel(string $callerSubjectKey, string $routeKey, string $routeLabel): string {
		$route = $routeLabel !== '' ? $routeLabel : trim($routeKey);
		if ($route === '') {
			$route = 'unknown route';
		}
		return $callerSubjectKey . ' @ ' . $route;
	}

	private function invertSubjects(array $rule): array {
		if (($rule['caller_mode'] ?? 'any') === 'specific_only') {
			return array_values($rule['include_callers'] ?? []);
		}
		if (($rule['caller_mode'] ?? 'any') === 'withheld_only') {
			return ['withheld'];
		}

		return [$this->invertAggregateSubject((int)$rule['id'])];
	}

	private function invertAggregateSubject(int $ruleId): string {
		return '__invert_rule__' . $ruleId;
	}

	private function latestScheduleAnchor(array $schedules, string $now): ?string {
		$nowTs = strtotime($now);
		if ($nowTs === false || !$schedules) {
			return null;
		}

		$latest = null;
		for ($offset = 0; $offset <= 7; $offset++) {
			$dayTs = strtotime('-' . $offset . ' day', $nowTs);
			$day = (int)date('w', $dayTs);
			foreach ($schedules as $period) {
				$periodDay = isset($period['day']) ? (int)$period['day'] : (isset($period['day_of_week']) ? (int)$period['day_of_week'] : null);
				if ($periodDay === null) {
					continue;
				}
				if ($periodDay !== -1 && $periodDay !== $day) {
					continue;
				}
				$startRaw = isset($period['start']) ? (string)$period['start'] : (isset($period['start_time']) ? (string)$period['start_time'] : '');
				$start = substr(trim($startRaw), 0, 5);
				if (!preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $start)) {
					continue;
				}
				$candidate = date('Y-m-d', $dayTs) . ' ' . $start . ':00';
				if (strtotime($candidate) !== false && strtotime($candidate) <= $nowTs) {
					if ($latest === null || strtotime($candidate) > strtotime($latest)) {
						$latest = $candidate;
					}
				}
			}
		}

		return $latest;
	}

	private function ruleSuppressionMinutes(array $rule): int {
		$value = isset($rule['suppression_minutes_override']) && $rule['suppression_minutes_override'] !== null && $rule['suppression_minutes_override'] !== ''
			? (int)$rule['suppression_minutes_override']
			: 1440;
		return max(0, $value);
	}

	private function lookbackMinutes(array $rules): int {
		$maxWindow = 60;
		foreach ($rules as $rule) {
			$maxWindow = max($maxWindow, (int)($rule['observation_window_minutes'] ?? 60));
		}
		$derived = min(10080, max(180, $maxWindow + 180));
		return $derived;
	}

	private function now(): string {
		return (string)call_user_func($this->nowProvider);
	}
}
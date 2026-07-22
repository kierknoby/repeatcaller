<?php

declare(strict_types=1);

namespace FreePBX\modules\Repeatcaller;

final class DetectionEngine {
	public static function isWithheldCaller($value): bool {
		$value = strtolower(trim((string)$value));
		return in_array($value, ['', 'anonymous', 'withheld', 'unavailable', 'private', 'unknown'], true);
	}

	public static function normaliseCaller($value, ?string $defaultCountryCode): ?string {
		$value = trim((string)$value);
		if (self::isWithheldCaller($value)) {
			return null;
		}

		$defaultCountryCode = trim((string)$defaultCountryCode);
		$hasPlus = strpos($value, '+') === 0;
		$digits = preg_replace('/\D+/', '', $value) ?? '';
		if ($digits === '') {
			return null;
		}

		if ($hasPlus) {
			return '+' . $digits;
		}

		if ($defaultCountryCode !== '') {
			$countryDigits = preg_replace('/\D+/', '', $defaultCountryCode) ?? '';
			if ($countryDigits !== '') {
				if (strpos($digits, '00') === 0) {
					return '+' . substr($digits, 2);
				}
				if (strpos($digits, $countryDigits) === 0) {
					return '+' . $digits;
				}
				if ($digits[0] === '0') {
					return '+' . $countryDigits . substr($digits, 1);
				}
			}
		}

		return $digits;
	}

	public static function conservativeCallFingerprint(array $row): string {
		$fields = [
			(string)($row['calldate'] ?? ''),
			(string)($row['src'] ?? ''),
			(string)($row['dst'] ?? ''),
			(string)($row['dcontext'] ?? ''),
			(string)($row['clid'] ?? ''),
			(string)($row['duration'] ?? ''),
			(string)($row['billsec'] ?? ''),
		];

		return hash('sha256', implode("\0", $fields));
	}

	public static function selectCallIdentity(array $row): array {
		$linkedId = trim((string)($row['linkedid'] ?? ''));
		if ($linkedId !== '') {
			return ['identity' => $linkedId, 'type' => 'linkedid'];
		}

		$uniqueId = trim((string)($row['uniqueid'] ?? ''));
		if ($uniqueId !== '') {
			return ['identity' => $uniqueId, 'type' => 'uniqueid'];
		}

		return ['identity' => self::conservativeCallFingerprint($row), 'type' => 'fingerprint'];
	}

	public static function routeKey(array $row): string {
		$routeKey = trim((string)($row['route_key'] ?? ''));
		if ($routeKey !== '') {
			return $routeKey;
		}

		$did = trim((string)($row['did'] ?? $row['dst'] ?? ''));
		$cid = trim((string)($row['cid'] ?? ''));
		return $did . '|' . $cid;
	}

	public static function collapseCallJourneys(array $rows): array {
		$grouped = [];
		foreach ($rows as $row) {
			$identity = self::selectCallIdentity($row);
			$key = $identity['identity'];
			if (!isset($grouped[$key])) {
				$grouped[$key] = [
					'identity' => $key,
					'identity_type' => $identity['type'],
					'rows' => [],
				];
			}
			$grouped[$key]['rows'][] = $row;
		}

		$journeys = [];
		foreach ($grouped as $group) {
			usort($group['rows'], function ($left, $right) {
				return self::timestamp((string)$left['calldate']) <=> self::timestamp((string)$right['calldate']);
			});
			$representative = $group['rows'][0];
			foreach ($group['rows'] as $row) {
				foreach (['src', 'clid', 'dst', 'dcontext', 'did', 'route_key'] as $field) {
					if (trim((string)($representative[$field] ?? '')) === '' && trim((string)($row[$field] ?? '')) !== '') {
						$representative[$field] = $row[$field];
					}
				}
			}

			$journeys[] = [
				'call_identity' => $group['identity'],
				'identity_type' => $group['identity_type'],
				'row_count' => count($group['rows']),
				'caller_raw' => (string)($representative['src'] ?? ''),
				'caller_clid' => (string)($representative['clid'] ?? ''),
				'did_value' => trim((string)($representative['did'] ?? $representative['dst'] ?? '')),
				'route_key' => self::routeKey($representative),
				'completed_at' => (string)$representative['calldate'],
				'dcontext' => (string)($representative['dcontext'] ?? ''),
				'disposition' => (string)($representative['disposition'] ?? ''),
			];
		}

		usort($journeys, function ($left, $right) {
			return self::timestamp($left['completed_at']) <=> self::timestamp($right['completed_at']);
		});

		return $journeys;
	}

	public static function callInActiveSchedule(string $dateTime, array $periods): bool {
		foreach ($periods as $period) {
			if (self::timeInPeriod($dateTime, $period)) {
				return true;
			}
		}

		return false;
	}

	public static function matchJourneyToRule(array $journey, array $rule, ?string $defaultCountryCode): ?array {
		if (empty($rule['enabled'])) {
			return null;
		}
		if (!self::callInActiveSchedule((string)$journey['completed_at'], $rule['schedules'])) {
			return null;
		}

		$withheld = self::isWithheldCaller($journey['caller_raw']) && self::isWithheldCaller($journey['caller_clid']);
		$normalizedCaller = $withheld
			? null
			: self::normaliseCaller($journey['caller_raw'] !== '' ? $journey['caller_raw'] : $journey['caller_clid'], $defaultCountryCode);

		if ($withheld) {
			if (($rule['caller_mode'] ?? 'any') !== 'withheld_only') {
				return null;
			}
			if (!empty($rule['exclude_withheld'])) {
				return null;
			}
			$subjectKey = 'withheld';
		} else {
			if ($normalizedCaller === null || $normalizedCaller === '') {
				return null;
			}

			$includeCallers = $rule['include_callers'] ?? [];
			$excludeCallers = $rule['exclude_callers'] ?? [];
			switch ($rule['caller_mode'] ?? 'any') {
				case 'specific_only':
					if (!in_array($normalizedCaller, $includeCallers, true)) {
						return null;
					}
					break;
				case 'withheld_only':
					return null;
				case 'any':
				default:
					break;
			}

			if (in_array($normalizedCaller, $excludeCallers, true)) {
				return null;
			}

			$subjectKey = $normalizedCaller;
		}

		$route = (string)$journey['route_key'];
		if (($rule['did_scope_mode'] ?? 'all') === 'selected') {
			if (!in_array($route, $rule['include_routes'] ?? [], true)) {
				return null;
			}
		}
		if (in_array($route, $rule['exclude_routes'] ?? [], true)) {
			return null;
		}

		$journey['withheld'] = $withheld;
		$journey['normalized_caller'] = $normalizedCaller;
		$journey['subject_key'] = $subjectKey;
		return $journey;
	}

	public static function filterJourneys(array $rows, array $rule, ?string $defaultCountryCode): array {
		$journeys = [];
		foreach (self::collapseCallJourneys($rows) as $journey) {
			$matched = self::matchJourneyToRule($journey, $rule, $defaultCountryCode);
			if ($matched !== null) {
				$journeys[] = $matched;
			}
		}

		usort($journeys, function ($left, $right) {
			return self::timestamp($left['completed_at']) <=> self::timestamp($right['completed_at']);
		});

		return $journeys;
	}

	public static function evaluateRepeat(array $rows, array $rule, ?string $defaultCountryCode): array {
		$journeys = self::filterJourneys($rows, $rule, $defaultCountryCode);
		$history = [];
		$subjectStates = [];
		$incidents = [];
		$incidentId = 0;

		foreach ($journeys as $journey) {
			$subject = $journey['subject_key'];
			$history[$subject][] = $journey;
			$windowStart = self::timestamp($journey['completed_at']) - ((int)$rule['observation_window_minutes'] * 60);
			$windowCalls = array_values(array_filter($history[$subject], function ($item) use ($windowStart, $journey) {
				$ts = self::timestamp($item['completed_at']);
				return $ts >= $windowStart && $ts <= self::timestamp($journey['completed_at']);
			}));
			$currentCount = count($windowCalls);
			$conditionMet = $currentCount >= (int)$rule['threshold_count'];

			if (!isset($subjectStates[$subject])) {
				$subjectStates[$subject] = [
					'active_incident_id' => null,
					'rearm_required' => false,
					'clear_observed' => false,
				];
			}
			$state = &$subjectStates[$subject];

			if ($state['active_incident_id'] !== null) {
				$activeIncident = &$incidents[$state['active_incident_id']];
				if ($activeIncident['state'] === 'active' && self::timestamp($journey['completed_at']) > self::timestamp($activeIncident['suppression_expires_at'])) {
					$activeIncident['state'] = 'expired';
					$state['active_incident_id'] = null;
				}
			}

			if (!$conditionMet) {
				if ($state['rearm_required']) {
					$state['clear_observed'] = true;
				}
				unset($state);
				continue;
			}

			if ($state['active_incident_id'] !== null) {
				$activeIncident = &$incidents[$state['active_incident_id']];
				$activeIncident['matched_call_count'] = $currentCount;
				$activeIncident['last_matched_at'] = $journey['completed_at'];
				unset($activeIncident, $state);
				continue;
			}

			if ($state['rearm_required'] && !$state['clear_observed']) {
				unset($state);
				continue;
			}

			$incidentId++;
			$incidents[$incidentId] = [
				'id' => $incidentId,
				'subject_key' => $subject,
				'caller_normalized' => $journey['normalized_caller'],
				'withheld' => $journey['withheld'],
				'state' => 'active',
				'first_matched_at' => $journey['completed_at'],
				'last_matched_at' => $journey['completed_at'],
				'matched_call_count' => $currentCount,
				'suppression_expires_at' => date('Y-m-d H:i:s', self::timestamp($journey['completed_at']) + ((int)$rule['suppression_minutes'] * 60)),
			];
			$state['active_incident_id'] = $incidentId;
			$state['rearm_required'] = true;
			$state['clear_observed'] = false;
			unset($state);
		}

		return [
			'journeys' => $journeys,
			'incidents' => array_values($incidents),
			'subject_states' => $subjectStates,
		];
	}

	public static function evaluateInvert(array $rows, array $rule, ?string $defaultCountryCode, array $subjects, string $windowStartedAt, string $asOf): array {
		$windowStartTs = self::timestamp($windowStartedAt);
		$windowEndTs = $windowStartTs + ((int)$rule['observation_window_minutes'] * 60);
		if (self::timestamp($asOf) < $windowEndTs) {
			return ['evaluated' => false, 'incidents' => []];
		}

		$journeys = self::filterJourneys($rows, $rule, $defaultCountryCode);
		$incidents = [];
		$id = 0;
		foreach ($subjects as $subject) {
			$matching = array_values(array_filter($journeys, function ($journey) use ($subject, $windowStartTs, $windowEndTs) {
				$ts = self::timestamp($journey['completed_at']);
				return $journey['subject_key'] === $subject && $ts >= $windowStartTs && $ts < $windowEndTs;
			}));
			if (count($matching) >= (int)$rule['threshold_count']) {
				continue;
			}

			$id++;
			$incidents[] = [
				'id' => $id,
				'subject_key' => $subject,
				'window_started_at' => $windowStartedAt,
				'window_ended_at' => date('Y-m-d H:i:s', $windowEndTs),
				'matched_call_count' => count($matching),
				'state' => 'active',
			];
		}

		return ['evaluated' => true, 'incidents' => $incidents];
	}

	private static function timeInPeriod(string $dateTime, array $period): bool {
		$timestamp = self::timestamp($dateTime);
		$dayOfWeek = (int)date('w', $timestamp);
		$periodDayRaw = $period['day'] ?? $period['day_of_week'] ?? null;
		$periodDay = is_numeric($periodDayRaw) ? (int)$periodDayRaw : -2;
		if ($periodDay !== -1 && $dayOfWeek !== $periodDay) {
			return false;
		}

		$start = substr(trim((string)($period['start'] ?? $period['start_time'] ?? '')), 0, 5);
		$end = substr(trim((string)($period['end'] ?? $period['end_time'] ?? '')), 0, 5);
		$allDay = !empty($period['all_day']) || ($start === '00:00' && ($end === '24:00' || $end === ''));
		if ($allDay) {
			return true;
		}
		if ($start === '' || $end === '') {
			return false;
		}

		$time = date('H:i', $timestamp);
		return $time >= $start && $time < $end;
	}

	private static function timestamp(string $value): int {
		$timestamp = strtotime($value);
		if ($timestamp === false) {
			throw new \RuntimeException('Invalid datetime value: ' . $value);
		}

		return $timestamp;
	}
}
<?php
/**
 * Repeat Caller for FreePBX.
 *
 * Repeated inbound caller detection, incidents, and alerting.
 *
 * @copyright 2026 20 Telecom Ltd (trading as 20tele.com)
 * @license   GPLv3+
 */

namespace FreePBX\modules;

class Repeatcaller implements \BMO {

	/** Fallback only. Authoritative version lives in module.xml. */
	const VERSION = '1.0.0';
	const CSRF_SESSION_KEY = 'repeatcaller_csrf_token';
	const REPEAT_MODE_NEVER = 'never';
	const REPEAT_MODE_FIVE_MINUTES = '5m';
	const REPEAT_MODE_HOURLY = 'hourly';
	const REPEAT_MODE_DAILY = 'daily';
	const REPEAT_MODE_ESCALATING = 'escalating';
	const REPEAT_MODE_FIBONACCI = 'fibonacci';
	const AJAX_COMMANDS = [
		'getenginestatus',
		'runmonitor',
		'saveglobalsettings',
		'getrules',
		'getrule',
		'getsuppressedincidents',
		'saverule',
		'deleterule',
		'setruleenabled',
		'getinboundroutes',
		'getincidents',
		'claimincident',
		'getalerthistory',
		'clearsuppression',
		'getuichangetoken',
		'setsnooze',
		'resumemonitoring',
		'prunehistory',
		'clearalerthistory',
	];

	private $settingsDefaults = [
		'enabled' => '0',
		'default_country_code' => '',
		'engine_last_success_at' => '',
		'engine_last_summary_json' => '',
		'global_snoozed_until' => '',
		'global_snooze_selected_seconds' => '',
		'incident_history_prune_policy' => 'daily',
		'alert_history_prune_policy' => 'daily',
		'suppression_history_prune_policy' => 'daily',
	];

	/** @var \FreePBX */
	private $FreePBX;

	public function __construct($freepbx = null) {
		if ($freepbx === null) {
			throw new \Exception('Not given a FreePBX Object');
		}
		$this->FreePBX = $freepbx;
	}

	public function install(): void {
		require_once __DIR__ . '/src/Schema.php';
		try {
			\FreePBX\modules\Repeatcaller\Schema::install($this->db());
		} catch (\Throwable $e) {
			// Per the BMO install() contract, an exception thrown here means the
			// module is NOT marked as installed. Log the detail server-side and
			// let it propagate rather than reporting a false success with a
			// missing schema.
			$this->logError('Installation failed: ' . $e->getMessage());
			throw $e;
		}
	}
	public function uninstall(): void {}
	public function doConfigPageInit($page): void {}

	public function backup(): array {
		$backup = [
			'settings' => [],
			'rules' => [],
		];

		try {
			$stmt = $this->db()->query('SELECT setting_key, setting_value, updated_at FROM repeatcaller_settings');
			if ($stmt) {
				$backup['settings'] = $stmt->fetchAll(\PDO::FETCH_ASSOC);
			}
			$repository = $this->rcRepository();
			foreach ($repository->loadRulesSummary() as $rule) {
				$loaded = $repository->loadRule((int)$rule['id']);
				if (is_array($loaded)) {
					$backup['rules'][] = $loaded;
				}
			}
		} catch (\Throwable $e) {
			$this->logError('Backup failed: ' . $e->getMessage());
		}

		return $backup;
	}

	public function restore($backup): void {
		if (!is_array($backup) || !$backup) {
			return;
		}

		try {
			if (!empty($backup['settings']) && is_array($backup['settings'])) {
				foreach ($backup['settings'] as $row) {
					if (isset($row['setting_key']) && array_key_exists((string)$row['setting_key'], $this->settingsDefaults)) {
						$this->setSetting((string)$row['setting_key'], (string)($row['setting_value'] ?? ''));
					}
				}
			}
			if (!empty($backup['rules']) && is_array($backup['rules'])) {
				$repository = $this->rcRepository();
				foreach ($backup['rules'] as $rule) {
					if (!is_array($rule) || empty($rule['name'])) {
						continue;
					}
					$repository->saveRule([
						'name' => (string)$rule['name'],
						'enabled' => !empty($rule['enabled']) ? 1 : 0,
						'email_enabled' => !empty($rule['email_enabled']) ? 1 : 0,
						'email_recipients' => (string)($rule['email_recipients'] ?? ''),
						'alert_call_enabled' => !empty($rule['alert_call_enabled']) ? 1 : 0,
						'alert_call_destinations' => (string)($rule['alert_call_destinations'] ?? ''),
						'alert_call_strategy' => $this->normaliseAlertCallStrategy((string)($rule['alert_call_strategy'] ?? 'ringall')),
						'alert_call_keep_trying' => isset($rule['alert_call_keep_trying']) ? (!empty($rule['alert_call_keep_trying']) ? 1 : 0) : 1,
						'alert_call_recording_id' => $rule['alert_call_recording_id'] !== null && $rule['alert_call_recording_id'] !== ''
							? (int)$rule['alert_call_recording_id']
							: null,
						'alert_call_callerid' => (string)($rule['alert_call_callerid'] ?? ''),
						'mode' => (string)($rule['mode'] ?? 'repeat'),
						'threshold_count' => (int)($rule['threshold_count'] ?? 2),
						'observation_window_minutes' => (int)($rule['observation_window_minutes'] ?? 60),
						'caller_mode' => (string)($rule['caller_mode'] ?? 'any'),
						'exclude_withheld' => !empty($rule['exclude_withheld']) ? 1 : 0,
						'did_scope_mode' => (string)($rule['did_scope_mode'] ?? 'all'),
						'repeat_mode_override' => (string)($rule['repeat_mode_override'] ?? ''),
						'suppression_minutes_override' => $rule['suppression_minutes_override'] !== null && $rule['suppression_minutes_override'] !== ''
							? (int)$rule['suppression_minutes_override']
							: null,
						'schedules' => $rule['schedules'] ?? [],
						'callers' => array_merge(
							array_map(function ($row) {
								return ['list_type' => 'include', 'raw_value' => (string)$row['raw_value'], 'normalized_value' => (string)$row['normalized_value']];
							}, $rule['caller_lists']['include'] ?? []),
							array_map(function ($row) {
								return ['list_type' => 'exclude', 'raw_value' => (string)$row['raw_value'], 'normalized_value' => (string)$row['normalized_value']];
							}, $rule['caller_lists']['exclude'] ?? [])
						),
						'dids' => array_merge(
							array_map(function ($row) {
								return ['list_type' => 'include', 'route_key' => (string)$row['route_key'], 'route_label' => (string)$row['route_label'], 'did_value' => $row['did_value'], 'cid_value' => $row['cid_value']];
							}, $rule['did_lists']['include'] ?? []),
							array_map(function ($row) {
								return ['list_type' => 'exclude', 'route_key' => (string)$row['route_key'], 'route_label' => (string)$row['route_label'], 'did_value' => $row['did_value'], 'cid_value' => $row['cid_value']];
							}, $rule['did_lists']['exclude'] ?? [])
						),
					], $this->now());
				}
			}
		} catch (\Throwable $e) {
			$this->logError('Restore failed: ' . $e->getMessage());
		}
	}

	public function getVersion(): string {
		try {
			$info = \FreePBX::Modules()->getInfo('repeatcaller');
			if (isset($info['repeatcaller']['version'])) {
				return (string)$info['repeatcaller']['version'];
			}
		} catch (\Throwable $e) {
		}

		return self::VERSION;
	}

	public function showPage(): string {
		if (!$this->isSchemaReady()) {
			return '<div class="alert alert-danger">'
				. htmlspecialchars(_('Repeat Caller database tables are missing. Reinstall or repair the module.'), ENT_QUOTES, 'UTF-8')
				. '</div>';
		}

		$data = $this->rcBuildPageData();

		return load_view(__DIR__ . '/views/main.php', [
			'moduleVersion' => $this->getVersion(),
			'engineStatus' => $data['engineStatus'],
			'globalSettings' => $data['globalSettings'],
			'rules' => $data['rules'],
			'activeIncidents' => $data['activeIncidents'],
			'recentIncidents' => $data['recentIncidents'],
			'alertHistory' => $data['alertHistory'],
			'inboundRoutes' => $data['inboundRoutes'],
			'systemRecordings' => $data['systemRecordings'],
			'csrfToken' => $this->createCsrfToken(),
		]);
	}

	public function ajaxRequest($req, &$setting): bool {
		return in_array((string)$req, self::AJAX_COMMANDS, true);
	}

	public function ajaxHandler(): array {
		if (!$this->validateCsrfToken()) {
			return ['status' => false, 'message' => _('Invalid security token. Please reload the page and try again.')];
		}

		$command = isset($_REQUEST['command']) ? (string)$_REQUEST['command'] : '';
		try {
			switch ($command) {
				case 'getenginestatus': return $this->rcHandleGetEngineStatus();
				case 'runmonitor': return $this->rcHandleRunMonitor();
				case 'saveglobalsettings': return $this->rcHandleSaveGlobalSettings();
				case 'getrules': return $this->rcHandleGetRules();
				case 'getrule': return $this->rcHandleGetRule();
				case 'saverule': return $this->rcHandleSaveRule();
				case 'deleterule': return $this->rcHandleDeleteRule();
				case 'setruleenabled': return $this->rcHandleSetRuleEnabled();
				case 'getinboundroutes': return $this->rcHandleGetInboundRoutes();
				case 'getincidents': return $this->rcHandleGetIncidents();
				case 'getsuppressedincidents': return $this->rcHandleGetSuppressedIncidents();
				case 'claimincident': return $this->rcHandleClaimIncident();
				case 'getalerthistory': return $this->rcHandleGetAlertHistory();
				case 'clearsuppression': return $this->rcHandleClearSuppression();
				case 'getuichangetoken': return $this->rcHandleGetUiChangeToken();
				case 'setsnooze': return $this->rcHandleSetSnooze();
				case 'resumemonitoring': return $this->rcHandleResumeMonitoring();
				case 'prunehistory': return $this->rcHandlePruneHistory();
				case 'clearalerthistory': return $this->rcHandleClearAlertHistory();
			}
		} catch (\Throwable $e) {
			$this->logError('AJAX command "' . $command . '" failed: ' . $e->getMessage());
			return ['status' => false, 'message' => _('An internal error occurred. Please check the system logs.')];
		}

		return ['status' => false, 'message' => _('Unknown command')];
	}

	public function runBackgroundMonitor($output = null): bool {
		$result = $this->runBackgroundMonitorDetailed($output);
		return !empty($result['status']);
	}

	private function runBackgroundMonitorDetailed($output = null): array {
		try {
			require_once __DIR__ . '/src/DetectionEngine.php';
			require_once __DIR__ . '/src/RepeatCallerRepository.php';
			require_once __DIR__ . '/src/CdrScanner.php';
			require_once __DIR__ . '/src/BackgroundProcessor.php';
			require_once __DIR__ . '/src/IncidentAlertProcessor.php';

			$settings = $this->rcSettings();
			if (($settings['enabled'] ?? '0') !== '1') {
				return ['status' => true, 'ran' => false, 'already_running' => false, 'message' => 'disabled'];
			}

			if (!$this->acquireReconcileLock()) {
				return ['status' => true, 'ran' => false, 'already_running' => true, 'message' => 'already_running'];
			}

			try {
				$pdo = $this->db();
				$repository = new \FreePBX\modules\Repeatcaller\RepeatCallerRepository($pdo);
				$scanner = new \FreePBX\modules\Repeatcaller\CdrScanner($pdo);
				$runtime = new \FreePBX\modules\Repeatcaller\BackgroundProcessor(
					$pdo,
					$repository,
					$scanner,
					function () { return $this->now(); }
				);
				$alerts = new \FreePBX\modules\Repeatcaller\IncidentAlertProcessor(
					$repository,
					[$this, 'sendEmail'],
					function () { return $this->now(); },
					[$this, 'sendAlertCall']
				);
				$runtimeSummary = $runtime->run($settings);
				$alertSummary = $alerts->run($settings);
				$this->setSetting('engine_last_success_at', $this->now());
				$this->setSetting('engine_last_summary_json', json_encode([
					'runtime' => $runtimeSummary,
					'alerts' => $alertSummary,
				]));

				return [
					'status' => true,
					'ran' => true,
					'already_running' => false,
					'runtime_summary' => $runtimeSummary,
					'alert_summary' => $alertSummary,
				];
			} finally {
				$this->releaseReconcileLock();
			}
		} catch (\Throwable $e) {
			$this->logError('Background job failed: ' . $e->getMessage());
			return ['status' => false, 'ran' => false, 'already_running' => false, 'message' => 'failed'];
		}
	}

	private function isSchemaReady(): bool {
		require_once __DIR__ . '/src/Schema.php';
		try {
			return \FreePBX\modules\Repeatcaller\Schema::isReady($this->db());
		} catch (\Throwable $e) {
			$this->logError('Schema readiness check failed: ' . $e->getMessage());
			return false;
		}
	}

	private function rcBuildPageData(): array {
		$repository = $this->rcRepository();
		$settings = $this->rcSettings();

		return [
			'engineStatus' => $this->rcEngineStatus($settings),
			'globalSettings' => $settings,
			'rules' => $repository->loadRulesSummary(),
			'activeIncidents' => $repository->loadIncidents('active', 100),
			'recentIncidents' => $repository->loadIncidents('claimed', 200),
			'alertHistory' => $repository->loadIncidentAlertHistory(200),
			'inboundRoutes' => $repository->loadInboundRoutes(),
			'systemRecordings' => $this->loadSystemRecordingsForEditor(),
		];
	}

	private function loadSystemRecordingsForEditor(): array {
		$items = [];
		$rows = $this->loadSystemRecordingRowsFromApi();
		foreach ($rows as $row) {
			if (!is_array($row)) {
				continue;
			}
			$id = isset($row['id']) ? (int)$row['id'] : 0;
			if ($id <= 0) {
				continue;
			}
			$name = trim((string)($row['displayname'] ?? ''));
			if ($name === '') {
				continue;
			}
			$items[$id] = ['id' => $id, 'name' => $name];
		}

		uasort($items, function (array $a, array $b): int {
			$byName = strnatcasecmp((string)$a['name'], (string)$b['name']);
			if ($byName !== 0) {
				return $byName;
			}
			return (int)$a['id'] <=> (int)$b['id'];
		});
		return array_values($items);
	}

	private function loadSystemRecordingRowsFromApi(): array {
		try {
			$container = \FreePBX::create();
			$recordings = is_object($container) ? $container->Recordings : null;
			if (is_object($container) && is_object($recordings) && method_exists($recordings, 'getAllRecordings')) {
				$rows = $recordings->getAllRecordings();
				return is_array($rows) ? $rows : [];
			}
		} catch (\Throwable $e) {
		}

		return [];
	}

	private function rcHandleGetEngineStatus(): array {
		return ['status' => true, 'engineStatus' => $this->rcEngineStatus($this->rcSettings())];
	}

	private function rcHandleRunMonitor(): array {
		$result = $this->runBackgroundMonitorDetailed();
		$settings = $this->rcSettings();
		if (!empty($result['already_running'])) {
			return [
				'status' => true,
				'already_running' => true,
				'message' => _('Repeat Caller monitor is already running.'),
				'engineStatus' => $this->rcEngineStatus($settings),
			];
		}

		return [
			'status' => !empty($result['status']),
			'already_running' => false,
			'ran' => !empty($result['ran']),
			'message' => !empty($result['status']) ? _('Repeat Caller monitor run completed.') : _('Repeat Caller monitor run failed. Check logs.'),
			'runtimeSummary' => $result['runtime_summary'] ?? [],
			'alertSummary' => $result['alert_summary'] ?? [],
			'engineStatus' => $this->rcEngineStatus($settings),
		];
	}

	private function rcHandleSaveGlobalSettings(): array {
		$enabled = array_key_exists('enabled', $_REQUEST) ? (!empty($_REQUEST['enabled']) ? '1' : '0') : null;
		$country = preg_replace('/\D+/', '', trim((string)($_REQUEST['default_country_code'] ?? ''))) ?? '';
		$incidentPrune = $this->normalisePrunePolicy((string)($_REQUEST['incident_history_prune_policy'] ?? 'daily'));
		$alertPrune = $this->normalisePrunePolicy((string)($_REQUEST['alert_history_prune_policy'] ?? 'daily'));
		$suppressionPrune = $this->normalisePrunePolicy((string)($_REQUEST['suppression_history_prune_policy'] ?? 'daily'));

		if ($enabled !== null) {
			$this->setSetting('enabled', $enabled);
			if ($enabled !== '1') {
				$this->setSetting('global_snoozed_until', '');
				$this->setSetting('global_snooze_selected_seconds', '');
			}
		}
		$this->setSetting('default_country_code', $country);
		$this->setSetting('incident_history_prune_policy', $incidentPrune);
		$this->setSetting('alert_history_prune_policy', $alertPrune);
		$this->setSetting('suppression_history_prune_policy', $suppressionPrune);

		$settings = $this->rcSettings();
		return [
			'status' => true,
			'message' => _('Global settings saved.'),
			'globalSettings' => $settings,
			'engineStatus' => $this->rcEngineStatus($settings),
		];
	}

	private function rcHandleGetRules(): array {
		return ['status' => true, 'rules' => $this->rcRepository()->loadRulesSummary()];
	}

	private function rcHandleGetRule(): array {
		$ruleId = $this->positiveRequestId('rule_id');
		if ($ruleId <= 0) {
			return ['status' => false, 'message' => _('Missing rule ID.')];
		}
		$rule = $this->rcRepository()->loadRule($ruleId);
		if (!is_array($rule)) {
			return ['status' => false, 'message' => _('Rule not found or has been deleted.')];
		}
		return ['status' => true, 'rule' => $rule];
	}

	private function rcHandleSaveRule(): array {
		$repository = $this->rcRepository();
		$ruleId = $this->positiveRequestId('rule_id');
		$existingRule = null;
		if ($ruleId > 0) {
			$existingRule = $repository->loadRule($ruleId);
		}
		if ($ruleId > 0 && $existingRule === null) {
			return ['status' => false, 'message' => _('Rule not found or has been deleted.')];
		}

		$name = trim((string)($_REQUEST['name'] ?? ''));
		if ($name === '') {
			return ['status' => false, 'message' => _('Rule name is required.')];
		}
		if (strlen($name) > 255) {
			$name = substr($name, 0, 255);
		}

		try {
			$recordingId = array_key_exists('alert_call_recording_id', $_REQUEST)
				? $this->nullablePositiveRequestInt('alert_call_recording_id')
				: (($existingRule !== null && ($existingRule['alert_call_recording_id'] ?? null) !== null && ($existingRule['alert_call_recording_id'] ?? '') !== '')
					? (int)$existingRule['alert_call_recording_id']
					: null);

			$payload = [
				'id' => $ruleId,
				'name' => $name,
				'enabled' => !empty($_REQUEST['enabled']) ? 1 : 0,
				'email_enabled' => !empty($_REQUEST['email_enabled']) ? 1 : 0,
				'alert_call_enabled' => !empty($_REQUEST['alert_call_enabled']) ? 1 : 0,
				'alert_call_destinations' => implode(', ', $this->normaliseAlertCallDestinations((string)($_REQUEST['alert_call_destinations'] ?? ''))),
				'alert_call_strategy' => $this->normaliseAlertCallStrategy((string)($_REQUEST['alert_call_strategy'] ?? 'ringall')),
				'alert_call_keep_trying' => isset($_REQUEST['alert_call_keep_trying']) ? (!empty($_REQUEST['alert_call_keep_trying']) ? 1 : 0) : 1,
				'alert_call_recording_id' => $recordingId,
				'alert_call_callerid' => trim((string)($_REQUEST['alert_call_callerid'] ?? '')),
				'mode' => (string)($_REQUEST['mode'] ?? 'repeat'),
				'threshold_count' => $this->boundedDigits((string)($_REQUEST['threshold_count'] ?? '2'), 1, 1000, 2),
				'observation_window_minutes' => $this->boundedDigits((string)($_REQUEST['observation_window_minutes'] ?? '60'), 1, 10080, 60),
				'caller_mode' => (string)($_REQUEST['caller_mode'] ?? 'any'),
				'exclude_withheld' => !empty($_REQUEST['exclude_withheld']) ? 1 : 0,
				'did_scope_mode' => (string)($_REQUEST['did_scope_mode'] ?? 'all'),
				'repeat_mode_override' => (string)($_REQUEST['repeat_mode_override'] ?? self::REPEAT_MODE_NEVER),
				'email_recipients' => implode(', ', $this->normaliseRecipients((string)($_REQUEST['email_recipients'] ?? ''))),
				'suppression_minutes_override' => ($_REQUEST['suppression_minutes_override'] ?? '') !== ''
					? $this->boundedDigits((string)$_REQUEST['suppression_minutes_override'], 0, 525600, 1440)
					: null,
				'schedules' => $this->rcParseSchedules($_REQUEST['schedules'] ?? []),
				'callers' => $this->rcParseCallers($_REQUEST['callers'] ?? []),
				'dids' => $this->rcParseDids($_REQUEST['dids'] ?? []),
			];
		} catch (\InvalidArgumentException $e) {
			return ['status' => false, 'message' => $e->getMessage()];
		}

		$payload['mode'] = $payload['mode'] === 'invert' ? 'invert' : 'repeat';
		if (!in_array($payload['caller_mode'], ['any', 'withheld_only', 'specific_only'], true)) {
			$payload['caller_mode'] = 'any';
		}
		if (!in_array($payload['did_scope_mode'], ['all', 'selected'], true)) {
			$payload['did_scope_mode'] = 'all';
		}
		if (!empty($payload['email_enabled'])) {
			$rawRecipients = trim((string)($_REQUEST['email_recipients'] ?? ''));
			$recipients = $this->normaliseRecipients($rawRecipients);
			if (empty($recipients)) {
				return ['status' => false, 'message' => _('Email is enabled. Enter at least one valid recipient address.')];
			}
			foreach ($recipients as $recipient) {
				if (!$this->emailHasMinimumParts($recipient)) {
					return ['status' => false, 'message' => _('Email recipients must include an @, a ., and at least three parts (for example: alerts@example.com).')];
				}
			}
			$payload['email_recipients'] = implode(', ', $recipients);
		}
		if (!empty($payload['alert_call_enabled']) && trim((string)$payload['alert_call_destinations']) === '') {
			return ['status' => false, 'message' => _('Alert Call is enabled. Enter at least one Alert Call destination.')];
		}
		$override = strtolower(trim((string)$payload['repeat_mode_override']));
		$payload['repeat_mode_override'] = $this->normaliseRepeatMode($override);
		if ($payload['caller_mode'] === 'specific_only') {
			$hasIncludedCaller = false;
			foreach ($payload['callers'] as $caller) {
				if ((string)$caller['list_type'] === 'include') {
					$hasIncludedCaller = true;
					break;
				}
			}
			if (!$hasIncludedCaller) {
				return ['status' => false, 'message' => _('Specific caller mode requires at least one included caller.')];
			}
		}
		if ($payload['did_scope_mode'] === 'selected') {
			$hasIncludedRoute = false;
			foreach ($payload['dids'] as $did) {
				if ((string)$did['list_type'] === 'include') {
					$hasIncludedRoute = true;
					break;
				}
			}
			if (!$hasIncludedRoute) {
				return ['status' => false, 'message' => _('Selected DID scope requires at least one included inbound route.')];
			}
		}

		$ruleId = $repository->saveRule($payload, $this->now());
		return [
			'status' => true,
			'message' => _('Rule saved.'),
			'rule' => $repository->loadRule($ruleId),
		];
	}

	private function rcHandleDeleteRule(): array {
		$ruleId = $this->positiveRequestId('rule_id');
		if ($ruleId <= 0) {
			return ['status' => false, 'message' => _('Missing rule ID.')];
		}
		$repository = $this->rcRepository();
		if ($repository->loadRule($ruleId) === null) {
			return ['status' => false, 'message' => _('Rule not found or already deleted.')];
		}
		$repository->softDeleteRule($ruleId, $this->now());
		return ['status' => true, 'message' => _('Rule deleted.'), 'rules' => $repository->loadRulesSummary()];
	}

	private function rcHandleSetRuleEnabled(): array {
		$ruleId = $this->positiveRequestId('rule_id');
		if ($ruleId <= 0) {
			return ['status' => false, 'message' => _('Missing rule ID.')];
		}
		$repository = $this->rcRepository();
		if ($repository->loadRule($ruleId) === null) {
			return ['status' => false, 'message' => _('Rule not found or has been deleted.')];
		}
		$repository->setRuleEnabled($ruleId, !empty($_REQUEST['enabled']), $this->now());
		return ['status' => true, 'message' => _('Rule state updated.'), 'rules' => $repository->loadRulesSummary()];
	}

	private function rcHandleGetInboundRoutes(): array {
		return ['status' => true, 'routes' => $this->rcRepository()->loadInboundRoutes()];
	}

	private function rcHandleGetIncidents(): array {
		$view = strtolower(trim((string)($_REQUEST['view'] ?? 'active')));
		if (!in_array($view, ['active', 'recent', 'claimed'], true)) {
			$view = 'active';
		}
		if ($view === 'recent') {
			$view = 'claimed';
		}
		return ['status' => true, 'incidents' => $this->rcRepository()->loadIncidents($view, 300)];
	}

	private function rcHandleGetSuppressedIncidents(): array {
		$asOf = $this->now();
		return ['status' => true, 'suppressedIncidents' => $this->rcRepository()->loadActiveSuppressedIncidents($asOf, 300)];
	}

	private function rcHandleClearSuppression(): array {
		$historyId = $this->positiveRequestId('suppression_history_id');
		if ($historyId <= 0) {
			return ['status' => false, 'message' => _('Missing suppression history ID.')];
		}

		$clearedAt = $this->now();
		if (!$this->rcRepository()->clearSuppressedIncidentHistory($historyId, $clearedAt)) {
			return ['status' => false, 'message' => _('Suppression history row was not found.')];
		}

		return [
			'urgency' => 'low',
			'status' => true,
			'message' => _('Suppression cleared.'),
			'suppressedIncidents' => $this->rcRepository()->loadActiveSuppressedIncidents($clearedAt, 300),
			'engineStatus' => $this->rcEngineStatus($this->rcSettings()),
		];
	}

	private function rcHandleClaimIncident(): array {
		$incidentId = $this->positiveRequestId('incident_id');
		if ($incidentId <= 0) {
			return ['status' => false, 'message' => _('Missing incident ID.')];
		}
		$sessionUser = $_SESSION['AMP_user'] ?? null;
		if (is_object($sessionUser) && isset($sessionUser->username)) {
			$user = trim((string)$sessionUser->username);
		} elseif (is_scalar($sessionUser)) {
			$user = trim((string)$sessionUser);
		} else {
			$user = '';
		}
		$user = $user !== '' ? $user : 'gui';
		$repository = $this->rcRepository();
		if (!$repository->claimActiveIncident($incidentId, $user, $this->now(), 'gui')) {
			return ['status' => false, 'message' => _('Incident is not active or was already accepted.')];
		}
		return [
			'status' => true,
			'message' => _('Incident accepted.'),
			'activeIncidents' => $repository->loadIncidents('active', 200),
			'recentIncidents' => $repository->loadIncidents('claimed', 300),
		];
	}

	private function rcHandleGetAlertHistory(): array {
		return ['status' => true, 'alertHistory' => $this->rcRepository()->loadIncidentAlertHistory(300)];
	}

	private function rcHandleGetUiChangeToken(): array {
		return [
			'status' => true,
			'changeTokens' => $this->rcRepository()->loadUiChangeTokens(),
		];
	}

	private function rcHandleSetSnooze(): array {
		$seconds = isset($_REQUEST['seconds']) ? (int)$_REQUEST['seconds'] : 0;
		if (!in_array($seconds, [300, 900, 1800, 3600, 86400], true)) {
			return ['status' => false, 'message' => _('Invalid snooze duration.')];
		}
		$this->setSetting('global_snoozed_until', date('Y-m-d H:i:s', strtotime($this->now()) + $seconds));
		$this->setSetting('global_snooze_selected_seconds', (string)$seconds);
		return ['status' => true, 'message' => _('Monitoring snoozed.'), 'engineStatus' => $this->rcEngineStatus($this->rcSettings())];
	}

	private function rcHandleResumeMonitoring(): array {
		$this->setSetting('global_snoozed_until', '');
		$this->setSetting('global_snooze_selected_seconds', '');
		return ['status' => true, 'message' => _('Monitoring resumed.'), 'engineStatus' => $this->rcEngineStatus($this->rcSettings())];
	}

	private function rcHandlePruneHistory(): array {
		$repository = $this->rcRepository();
		$settings = $this->rcSettings();
		$incidentPolicy = $this->normalisePrunePolicy((string)($_REQUEST['incident_history_prune_policy'] ?? ($settings['incident_history_prune_policy'] ?? 'never')));
		$alertPolicy = $this->normalisePrunePolicy((string)($_REQUEST['alert_history_prune_policy'] ?? ($settings['alert_history_prune_policy'] ?? 'never')));
		$suppressionPolicy = $this->normalisePrunePolicy((string)($_REQUEST['suppression_history_prune_policy'] ?? ($settings['suppression_history_prune_policy'] ?? 'never')));
		$this->setSetting('incident_history_prune_policy', $incidentPolicy);
		$this->setSetting('alert_history_prune_policy', $alertPolicy);
		$this->setSetting('suppression_history_prune_policy', $suppressionPolicy);

		$deleted = ['alert_history' => 0, 'alert_state' => 0, 'incidents' => 0, 'suppressed_history' => 0, 'seen_calls' => 0];
		$alertCutoff = $this->pruneCutoff($alertPolicy);
		$incidentCutoff = $this->pruneCutoff($incidentPolicy);
		$suppressionCutoff = $this->pruneCutoff($suppressionPolicy);
		if ($alertCutoff !== null) {
			$deleted['alert_history'] = $repository->pruneIncidentAlertHistory($alertCutoff);
		}
		if ($incidentCutoff !== null) {
			$deleted['alert_state'] = $repository->pruneIncidentAlertState($incidentCutoff);
			$deleted['incidents'] = $repository->pruneClosedIncidents($incidentCutoff);
		}
		if ($suppressionCutoff !== null) {
			$deleted['suppressed_history'] = $repository->pruneSuppressedIncidentHistory($suppressionCutoff);
		}
		$deleted['seen_calls'] = $repository->pruneSeenCallsByFixedRetention($this->now());

		return [
			'status' => true,
			'message' => _('Pruning completed.'),
			'deleted' => $deleted,
			'alertHistory' => $repository->loadIncidentAlertHistory(300),
			'suppressedIncidents' => $repository->loadSuppressedIncidentHistory(300),
			'recentIncidents' => $repository->loadIncidents('claimed', 300),
		];
	}

	private function rcHandleClearAlertHistory(): array {
		$repository = $this->rcRepository();
		$deletedCount = $repository->clearIncidentAlertHistory();

		return [
			'status' => true,
			'message' => _('Alert history cleared.'),
			'deleted_count' => $deletedCount,
			'alertHistory' => $repository->loadIncidentAlertHistory(300),
		];
	}

	private function rcRepository(): \FreePBX\modules\Repeatcaller\RepeatCallerRepository {
		require_once __DIR__ . '/src/RepeatCallerRepository.php';
		return new \FreePBX\modules\Repeatcaller\RepeatCallerRepository($this->db());
	}

	private function rcSettings(): array {
		return $this->getAlertSettings();
	}

	private function rcEngineStatus(array $settings): array {
		$pbxNow = $this->now();
		$enabled = (string)($settings['enabled'] ?? '0') === '1';
		$snoozedUntil = trim((string)($settings['global_snoozed_until'] ?? ''));
		$selectedSnoozeSeconds = trim((string)($settings['global_snooze_selected_seconds'] ?? ''));

		$nowTs = strtotime($pbxNow);
		$untilTs = $snoozedUntil !== '' ? strtotime($snoozedUntil) : false;
		$isExpired = $snoozedUntil !== '' && ($untilTs === false || $nowTs === false || $untilTs <= $nowTs);
		if (!$enabled || $isExpired) {
			if ($snoozedUntil !== '' || $selectedSnoozeSeconds !== '') {
				$this->setSetting('global_snoozed_until', '');
				$this->setSetting('global_snooze_selected_seconds', '');
			}
			$snoozedUntil = '';
			$selectedSnoozeSeconds = '';
		}

		$activeIncidentCount = 0;
		$enabledRuleCount = 0;
		$databaseTime = '';
		try {
			$activeIncidentCount = (int)$this->db()->query("SELECT COUNT(*) FROM repeatcaller_incidents WHERE state = 'active'")->fetchColumn();
			$enabledRuleCount = (int)$this->db()->query("SELECT COUNT(*) FROM repeatcaller_rules WHERE enabled = 1 AND is_deleted = 0")->fetchColumn();
			$stmt = $this->db()->query('SELECT NOW()');
			$databaseTime = $stmt ? (string)$stmt->fetchColumn() : '';
		} catch (\Throwable $e) {
		}

		$lastSummary = [];
		$rawSummary = trim((string)($settings['engine_last_summary_json'] ?? ''));
		if ($rawSummary !== '') {
			$decoded = json_decode($rawSummary, true);
			if (is_array($decoded)) {
				$lastSummary = $decoded;
			}
		}

		$lockState = 'unknown';
		$lockProbe = $this->probeReconcileLock();
		if ($lockProbe === true) {
			$lockState = 'idle';
		} elseif ($lockProbe === false) {
			$lockState = 'running';
		}

		return [
			'enabled' => $enabled,
			'job_name' => 'repeatcaller::monitor',
			'job_status' => 'unknown',
			'last_successful_run' => (string)($settings['engine_last_success_at'] ?? ''),
			'last_run_summary' => $lastSummary,
			'pbx_time' => $pbxNow,
			'database_time' => $databaseTime,
			'global_snoozed_until' => $snoozedUntil,
			'selected_snooze_seconds' => $selectedSnoozeSeconds,
			'lock_state' => $lockState,
			'active_incident_count' => $activeIncidentCount,
			'enabled_rule_count' => $enabledRuleCount,
		];
	}

	private function getAlertSettings(): array {
		$settings = $this->settingsDefaults;
		try {
			$stmt = $this->db()->query('SELECT setting_key, setting_value FROM repeatcaller_settings');
			if ($stmt) {
				foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
					$key = (string)$row['setting_key'];
					if (array_key_exists($key, $settings)) {
						$settings[$key] = (string)$row['setting_value'];
					}
				}
			}
		} catch (\Throwable $e) {
		}

		$settings['incident_history_prune_policy'] = $this->normalisePrunePolicy((string)$settings['incident_history_prune_policy']);
		$settings['alert_history_prune_policy'] = $this->normalisePrunePolicy((string)$settings['alert_history_prune_policy']);
		$settings['suppression_history_prune_policy'] = $this->normalisePrunePolicy((string)$settings['suppression_history_prune_policy']);
		$settings['enabled'] = (string)$settings['enabled'] === '1' ? '1' : '0';

		return $settings;
	}

	private function setSetting(string $key, string $value): void {
		if (!array_key_exists($key, $this->settingsDefaults)) {
			return;
		}
		$stmt = $this->db()->prepare(
			'INSERT INTO repeatcaller_settings (setting_key, setting_value, updated_at)
			 VALUES (:setting_key, :setting_value, :updated_at)
			 ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = VALUES(updated_at)'
		);
		$stmt->execute([
			':setting_key' => $key,
			':setting_value' => $value,
			':updated_at' => $this->now(),
		]);
	}

	private function rcParseSchedules($raw): array {
		$schedules = $this->rcDecodePayloadList($raw);
		$normalized = [];
		$rejected = false;
		foreach ($schedules as $item) {
			$day = -1;
			if (array_key_exists('day', $item)) {
				$dayRaw = $item['day'];
				if (is_int($dayRaw)) {
					$day = $dayRaw;
				} elseif (is_string($dayRaw) && preg_match('/^-?\d+$/', trim($dayRaw))) {
					$day = (int)trim($dayRaw);
				} elseif ($dayRaw === null || $dayRaw === '') {
					$day = -1;
				} else {
					continue;
				}
			}
			$allDay = !empty($item['all_day']);
			$start = substr(trim((string)($item['start'] ?? '')), 0, 5);
			$end = substr(trim((string)($item['end'] ?? '')), 0, 5);
			if ($day < -1 || $day > 6) {
				continue;
			}
			if ($allDay) {
				$normalized[] = ['day' => $day, 'start' => '00:00', 'end' => '24:00'];
				continue;
			}
			if (!preg_match('/^\d{2}:\d{2}$/', $start) || !preg_match('/^\d{2}:\d{2}$/', $end)) {
				continue;
			}
			if ($start >= $end) {
				$rejected = true;
				continue;
			}
			$normalized[] = ['day' => $day, 'start' => $start, 'end' => $end];
		}
		if ($rejected) {
			throw new \InvalidArgumentException('Overnight or invalid schedule ranges are not supported in this phase.');
		}
		return \FreePBX\modules\Repeatcaller\RepeatCallerRepository::normalizeSchedules($normalized);
	}

	private function rcParseCallers($raw): array {
		require_once __DIR__ . '/src/DetectionEngine.php';
		$items = $this->rcDecodePayloadList($raw);
		$normalized = [];
		$country = trim((string)($this->rcSettings()['default_country_code'] ?? ''));
		foreach ($items as $item) {
			$listType = (string)($item['list_type'] ?? 'include');
			if (!in_array($listType, ['include', 'exclude'], true)) {
				continue;
			}
			$rawValue = trim((string)($item['raw_value'] ?? ''));
			if ($rawValue === '') {
				continue;
			}
			if (strtolower($rawValue) === 'withheld') {
				$norm = 'withheld';
			} else {
				$norm = \FreePBX\modules\Repeatcaller\DetectionEngine::normaliseCaller($rawValue, $country);
				if ($norm === null || $norm === '') {
					continue;
				}
			}
			$normalized[] = ['list_type' => $listType, 'raw_value' => $rawValue, 'normalized_value' => $norm];
		}
		return $normalized;
	}

	private function rcParseDids($raw): array {
		$items = $this->rcDecodePayloadList($raw);
		$normalized = [];
		$allowedRoutes = [];
		foreach ($this->rcRepository()->loadInboundRoutes() as $route) {
			$allowedRoutes[(string)$route['route_key']] = $route;
		}
		foreach ($items as $item) {
			$listType = (string)($item['list_type'] ?? 'include');
			if (!in_array($listType, ['include', 'exclude'], true)) {
				continue;
			}
			$routeKey = trim((string)($item['route_key'] ?? ''));
			if ($routeKey === '' || !isset($allowedRoutes[$routeKey])) {
				continue;
			}
			$route = $allowedRoutes[$routeKey];
			$normalized[] = [
				'list_type' => $listType,
				'route_key' => $routeKey,
				'route_label' => (string)$route['route_label'],
				'did_value' => (string)$route['did_value'],
				'cid_value' => (string)$route['cid_value'],
			];
		}
		return $normalized;
	}

	private function rcDecodePayloadList($raw): array {
		if (is_array($raw)) {
			return $raw;
		}
		if (!is_string($raw) || trim($raw) === '') {
			return [];
		}
		$decoded = json_decode($raw, true);
		return is_array($decoded) ? $decoded : [];
	}

	private function normaliseAlertCallDestinations(string $raw): array {
		$parts = preg_split('/[,;\n\r]+/', trim($raw));
		$destinations = [];
		foreach ($parts as $part) {
			$value = trim((string)$part);
			if ($value === '') {
				continue;
			}
			$destinations[$value] = $value;
		}
		return array_values($destinations);
	}

	private function normaliseAlertCallStrategy(string $strategy): string {
		$strategy = strtolower(trim($strategy));
		if ($strategy === 'ordered') {
			return 'ordered';
		}
		return 'ringall';
	}

	private function nullablePositiveRequestInt(string $key): ?int {
		$raw = trim((string)($_REQUEST[$key] ?? ''));
		if ($raw === '' || !ctype_digit($raw)) {
			return null;
		}
		$value = (int)$raw;
		return $value > 0 ? $value : null;
	}

	private function createCsrfToken(): string {
		if (!$this->ensureSessionForCsrfWrite()) {
			return '';
		}
		$token = isset($_SESSION[self::CSRF_SESSION_KEY]) ? (string)$_SESSION[self::CSRF_SESSION_KEY] : '';
		if ($token !== '') {
			return $token;
		}
		try {
			$token = bin2hex(random_bytes(32));
			$_SESSION[self::CSRF_SESSION_KEY] = $token;
			return $token;
		} catch (\Throwable $e) {
			$this->logWarning('Unable to create Repeat Caller CSRF token: ' . $e->getMessage());
			return '';
		}
	}

	private function ensureSessionForCsrfWrite(): bool {
		if (!function_exists('session_status')) {
			return isset($_SESSION) && is_array($_SESSION);
		}
		$status = session_status();
		if ($status === PHP_SESSION_ACTIVE) {
			return true;
		}
		if ($status === PHP_SESSION_DISABLED || headers_sent()) {
			return false;
		}
		return @session_start();
	}

	private function validateCsrfToken(): bool {
		$sessionToken = isset($_SESSION[self::CSRF_SESSION_KEY]) ? (string)$_SESSION[self::CSRF_SESSION_KEY] : '';
		$provided = isset($_REQUEST['token']) ? (string)$_REQUEST['token'] : '';
		return $sessionToken !== '' && $provided !== '' && hash_equals($sessionToken, $provided);
	}

	public function sendEmail(string $recipient, string $subject, string $message): array {
		try {
			if (!class_exists('\CI_Email')) {
				return ['status' => false, 'message' => 'CI_Email is not available.'];
			}
			$from = $this->getNotificationFromAddress();
			if ($from === '') {
				return ['status' => false, 'message' => 'Email "From:" Address is not configured in Advanced Settings.'];
			}
			$senderName = $this->getNotificationSenderName();
			$email = new \CI_Email();
			if ($this->emailFromSupportsReturnPath($email)) {
				$email->from($from, $senderName, $from);
			} else {
				$email->from($from, $senderName);
				if (method_exists($email, 'set_header')) {
					$email->set_header('Return-Path', $from);
				}
			}
			if (method_exists($email, 'reply_to')) {
				$email->reply_to($from, $senderName);
			}
			$email->to($recipient);
			$email->subject($subject);
			$email->set_mailtype('text');
			$email->message($message);
			if ($email->send()) {
				return ['status' => true, 'message' => 'accepted by local mailer; delivery not confirmed'];
			}
			$error = 'CI_Email send failed.';
			if (method_exists($email, 'print_debugger')) {
				$debug = trim(strip_tags((string)$email->print_debugger(['headers'])));
				if ($debug !== '') {
					$error .= ' ' . $debug;
				}
			}
			return ['status' => false, 'message' => $error];
		} catch (\Throwable $e) {
			return ['status' => false, 'message' => $e->getMessage()];
		}
	}

	public function sendAlertCall(string $destination, string $recordingId, string $callerId = '', array $context = []): array {
		$destination = trim($destination);
		if ($destination === '') {
			return ['status' => false, 'message' => 'Alert call destination is required.'];
		}
		$playbackTarget = '';
		$playbackLanguage = $this->resolveAlertCallPlaybackLanguage($recordingId);
		if (trim($recordingId) !== '') {
			$recording = $this->resolveSystemRecordingPlayback($recordingId);
			$playbackTarget = (string)($recording['target'] ?? '');
			if ($playbackTarget === '') {
				return ['status' => false, 'message' => 'System Recording could not be resolved.'];
			}
		}

		$astman = $this->astmanConnection();
		if (!is_object($astman)) {
			return ['status' => false, 'message' => 'Asterisk Manager connection is not available.'];
		}

		$historyId = max(0, (int)($context['history_id'] ?? 0));
		$incidentId = max(0, (int)($context['incident_id'] ?? 0));
		$recipient = $this->normaliseAlertCallVariable((string)($context['recipient'] ?? $destination));
		$summaryMode = $this->normaliseAlertCallVariable((string)($context['summary_mode'] ?? 'repeat'));
		$summaryCallCount = $this->normaliseAlertCallDigits((string)($context['summary_call_count'] ?? '0'));
		$summaryThreshold = $this->normaliseAlertCallDigits((string)($context['summary_threshold'] ?? '0'));
		$summaryWindowMinutes = $this->normaliseAlertCallDigits((string)($context['summary_window_minutes'] ?? '0'));
		$summaryCallerKind = $this->normaliseAlertCallVariable((string)($context['summary_caller_kind'] ?? 'unknown'));
		$summaryCallerValue = $this->normaliseAlertCallDigits((string)($context['summary_caller_value'] ?? ''));
		$summaryDidValue = $this->normaliseAlertCallDigits((string)($context['summary_did_value'] ?? ''));

		$params = [
			'Channel' => 'Local/' . $destination . '@repeatcaller-alert-launch/n',
			'Application' => 'Wait',
			'Data' => '60',
			'Async' => 'true',
			'Timeout' => '30000',
			'Variable' => 'REPEATCALLER_PLAYBACK_TARGET=' . $playbackTarget . ',REPEATCALLER_PLAYBACK_LANGUAGE=' . $playbackLanguage . ',REPEATCALLER_ALERT_HISTORY_ID=' . $historyId . ',REPEATCALLER_INCIDENT_ID=' . $incidentId . ',REPEATCALLER_ALERT_RECIPIENT=' . $recipient . ',REPEATCALLER_SUMMARY_MODE=' . $summaryMode . ',REPEATCALLER_SUMMARY_CALL_COUNT=' . $summaryCallCount . ',REPEATCALLER_SUMMARY_THRESHOLD=' . $summaryThreshold . ',REPEATCALLER_SUMMARY_WINDOW_MINUTES=' . $summaryWindowMinutes . ',REPEATCALLER_SUMMARY_CALLER_KIND=' . $summaryCallerKind . ',REPEATCALLER_SUMMARY_CALLER_VALUE=' . $summaryCallerValue . ',REPEATCALLER_SUMMARY_DID_VALUE=' . $summaryDidValue,
		];
		$callerId = trim($callerId);
		if ($callerId !== '') {
			$params['CallerID'] = $callerId;
		}

		try {
			if (method_exists($astman, 'originate')) {
				$result = $astman->originate($params);
			} elseif (method_exists($astman, 'send_request')) {
				$result = $astman->send_request('Originate', $params);
			} else {
				return ['status' => false, 'message' => 'Asterisk Manager originate is not available.'];
			}
			return $this->normaliseOriginateResult($result);
		} catch (\Throwable $e) {
			return ['status' => false, 'message' => $e->getMessage()];
		}
	}

	private function normaliseAlertCallVariable(string $value): string {
		$value = trim($value);
		return preg_replace('/[^0-9A-Za-z_.+@:-]/', '', $value) ?? '';
	}

	private function normaliseAlertCallDigits(string $value): string {
		$value = trim($value);
		return preg_replace('/\D+/', '', $value) ?? '';
	}

	private function astmanConnection() {
		global $astman;
		return is_object($astman) ? $astman : null;
	}

	private function resolveSystemRecordingPlayback(string $recordingId): array {
		$recordingId = trim($recordingId);
		if ($recordingId === '' || !ctype_digit($recordingId)) {
			return ['target' => '', 'language' => ''];
		}
		$recordingIdInt = (int)$recordingId;

		$filename = '';
		$language = '';
		foreach ($this->loadSystemRecordingRowsFromApi() as $row) {
			if (!is_array($row)) {
				continue;
			}
			$id = isset($row['id']) ? (int)$row['id'] : (isset($row['recording_id']) ? (int)$row['recording_id'] : 0);
			if ($id !== $recordingIdInt) {
				continue;
			}
			$filename = trim((string)($row['filename'] ?? $row['file'] ?? ''));
			$language = trim((string)($row['fcode_lang'] ?? $row['language'] ?? ''));
			break;
		}

		if ($filename === '') {
			return ['target' => '', 'language' => ''];
		}

		$target = preg_replace('/\.[^.\/]+$/', '', $filename) ?? '';
		return [
			'target' => ltrim(trim($target), '/'),
			'language' => $this->sanitizePlaybackLanguage($language),
		];
	}

	private function resolveAlertCallPlaybackLanguage(string $recordingId): string {
		$recording = trim($recordingId) !== '' ? $this->resolveSystemRecordingPlayback($recordingId) : ['language' => ''];
		$recordingLanguage = $this->sanitizePlaybackLanguage((string)($recording['language'] ?? ''));
		if ($recordingLanguage !== '') {
			return $recordingLanguage;
		}

		return $this->resolveFreePBXDefaultLanguage();
	}

	private function resolveFreePBXDefaultLanguage(): string {
		try {
			$value = $this->sanitizePlaybackLanguage((string)\FreePBX::Soundlang()->getLanguage());
			if ($value !== '') {
				return $value;
			}
		} catch (\Throwable $e) {
		}

		return '';
	}

	private function sanitizePlaybackLanguage(string $language): string {
		$language = trim($language);
		return preg_match('/^[A-Za-z0-9_.-]+$/', $language) === 1 ? $language : '';
	}

	private function normaliseOriginateResult($result): array {
		if ($result === true) {
			return ['status' => true, 'message' => 'queued'];
		}
		if (is_array($result)) {
			$response = strtolower(trim((string)($result['Response'] ?? $result['response'] ?? '')));
			$message = trim((string)($result['Message'] ?? $result['message'] ?? ''));
			if ($response === 'success') {
				return ['status' => true, 'message' => $message !== '' ? $message : 'queued'];
			}
			return ['status' => false, 'message' => $message !== '' ? $message : 'Originate failed.'];
		}
		if (is_string($result)) {
			$normalized = strtolower(trim($result));
			if (strpos($normalized, 'success') !== false || strpos($normalized, 'queued') !== false) {
				return ['status' => true, 'message' => trim($result)];
			}
			return ['status' => false, 'message' => trim($result) !== '' ? trim($result) : 'Originate failed.'];
		}
		return ['status' => false, 'message' => 'Originate failed.'];
	}

	private function getNotificationFromAddress(): string {
		try {
			$value = (string)\FreePBX::Config()->get('AMPUSERMANEMAILFROM');
			return $this->normaliseEmailAddress($value);
		} catch (\Throwable $e) {
			return '';
		}
	}

	private function getNotificationSenderName(): string {
		try {
			$brand = (string)\FreePBX::Config()->get('DASHBOARD_FREEPBX_BRAND');
			return $brand !== '' ? $brand : 'Repeat Caller';
		} catch (\Throwable $e) {
			return 'Repeat Caller';
		}
	}

	private function emailFromSupportsReturnPath($email): bool {
		try {
			$method = new \ReflectionMethod($email, 'from');
			return $method->getNumberOfParameters() >= 3;
		} catch (\ReflectionException $e) {
			return false;
		}
	}

	private function normaliseEmailAddress(string $value): string {
		$value = trim($value);
		if ($value === '') {
			return '';
		}
		if (preg_match('/<([^>]+)>/', $value, $matches)) {
			$value = trim($matches[1]);
		}
		return filter_var($value, FILTER_VALIDATE_EMAIL) ? $value : '';
	}

	private function normaliseRecipients(string $raw): array {
		$parts = preg_split('/[,;\s]+/', trim($raw));
		$recipients = [];
		foreach ($parts as $part) {
			$email = $this->normaliseEmailAddress((string)$part);
			if ($email !== '') {
				$recipients[strtolower($email)] = $email;
			}
		}
		return array_values($recipients);
	}

	private function emailHasMinimumParts(string $email): bool {
		$parts = preg_split('/[@.]+/', trim($email));
		$nonEmpty = array_values(array_filter($parts, function ($part) {
			return trim((string)$part) !== '';
		}));
		return count($nonEmpty) >= 3;
	}

	private function normaliseRepeatMode(?string $mode): string {
		$mode = strtolower(trim((string)$mode));
		if ($mode === self::REPEAT_MODE_FIBONACCI) {
			return self::REPEAT_MODE_ESCALATING;
		}
		return in_array($mode, [self::REPEAT_MODE_NEVER, self::REPEAT_MODE_FIVE_MINUTES, self::REPEAT_MODE_HOURLY, self::REPEAT_MODE_DAILY, self::REPEAT_MODE_ESCALATING], true)
			? $mode
			: self::REPEAT_MODE_NEVER;
	}

	private function normalisePrunePolicy(string $policy): string {
		$policy = strtolower(trim($policy));
		return in_array($policy, ['hourly', 'daily', 'weekly', 'monthly', 'yearly', 'never'], true) ? $policy : 'never';
	}

	private function pruneCutoff(string $policy): ?string {
		$policy = $this->normalisePrunePolicy($policy);
		if ($policy === 'never') {
			return null;
		}
		try {
			$cutoff = new \DateTimeImmutable($this->now());
			switch ($policy) {
				case 'hourly': $cutoff = $cutoff->modify('-1 hour'); break;
				case 'daily': $cutoff = $cutoff->modify('-1 day'); break;
				case 'weekly': $cutoff = $cutoff->modify('-1 week'); break;
				case 'monthly': $cutoff = $cutoff->modify('-1 month'); break;
				case 'yearly': $cutoff = $cutoff->modify('-1 year'); break;
			}
			return $cutoff->format('Y-m-d H:i:s');
		} catch (\Throwable $e) {
			$this->logError('Unable to calculate pruning cutoff: ' . $e->getMessage());
			return null;
		}
	}

	private function boundedDigits(string $value, int $min, int $max, int $default): int {
		$value = trim($value);
		if ($value === '' || !ctype_digit($value)) {
			return $default;
		}
		return max($min, min($max, (int)$value));
	}

	private function positiveRequestId(string $key): int {
		$value = isset($_REQUEST[$key]) ? (string)$_REQUEST[$key] : '';
		return ctype_digit($value) && (int)$value > 0 ? (int)$value : 0;
	}

	private function acquireReconcileLock(): bool {
		try {
			$stmt = $this->db()->query("SELECT GET_LOCK('repeatcaller_reconcile', 0)");
			return $stmt ? (int)$stmt->fetchColumn() === 1 : false;
		} catch (\Throwable $e) {
			$this->logWarning('Repeat Caller reconcile lock unavailable: ' . $e->getMessage());
			return false;
		}
	}

	private function releaseReconcileLock(): void {
		try {
			$this->db()->query("SELECT RELEASE_LOCK('repeatcaller_reconcile')");
		} catch (\Throwable $e) {
			$this->logWarning('Repeat Caller reconcile lock release failed: ' . $e->getMessage());
		}
	}

	private function probeReconcileLock(): ?bool {
		try {
			$stmt = $this->db()->query("SELECT GET_LOCK('repeatcaller_reconcile', 0)");
			$acquired = $stmt ? (int)$stmt->fetchColumn() === 1 : false;
			if ($acquired) {
				$this->db()->query("SELECT RELEASE_LOCK('repeatcaller_reconcile')");
			}
			return $acquired;
		} catch (\Throwable $e) {
			return null;
		}
	}

	private function db() {
		return \FreePBX::Database();
	}

	public function logLevel(string $level, string $message): void {
		switch ($level) {
			case 'error':
				$this->logError($message);
				break;
			case 'warning':
				$this->logWarning($message);
				break;
			default:
				$this->logInfo($message);
				break;
		}
	}

	private function logError(string $message): void {
		try {
			if (is_callable(['\FreePBX', 'Log'])) {
				\FreePBX::Log()->error('repeatcaller: ' . $message);
				return;
			}
		} catch (\Throwable $e) {
		}
		error_log('repeatcaller: ' . $message);
	}

	private function logWarning(string $message): void {
		try {
			if (is_callable(['\FreePBX', 'Log'])) {
				\FreePBX::Log()->warning('repeatcaller: ' . $message);
				return;
			}
		} catch (\Throwable $e) {
		}
		error_log('repeatcaller: ' . $message);
	}

	private function logInfo(string $message): void {
		try {
			if (is_callable(['\FreePBX', 'Log'])) {
				\FreePBX::Log()->info('repeatcaller: ' . $message);
				return;
			}
		} catch (\Throwable $e) {
		}
		error_log('repeatcaller: ' . $message);
	}

	private function now(): string {
		return date('Y-m-d H:i:s');
	}
}

<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/RepeatCallerRepository.php';
require_once __DIR__ . '/../src/IncidentAlertProcessor.php';
require_once __DIR__ . '/../src/DetectionEngine.php';
require_once __DIR__ . '/../src/AlertCallAgiSession.php';

use FreePBX\modules\Repeatcaller\AlertCallAgiSession;
use FreePBX\modules\Repeatcaller\AlertCallAgiTransport;
use FreePBX\modules\Repeatcaller\IncidentAlertProcessor;
use FreePBX\modules\Repeatcaller\RepeatCallerRepository;

if (!interface_exists('BMO')) {
	interface BMO {}
}

if (!class_exists('FreePBX')) {
	class FreePBX {
		public static array $config = [];
		public static array $recordings = [];
		public static string $soundlangLanguage = 'en';
		public static $database = null;

		public static function Config() {
			$config = self::$config;
			return new class($config) {
				/** @var array<string, string> */
				private array $config;

				public function __construct(array $config) {
					$this->config = $config;
				}

				public function get($key) {
					return $this->config[(string)$key] ?? '';
				}
			};
		}

		public static function __callStatic($name, $arguments) {
			if ($name === 'Soundlang') {
				$language = self::$soundlangLanguage;
				return new class($language) {
					private string $language;

					public function __construct(string $language) {
						$this->language = $language;
					}

					public function getLanguage(): string {
						return $this->language;
					}
				};
			}

			throw new BadMethodCallException('Undefined static method ' . $name);
		}

		public static function create() {
			$container = new stdClass();
			$container->Recordings = new class(self::$recordings) {
				/** @var array<int, array<string, mixed>> */
				private array $recordings;

				public function __construct(array $recordings) {
					$this->recordings = $recordings;
				}

				public function getAllRecordings(): array {
					return $this->recordings;
				}
			};

			return $container;
		}

		public static function Database() {
			return self::$database;
		}
	}
}

require_once __DIR__ . '/../Repeatcaller.class.php';

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

$installSource = file_get_contents(__DIR__ . '/../install.php');
assert_true($installSource !== false, 'install.php should be readable for alert-call dialplan contract checks');
assert_true(strpos($installSource, 'U(repeatcaller-alert-playback^${REPEATCALLER_PLAYBACK_TARGET}^${IF($["${REPEATCALLER_PLAYBACK_LANGUAGE}"=""]?${CHANNEL(language)}:${REPEATCALLER_PLAYBACK_LANGUAGE})}^${REPEATCALLER_ALERT_HISTORY_ID}^${REPEATCALLER_INCIDENT_ID}^${REPEATCALLER_ALERT_RECIPIENT}^${REPEATCALLER_SUMMARY_MODE}^${REPEATCALLER_SUMMARY_CALL_COUNT}^${REPEATCALLER_SUMMARY_THRESHOLD}^${REPEATCALLER_SUMMARY_WINDOW_MINUTES}^${REPEATCALLER_SUMMARY_CALLER_KIND}^${REPEATCALLER_SUMMARY_CALLER_VALUE}^${REPEATCALLER_SUMMARY_DID_VALUE})') !== false, 'called-channel U() invocation must carry mode, count, threshold, window, caller-kind, caller digits, and DID digits into alert playback instead of relying on empty summary arguments');
assert_true(strpos($installSource, 'AGI(__REPEATCALLER_AGI_SCRIPT__,${REPEATCALLER_ALERT_HISTORY_ID},${REPEATCALLER_INCIDENT_ID},interactive,${REPEATCALLER_ALERT_RECIPIENT},${REPEATCALLER_PLAYBACK_TARGET},${REPEATCALLER_SUMMARY_MODE},${REPEATCALLER_SUMMARY_CALL_COUNT},${REPEATCALLER_SUMMARY_THRESHOLD},${REPEATCALLER_SUMMARY_WINDOW_MINUTES},${REPEATCALLER_SUMMARY_CALLER_KIND},${REPEATCALLER_SUMMARY_CALLER_VALUE},${REPEATCALLER_SUMMARY_DID_VALUE})') !== false, 'generated alert-playback dialplan must delegate answered-call interaction to the dedicated AGI session');
assert_true(strpos($installSource, 'Read(REPEATCALLER_DTMF') === false, 'answered-call interaction must no longer depend on dialplan Read() collection');
assert_true(strpos($installSource, 'While($[') === false, 'response loop must be finite and must not rely on an unbounded While retry structure');
assert_true(strpos($installSource, '[repeatcaller-alert-summary]') !== false, 'generated dialplan may retain the summary helper context for compatibility even though answered-call interaction is now AGI-owned');
assert_true(strpos($installSource, 'exten => remote_accepted,1,Set(REPEATCALLER_ALERT_COMPLETED=1)') !== false, 'generated dialplan must expose a dedicated remote_accepted extension so other answered callers can be redirected out immediately on remote acceptance');
assert_true(strpos($installSource, 'exten => remote_accepted,1,Set(REPEATCALLER_ALERT_COMPLETED=1)') !== false, 'already answered concurrent callers must be routed to thank-you and goodbye once the incident is accepted elsewhere');
assert_true(strpos($installSource, 'exten => h,1,GotoIf($["${REPEATCALLER_ALERT_COMPLETED}"="1"]?done)') !== false, 'hangup handling must preserve accepted, declined, and third-attempt no-response terminal outcomes');
assert_true(strpos($installSource, 'U(repeatcaller-alert-playback^${REPEATCALLER_PLAYBACK_TARGET}') !== false, 'alert playback must remain module-owned generated dialplan invoked through U() for FreePBX 16 and 17 compatibility');
assert_true(strpos($installSource, 'Set(CHANNEL(language)=${ARG2})') !== false, 'generated playback must remain language-aware via the carried channel language argument');
assert_true(strpos($installSource, '/var/lib/asterisk/sounds') === false && strpos($installSource, '/usr/share/asterisk/sounds') === false, 'generated playback must not hardcode absolute sound paths so carried languages like en and en_GB continue to resolve installed prompts');

$agiSource = file_get_contents(__DIR__ . '/../agi/repeatcaller_alert_response.php');
assert_true($agiSource !== false, 'AGI interactive handler should be readable');
assert_true(strpos($agiSource, 'STREAM FILE') !== false, 'interactive AGI must stream recordings and prompts through STREAM FILE with escape digits');
assert_true(strpos($agiSource, 'SAY NUMBER') !== false, 'interactive AGI must speak numeric counts and windows through SAY NUMBER with escape digits');
assert_true(strpos($agiSource, 'SAY DIGITS') !== false, 'interactive AGI must speak caller and DID details through SAY DIGITS with escape digits');
$agiSessionSource = file_get_contents(__DIR__ . '/../src/AlertCallAgiSession.php');
assert_true($agiSessionSource !== false, 'AlertCallAgiSession.php should be readable');
assert_true(strpos($agiSource, 'WAIT FOR DIGIT ') !== false, 'interactive AGI transport must issue WAIT FOR DIGIT commands');
assert_true(strpos($agiSessionSource, 'waitForDigit(1)') !== false, 'interactive AGI session must poll immediately after answer so a buffered early digit is retained and acted on');
assert_true(strpos($agiSessionSource, 'waitForDigit(10000)') !== false, 'interactive AGI session must preserve the 10-second response wait after spoken prompts');

$viewSource = file_get_contents(__DIR__ . '/../views/main.php');
assert_true($viewSource !== false, 'views/main.php should be readable for alert-call UI contract checks');
assert_true(strpos($viewSource, '<option value=""><?php echo _(\'None\'); ?></option>') !== false, 'System Recording selector must default to None');
assert_true(strpos($viewSource, 'Optionally play a System Recording before the generated alert message. Default: None.') !== false, 'System Recording help text must explain the optional intro recording and default None behavior');
assert_true(strpos($viewSource, 'Warning. This alert has been initiated for [X] calls within [X] minutes from [Caller ID], calling number [DID]. Press 1 to accept or 2 to decline.') === false, 'System Recording help text should not include the removed warning example sentence');
assert_true(strpos($viewSource, 'The placeholders are replaced with the incident\'s actual call count, configured threshold/window, Caller ID and DID as applicable.') === false, 'System Recording help text must not include the removed placeholder replacement sentence');

final class FakeInteractiveTransport implements AlertCallAgiTransport {
	public array $variables = [];
	public array $calls = [];
	public array $waitResponses;
	public array $streamResponses;
	public array $sayNumberResponses;
	public array $sayDigitsResponses;

	public function __construct(array $waitResponses = [], array $streamResponses = [], array $sayNumberResponses = [], array $sayDigitsResponses = []) {
		$this->waitResponses = $waitResponses;
		$this->streamResponses = $streamResponses;
		$this->sayNumberResponses = $sayNumberResponses;
		$this->sayDigitsResponses = $sayDigitsResponses;
	}

	public function setVariable(string $name, string $value): void {
		$this->variables[$name] = $value;
		$this->calls[] = ['method' => 'setVariable', 'name' => $name, 'value' => $value];
	}

	public function streamFile(string $file, string $escapeDigits): string {
		$this->calls[] = ['method' => 'streamFile', 'file' => $file, 'escapeDigits' => $escapeDigits];
		return array_shift($this->streamResponses) ?? '';
	}

	public function sayNumber(int $number, string $escapeDigits): string {
		$this->calls[] = ['method' => 'sayNumber', 'number' => $number, 'escapeDigits' => $escapeDigits];
		return array_shift($this->sayNumberResponses) ?? '';
	}

	public function sayDigits(string $digits, string $escapeDigits): string {
		$this->calls[] = ['method' => 'sayDigits', 'digits' => $digits, 'escapeDigits' => $escapeDigits];
		return array_shift($this->sayDigitsResponses) ?? '';
	}

	public function waitForDigit(int $milliseconds): string {
		$this->calls[] = ['method' => 'waitForDigit', 'milliseconds' => $milliseconds];
		return array_shift($this->waitResponses) ?? '';
	}
}

$agiSession = new AlertCallAgiSession();

$earlyDigitTransport = new FakeInteractiveTransport(['1']);
$earlyDigitResult = $agiSession->run([
	'playback_target' => 'intro',
	'summary_mode' => 'repeat',
	'summary_call_count' => '2',
	'summary_threshold' => '2',
	'summary_window_minutes' => '60',
	'summary_caller_kind' => 'numeric',
	'summary_caller_value' => '4412345',
	'summary_did_value' => '0207000',
], $earlyDigitTransport, function (): bool {
	return false;
});
assert_same('accepted', (string)$earlyDigitResult['response'], 'a digit entered immediately after answer must be retained and acted on before any audio starts');
assert_same('1', (string)$earlyDigitResult['digit'], 'early buffered accept digit should be preserved');
assert_same('waitForDigit', (string)$earlyDigitTransport->calls[0]['method'], 'the interactive AGI session must check for an immediate buffered digit before playback');
assert_same(1, (int)$earlyDigitTransport->calls[0]['milliseconds'], 'the immediate buffered-digit check must use a 1ms AGI wait rather than a dialplan delay');

$introAcceptTransport = new FakeInteractiveTransport([''], ['1']);
$introAcceptResult = $agiSession->run([
	'playback_target' => 'intro',
	'summary_mode' => 'repeat',
	'summary_call_count' => '2',
	'summary_threshold' => '2',
	'summary_window_minutes' => '60',
	'summary_caller_kind' => 'numeric',
	'summary_caller_value' => '4412345',
	'summary_did_value' => '0207000',
], $introAcceptTransport, function (): bool {
	return false;
});
assert_same('accepted', (string)$introAcceptResult['response'], 'Press 1 during the introductory recording must accept immediately');

$callerDigitAcceptTransport = new FakeInteractiveTransport([''], [''], [], ['1']);
$callerDigitAcceptResult = $agiSession->run([
	'playback_target' => '',
	'summary_mode' => 'repeat',
	'summary_call_count' => '2',
	'summary_threshold' => '2',
	'summary_window_minutes' => '60',
	'summary_caller_kind' => 'numeric',
	'summary_caller_value' => '4412345',
	'summary_did_value' => '',
], $callerDigitAcceptTransport, function (): bool {
	return false;
});
assert_same('accepted', (string)$callerDigitAcceptResult['response'], 'Press 1 during spoken caller digits must accept immediately');

$spokenDeclineTransport = new FakeInteractiveTransport([''], [''], ['2']);
$spokenDeclineResult = $agiSession->run([
	'playback_target' => '',
	'summary_mode' => 'repeat',
	'summary_call_count' => '2',
	'summary_threshold' => '2',
	'summary_window_minutes' => '60',
	'summary_caller_kind' => 'none',
	'summary_caller_value' => '',
	'summary_did_value' => '',
], $spokenDeclineTransport, function (): bool {
	return false;
});
assert_same('declined', (string)$spokenDeclineResult['response'], 'Press 2 during a spoken summary segment must decline immediately');

$invalidRetryTransport = new FakeInteractiveTransport(
	['', '', ''],
	['9', '', '8', '', '7'],
	[],
	[]
);
$invalidRetryResult = $agiSession->run([
	'playback_target' => 'intro',
	'summary_mode' => 'repeat',
	'summary_call_count' => '2',
	'summary_threshold' => '2',
	'summary_window_minutes' => '60',
	'summary_caller_kind' => 'none',
	'summary_caller_value' => '',
	'summary_did_value' => '',
], $invalidRetryTransport, function (): bool {
	return false;
});
assert_same('answered_no_response', (string)$invalidRetryResult['response'], 'invalid digits across interactive playback must retain the existing retry limit');
assert_same('7', (string)$invalidRetryResult['digit'], 'the final invalid digit must be preserved when retries are exhausted');

$remoteAcceptedTransport = new FakeInteractiveTransport([''], ['']);
$remoteAcceptChecks = 0;
$remoteAcceptedResult = $agiSession->run([
	'playback_target' => 'intro',
	'summary_mode' => 'repeat',
	'summary_call_count' => '2',
	'summary_threshold' => '2',
	'summary_window_minutes' => '60',
	'summary_caller_kind' => 'none',
	'summary_caller_value' => '',
	'summary_did_value' => '',
], $remoteAcceptedTransport, function () use (&$remoteAcceptChecks): bool {
	$remoteAcceptChecks++;
	return $remoteAcceptChecks >= 2;
});
assert_same('remote_accepted', (string)$remoteAcceptedResult['response'], 'remote acceptance must terminate the interactive AGI session cleanly');
assert_same('1', (string)($remoteAcceptedTransport->variables['REPEATCALLER_ALERT_COMPLETED'] ?? ''), 'remote acceptance must mark the answered call complete before exit audio');

final class TestClock {
	public string $now;

	public function __construct(string $now) {
		$this->now = $now;
	}

	public function now(): string {
		return $this->now;
	}
}

final class FakeEmailSender {
	/** @var array<int, array{recipient:string, subject:string, message:string}> */
	public array $calls = [];
	/** @var array<string, string> */
	public array $failuresByRecipient = [];

	public function __invoke(string $recipient, string $subject, string $message): array {
		$this->calls[] = [
			'recipient' => $recipient,
			'subject' => $subject,
			'message' => $message,
		];

		if (isset($this->failuresByRecipient[strtolower($recipient)])) {
			return ['status' => false, 'message' => $this->failuresByRecipient[strtolower($recipient)]];
		}

		return ['status' => true, 'message' => 'accepted'];
	}
}

final class FakeCallSender {
	/** @var array<int, array{destination:string, recordingId:string, callerId:string, context:array}> */
	public array $calls = [];
	/** @var array<string, string> */
	public array $failuresByDestination = [];
	/** @var callable|null */
	public $afterCall;

	public function __invoke(string $destination, string $recordingId, string $callerId = '', array $context = []): array {
		$this->calls[] = [
			'destination' => $destination,
			'recordingId' => $recordingId,
			'callerId' => $callerId,
			'context' => $context,
		];

		if (is_callable($this->afterCall)) {
			call_user_func($this->afterCall, $destination, $recordingId, $callerId, $context, $this);
		}

		if (isset($this->failuresByDestination[$destination])) {
			return ['status' => false, 'message' => $this->failuresByDestination[$destination]];
		}

		return ['status' => true, 'message' => 'queued'];
	}
}

final class FakeAstman {
	public array $requests = [];
	public array $dbValues = [];

	public function send_request(string $action, array $params) {
		$this->requests[] = ['action' => $action, 'params' => $params];
		if ($action === 'DBGet') {
			$key = (string)($params['Family'] ?? '') . '/' . (string)($params['Key'] ?? '');
			if (array_key_exists($key, $this->dbValues)) {
				return ['Response' => 'Success', 'Val' => (string)$this->dbValues[$key]];
			}
			return ['Response' => 'Error', 'Message' => 'Database entry not found'];
		}

		return ['Response' => 'Success', 'Message' => 'Success'];
	}
}

function create_alert_environment(TestClock $clock, FakeEmailSender $sender, ?FakeCallSender $callSender = null): array {
	$db = new PDO('sqlite::memory:');
	$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
	FreePBX::$database = $db;

	$db->exec(
		'CREATE TABLE repeatcaller_rules (
			id INTEGER PRIMARY KEY AUTOINCREMENT,
			name TEXT NOT NULL,
			enabled INTEGER NOT NULL DEFAULT 1,
			email_enabled INTEGER NOT NULL DEFAULT 0,
			email_recipients TEXT,
			alert_call_enabled INTEGER NOT NULL DEFAULT 0,
			alert_call_destinations TEXT,
			alert_call_strategy TEXT NOT NULL DEFAULT "ringall",
			alert_call_keep_trying INTEGER NOT NULL DEFAULT 1,
			alert_call_recording_id INTEGER,
			alert_call_handle_callerid_upstream INTEGER NOT NULL DEFAULT 0,
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
		'CREATE TABLE repeatcaller_incidents (
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
			accepted_by TEXT,
			accepted_at TEXT,
			accept_source TEXT,
			suppression_expires_at TEXT,
			cleared_at TEXT,
			created_at TEXT,
			updated_at TEXT
		)'
	);
	$db->exec(
		'CREATE TABLE repeatcaller_incident_alert_state (
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
		'CREATE TABLE repeatcaller_rule_schedules (
			id INTEGER PRIMARY KEY AUTOINCREMENT,
			rule_id INTEGER NOT NULL,
			day_of_week INTEGER NOT NULL,
			start_time TEXT NOT NULL,
			end_time TEXT NOT NULL,
			created_at TEXT,
			updated_at TEXT
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
			route_key TEXT,
			route_label TEXT,
			did_value TEXT,
			cid_value TEXT,
			created_at TEXT
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
		'CREATE TABLE repeatcaller_seen_calls (
			id INTEGER PRIMARY KEY AUTOINCREMENT,
			call_identity TEXT NOT NULL,
			identity_type TEXT NOT NULL,
			fingerprint TEXT NOT NULL,
			linkedid TEXT,
			uniqueid TEXT,
			caller_raw TEXT,
			caller_normalized TEXT,
			inbound_route_key TEXT,
			did_value TEXT,
			call_started_at TEXT,
			call_completed_at TEXT,
			disposition TEXT,
			source_context TEXT,
			processed_at TEXT
		)'
	);

	$repository = new RepeatCallerRepository($db);
	$processor = new IncidentAlertProcessor(
		$repository,
		$sender,
		[$clock, 'now'],
		$callSender
	);

	return [$db, $processor];
}

function insert_rule(PDO $db, array $rule): int {
	$stmt = $db->prepare(
		'INSERT INTO repeatcaller_rules
			(name, enabled, email_enabled, email_recipients, alert_call_enabled, alert_call_destinations, alert_call_strategy, alert_call_keep_trying, alert_call_recording_id, alert_call_handle_callerid_upstream, alert_call_callerid, mode, threshold_count, observation_window_minutes, caller_mode,
			 exclude_withheld, did_scope_mode, repeat_mode_override, suppression_minutes_override, created_at, updated_at)
		 VALUES
			(?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)' 
	);
	$stmt->execute([
		$rule['name'] ?? 'Rule',
		$rule['enabled'] ?? 1,
		$rule['email_enabled'] ?? 0,
		$rule['email_recipients'] ?? ((int)($rule['email_enabled'] ?? 0) === 1 ? 'alerts@example.invalid' : null),
		$rule['alert_call_enabled'] ?? 0,
		$rule['alert_call_destinations'] ?? null,
		$rule['alert_call_strategy'] ?? 'ringall',
		$rule['alert_call_keep_trying'] ?? 1,
		$rule['alert_call_recording_id'] ?? null,
		$rule['alert_call_handle_callerid_upstream'] ?? 0,
		$rule['alert_call_callerid'] ?? null,
		$rule['mode'] ?? 'repeat',
		$rule['threshold_count'] ?? 2,
		$rule['observation_window_minutes'] ?? 60,
		$rule['caller_mode'] ?? 'any',
		$rule['exclude_withheld'] ?? 0,
		$rule['did_scope_mode'] ?? 'all',
		$rule['repeat_mode_override'] ?? null,
		$rule['suppression_minutes_override'] ?? null,
		$rule['created_at'] ?? '2026-07-13 09:00:00',
		$rule['updated_at'] ?? '2026-07-13 09:00:00',
	]);

	return (int)$db->lastInsertId();
}

function insert_incident(PDO $db, array $incident): int {
	$ruleSnapshot = $db->prepare('SELECT threshold_count, observation_window_minutes FROM repeatcaller_rules WHERE id = ? LIMIT 1');
	$ruleSnapshot->execute([$incident['rule_id']]);
	$ruleRow = $ruleSnapshot->fetch(PDO::FETCH_ASSOC) ?: [];
	$thresholdCount = array_key_exists('threshold_count', $incident) ? (int)$incident['threshold_count'] : (int)($ruleRow['threshold_count'] ?? 0);
	$observationWindowMinutes = array_key_exists('observation_window_minutes', $incident) ? (int)$incident['observation_window_minutes'] : (int)($ruleRow['observation_window_minutes'] ?? 0);
	$stmt = $db->prepare(
		'INSERT INTO repeatcaller_incidents
			(rule_id, subject_key, active_subject_key, subject_label, caller_normalized, caller_display,
			 withheld_caller, mode, threshold_count, observation_window_minutes, first_matched_at, last_matched_at, matched_call_count, state,
			 accepted_by, accepted_at, accept_source, suppression_expires_at, cleared_at, created_at, updated_at)
		 VALUES
			(?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
	);
	$stmt->execute([
		$incident['rule_id'],
		$incident['subject_key'],
		$incident['active_subject_key'] ?? (((string)($incident['state'] ?? 'active')) === 'active' ? ($incident['rule_id'] . '|' . $incident['subject_key']) : null),
		$incident['subject_label'] ?? $incident['subject_key'],
		$incident['caller_normalized'] ?? null,
		$incident['caller_display'] ?? $incident['subject_key'],
		$incident['withheld_caller'] ?? 0,
		$incident['mode'] ?? 'repeat',
		$thresholdCount,
		$observationWindowMinutes,
		$incident['first_matched_at'],
		$incident['last_matched_at'] ?? $incident['first_matched_at'],
		$incident['matched_call_count'] ?? 2,
		$incident['state'] ?? 'active',
		$incident['accepted_by'] ?? null,
		$incident['accepted_at'] ?? null,
		$incident['accept_source'] ?? null,
		$incident['suppression_expires_at'] ?? null,
		$incident['cleared_at'] ?? null,
		$incident['created_at'] ?? $incident['first_matched_at'],
		$incident['updated_at'] ?? $incident['first_matched_at'],
	]);

	return (int)$db->lastInsertId();
}

function insert_seen_call(PDO $db, array $call): int {
	$stmt = $db->prepare(
		'INSERT INTO repeatcaller_seen_calls
			(call_identity, identity_type, fingerprint, linkedid, uniqueid, caller_raw, caller_normalized,
			 inbound_route_key, did_value, call_started_at, call_completed_at, disposition, source_context, processed_at)
		 VALUES
			(?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
	);
	$stmt->execute([
		$call['call_identity'] ?? uniqid('call-', true),
		$call['identity_type'] ?? 'linkedid',
		$call['fingerprint'] ?? uniqid('fp-', true),
		$call['linkedid'] ?? null,
		$call['uniqueid'] ?? null,
		$call['caller_raw'] ?? '',
		$call['caller_normalized'] ?? null,
		$call['inbound_route_key'] ?? null,
		$call['did_value'] ?? null,
		$call['call_started_at'] ?? ($call['call_completed_at'] ?? '2026-07-13 10:00:00'),
		$call['call_completed_at'] ?? '2026-07-13 10:00:00',
		$call['disposition'] ?? 'ANSWERED',
		$call['source_context'] ?? 'from-pstn',
		$call['processed_at'] ?? ($call['call_completed_at'] ?? '2026-07-13 10:00:00'),
	]);

	return (int)$db->lastInsertId();
}

function count_history(PDO $db, string $where = '1=1'): int {
	return (int)$db->query('SELECT COUNT(*) FROM repeatcaller_incident_alert_history WHERE ' . $where)->fetchColumn();
}

function settings(array $overrides = []): array {
	return array_merge([
		'alert_enabled' => '1',
		'global_snoozed_until' => '',
		'alert_history_prune_policy' => 'never',
	], $overrides);
}

FreePBX::$config = ['FREEPBX_SYSTEM_IDENT' => 'PBXSRV20-ONP'];
FreePBX::$recordings = [
	['id' => 41, 'displayname' => 'Intro', 'filename' => 'intro.wav', 'fcode_lang' => 'en_GB'],
];
FreePBX::$soundlangLanguage = 'en_GB';
$repeatcaller = new \FreePBX\modules\Repeatcaller(new stdClass());
$languageResolver = new ReflectionMethod(\FreePBX\modules\Repeatcaller::class, 'resolveAlertCallPlaybackLanguage');
$languageResolver->setAccessible(true);
$classSource = file_get_contents(__DIR__ . '/../Repeatcaller.class.php');
assert_true($classSource !== false, 'Repeatcaller.class.php should be readable for language fallback contract checks');
assert_true(strpos($classSource, 'en_US') === false && strpos($classSource, 'en_GB') === false, 'language resolution code must not hard-code en_US or en_GB');
assert_true(strpos($classSource, "'Account' => 'repeatcaller_alert_internal'") !== false, 'alert call originate should apply an internal account marker for module-originated call legs');
assert_true(strpos($classSource, 'REPEATCALLER_INTERNAL_ORIGIN=1,__REPEATCALLER_INTERNAL_ORIGIN=1') !== false, 'alert call originate variables should carry explicit internal-origin marker flags for module-owned legs');
assert_true(strpos($classSource, 'if ($callerId !== \'\') {') !== false && strpos($classSource, '$params[\'CallerID\'] = $callerId;') !== false, 'originate should only include CallerID when Repeat Caller is explicitly setting a nonblank value');
$alertProcessorSource = file_get_contents(__DIR__ . '/../src/IncidentAlertProcessor.php');
assert_true($alertProcessorSource !== false, 'IncidentAlertProcessor.php should be readable for system identifier contract checks');
assert_true(strpos($alertProcessorSource, "FreePBX::Config()->get('FREEPBX_SYSTEM_IDENT')") !== false, 'email output should source the system identifier from the supported FreePBX Config API');

$installSource = file_get_contents(__DIR__ . '/../install.php');
assert_true($installSource !== false, 'install.php should be readable for language fallback contract checks');
assert_true(strpos($installSource, 'en_US') === false && strpos($installSource, 'en_GB') === false, 'install-time dialplan generation must not hard-code en_US or en_GB');
assert_same('en_GB', (string)$languageResolver->invoke($repeatcaller, '41'), 'selected System Recording should preserve its fcode_lang value');
assert_same('en_GB', (string)$languageResolver->invoke($repeatcaller, ''), 'missing System Recording should fall back to the FreePBX sound language default');

// 1, 2, 3, 4, 5, 6, 7, 19 in one scenario
$clock = new TestClock('2026-07-13 10:00:00');
$sender = new FakeEmailSender();
[$db, $processor] = create_alert_environment($clock, $sender);
$ruleEmail = insert_rule($db, ['name' => 'Email Rule', 'email_enabled' => 1, 'repeat_mode_override' => 'never']);
$ruleNoEmail = insert_rule($db, ['name' => 'GUI Rule', 'email_enabled' => 0, 'repeat_mode_override' => 'never']);
$incidentA = insert_incident($db, [
	'rule_id' => $ruleEmail,
	'subject_key' => '+441234500001',
	'subject_label' => '+441234500001',
	'first_matched_at' => '2026-07-13 10:00:00',
	'suppression_expires_at' => '2026-07-13 11:00:00',
]);
$incidentB = insert_incident($db, [
	'rule_id' => $ruleNoEmail,
	'subject_key' => '+441234500002',
	'subject_label' => '+441234500002',
	'first_matched_at' => '2026-07-13 10:00:00',
	'suppression_expires_at' => '2026-07-13 11:00:00',
]);

$summary = $processor->run(settings());
assert_same(2, $summary['initial_events'], 'initial pass should process both active incidents');
assert_same(1, count_history($db, "incident_id = {$incidentA} AND action_type = 'gui' AND event_type = 'initial'"), 'active incident should create one initial GUI event');
assert_same(1, count_history($db, "incident_id = {$incidentA} AND action_type = 'email' AND event_type = 'initial'"), 'email-enabled rule should queue one initial email event');
assert_same(0, count_history($db, "incident_id = {$incidentB} AND action_type = 'email'"), 'email must not queue when rule email is disabled');
assert_same(1, count_history($db, "incident_id = {$incidentB} AND action_type = 'gui'"), 'GUI history should still be recorded when email is disabled');
assert_same(1, count($sender->calls), 'email sender should run only for email-enabled incident');
$initialEmailSubject = (string)$sender->calls[0]['subject'];
assert_same('Repeat Caller: incident started [Email Rule] +441234500001', $initialEmailSubject, 'repeat-mode email subject should keep the stored subject label');
$initialEmailSubjectBody = (string)$sender->calls[0]['message'];
assert_true(strpos($initialEmailSubjectBody, 'Repeat Caller incident alert from PBXSRV20-ONP') !== false, 'email body should include the configured FreePBX System Identifier in the opening line');
$emailMessage = (string)$sender->calls[0]['message'];
assert_true(strpos($emailMessage, 'Mode: Repeat') !== false, 'email output should render repeat detection mode as Repeat');
	assert_true(strpos($emailMessage, 'Alert Reminder: Never') !== false, 'email output should render the effective reminder mode as Alert Reminder');
assert_true(strpos($emailMessage, 'Mode: repeat') === false && strpos($emailMessage, 'Mode: invert') === false, 'email output should not expose lowercase detection mode codes');
assert_true(strpos($emailMessage, 'Rule Repeat Mode:') === false && strpos($emailMessage, 'Effective Repeat Mode:') === false, 'email output should not expose duplicate repeat-mode labels');
assert_true(strpos($emailMessage, 'This incident has not been accepted. You can accept it by phone if Alert Calls are enabled, or through the GUI.') !== false, 'email output should include customer-facing accepted-capability wording without implying post-accept notifications');
assert_true(strpos($emailMessage, 'Alert Call follows the same normal stage cadence as GUI and email when enabled for the rule.') === false, 'email output should not expose internal stage-cadence implementation wording');

$invertEmailClock = new TestClock('2026-07-13 10:05:00');
$invertEmailSender = new FakeEmailSender();
[$invertEmailDb, $invertEmailProcessor] = create_alert_environment($invertEmailClock, $invertEmailSender);
$invertEmailRule = insert_rule($invertEmailDb, ['name' => 'Invert Email Rule', 'email_enabled' => 1, 'mode' => 'invert', 'threshold_count' => 2, 'observation_window_minutes' => 3, 'repeat_mode_override' => 'never']);
insert_incident($invertEmailDb, [
	'rule_id' => $invertEmailRule,
	'subject_key' => '+441234500099',
	'subject_label' => 'Any caller',
	'mode' => 'invert',
	'first_matched_at' => '2026-07-13 10:05:00',
	'suppression_expires_at' => '2026-07-13 11:05:00',
]);
$invertEmailProcessor->run(settings());
assert_same(1, count($invertEmailSender->calls), 'invert email scenario should send one email');
assert_same('Repeat Caller: incident started [Invert Email Rule] Fewer than 2 calls within a 3-minute window', (string)$invertEmailSender->calls[0]['subject'], 'invert email subject should use the stored threshold/window summary instead of Any caller');

$clockGlobalEmail = new TestClock('2026-07-13 10:10:00');
$senderGlobalEmail = new FakeEmailSender();
[$dbGlobalEmail, $processorGlobalEmail] = create_alert_environment($clockGlobalEmail, $senderGlobalEmail);
$ruleGlobalEmail = insert_rule($dbGlobalEmail, ['name' => 'Rule Email Mode', 'email_enabled' => 1, 'repeat_mode_override' => '5m']);
$incidentGlobalEmail = insert_incident($dbGlobalEmail, [
	'rule_id' => $ruleGlobalEmail,
	'subject_key' => '+441234599999',
	'subject_label' => '+441234599999',
	'first_matched_at' => '2026-07-13 10:10:00',
	'suppression_expires_at' => '2026-07-13 11:10:00',
]);
$processorGlobalEmail->run(settings());
assert_same(1, count($senderGlobalEmail->calls), 'rule repeat-mode email scenario should send one email');
$globalEmailMessage = (string)$senderGlobalEmail->calls[0]['message'];
	assert_true(strpos($globalEmailMessage, 'Alert Reminder: Every 5 Minutes') !== false, 'email output should render the effective 5m repeat mode as Alert Reminder');
	assert_true(strpos($globalEmailMessage, 'Rule Repeat Mode:') === false && strpos($globalEmailMessage, 'Effective Repeat Mode:') === false, 'email output should not expose duplicate repeat-mode labels in the alert email');
assert_true(strpos($globalEmailMessage, 'Event: Initial') !== false, 'email output should render event type label as Initial');

FreePBX::$config = [];
$fallbackClock = new TestClock('2026-07-13 10:12:00');
$fallbackSender = new FakeEmailSender();
[$fallbackDb, $fallbackProcessor] = create_alert_environment($fallbackClock, $fallbackSender);
$fallbackRule = insert_rule($fallbackDb, ['name' => 'Fallback Email Rule', 'email_enabled' => 1, 'repeat_mode_override' => 'never']);
insert_incident($fallbackDb, [
	'rule_id' => $fallbackRule,
	'subject_key' => '+441234511111',
	'subject_label' => '+441234511111',
	'first_matched_at' => '2026-07-13 10:12:00',
	'suppression_expires_at' => '2026-07-13 11:12:00',
]);
$fallbackProcessor->run(settings());
assert_same(1, count($fallbackSender->calls), 'fallback identifier scenario should send one email');
assert_true(strpos((string)$fallbackSender->calls[0]['message'], 'Repeat Caller incident alert from unknown system') !== false, 'email body should use a sensible fallback when the FreePBX System Identifier is unavailable');

$summaryRerun = $processor->run(settings());
assert_same(0, $summaryRerun['initial_events'], 're-run at same timestamp should not create duplicate initial stage');
assert_same(1, count_history($db, "incident_id = {$incidentA} AND action_type = 'gui' AND event_type = 'initial'"), 'same timestamp rerun should keep a single initial GUI record');

$sender->failuresByRecipient['alerts@example.invalid'] = 'smtp down';

$callClock = new TestClock('2026-07-13 10:00:00');
$callEmailSender = new FakeEmailSender();
$callSender = new FakeCallSender();
[$callDb, $callProcessor] = create_alert_environment($callClock, $callEmailSender, $callSender);
$callRule = insert_rule($callDb, [
	'name' => 'Call Rule',
	'email_enabled' => 0,
	'alert_call_enabled' => 1,
	'alert_call_destinations' => '100,101',
	'alert_call_recording_id' => 55,
	'alert_call_handle_callerid_upstream' => 0,
	'alert_call_callerid' => '5551234',
	'repeat_mode_override' => 'never',
]);
$callIncident = insert_incident($callDb, [
	'rule_id' => $callRule,
	'subject_key' => '+441234500003',
	'subject_label' => '+441234500003',
	'first_matched_at' => '2026-07-13 10:00:00',
	'suppression_expires_at' => '2026-07-13 11:00:00',
]);
$callSummary = $callProcessor->run(settings());
assert_same(2, count_history($callDb, "incident_id = {$callIncident} AND action_type = 'alert_call' AND event_type = 'initial'"), 'enabled call alerts should create alert_call history rows for each configured destination');
assert_same(2, count($callSender->calls), 'enabled call alerts should attempt each configured outbound call');
assert_same(55, (int)$callSender->calls[0]['recordingId'], 'configured recording id should be passed to call transport');
assert_same('5551234', (string)$callSender->calls[0]['callerId'], 'configured caller ID should be passed to call transport');
$upstreamClock = new TestClock('2026-07-13 10:10:00');
$upstreamSender = new FakeCallSender();
[$upstreamDb, $upstreamProcessor] = create_alert_environment($upstreamClock, new FakeEmailSender(), $upstreamSender);
$upstreamRule = insert_rule($upstreamDb, [
	'name' => 'Upstream Caller ID Rule',
	'email_enabled' => 0,
	'alert_call_enabled' => 1,
	'alert_call_destinations' => '102',
	'alert_call_recording_id' => 55,
	'alert_call_handle_callerid_upstream' => 1,
	'alert_call_callerid' => '5559999',
	'repeat_mode_override' => 'never',
]);
$upstreamIncident = insert_incident($upstreamDb, [
	'rule_id' => $upstreamRule,
	'subject_key' => '+441234500004',
	'subject_label' => '+441234500004',
	'first_matched_at' => '2026-07-13 10:10:00',
	'suppression_expires_at' => '2026-07-13 11:10:00',
]);
$upstreamSummary = $upstreamProcessor->run(settings());
assert_same(1, count($upstreamSender->calls), 'upstream caller-id rules should still attempt their alert call');
assert_same('', (string)$upstreamSender->calls[0]['callerId'], 'upstream caller-id handling should omit Caller ID from the alert-call transport');
assert_same(1, $upstreamSummary['alert_call_sent'], 'upstream caller-id handling should still count a successful alert call as sent');

assert_true((int)$callSender->calls[0]['context']['history_id'] > 0, 'alert call transport should receive the exact alert-history attempt id');
assert_same($callIncident, (int)$callSender->calls[0]['context']['incident_id'], 'alert call transport should receive the exact incident id');
assert_same('100', (string)$callSender->calls[0]['context']['recipient'], 'alert call transport should receive the alert destination recipient');
assert_same('repeat', (string)$callSender->calls[0]['context']['summary_mode'], 'normal alert transport must carry repeat mode into the generated playback context');
assert_same('2', (string)$callSender->calls[0]['context']['summary_call_count'], 'normal alert transport must carry the actual incident call count into the generated playback context');
assert_same('2', (string)$callSender->calls[0]['context']['summary_threshold'], 'normal alert transport must also carry the configured threshold for completeness');
assert_same('60', (string)$callSender->calls[0]['context']['summary_window_minutes'], 'normal alert transport must carry the configured observation window into the generated playback context');
assert_same('numeric', (string)$callSender->calls[0]['context']['summary_caller_kind'], 'fallback incident data must still announce one actual distinct caller even when the rule has no caller restriction');
assert_same('441234500003', (string)$callSender->calls[0]['context']['summary_caller_value'], 'fallback incident data must still carry caller digits for one actual distinct caller');
assert_same('', (string)$callSender->calls[0]['context']['summary_did_value'], 'legacy subject labels without route detail must not invent a DID value');
assert_same(2, $callSummary['alert_call_sent'], 'successful call alerts should be counted as sent');
$callSentRows = $callDb->query("SELECT successful_at FROM repeatcaller_incident_alert_history WHERE incident_id = {$callIncident} AND action_type = 'alert_call' AND delivery_status = 'sent' ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);
assert_same(2, count($callSentRows), 'successful call alerts should persist sent delivery status rows for each destination');
assert_same('2026-07-13 10:00:00', (string)($callSentRows[0]['successful_at'] ?? ''), 'successful call alerts should persist the originate success timestamp');

$noRecordingClock = new TestClock('2026-07-13 15:00:00');
$noRecordingSender = new FakeCallSender();
[$noRecordingDb, $noRecordingProcessor] = create_alert_environment($noRecordingClock, new FakeEmailSender(), $noRecordingSender);
$noRecordingRule = insert_rule($noRecordingDb, [
	'name' => 'No Recording Rule',
	'email_enabled' => 0,
	'alert_call_enabled' => 1,
	'alert_call_destinations' => '999',
	'alert_call_recording_id' => null,
	'caller_mode' => 'specific_only',
	'did_scope_mode' => 'selected',
	'threshold_count' => 3,
	'observation_window_minutes' => 5,
	'repeat_mode_override' => 'never',
]);
insert_incident($noRecordingDb, [
	'rule_id' => $noRecordingRule,
	'subject_key' => '+441112223333',
	'subject_label' => '+441112223333 @ 02030040050',
	'caller_display' => '1112223333',
	'matched_call_count' => 3,
	'first_matched_at' => $noRecordingClock->now,
	'suppression_expires_at' => '2026-07-13 16:00:00',
]);
$noRecordingProcessor->run(settings());
assert_same(1, count($noRecordingSender->calls), 'alert calls must still be attempted when no System Recording is selected');
assert_same('', (string)$noRecordingSender->calls[0]['recordingId'], 'no System Recording selected by default must pass an empty recording id to the alert-call sender');
assert_same('repeat', (string)$noRecordingSender->calls[0]['context']['summary_mode'], 'no-recording alerts must still carry repeat mode');
assert_same('3', (string)$noRecordingSender->calls[0]['context']['summary_call_count'], 'normal alerts must use the actual incident call count');
assert_same('3', (string)$noRecordingSender->calls[0]['context']['summary_threshold'], 'normal alerts must still carry the configured threshold');
assert_same('5', (string)$noRecordingSender->calls[0]['context']['summary_window_minutes'], 'all alerts must carry the configured window minutes');
assert_same('numeric', (string)$noRecordingSender->calls[0]['context']['summary_caller_kind'], 'numeric caller incidents without a System Recording must still carry numeric caller-kind');
assert_same('1112223333', (string)$noRecordingSender->calls[0]['context']['summary_caller_value'], 'numeric caller incidents without a System Recording must still carry caller digits');
assert_same('02030040050', (string)$noRecordingSender->calls[0]['context']['summary_did_value'], 'numeric DID incidents without a System Recording must still carry DID digits');

$didOnlyClock = new TestClock('2026-07-13 15:00:30');
$didOnlySender = new FakeCallSender();
[$didOnlyDb, $didOnlyProcessor] = create_alert_environment($didOnlyClock, new FakeEmailSender(), $didOnlySender);
$didOnlyRule = insert_rule($didOnlyDb, [
	'name' => 'Did Only Rule',
	'email_enabled' => 0,
	'alert_call_enabled' => 1,
	'alert_call_destinations' => '997',
	'alert_call_recording_id' => 55,
	'caller_mode' => 'any',
	'did_scope_mode' => 'selected',
	'threshold_count' => 4,
	'observation_window_minutes' => 5,
	'repeat_mode_override' => 'never',
]);
insert_incident($didOnlyDb, [
	'rule_id' => $didOnlyRule,
	'subject_key' => '+447700900001',
	'subject_label' => '+447700900001 @ 02039466572',
	'caller_display' => '07700900001',
	'matched_call_count' => 4,
	'first_matched_at' => $didOnlyClock->now,
	'suppression_expires_at' => '2026-07-13 16:00:30',
]);
insert_seen_call($didOnlyDb, [
	'call_identity' => 'did-only-1',
	'fingerprint' => 'did-only-1',
	'caller_raw' => '07700900001',
	'caller_normalized' => '+447700900001',
	'inbound_route_key' => 'route-a',
	'did_value' => '02039466572',
	'call_completed_at' => '2026-07-13 15:00:30',
]);
$didOnlyProcessor->run(settings());
assert_same('numeric', (string)$didOnlySender->calls[0]['context']['summary_caller_kind'], 'Any Caller alerts must still announce one actual distinct incident caller');
assert_same('07700900001', (string)$didOnlySender->calls[0]['context']['summary_caller_value'], 'Any Caller alerts must carry the actual distinct caller digits from the triggering call rows');
assert_same('02039466572', (string)$didOnlySender->calls[0]['context']['summary_did_value'], 'DID-only alerts must still send DID digits');

$callerOnlyClock = new TestClock('2026-07-13 15:00:45');
$callerOnlySender = new FakeCallSender();
[$callerOnlyDb, $callerOnlyProcessor] = create_alert_environment($callerOnlyClock, new FakeEmailSender(), $callerOnlySender);
$callerOnlyRule = insert_rule($callerOnlyDb, [
	'name' => 'Caller Only Rule',
	'email_enabled' => 0,
	'alert_call_enabled' => 1,
	'alert_call_destinations' => '996',
	'alert_call_recording_id' => 55,
	'caller_mode' => 'specific_only',
	'did_scope_mode' => 'all',
	'observation_window_minutes' => 1,
	'repeat_mode_override' => 'never',
]);
insert_incident($callerOnlyDb, [
	'rule_id' => $callerOnlyRule,
	'subject_key' => '+447700900002',
	'subject_label' => '+447700900002',
	'caller_display' => '07700900002',
	'matched_call_count' => 1,
	'first_matched_at' => $callerOnlyClock->now,
	'suppression_expires_at' => '2026-07-13 16:00:45',
]);
insert_seen_call($callerOnlyDb, [
	'call_identity' => 'caller-only-1',
	'fingerprint' => 'caller-only-1',
	'caller_raw' => '07700900002',
	'caller_normalized' => '+447700900002',
	'inbound_route_key' => 'route-b',
	'did_value' => '',
	'call_completed_at' => '2026-07-13 15:00:45',
]);
$callerOnlyProcessor->run(settings());
assert_same('1', (string)$callerOnlySender->calls[0]['context']['summary_call_count'], 'normal alerts must preserve singular actual incident call counts');
assert_same('1', (string)$callerOnlySender->calls[0]['context']['summary_window_minutes'], 'alerts must preserve singular configured window minutes');
assert_same('numeric', (string)$callerOnlySender->calls[0]['context']['summary_caller_kind'], 'caller-only alerts must send numeric caller-kind');
assert_same('07700900002', (string)$callerOnlySender->calls[0]['context']['summary_caller_value'], 'caller-only alerts must send caller digits');
assert_same('', (string)$callerOnlySender->calls[0]['context']['summary_did_value'], 'caller-only alerts must not send DID digits');

$invertClock = new TestClock('2026-07-13 15:00:50');
$invertSender = new FakeCallSender();
[$invertDb, $invertProcessor] = create_alert_environment($invertClock, new FakeEmailSender(), $invertSender);
$invertRule = insert_rule($invertDb, [
	'name' => 'Invert Rule',
	'email_enabled' => 0,
	'alert_call_enabled' => 1,
	'alert_call_destinations' => '995',
	'alert_call_recording_id' => 55,
	'mode' => 'invert',
	'caller_mode' => 'specific_only',
	'did_scope_mode' => 'selected',
	'threshold_count' => 1,
	'observation_window_minutes' => 1,
	'repeat_mode_override' => 'never',
]);
insert_incident($invertDb, [
	'rule_id' => $invertRule,
	'subject_key' => '+447700900003',
	'subject_label' => '+447700900003 @ 02039466573',
	'caller_display' => '07700900003',
	'mode' => 'invert',
	'matched_call_count' => 0,
	'first_matched_at' => $invertClock->now,
	'suppression_expires_at' => '2026-07-13 16:00:50',
]);
insert_seen_call($invertDb, [
	'call_identity' => 'invert-1',
	'fingerprint' => 'invert-1',
	'caller_raw' => '07700900003',
	'caller_normalized' => '+447700900003',
	'inbound_route_key' => 'route-c',
	'did_value' => '02039466573',
	'call_completed_at' => '2026-07-13 15:00:50',
]);
$invertProcessor->run(settings());
assert_same('repeat', (string)$invertSender->calls[0]['context']['summary_mode'], 'invert alerts must use the same generated playback mode path as repeat alerts');
assert_same(55, (int)$invertSender->calls[0]['recordingId'], 'invert alerts must pass configured System Recording ID to call transport');
assert_same('0', (string)$invertSender->calls[0]['context']['summary_call_count'], 'invert alerts may carry the observed incident count but must not speak it as the threshold condition');
assert_same('1', (string)$invertSender->calls[0]['context']['summary_threshold'], 'invert alerts must carry the configured threshold for less-than wording');
assert_same('1', (string)$invertSender->calls[0]['context']['summary_window_minutes'], 'invert alerts must carry the configured window minutes');
assert_same('numeric', (string)$invertSender->calls[0]['context']['summary_caller_kind'], 'invert caller-and-DID alerts must still carry caller digits');
assert_same('07700900003', (string)$invertSender->calls[0]['context']['summary_caller_value'], 'invert caller-and-DID alerts must send caller digits');
assert_same('02039466573', (string)$invertSender->calls[0]['context']['summary_did_value'], 'invert caller-and-DID alerts must send DID digits');

$invertFallbackClock = new TestClock('2026-07-13 15:02:00');
$invertFallbackSender = new FakeCallSender();
[$invertFallbackDb, $invertFallbackProcessor] = create_alert_environment($invertFallbackClock, new FakeEmailSender(), $invertFallbackSender);
$invertFallbackRule = insert_rule($invertFallbackDb, [
	'name' => 'Invert Fallback Rule',
	'email_enabled' => 0,
	'alert_call_enabled' => 1,
	'alert_call_destinations' => '997',
	'alert_call_recording_id' => 55,
	'mode' => 'invert',
	'caller_mode' => 'specific_only',
	'did_scope_mode' => 'selected',
	'threshold_count' => 1,
	'observation_window_minutes' => 1,
	'repeat_mode_override' => 'never',
]);
$sendAlertCallMethod = new ReflectionMethod(IncidentAlertProcessor::class, 'sendAlertCall');
$sendAlertCallMethod->setAccessible(true);
$fallbackSend = $sendAlertCallMethod->invoke($invertFallbackProcessor, [
	'id' => 9001,
	'incident_id' => 9002,
	'rule_id' => $invertFallbackRule,
	'subject_key' => '+447700900099',
	'subject_label' => '+447700900099 @ 02030040099',
	'recipient' => '997',
	'alert_call_recording_id' => '',
	'alert_call_callerid' => '',
	'mode' => 'invert',
	'matched_call_count' => 0,
	'threshold_count' => 1,
	'observation_window_minutes' => 1,
	'first_matched_at' => '2026-07-13 15:02:00',
	'last_matched_at' => '2026-07-13 15:02:00',
	'caller_display' => '07700900099',
	'caller_normalized' => '+447700900099',
	'withheld_caller' => 0,
	'did_scope_mode' => 'selected',
]);
assert_true(!empty($fallbackSend['status']), 'invert fallback send path should still queue the call when rule recording exists');
assert_same(55, (int)$invertFallbackSender->calls[0]['recordingId'], 'invert alerts with missing deliverable recording id should fall back to the configured rule recording id');

$invertAggregateClock = new TestClock('2026-07-13 15:00:55');
$invertAggregateSender = new FakeCallSender();
[$invertAggregateDb, $invertAggregateProcessor] = create_alert_environment($invertAggregateClock, new FakeEmailSender(), $invertAggregateSender);
$invertAggregateRule = insert_rule($invertAggregateDb, [
	'name' => 'Invert Aggregate Rule',
	'email_enabled' => 0,
	'alert_call_enabled' => 1,
	'alert_call_destinations' => '996',
	'alert_call_recording_id' => 55,
	'mode' => 'invert',
	'caller_mode' => 'any',
	'did_scope_mode' => 'all',
	'threshold_count' => 1,
	'observation_window_minutes' => 1,
	'repeat_mode_override' => 'never',
]);
insert_incident($invertAggregateDb, [
	'rule_id' => $invertAggregateRule,
	'subject_key' => '__invert_rule__' . $invertAggregateRule,
	'subject_label' => 'Any caller @ 02039990001',
	'caller_display' => 'Any caller',
	'mode' => 'invert',
	'matched_call_count' => 0,
	'first_matched_at' => $invertAggregateClock->now,
	'suppression_expires_at' => '2026-07-13 16:00:55',
]);
$invertAggregateProcessor->run(settings());
assert_same(1, count($invertAggregateSender->calls), 'invert aggregate incidents should still create one call attempt');
assert_same('none', (string)$invertAggregateSender->calls[0]['context']['summary_caller_kind'], 'invert aggregate incidents must omit caller identity instead of mapping to unknown caller');
assert_true((string)$invertAggregateSender->calls[0]['context']['summary_caller_kind'] !== 'unknown', 'invert aggregate incidents must not map to unknown caller-kind');
assert_same('', (string)$invertAggregateSender->calls[0]['context']['summary_caller_value'], 'invert aggregate incidents must not carry caller digits');
assert_same('02039990001', (string)$invertAggregateSender->calls[0]['context']['summary_did_value'], 'invert aggregate incidents must keep DID digits when available');

$unknownClock = new TestClock('2026-07-13 15:01:00');
$unknownSender = new FakeCallSender();
[$unknownDb, $unknownProcessor] = create_alert_environment($unknownClock, new FakeEmailSender(), $unknownSender);
$unknownRule = insert_rule($unknownDb, [
	'name' => 'Unknown Caller Rule',
	'email_enabled' => 0,
	'alert_call_enabled' => 1,
	'alert_call_destinations' => '998',
	'alert_call_recording_id' => 55,
	'caller_mode' => 'withheld_only',
	'did_scope_mode' => 'selected',
	'repeat_mode_override' => 'never',
]);
insert_incident($unknownDb, [
	'rule_id' => $unknownRule,
	'subject_key' => 'withheld',
	'subject_label' => 'withheld @ 02039466571',
	'caller_display' => 'Unavailable',
	'withheld_caller' => 1,
	'first_matched_at' => $unknownClock->now,
	'suppression_expires_at' => '2026-07-13 16:01:00',
]);
insert_seen_call($unknownDb, [
	'call_identity' => 'unknown-1',
	'fingerprint' => 'unknown-1',
	'caller_raw' => 'Unavailable',
	'caller_normalized' => 'withheld',
	'inbound_route_key' => 'route-d',
	'did_value' => '02039466571',
	'call_completed_at' => '2026-07-13 15:01:00',
]);
$unknownProcessor->run(settings());
assert_same(1, count($unknownSender->calls), 'unknown-caller incidents should still create one call attempt');
assert_same('unknown', (string)$unknownSender->calls[0]['context']['summary_caller_kind'], 'unknown or withheld caller identities must use unknown caller-kind');
assert_same('', (string)$unknownSender->calls[0]['context']['summary_caller_value'], 'unknown or withheld caller identities must not send numeric caller digits');
assert_same('02039466571', (string)$unknownSender->calls[0]['context']['summary_did_value'], 'unknown caller incidents must still carry DID digits');

$distinctClock = new TestClock('2026-07-13 16:10:00');
$distinctSender = new FakeCallSender();
[$distinctDb, $distinctProcessor] = create_alert_environment($distinctClock, new FakeEmailSender(), $distinctSender);
$distinctRule = insert_rule($distinctDb, [
	'name' => 'Distinct Values Rule',
	'email_enabled' => 0,
	'alert_call_enabled' => 1,
	'alert_call_destinations' => '991',
	'alert_call_recording_id' => 55,
	'did_scope_mode' => 'selected',
	'threshold_count' => 3,
	'observation_window_minutes' => 5,
	'repeat_mode_override' => 'never',
]);
insert_incident($distinctDb, [
	'rule_id' => $distinctRule,
	'subject_key' => '+447700900010',
	'subject_label' => '+447700900010 @ 02070000010',
	'caller_display' => '07700900010',
	'matched_call_count' => 3,
	'first_matched_at' => $distinctClock->now,
	'suppression_expires_at' => '2026-07-13 17:10:00',
]);
insert_seen_call($distinctDb, [
	'call_identity' => 'distinct-1',
	'fingerprint' => 'distinct-1',
	'caller_raw' => '07700900010',
	'caller_normalized' => '+447700900010',
	'inbound_route_key' => 'route-e',
	'did_value' => '02070000010',
	'call_completed_at' => '2026-07-13 16:10:00',
]);
$distinctProcessor->run(settings());
assert_same('numeric', (string)$distinctSender->calls[0]['context']['summary_caller_kind'], 'one distinct numeric Caller ID must be announced');
assert_same('07700900010', (string)$distinctSender->calls[0]['context']['summary_caller_value'], 'one distinct numeric Caller ID must be carried digit-for-digit');
assert_same('02070000010', (string)$distinctSender->calls[0]['context']['summary_did_value'], 'one distinct DID must be announced when present');

$multiCallerClock = new TestClock('2026-07-13 16:11:00');
$multiCallerSender = new FakeCallSender();
[$multiCallerDb, $multiCallerProcessor] = create_alert_environment($multiCallerClock, new FakeEmailSender(), $multiCallerSender);
$multiCallerRule = insert_rule($multiCallerDb, [
	'name' => 'Multi Caller Rule',
	'email_enabled' => 0,
	'alert_call_enabled' => 1,
	'alert_call_destinations' => '992',
	'alert_call_recording_id' => 55,
	'observation_window_minutes' => 5,
	'repeat_mode_override' => 'never',
]);
insert_incident($multiCallerDb, [
	'rule_id' => $multiCallerRule,
	'subject_key' => '+447700900011',
	'subject_label' => '+447700900011',
	'caller_display' => '07700900011',
	'matched_call_count' => 2,
	'first_matched_at' => $multiCallerClock->now,
	'suppression_expires_at' => '2026-07-13 17:11:00',
]);
insert_seen_call($multiCallerDb, [
	'call_identity' => 'multi-caller-1',
	'fingerprint' => 'multi-caller-1',
	'caller_raw' => '07700900011',
	'caller_normalized' => '+447700900011',
	'did_value' => '02070000011',
	'call_completed_at' => '2026-07-13 16:11:00',
]);
insert_seen_call($multiCallerDb, [
	'call_identity' => 'multi-caller-2',
	'fingerprint' => 'multi-caller-2',
	'caller_raw' => '07700900012',
	'caller_normalized' => '+447700900011',
	'did_value' => '02070000011',
	'call_completed_at' => '2026-07-13 16:10:30',
]);
$multiCallerProcessor->run(settings());
assert_same('none', (string)$multiCallerSender->calls[0]['context']['summary_caller_kind'], 'multiple distinct Caller IDs must omit the caller phrase');
assert_same('', (string)$multiCallerSender->calls[0]['context']['summary_caller_value'], 'multiple distinct Caller IDs must not send caller digits');

$multiUnknownClock = new TestClock('2026-07-13 16:12:00');
$multiUnknownSender = new FakeCallSender();
[$multiUnknownDb, $multiUnknownProcessor] = create_alert_environment($multiUnknownClock, new FakeEmailSender(), $multiUnknownSender);
$multiUnknownRule = insert_rule($multiUnknownDb, [
	'name' => 'Unknown Signature Rule',
	'email_enabled' => 0,
	'alert_call_enabled' => 1,
	'alert_call_destinations' => '993',
	'alert_call_recording_id' => 55,
	'observation_window_minutes' => 5,
	'repeat_mode_override' => 'never',
]);
insert_incident($multiUnknownDb, [
	'rule_id' => $multiUnknownRule,
	'subject_key' => 'withheld',
	'subject_label' => 'withheld',
	'caller_display' => 'Blocked',
	'withheld_caller' => 1,
	'matched_call_count' => 2,
	'first_matched_at' => $multiUnknownClock->now,
	'suppression_expires_at' => '2026-07-13 17:12:00',
]);
insert_seen_call($multiUnknownDb, [
	'call_identity' => 'unknown-same-1',
	'fingerprint' => 'unknown-same-1',
	'caller_raw' => 'Blocked',
	'caller_normalized' => 'withheld',
	'call_completed_at' => '2026-07-13 16:12:00',
]);
insert_seen_call($multiUnknownDb, [
	'call_identity' => 'unknown-same-2',
	'fingerprint' => 'unknown-same-2',
	'caller_raw' => 'Blocked',
	'caller_normalized' => 'withheld',
	'call_completed_at' => '2026-07-13 16:11:30',
]);
$multiUnknownProcessor->run(settings());
assert_same('unknown', (string)$multiUnknownSender->calls[0]['context']['summary_caller_kind'], 'one consistent unknown caller identity must use only from-unknown-caller');
assert_same('', (string)$multiUnknownSender->calls[0]['context']['summary_caller_value'], 'one consistent unknown caller identity must not send caller digits');

$multiDidClock = new TestClock('2026-07-13 16:13:00');
$multiDidSender = new FakeCallSender();
[$multiDidDb, $multiDidProcessor] = create_alert_environment($multiDidClock, new FakeEmailSender(), $multiDidSender);
$multiDidRule = insert_rule($multiDidDb, [
	'name' => 'Multi DID Rule',
	'email_enabled' => 0,
	'alert_call_enabled' => 1,
	'alert_call_destinations' => '994',
	'alert_call_recording_id' => 55,
	'observation_window_minutes' => 5,
	'repeat_mode_override' => 'never',
]);
insert_incident($multiDidDb, [
	'rule_id' => $multiDidRule,
	'subject_key' => '+447700900013',
	'subject_label' => '+447700900013',
	'caller_display' => '07700900013',
	'matched_call_count' => 2,
	'first_matched_at' => $multiDidClock->now,
	'suppression_expires_at' => '2026-07-13 17:13:00',
]);
insert_seen_call($multiDidDb, [
	'call_identity' => 'multi-did-1',
	'fingerprint' => 'multi-did-1',
	'caller_raw' => '07700900013',
	'caller_normalized' => '+447700900013',
	'did_value' => '02070000013',
	'call_completed_at' => '2026-07-13 16:13:00',
]);
insert_seen_call($multiDidDb, [
	'call_identity' => 'multi-did-2',
	'fingerprint' => 'multi-did-2',
	'caller_raw' => '07700900013',
	'caller_normalized' => '+447700900013',
	'did_value' => '02070000014',
	'call_completed_at' => '2026-07-13 16:12:30',
]);
$multiDidProcessor->run(settings());
assert_same('', (string)$multiDidSender->calls[0]['context']['summary_did_value'], 'multiple distinct DIDs must omit the DID phrase');

$callHistoryId = (int)$callDb->query("SELECT id FROM repeatcaller_incident_alert_history WHERE incident_id = {$callIncident} AND action_type = 'alert_call' AND recipient = '100' LIMIT 1")->fetchColumn();
$accepted = (new RepeatCallerRepository($callDb))->recordAlertCallDtmfResponse($callHistoryId, $callIncident, 'accepted', '100', '1', '2026-07-13 10:01:00');
$acceptedIncident = $callDb->query("SELECT state, accepted_by, accepted_at, accept_source FROM repeatcaller_incidents WHERE id = {$callIncident}")->fetch(PDO::FETCH_ASSOC);
$acceptedHistory = $callDb->query("SELECT delivery_status, successful_at, failure_detail FROM repeatcaller_incident_alert_history WHERE id = {$callHistoryId}")->fetch(PDO::FETCH_ASSOC);
assert_true(!empty($accepted['accepted']), 'DTMF 1 should invoke the existing accept path');
assert_same('accepted', (string)$acceptedIncident['state'], 'DTMF 1 should accept the incident');
assert_same('alert-call:100', (string)$acceptedIncident['accepted_by'], 'DTMF 1 acceptance should be attributed to the alert-call recipient');
assert_same('alert_call', (string)$acceptedIncident['accept_source'], 'DTMF 1 acceptance should use the alert_call acceptance source');
assert_same('accepted', (string)$acceptedHistory['delivery_status'], 'DTMF 1 should record the alert call as accepted');
assert_same('incident accepted', (string)$acceptedHistory['failure_detail'], 'DTMF 1 should record an accepted-friendly failure detail');
assert_same('2026-07-13 10:01:00', (string)$acceptedHistory['successful_at'], 'DTMF 1 should record the accepted response time');

$callClock->now = '2026-07-13 10:06:00';
$acceptedReminder = $callProcessor->run(settings());
assert_same(0, $acceptedReminder['reminder_events'], 'accepted alert calls should stop further escalation');

$acceptedRepeatClock = new TestClock('2026-07-13 10:00:00');
$acceptedRepeatEmailSender = new FakeEmailSender();
$acceptedRepeatCallSender = new FakeCallSender();
[$acceptedRepeatDb, $acceptedRepeatProcessor] = create_alert_environment($acceptedRepeatClock, $acceptedRepeatEmailSender, $acceptedRepeatCallSender);
$acceptedRepeatRule = insert_rule($acceptedRepeatDb, [
	'name' => 'Accepted Repeat Rule',
	'email_enabled' => 1,
	'alert_call_enabled' => 1,
	'alert_call_destinations' => '400',
	'alert_call_recording_id' => 55,
	'repeat_mode_override' => '5m',
]);
$acceptedRepeatIncident = insert_incident($acceptedRepeatDb, [
	'rule_id' => $acceptedRepeatRule,
	'subject_key' => 'accepted-repeat-subject',
	'first_matched_at' => '2026-07-13 10:00:00',
	'suppression_expires_at' => '2026-07-13 11:00:00',
]);
$acceptedRepeatProcessor->run(settings());
$acceptedRepeatHistoryId = (int)$acceptedRepeatDb->query("SELECT id FROM repeatcaller_incident_alert_history WHERE incident_id = {$acceptedRepeatIncident} AND action_type = 'alert_call' AND recipient = '400' LIMIT 1")->fetchColumn();
assert_true($acceptedRepeatHistoryId > 0, 'accepted repeat scenario should create an initial alert call attempt before DTMF acceptance');
(new RepeatCallerRepository($acceptedRepeatDb))->recordAlertCallDtmfResponse($acceptedRepeatHistoryId, $acceptedRepeatIncident, 'accepted', '400', '1', '2026-07-13 10:01:00');
$acceptedRepeatDb->prepare('UPDATE repeatcaller_incidents SET last_matched_at = ?, matched_call_count = ? WHERE id = ?')->execute(['2026-07-13 10:03:00', 3, $acceptedRepeatIncident]);
$acceptedRepeatClock->now = '2026-07-13 10:05:00';
$acceptedRepeatProcessor->run(settings());
assert_same(0, count_history($acceptedRepeatDb, "incident_id = {$acceptedRepeatIncident} AND event_type = 'reminder' AND action_type = 'gui'"), 'DTMF-accepted incidents should not reserve GUI reminders while suppression remains active');
assert_same(0, count_history($acceptedRepeatDb, "incident_id = {$acceptedRepeatIncident} AND event_type = 'reminder' AND action_type = 'email'"), 'DTMF-accepted incidents should not reserve email reminders while suppression remains active');
assert_same(0, count_history($acceptedRepeatDb, "incident_id = {$acceptedRepeatIncident} AND event_type = 'reminder' AND action_type = 'alert_call'"), 'DTMF-accepted incidents should not reserve alert_call reminders while suppression remains active');
$acceptedRepeatStateSuppressed = $acceptedRepeatDb->query("SELECT last_alert_at, reminders_sent FROM repeatcaller_incident_alert_state WHERE incident_id = {$acceptedRepeatIncident}")->fetch(PDO::FETCH_ASSOC);
assert_same('2026-07-13 10:00:00', (string)$acceptedRepeatStateSuppressed['last_alert_at'], 'DTMF-accepted incidents should keep the original last-alert checkpoint while suppression remains active');
assert_same(0, (int)$acceptedRepeatStateSuppressed['reminders_sent'], 'DTMF-accepted incidents should not advance reminder count while suppression remains active');
$acceptedRepeatDb->prepare('UPDATE repeatcaller_incidents SET last_matched_at = ?, matched_call_count = ?, suppression_expires_at = ? WHERE id = ?')->execute(['2026-07-13 11:05:00', 4, '2026-07-13 11:00:00', $acceptedRepeatIncident]);
$acceptedRepeatClock->now = '2026-07-13 11:05:00';
$acceptedRepeatProcessor->run(settings());
assert_same(1, count_history($acceptedRepeatDb, "incident_id = {$acceptedRepeatIncident} AND event_type = 'reminder' AND stage_n = 1 AND action_type = 'gui'"), 'DTMF-accepted incidents should reserve one fresh GUI reminder after suppression expiry and new activity');
assert_same(1, count_history($acceptedRepeatDb, "incident_id = {$acceptedRepeatIncident} AND event_type = 'reminder' AND stage_n = 1 AND action_type = 'email'"), 'DTMF-accepted incidents should reserve one fresh email reminder after suppression expiry and new activity');
assert_same(0, count_history($acceptedRepeatDb, "incident_id = {$acceptedRepeatIncident} AND event_type = 'reminder' AND stage_n = 1 AND action_type = 'alert_call'"), 'DTMF-accepted incidents should not restart Alert Call activity after acceptance, even after suppression expiry and new activity');
assert_same(0, count_history($acceptedRepeatDb, "incident_id = {$acceptedRepeatIncident} AND event_type = 'reminder' AND stage_n = 2"), 'DTMF-accepted incidents should restart reminder stages at the first fresh post-suppression stage');
assert_same(1, count_history($acceptedRepeatDb, "incident_id = {$acceptedRepeatIncident} AND action_type = 'email' AND event_type = 'reminder' AND delivery_status = 'sent'"), 'DTMF-accepted incidents should deliver email once the fresh post-suppression reminder is reserved');
$acceptedRepeatStateExpired = $acceptedRepeatDb->query("SELECT last_alert_at, reminders_sent FROM repeatcaller_incident_alert_state WHERE incident_id = {$acceptedRepeatIncident}")->fetch(PDO::FETCH_ASSOC);
assert_same('2026-07-13 11:05:00', (string)$acceptedRepeatStateExpired['last_alert_at'], 'DTMF-accepted incidents should advance the reminder checkpoint once suppression has expired and a fresh reminder is reserved');
assert_same(1, (int)$acceptedRepeatStateExpired['reminders_sent'], 'DTMF-accepted incidents should advance the reminder count once suppression has expired and a fresh reminder is reserved');

$declineClock = new TestClock('2026-07-13 11:00:00');
$declineSender = new FakeCallSender();
[$declineDb, $declineProcessor] = create_alert_environment($declineClock, new FakeEmailSender(), $declineSender);
$declineRule = insert_rule($declineDb, ['alert_call_enabled' => 1, 'alert_call_destinations' => '200', 'alert_call_recording_id' => 55, 'repeat_mode_override' => '5m']);
$declineIncident = insert_incident($declineDb, ['rule_id' => $declineRule, 'subject_key' => 'decline-subject', 'first_matched_at' => '2026-07-13 11:00:00', 'suppression_expires_at' => '2026-07-13 12:00:00']);
$declineProcessor->run(settings());
$declineHistoryId = (int)$declineDb->query("SELECT id FROM repeatcaller_incident_alert_history WHERE incident_id = {$declineIncident} AND action_type = 'alert_call' LIMIT 1")->fetchColumn();
(new RepeatCallerRepository($declineDb))->recordAlertCallDtmfResponse($declineHistoryId, $declineIncident, 'declined', '200', '2', '2026-07-13 11:01:00');
$declinedIncident = $declineDb->query("SELECT state, accepted_by FROM repeatcaller_incidents WHERE id = {$declineIncident}")->fetch(PDO::FETCH_ASSOC);
$declinedHistory = $declineDb->query("SELECT delivery_status, successful_at, failure_detail FROM repeatcaller_incident_alert_history WHERE id = {$declineHistoryId}")->fetch(PDO::FETCH_ASSOC);
assert_same('active', (string)$declinedIncident['state'], 'DTMF 2 should leave the incident active');
assert_true($declinedIncident['accepted_by'] === null, 'DTMF 2 should not accept the incident');
assert_same('declined', (string)$declinedHistory['delivery_status'], 'DTMF 2 should record the alert call as declined');
assert_same('Recipient declined the Alert Call', (string)$declinedHistory['failure_detail'], 'DTMF 2 should record a decline-friendly failure detail');
$declineSameStageRows = count_history($declineDb, "incident_id = {$declineIncident} AND action_type = 'alert_call' AND event_type = 'initial' AND stage_n = 0");
$declineCallAttemptsBefore = count($declineSender->calls);
$declineClock->now = '2026-07-13 11:05:00';
$declineProcessor->run(settings());
assert_same(1, count_history($declineDb, "incident_id = {$declineIncident} AND action_type = 'gui' AND event_type = 'reminder'"), 'declined incidents should continue normal GUI reminder progression');
assert_same($declineSameStageRows, count_history($declineDb, "incident_id = {$declineIncident} AND action_type = 'alert_call' AND event_type = 'initial' AND stage_n = 0"), 'declined calls must not retry the same alert_call stage');
assert_same($declineCallAttemptsBefore, count($declineSender->calls), 'declined recipient must be permanently excluded for the incident');

$timeoutClock = new TestClock('2026-07-13 12:00:00');
$timeoutSender = new FakeCallSender();
[$timeoutDb, $timeoutProcessor] = create_alert_environment($timeoutClock, new FakeEmailSender(), $timeoutSender);
$timeoutRule = insert_rule($timeoutDb, ['alert_call_enabled' => 1, 'alert_call_destinations' => '300', 'alert_call_recording_id' => 55, 'repeat_mode_override' => '5m']);
$timeoutIncident = insert_incident($timeoutDb, ['rule_id' => $timeoutRule, 'subject_key' => 'timeout-subject', 'first_matched_at' => '2026-07-13 12:00:00', 'suppression_expires_at' => '2026-07-13 13:00:00']);
$timeoutProcessor->run(settings());
$timeoutHistoryId = (int)$timeoutDb->query("SELECT id FROM repeatcaller_incident_alert_history WHERE incident_id = {$timeoutIncident} AND action_type = 'alert_call' LIMIT 1")->fetchColumn();
(new RepeatCallerRepository($timeoutDb))->recordAlertCallDtmfResponse($timeoutHistoryId, $timeoutIncident, 'timeout', '300', '', '2026-07-13 12:01:00');
$timeoutIncidentRow = $timeoutDb->query("SELECT state, accepted_by FROM repeatcaller_incidents WHERE id = {$timeoutIncident}")->fetch(PDO::FETCH_ASSOC);
$timeoutHistory = $timeoutDb->query("SELECT delivery_status, successful_at, failure_detail FROM repeatcaller_incident_alert_history WHERE id = {$timeoutHistoryId}")->fetch(PDO::FETCH_ASSOC);
assert_same('active', (string)$timeoutIncidentRow['state'], 'timeout should leave the incident active');
assert_true($timeoutIncidentRow['accepted_by'] === null, 'timeout should not accept the incident');
assert_same('answered_no_response', (string)$timeoutHistory['delivery_status'], 'timeout should record answered_no_response');
assert_true($timeoutHistory['successful_at'] === null, 'timeout should not record a successful response time');
$timeoutSameStageRows = count_history($timeoutDb, "incident_id = {$timeoutIncident} AND action_type = 'alert_call' AND event_type = 'initial' AND stage_n = 0");
$timeoutCallAttemptsBefore = count($timeoutSender->calls);
$timeoutClock->now = '2026-07-13 12:05:00';
$timeoutProcessor->run(settings());
assert_same(1, count_history($timeoutDb, "incident_id = {$timeoutIncident} AND action_type = 'gui' AND event_type = 'reminder'"), 'unanswered calls should remain eligible for normal escalation');
assert_same($timeoutSameStageRows, count_history($timeoutDb, "incident_id = {$timeoutIncident} AND action_type = 'alert_call' AND event_type = 'initial' AND stage_n = 0"), 'timed-out calls must not retry the same alert_call stage');
assert_true(count($timeoutSender->calls) > $timeoutCallAttemptsBefore, 'timed-out calls should generate a new call only when the next normal reminder stage becomes due');

$invalidClock = new TestClock('2026-07-13 13:00:00');
$invalidSender = new FakeCallSender();
[$invalidDb, $invalidProcessor] = create_alert_environment($invalidClock, new FakeEmailSender(), $invalidSender);
$invalidRule = insert_rule($invalidDb, ['alert_call_enabled' => 1, 'alert_call_destinations' => '400', 'alert_call_recording_id' => 55, 'repeat_mode_override' => 'never']);
$invalidIncident = insert_incident($invalidDb, ['rule_id' => $invalidRule, 'subject_key' => 'invalid-subject', 'first_matched_at' => '2026-07-13 13:00:00', 'suppression_expires_at' => '2026-07-13 14:00:00']);
$invalidProcessor->run(settings());
$invalidHistoryId = (int)$invalidDb->query("SELECT id FROM repeatcaller_incident_alert_history WHERE incident_id = {$invalidIncident} AND action_type = 'alert_call' LIMIT 1")->fetchColumn();
(new RepeatCallerRepository($invalidDb))->recordAlertCallDtmfResponse($invalidHistoryId, $invalidIncident, 'timeout', '400', '9', '2026-07-13 13:01:00');
$invalidIncidentRow = $invalidDb->query("SELECT state, accepted_by FROM repeatcaller_incidents WHERE id = {$invalidIncident}")->fetch(PDO::FETCH_ASSOC);
$invalidHistory = $invalidDb->query("SELECT delivery_status, failure_detail FROM repeatcaller_incident_alert_history WHERE id = {$invalidHistoryId}")->fetch(PDO::FETCH_ASSOC);
assert_same('active', (string)$invalidIncidentRow['state'], 'invalid DTMF should leave the incident active');
assert_true($invalidIncidentRow['accepted_by'] === null, 'invalid DTMF should not accept the incident');
assert_same('answered_no_response', (string)$invalidHistory['delivery_status'], 'invalid DTMF after all attempts should be recorded as answered_no_response');
assert_true(strpos((string)$invalidHistory['failure_detail'], 'last digit: 9') !== false, 'invalid DTMF should record the last invalid digit after retries are exhausted');

$hangupClock = new TestClock('2026-07-13 13:30:00');
[$hangupDb, $hangupProcessor] = create_alert_environment($hangupClock, new FakeEmailSender(), new FakeCallSender());
$hangupRule = insert_rule($hangupDb, ['alert_call_enabled' => 1, 'alert_call_destinations' => '450', 'alert_call_recording_id' => 55, 'repeat_mode_override' => 'never']);
$hangupIncident = insert_incident($hangupDb, ['rule_id' => $hangupRule, 'subject_key' => 'hangup-subject', 'first_matched_at' => '2026-07-13 13:30:00', 'suppression_expires_at' => '2026-07-13 14:30:00']);
$hangupProcessor->run(settings());
$hangupHistoryId = (int)$hangupDb->query("SELECT id FROM repeatcaller_incident_alert_history WHERE incident_id = {$hangupIncident} AND action_type = 'alert_call' LIMIT 1")->fetchColumn();
(new RepeatCallerRepository($hangupDb))->recordAlertCallDtmfResponse($hangupHistoryId, $hangupIncident, 'hangup', '450', '', '2026-07-13 13:31:00');
$hangupIncidentRow = $hangupDb->query("SELECT state, accepted_by FROM repeatcaller_incidents WHERE id = {$hangupIncident}")->fetch(PDO::FETCH_ASSOC);
$hangupHistory = $hangupDb->query("SELECT delivery_status, failure_detail FROM repeatcaller_incident_alert_history WHERE id = {$hangupHistoryId}")->fetch(PDO::FETCH_ASSOC);
assert_same('active', (string)$hangupIncidentRow['state'], 'hangup during the prompt loop should leave the incident active');
assert_true($hangupIncidentRow['accepted_by'] === null, 'hangup during the prompt loop should not accept the incident');
assert_same('answered_no_response', (string)$hangupHistory['delivery_status'], 'hangup during the prompt loop should record answered_no_response');
assert_true(strpos((string)$hangupHistory['failure_detail'], 'answered call ended without a valid DTMF response') !== false, 'hangup result should record the no-response reason');

// Ordered progression is stage-based: a non-accepted result reserves the next cumulative stage after a 60-second pause, and only destinations with Keep Trying enabled carry into later stages.
$orderedClock = new TestClock('2026-07-13 13:40:00');
$orderedSender = new FakeCallSender();
[$orderedDb, $orderedProcessor] = create_alert_environment($orderedClock, new FakeEmailSender(), $orderedSender);
$orderedRule = insert_rule($orderedDb, [
	'alert_call_enabled' => 1,
	'alert_call_destinations' => '700,701',
	'alert_call_strategy' => 'ordered',
	'alert_call_keep_trying' => 1,
	'alert_call_recording_id' => 55,
	'repeat_mode_override' => 'never',
]);
$orderedIncident = insert_incident($orderedDb, ['rule_id' => $orderedRule, 'subject_key' => 'ordered-subject', 'first_matched_at' => '2026-07-13 13:40:00', 'suppression_expires_at' => '2026-07-13 14:40:00']);
$orderedProcessor->run(settings());
assert_same(1, count($orderedSender->calls), 'ordered strategy should call only the first eligible destination for a stage');
assert_same('700', (string)$orderedSender->calls[0]['destination'], 'ordered strategy should start with first saved destination');
$orderedFirstHistoryId = (int)$orderedDb->query("SELECT id FROM repeatcaller_incident_alert_history WHERE incident_id = {$orderedIncident} AND recipient = '700' LIMIT 1")->fetchColumn();
$orderedRepo = new RepeatCallerRepository($orderedDb);
$orderedRepo->recordAlertCallDialDisposition($orderedFirstHistoryId, $orderedIncident, '700', 'BUSY', '17', '2026-07-13 13:40:20');
$orderedFirstHistory = $orderedDb->query("SELECT delivery_status, failure_detail FROM repeatcaller_incident_alert_history WHERE id = {$orderedFirstHistoryId}")->fetch(PDO::FETCH_ASSOC);
assert_same('busy', (string)$orderedFirstHistory['delivery_status'], 'dialstatus BUSY must map to busy outcome');
assert_true(strpos((string)$orderedFirstHistory['failure_detail'], 'DIALSTATUS=BUSY; HANGUPCAUSE=17') !== false, 'dialstatus callback must persist raw DIALSTATUS/HANGUPCAUSE details');
$queuedOrderedRows = $orderedDb->query("SELECT recipient, delivery_status, next_retry_at FROM repeatcaller_incident_alert_history WHERE incident_id = {$orderedIncident} AND action_type = 'alert_call' ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);
assert_same(3, count($queuedOrderedRows), 'ordered callback should reserve the full next cumulative stage as deferred pending rows');
assert_same('700', (string)$queuedOrderedRows[1]['recipient'], 'ordered callback should retain the earlier destination in the next stage because keep-trying is enabled');
assert_same('701', (string)$queuedOrderedRows[2]['recipient'], 'ordered callback should introduce the next destination in the next stage');
assert_same('pending', (string)$queuedOrderedRows[1]['delivery_status'], 'ordered callback should reserve the retained destination as pending');
assert_same('pending', (string)$queuedOrderedRows[2]['delivery_status'], 'ordered callback should reserve the introduced destination as pending');
assert_true((string)$queuedOrderedRows[1]['next_retry_at'] !== '', 'ordered callback should defer the retained destination with a retry timestamp');
assert_true((string)$queuedOrderedRows[2]['next_retry_at'] !== '', 'ordered callback should defer the introduced destination with a retry timestamp');
assert_same(1, count($orderedSender->calls), 'ordered callback should not originate deferred stage-2 rows before their retry time');
$orderedClock->now = '2026-07-13 13:41:20';
$orderedProcessor->run(settings());
assert_same(3, count($orderedSender->calls), 'ordered progression should originate the deferred cumulative stage once the scheduler permits it');
assert_same('700', (string)$orderedSender->calls[1]['destination'], 'ordered progression should retry the retained destination once the deferred stage becomes deliverable');
assert_same('701', (string)$orderedSender->calls[2]['destination'], 'ordered progression should originate the newly introduced destination once the deferred stage becomes deliverable');

$orderedNoAnswerClock = new TestClock('2026-07-13 13:41:00');
$orderedNoAnswerSender = new FakeCallSender();
[$orderedNoAnswerDb, $orderedNoAnswerProcessor] = create_alert_environment($orderedNoAnswerClock, new FakeEmailSender(), $orderedNoAnswerSender);
$orderedNoAnswerRule = insert_rule($orderedNoAnswerDb, [
	'alert_call_enabled' => 1,
	'alert_call_destinations' => '800,801',
	'alert_call_strategy' => 'ordered',
	'alert_call_keep_trying' => 1,
	'alert_call_recording_id' => 55,
	'repeat_mode_override' => 'never',
]);
$orderedNoAnswerIncident = insert_incident($orderedNoAnswerDb, ['rule_id' => $orderedNoAnswerRule, 'subject_key' => 'ordered-no-answer', 'first_matched_at' => '2026-07-13 13:41:00', 'suppression_expires_at' => '2026-07-13 14:41:00']);
$orderedNoAnswerProcessor->run(settings());
$orderedNoAnswerHistoryId = (int)$orderedNoAnswerDb->query("SELECT id FROM repeatcaller_incident_alert_history WHERE incident_id = {$orderedNoAnswerIncident} AND recipient = '800' LIMIT 1")->fetchColumn();
$orderedNoAnswerRepo = new RepeatCallerRepository($orderedNoAnswerDb);
$orderedNoAnswerResult = $orderedNoAnswerRepo->recordAlertCallDialDisposition($orderedNoAnswerHistoryId, $orderedNoAnswerIncident, '800', 'NOANSWER', '19', '2026-07-13 13:41:10');
assert_true(!empty($orderedNoAnswerResult['next_history_id']), 'non-accepted ordered callback should reserve the next destination history row');
$orderedNoAnswerRows = $orderedNoAnswerDb->query("SELECT recipient, delivery_status, next_retry_at FROM repeatcaller_incident_alert_history WHERE incident_id = {$orderedNoAnswerIncident} AND action_type = 'alert_call' ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);
assert_same(3, count($orderedNoAnswerRows), 'non-accepted ordered callback should create a deferred cumulative stage with the retained destination and the new destination');
assert_same('800', (string)$orderedNoAnswerRows[0]['recipient'], 'first ordered destination should remain the original recipient');
assert_same('no_answer', (string)$orderedNoAnswerRows[0]['delivery_status'], 'NOANSWER dialstatus should mark the first ordered attempt as no_answer');
assert_same('800', (string)$orderedNoAnswerRows[1]['recipient'], 'retained ordered destination should be reserved in the next cumulative stage');
assert_same('801', (string)$orderedNoAnswerRows[2]['recipient'], 'next ordered destination should be reserved after the first NOANSWER callback');
assert_same('pending', (string)$orderedNoAnswerRows[1]['delivery_status'], 'retained next-stage ordered destination should be reserved as pending');
assert_same('pending', (string)$orderedNoAnswerRows[2]['delivery_status'], 'new next-stage ordered destination should be reserved as pending');
assert_true((string)$orderedNoAnswerRows[1]['next_retry_at'] !== '', 'retained next-stage destination should be deferred with a retry timestamp');
assert_true((string)$orderedNoAnswerRows[2]['next_retry_at'] !== '', 'new next-stage destination should be deferred with a retry timestamp');
assert_true(strtotime((string)$orderedNoAnswerRows[1]['next_retry_at']) > strtotime('2026-07-13 13:41:10'), 'next-stage retained destination should not be deliverable immediately');
assert_true(strtotime((string)$orderedNoAnswerRows[2]['next_retry_at']) > strtotime('2026-07-13 13:41:10'), 'next-stage new destination should not be deliverable immediately');

// Single-destination ordered NOANSWER: covers the exact live failure path.
// Row 8 equivalent: history row starts sent, NOANSWER updates it to no_answer, a deferred
// next-stage row is reserved, and the processor delivers it after 60 seconds regardless of
// repeat_mode=never. CANCEL (the fallback when DIALSTATUS is empty after h-extension hangup)
// also produces a terminal outcome and reserves the next stage.
$orderedSingleNoAnswerClock = new TestClock('2026-07-13 14:00:00');
$orderedSingleNoAnswerSender = new FakeCallSender();
[$orderedSingleDb, $orderedSingleProcessor] = create_alert_environment($orderedSingleNoAnswerClock, new FakeEmailSender(), $orderedSingleNoAnswerSender);
$orderedSingleRule = insert_rule($orderedSingleDb, [
	'alert_call_enabled' => 1,
	'alert_call_destinations' => '2001|1',
	'alert_call_strategy' => 'ordered',
	'alert_call_keep_trying' => 1,
	'alert_call_recording_id' => 55,
	'repeat_mode_override' => 'never',
]);
$orderedSingleIncident = insert_incident($orderedSingleDb, ['rule_id' => $orderedSingleRule, 'subject_key' => 'ordered-single-noanswer', 'first_matched_at' => '2026-07-13 14:00:00', 'suppression_expires_at' => '2026-07-13 15:00:00']);
$orderedSingleProcessor->run(settings());
assert_same(1, count($orderedSingleNoAnswerSender->calls), 'ordered single-destination initial stage should originate exactly one call');
assert_same('2001', (string)$orderedSingleNoAnswerSender->calls[0]['destination'], 'ordered single-destination should call the configured destination');
$orderedSingleHistoryId = (int)$orderedSingleDb->query("SELECT id FROM repeatcaller_incident_alert_history WHERE incident_id = {$orderedSingleIncident} AND recipient = '2001' AND stage_n = 0 LIMIT 1")->fetchColumn();
assert_true($orderedSingleHistoryId > 0, 'ordered single-destination should have a stage-0 history row for recipient 2001');
$orderedSingleStatusBefore = (string)$orderedSingleDb->query("SELECT delivery_status FROM repeatcaller_incident_alert_history WHERE id = {$orderedSingleHistoryId}")->fetchColumn();
assert_same('sent', $orderedSingleStatusBefore, 'ordered single-destination initial row should be sent before dialstatus callback');
// Simulate the AGI dialstatus callback (from the h extension in the fixed dialplan) after NOANSWER.
$orderedSingleRepo = new RepeatCallerRepository($orderedSingleDb);
$orderedSingleResult = $orderedSingleRepo->recordAlertCallDialDisposition($orderedSingleHistoryId, $orderedSingleIncident, '2001', 'NOANSWER', '19', '2026-07-13 14:00:30');
assert_true(!empty($orderedSingleResult['status']), 'NOANSWER dialstatus callback must succeed for single-destination ordered row');
$orderedSingleStatusAfter = (string)$orderedSingleDb->query("SELECT delivery_status FROM repeatcaller_incident_alert_history WHERE id = {$orderedSingleHistoryId}")->fetchColumn();
assert_same('no_answer', $orderedSingleStatusAfter, 'NOANSWER dialstatus must update the single-destination row from sent to no_answer');
$orderedSingleAllRows = $orderedSingleDb->query("SELECT recipient, stage_n, delivery_status, next_retry_at FROM repeatcaller_incident_alert_history WHERE incident_id = {$orderedSingleIncident} AND action_type = 'alert_call' ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);
assert_same(2, count($orderedSingleAllRows), 'NOANSWER on single-destination ordered should reserve one next-stage row for the same destination');
assert_same('2001', (string)$orderedSingleAllRows[1]['recipient'], 'retained ordered destination must carry into the next stage after NOANSWER');
assert_same('1', (string)$orderedSingleAllRows[1]['stage_n'], 'next-stage row must be assigned stage number 1');
assert_same('pending', (string)$orderedSingleAllRows[1]['delivery_status'], 'next-stage ordered destination must be reserved as pending');
assert_true((string)$orderedSingleAllRows[1]['next_retry_at'] !== '', 'next-stage ordered destination must carry a deferred retry timestamp');
assert_true(strtotime((string)$orderedSingleAllRows[1]['next_retry_at']) > strtotime('2026-07-13 14:00:30'), 'next-stage ordered destination must not be deliverable immediately after NOANSWER');
// Verify the 60-second pause: processor before pause must not deliver the next stage.
$orderedSingleProcessor->run(settings());
assert_same(1, count($orderedSingleNoAnswerSender->calls), 'ordered single-destination stage-2 row must not be sent before the 60-second deferred retry time');
// Advance past the 60-second pause and confirm delivery even with repeat_mode=never.
$orderedSingleNoAnswerClock->now = '2026-07-13 14:01:31';
$orderedSingleProcessor->run(settings());
assert_same(2, count($orderedSingleNoAnswerSender->calls), 'ordered single-destination stage-2 row must be sent after the 60-second deferred retry time regardless of repeat_mode=never');
assert_same('2001', (string)$orderedSingleNoAnswerSender->calls[1]['destination'], 'ordered stage-2 delivery must target the retained destination');

// CANCEL fallback: when the h extension fires with no DIALSTATUS (originate interrupted),
// an empty DIALSTATUS maps to CANCEL which records as failed and still advances Ordered.
$orderedCancelClock = new TestClock('2026-07-13 14:02:00');
$orderedCancelSender = new FakeCallSender();
[$orderedCancelDb, $orderedCancelProcessor] = create_alert_environment($orderedCancelClock, new FakeEmailSender(), $orderedCancelSender);
$orderedCancelRule = insert_rule($orderedCancelDb, [
	'alert_call_enabled' => 1,
	'alert_call_destinations' => '2001|1',
	'alert_call_strategy' => 'ordered',
	'alert_call_keep_trying' => 1,
	'alert_call_recording_id' => 55,
	'repeat_mode_override' => 'never',
]);
$orderedCancelIncident = insert_incident($orderedCancelDb, ['rule_id' => $orderedCancelRule, 'subject_key' => 'ordered-cancel', 'first_matched_at' => '2026-07-13 14:02:00', 'suppression_expires_at' => '2026-07-13 15:02:00']);
$orderedCancelProcessor->run(settings());
$orderedCancelHistoryId = (int)$orderedCancelDb->query("SELECT id FROM repeatcaller_incident_alert_history WHERE incident_id = {$orderedCancelIncident} AND recipient = '2001' AND stage_n = 0 LIMIT 1")->fetchColumn();
$orderedCancelRepo = new RepeatCallerRepository($orderedCancelDb);
// CANCEL = the DIALSTATUS substituted by the h-extension fallback when DIALSTATUS is empty.
$orderedCancelResult = $orderedCancelRepo->recordAlertCallDialDisposition($orderedCancelHistoryId, $orderedCancelIncident, '2001', 'CANCEL', '', '2026-07-13 14:02:30');
assert_true(!empty($orderedCancelResult['status']), 'CANCEL dialstatus callback from h-extension fallback must succeed');
$orderedCancelStatus = (string)$orderedCancelDb->query("SELECT delivery_status FROM repeatcaller_incident_alert_history WHERE id = {$orderedCancelHistoryId}")->fetchColumn();
assert_same('failed', $orderedCancelStatus, 'CANCEL dialstatus must record the attempt as failed');
assert_true(!empty($orderedCancelResult['next_history_id']), 'CANCEL dialstatus must still advance the Ordered stage so progression is not permanently stuck');

$orderedNoKeepTryingClock = new TestClock('2026-07-13 13:42:20');
$orderedNoKeepTryingSender = new FakeCallSender();
[$orderedNoKeepTryingDb, $orderedNoKeepTryingProcessor] = create_alert_environment($orderedNoKeepTryingClock, new FakeEmailSender(), $orderedNoKeepTryingSender);
$orderedNoKeepTryingRule = insert_rule($orderedNoKeepTryingDb, [
	'alert_call_enabled' => 1,
	'alert_call_destinations' => '910|0,911|1',
	'alert_call_strategy' => 'ordered',
	'alert_call_keep_trying' => 1,
	'alert_call_recording_id' => 55,
	'repeat_mode_override' => 'never',
]);
$orderedNoKeepTryingIncident = insert_incident($orderedNoKeepTryingDb, ['rule_id' => $orderedNoKeepTryingRule, 'subject_key' => 'ordered-no-keep-trying', 'first_matched_at' => '2026-07-13 13:42:20', 'suppression_expires_at' => '2026-07-13 14:42:20']);
$orderedNoKeepTryingProcessor->run(settings());
$orderedNoKeepTryingHistoryId = (int)$orderedNoKeepTryingDb->query("SELECT id FROM repeatcaller_incident_alert_history WHERE incident_id = {$orderedNoKeepTryingIncident} AND recipient = '910' LIMIT 1")->fetchColumn();
$orderedNoKeepTryingEventType = (string)$orderedNoKeepTryingDb->query("SELECT event_type FROM repeatcaller_incident_alert_history WHERE incident_id = {$orderedNoKeepTryingIncident} AND action_type = 'alert_call' ORDER BY id ASC LIMIT 1")->fetchColumn();
$orderedNoKeepTryingPreCallbackRows = $orderedNoKeepTryingDb->query("SELECT COUNT(*) FROM repeatcaller_incident_alert_history WHERE incident_id = {$orderedNoKeepTryingIncident} AND action_type = 'alert_call' AND stage_n = 1")->fetchColumn();
assert_same('0', (string)$orderedNoKeepTryingPreCallbackRows, 'ordered stage progression should have no stage-2 rows before the NOANSWER callback when keep trying is disabled');
$orderedNoKeepTryingRepo = new RepeatCallerRepository($orderedNoKeepTryingDb);
$orderedNoKeepTryingResult = $orderedNoKeepTryingRepo->recordAlertCallDialDisposition($orderedNoKeepTryingHistoryId, $orderedNoKeepTryingIncident, '910', 'NOANSWER', '19', '2026-07-13 13:42:30');
assert_true(!empty($orderedNoKeepTryingResult['next_history_id']), 'ordered stage progression should reserve the next stage rows after a terminal first attempt even when the first destination has keep trying disabled');
$orderedNoKeepTryingRows = $orderedNoKeepTryingDb->query("SELECT recipient, stage_n, delivery_status, next_retry_at, dedupe_key FROM repeatcaller_incident_alert_history WHERE incident_id = {$orderedNoKeepTryingIncident} AND action_type = 'alert_call' ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);
assert_same(2, count($orderedNoKeepTryingRows), 'ordered stage progression should reserve the original stage plus the stage-2 destination when keep trying is disabled for the first destination');
assert_same(['910', '911'], array_map(static function (array $row): string { return (string)$row['recipient']; }, $orderedNoKeepTryingRows), 'ordered stage progression should keep the original first destination and introduce only the new second destination when keep trying is disabled');
assert_same('1', (string)$orderedNoKeepTryingRows[1]['stage_n'], 'ordered stage progression should assign the deferred follow-up row to the next stage number when keep trying is disabled');
assert_same('pending', (string)$orderedNoKeepTryingRows[1]['delivery_status'], 'ordered stage progression should reserve the newly introduced destination as pending when keep trying is disabled');
assert_true((string)$orderedNoKeepTryingRows[1]['next_retry_at'] !== '', 'ordered stage progression should defer the newly introduced destination with a retry timestamp when keep trying is disabled');
assert_same('incident:' . $orderedNoKeepTryingIncident . '|event:' . $orderedNoKeepTryingEventType . '|stage:1|action:alert_call|recipient:911', (string)$orderedNoKeepTryingRows[1]['dedupe_key'], 'ordered stage progression should create the expected dedupe key for the deferred stage-2 destination');
assert_same(1, count($orderedNoKeepTryingSender->calls), 'ordered stage progression should not originate deferred stage-2 rows before their retry time when keep trying is disabled');

$orderedStageTwoClock = new TestClock('2026-07-13 13:43:00');
$orderedStageTwoSender = new FakeCallSender();
[$orderedStageTwoDb, $orderedStageTwoProcessor] = create_alert_environment($orderedStageTwoClock, new FakeEmailSender(), $orderedStageTwoSender);
$orderedStageTwoRule = insert_rule($orderedStageTwoDb, [
	'alert_call_enabled' => 1,
	'alert_call_destinations' => '900|1,901|0',
	'alert_call_strategy' => 'ordered',
	'alert_call_keep_trying' => 1,
	'alert_call_recording_id' => 55,
	'repeat_mode_override' => 'never',
]);
$orderedStageTwoIncident = insert_incident($orderedStageTwoDb, ['rule_id' => $orderedStageTwoRule, 'subject_key' => 'ordered-stage-two', 'first_matched_at' => '2026-07-13 13:43:00', 'suppression_expires_at' => '2026-07-13 14:43:00']);
$orderedStageTwoProcessor->run(settings());
$orderedStageTwoHistoryId = (int)$orderedStageTwoDb->query("SELECT id FROM repeatcaller_incident_alert_history WHERE incident_id = {$orderedStageTwoIncident} AND recipient = '900' LIMIT 1")->fetchColumn();
$orderedStageTwoEventType = (string)$orderedStageTwoDb->query("SELECT event_type FROM repeatcaller_incident_alert_history WHERE incident_id = {$orderedStageTwoIncident} AND action_type = 'alert_call' ORDER BY id ASC LIMIT 1")->fetchColumn();
$orderedStageTwoPreCallbackRows = $orderedStageTwoDb->query("SELECT COUNT(*) FROM repeatcaller_incident_alert_history WHERE incident_id = {$orderedStageTwoIncident} AND action_type = 'alert_call' AND stage_n = 1")->fetchColumn();
assert_same('0', (string)$orderedStageTwoPreCallbackRows, 'ordered stage progression should have no stage-2 rows before the NOANSWER callback when keep trying is enabled');
$orderedStageTwoRepo = new RepeatCallerRepository($orderedStageTwoDb);
$orderedStageTwoResult = $orderedStageTwoRepo->recordAlertCallDialDisposition($orderedStageTwoHistoryId, $orderedStageTwoIncident, '900', 'NOANSWER', '19', '2026-07-13 13:43:10');
assert_true(!empty($orderedStageTwoResult['next_history_id']), 'ordered stage progression should reserve the next stage rows after a terminal first attempt');
$orderedStageTwoRows = $orderedStageTwoDb->query("SELECT recipient, stage_n, delivery_status, next_retry_at, dedupe_key FROM repeatcaller_incident_alert_history WHERE incident_id = {$orderedStageTwoIncident} AND action_type = 'alert_call' ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);
assert_same(3, count($orderedStageTwoRows), 'ordered stage progression should reserve the original stage plus the next cumulative stage destinations when the first stage fails');
assert_same(['900', '900', '901'], array_map(static function (array $row): string { return (string)$row['recipient']; }, $orderedStageTwoRows), 'ordered stage progression should include the original first destination, the retained first destination, and the newly introduced second destination');
assert_same('1', (string)$orderedStageTwoRows[1]['stage_n'], 'ordered stage progression should assign the retained deferred follow-up row to the next stage number');
assert_same('1', (string)$orderedStageTwoRows[2]['stage_n'], 'ordered stage progression should assign the newly introduced deferred follow-up row to the next stage number');
assert_same('pending', (string)$orderedStageTwoRows[1]['delivery_status'], 'ordered stage progression should reserve the retained destination as pending');
assert_same('pending', (string)$orderedStageTwoRows[2]['delivery_status'], 'ordered stage progression should reserve the newly introduced destination as pending');
assert_true((string)$orderedStageTwoRows[1]['next_retry_at'] !== '', 'ordered stage progression should defer the retained destination with a retry timestamp');
assert_true((string)$orderedStageTwoRows[2]['next_retry_at'] !== '', 'ordered stage progression should defer the newly introduced destination with a retry timestamp');
assert_same('incident:' . $orderedStageTwoIncident . '|event:' . $orderedStageTwoEventType . '|stage:1|action:alert_call|recipient:900', (string)$orderedStageTwoRows[1]['dedupe_key'], 'ordered stage progression should create the expected dedupe key for the retained stage-2 destination');
assert_same('incident:' . $orderedStageTwoIncident . '|event:' . $orderedStageTwoEventType . '|stage:1|action:alert_call|recipient:901', (string)$orderedStageTwoRows[2]['dedupe_key'], 'ordered stage progression should create the expected dedupe key for the newly introduced stage-2 destination');
$orderedStageTwoProcessor->run(settings());
assert_same(1, count($orderedStageTwoSender->calls), 'ordered stage progression should not originate deferred stage-2 rows before their retry time');

$orderedOriginateFailClock = new TestClock('2026-07-13 13:45:00');
$orderedOriginateFailSender = new FakeCallSender();
$orderedOriginateFailSender->failuresByDestination['740'] = 'originate failed 740';
[$orderedOriginateFailDb, $orderedOriginateFailProcessor] = create_alert_environment($orderedOriginateFailClock, new FakeEmailSender(), $orderedOriginateFailSender);
$orderedOriginateFailRule = insert_rule($orderedOriginateFailDb, [
	'alert_call_enabled' => 1,
	'alert_call_destinations' => '740,741',
	'alert_call_strategy' => 'ordered',
	'alert_call_keep_trying' => 1,
	'alert_call_recording_id' => 55,
	'repeat_mode_override' => 'never',
]);
$orderedOriginateFailIncident = insert_incident($orderedOriginateFailDb, ['rule_id' => $orderedOriginateFailRule, 'subject_key' => 'ordered-originate-fail', 'first_matched_at' => '2026-07-13 13:45:00', 'suppression_expires_at' => '2026-07-13 14:45:00']);
$orderedOriginateFailProcessor->run(settings());
assert_same(2, count($orderedOriginateFailSender->calls), 'initial ordered originate failure should immediately advance to the next destination even when Alert Reminder is Never');
assert_same('740', (string)$orderedOriginateFailSender->calls[0]['destination'], 'ordered originate-failure path should still start with the first saved destination');
assert_same('741', (string)$orderedOriginateFailSender->calls[1]['destination'], 'ordered originate-failure path should immediately continue to the second destination');
$orderedOriginateFailRows = $orderedOriginateFailDb->query("SELECT recipient, delivery_status, attempted_at, failure_detail FROM repeatcaller_incident_alert_history WHERE incident_id = {$orderedOriginateFailIncident} AND action_type = 'alert_call' ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);
assert_same(2, count($orderedOriginateFailRows), 'ordered originate-failure progression should reserve exactly one history row per destination in the stage');
assert_same('failed', (string)$orderedOriginateFailRows[0]['delivery_status'], 'failed originate should be recorded as failed for the first destination');
assert_same('2026-07-13 13:45:00', (string)$orderedOriginateFailRows[0]['attempted_at'], 'first failed originate should be timestamped at the current processor time');
assert_same('2026-07-13 13:45:00', (string)$orderedOriginateFailRows[1]['attempted_at'], 'immediate ordered follow-up send should use the same processor timestamp with no deliberate delay');
assert_same('sent', (string)$orderedOriginateFailRows[1]['delivery_status'], 'successful immediate ordered follow-up should remain in the current stage and be marked sent');

$orderedMultiFailClock = new TestClock('2026-07-13 13:46:00');
$orderedMultiFailSender = new FakeCallSender();
$orderedMultiFailSender->failuresByDestination['750'] = 'originate failed 750';
$orderedMultiFailSender->failuresByDestination['751'] = 'originate failed 751';
[$orderedMultiFailDb, $orderedMultiFailProcessor] = create_alert_environment($orderedMultiFailClock, new FakeEmailSender(), $orderedMultiFailSender);
$orderedMultiFailRule = insert_rule($orderedMultiFailDb, [
	'alert_call_enabled' => 1,
	'alert_call_destinations' => '750,751,752',
	'alert_call_strategy' => 'ordered',
	'alert_call_keep_trying' => 1,
	'alert_call_recording_id' => 55,
	'repeat_mode_override' => 'never',
]);
$orderedMultiFailIncident = insert_incident($orderedMultiFailDb, ['rule_id' => $orderedMultiFailRule, 'subject_key' => 'ordered-multi-fail', 'first_matched_at' => '2026-07-13 13:46:00', 'suppression_expires_at' => '2026-07-13 14:46:00']);
$orderedMultiFailSummary = $orderedMultiFailProcessor->run(settings());
assert_same(3, count($orderedMultiFailSender->calls), 'multiple consecutive ordered originate failures should advance through the full destination list in one stage');
assert_same('750', (string)$orderedMultiFailSender->calls[0]['destination'], 'multi-failure path should keep the first saved destination first');
assert_same('751', (string)$orderedMultiFailSender->calls[1]['destination'], 'multi-failure path should continue to the second destination');
assert_same('752', (string)$orderedMultiFailSender->calls[2]['destination'], 'multi-failure path should continue to the third destination without creating a new stage');
$orderedMultiFailRows = $orderedMultiFailDb->query("SELECT recipient, delivery_status FROM repeatcaller_incident_alert_history WHERE incident_id = {$orderedMultiFailIncident} AND action_type = 'alert_call' ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);
assert_same(3, count($orderedMultiFailRows), 'multi-failure path should not create duplicate history reservations');
assert_same('failed', (string)$orderedMultiFailRows[0]['delivery_status'], 'first failed originate should remain failed');
assert_same('failed', (string)$orderedMultiFailRows[1]['delivery_status'], 'second failed originate should remain failed');
assert_same('sent', (string)$orderedMultiFailRows[2]['delivery_status'], 'first nonfailed originate should stop same-stage progression at the queued destination');
assert_same(2, (int)$orderedMultiFailSummary['alert_call_failed'], 'processor summary should count each immediate direct originate failure in the ordered stage');
assert_same(1, (int)$orderedMultiFailSummary['alert_call_sent'], 'processor summary should count the eventual successful ordered follow-up send once');

$orderedFinalFailClock = new TestClock('2026-07-13 13:47:00');
$orderedFinalFailSender = new FakeCallSender();
$orderedFinalFailSender->failuresByDestination['760'] = 'originate failed 760';
$orderedFinalFailSender->failuresByDestination['761'] = 'originate failed 761';
[$orderedFinalFailDb, $orderedFinalFailProcessor] = create_alert_environment($orderedFinalFailClock, new FakeEmailSender(), $orderedFinalFailSender);
$orderedFinalFailRule = insert_rule($orderedFinalFailDb, [
	'alert_call_enabled' => 1,
	'alert_call_destinations' => '760,761',
	'alert_call_strategy' => 'ordered',
	'alert_call_keep_trying' => 1,
	'alert_call_recording_id' => 55,
	'repeat_mode_override' => 'never',
]);
$orderedFinalFailIncident = insert_incident($orderedFinalFailDb, ['rule_id' => $orderedFinalFailRule, 'subject_key' => 'ordered-final-fail', 'first_matched_at' => '2026-07-13 13:47:00', 'suppression_expires_at' => '2026-07-13 14:47:00']);
$orderedFinalFailProcessor->run(settings());
assert_same(2, count($orderedFinalFailSender->calls), 'final ordered originate failure should still attempt each destination once in the current stage');
assert_same(2, count_history($orderedFinalFailDb, "incident_id = {$orderedFinalFailIncident} AND action_type = 'alert_call'"), 'final originate failure should complete the ordered stage without duplicate reservations');
assert_same(0, count_history($orderedFinalFailDb, "incident_id = {$orderedFinalFailIncident} AND action_type = 'alert_call' AND delivery_status IN ('pending', 'snoozed', 'sending', 'sent')"), 'final originate failure should leave no deliverable same-stage alert rows behind');
$orderedFinalFailClock->now = '2026-07-13 13:47:30';
$orderedFinalFailProcessor->run(settings());
assert_same(2, count($orderedFinalFailSender->calls), 'completed ordered stage after final originate failure should not resend destinations without a new reminder stage');

$orderedAcceptAfterFailClock = new TestClock('2026-07-13 13:48:00');
$orderedAcceptAfterFailSender = new FakeCallSender();
$orderedAcceptAfterFailSender->failuresByDestination['770'] = 'originate failed 770';
[$orderedAcceptAfterFailDb, $orderedAcceptAfterFailProcessor] = create_alert_environment($orderedAcceptAfterFailClock, new FakeEmailSender(), $orderedAcceptAfterFailSender);
$orderedAcceptAfterFailRule = insert_rule($orderedAcceptAfterFailDb, [
	'alert_call_enabled' => 1,
	'alert_call_destinations' => '770,771,772',
	'alert_call_strategy' => 'ordered',
	'alert_call_keep_trying' => 1,
	'alert_call_recording_id' => 55,
	'repeat_mode_override' => 'never',
]);
$orderedAcceptAfterFailIncident = insert_incident($orderedAcceptAfterFailDb, ['rule_id' => $orderedAcceptAfterFailRule, 'subject_key' => 'ordered-accept-after-fail', 'first_matched_at' => '2026-07-13 13:48:00', 'suppression_expires_at' => '2026-07-13 14:48:00']);
$orderedAcceptAfterFailProcessor->run(settings());
assert_same(2, count($orderedAcceptAfterFailSender->calls), 'ordered progression should stop once a destination is successfully queued into the normal callback path');
$orderedAcceptHistoryId = (int)$orderedAcceptAfterFailDb->query("SELECT id FROM repeatcaller_incident_alert_history WHERE incident_id = {$orderedAcceptAfterFailIncident} AND recipient = '771' LIMIT 1")->fetchColumn();
(new RepeatCallerRepository($orderedAcceptAfterFailDb))->recordAlertCallDtmfResponse($orderedAcceptHistoryId, $orderedAcceptAfterFailIncident, 'accepted', '771', '1', '2026-07-13 13:48:10');
assert_same(0, count_history($orderedAcceptAfterFailDb, "incident_id = {$orderedAcceptAfterFailIncident} AND action_type = 'alert_call' AND recipient = '772'"), 'eventual answered-and-accepted ordered destination should stop progression before later destinations are reserved');

$orderedChainClock = new TestClock('2026-07-13 13:49:00');
$orderedChainSender = new FakeCallSender();
[$orderedChainDb, $orderedChainProcessor] = create_alert_environment($orderedChainClock, new FakeEmailSender(), $orderedChainSender);
$orderedChainRule = insert_rule($orderedChainDb, [
	'alert_call_enabled' => 1,
	'alert_call_destinations' => '1000|1,1001|0,1002|1',
	'alert_call_strategy' => 'ordered',
	'alert_call_keep_trying' => 1,
	'alert_call_recording_id' => 55,
	'repeat_mode_override' => 'never',
]);
$orderedChainIncident = insert_incident($orderedChainDb, ['rule_id' => $orderedChainRule, 'subject_key' => 'ordered-chain', 'first_matched_at' => '2026-07-13 13:49:00', 'suppression_expires_at' => '2026-07-13 14:49:00']);
$orderedChainProcessor->run(settings());
assert_same(1, count($orderedChainSender->calls), 'ordered three-destination chain should start with the first destination for stage 1');
assert_same('1000', (string)$orderedChainSender->calls[0]['destination'], 'ordered three-destination chain should begin with destination 1');
$orderedChainStageOneHistoryId = (int)$orderedChainDb->query("SELECT id FROM repeatcaller_incident_alert_history WHERE incident_id = {$orderedChainIncident} AND recipient = '1000' AND stage_n = 0 LIMIT 1")->fetchColumn();
$orderedChainRepo = new RepeatCallerRepository($orderedChainDb);
$orderedChainStageOneResult = $orderedChainRepo->recordAlertCallDialDisposition($orderedChainStageOneHistoryId, $orderedChainIncident, '1000', 'NOANSWER', '19', '2026-07-13 13:49:10');
assert_true(!empty($orderedChainStageOneResult['next_history_id']), 'ordered three-destination chain should reserve the next stage after stage 1 completes without acceptance');
$orderedChainRows = $orderedChainDb->query("SELECT id, recipient, stage_n, delivery_status, next_retry_at FROM repeatcaller_incident_alert_history WHERE incident_id = {$orderedChainIncident} AND action_type = 'alert_call' ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);
assert_same(3, count($orderedChainRows), 'ordered three-destination chain should reserve stage 2 with destinations 1 and 2 after stage 1 completes');
assert_same(['1000', '1000', '1001'], array_map(static function (array $row): string { return (string)$row['recipient']; }, $orderedChainRows), 'ordered three-destination chain should retain destination 1 and add destination 2 in stage 2');
assert_same(['0', '1', '1'], array_map(static function (array $row): string { return (string)$row['stage_n']; }, $orderedChainRows), 'ordered three-destination chain should assign stage 1 as stage 0 and stage 2 as stage 1');
assert_same('pending', (string)$orderedChainRows[1]['delivery_status'], 'ordered three-destination chain should reserve stage-2 destination 1 as pending');
assert_same('pending', (string)$orderedChainRows[2]['delivery_status'], 'ordered three-destination chain should reserve stage-2 destination 2 as pending');
assert_true((string)$orderedChainRows[1]['next_retry_at'] !== '', 'ordered three-destination chain should defer stage-2 destination 1 by 60 seconds');
assert_true((string)$orderedChainRows[2]['next_retry_at'] !== '', 'ordered three-destination chain should defer stage-2 destination 2 by 60 seconds');
$orderedChainStageTwoRows = $orderedChainDb->query("SELECT id, recipient FROM repeatcaller_incident_alert_history WHERE incident_id = {$orderedChainIncident} AND action_type = 'alert_call' AND stage_n = 1 ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);
assert_same(2, count($orderedChainStageTwoRows), 'ordered three-destination chain should create exactly two stage-2 attempts');
$orderedChainStageTwoFirstId = (int)$orderedChainStageTwoRows[0]['id'];
$orderedChainStageTwoSecondId = (int)$orderedChainStageTwoRows[1]['id'];
$orderedChainRepo->recordAlertCallDialDisposition($orderedChainStageTwoFirstId, $orderedChainIncident, '1000', 'NOANSWER', '19', '2026-07-13 13:49:20');
$orderedChainStageTwoPendingRows = $orderedChainDb->query("SELECT COUNT(*) FROM repeatcaller_incident_alert_history WHERE incident_id = {$orderedChainIncident} AND action_type = 'alert_call' AND stage_n = 2")->fetchColumn();
assert_same('0', (string)$orderedChainStageTwoPendingRows, 'ordered three-destination chain should not schedule stage 3 while one stage-2 sibling remains active');
$orderedChainRepo->recordAlertCallDialDisposition($orderedChainStageTwoSecondId, $orderedChainIncident, '1001', 'NOANSWER', '19', '2026-07-13 13:49:30');
$orderedChainStageThreeRows = $orderedChainDb->query("SELECT id, recipient, stage_n, delivery_status, next_retry_at FROM repeatcaller_incident_alert_history WHERE incident_id = {$orderedChainIncident} AND action_type = 'alert_call' AND stage_n = 2 ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);
assert_same(2, count($orderedChainStageThreeRows), 'ordered three-destination chain should create stage 3 with destinations 1 and 3 after every stage-2 attempt finishes');
assert_same(['1000', '1002'], array_map(static function (array $row): string { return (string)$row['recipient']; }, $orderedChainStageThreeRows), 'ordered three-destination chain should retain destination 1 and introduce destination 3 in stage 3');
assert_same(['2', '2'], array_map(static function (array $row): string { return (string)$row['stage_n']; }, $orderedChainStageThreeRows), 'ordered three-destination chain should assign stage-3 rows to stage number 2');
assert_same('pending', (string)$orderedChainStageThreeRows[0]['delivery_status'], 'ordered three-destination chain should reserve stage-3 destination 1 as pending');
assert_same('pending', (string)$orderedChainStageThreeRows[1]['delivery_status'], 'ordered three-destination chain should reserve stage-3 destination 3 as pending');
assert_true((string)$orderedChainStageThreeRows[0]['next_retry_at'] !== '', 'ordered three-destination chain should defer stage-3 destination 1 by 60 seconds');
assert_true((string)$orderedChainStageThreeRows[1]['next_retry_at'] !== '', 'ordered three-destination chain should defer stage-3 destination 3 by 60 seconds');
$orderedChainStageThreeDestinationTwoRows = $orderedChainDb->query("SELECT COUNT(*) FROM repeatcaller_incident_alert_history WHERE incident_id = {$orderedChainIncident} AND action_type = 'alert_call' AND recipient = '1001' AND stage_n >= 2")->fetchColumn();
assert_same('0', (string)$orderedChainStageThreeDestinationTwoRows, 'ordered three-destination chain should not bring back destination 2 after its introduction stage');
$orderedChainStageThreeFirstId = (int)$orderedChainStageThreeRows[0]['id'];
$orderedChainStageThreeSecondId = (int)$orderedChainStageThreeRows[1]['id'];
$orderedChainRepo->recordAlertCallDialDisposition($orderedChainStageThreeFirstId, $orderedChainIncident, '1000', 'NOANSWER', '19', '2026-07-13 13:49:40');
$orderedChainStageFourPendingRows = $orderedChainDb->query("SELECT COUNT(*) FROM repeatcaller_incident_alert_history WHERE incident_id = {$orderedChainIncident} AND action_type = 'alert_call' AND stage_n = 3")->fetchColumn();
assert_same('0', (string)$orderedChainStageFourPendingRows, 'ordered three-destination chain should not schedule stage 4 while one stage-3 sibling remains active');
$orderedChainRepo->recordAlertCallDialDisposition($orderedChainStageThreeSecondId, $orderedChainIncident, '1002', 'NOANSWER', '19', '2026-07-13 13:49:50');
$orderedChainStageFourRows = $orderedChainDb->query("SELECT id, recipient, stage_n FROM repeatcaller_incident_alert_history WHERE incident_id = {$orderedChainIncident} AND action_type = 'alert_call' AND stage_n = 3 ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);
assert_same(2, count($orderedChainStageFourRows), 'ordered three-destination chain should create stage 4 with destinations 1 and 3 after stage 3 completes');
assert_same(['1000', '1002'], array_map(static function (array $row): string { return (string)$row['recipient']; }, $orderedChainStageFourRows), 'ordered three-destination chain should continue retrying destinations 1 and 3 in later stages');
$orderedChainStageFourFirstId = (int)$orderedChainStageFourRows[0]['id'];
$orderedChainStageFourSecondId = (int)$orderedChainStageFourRows[1]['id'];
$orderedChainRepo->recordAlertCallDialDisposition($orderedChainStageFourFirstId, $orderedChainIncident, '1000', 'NOANSWER', '19', '2026-07-13 13:49:60');
$orderedChainStageFivePendingRows = $orderedChainDb->query("SELECT COUNT(*) FROM repeatcaller_incident_alert_history WHERE incident_id = {$orderedChainIncident} AND action_type = 'alert_call' AND stage_n = 4")->fetchColumn();
assert_same('0', (string)$orderedChainStageFivePendingRows, 'ordered three-destination chain should not schedule a later stage while one stage-4 sibling remains active');
$orderedChainRepo->recordAlertCallDialDisposition($orderedChainStageFourSecondId, $orderedChainIncident, '1002', 'NOANSWER', '19', '2026-07-13 13:49:70');
$orderedChainStageFiveRows = $orderedChainDb->query("SELECT id, recipient, stage_n FROM repeatcaller_incident_alert_history WHERE incident_id = {$orderedChainIncident} AND action_type = 'alert_call' AND stage_n = 4 ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);
assert_same(2, count($orderedChainStageFiveRows), 'ordered three-destination chain should continue retrying destinations 1 and 3 in later stages after stage 4');
assert_same(['1000', '1002'], array_map(static function (array $row): string { return (string)$row['recipient']; }, $orderedChainStageFiveRows), 'ordered three-destination chain should preserve destinations 1 and 3 in subsequent continuation stages');
$orderedChainAcceptanceId = (int)$orderedChainDb->query("SELECT id FROM repeatcaller_incident_alert_history WHERE incident_id = {$orderedChainIncident} AND action_type = 'alert_call' AND recipient = '1000' AND stage_n = 1 ORDER BY id ASC LIMIT 1")->fetchColumn();
$orderedChainAcceptedResult = $orderedChainRepo->recordAlertCallDtmfResponse($orderedChainAcceptanceId, $orderedChainIncident, 'accepted', '1000', '1', '2026-07-13 13:49:80');
assert_true(!empty($orderedChainAcceptedResult['accepted']), 'ordered three-destination chain should accept the incident when any destination accepts');
$orderedChainPendingAfterAcceptance = $orderedChainDb->query("SELECT COUNT(*) FROM repeatcaller_incident_alert_history WHERE incident_id = {$orderedChainIncident} AND action_type = 'alert_call' AND delivery_status IN ('pending', 'snoozed', 'sending')")->fetchColumn();
assert_same('0', (string)$orderedChainPendingAfterAcceptance, 'ordered three-destination chain should cancel pending future rows when any destination accepts');
$orderedChainSiblingCallbackResult = $orderedChainRepo->recordAlertCallDialDisposition($orderedChainStageTwoSecondId, $orderedChainIncident, '1001', 'NOANSWER', '19', '2026-07-13 13:49:90');
assert_true(empty($orderedChainSiblingCallbackResult['next_history_id']), 'ordered three-destination chain should prevent later sibling callbacks from creating another stage after acceptance');
$orderedChainDuplicateAcceptanceResult = $orderedChainRepo->recordAlertCallDtmfResponse($orderedChainAcceptanceId, $orderedChainIncident, 'accepted', '1000', '1', '2026-07-13 13:49:95');
assert_true(empty($orderedChainDuplicateAcceptanceResult['accepted']), 'ordered three-destination chain should keep acceptance callbacks idempotent');
$orderedChainLateCallbackResult = $orderedChainRepo->recordAlertCallDialDisposition($orderedChainStageThreeSecondId, $orderedChainIncident, '1002', 'NOANSWER', '19', '2026-07-13 13:50:00');
assert_true(empty($orderedChainLateCallbackResult['next_history_id']), 'ordered three-destination chain should keep late callbacks idempotent after acceptance');

$parallelAcceptClock = new TestClock('2026-07-13 13:49:00');
$parallelAcceptSender = new FakeCallSender();
[$parallelAcceptDb, $parallelAcceptProcessor] = create_alert_environment($parallelAcceptClock, new FakeEmailSender(), $parallelAcceptSender);
$parallelAcceptRule = insert_rule($parallelAcceptDb, [
	'alert_call_enabled' => 1,
	'alert_call_destinations' => '780,781',
	'alert_call_strategy' => 'ringall',
	'alert_call_keep_trying' => 1,
	'alert_call_recording_id' => 55,
	'repeat_mode_override' => 'never',
]);
$parallelAcceptIncident = insert_incident($parallelAcceptDb, ['rule_id' => $parallelAcceptRule, 'subject_key' => 'parallel-accept', 'first_matched_at' => '2026-07-13 13:49:00', 'suppression_expires_at' => '2026-07-13 14:49:00']);
$parallelAcceptSender->afterCall = function (string $destination) use ($parallelAcceptDb, $parallelAcceptIncident): void {
	if ($destination !== '780') {
		return;
	}
	(new RepeatCallerRepository($parallelAcceptDb))->acceptActiveIncident($parallelAcceptIncident, 'gui-user', '2026-07-13 13:49:01', 'gui');
};
$parallelAcceptProcessor->run(settings());
assert_same(1, count($parallelAcceptSender->calls), 'acceptance on one concurrent call should stop any further originates for that incident in the same processor pass');
assert_same(0, count_history($parallelAcceptDb, "incident_id = {$parallelAcceptIncident} AND action_type = 'alert_call' AND recipient = '781' AND delivery_status IN ('pending', 'snoozed', 'sending')"), 'acceptance should cancel or make ineligible any reserved but unsent concurrent alert-call attempts');

$astman = new FakeAstman();
$astman->dbValues['repeatcaller/alertcall/1/launch_channel'] = 'Local/780@repeatcaller-alert-launch-0001;1';
$astman->dbValues['repeatcaller/alertcall/1/playback_channel'] = 'Local/780@from-internal-0001;2';
$astman->dbValues['repeatcaller/alertcall/2/launch_channel'] = 'Local/781@repeatcaller-alert-launch-0002;1';
$astman->dbValues['repeatcaller/alertcall/2/playback_channel'] = 'Local/781@from-internal-0002;2';
$signalModule = new \FreePBX\modules\Repeatcaller(new stdClass());
$signalModule->signalIncidentAcceptedForLiveAlertCalls($parallelAcceptIncident, 1);
$requestActions = array_map(static function (array $request): string {
	return (string)$request['action'];
}, $astman->requests);
assert_true(in_array('DBPut', $requestActions, true), 'acceptance signalling should mark the incident accepted in Asterisk DB for live legs');
assert_true(in_array('DBGet', $requestActions, true), 'acceptance signalling should query tracked live alert-call channels by history id');
assert_true(in_array('Redirect', $requestActions, true), 'acceptance signalling should redirect already answered playback legs to the remote-accepted path');
assert_true(in_array('Hangup', $requestActions, true), 'acceptance signalling should hang up remaining launch or ringing legs immediately');

$lateCallbackClock = new TestClock('2026-07-13 13:49:30');
[$lateCallbackDb, $lateCallbackProcessor] = create_alert_environment($lateCallbackClock, new FakeEmailSender(), new FakeCallSender());
$lateCallbackRule = insert_rule($lateCallbackDb, [
	'alert_call_enabled' => 1,
	'alert_call_destinations' => '790,791',
	'alert_call_strategy' => 'ringall',
	'alert_call_keep_trying' => 1,
	'alert_call_recording_id' => 55,
	'repeat_mode_override' => '5m',
]);
$lateCallbackIncident = insert_incident($lateCallbackDb, ['rule_id' => $lateCallbackRule, 'subject_key' => 'late-callback', 'first_matched_at' => '2026-07-13 13:49:30', 'suppression_expires_at' => '2026-07-13 14:49:30']);
$lateCallbackProcessor->run(settings());
$lateCallbackRows = $lateCallbackDb->query("SELECT id, recipient FROM repeatcaller_incident_alert_history WHERE incident_id = {$lateCallbackIncident} AND action_type = 'alert_call' ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);
$lateCallbackRepo = new RepeatCallerRepository($lateCallbackDb);
$lateCallbackRepo->recordAlertCallDtmfResponse((int)$lateCallbackRows[0]['id'], $lateCallbackIncident, 'accepted', (string)$lateCallbackRows[0]['recipient'], '1', '2026-07-13 13:49:35');
$lateCallbackResult = $lateCallbackRepo->recordAlertCallDialDisposition((int)$lateCallbackRows[1]['id'], $lateCallbackIncident, (string)$lateCallbackRows[1]['recipient'], 'BUSY', '17', '2026-07-13 13:49:40');
assert_true(empty($lateCallbackResult['next_history_id']), 'late AGI or dialstatus callbacks after acceptance must not restart progression or reserve another destination');

$idempotentAcceptClock = new TestClock('2026-07-13 13:49:45');
[$idempotentAcceptDb, $idempotentAcceptProcessor] = create_alert_environment($idempotentAcceptClock, new FakeEmailSender(), new FakeCallSender());
$idempotentAcceptRule = insert_rule($idempotentAcceptDb, [
	'alert_call_enabled' => 1,
	'alert_call_destinations' => '795,796',
	'alert_call_strategy' => 'ringall',
	'alert_call_keep_trying' => 1,
	'alert_call_recording_id' => 55,
	'repeat_mode_override' => '5m',
]);
$idempotentAcceptIncident = insert_incident($idempotentAcceptDb, ['rule_id' => $idempotentAcceptRule, 'subject_key' => 'idempotent-accept', 'first_matched_at' => '2026-07-13 13:49:45', 'suppression_expires_at' => '2026-07-13 14:49:45']);
$idempotentAcceptProcessor->run(settings());
$idempotentRows = $idempotentAcceptDb->query("SELECT id, recipient FROM repeatcaller_incident_alert_history WHERE incident_id = {$idempotentAcceptIncident} AND action_type = 'alert_call' ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);
$idempotentRepo = new RepeatCallerRepository($idempotentAcceptDb);
$firstAccept = $idempotentRepo->recordAlertCallDtmfResponse((int)$idempotentRows[0]['id'], $idempotentAcceptIncident, 'accepted', (string)$idempotentRows[0]['recipient'], '1', '2026-07-13 13:49:50');
$secondAccept = $idempotentRepo->recordAlertCallDtmfResponse((int)$idempotentRows[1]['id'], $idempotentAcceptIncident, 'accepted', (string)$idempotentRows[1]['recipient'], '1', '2026-07-13 13:49:51');
$idempotentIncident = $idempotentAcceptDb->query("SELECT state, accepted_by, accepted_at, accept_source FROM repeatcaller_incidents WHERE id = {$idempotentAcceptIncident}")->fetch(PDO::FETCH_ASSOC);
assert_true(!empty($firstAccept['accepted']), 'first concurrent acceptance callback should accept the incident');
assert_true(empty($secondAccept['accepted']), 'concurrent acceptance callbacks must remain idempotent after the incident is already accepted');
assert_same('accepted', (string)$idempotentIncident['state'], 'concurrent acceptance callbacks must leave the incident accepted');
assert_same('alert_call', (string)$idempotentIncident['accept_source'], 'concurrent acceptance callbacks must preserve the first acceptance source');

$ignoredCallerOrderedClock = new TestClock('2026-07-13 13:50:00');
$ignoredCallerOrderedSender = new FakeCallSender();
[$ignoredCallerOrderedDb, $ignoredCallerOrderedProcessor] = create_alert_environment($ignoredCallerOrderedClock, new FakeEmailSender(), $ignoredCallerOrderedSender);
$ignoredCallerOrderedRule = insert_rule($ignoredCallerOrderedDb, [
	'alert_call_enabled' => 1,
	'alert_call_destinations' => '810,811',
	'alert_call_strategy' => 'ordered',
	'alert_call_keep_trying' => 1,
	'alert_call_recording_id' => 55,
	'repeat_mode_override' => 'never',
]);
$ignoredCallerOrderedDb->prepare('INSERT INTO repeatcaller_rule_callers (rule_id, list_type, raw_value, normalized_value, created_at) VALUES (?, ?, ?, ?, ?)')->execute([$ignoredCallerOrderedRule, 'exclude', '810', '810', '2026-07-13 13:50:00']);
$ignoredCallerOrderedIncident = insert_incident($ignoredCallerOrderedDb, ['rule_id' => $ignoredCallerOrderedRule, 'subject_key' => 'ignored-caller-ordered', 'first_matched_at' => '2026-07-13 13:50:00', 'suppression_expires_at' => '2026-07-13 14:50:00']);
$ignoredCallerOrderedProcessor->run(settings());
assert_same(1, count($ignoredCallerOrderedSender->calls), 'ordered alert calls must still originate the first configured destination even when that destination also appears in ignore callers');
assert_same('810', (string)$ignoredCallerOrderedSender->calls[0]['destination'], 'ordered alert calls must not skip a destination merely because it appears in ignore callers');

// Ring All remains destination-wide while Ignore Callers remains an inbound-detection safeguard.
$ignoredCallerRingAllClock = new TestClock('2026-07-13 13:51:00');
$ignoredCallerRingAllSender = new FakeCallSender();
[$ignoredCallerRingAllDb, $ignoredCallerRingAllProcessor] = create_alert_environment($ignoredCallerRingAllClock, new FakeEmailSender(), $ignoredCallerRingAllSender);
$ignoredCallerRingAllRule = insert_rule($ignoredCallerRingAllDb, [
	'alert_call_enabled' => 1,
	'alert_call_destinations' => '820,821',
	'alert_call_strategy' => 'ringall',
	'alert_call_keep_trying' => 1,
	'alert_call_recording_id' => 55,
	'repeat_mode_override' => 'never',
]);
$ignoredCallerRingAllDb->prepare('INSERT INTO repeatcaller_rule_callers (rule_id, list_type, raw_value, normalized_value, created_at) VALUES (?, ?, ?, ?, ?)')->execute([$ignoredCallerRingAllRule, 'exclude', '820', '820', '2026-07-13 13:51:00']);
$ignoredCallerRingAllIncident = insert_incident($ignoredCallerRingAllDb, ['rule_id' => $ignoredCallerRingAllRule, 'subject_key' => 'ignored-caller-ringall', 'first_matched_at' => '2026-07-13 13:51:00', 'suppression_expires_at' => '2026-07-13 14:51:00']);
$ignoredCallerRingAllProcessor->run(settings());
assert_same(2, count($ignoredCallerRingAllSender->calls), 'ring-all alert calls must include every enabled destination even when one destination also appears in ignore callers');
assert_same(['820', '821'], array_map(static function (array $call): string { return (string)$call['destination']; }, $ignoredCallerRingAllSender->calls), 'ring-all alert calls must not drop an enabled destination because it also appears in ignore callers');

$orderedNoRetryClock = new TestClock('2026-07-13 13:52:00');
$orderedNoRetrySender = new FakeCallSender();
[$orderedNoRetryDb, $orderedNoRetryProcessor] = create_alert_environment($orderedNoRetryClock, new FakeEmailSender(), $orderedNoRetrySender);
$orderedNoRetryRule = insert_rule($orderedNoRetryDb, [
	'alert_call_enabled' => 1,
	'alert_call_destinations' => '710|0',
	'alert_call_strategy' => 'ordered',
	'alert_call_keep_trying' => 1,
	'alert_call_recording_id' => 55,
	'repeat_mode_override' => '5m',
]);
$orderedNoRetryIncident = insert_incident($orderedNoRetryDb, ['rule_id' => $orderedNoRetryRule, 'subject_key' => 'ordered-no-retry', 'first_matched_at' => '2026-07-13 13:52:00', 'suppression_expires_at' => '2026-07-13 14:52:00']);
$orderedNoRetryProcessor->run(settings());
$orderedNoRetryHistoryId = (int)$orderedNoRetryDb->query("SELECT id FROM repeatcaller_incident_alert_history WHERE incident_id = {$orderedNoRetryIncident} AND recipient = '710' LIMIT 1")->fetchColumn();
(new RepeatCallerRepository($orderedNoRetryDb))->recordAlertCallDialDisposition($orderedNoRetryHistoryId, $orderedNoRetryIncident, '710', 'NOANSWER', '19', '2026-07-13 13:52:10');
$orderedNoRetryClock->now = '2026-07-13 13:55:00';
$orderedNoRetryProcessor->run(settings());
assert_same(1, count($orderedNoRetrySender->calls), 'destination-scoped keep-trying disabled should limit each recipient to one attempt even when rule-level keep-trying is enabled');

$dialMapClock = new TestClock('2026-07-13 14:10:00');
[$dialMapDb, $dialMapProcessor] = create_alert_environment($dialMapClock, new FakeEmailSender(), new FakeCallSender());
$dialMapRule = insert_rule($dialMapDb, ['alert_call_enabled' => 1, 'alert_call_destinations' => '720', 'alert_call_recording_id' => 55, 'repeat_mode_override' => 'never']);
$dialMapIncident = insert_incident($dialMapDb, ['rule_id' => $dialMapRule, 'subject_key' => 'dial-map', 'first_matched_at' => '2026-07-13 14:10:00', 'suppression_expires_at' => '2026-07-13 15:10:00']);
$dialMapProcessor->run(settings());
$dialMapHistoryId = (int)$dialMapDb->query("SELECT id FROM repeatcaller_incident_alert_history WHERE incident_id = {$dialMapIncident} AND recipient = '720' LIMIT 1")->fetchColumn();
$dialRepo = new RepeatCallerRepository($dialMapDb);
$dialRepo->recordAlertCallDialDisposition($dialMapHistoryId, $dialMapIncident, '720', 'CONGESTION', '34', '2026-07-13 14:10:15');
$dialRow = $dialMapDb->query("SELECT delivery_status FROM repeatcaller_incident_alert_history WHERE id = {$dialMapHistoryId}")->fetch(PDO::FETCH_ASSOC);
assert_same('congestion', (string)$dialRow['delivery_status'], 'dialstatus CONGESTION must map to congestion');
$dialMapDb->prepare("UPDATE repeatcaller_incident_alert_history SET delivery_status = 'sent', failure_detail = NULL, successful_at = NULL WHERE id = ?")->execute([$dialMapHistoryId]);
$dialRepo->recordAlertCallDialDisposition($dialMapHistoryId, $dialMapIncident, '720', 'CHANUNAVAIL', '20', '2026-07-13 14:10:20');
$dialRow = $dialMapDb->query("SELECT delivery_status FROM repeatcaller_incident_alert_history WHERE id = {$dialMapHistoryId}")->fetch(PDO::FETCH_ASSOC);
assert_same('unreachable', (string)$dialRow['delivery_status'], 'dialstatus CHANUNAVAIL must map to unreachable');
$dialMapDb->prepare("UPDATE repeatcaller_incident_alert_history SET delivery_status = 'sent', failure_detail = NULL, successful_at = NULL WHERE id = ?")->execute([$dialMapHistoryId]);
$dialRepo->recordAlertCallDialDisposition($dialMapHistoryId, $dialMapIncident, '720', 'UNKNOWN', '0', '2026-07-13 14:10:25');
$dialRow = $dialMapDb->query("SELECT delivery_status FROM repeatcaller_incident_alert_history WHERE id = {$dialMapHistoryId}")->fetch(PDO::FETCH_ASSOC);
assert_same('failed', (string)$dialRow['delivery_status'], 'unknown non-ANSWER dialstatus must map to failed');

$answerPreserveClock = new TestClock('2026-07-13 14:20:00');
[$answerPreserveDb, $answerPreserveProcessor] = create_alert_environment($answerPreserveClock, new FakeEmailSender(), new FakeCallSender());
$answerPreserveRule = insert_rule($answerPreserveDb, ['alert_call_enabled' => 1, 'alert_call_destinations' => '730', 'alert_call_recording_id' => 55, 'repeat_mode_override' => 'never']);
$answerPreserveIncident = insert_incident($answerPreserveDb, ['rule_id' => $answerPreserveRule, 'subject_key' => 'answer-preserve', 'first_matched_at' => '2026-07-13 14:20:00', 'suppression_expires_at' => '2026-07-13 15:20:00']);
$answerPreserveProcessor->run(settings());
$answerPreserveHistoryId = (int)$answerPreserveDb->query("SELECT id FROM repeatcaller_incident_alert_history WHERE incident_id = {$answerPreserveIncident} AND action_type = 'alert_call' LIMIT 1")->fetchColumn();
$answerRepo = new RepeatCallerRepository($answerPreserveDb);
$answerRepo->recordAlertCallDtmfResponse($answerPreserveHistoryId, $answerPreserveIncident, 'declined', '730', '2', '2026-07-13 14:20:10');
$answerRepo->recordAlertCallDialDisposition($answerPreserveHistoryId, $answerPreserveIncident, '730', 'ANSWER', '16', '2026-07-13 14:20:20');
$answerRow = $answerPreserveDb->query("SELECT delivery_status FROM repeatcaller_incident_alert_history WHERE id = {$answerPreserveHistoryId}")->fetch(PDO::FETCH_ASSOC);
assert_same('declined', (string)$answerRow['delivery_status'], 'ANSWER dialstatus callback must not overwrite final DTMF disposition');

$existingAcceptClock = new TestClock('2026-07-13 14:00:00');
[$existingAcceptDb, $existingAcceptProcessor] = create_alert_environment($existingAcceptClock, new FakeEmailSender(), new FakeCallSender());
$existingAcceptRule = insert_rule($existingAcceptDb, ['alert_call_enabled' => 1, 'alert_call_destinations' => '500', 'alert_call_recording_id' => 55, 'repeat_mode_override' => 'never']);
$existingAcceptIncident = insert_incident($existingAcceptDb, ['rule_id' => $existingAcceptRule, 'subject_key' => 'already-accepted', 'first_matched_at' => '2026-07-13 14:00:00', 'suppression_expires_at' => '2026-07-13 15:00:00']);
$existingAcceptProcessor->run(settings());
$existingAcceptHistoryId = (int)$existingAcceptDb->query("SELECT id FROM repeatcaller_incident_alert_history WHERE incident_id = {$existingAcceptIncident} AND action_type = 'alert_call' LIMIT 1")->fetchColumn();
(new RepeatCallerRepository($existingAcceptDb))->acceptActiveIncident($existingAcceptIncident, 'admin', '2026-07-13 14:00:30', 'gui');
$existingAcceptResult = (new RepeatCallerRepository($existingAcceptDb))->recordAlertCallDtmfResponse($existingAcceptHistoryId, $existingAcceptIncident, 'accepted', '500', '1', '2026-07-13 14:01:00');
$existingAcceptIncidentRow = $existingAcceptDb->query("SELECT state, accepted_by, accepted_at, accept_source FROM repeatcaller_incidents WHERE id = {$existingAcceptIncident}")->fetch(PDO::FETCH_ASSOC);
assert_true(empty($existingAcceptResult['accepted']), 'DTMF 1 after an existing accept should not create a second accept');
assert_same('accepted', (string)$existingAcceptIncidentRow['state'], 'existing accepted incident should remain accepted');
assert_same('admin', (string)$existingAcceptIncidentRow['accepted_by'], 'existing accept user must not be overwritten');
assert_same('2026-07-13 14:00:30', (string)$existingAcceptIncidentRow['accepted_at'], 'existing accept timestamp must not be overwritten');
assert_same('gui', (string)$existingAcceptIncidentRow['accept_source'], 'existing accept source must not be overwritten');

$callSender->failuresByDestination['101'] = 'originate failed';
$reminderClock = new TestClock('2026-07-13 10:05:00');
[$callDbFail, $callProcessorFail] = create_alert_environment($reminderClock, new FakeEmailSender(), $callSender);
$callRuleFail = insert_rule($callDbFail, [
	'name' => 'Call Fail Rule',
	'email_enabled' => 0,
	'alert_call_enabled' => 1,
	'alert_call_destinations' => '101',
	'alert_call_recording_id' => 77,
	'repeat_mode_override' => 'never',
]);
$callIncidentFail = insert_incident($callDbFail, [
	'rule_id' => $callRuleFail,
	'subject_key' => '+441234500004',
	'subject_label' => '+441234500004',
	'first_matched_at' => '2026-07-13 10:05:00',
	'suppression_expires_at' => '2026-07-13 11:05:00',
]);
$callFailSummary = $callProcessorFail->run(settings());
$failedCallHistory = $callDbFail->query("SELECT delivery_status, failure_detail FROM repeatcaller_incident_alert_history WHERE incident_id = {$callIncidentFail} AND action_type = 'alert_call' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$failedCallIncident = $callDbFail->query("SELECT state, accepted_by FROM repeatcaller_incidents WHERE id = {$callIncidentFail}")->fetch(PDO::FETCH_ASSOC);
assert_same('failed', (string)$failedCallHistory['delivery_status'], 'failed call alerts should store failed result in history');
assert_same('originate failed', (string)$failedCallHistory['failure_detail'], 'failed call alerts should store transport failure detail');
assert_same('active', (string)$failedCallIncident['state'], 'originate failure should leave the incident active');
assert_true($failedCallIncident['accepted_by'] === null, 'originate failure should not accept the incident');
assert_same(1, $callFailSummary['alert_call_failed'], 'failed call alerts should be counted as failed');
$failedSameStageRows = count_history($callDbFail, "incident_id = {$callIncidentFail} AND action_type = 'alert_call' AND event_type = 'initial' AND stage_n = 0");
$failedCallAttemptsBefore = count($callSender->calls);
$reminderClock->now = '2026-07-13 10:06:00';
$callProcessorFail->run(settings());
assert_same($failedSameStageRows, count_history($callDbFail, "incident_id = {$callIncidentFail} AND action_type = 'alert_call' AND event_type = 'initial' AND stage_n = 0"), 'failed originate outcomes must not retry the same alert_call stage');
assert_same($failedCallAttemptsBefore, count($callSender->calls), 'failed originate outcomes must not trigger a same-stage call retry while repeat mode is never');

$disabledCallClock = new TestClock('2026-07-13 10:10:00');
[$disabledCallDb, $disabledCallProcessor] = create_alert_environment($disabledCallClock, new FakeEmailSender(), new FakeCallSender());
$disabledCallRule = insert_rule($disabledCallDb, [
	'name' => 'Disabled Call Rule',
	'email_enabled' => 0,
	'alert_call_enabled' => 0,
	'alert_call_destinations' => '200',
	'alert_call_recording_id' => 88,
	'repeat_mode_override' => 'never',
]);
$disabledCallIncident = insert_incident($disabledCallDb, [
	'rule_id' => $disabledCallRule,
	'subject_key' => '+441234500005',
	'subject_label' => '+441234500005',
	'first_matched_at' => '2026-07-13 10:10:00',
	'suppression_expires_at' => '2026-07-13 11:10:00',
]);
$disabledCallProcessor->run(settings());
assert_same(0, count_history($disabledCallDb, "incident_id = {$disabledCallIncident} AND action_type = 'alert_call'"), 'disabled call alerts should not create alert_call history entries');
$incidentFail = insert_incident($db, [
	'rule_id' => $ruleEmail,
	'subject_key' => '+441234500003',
	'subject_label' => '+441234500003',
	'first_matched_at' => '2026-07-13 10:01:00',
	'suppression_expires_at' => '2026-07-13 11:01:00',
]);
$clock->now = '2026-07-13 10:01:00';
$processor->run(settings());
assert_same(1, count_history($db, "incident_id = {$incidentFail} AND action_type = 'gui'"), 'GUI event should remain persisted when email delivery fails');
$emailFailure = $db->query("SELECT delivery_status, failure_detail FROM repeatcaller_incident_alert_history WHERE incident_id = {$incidentFail} AND action_type = 'email' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
assert_same('failed', (string)$emailFailure['delivery_status'], 'email failure status must be recorded');
assert_true(strpos((string)$emailFailure['failure_detail'], 'smtp down') !== false, 'email failure detail should be persisted');

$clock->now = '2026-07-13 10:20:00';
$db->prepare('UPDATE repeatcaller_incidents SET last_matched_at = ?, matched_call_count = ? WHERE id = ?')->execute(['2026-07-13 10:20:00', 9, $incidentA]);
$processor->run(settings());
assert_same(1, count_history($db, "incident_id = {$incidentA} AND action_type = 'gui' AND event_type = 'initial'"), 'updating an active incident should not create another initial event');

// 8: every 5 minutes
$clock5 = new TestClock('2026-07-13 12:00:00');
$sender5 = new FakeEmailSender();
[$db5, $processor5] = create_alert_environment($clock5, $sender5);
$rule5 = insert_rule($db5, ['email_enabled' => 0, 'repeat_mode_override' => '5m']);
$incident5 = insert_incident($db5, [
	'rule_id' => $rule5,
	'subject_key' => '+441200000005',
	'first_matched_at' => '2026-07-13 12:00:00',
	'suppression_expires_at' => '2026-07-13 13:00:00',
]);
$processor5->run(settings());
$clock5->now = '2026-07-13 12:04:59';
$processor5->run(settings());
assert_same(0, count_history($db5, "incident_id = {$incident5} AND action_type = 'gui' AND event_type = 'reminder'"), '5m mode should not emit reminder before five minutes');
$clock5->now = '2026-07-13 12:05:00';
$processor5->run(settings());
assert_same(1, count_history($db5, "incident_id = {$incident5} AND action_type = 'gui' AND event_type = 'reminder' AND stage_n = 1"), '5m mode should emit one reminder at five-minute eligibility');

// 9: hourly
$clockH = new TestClock('2026-07-13 14:00:00');
$senderH = new FakeEmailSender();
[$dbH, $processorH] = create_alert_environment($clockH, $senderH);
$ruleH = insert_rule($dbH, ['email_enabled' => 0, 'repeat_mode_override' => 'hourly']);
$incidentH = insert_incident($dbH, [
	'rule_id' => $ruleH,
	'subject_key' => '+441200000006',
	'first_matched_at' => '2026-07-13 14:00:00',
	'suppression_expires_at' => '2026-07-13 16:00:00',
]);
$processorH->run(settings());
$clockH->now = '2026-07-13 14:59:59';
$processorH->run(settings());
assert_same(0, count_history($dbH, "incident_id = {$incidentH} AND action_type = 'gui' AND event_type = 'reminder'"), 'hourly mode should not alert before one hour');
$clockH->now = '2026-07-13 15:00:00';
$processorH->run(settings());
assert_same(1, count_history($dbH, "incident_id = {$incidentH} AND action_type = 'gui' AND event_type = 'reminder' AND stage_n = 1"), 'hourly mode should alert at one hour');

// 10: daily
$clockD = new TestClock('2026-07-13 00:00:00');
$senderD = new FakeEmailSender();
[$dbD, $processorD] = create_alert_environment($clockD, $senderD);
$ruleD = insert_rule($dbD, ['email_enabled' => 0, 'repeat_mode_override' => 'daily']);
$incidentD = insert_incident($dbD, [
	'rule_id' => $ruleD,
	'subject_key' => '+441200000007',
	'first_matched_at' => '2026-07-13 00:00:00',
	'suppression_expires_at' => '2026-07-15 00:00:00',
]);
$processorD->run(settings());
$clockD->now = '2026-07-13 23:59:59';
$processorD->run(settings());
assert_same(0, count_history($dbD, "incident_id = {$incidentD} AND action_type = 'gui' AND event_type = 'reminder'"), 'daily mode should not alert before one day');
$clockD->now = '2026-07-14 00:00:00';
$processorD->run(settings());
assert_same(1, count_history($dbD, "incident_id = {$incidentD} AND action_type = 'gui' AND event_type = 'reminder' AND stage_n = 1"), 'daily mode should alert at one day');

// 11: escalating sequence (5m, 5m, 10m)
$clockE = new TestClock('2026-07-13 08:00:00');
$senderE = new FakeEmailSender();
[$dbE, $processorE] = create_alert_environment($clockE, $senderE);
$ruleE = insert_rule($dbE, ['email_enabled' => 0, 'repeat_mode_override' => 'escalating']);
$incidentE = insert_incident($dbE, [
	'rule_id' => $ruleE,
	'subject_key' => '+441200000008',
	'first_matched_at' => '2026-07-13 08:00:00',
	'suppression_expires_at' => '2026-07-13 12:00:00',
]);
$processorE->run(settings());
$clockE->now = '2026-07-13 08:04:59';
$processorE->run(settings());
assert_same(0, count_history($dbE, "incident_id = {$incidentE} AND action_type = 'gui' AND event_type = 'reminder'"), 'escalating should not alert before first 5m interval');
$clockE->now = '2026-07-13 08:05:00';
$processorE->run(settings());
$clockE->now = '2026-07-13 08:09:59';
$processorE->run(settings());
$clockE->now = '2026-07-13 08:10:00';
$processorE->run(settings());
$clockE->now = '2026-07-13 08:19:59';
$processorE->run(settings());
assert_same(2, count_history($dbE, "incident_id = {$incidentE} AND action_type = 'gui' AND event_type = 'reminder'"), 'escalating should emit two reminders by 10 minutes (5m + 5m)');
$clockE->now = '2026-07-13 08:20:00';
$processorE->run(settings());
assert_same(3, count_history($dbE, "incident_id = {$incidentE} AND action_type = 'gui' AND event_type = 'reminder'"), 'escalating third reminder should occur after the next 10-minute interval');

// 11b: shared stage scheduler across GUI/email/alert_call transports
$clockShared = new TestClock('2026-07-13 16:00:00');
$sharedEmailSender = new FakeEmailSender();
$sharedCallSender = new FakeCallSender();
[$dbShared, $processorShared] = create_alert_environment($clockShared, $sharedEmailSender, $sharedCallSender);
$ruleShared = insert_rule($dbShared, [
	'name' => 'Shared Transport Scheduler',
	'email_enabled' => 1,
	'alert_call_enabled' => 1,
	'alert_call_destinations' => '910',
	'alert_call_recording_id' => 66,
	'repeat_mode_override' => '5m',
]);
$incidentShared = insert_incident($dbShared, [
	'rule_id' => $ruleShared,
	'subject_key' => '+441200000801',
	'first_matched_at' => '2026-07-13 16:00:00',
	'suppression_expires_at' => '2026-07-13 17:00:00',
]);
$processorShared->run(settings());
assert_same(1, count_history($dbShared, "incident_id = {$incidentShared} AND action_type = 'gui' AND event_type = 'initial' AND stage_n = 0"), 'initial stage should reserve GUI history from the shared stage path');
assert_same(1, count_history($dbShared, "incident_id = {$incidentShared} AND action_type = 'email' AND event_type = 'initial' AND stage_n = 0"), 'initial stage should reserve email history from the shared stage path');
assert_same(1, count_history($dbShared, "incident_id = {$incidentShared} AND action_type = 'alert_call' AND event_type = 'initial' AND stage_n = 0"), 'initial stage should reserve alert_call history from the shared stage path');
$clockShared->now = '2026-07-13 16:05:00';
$processorShared->run(settings());
assert_same(1, count_history($dbShared, "incident_id = {$incidentShared} AND action_type = 'gui' AND event_type = 'reminder' AND stage_n = 1"), 'reminder stage should reserve GUI history from the same shared scheduler');
assert_same(1, count_history($dbShared, "incident_id = {$incidentShared} AND action_type = 'email' AND event_type = 'reminder' AND stage_n = 1"), 'reminder stage should reserve email history from the same shared scheduler');
assert_same(1, count_history($dbShared, "incident_id = {$incidentShared} AND action_type = 'alert_call' AND event_type = 'reminder' AND stage_n = 1"), 'reminder stage should reserve alert_call history from the same shared scheduler');

// 11c: legacy fibonacci override must remain equivalent to escalating
$clockFib = new TestClock('2026-07-13 17:00:00');
[$dbFib, $processorFib] = create_alert_environment($clockFib, new FakeEmailSender(), new FakeCallSender());
$ruleFib = insert_rule($dbFib, ['email_enabled' => 0, 'repeat_mode_override' => 'fibonacci']);
$incidentFib = insert_incident($dbFib, [
	'rule_id' => $ruleFib,
	'subject_key' => '+441200000802',
	'first_matched_at' => '2026-07-13 17:00:00',
	'suppression_expires_at' => '2026-07-13 19:00:00',
]);
$processorFib->run(settings());
$clockFib->now = '2026-07-13 17:04:59';
$processorFib->run(settings());
assert_same(0, count_history($dbFib, "incident_id = {$incidentFib} AND action_type = 'gui' AND event_type = 'reminder'"), 'legacy fibonacci mode should not alert before first 5m interval');
$clockFib->now = '2026-07-13 17:05:00';
$processorFib->run(settings());
$clockFib->now = '2026-07-13 17:10:00';
$processorFib->run(settings());
assert_same(2, count_history($dbFib, "incident_id = {$incidentFib} AND action_type = 'gui' AND event_type = 'reminder'"), 'legacy fibonacci mode should follow escalating cadence (5m then 5m)');

// 12 and 13: per-rule override vs default never fallback
$clockG = new TestClock('2026-07-13 09:00:00');
$senderG = new FakeEmailSender();
[$dbG, $processorG] = create_alert_environment($clockG, $senderG);
$ruleOverride = insert_rule($dbG, ['email_enabled' => 0, 'repeat_mode_override' => '5m']);
$ruleGlobal = insert_rule($dbG, ['email_enabled' => 0, 'repeat_mode_override' => null]);
$incidentOverride = insert_incident($dbG, [
	'rule_id' => $ruleOverride,
	'subject_key' => '+441200000009',
	'first_matched_at' => '2026-07-13 09:00:00',
	'suppression_expires_at' => '2026-07-13 11:00:00',
]);
$incidentGlobal = insert_incident($dbG, [
	'rule_id' => $ruleGlobal,
	'subject_key' => '+441200000010',
	'first_matched_at' => '2026-07-13 09:00:00',
	'suppression_expires_at' => '2026-07-13 11:00:00',
]);
$processorG->run(settings());
$clockG->now = '2026-07-13 09:05:00';
$processorG->run(settings());
assert_same(1, count_history($dbG, "incident_id = {$incidentOverride} AND action_type = 'gui' AND event_type = 'reminder'"), 'rule override should apply independently');
assert_same(0, count_history($dbG, "incident_id = {$incidentGlobal} AND action_type = 'gui' AND event_type = 'reminder'"), 'rule without override should use default never mode');
$clockG->now = '2026-07-13 10:00:00';
$processorG->run(settings());
assert_same(0, count_history($dbG, "incident_id = {$incidentGlobal} AND action_type = 'gui' AND event_type = 'reminder' AND stage_n = 1"), 'default never mode should not trigger reminders for unset overrides');

// 14, 15, 16: accepted/suppressed incident eligibility
$clockCS = new TestClock('2026-07-13 10:05:00');
$senderCS = new FakeEmailSender();
$callSenderCS = new FakeCallSender();
[$dbCS, $processorCS] = create_alert_environment($clockCS, $senderCS, $callSenderCS);
$ruleCS = insert_rule($dbCS, ['email_enabled' => 1, 'alert_call_enabled' => 1, 'alert_call_destinations' => '301', 'alert_call_recording_id' => 55, 'repeat_mode_override' => '5m']);
$acceptedNoNewIncident = insert_incident($dbCS, [
	'rule_id' => $ruleCS,
	'subject_key' => 'accepted-no-new-subject',
	'first_matched_at' => '2026-07-13 10:00:00',
	'last_matched_at' => '2026-07-13 10:00:00',
	'state' => 'accepted',
	'suppression_expires_at' => '2026-07-13 11:00:00',
]);
$dbCS->prepare(
	'INSERT INTO repeatcaller_incident_alert_state
		(incident_id, rule_id, repeat_mode, initial_sent_at, last_alert_at, reminders_sent, next_due_at, created_at, updated_at)
	 VALUES
		(?, ?, ?, ?, ?, ?, ?, ?, ?)'
)->execute([
	$acceptedNoNewIncident,
	$ruleCS,
	'5m',
	'2026-07-13 10:00:00',
	'2026-07-13 10:00:00',
	0,
	'2026-07-13 10:05:00',
	'2026-07-13 10:00:00',
	'2026-07-13 10:00:00',
]);

$acceptedWithNewIncident = insert_incident($dbCS, [
	'rule_id' => $ruleCS,
	'subject_key' => 'accepted-with-new-subject',
	'first_matched_at' => '2026-07-13 10:00:00',
	'last_matched_at' => '2026-07-13 10:03:00',
	'matched_call_count' => 3,
	'state' => 'accepted',
	'suppression_expires_at' => '2026-07-13 11:00:00',
]);
$dbCS->prepare(
	'INSERT INTO repeatcaller_incident_alert_state
		(incident_id, rule_id, repeat_mode, initial_sent_at, last_alert_at, reminders_sent, next_due_at, created_at, updated_at)
	 VALUES
		(?, ?, ?, ?, ?, ?, ?, ?, ?)'
)->execute([
	$acceptedWithNewIncident,
	$ruleCS,
	'5m',
	'2026-07-13 10:00:00',
	'2026-07-13 10:01:00',
	0,
	null,
	'2026-07-13 10:00:00',
	'2026-07-13 10:01:00',
]);
$suppressedIncident = insert_incident($dbCS, [
	'rule_id' => $ruleCS,
	'subject_key' => 'suppressed-subject',
	'first_matched_at' => '2026-07-13 10:00:00',
	'last_matched_at' => '2026-07-13 10:03:00',
	'state' => 'suppressed',
	'suppression_expires_at' => '2026-07-13 11:00:00',
]);
$processorCS->run(settings());
assert_same(0, count_history($dbCS, "incident_id = {$acceptedNoNewIncident}"), 'accepted incidents with no new qualifying activity should not generate further alerts');
assert_same(0, count_history($dbCS, "incident_id = {$acceptedWithNewIncident} AND action_type = 'gui' AND event_type = 'reminder'"), 'accepted incidents with new qualifying activity should not reserve GUI reminders while suppression remains active');
assert_same(0, count_history($dbCS, "incident_id = {$acceptedWithNewIncident} AND action_type = 'email' AND event_type = 'reminder'"), 'accepted incidents with new qualifying activity should not reserve email reminders while suppression remains active');
assert_same(0, count_history($dbCS, "incident_id = {$acceptedWithNewIncident} AND action_type = 'alert_call' AND event_type = 'reminder'"), 'accepted incidents with new qualifying activity should not reserve alert_call reminders while suppression remains active');
assert_same(0, count($senderCS->calls), 'accepted incidents with new qualifying activity should not send email while suppression remains active');
assert_same(0, count($callSenderCS->calls), 'accepted incidents with new qualifying activity should not send alert calls while suppression remains active');

$acceptedWithNewState = $dbCS->query("SELECT last_alert_at, reminders_sent FROM repeatcaller_incident_alert_state WHERE incident_id = {$acceptedWithNewIncident}")->fetch(PDO::FETCH_ASSOC);
assert_same('2026-07-13 10:01:00', (string)$acceptedWithNewState['last_alert_at'], 'accepted incidents with new qualifying activity should not advance the last-alert checkpoint while suppression remains active');
assert_same(0, (int)$acceptedWithNewState['reminders_sent'], 'accepted incidents with new qualifying activity should not advance the reminder counter while suppression remains active');
assert_same(0, count_history($dbCS, "incident_id = {$suppressedIncident}"), 'suppressed incidents should not generate further alerts');

$acceptedAfterExpiry = insert_incident($dbCS, [
	'rule_id' => $ruleCS,
	'subject_key' => 'accepted-after-expiry',
	'first_matched_at' => '2026-07-13 10:00:00',
	'last_matched_at' => '2026-07-13 11:05:00',
	'matched_call_count' => 4,
	'state' => 'accepted',
	'suppression_expires_at' => '2026-07-13 11:00:00',
]);
$dbCS->prepare(
	'INSERT INTO repeatcaller_incident_alert_state
		(incident_id, rule_id, repeat_mode, initial_sent_at, last_alert_at, reminders_sent, next_due_at, created_at, updated_at)
	 VALUES
		(?, ?, ?, ?, ?, ?, ?, ?, ?)'
)->execute([
	$acceptedAfterExpiry,
	$ruleCS,
	'5m',
	'2026-07-13 10:00:00',
	'2026-07-13 10:01:00',
	0,
	null,
	'2026-07-13 10:00:00',
	'2026-07-13 10:01:00',
]);
$clockCS->now = '2026-07-13 11:05:00';
$processorCS->run(settings());
$acceptedAfterExpiryState = $dbCS->query("SELECT last_alert_at, reminders_sent FROM repeatcaller_incident_alert_state WHERE incident_id = {$acceptedAfterExpiry}")->fetch(PDO::FETCH_ASSOC);
assert_same('2026-07-13 11:05:00', (string)$acceptedAfterExpiryState['last_alert_at'], 'accepted incidents should advance the checkpoint once suppression has expired and fresh activity becomes alert-eligible');
assert_same(1, (int)$acceptedAfterExpiryState['reminders_sent'], 'accepted incidents should advance the reminder counter once suppression has expired and fresh activity becomes alert-eligible');
assert_same(1, count_history($dbCS, "incident_id = {$acceptedAfterExpiry} AND action_type = 'gui' AND event_type = 'reminder' AND stage_n = 1"), 'accepted incidents should reserve one fresh GUI reminder after suppression expires');
assert_same(1, count_history($dbCS, "incident_id = {$acceptedAfterExpiry} AND action_type = 'email' AND event_type = 'reminder' AND stage_n = 1 AND delivery_status = 'sent'"), 'accepted incidents should deliver one fresh email reminder after suppression expires');
assert_same(0, count_history($dbCS, "incident_id = {$acceptedAfterExpiry} AND action_type = 'alert_call' AND event_type = 'reminder' AND stage_n = 1"), 'accepted incidents should not restart Alert Call activity after suppression expires');
assert_same(0, count_history($dbCS, "incident_id = {$acceptedAfterExpiry} AND event_type = 'reminder' AND stage_n = 2"), 'accepted incidents should not resume older reminder stages once suppression expires');

// 16 and 17: global snooze handling
$clockS = new TestClock('2026-07-13 11:00:00');
$senderS = new FakeEmailSender();
[$dbS, $processorS] = create_alert_environment($clockS, $senderS);
$ruleS = insert_rule($dbS, ['email_enabled' => 1, 'repeat_mode_override' => 'never']);
$incidentS = insert_incident($dbS, [
	'rule_id' => $ruleS,
	'subject_key' => 'snooze-subject',
	'first_matched_at' => '2026-07-13 11:00:00',
	'suppression_expires_at' => '2026-07-13 12:00:00',
]);
$processorS->run(settings(['global_snoozed_until' => '2026-07-13 11:30:00']));
assert_same(0, count($senderS->calls), 'global snooze should prevent delivery attempts while active');
assert_same(1, count_history($dbS, "incident_id = {$incidentS} AND action_type = 'email' AND delivery_status = 'snoozed'"), 'snoozed pass should keep a deferred email history record');
$clockS->now = '2026-07-13 11:31:00';
$processorS->run(settings());
assert_same(1, count($senderS->calls), 'after snooze expiry the still-eligible alert should be delivered');
assert_same(1, count_history($dbS, "incident_id = {$incidentS} AND action_type = 'email' AND delivery_status = 'sent'"), 'snoozed email should transition to sent after expiry');

// 18: duplicate prevention under two processors
$clockR = new TestClock('2026-07-13 13:00:00');
$senderR1 = new FakeEmailSender();
$senderR2 = new FakeEmailSender();
[$dbR, $processorR1] = create_alert_environment($clockR, $senderR1);
$repositoryR = new RepeatCallerRepository($dbR);
$processorR2 = new IncidentAlertProcessor($repositoryR, $senderR2, [$clockR, 'now']);
$ruleR = insert_rule($dbR, ['email_enabled' => 0, 'repeat_mode_override' => 'never']);
$incidentR = insert_incident($dbR, [
	'rule_id' => $ruleR,
	'subject_key' => 'race-subject',
	'first_matched_at' => '2026-07-13 13:00:00',
	'suppression_expires_at' => '2026-07-13 14:00:00',
]);
$processorR1->run(settings());
$processorR2->run(settings());
assert_same(1, count_history($dbR, "incident_id = {$incidentR} AND action_type = 'gui' AND event_type = 'initial'"), 'two processors must not persist the same alert stage twice');

// 20: pruning should delete old closed history without removing active dedupe state,
// and incident retention ("never" by default) must stay fully independent of
// alert-history retention: pruning alert history is never a back door for
// deleting incident rows or their alert state.
$clockP = new TestClock('2026-07-20 00:00:00');
$senderP = new FakeEmailSender();
[$dbP, $processorP] = create_alert_environment($clockP, $senderP);
$ruleP = insert_rule($dbP, ['email_enabled' => 0, 'repeat_mode_override' => 'never']);
$activeIncident = insert_incident($dbP, [
	'rule_id' => $ruleP,
	'subject_key' => 'active-prune',
	'first_matched_at' => '2026-07-10 00:00:00',
	'updated_at' => '2026-07-10 00:00:00',
	'suppression_expires_at' => '2026-07-21 00:00:00',
	'state' => 'active',
]);
$closedIncident = insert_incident($dbP, [
	'rule_id' => $ruleP,
	'subject_key' => 'closed-prune',
	'first_matched_at' => '2026-07-01 00:00:00',
	'updated_at' => '2026-07-01 00:00:00',
	'state' => 'closed',
]);
$acceptedIncident = insert_incident($dbP, [
	'rule_id' => $ruleP,
	'subject_key' => 'accepted-prune',
	'first_matched_at' => '2026-07-01 00:00:00',
	'updated_at' => '2026-07-01 00:00:00',
	'state' => 'accepted',
]);
$dbP->prepare(
	'INSERT INTO repeatcaller_incident_alert_state
		(incident_id, rule_id, repeat_mode, initial_sent_at, last_alert_at, reminders_sent, next_due_at, created_at, updated_at)
	 VALUES
		(?, ?, ?, ?, ?, ?, ?, ?, ?)'
)->execute([
	$acceptedIncident,
	$ruleP,
	'never',
	'2026-07-01 00:00:00',
	'2026-07-01 00:00:00',
	0,
	null,
	'2026-07-01 00:00:00',
	'2026-07-01 00:00:00',
]);
$dbP->prepare(
	'INSERT INTO repeatcaller_incident_alert_history
		(incident_id, rule_id, subject_key, subject_label, action_type, event_type, stage_n, recipient, delivery_status,
		 attempted_at, successful_at, next_retry_at, failure_detail, repeat_mode, dedupe_key, created_at, updated_at)
	 VALUES
		(?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
)->execute([
	$closedIncident,
	$ruleP,
	'closed-prune',
	'closed-prune',
	'gui',
	'initial',
	0,
	null,
	'recorded',
	'2026-07-01 00:00:00',
	'2026-07-01 00:00:00',
	null,
	null,
	'never',
	'closed-old-history',
	'2026-07-01 00:00:00',
	'2026-07-01 00:00:00',
]);
$dbP->prepare(
	'INSERT INTO repeatcaller_incident_alert_history
		(incident_id, rule_id, subject_key, subject_label, action_type, event_type, stage_n, recipient, delivery_status,
		 attempted_at, successful_at, next_retry_at, failure_detail, repeat_mode, dedupe_key, created_at, updated_at)
	 VALUES
		(?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
)->execute([
	$activeIncident,
	$ruleP,
	'active-prune',
	'active-prune',
	'gui',
	'initial',
	0,
	null,
	'recorded',
	'2026-07-10 00:00:00',
	'2026-07-10 00:00:00',
	null,
	null,
	'never',
	'active-old-history',
	'2026-07-10 00:00:00',
	'2026-07-10 00:00:00',
]);
$dbP->prepare(
	'INSERT INTO repeatcaller_incident_alert_state
		(incident_id, rule_id, repeat_mode, initial_sent_at, last_alert_at, reminders_sent, next_due_at, created_at, updated_at)
	 VALUES
		(?, ?, ?, ?, ?, ?, ?, ?, ?)'
)->execute([
	$closedIncident,
	$ruleP,
	'never',
	'2026-07-01 00:00:00',
	'2026-07-01 00:00:00',
	0,
	null,
	'2026-07-01 00:00:00',
	'2026-07-01 00:00:00',
]);
$processorP->run(settings(['alert_history_prune_policy' => 'daily']));
// Point 1: alert-history retention prunes old history for non-active incidents only.
assert_same(0, count_history($dbP, "incident_id = {$closedIncident}"), 'pruning should remove eligible old closed incident history');
assert_true(count_history($dbP, "incident_id = {$activeIncident}") >= 1, 'pruning must not remove active incident history required for dedupe/timing');
assert_same(1, (int)$dbP->query("SELECT COUNT(*) FROM repeatcaller_incident_alert_history WHERE dedupe_key = 'active-old-history'")->fetchColumn(), 'active dedupe history should survive pruning');
// Point 4: active incidents are never removed, regardless of policy.
assert_same(1, (int)$dbP->query("SELECT COUNT(*) FROM repeatcaller_incidents WHERE id = {$activeIncident}")->fetchColumn(), 'pruning must not delete active incidents');
// Points 1 (incident half) + 2: incident retention "never" (the default here, since
// incident_history_prune_policy was not overridden) must preserve the incident AND its
// alert state even though an unrelated alert_history_prune_policy just pruned its history.
assert_same(1, (int)$dbP->query("SELECT COUNT(*) FROM repeatcaller_incidents WHERE id = {$closedIncident}")->fetchColumn(), 'incident retention "never" must preserve the incident even after its alert history is pruned');
assert_same(1, (int)$dbP->query("SELECT COUNT(*) FROM repeatcaller_incident_alert_state WHERE incident_id = {$closedIncident}")->fetchColumn(), 'incident alert state must not be removed merely because its alert history was pruned');
// Point 5: accepted incidents are preserved under incident retention "never" -- not
// disposable merely because alerts stopped.
assert_same(1, (int)$dbP->query("SELECT COUNT(*) FROM repeatcaller_incidents WHERE id = {$acceptedIncident}")->fetchColumn(), 'accepted incidents must be preserved under incident retention never');
assert_same(1, (int)$dbP->query("SELECT COUNT(*) FROM repeatcaller_incident_alert_state WHERE incident_id = {$acceptedIncident}")->fetchColumn(), 'accepted incident alert state must be preserved under incident retention never');

$initialEventsAfterFirstRun = (int)$dbP->query("SELECT COUNT(*) FROM repeatcaller_incident_alert_history WHERE incident_id = {$activeIncident} AND event_type = 'initial'")->fetchColumn();

// Point 6: an invalid incident-retention value must normalise to "never" (preserve
// data), not be silently treated as an aggressive policy.
$processorP->run(settings(['alert_history_prune_policy' => 'daily', 'incident_history_prune_policy' => 'not-a-real-policy']));
assert_same(1, (int)$dbP->query("SELECT COUNT(*) FROM repeatcaller_incidents WHERE id = {$closedIncident}")->fetchColumn(), 'an invalid incident-retention value must preserve incidents, not delete them');
assert_same(1, (int)$dbP->query("SELECT COUNT(*) FROM repeatcaller_incident_alert_state WHERE incident_id = {$closedIncident}")->fetchColumn(), 'an invalid incident-retention value must preserve incident alert state');

// Point 3: once an explicit, non-"never" incident-retention policy is set, the closed
// incident (already orphaned of alert history above) becomes eligible and is removed.
$processorP->run(settings(['alert_history_prune_policy' => 'daily', 'incident_history_prune_policy' => 'daily']));
assert_same(0, (int)$dbP->query("SELECT COUNT(*) FROM repeatcaller_incidents WHERE id = {$closedIncident}")->fetchColumn(), 'an explicit incident-retention policy must remove eligible closed incidents');
assert_same(0, (int)$dbP->query("SELECT COUNT(*) FROM repeatcaller_incident_alert_state WHERE incident_id = {$closedIncident}")->fetchColumn(), 'incident alert state should be cleaned up alongside its incident once explicitly eligible');
assert_same(1, (int)$dbP->query("SELECT COUNT(*) FROM repeatcaller_incidents WHERE id = {$activeIncident}")->fetchColumn(), 'active incidents must never be removed regardless of incident-retention policy');

// Point 8: none of the pruning passes above may cause the active incident's already-sent
// "initial" stage to be re-emitted -- alert_state, not history-row survival, is the
// dedupe source of truth.
$initialEventsAfterPruning = (int)$dbP->query("SELECT COUNT(*) FROM repeatcaller_incident_alert_history WHERE incident_id = {$activeIncident} AND event_type = 'initial'")->fetchColumn();
assert_same($initialEventsAfterFirstRun, $initialEventsAfterPruning, 'alert-history pruning must never cause a still-retained active incident to re-emit an already-sent stage');

// 7: never sends no reminders (explicit check with long time jump)
$clockN = new TestClock('2026-07-13 06:00:00');
$senderN = new FakeEmailSender();
[$dbN, $processorN] = create_alert_environment($clockN, $senderN);
$ruleN = insert_rule($dbN, ['email_enabled' => 0, 'repeat_mode_override' => 'never']);
$incidentN = insert_incident($dbN, [
	'rule_id' => $ruleN,
	'subject_key' => 'never-subject',
	'first_matched_at' => '2026-07-13 06:00:00',
	'suppression_expires_at' => '2026-07-14 06:00:00',
]);
$processorN->run(settings());
$clockN->now = '2026-07-14 06:00:00';
$processorN->run(settings());
assert_same(0, count_history($dbN, "incident_id = {$incidentN} AND action_type = 'gui' AND event_type = 'reminder'"), 'never mode should not create reminders');

$alertSource = file_get_contents(__DIR__ . '/../src/IncidentAlertProcessor.php');
assert_true($alertSource !== false, 'IncidentAlertProcessor source should be readable for formatter path checks');
assert_true(strpos($alertSource, 'private function formatAlertTextLabel(string $value, array $labels, string $defaultLabel): string {') !== false, 'email mode and repeat labels should share one central formatter helper');
assert_true(strpos($alertSource, 'Alert Reminder: ') !== false && strpos($alertSource, '$this->formatRepeatModeLabel(') !== false, 'email builder should render a single customer-facing Alert Reminder line using the effective repeat mode');

echo "repeat alerting contract tests passed\n";

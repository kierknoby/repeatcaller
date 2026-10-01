<?php

declare(strict_types=1);

// Focused contract for the Test Email feature (rcHandleTestRuleEmail),
// adapted from Registration Watch's Test Email coverage. Exercises the rule
// lookup, recipient validation/normalisation, the real sendEmail() transport,
// the configured FreePBX System Identifier (with its fallback), wording, and
// sent/failed recipient counting.

if (!function_exists('_')) {
	function _(string $value): string {
		return $value;
	}
}

if (!interface_exists('BMO')) {
	interface BMO {}
}

if (!class_exists('FreePBX')) {
	class FreePBX {
		public static $settings = [];
		private static ?PDO $database = null;

		public static function setDatabase(PDO $database): void {
			self::$database = $database;
		}

		public static function Database(): PDO {
			if (!self::$database instanceof PDO) {
				throw new RuntimeException('Test FreePBX database is not configured.');
			}
			return self::$database;
		}

		public static function Config() {
			return new class {
				public function get($key) {
					return FreePBX::$settings[$key] ?? '';
				}
			};
		}
	}
}

// Controls per-call success/failure of the mail transport without a second
// mail implementation: CI_Email is the same class Repeatcaller::sendEmail()
// uses for real alert emails.
class CI_Email {
	public static $sendResults = [];
	public static $sent = [];
	private $to = '';
	private $subject = '';
	private $message = '';
	private $fromArgs = [];
	private $replyToArgs = [];

	public function from($address, $name, $returnPath = null) { $this->fromArgs = func_get_args(); }
	public function reply_to($address, $name) { $this->replyToArgs = func_get_args(); }
	public function to($value) { $this->to = $value; }
	public function subject($value) { $this->subject = $value; }
	public function set_mailtype($value) {}
	public function message($value) { $this->message = $value; }

	public function send() {
		self::$sent[] = ['to' => $this->to, 'subject' => $this->subject, 'message' => $this->message, 'from' => $this->fromArgs, 'reply_to' => $this->replyToArgs];
		if (self::$sendResults) {
			return array_shift(self::$sendResults);
		}
		return true;
	}
}

require_once __DIR__ . '/../src/RepeatCallerRepository.php';
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

function make_test_email_db(): PDO {
	$db = new PDO('sqlite::memory:');
	$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
	$db->exec('CREATE TABLE repeatcaller_rules (
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
		is_deleted INTEGER NOT NULL DEFAULT 0,
		deleted_at TEXT,
		mode TEXT NOT NULL,
		threshold_count INTEGER NOT NULL,
		observation_window_minutes INTEGER NOT NULL,
		caller_mode TEXT NOT NULL,
		exclude_withheld INTEGER NOT NULL DEFAULT 0,
		did_scope_mode TEXT NOT NULL,
		alert_reminder_mode_override TEXT,
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
	return $db;
}

function save_basic_rule(RepeatCallerRepository $repo, string $emailRecipients, string $now): int {
	return $repo->saveRule([
		'name' => 'Test Rule',
		'enabled' => 1,
		'email_enabled' => 1,
		'email_recipients' => $emailRecipients,
		'alert_call_enabled' => 0,
		'mode' => 'repeat',
		'threshold_count' => 2,
		'observation_window_minutes' => 60,
		'caller_mode' => 'any',
		'exclude_withheld' => 0,
		'did_scope_mode' => 'all',
		'alert_reminder_mode_override' => 'never',
		'suppression_minutes_override' => null,
		'schedules' => [],
		'callers' => [],
		'dids' => [],
	], $now);
}

function invoke_test_email(\FreePBX\modules\Repeatcaller $controller, int $ruleId): array {
	$_REQUEST = $ruleId > 0 ? ['rule_id' => (string)$ruleId] : [];
	$method = new ReflectionMethod($controller, 'rcHandleTestRuleEmail');
	$method->setAccessible(true);
	return $method->invoke($controller);
}

$now = '2026-10-01 12:00:00';

// Missing rule id.
FreePBX::$settings = [];
$db = make_test_email_db();
FreePBX::setDatabase($db);
$controller = new \FreePBX\modules\Repeatcaller(new stdClass());
$response = invoke_test_email($controller, 0);
assert_same(false, $response['status'], 'missing rule_id should fail');
assert_same('Missing rule ID.', $response['message'], 'missing rule_id message');

// Rule not found.
$response = invoke_test_email($controller, 999);
assert_same(false, $response['status'], 'unknown rule should fail');
assert_same('Rule not found or has been deleted.', $response['message'], 'unknown rule message');

// No valid recipients configured for the rule.
$repo = new RepeatCallerRepository($db);
$emptyRecipientsRuleId = save_basic_rule($repo, '', $now);
$response = invoke_test_email($controller, $emptyRecipientsRuleId);
assert_same(false, $response['status'], 'no recipients should fail');
assert_same('No valid email recipients are configured for this rule.', $response['message'], 'no recipients message');

// Successful send to two recipients, using the FreePBX System Identifier.
FreePBX::$settings = ['FREEPBX_SYSTEM_IDENT' => 'MY-PBX-NAME', 'AMPUSERMANEMAILFROM' => 'asterisk@demodomain.name'];
$recipientsRuleId = save_basic_rule($repo, 'a@example.org, b@example.org', $now);
CI_Email::$sendResults = [];
CI_Email::$sent = [];
$response = invoke_test_email($controller, $recipientsRuleId);
assert_same(true, $response['status'], 'test email should succeed when the local mailer accepts every recipient');
assert_same('Test email accepted by local mailer for 2 recipient(s). Delivery is not confirmed.', $response['message'], 'all-sent success wording');
assert_same(2, count(CI_Email::$sent), 'two recipients should each receive a message');
assert_same('Repeat Caller: test email', CI_Email::$sent[0]['subject'], 'subject should use Repeat Caller wording');
assert_true(strpos(CI_Email::$sent[0]['message'], 'Repeat Caller test email from MY-PBX-NAME') === 0, 'body should open with the configured system identifier');
assert_true((bool)preg_match('/Time: \d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}/', CI_Email::$sent[0]['message']), 'body should include the current time');
assert_true(strpos(CI_Email::$sent[0]['message'], 'Source: manual test') !== false, 'body should identify a manual test');
assert_same(['a@example.org', 'b@example.org'], array_column(CI_Email::$sent, 'to'), 'recipients should come from the rule email_recipients');

// Verify the resolved sender identity: Test Email must use the same
// configured FreePBX Email "From:" Address/display name resolution as the
// production alert path (see tests/repeat_email_from_contract.php).
assert_same(['asterisk@demodomain.name', 'Repeat Caller', 'asterisk@demodomain.name'], CI_Email::$sent[0]['from'], 'Test Email should apply the configured Email "From:" Address as the From identity, with the Repeat Caller fallback display name');
assert_same(['asterisk@demodomain.name', 'Repeat Caller'], CI_Email::$sent[0]['reply_to'], 'Test Email should set Reply-To to the same resolved From identity');

// System identifier fallback when FREEPBX_SYSTEM_IDENT is unavailable.
FreePBX::$settings['FREEPBX_SYSTEM_IDENT'] = '';
CI_Email::$sendResults = [];
CI_Email::$sent = [];
$response = invoke_test_email($controller, $recipientsRuleId);
assert_same(true, $response['status'], 'fallback identifier path should still succeed');
assert_true(strpos(CI_Email::$sent[0]['message'], 'Repeat Caller test email from unknown system') === 0, 'body should fall back to "unknown system" when the identifier is unavailable, matching Registration Watch');
FreePBX::$settings['FREEPBX_SYSTEM_IDENT'] = 'MY-PBX-NAME';

// Mixed success/failure across recipients.
CI_Email::$sendResults = [true, false];
CI_Email::$sent = [];
$response = invoke_test_email($controller, $recipientsRuleId);
assert_same(true, $response['status'], 'partial success should still be reported as accepted');
assert_same('Test email accepted by local mailer for 1 recipient(s); 1 failed. Delivery is not confirmed.', $response['message'], 'partial-failure wording should preserve sent/failed counts');

// Total failure across all recipients.
CI_Email::$sendResults = [false, false];
CI_Email::$sent = [];
$response = invoke_test_email($controller, $recipientsRuleId);
assert_same(false, $response['status'], 'total failure should be reported as a failure');
assert_same('Test email failed for all recipients.', $response['message'], 'total-failure wording');

// Invalid recipients stored on the rule are filtered out by the same
// normalisation/validation used elsewhere in Repeat Caller.
$invalidRecipientsRuleId = save_basic_rule($repo, 'not-an-email, also bad', $now);
$response = invoke_test_email($controller, $invalidRecipientsRuleId);
assert_same(false, $response['status'], 'invalid recipients should be treated the same as no recipients');
assert_same('No valid email recipients are configured for this rule.', $response['message'], 'invalid recipients message');

// AJAX routing: the command must be registered and CSRF-protected like every
// other state-reading/state-changing Repeat Caller AJAX command.
$controllerSource = file_get_contents(__DIR__ . '/../Repeatcaller.class.php');
assert_true(strpos($controllerSource, "'testruleemail',") !== false, 'testruleemail must be registered in AJAX_COMMANDS');
assert_true((bool)preg_match('/case \'testruleemail\':\s*return \$this->rcHandleTestRuleEmail\(\);/', $controllerSource), 'ajaxHandler must route testruleemail to rcHandleTestRuleEmail');
assert_true(strpos($controllerSource, 'if (!$this->validateCsrfToken())') !== false, 'ajaxHandler must continue validating CSRF before dispatching any command');

// Frontend: Test Email must belong to the rule editor, be disabled until
// valid saved recipients exist and the rule's email settings are not dirty,
// and must not be double-bound on repeated renders.
$viewSource = file_get_contents(__DIR__ . '/../views/main.php');
assert_true(strpos($viewSource, 'id="rc-rule-test-email"') !== false, 'Test Email button must exist in the rule editor');
assert_true((bool)preg_match('/id="rc-rule-email-recipients"[\s\S]{0,400}id="rc-rule-test-email" disabled/', $viewSource), 'Test Email button must sit with the rule Email Recipients control and start disabled');

$jsSource = file_get_contents(__DIR__ . '/../assets/js/repeatcaller.js');
assert_true(strpos($jsSource, "ajax('testruleemail'") !== false, 'frontend must call the testruleemail AJAX command');
assert_true(strpos($jsSource, "\$('#rc-rule-test-email').off('click.repeatcaller').on('click.repeatcaller'") !== false, 'Test Email click binding must be namespaced and re-bound via off().on() to prevent duplicate bindings');
assert_true(strpos($jsSource, 'function hasValidRuleEmailRecipients()') !== false, 'frontend must validate recipients using the existing rule email recipient validation');
assert_true(strpos($jsSource, 'function updateRuleTestEmailState()') !== false, 'frontend must centralise Test Email availability state');
assert_true((bool)preg_match('/ruleEditorDirty \|\| !hasValidRuleEmailRecipients\(\)/', $jsSource), 'Test Email must be unavailable while the rule editor is dirty or recipients are invalid/empty');

// Regression: withBusy() unconditionally re-enables the button on done(),
// which would race a mid-flight edit that set ruleEditorDirty = true. The
// completion callback must re-apply updateRuleTestEmailState() after done()
// so a dirty edit made during the request keeps the button disabled.
assert_true((bool)preg_match('/ajax\(\'testruleemail\',\s*\{\s*rule_id:[\s\S]*?\},\s*function \(response\) \{[\s\S]*?\}, function \(\) \{\s*done\(\);\s*updateRuleTestEmailState\(\);\s*endAction\(\);\s*\}\);/', $jsSource), 'Test Email completion callback must call updateRuleTestEmailState() after done() and before endAction(), so a mid-flight dirty edit is not overridden by withBusy() re-enabling the button');

// Behavioural coverage of each custom mutation path lives in tests/repeat_admin_contract.php.
assert_true((bool)preg_match('/function markRuleEditorDirty\(\) \{\s*ruleEditorDirty = true;\s*updateRuleTestEmailState\(\);\s*\}/', $jsSource), 'markRuleEditorDirty() must set the dirty flag and re-apply Test Email availability');
assert_true((bool)preg_match('/\$\(\'\.rc-editor-panel\'\)\.off\(\'change\.repeatcaller-dirty input\.repeatcaller-dirty\'\)\.on\(\'change\.repeatcaller-dirty input\.repeatcaller-dirty\', function \(\) \{\s*markRuleEditorDirty\(\);\s*\}\);/', $jsSource), 'delegated input/change handler must use markRuleEditorDirty()');
assert_true(substr_count($jsSource, 'markRuleEditorDirty();') >= 8, 'every custom rule-editor mutation path must call markRuleEditorDirty()');
assert_true((bool)preg_match('/function loadRule\(id\) \{[\s\S]*?ruleEditorDirty = false;\s*updateRuleTestEmailState\(\);\s*scrollToRuleEditor\(\);/', $jsSource), 'loadRule() must leave the editor clean after rendering saved values');

echo "Test Email contract tests passed.\n";

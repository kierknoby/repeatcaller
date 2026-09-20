<?php

declare(strict_types=1);

interface BMO {}
class FreePBX {
	public static $settings = [];
	public static function Config() {
		return new class {
			public function get($key) { return FreePBX::$settings[$key] ?? ''; }
		};
	}
}

// Separate processes allow both incompatible CI_Email signatures to be tested.
class EmailCapture {
	public static $last;
	public $calls = [];
	public function __construct() { self::$last = $this; }
	public function set_header($key, $value) { $this->calls[$key] = $value; }
	public function reply_to($address, $name) { $this->calls['reply_to'] = [$address, $name]; }
	public function to($value) { $this->calls['to'] = $value; }
	public function subject($value) { $this->calls['subject'] = $value; }
	public function set_mailtype($value) { $this->calls['mailtype'] = $value; }
	public function message($value) { $this->calls['message'] = $value; }
	public function send() { return true; }
}
$legacy = ($argv[1] ?? '') === 'legacy';
if ($legacy) {
	class CI_Email extends EmailCapture {
		public function from($address, $name) { $this->calls['from'] = func_get_args(); }
	}
} else {
	class CI_Email extends EmailCapture {
		public function from($address, $name, $returnPath = null) { $this->calls['from'] = func_get_args(); }
	}
}
require_once __DIR__ . '/../Repeatcaller.class.php';
use FreePBX\modules\Repeatcaller;
function same($expected, $actual, string $label): void {
	if ($expected !== $actual) {
		throw new RuntimeException($label . ': ' . var_export($actual, true));
	}
}
$module = (new ReflectionClass(Repeatcaller::class))->newInstanceWithoutConstructor();
$resolve = new ReflectionMethod($module, 'getNotificationSenderIdentity');
$resolve->setAccessible(true);
$valid = [
	["\tasterisk@demodomain.name\t", 'Brand', 'asterisk@demodomain.name', 'Brand'],
	['asterisk@demodomain.name', ' Brand ', 'asterisk@demodomain.name', 'Brand'],
	['<asterisk@demodomain.name>', 'Brand', 'asterisk@demodomain.name', 'Brand'],
	['PBX <asterisk@demodomain.name>', 'Brand', 'asterisk@demodomain.name', 'PBX'],
	['PBX London <asterisk@demodomain.name>', 'Brand', 'asterisk@demodomain.name', 'PBX London'],
	['PBX-123 <asterisk@demodomain.name>', 'Brand', 'asterisk@demodomain.name', 'PBX-123'],
	['  JaCoTec TK-System <pbx@mydomain.de>  ', 'Brand', 'pbx@mydomain.de', 'JaCoTec TK-System'],
	['PBXSRV28-LON &lt;asterisk@freepbx.uk&gt;', 'Brand', 'asterisk@freepbx.uk', 'PBXSRV28-LON'],
	['asterisk@demodomain.name', '', 'asterisk@demodomain.name', 'Repeat Caller'],
	['asterisk@demodomain.name', '   ', 'asterisk@demodomain.name', 'Repeat Caller'],
	['asterisk@demodomain.name', "Brand\rInjected", 'asterisk@demodomain.name', 'Repeat Caller'],
	['asterisk@demodomain.name', "Brand\n", 'asterisk@demodomain.name', 'Repeat Caller'],
];
foreach ($valid as [$setting, $brand, $address, $name]) {
	FreePBX::$settings = ['AMPUSERMANEMAILFROM' => $setting, 'DASHBOARD_FREEPBX_BRAND' => $brand];
	same(['address' => $address, 'name' => $name], $resolve->invoke($module), $setting);
	same(true, $module->sendEmail('recipient@example.org', 'Subject', 'Body')['status'], 'send');
	$calls = EmailCapture::$last->calls;
	same($legacy ? [$address, $name] : [$address, $name, $address], $calls['from'], 'From identity');
	same([$address, $name], $calls['reply_to'], 'Reply-To identity');
	same($address, $legacy ? $calls['Return-Path'] : $calls['from'][2], 'Return-Path');
	same('recipient@example.org', $calls['to'], 'recipient');
	same('Subject', $calls['subject'], 'subject');
	same('Body', $calls['message'], 'body');
	same('text', $calls['mailtype'], 'mail type');
}
foreach ([
	'', ' ', 'invalid', '<>', 'Name < >', '<invalid>', 'Name <a@example.org',
	'Name a@example.org>', 'Name <<a@example.org>>', 'Name <a@example.org>>',
	'Name <a@example.org><b@example.org>', 'Name <a@example.org> trailing',
	"Name\r\nBcc: victim@example.org <a@example.org>", "a@example.org\n", "\ra@example.org",
	'Name&#13;&#10;Bcc: victim@example.org &lt;a@example.org&gt;',
	'Name&#x0a; &lt;a@example.org&gt;', 'a@example.org&NewLine;',
	"Name\0 <a@example.org>",
] as $setting) {
	FreePBX::$settings['AMPUSERMANEMAILFROM'] = $setting;
	same('', $resolve->invoke($module)['address'], 'invalid sender ' . $setting);
	EmailCapture::$last = null;
	same(['status' => false, 'message' => 'Email "From:" Address is not configured in Advanced Settings.'], $module->sendEmail('recipient@example.org', 'Subject', 'Body'), 'invalid send');
	same(null, EmailCapture::$last, 'invalid sender must not construct email');
}
$recipients = new ReflectionMethod($module, 'normaliseRecipients');
$recipients->setAccessible(true);
foreach ([
	['A@example.org;a@example.org, b@example.org c@example.org', ['a@example.org', 'b@example.org', 'c@example.org']],
	['Name <a@example.org> invalid', ['a@example.org']],
	['Name &lt;a@example.org&gt;', []],
	['<a@example.org>tail <<b@example.org>>', ['a@example.org']],
	["a@example.org\r\nb@example.org", ['a@example.org', 'b@example.org']],
] as [$input, $expected]) {
	same($expected, $recipients->invoke($module, $input), 'unchanged recipient parsing');
}
echo 'repeat_email_from_contract: PASS (' . ($legacy ? 'legacy' : 'modern') . ")\n";
if (!$legacy) {
	passthru(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' legacy', $status);
	if ($status !== 0) { exit($status); }
}

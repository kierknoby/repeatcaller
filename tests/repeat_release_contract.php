<?php

declare(strict_types=1);

// Release-completion contract: structural/behavioral parity checks that
// complement (not replace) the runtime suites. Focus is on invariants that
// large file rewrites are most likely to silently break: AJAX allowlist vs
// dispatcher vs frontend parity, fresh-install/uninstall table coverage,
// absence of legacy Registration Watch SQL in active code, and settings
// allowlisting. Behavioral checks are used wherever they can be exercised
// without relying on MySQL-only SQL syntax (e.g. ON DUPLICATE KEY UPDATE)
// that SQLite cannot execute; those specific paths are documented as
// requiring real MySQL/MariaDB validation instead.

if (!interface_exists('BMO')) {
	interface BMO {}
}

if (!class_exists('FreePBX')) {
	class FreePBX {
		public static $database;

		public static function Database() {
			return self::$database;
		}

		public static function Log() {
			return new class {
				public function error($message): void {}
				public function warning($message): void {}
				public function info($message): void {}
			};
		}

		public static function Modules() {
			return new class {
				public function getInfo($name): array {
					return [];
				}
			};
		}
	}
}

require_once __DIR__ . '/../src/RepeatCallerRepository.php';
require_once __DIR__ . '/../src/IncidentAlertProcessor.php';
require_once __DIR__ . '/../src/Schema.php';
require_once __DIR__ . '/../Repeatcaller.class.php';

use FreePBX\modules\Repeatcaller;
use FreePBX\modules\Repeatcaller\IncidentAlertProcessor;
use FreePBX\modules\Repeatcaller\RepeatCallerRepository;
use FreePBX\modules\Repeatcaller\Schema as RepeatCallerSchema;

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

$root = dirname(__DIR__);

// --- Release version declarations ----------------------------------------

$moduleXml = simplexml_load_file($root . '/module.xml');
assert_true($moduleXml !== false, 'module.xml should parse');
assert_same('1.0.0', (string)$moduleXml->version, 'module.xml version must be 1.0.0 for this release');
assert_same('1.0.0', Repeatcaller::VERSION, 'Repeatcaller fallback VERSION constant must match module.xml for release 1.0.0');

// --- 1-3: AJAX allowlist, dispatcher, and frontend command parity --------

$module = new Repeatcaller(new stdClass());
$allowedCommands = Repeatcaller::AJAX_COMMANDS;
assert_true(count($allowedCommands) > 0, 'AJAX_COMMANDS allowlist must not be empty');

$dummySetting = null;
foreach ($allowedCommands as $command) {
	assert_true($module->ajaxRequest($command, $dummySetting), "allowlisted command '{$command}' should be accepted by ajaxRequest");
}
assert_true(!$module->ajaxRequest('registrationwatchstatus', $dummySetting), 'unknown/legacy command must not be accepted by ajaxRequest');
assert_true(!$module->ajaxRequest('saveuisetting', $dummySetting), 'commands absent from the allowlist must not be accepted, even if plausible-sounding');

$controllerSource = file_get_contents($root . '/Repeatcaller.class.php');
assert_true($controllerSource !== false, 'controller source should be readable');

$handlerMethod = new ReflectionMethod(Repeatcaller::class, 'ajaxHandler');
$controllerLines = explode("\n", $controllerSource);
$handlerBody = implode("\n", array_slice($controllerLines, $handlerMethod->getStartLine() - 1, $handlerMethod->getEndLine() - $handlerMethod->getStartLine() + 1));
preg_match_all('/case\s+\'([a-z_]+)\'\s*:/', $handlerBody, $matches);
$dispatchedCommands = $matches[1];
sort($dispatchedCommands);
$sortedAllowed = $allowedCommands;
sort($sortedAllowed);
assert_same($sortedAllowed, $dispatchedCommands, 'every allowlisted command must have exactly one dispatcher case, and no dispatcher case may exist outside the allowlist (no unreachable/orphaned commands)');

$jsSource = file_get_contents($root . '/assets/js/repeatcaller.js');
assert_true($jsSource !== false, 'frontend script should be readable');
preg_match_all('/ajax\(\'([a-z_]+)\'/', $jsSource, $jsMatches);
$jsCommands = array_values(array_unique($jsMatches[1]));
foreach ($jsCommands as $jsCommand) {
	assert_true(in_array($jsCommand, $allowedCommands, true), "frontend issues command '{$jsCommand}' which must exist in the backend allowlist");
}
assert_true(strpos($jsSource, "answered_no_response: 'Answered, No Response'") !== false, 'frontend should render canonical friendly label for answered_no_response');
assert_true(strpos($jsSource, "congestion: 'Congestion'") !== false, 'frontend should render canonical friendly label for congestion');
assert_true(strpos($jsSource, "failed: 'Failed'") !== false, 'frontend should render canonical friendly label for failed');

// --- 4-5: fresh install creates current tables only, no legacy tables ----

$requiredFreshTables = RepeatCallerSchema::REQUIRED_TABLES;
assert_true(count($requiredFreshTables) === 11, 'exactly 11 current tables are expected');
$obsoleteRegistrationTables = [
	'repeatcaller_alert_escalation',
	'repeatcaller_alert_history',
];

$createStatements = new ReflectionMethod(RepeatCallerSchema::class, 'createTableStatements');
$createStatements->setAccessible(true);
$statements = $createStatements->invoke(null);
$createdTables = [];
foreach ($statements as $statement) {
	assert_true((bool)preg_match('/CREATE TABLE IF NOT EXISTS (\w+)/', $statement, $stmtMatch), 'every schema statement must be a guarded CREATE TABLE IF NOT EXISTS');
	$createdTables[] = $stmtMatch[1];
}
sort($createdTables);
$sortedRequired = $requiredFreshTables;
sort($sortedRequired);
assert_same($sortedRequired, $createdTables, 'fresh install must create exactly the current v1 table set, no more, no less');

$schemaSource = file_get_contents($root . '/src/Schema.php');
assert_true($schemaSource !== false, 'src/Schema.php should be readable');
foreach ($obsoleteRegistrationTables as $legacyTable) {
	assert_true(strpos($schemaSource, $legacyTable) === false, "src/Schema.php must not create or reference obsolete registration table '{$legacyTable}'");
}
assert_true(strpos($schemaSource, "alert_call_strategy VARCHAR(20) NOT NULL DEFAULT 'ringall'") !== false, 'fresh schema must include alert_call_strategy with ringall default');
assert_true(strpos($schemaSource, 'alert_call_keep_trying TINYINT(1) NOT NULL DEFAULT 1') !== false, 'fresh schema must include alert_call_keep_trying enabled by default');

$installSource = file_get_contents($root . '/install.php');
assert_true($installSource !== false, 'install.php should be readable');
assert_true(strpos($installSource, 'Schema::install') !== false, 'install.php must delegate schema creation to the shared Schema class rather than duplicating SQL');
assert_true(strpos($installSource, 'global $db;') === false, 'install.php must not rely on the legacy global $db wrapper, which silently swallows SQL exceptions');
assert_true(strpos($installSource, '\FreePBX::Database()') !== false, 'install.php must use a real PDO connection via \FreePBX::Database()');

// --- 6: uninstall tolerates current tables and known inherited tables ----

$uninstallSource = file_get_contents($root . '/uninstall.php');
assert_true($uninstallSource !== false, 'uninstall.php should be readable');
assert_true(strpos($uninstallSource, 'Schema::REQUIRED_TABLES') !== false, 'uninstall.php must drop every current table via the shared Schema table list, not a hand-duplicated one');
foreach ($obsoleteRegistrationTables as $expectedDrop) {
	assert_true(strpos($uninstallSource, "'{$expectedDrop}'") !== false, "uninstall.php must tolerate dropping inherited table '{$expectedDrop}'");
}
assert_true(strpos($uninstallSource, "DROP TABLE IF EXISTS cdr") === false, 'uninstall must never touch the FreePBX cdr table');
assert_true(strpos($uninstallSource, "DROP TABLE IF EXISTS incoming") === false, 'uninstall must never touch the FreePBX incoming table');

// --- 7: no active production SQL references removed registration tables -

$activeProductionFiles = [
	'/Repeatcaller.class.php',
	'/Job.php',
	'/page.repeatcaller.php',
	'/views/main.php',
	'/assets/js/repeatcaller.js',
	'/src/DetectionEngine.php',
	'/src/RepeatCallerRepository.php',
	'/src/CdrScanner.php',
	'/src/BackgroundProcessor.php',
	'/src/IncidentAlertProcessor.php',
	'/src/Schema.php',
];
foreach ($activeProductionFiles as $relativePath) {
	$source = file_get_contents($root . $relativePath);
	assert_true($source !== false, "{$relativePath} should be readable");
	foreach ($obsoleteRegistrationTables as $legacyTable) {
		assert_true(strpos($source, $legacyTable) === false, "{$relativePath} must not reference removed registration table '{$legacyTable}'");
	}
}

$viewSource = file_get_contents($root . '/views/main.php');
assert_true($viewSource !== false, 'views/main.php should be readable');
assert_true(strpos($viewSource, 'id="rc-rule-alert-call-strategy"') !== false, 'rule editor must expose alert_call_strategy selector');
assert_true(strpos($viewSource, 'id="rc-rule-alert-call-keep-trying"') === false, 'rule editor should not expose deprecated global alert_call_keep_trying toggle');
assert_true(strpos($viewSource, 'id="rc-rule-alert-call-destination-list"') !== false, 'rule editor must expose ordered destination list UI');

$readmeSource = file_get_contents($root . '/README.md');
assert_true($readmeSource !== false, 'README should be readable');
assert_true(strpos($readmeSource, '# Repeat Caller 1.0.0 for FreePBX 16 and 17') !== false, 'README title should declare 1.0.0');
assert_true(strpos($readmeSource, '**Release date:** 22 July 2026') !== false, 'README should declare the 1.0.0 release date');
assert_true(strpos($readmeSource, 'Repeat Caller supports two distinct operating modes') !== false, 'README should describe the module in user-facing language');
assert_true(strpos($readmeSource, 'fwconsole ma installlocal repeatcaller') !== false, 'README must keep installlocal warning text');
assert_true(strpos($readmeSource, 'git reset --hard FETCH_HEAD') !== false, 'README must keep deterministic update sequence');
assert_true(strpos($readmeSource, 'Reports > Repeat Caller') !== false, 'README should include short getting-started UI path');
assert_true(strpos($readmeSource, 'USER_GUIDE.md') !== false, 'README should link to USER_GUIDE.md');
assert_true(strpos($readmeSource, 'TESTING.md') !== false, 'README should link to TESTING.md');
assert_true(strpos($readmeSource, '## Introduction') !== false, 'README should include Introduction section');
assert_true(strpos($readmeSource, '## Compatibility') !== false, 'README should include Compatibility section');
assert_true(strpos($readmeSource, '## Requirements') !== false, 'README should include Requirements section');
assert_true(strpos($readmeSource, '## Installing') !== false, 'README should include Installing section');
assert_true(strpos($readmeSource, '## Updating Repeat Caller') !== false, 'README should include Updating Repeat Caller section');
assert_true(strpos($readmeSource, '## Background Processing') !== false, 'README should include Background Processing section');
assert_true(strpos($readmeSource, '## Data Model') !== false, 'README should include Data Model section');
assert_true(strpos($readmeSource, '## Detection and Incident Behaviour') !== false, 'README should include Detection and Incident Behaviour section');
assert_true(strpos($readmeSource, '## Alerting') !== false, 'README should include Alerting section');
assert_true((bool) preg_match('/Repeat Caller provides incident visibility and two optional notification\s+methods:/', $readmeSource), 'README should use the updated alerts introduction');
assert_true(strpos($readmeSource, 'GUI incidents, which are always recorded') !== false, 'README should use GUI incidents wording');
assert_true(strpos($readmeSource, 'Email notifications, which are optional per rule') !== false, 'README should use email notifications wording');
assert_true(strpos($readmeSource, '## Repeat Alert Modes') !== false, 'README should document repeat alert modes');
assert_true(strpos($readmeSource, 'Initial alert only.') !== false, 'README should describe Never repeat mode as initial alert only');
assert_true(strpos($readmeSource, 'Repeats every 5 minutes while the incident remains active.') !== false, 'README should describe 5-minute repeat mode');
assert_true(strpos($readmeSource, 'Repeats every hour while the incident remains active.') !== false, 'README should describe hourly repeat mode');
assert_true(strpos($readmeSource, 'Repeats every 24 hours while the incident remains active.') !== false, 'README should describe daily repeat mode');
assert_true(strpos($readmeSource, 'Uses a Fibonacci-style escalating backoff schedule, starting with shorter') !== false && strpos($readmeSource, 'reminders and gradually increasing the interval up to daily.') !== false, 'README should describe the Escalating mode using the intended Fibonacci-style algorithm wording');
assert_true(strpos($readmeSource, '5 min, 5 min, 10 min, 15 min, 25 min, 40 min, 65 min, 105 min, …') !== false, 'README should list the Fibonacci escalation examples through 105 minutes');
assert_true(strpos($readmeSource, 'Capped at 24 hours once the interval reaches the daily ceiling.') !== false, 'README should describe the 24-hour ceiling');
assert_true(strpos($readmeSource, "- Never\n  - Initial alert only.\n- Every 5 minutes") !== false, 'README repeat mode bullets should use nested spaces for sub-bullets');
assert_true(strpos($readmeSource, "- Escalating\n  - Uses a Fibonacci-style escalating backoff schedule") !== false, 'README repeat mode bullets should keep nested spacing on escalating mode');
assert_true(strpos($readmeSource, "\n- Fibonacci\n") === false && strpos($readmeSource, "\n  - Fibonacci\n") === false, 'README must not present Fibonacci as a selectable repeat mode name');
assert_true(strpos($readmeSource, 'Stored legacy repeat mode values from earlier builds are treated as Escalating.') !== false, 'README should describe legacy repeat mode compatibility without exposing legacy operator-facing terminology');
assert_true(strpos($readmeSource, '## Suppression') !== false, 'README should include Suppression section');
assert_true(strpos($readmeSource, '## Data Retention') !== false, 'README should include Data Retention section');
assert_true(strpos($readmeSource, '## Snooze Monitoring') !== false, 'README should include Snooze Monitoring section');
assert_true(strpos($readmeSource, '## User Interface') !== false, 'README should include User Interface section');
assert_true(strpos($readmeSource, '## Security Model') !== false, 'README should include Security Model section');
assert_true(strpos($readmeSource, '## Current Limitations') !== false, 'README should include Current Limitations section');
assert_true(strpos($readmeSource, '## Validation') !== false, 'README should include Validation section');
assert_true(strpos($readmeSource, '## Uninstalling') !== false, 'README should include Uninstalling section');
assert_true(strpos($readmeSource, '## Licence') !== false, 'README should include Licence section');
assert_true(strpos($readmeSource, '## AI Disclosure') !== false, 'README should include AI Disclosure section');
assert_true(strpos($readmeSource, '## Author') !== false, 'README should include Author section');
assert_true(strpos($readmeSource, 'current public ' . 'release candidate') === false, 'README must not use a legacy release label');
assert_true(strpos($readmeSource, 'stage cadence') === false, 'README must not expose internal stage cadence terminology');
assert_true(strpos($readmeSource, 'email escalation') === false, 'README must not use the old email escalation wording');
assert_true(strpos($readmeSource, 'Enable monitoring in Global Settings') === false, 'README must not refer to removed Global Settings monitoring enablement');
assert_true(strpos($readmeSource, "cd /var/www/html/admin/modules/repeatcaller\nfwconsole ma install repeatcaller") !== false, 'README should show the unpacked-directory install command sequence');
assert_true(strpos($readmeSource, "git clone https://github.com/kierknoby/repeatcaller.git repeatcaller\ncd repeatcaller\nfwconsole ma install repeatcaller") !== false, 'README should show the GitHub install command sequence');
assert_true(strpos($readmeSource, 'Option 3: Install from a local copy') !== false, 'README should document local-copy installation path');
assert_true(strpos($readmeSource, 'Option 3: Update from a local copy') !== false, 'README should document local-copy update path');
assert_true(strpos($readmeSource, 'release history') === false, 'README must not reference release history documentation');
assert_true(strpos($readmeSource, 'Release History') === false, 'README must not include a release history section');
assert_true(strpos($readmeSource, 'Release Status') === false, 'README must not include release status section');

$userGuideSource = file_get_contents($root . '/USER_GUIDE.md');
assert_true($userGuideSource !== false, 'USER_GUIDE.md should exist and be readable');
assert_true(strpos($userGuideSource, '# Repeat Caller User Guide') !== false, 'USER_GUIDE.md should have the expected title');
assert_true(strpos($userGuideSource, 'Reports > Repeat Caller') !== false, 'USER_GUIDE.md should include the Reports > Repeat Caller navigation path');
assert_true(strpos($userGuideSource, 'Recent Incidents') !== false, 'USER_GUIDE.md should use the current UI label Recent Incidents');
assert_true(strpos($userGuideSource, 'Suppressed Alerts History') !== false, 'USER_GUIDE.md should explain suppressed alerts history');
assert_true(strpos($userGuideSource, '## History Pruning') !== false, 'USER_GUIDE.md should include a History Pruning section');
assert_true(strpos($userGuideSource, '## Clearing Alert History') !== false, 'USER_GUIDE.md should include clear alert history instructions');
assert_true(strpos($userGuideSource, 'Rule-level Suppression override replaces that default for the rule.') !== false, 'USER_GUIDE.md should explain rule-level suppression override behavior');
assert_true(strpos($userGuideSource, 'Clear Alert History is an immediate manual action. Prune Alert History is the') !== false, 'USER_GUIDE.md should distinguish clear alert history from automatic pruning');
assert_true(strpos($userGuideSource, 'Clear Suppression affects current suppression state for that rule/subject.') !== false, 'USER_GUIDE.md should distinguish clear suppression from suppression-history pruning');
assert_true((bool) preg_match('/Repeat Caller automatically removes old internal detection records during\s+pruning to prevent unnecessary database growth\./', $userGuideSource), 'USER_GUIDE.md should document automatic internal detection-record cleanup during pruning');
assert_true(strpos($userGuideSource, 'This alert is currently unaccepted. You will receive a notification once it is accepted by phone or through the GUI.') !== false, 'USER_GUIDE.md should include current customer-facing unaccepted notification wording');
assert_true(strpos($userGuideSource, 'README.md') !== false, 'USER_GUIDE.md should link back to README.md');
assert_true(strpos($userGuideSource, 'stage cadence') === false, 'USER_GUIDE.md must not expose internal stage cadence terminology');
assert_true(strpos($userGuideSource, 'Alert Call destinations and Alert Call Caller ID are administrator-controlled settings; only configure trusted values that are appropriate for your PBX.') !== false, 'USER_GUIDE.md should clarify trusted administrator-controlled Alert Call destination and caller ID settings');
assert_true(strpos($readmeSource, 'Press 1: accepts the incident') !== false && strpos($readmeSource, 'Press 2: declines that Alert Call attempt') !== false, 'README should describe current Alert Call DTMF controls');
assert_true(strpos($readmeSource, 'Alert Call destinations and Alert Call caller ID values are administrator-') !== false, 'README should clarify administrator-controlled Alert Call destination and caller ID settings');
assert_true(strpos($readmeSource, 'Only use trusted values that are appropriate for') !== false, 'README should advise trusted Alert Call destination and caller ID values');
assert_true((bool) preg_match('/No valid response: records an answered-no-response outcome and leaves the\s+incident unaccepted/', $readmeSource), 'README should describe no-response unaccepted outcome');
assert_true(strpos($readmeSource, 'GUI incidents, which are always recorded') !== false && strpos($readmeSource, 'Email notifications, which are optional per rule') !== false && strpos($readmeSource, 'Alert Call, which is optional per rule and can be answered from the phone') !== false, 'README should document GUI incidents and optional Email/Alert Call behaviour');
assert_true(strpos($readmeSource, 'Prune Incident History removes old completed incident records.') !== false, 'README should explain incident-history pruning scope');
assert_true(strpos($readmeSource, 'Prune Alert History removes old notification and Alert Call delivery records.') !== false, 'README should explain alert-history pruning scope');
assert_true(strpos($readmeSource, 'Prune Suppression History removes old suppression audit records.') !== false, 'README should explain suppression-history pruning scope');
assert_true(strpos($readmeSource, 'Pruning affects historical records only. It does not remove rules, settings,') !== false, 'README should explain pruning boundaries for live data');
assert_true((bool) preg_match('/Repeat Caller automatically removes old internal detection records during\s+pruning to prevent unnecessary database growth\./', $readmeSource), 'README should document automatic internal detection-record cleanup during pruning');
assert_true(strpos($readmeSource, 'Never disables automatic pruning.') !== false, 'README should document that Never disables automatic pruning');
assert_true((bool) preg_match('/Available pruning schedule options are:\s+- Never\s+- Hourly\s+- Daily \(default\)\s+- Weekly\s+- Monthly\s+- Yearly/', $readmeSource), 'README should list the complete pruning schedule options in canonical order');
assert_true(strpos($userGuideSource, 'Never disables automatic pruning.') !== false, 'USER_GUIDE.md should document that Never disables automatic pruning');
assert_true((bool) preg_match('/Available pruning schedule options are:\s+- Never\s+- Hourly\s+- Daily \(default\)\s+- Weekly\s+- Monthly\s+- Yearly/', $userGuideSource), 'USER_GUIDE.md should list the complete pruning schedule options in canonical order');
assert_true(strpos($readmeSource, 'Claimed By') === false, 'README must not use Claimed By terminology');
assert_true(strpos($readmeSource, 'Accepted and claimed') === false, 'README must not use Accepted and claimed wording');
assert_true(strpos($readmeSource, 'Press 1 to claim') === false, 'README must not use Press 1 to claim wording');
assert_true(strpos($userGuideSource, 'Snooze is not suppression.') !== false, 'USER_GUIDE.md should distinguish snooze from suppression');

$testingSource = file_get_contents($root . '/TESTING.md');
assert_true($testingSource !== false, 'TESTING.md should exist and be readable');
assert_true((bool) preg_match('/Confirm the available pruning schedule options:\s+- Never\s+- Hourly\s+- Daily \(default\)\s+- Weekly\s+- Monthly\s+- Yearly/', $testingSource), 'TESTING.md should list the complete pruning schedule options in canonical order');

assert_true(preg_match('/\b0\.' . '9\.[0-9]+\b/', $readmeSource) !== 1, 'README must not reference legacy patch versions');
assert_true(strpos($readmeSource, 'CHANGELOG') === false && strpos($readmeSource, 'changelog') === false, 'README must not reference changelog files');
assert_true(strpos($readmeSource, 'alpha') === false, 'README must not use alpha terminology');
assert_true(strpos($readmeSource, 'beta') === false, 'README must not use beta terminology');

// --- 8: removed/unknown settings cannot be written ------------------------

$settingsPdo = new PDO('sqlite::memory:');
$settingsPdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
// Deliberately no repeatcaller_settings table: a write attempt of any kind
// (valid or not) would throw "no such table". A silent return proves the
// allowlist guard short-circuited before touching the database.
FreePBX::$database = $settingsPdo;

$setSetting = new ReflectionMethod(Repeatcaller::class, 'setSetting');
$setSetting->setAccessible(true);

$rejectedWrite = true;
try {
	$setSetting->invoke($module, 'repeatcaller_registrations_watch_enabled', '1');
} catch (Throwable $e) {
	$rejectedWrite = false;
}
assert_true($rejectedWrite, 'writing an unknown/removed setting key must be a guarded no-op, not reach the database');

$attemptedKnownWrite = false;
try {
	$setSetting->invoke($module, 'enabled', '1');
} catch (Throwable $e) {
	$attemptedKnownWrite = true;
}
assert_true($attemptedKnownWrite, 'writing a canonical setting key must reach the database write path (table-less PDO used here only to prove reachability)');

// --- 9: active settings are allowlisted on read, and values validated ----

$settingsPdo->exec('CREATE TABLE repeatcaller_settings (setting_key TEXT PRIMARY KEY, setting_value TEXT, updated_at TEXT)');
$settingsPdo->exec("INSERT INTO repeatcaller_settings (setting_key, setting_value, updated_at) VALUES ('enabled', '1', '2026-07-13 00:00:00')");
$settingsPdo->exec("INSERT INTO repeatcaller_settings (setting_key, setting_value, updated_at) VALUES ('repeatcaller_registrations_watch_enabled', '1', '2026-07-13 00:00:00')");

$getAlertSettings = new ReflectionMethod(Repeatcaller::class, 'getAlertSettings');
$getAlertSettings->setAccessible(true);
$loadedSettings = $getAlertSettings->invoke($module);
assert_true(array_key_exists('enabled', $loadedSettings), 'canonical settings must be loaded');
assert_true(!array_key_exists('repeatcaller_registrations_watch_enabled', $loadedSettings), 'unknown/removed settings persisted in the database must not surface through settings reads');
assert_same('1', $loadedSettings['enabled'], 'known setting values should round-trip');

$normalisePrunePolicy = new ReflectionMethod(Repeatcaller::class, 'normalisePrunePolicy');
$normalisePrunePolicy->setAccessible(true);
assert_same('never', $normalisePrunePolicy->invoke($module, 'not-a-real-policy'), 'invalid prune policy values must normalise to never (preserve data)');
assert_same('daily', $normalisePrunePolicy->invoke($module, 'DAILY'), 'valid prune policy values should normalise case-insensitively');
assert_same('never', $normalisePrunePolicy->invoke($module, 'NEVER'), 'Never should be accepted case-insensitively');
assert_same('yearly', $normalisePrunePolicy->invoke($module, 'YEARLY'), 'Yearly should be accepted case-insensitively');

$boundedDigits = new ReflectionMethod(Repeatcaller::class, 'boundedDigits');
$boundedDigits->setAccessible(true);
assert_same(240, $boundedDigits->invoke($module, 'not-a-number', 60, 10080, 240), 'non-numeric bounded settings must fall back to the documented default');
assert_same(10080, $boundedDigits->invoke($module, '999999', 60, 10080, 240), 'bounded settings must clamp to the documented maximum');
assert_same(60, $boundedDigits->invoke($module, '1', 60, 10080, 240), 'bounded settings must clamp to the documented minimum');

// --- retention point 7: manual pruning (Repeatcaller::rcHandlePruneHistory) and
// background pruning (IncidentAlertProcessor::run) must derive prune cutoffs from
// identical retention semantics, so an operator sees the same behavior from either
// path. rcHandlePruneHistory itself cannot be driven end-to-end here because it also
// persists settings via MySQL-only "ON DUPLICATE KEY UPDATE" syntax that SQLite cannot
// execute; instead the two independent cutoff calculators are compared directly.

$parityPdo = new PDO('sqlite::memory:');
$parityPdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$parityRepository = new RepeatCallerRepository($parityPdo);
$parityProcessor = new IncidentAlertProcessor($parityRepository, null, null, null);

$controllerPruneCutoff = new ReflectionMethod(Repeatcaller::class, 'pruneCutoff');
$controllerPruneCutoff->setAccessible(true);
$processorPruneCutoff = new ReflectionMethod(IncidentAlertProcessor::class, 'pruneCutoff');
$processorPruneCutoff->setAccessible(true);

foreach (['never', 'not-a-real-policy', ''] as $preservingPolicy) {
	$controllerCutoff = $controllerPruneCutoff->invoke($module, $preservingPolicy);
	$processorCutoff = $processorPruneCutoff->invoke($parityProcessor, $preservingPolicy, date('Y-m-d H:i:s'));
	assert_true($controllerCutoff === null, "manual-pruning cutoff for policy '{$preservingPolicy}' must be null (preserve)");
	assert_true($processorCutoff === null, "background-pruning cutoff for policy '{$preservingPolicy}' must be null (preserve), matching manual pruning");
}

foreach (['hourly', 'daily', 'weekly', 'monthly', 'yearly'] as $activePolicy) {
	$now = date('Y-m-d H:i:s');
	$controllerCutoff = $controllerPruneCutoff->invoke($module, $activePolicy);
	$processorCutoff = $processorPruneCutoff->invoke($parityProcessor, $activePolicy, $now);
	assert_true($controllerCutoff !== null, "manual-pruning cutoff for policy '{$activePolicy}' must be a real cutoff");
	assert_true($processorCutoff !== null, "background-pruning cutoff for policy '{$activePolicy}' must be a real cutoff");
	$driftSeconds = abs(strtotime($controllerCutoff) - strtotime($processorCutoff));
	assert_true($driftSeconds <= 5, "manual and background pruning must compute the same '{$activePolicy}' cutoff window (drift {$driftSeconds}s)");
}

echo "repeat release contract tests passed\n";

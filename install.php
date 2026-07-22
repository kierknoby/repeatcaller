<?php

// Repeat Caller install hook.
//
// Schema creation is owned by src/Schema.php and is also invoked from
// Repeatcaller::install() (the BMO install() method), which FreePBX's module
// installer calls before this file. Both entrypoints share the same
// idempotent logic and use \FreePBX::Database() (a real PDO connection)
// rather than the legacy global $db wrapper, whose query() method silently
// swallows SQL exceptions instead of throwing them.
//
// This file is kept as a defensive, redundant second attempt for
// compatibility with FreePBX's legacy install.php convention. If schema
// creation genuinely fails here, the exception is allowed to propagate so
// the failure is visible rather than silently ignored.

require_once __DIR__ . '/src/Schema.php';

\FreePBX\modules\Repeatcaller\Schema::install(\FreePBX::Database());

repeatcallerInstallAgi();
repeatcallerInstallDialplan();

try {
	\FreePBX::Job()->addClass(
		'repeatcaller',
		'monitor',
		'\\FreePBX\\modules\\Repeatcaller\\Job',
		'* * * * *',
		30,
		true
	);
} catch (\Throwable $e) {
	\FreePBX::Log()->error('repeatcaller: failed to register background job: ' . $e->getMessage());
}

function repeatcallerInstallAgi(): void {
	$sourceAgiPath = __DIR__ . '/agi/repeatcaller_alert_response.php';
	if (!file_exists($sourceAgiPath)) {
		throw new \RuntimeException('Repeat Caller AGI source script is missing.');
	}

	$astAgiDir = rtrim((string)\FreePBX::Config()->get('ASTAGIDIR'), '/');
	if ($astAgiDir === '') {
		$astAgiDir = '/var/lib/asterisk/agi-bin';
	}

	if (!is_dir($astAgiDir)) {
		if (!@mkdir($astAgiDir, 0755, true) && !is_dir($astAgiDir)) {
			throw new \RuntimeException('Asterisk AGI directory is not available.');
		}
	}

	$deployedAgiPath = $astAgiDir . '/repeatcaller_alert_response.php';
	if (!@copy($sourceAgiPath, $deployedAgiPath)) {
		throw new \RuntimeException('Unable to deploy Repeat Caller AGI script to Asterisk AGI directory.');
	}

	if (!@chmod($deployedAgiPath, 0755)) {
		throw new \RuntimeException('Unable to enforce executable mode on deployed Repeat Caller AGI script.');
	}
}

function repeatcallerInstallDialplan(): void {
	$astetc = rtrim((string)\FreePBX::Config()->get('ASTETCDIR'), '/');
	if ($astetc === '') {
		throw new \RuntimeException('Asterisk configuration directory is not available.');
	}

	$fragmentPath = $astetc . '/repeatcaller_alert.conf';
	$customPath = $astetc . '/extensions_custom.conf';
	$agiScriptName = 'repeatcaller_alert_response.php';
	$fragment = <<<'CONF'
[repeatcaller-alert-launch]
exten => _X.,1,NoOp(Repeat Caller outbound alert for ${EXTEN})
 same => n,Dial(Local/${EXTEN}@from-internal/n,,U(repeatcaller-alert-playback^${REPEATCALLER_PLAYBACK_TARGET}^${IF($["${REPEATCALLER_PLAYBACK_LANGUAGE}"=""]?${CHANNEL(language)}:${REPEATCALLER_PLAYBACK_LANGUAGE})}^${REPEATCALLER_ALERT_HISTORY_ID}^${REPEATCALLER_INCIDENT_ID}^${REPEATCALLER_ALERT_RECIPIENT}^${REPEATCALLER_SUMMARY_MODE}^${REPEATCALLER_SUMMARY_CALL_COUNT}^${REPEATCALLER_SUMMARY_THRESHOLD}^${REPEATCALLER_SUMMARY_WINDOW_MINUTES}^${REPEATCALLER_SUMMARY_CALLER_KIND}^${REPEATCALLER_SUMMARY_CALLER_VALUE}^${REPEATCALLER_SUMMARY_DID_VALUE}))
 same => n,AGI(__REPEATCALLER_AGI_SCRIPT__,${REPEATCALLER_ALERT_HISTORY_ID},${REPEATCALLER_INCIDENT_ID},dialstatus,${REPEATCALLER_ALERT_RECIPIENT},${DIALSTATUS},${HANGUPCAUSE})
 same => n,Hangup()

[repeatcaller-alert-playback]
exten => s,1,NoOp(Repeat Caller alert playback)
 same => n,Set(CHANNEL(language)=${ARG2})
 same => n,Set(REPEATCALLER_ALERT_HISTORY_ID=${ARG3})
 same => n,Set(REPEATCALLER_INCIDENT_ID=${ARG4})
 same => n,Set(REPEATCALLER_ALERT_RECIPIENT=${ARG5})
 same => n,Answer()
 same => n,Set(REPEATCALLER_ATTEMPT=1)
 same => n,Set(REPEATCALLER_ALERT_COMPLETED=)
 same => n(begin_attempt),Set(REPEATCALLER_DTMF=)
 same => n,Set(REPEATCALLER_RESPONSE_PHASE=recording)
 same => n,GotoIf($["${ARG1}"=""]?generated_summary)
 same => n,Background(${ARG1})
 same => n(generated_summary),Set(REPEATCALLER_RESPONSE_PHASE=summary)
 same => n,Gosub(repeatcaller-alert-summary,s,1(${ARG6},${ARG7},${ARG8},${ARG9},${ARG10},${ARG11},${ARG12}))
 same => n(read_wait),Set(REPEATCALLER_RESPONSE_PHASE=menu)
 same => n,Read(REPEATCALLER_DTMF,,1,,1,10)
 same => n,GotoIf($["${REPEATCALLER_DTMF}"="1"]?accepted)
 same => n,GotoIf($["${REPEATCALLER_DTMF}"="2"]?declined)
 same => n,GotoIf($["${REPEATCALLER_DTMF}"!=""]?invalid_response)
 same => n,GotoIf($[${REPEATCALLER_ATTEMPT} >= 3]?no_response)
 same => n,Set(REPEATCALLER_ATTEMPT=$[${REPEATCALLER_ATTEMPT} + 1])
 same => n,Goto(begin_attempt)
 same => n(no_response),AGI(__REPEATCALLER_AGI_SCRIPT__,${REPEATCALLER_ALERT_HISTORY_ID},${REPEATCALLER_INCIDENT_ID},answered_no_response,${REPEATCALLER_ALERT_RECIPIENT},${REPEATCALLER_DTMF})
 same => n,Set(REPEATCALLER_ALERT_COMPLETED=1)
 same => n,Background(auth-thankyou)
 same => n,Background(goodbye)
 same => n,Hangup()
 same => n(invalid_response),Background(sorry&please-try-again)
 same => n,GotoIf($[${REPEATCALLER_ATTEMPT} >= 3]?no_response)
 same => n,Set(REPEATCALLER_ATTEMPT=$[${REPEATCALLER_ATTEMPT} + 1])
 same => n,Goto(begin_attempt)
 same => n(accepted),AGI(__REPEATCALLER_AGI_SCRIPT__,${REPEATCALLER_ALERT_HISTORY_ID},${REPEATCALLER_INCIDENT_ID},accepted,${REPEATCALLER_ALERT_RECIPIENT},1)
 same => n,Set(REPEATCALLER_ALERT_COMPLETED=1)
 same => n,Background(auth-thankyou)
 same => n,Background(goodbye)
 same => n,Hangup()
 same => n(declined),AGI(__REPEATCALLER_AGI_SCRIPT__,${REPEATCALLER_ALERT_HISTORY_ID},${REPEATCALLER_INCIDENT_ID},declined,${REPEATCALLER_ALERT_RECIPIENT},2)
 same => n,Set(REPEATCALLER_ALERT_COMPLETED=1)
 same => n,Background(auth-thankyou)
 same => n,Background(goodbye)
 same => n,Hangup()

exten => 1,1,Goto(s,accepted)
exten => 2,1,Goto(s,declined)
exten => i,1,GotoIf($["${REPEATCALLER_RESPONSE_PHASE}"="recording"]?s,generated_summary)
 same => n,Goto(s,invalid_response)

[repeatcaller-alert-summary]
exten => s,1,NoOp(Repeat Caller generated summary ${ARG1})
 same => n,Background(beep&beep&beep&warning&beep&beep&beep)
 same => n,Background(this&alert&has-been&initiated&for)
 same => n,GotoIf($["${ARG1}"="invert"]?invert_threshold)
 same => n,SayNumber(${ARG2})
 same => n,GotoIf($[${ARG2} = 1]?single_call:plural_calls)
 same => n(invert_threshold),Background(less-than)
 same => n,SayNumber(${ARG3})
 same => n,GotoIf($[${ARG3} = 1]?single_call:plural_calls)
 same => n(single_call),Background(call)
 same => n,Goto(window_within)
 same => n(plural_calls),Background(calls)
 same => n(window_within),Background(within)
 same => n,SayNumber(${ARG4})
 same => n,GotoIf($[${ARG4} = 1]?single_minute:plural_minutes)
 same => n(single_minute),Background(minute)
 same => n,Goto(caller_info)
 same => n(plural_minutes),Background(minutes)
 same => n(caller_info),GotoIf($["${ARG5}"="none"]?calling_number)
 same => n,GotoIf($["${ARG5}"="unknown"]?unknown_caller)
 same => n,Background(from)
 same => n,SayDigits(${ARG6})
 same => n,Goto(calling_number)
 same => n(unknown_caller),Background(from-unknown-caller)
 same => n(calling_number),GotoIf($["${ARG7}"=""]?response_menu)
 same => n,Background(calling&number)
 same => n,SayDigits(${ARG7})
 same => n(response_menu),Background(vqplus-accept)
 same => n,Return()

exten => 1,1,Goto(repeatcaller-alert-playback,s,accepted)
exten => 2,1,Goto(repeatcaller-alert-playback,s,declined)
exten => i,1,Goto(repeatcaller-alert-playback,s,invalid_response)

exten => h,1,GotoIf($["${REPEATCALLER_ALERT_COMPLETED}"="1"]?done)
 same => n,AGI(__REPEATCALLER_AGI_SCRIPT__,${REPEATCALLER_ALERT_HISTORY_ID},${REPEATCALLER_INCIDENT_ID},answered_no_response,${REPEATCALLER_ALERT_RECIPIENT},${REPEATCALLER_DTMF})
 same => n(done),NoOp(Repeat Caller alert playback complete)
CONF;
	$fragment = str_replace('__REPEATCALLER_AGI_SCRIPT__', $agiScriptName, $fragment);

	if (file_put_contents($fragmentPath, $fragment . "\n") === false) {
		throw new \RuntimeException('Unable to write Repeat Caller alert dialplan fragment.');
	}

	$includeBlock = "; Repeat Caller managed include\n#include repeatcaller_alert.conf\n; End Repeat Caller managed include";
	if (!file_exists($customPath)) {
		if (file_put_contents($customPath, $includeBlock . "\n") === false) {
			throw new \RuntimeException('Unable to create extensions_custom.conf include block.');
		}
		return;
	}

	$content = (string)file_get_contents($customPath);
	if (strpos($content, '#include repeatcaller_alert.conf') !== false) {
		return;
	}

	$content = rtrim($content);
	if ($content !== '') {
		$content .= "\n\n";
	}
	$content .= $includeBlock . "\n";
	if (file_put_contents($customPath, $content) === false) {
		throw new \RuntimeException('Unable to update extensions_custom.conf include block.');
	}
}

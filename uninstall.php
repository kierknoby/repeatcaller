<?php

// Repeat Caller uninstall hook.

require_once __DIR__ . '/src/Schema.php';

repeatcallerRemoveDialplan();
repeatcallerRemoveAgi();

try {
	\FreePBX::Job()->remove('repeatcaller', 'monitor');
} catch (\Throwable $e) {
	\FreePBX::Log()->error('repeatcaller: failed to remove background job: ' . $e->getMessage());
}

$pdo = \FreePBX::Database();

// Current tables plus known legacy Repeat Caller-owned tables. Each drop is
// independent so one missing/locked table never blocks cleanup of the rest.
$tables = array_merge(\FreePBX\modules\Repeatcaller\Schema::REQUIRED_TABLES, [
	'repeatcaller_alert_escalation',
	'repeatcaller_alert_history',
]);

foreach ($tables as $table) {
	try {
		$pdo->exec('DROP TABLE IF EXISTS ' . $table);
	} catch (\Throwable $e) {
		\FreePBX::Log()->error('repeatcaller: failed to drop table ' . $table . ': ' . $e->getMessage());
	}
}

function repeatcallerRemoveAgi(): void {
	$astAgiDir = rtrim((string)\FreePBX::Config()->get('ASTAGIDIR'), '/');
	if ($astAgiDir === '') {
		$astAgiDir = '/var/lib/asterisk/agi-bin';
	}

	$deployedAgiPath = $astAgiDir . '/repeatcaller_alert_response.php';
	if (file_exists($deployedAgiPath)) {
		@unlink($deployedAgiPath);
	}
}

function repeatcallerRemoveDialplan(): void {
	$astetc = rtrim((string)\FreePBX::Config()->get('ASTETCDIR'), '/');
	if ($astetc === '') {
		return;
	}

	$fragmentPath = $astetc . '/repeatcaller_alert.conf';
	$customPath = $astetc . '/extensions_custom.conf';
	if (file_exists($fragmentPath)) {
		@unlink($fragmentPath);
	}

	if (!file_exists($customPath)) {
		return;
	}

	$content = (string)file_get_contents($customPath);
	$pattern = '/\n?; Repeat Caller managed include\n#include repeatcaller_alert\.conf\n; End Repeat Caller managed include\n?/';
	$updated = preg_replace($pattern, "\n", $content);
	if (is_string($updated) && $updated !== $content) {
		file_put_contents($customPath, rtrim($updated) . "\n");
	}
}

<?php

declare(strict_types=1);

namespace FreePBX\modules\Repeatcaller;

use PDO;

/**
 * Repeat Caller schema install/readiness logic.
 *
 * Deliberately uses a real PDO connection with PDO::ERRMODE_EXCEPTION rather
 * than FreePBX's legacy global $db (a PearDB-compatible wrapper around PDO
 * whose query() method catches all exceptions and returns a DB_Error object
 * instead of throwing). That wrapper is why a failed CREATE TABLE could
 * previously be silently swallowed while FreePBX still reported a successful
 * module install.
 */
final class Schema {
	public const REQUIRED_TABLES = [
		'repeatcaller_settings',
		'repeatcaller_rules',
		'repeatcaller_rule_schedules',
		'repeatcaller_rule_callers',
		'repeatcaller_rule_dids',
		'repeatcaller_seen_calls',
		'repeatcaller_rule_subject_state',
		'repeatcaller_incidents',
		'repeatcaller_incident_alert_state',
		'repeatcaller_incident_alert_history',
		'repeatcaller_incident_suppression_history',
	];

	private const DEFAULT_SETTINGS = [
		'default_country_code' => '',
		'engine_last_success_at' => '',
		'engine_last_summary_json' => '',
		'global_snoozed_until' => '',
		'global_snooze_selected_seconds' => '',
		'incident_history_prune_policy' => 'daily',
		'alert_history_prune_policy' => 'daily',
		'suppression_history_prune_policy' => 'daily',
	];

	/**
	 * Idempotent: safe to call multiple times (fresh install, upgrade, or a
	 * redundant second invocation from the legacy install.php hook). Throws
	 * on any real failure instead of swallowing it, so FreePBX's installer
	 * can correctly report a failed install per the BMO install() contract.
	 */
	public static function install(PDO $pdo): void {
		$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

		foreach (self::createTableStatements() as $statement) {
			$pdo->exec($statement);
		}

		$missing = [];
		foreach (self::REQUIRED_TABLES as $table) {
			if (!self::tableExists($pdo, $table)) {
				$missing[] = $table;
			}
		}
		if ($missing) {
			throw new \RuntimeException(
				'Repeat Caller schema creation did not produce required table(s): ' . implode(', ', $missing)
			);
		}

		self::seedDefaultSettings($pdo);
		self::applyGuardedMigrations($pdo);
	}

	/** True only when every current required table exists. Used both by the
	 * installer's own post-creation check and by the admin page's readiness
	 * guard, so a genuinely incomplete install shows a bounded operator
	 * message instead of a raw PDOException. */
	public static function isReady(PDO $pdo): bool {
		foreach (self::REQUIRED_TABLES as $table) {
			if (!self::tableExists($pdo, $table)) {
				return false;
			}
		}
		return true;
	}

	public static function tableExists(PDO $pdo, string $table): bool {
		$stmt = $pdo->prepare(
			'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?'
		);
		$stmt->execute([$table]);
		return (int)$stmt->fetchColumn() > 0;
	}

	private static function seedDefaultSettings(PDO $pdo): void {
		$stmt = $pdo->prepare(
			'INSERT IGNORE INTO repeatcaller_settings (setting_key, setting_value, updated_at) VALUES (:setting_key, :setting_value, :updated_at)'
		);
		$now = date('Y-m-d H:i:s');
		foreach (self::DEFAULT_SETTINGS as $key => $value) {
			$stmt->execute([':setting_key' => $key, ':setting_value' => $value, ':updated_at' => $now]);
		}
	}

	private static function applyGuardedMigrations(PDO $pdo): void {
		self::addColumnIfMissing($pdo, 'repeatcaller_rules', 'email_enabled', 'TINYINT(1) NOT NULL DEFAULT 0');
		self::addColumnIfMissing($pdo, 'repeatcaller_rules', 'email_recipients', 'TEXT NULL');
		self::addColumnIfMissing($pdo, 'repeatcaller_rules', 'alert_call_enabled', 'TINYINT(1) NOT NULL DEFAULT 0');
		self::addColumnIfMissing($pdo, 'repeatcaller_rules', 'alert_call_destinations', 'TEXT NULL');
		self::addColumnIfMissing($pdo, 'repeatcaller_rules', 'alert_call_strategy', 'VARCHAR(20) NOT NULL DEFAULT "ringall"');
		self::addColumnIfMissing($pdo, 'repeatcaller_rules', 'alert_call_keep_trying', 'TINYINT(1) NOT NULL DEFAULT 1');
		self::addColumnIfMissing($pdo, 'repeatcaller_rules', 'alert_call_recording_id', 'INT UNSIGNED NULL');
		self::addColumnIfMissing($pdo, 'repeatcaller_rules', 'alert_call_callerid', 'VARCHAR(255) NULL');
		self::addColumnIfMissing($pdo, 'repeatcaller_rules', 'is_deleted', 'TINYINT(1) NOT NULL DEFAULT 0');
		self::addColumnIfMissing($pdo, 'repeatcaller_rules', 'deleted_at', 'DATETIME NULL');
		self::addColumnIfMissing($pdo, 'repeatcaller_incidents', 'cleared_at', 'DATETIME NULL');
		self::addColumnIfMissing($pdo, 'repeatcaller_incidents', 'threshold_count', 'INT UNSIGNED NOT NULL DEFAULT 0');
		self::addColumnIfMissing($pdo, 'repeatcaller_incidents', 'observation_window_minutes', 'INT UNSIGNED NOT NULL DEFAULT 0');
		self::addColumnIfMissing($pdo, 'repeatcaller_incident_suppression_history', 'cleared_at', 'DATETIME NULL');
		self::ensureScheduleDayOfWeekIsSigned($pdo);
	}

	private static function ensureScheduleDayOfWeekIsSigned(PDO $pdo): void {
		$stmt = $pdo->prepare(
			'SELECT COLUMN_TYPE
			 FROM information_schema.COLUMNS
			 WHERE TABLE_SCHEMA = DATABASE()
				AND TABLE_NAME = ?
				AND COLUMN_NAME = ?
			 LIMIT 1'
		);
		$stmt->execute(['repeatcaller_rule_schedules', 'day_of_week']);
		$columnType = strtolower((string)$stmt->fetchColumn());
		if ($columnType === '') {
			return;
		}
		if (strpos($columnType, 'unsigned') === false) {
			return;
		}

		$pdo->exec('ALTER TABLE repeatcaller_rule_schedules MODIFY COLUMN day_of_week TINYINT NOT NULL');
	}

	private static function addColumnIfMissing(PDO $pdo, string $table, string $column, string $definition): void {
		$stmt = $pdo->prepare(
			'SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
		);
		$stmt->execute([$table, $column]);
		if ((int)$stmt->fetchColumn() === 0) {
			$pdo->exec("ALTER TABLE {$table} ADD COLUMN {$column} {$definition}");
		}
	}

	/**
	 * @return string[]
	 */
	private static function createTableStatements(): array {
		return [
			"CREATE TABLE IF NOT EXISTS repeatcaller_settings (
				setting_key VARCHAR(80) NOT NULL,
				setting_value TEXT NULL,
				updated_at DATETIME NULL,
				PRIMARY KEY (setting_key)
			) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci",

			"CREATE TABLE IF NOT EXISTS repeatcaller_rules (
				id INT UNSIGNED NOT NULL AUTO_INCREMENT,
				name VARCHAR(255) NOT NULL,
				enabled TINYINT(1) NOT NULL DEFAULT 1,
				email_enabled TINYINT(1) NOT NULL DEFAULT 0,
				email_recipients TEXT NULL,
				alert_call_enabled TINYINT(1) NOT NULL DEFAULT 0,
				alert_call_destinations TEXT NULL,
				alert_call_strategy VARCHAR(20) NOT NULL DEFAULT 'ringall',
				alert_call_keep_trying TINYINT(1) NOT NULL DEFAULT 1,
				alert_call_recording_id INT UNSIGNED NULL,
				alert_call_callerid VARCHAR(255) NULL,
				is_deleted TINYINT(1) NOT NULL DEFAULT 0,
				deleted_at DATETIME NULL,
				mode VARCHAR(20) NOT NULL,
				threshold_count INT UNSIGNED NOT NULL,
				observation_window_minutes INT UNSIGNED NOT NULL,
				caller_mode VARCHAR(20) NOT NULL,
				exclude_withheld TINYINT(1) NOT NULL DEFAULT 0,
				did_scope_mode VARCHAR(20) NOT NULL,
				repeat_mode_override VARCHAR(20) NULL,
				suppression_minutes_override INT UNSIGNED NULL,
				created_at DATETIME NULL,
				updated_at DATETIME NULL,
				PRIMARY KEY (id),
				KEY repeatcaller_rules_enabled (enabled),
				KEY repeatcaller_rules_mode (mode),
				KEY repeatcaller_rules_deleted (is_deleted)
			) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci",

			"CREATE TABLE IF NOT EXISTS repeatcaller_rule_schedules (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				rule_id INT UNSIGNED NOT NULL,
				day_of_week TINYINT NOT NULL,
				start_time TIME NOT NULL,
				end_time TIME NOT NULL,
				created_at DATETIME NULL,
				PRIMARY KEY (id),
				KEY repeatcaller_rule_schedules_rule_id (rule_id),
				KEY repeatcaller_rule_schedules_rule_day (rule_id, day_of_week)
			) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci",

			"CREATE TABLE IF NOT EXISTS repeatcaller_rule_callers (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				rule_id INT UNSIGNED NOT NULL,
				list_type VARCHAR(20) NOT NULL,
				raw_value VARCHAR(80) NOT NULL,
				normalized_value VARCHAR(40) NOT NULL,
				created_at DATETIME NULL,
				PRIMARY KEY (id),
				KEY repeatcaller_rule_callers_rule_id (rule_id),
				KEY repeatcaller_rule_callers_rule_list (rule_id, list_type),
				KEY repeatcaller_rule_callers_normalized (normalized_value)
			) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci",

			"CREATE TABLE IF NOT EXISTS repeatcaller_rule_dids (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				rule_id INT UNSIGNED NOT NULL,
				list_type VARCHAR(20) NOT NULL,
				route_key VARCHAR(191) NOT NULL,
				route_label VARCHAR(255) NOT NULL,
				did_value VARCHAR(80) NULL,
				cid_value VARCHAR(80) NULL,
				created_at DATETIME NULL,
				PRIMARY KEY (id),
				KEY repeatcaller_rule_dids_rule_id (rule_id),
				KEY repeatcaller_rule_dids_rule_list (rule_id, list_type),
				KEY repeatcaller_rule_dids_route_key (route_key)
			) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci",

			"CREATE TABLE IF NOT EXISTS repeatcaller_seen_calls (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				call_identity VARCHAR(191) NOT NULL,
				identity_type VARCHAR(20) NOT NULL,
				fingerprint CHAR(64) NOT NULL,
				linkedid VARCHAR(64) NULL,
				uniqueid VARCHAR(64) NULL,
				caller_raw VARCHAR(80) NULL,
				caller_normalized VARCHAR(40) NULL,
				inbound_route_key VARCHAR(191) NULL,
				did_value VARCHAR(80) NULL,
				call_started_at DATETIME NOT NULL,
				call_completed_at DATETIME NULL,
				disposition VARCHAR(45) NULL,
				source_context VARCHAR(80) NULL,
				processed_at DATETIME NOT NULL,
				PRIMARY KEY (id),
				UNIQUE KEY repeatcaller_seen_calls_identity (call_identity),
				KEY repeatcaller_seen_calls_fingerprint (fingerprint),
				KEY repeatcaller_seen_calls_started_at (call_started_at),
				KEY repeatcaller_seen_calls_caller_started (caller_normalized, call_started_at),
				KEY repeatcaller_seen_calls_route_started (inbound_route_key, call_started_at)
			) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci",

			"CREATE TABLE IF NOT EXISTS repeatcaller_rule_subject_state (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				rule_id INT UNSIGNED NOT NULL,
				subject_key VARCHAR(191) NOT NULL,
				current_window_started_at DATETIME NULL,
				current_window_ends_at DATETIME NULL,
				current_window_call_count INT UNSIGNED NOT NULL DEFAULT 0,
				threshold_met TINYINT(1) NOT NULL DEFAULT 0,
				clear_observed_since_trigger TINYINT(1) NOT NULL DEFAULT 0,
				active_incident_id BIGINT UNSIGNED NULL,
				suppression_expires_at DATETIME NULL,
				last_call_at DATETIME NULL,
				last_evaluated_at DATETIME NULL,
				created_at DATETIME NULL,
				updated_at DATETIME NULL,
				PRIMARY KEY (id),
				UNIQUE KEY repeatcaller_rule_subject_state_unique (rule_id, subject_key),
				KEY repeatcaller_rule_subject_state_window (current_window_ends_at),
				KEY repeatcaller_rule_subject_state_incident (active_incident_id)
			) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci",

			"CREATE TABLE IF NOT EXISTS repeatcaller_incidents (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				rule_id INT UNSIGNED NOT NULL,
				subject_key VARCHAR(191) NOT NULL,
				active_subject_key VARCHAR(255) NULL,
				subject_label VARCHAR(255) NOT NULL,
				caller_normalized VARCHAR(40) NULL,
				caller_display VARCHAR(80) NULL,
				withheld_caller TINYINT(1) NOT NULL DEFAULT 0,
				mode VARCHAR(20) NOT NULL,
				threshold_count INT UNSIGNED NOT NULL DEFAULT 0,
				observation_window_minutes INT UNSIGNED NOT NULL DEFAULT 0,
				first_matched_at DATETIME NOT NULL,
				last_matched_at DATETIME NOT NULL,
				matched_call_count INT UNSIGNED NOT NULL DEFAULT 0,
				state VARCHAR(20) NOT NULL,
				claimed_by VARCHAR(255) NULL,
				claimed_at DATETIME NULL,
				claim_source VARCHAR(20) NULL,
				suppression_expires_at DATETIME NULL,
				cleared_at DATETIME NULL,
				created_at DATETIME NULL,
				updated_at DATETIME NULL,
				PRIMARY KEY (id),
				UNIQUE KEY repeatcaller_incidents_active_subject (active_subject_key),
				KEY repeatcaller_incidents_rule_state (rule_id, state),
				KEY repeatcaller_incidents_subject_state (subject_key, state),
				KEY repeatcaller_incidents_suppression_expires (suppression_expires_at),
				KEY repeatcaller_incidents_last_matched (last_matched_at)
			) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci",

			"CREATE TABLE IF NOT EXISTS repeatcaller_incident_alert_state (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				incident_id BIGINT UNSIGNED NOT NULL,
				rule_id INT UNSIGNED NOT NULL,
				repeat_mode VARCHAR(20) NOT NULL,
				initial_sent_at DATETIME NULL,
				last_alert_at DATETIME NULL,
				reminders_sent INT UNSIGNED NOT NULL DEFAULT 0,
				next_due_at DATETIME NULL,
				created_at DATETIME NOT NULL,
				updated_at DATETIME NOT NULL,
				PRIMARY KEY (id),
				UNIQUE KEY repeatcaller_incident_alert_state_incident (incident_id),
				KEY repeatcaller_incident_alert_state_rule_id (rule_id),
				KEY repeatcaller_incident_alert_state_next_due_at (next_due_at)
			) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci",

			"CREATE TABLE IF NOT EXISTS repeatcaller_incident_alert_history (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				incident_id BIGINT UNSIGNED NOT NULL,
				rule_id INT UNSIGNED NOT NULL,
				subject_key VARCHAR(191) NOT NULL,
				subject_label VARCHAR(255) NOT NULL,
				action_type VARCHAR(20) NOT NULL,
				event_type VARCHAR(20) NOT NULL,
				stage_n INT UNSIGNED NOT NULL DEFAULT 0,
				recipient VARCHAR(255) NULL,
				delivery_status VARCHAR(40) NOT NULL,
				attempted_at DATETIME NULL,
				successful_at DATETIME NULL,
				next_retry_at DATETIME NULL,
				failure_detail VARCHAR(1024) NULL,
				repeat_mode VARCHAR(20) NOT NULL,
				dedupe_key VARCHAR(191) NOT NULL,
				created_at DATETIME NOT NULL,
				updated_at DATETIME NOT NULL,
				PRIMARY KEY (id),
				UNIQUE KEY repeatcaller_incident_alert_history_dedupe (dedupe_key),
				KEY repeatcaller_incident_alert_history_incident (incident_id),
				KEY repeatcaller_incident_alert_history_rule_id (rule_id),
				KEY repeatcaller_incident_alert_history_action_status (action_type, delivery_status),
				KEY repeatcaller_incident_alert_history_retry (next_retry_at),
				KEY repeatcaller_incident_alert_history_created_at (created_at)
			) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci",

			"CREATE TABLE IF NOT EXISTS repeatcaller_incident_suppression_history (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				related_incident_id BIGINT UNSIGNED NOT NULL,
				rule_id INT UNSIGNED NOT NULL,
				rule_name VARCHAR(255) NOT NULL,
				mode VARCHAR(20) NOT NULL,
				subject_key VARCHAR(191) NOT NULL,
				subject_label VARCHAR(255) NOT NULL,
				caller_normalized VARCHAR(40) NULL,
				caller_display VARCHAR(80) NULL,
				inbound_route_key VARCHAR(191) NULL,
				inbound_route_label VARCHAR(255) NULL,
				did_value VARCHAR(80) NULL,
				matched_call_count INT UNSIGNED NOT NULL DEFAULT 0,
				threshold_count INT UNSIGNED NOT NULL,
				observation_window_minutes INT UNSIGNED NOT NULL,
				suppression_source VARCHAR(20) NOT NULL,
				suppression_minutes INT UNSIGNED NOT NULL,
				suppression_started_at DATETIME NOT NULL,
				suppression_expires_at DATETIME NOT NULL,
				cleared_at DATETIME NULL,
				related_incident_state VARCHAR(20) NOT NULL,
				detected_at DATETIME NOT NULL,
				created_at DATETIME NOT NULL,
				updated_at DATETIME NOT NULL,
				PRIMARY KEY (id),
				UNIQUE KEY repeatcaller_incident_suppression_history_related (related_incident_id),
				KEY repeatcaller_incident_suppression_history_rule (rule_id),
				KEY repeatcaller_incident_suppression_history_created_at (created_at),
				KEY repeatcaller_incident_suppression_history_expires_at (suppression_expires_at)
			) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci",
		];
	}
}

<?php
/**
 * Repeat Caller main view (v1 operator UI).
 *
 * @var string $moduleVersion
 * @var array $engineStatus
 * @var array $globalSettings
 * @var array $rules
 * @var array $activeIncidents
 * @var array $recentIncidents
 * @var array $suppressedIncidents
 * @var array $alertHistory
 * @var array $inboundRoutes
 * @var array $systemRecordings
 * @var array $alertCallLanguageSupport
 * @var string $csrfToken
 */
if (!defined('FREEPBX_IS_AUTH')) {
	die('No direct script access allowed');
}

$engineStatus = isset($engineStatus) && is_array($engineStatus) ? $engineStatus : [];
$globalSettings = isset($globalSettings) && is_array($globalSettings) ? $globalSettings : [];
$rules = isset($rules) && is_array($rules) ? $rules : [];
$activeIncidents = isset($activeIncidents) && is_array($activeIncidents) ? $activeIncidents : [];
$recentIncidents = isset($recentIncidents) && is_array($recentIncidents) ? $recentIncidents : [];
$suppressedIncidents = isset($suppressedIncidents) && is_array($suppressedIncidents) ? $suppressedIncidents : [];
$alertHistory = isset($alertHistory) && is_array($alertHistory) ? $alertHistory : [];
$inboundRoutes = isset($inboundRoutes) && is_array($inboundRoutes) ? $inboundRoutes : [];
$systemRecordings = isset($systemRecordings) && is_array($systemRecordings) ? $systemRecordings : [];
$alertCallLanguageSupport = isset($alertCallLanguageSupport) && is_array($alertCallLanguageSupport) ? $alertCallLanguageSupport : [];
$csrfToken = isset($csrfToken) ? (string)$csrfToken : '';

$repeatModes = [
	'never' => _('Never'),
	'5m' => _('Every 5 minutes'),
	'hourly' => _('Hourly'),
	'daily' => _('Daily'),
	'escalating' => _('Escalating'),
];

$initialRunStatus = strtolower((string)($engineStatus['lock_state'] ?? '')) === 'running' ? 'Running' : 'Waiting';
$initialMonitoringEnabled = !empty($engineStatus['enabled']);
$initialMonitoringSnoozed = trim((string)($engineStatus['global_snoozed_until'] ?? '')) !== '';
$initialBulkActionLabel = $initialMonitoringSnoozed
	? _('Resume All Rules')
	: ($initialMonitoringEnabled ? _('Disable All Rules') : _('Enable All Rules'));
$initialBulkActionButtonClass = ($initialMonitoringSnoozed || !$initialMonitoringEnabled) ? 'btn-success' : 'btn-danger';
$initialRunNowEnabled = $initialMonitoringEnabled && !$initialMonitoringSnoozed;

$assetVer = max(
	@filemtime(__DIR__ . '/../assets/js/repeatcaller.js') ?: 0,
	@filemtime(__DIR__ . '/../assets/css/repeatcaller.css') ?: 0
) ?: time();
?>
<link rel="stylesheet" href="modules/repeatcaller/assets/css/repeatcaller.css?v=<?php echo $assetVer; ?>">

<div class="repeatcaller" data-csrf-token="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
	<input type="hidden" name="token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
	<div class="container-fluid repeatcaller-container">

	<div class="row">
		<div class="col-sm-12">
			<h1><?php echo _('Repeat Caller'); ?> <small class="text-muted">v<?php echo htmlspecialchars((string)$moduleVersion, ENT_QUOTES, 'UTF-8'); ?></small></h1>
			<p><?php echo _('Rules support repeat and invert detection modes, caller and DID scoping, schedule windows, repeat-notification modes, and suppression controls. The admin page presents active and historical lifecycle views so operators can review incidents, acceptances, alerts, and suppression decisions in one place.'); ?></p>
			<div id="rc-message" class="alert" style="display:none;"></div>
		</div>
	</div>

	<div class="row rc-section">
		<div class="col-sm-12">
			<div class="panel panel-default">
				<div class="panel-heading"><h3 class="panel-title"><?php echo _('Engine Status'); ?></h3></div>
				<div class="panel-body">
					<div class="rc-engine-banner" id="rc-engine-banner"></div>
					<div class="rc-engine-summary-row" id="rc-engine-summary-row">
						<div class="rc-engine-summary-item"><strong><?php echo _('Enabled Rules'); ?>:</strong><span class="rc-engine-summary-value" id="rc-enabled-rule-count"><?php echo (int)($engineStatus['enabled_rule_count'] ?? 0); ?></span></div>
						<div class="rc-engine-summary-item"><strong><?php echo _('Active Incidents'); ?>:</strong><span class="rc-engine-summary-value" id="rc-active-incident-count"><?php echo (int)($engineStatus['active_incident_count'] ?? 0); ?></span></div>
						<div class="rc-engine-summary-item"><strong><?php echo _('Last Run'); ?>:</strong><span class="rc-engine-summary-value" id="rc-last-run"><?php echo htmlspecialchars((string)($engineStatus['last_successful_run'] ?? '-'), ENT_QUOTES, 'UTF-8'); ?></span></div>
						<div class="rc-engine-summary-item"><strong><?php echo _('Run Status'); ?>:</strong><span class="rc-engine-summary-value" id="rc-run-status"><span id="rc-lock-state"><?php echo htmlspecialchars($initialRunStatus, ENT_QUOTES, 'UTF-8'); ?></span></span></div>
						<div class="rc-engine-summary-item"><strong><?php echo _('PBX Time'); ?>:</strong><span class="rc-engine-summary-value" id="rc-pbx-time"><?php echo htmlspecialchars((string)($engineStatus['pbx_time'] ?? '-'), ENT_QUOTES, 'UTF-8'); ?></span></div>
					</div>
					<div class="rc-actions">
						<button type="button" class="btn <?php echo $initialBulkActionButtonClass; ?>" id="rc-bulk-rule-action"><?php echo htmlspecialchars((string)$initialBulkActionLabel, ENT_QUOTES, 'UTF-8'); ?></button>
						<span role="group" aria-label="Snooze controls">
							<button type="button" class="btn btn-primary rc-snooze" data-seconds="300"><?php echo _('Snooze 5m'); ?></button>
							<button type="button" class="btn btn-primary rc-snooze" data-seconds="900"><?php echo _('Snooze 15m'); ?></button>
							<button type="button" class="btn btn-primary rc-snooze" data-seconds="1800"><?php echo _('Snooze 30m'); ?></button>
							<button type="button" class="btn btn-primary rc-snooze" data-seconds="3600"><?php echo _('Snooze 1h'); ?></button>
							<button type="button" class="btn btn-primary rc-snooze" data-seconds="10800"><?php echo _('Snooze 3h'); ?></button>
							<button type="button" class="btn btn-primary rc-snooze" data-seconds="21600"><?php echo _('Snooze 6h'); ?></button>
							<button type="button" class="btn btn-primary rc-snooze" data-seconds="43200"><?php echo _('Snooze 12h'); ?></button>
							<button type="button" class="btn btn-primary rc-snooze" data-seconds="86400"><?php echo _('Snooze 24h'); ?></button>
						</span>
						<button type="button" class="btn btn-warning" id="rc-run-now"<?php echo $initialRunNowEnabled ? '' : ' disabled'; ?>><?php echo _('Run Now'); ?></button>
					</div>
				</div>
			</div>
		</div>
	</div>

	<div class="row rc-section">
		<div class="col-sm-12"><div class="panel panel-default"><div class="panel-heading"><h3 class="panel-title"><?php echo _('Active Incidents'); ?></h3></div><div class="panel-body"><div class="table-responsive"><table class="table table-striped table-condensed" id="rc-active-incidents-table"><thead><tr><th>ID</th><th><?php echo _('First'); ?></th><th><?php echo _('Rule'); ?></th><th><?php echo _('Mode'); ?></th><th><?php echo _('Subject'); ?></th><th><?php echo _('Caller'); ?></th><th><?php echo _('Last'); ?></th><th><?php echo _('Count'); ?></th><th><?php echo _('Threshold'); ?></th><th><?php echo _('Window'); ?></th><th><?php echo _('Status'); ?></th><th><?php echo _('Updated'); ?></th><th><?php echo _('Action'); ?></th></tr></thead><tbody></tbody></table></div></div></div></div>
	</div>

	<div class="row rc-section">
			<div class="col-sm-12"><div class="panel panel-default"><div class="panel-heading"><h3 class="panel-title"><?php echo _('Suppressed Incidents'); ?></h3></div><div class="panel-body"><div class="table-responsive"><table class="table table-striped table-condensed" id="rc-suppressed-incidents-table"><thead><tr><th><?php echo _('Time'); ?></th><th><?php echo _('Rule'); ?></th><th><?php echo _('Mode'); ?></th><th><?php echo _('Subject'); ?></th><th><?php echo _('Count'); ?></th><th><?php echo _('Threshold / Window'); ?></th><th><?php echo _('Suppressed Until'); ?></th><th><?php echo _('Cleared'); ?></th><th><?php echo _('Reason'); ?></th><th><?php echo _('Related Incident'); ?></th><th><?php echo _('Action'); ?></th></tr></thead><tbody></tbody></table></div></div></div></div>
	</div>

	<div class="row rc-section">
		<div class="col-sm-12">
			<div class="panel panel-default">
				<div class="panel-heading"><h3 class="panel-title"><?php echo _('Global Settings'); ?></h3></div>
				<div class="panel-body">
					<?php $activeAlertCallLanguage = array_values(array_filter((array)($alertCallLanguageSupport['rows'] ?? []), function (array $row): bool { return !empty($row['active']); })); ?>
					<p><strong><?php echo _('Active FreePBX Language'); ?>:</strong> <span id="rc-active-freepbx-language"><?php echo htmlspecialchars((string)($activeAlertCallLanguage[0]['language'] ?? _('Unknown')), ENT_QUOTES, 'UTF-8'); ?></span> <button type="button" class="btn btn-xs btn-default" id="rc-refresh-alert-call-language" title="<?php echo htmlspecialchars(_('Refresh the detected language status.'), ENT_QUOTES, 'UTF-8'); ?>"><span class="fa fa-refresh" aria-hidden="true"></span> <span class="rc-refresh-label"><?php echo _('Refresh'); ?></span></button></p>
					<p class="help-block"><?php echo htmlspecialchars(_('Languages are detected from locale directories under /var/lib/asterisk/sounds, based on audio files Repeat Caller can actually use. This is not the list of available or downloadable FreePBX language packs.'), ENT_QUOTES, 'UTF-8'); ?></p>
					<p class="help-block"><?php echo htmlspecialchars(_('If a language pack has been removed but still appears in the Alert Call language table, check /var/lib/asterisk/sounds for leftover locale directories. Remove /var/lib/asterisk/sounds/<locale> if that language should no longer appear.'), ENT_QUOTES, 'UTF-8'); ?></p>
					<p class="help-block"><?php echo htmlspecialchars(_('Native and Original uses a complete required prompt set from the original voice pack. Native but Adapted uses a complete required prompt set from an adapted or localised voice pack. Rejected → Fallback means the locale was evaluated and did not meet the requirements. Untested → Fallback means the local locale has not yet been evaluated. For both fallback states, the Alert Call Language shown will be used.'), ENT_QUOTES, 'UTF-8'); ?></p>
					<div class="table-responsive">
						<table class="table table-condensed" id="rc-alert-call-language-table">
							<thead><tr><th><?php echo _('Language'); ?></th><th><?php echo _('Locale'); ?></th><th title="<?php echo htmlspecialchars(_('Shows only the audio codecs available for the required Alert Call prompts, not all codecs found in the language directory.'), ENT_QUOTES, 'UTF-8'); ?>"><?php echo _('Available Codecs'); ?></th><th><?php echo _('Status'); ?></th><th><?php echo _('Alert Call Language'); ?></th><th><?php echo _('Sample'); ?></th></tr></thead>
								<tbody>
									<?php foreach ((array)($alertCallLanguageSupport['rows'] ?? []) as $languageStatus): ?>
										<tr<?php echo !empty($languageStatus['active']) ? ' class="info"' : ''; ?>>
											<td><?php echo htmlspecialchars((string)($languageStatus['language'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
											<td><?php echo htmlspecialchars((string)($languageStatus['locale'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
											<td><?php $availableCodecs = array_values((array)($languageStatus['available_codecs'] ?? [])); echo htmlspecialchars($availableCodecs === [] ? '-' : implode(', ', $availableCodecs), ENT_QUOTES, 'UTF-8'); ?></td>
											<td><?php if (!empty($languageStatus['active'])): ?><strong><?php echo htmlspecialchars((string)($languageStatus['status'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></strong><?php else: ?><?php echo htmlspecialchars((string)($languageStatus['status'] ?? ''), ENT_QUOTES, 'UTF-8'); ?><?php endif; ?></td>
											<td><?php echo htmlspecialchars((string)($languageStatus['alert_call_language'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
											<td><button type="button" class="btn btn-xs btn-default rc-alert-call-language-sample" data-language="<?php echo htmlspecialchars((string)($languageStatus['sample_locale'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" data-sample-available="<?php echo !empty($languageStatus['sample_available']) ? '1' : '0'; ?>" title="<?php echo htmlspecialchars(_('Play Alert Call language sample.'), ENT_QUOTES, 'UTF-8'); ?>" aria-label="<?php echo htmlspecialchars(_('Play Alert Call language sample.'), ENT_QUOTES, 'UTF-8'); ?>"<?php echo empty($languageStatus['sample_available']) ? ' disabled' : ''; ?>><span class="fa fa-play" aria-hidden="true"></span></button></td>
										</tr>
									<?php endforeach; ?>
								</tbody>
						</table>
					</div>
					<div class="row">
						<div class="col-sm-3">
							<label><?php echo _('Default Country Code'); ?></label>
							<input type="text" id="rc-setting-country" class="form-control" value="<?php echo htmlspecialchars((string)($globalSettings['default_country_code'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" placeholder="44">
							<p class="help-block"><?php echo _('Normalizes local caller numbers before matching rules (for example, 44 for UK numbers).'); ?></p>
						</div>
						<div class="col-sm-3">
							<label><?php echo _('Prune Incident History'); ?></label>
							<select id="rc-setting-incident-prune" class="form-control">
								<option value="never" <?php echo (string)($globalSettings['incident_history_prune_policy'] ?? 'daily') === 'never' ? 'selected' : ''; ?>><?php echo _('Never'); ?></option>
								<option value="hourly" <?php echo (string)($globalSettings['incident_history_prune_policy'] ?? 'daily') === 'hourly' ? 'selected' : ''; ?>><?php echo _('Hourly'); ?></option>
								<option value="daily" <?php echo (string)($globalSettings['incident_history_prune_policy'] ?? 'daily') === 'daily' ? 'selected' : ''; ?>><?php echo _('Daily'); ?></option>
								<option value="weekly" <?php echo (string)($globalSettings['incident_history_prune_policy'] ?? 'daily') === 'weekly' ? 'selected' : ''; ?>><?php echo _('Weekly'); ?></option>
								<option value="monthly" <?php echo (string)($globalSettings['incident_history_prune_policy'] ?? 'daily') === 'monthly' ? 'selected' : ''; ?>><?php echo _('Monthly'); ?></option>
								<option value="yearly" <?php echo (string)($globalSettings['incident_history_prune_policy'] ?? 'daily') === 'yearly' ? 'selected' : ''; ?>><?php echo _('Yearly'); ?></option>
							</select>
							<p class="help-block"><?php echo _('Removes old completed incident records. Active incidents are not affected.'); ?></p>
						</div>
						<div class="col-sm-3">
							<label><?php echo _('Prune Suppression History'); ?></label>
							<select id="rc-setting-suppression-prune" class="form-control">
								<option value="never" <?php echo (string)($globalSettings['suppression_history_prune_policy'] ?? 'daily') === 'never' ? 'selected' : ''; ?>><?php echo _('Never'); ?></option>
								<option value="hourly" <?php echo (string)($globalSettings['suppression_history_prune_policy'] ?? 'daily') === 'hourly' ? 'selected' : ''; ?>><?php echo _('Hourly'); ?></option>
								<option value="daily" <?php echo (string)($globalSettings['suppression_history_prune_policy'] ?? 'daily') === 'daily' ? 'selected' : ''; ?>><?php echo _('Daily'); ?></option>
								<option value="weekly" <?php echo (string)($globalSettings['suppression_history_prune_policy'] ?? 'daily') === 'weekly' ? 'selected' : ''; ?>><?php echo _('Weekly'); ?></option>
								<option value="monthly" <?php echo (string)($globalSettings['suppression_history_prune_policy'] ?? 'daily') === 'monthly' ? 'selected' : ''; ?>><?php echo _('Monthly'); ?></option>
								<option value="yearly" <?php echo (string)($globalSettings['suppression_history_prune_policy'] ?? 'daily') === 'yearly' ? 'selected' : ''; ?>><?php echo _('Yearly'); ?></option>
							</select>
							<p class="help-block"><?php echo _('Removes old suppression audit records. Current suppression state is not affected.'); ?></p>
						</div>
						<div class="col-sm-3">
							<label><?php echo _('Prune Alert History'); ?></label>
							<select id="rc-setting-alert-prune" class="form-control">
								<option value="never" <?php echo (string)($globalSettings['alert_history_prune_policy'] ?? 'daily') === 'never' ? 'selected' : ''; ?>><?php echo _('Never'); ?></option>
								<option value="hourly" <?php echo (string)($globalSettings['alert_history_prune_policy'] ?? 'daily') === 'hourly' ? 'selected' : ''; ?>><?php echo _('Hourly'); ?></option>
								<option value="daily" <?php echo (string)($globalSettings['alert_history_prune_policy'] ?? 'daily') === 'daily' ? 'selected' : ''; ?>><?php echo _('Daily'); ?></option>
								<option value="weekly" <?php echo (string)($globalSettings['alert_history_prune_policy'] ?? 'daily') === 'weekly' ? 'selected' : ''; ?>><?php echo _('Weekly'); ?></option>
								<option value="monthly" <?php echo (string)($globalSettings['alert_history_prune_policy'] ?? 'daily') === 'monthly' ? 'selected' : ''; ?>><?php echo _('Monthly'); ?></option>
								<option value="yearly" <?php echo (string)($globalSettings['alert_history_prune_policy'] ?? 'daily') === 'yearly' ? 'selected' : ''; ?>><?php echo _('Yearly'); ?></option>
							</select>
							<p class="help-block"><?php echo _('Removes old email and Alert Call delivery records. Incident records are not affected.'); ?></p>
						</div>
					</div>
					<div class="rc-actions">
						<button type="button" class="btn btn-primary" id="rc-save-global"><?php echo _('Save Global Settings'); ?></button>
						<button type="button" class="btn btn-warning" id="rc-prune-now"><?php echo _('Run Pruning Now'); ?></button>
						<button type="button" class="btn btn-danger" id="rc-clear-alert-history"><?php echo _('Clear Alert History'); ?></button>
					</div>
				</div>
			</div>
		</div>
	</div>

	<div class="row rc-section">
		<div class="col-sm-12">
			<div class="panel panel-default">
				<div class="panel-heading"><h3 class="panel-title"><?php echo _('Rules'); ?></h3></div>
				<div class="panel-body">
					<div class="table-responsive">
						<table class="table table-striped table-condensed" id="rc-rules-table">
							<thead><tr><th><?php echo _('Name'); ?></th><th><?php echo _('Enabled'); ?></th><th><?php echo _('Mode'); ?></th><th><?php echo _('Threshold/Window'); ?></th><th><?php echo _('Caller Scope'); ?></th><th><?php echo _('DID Scope'); ?></th><th><?php echo _('Schedule'); ?></th><th><?php echo _('Actions'); ?></th><th><?php echo _('Recording'); ?></th><th><?php echo _('Repeat'); ?></th><th><?php echo _('Suppression'); ?></th><th><?php echo _('Active Incidents'); ?></th><th><?php echo _('Controls'); ?></th></tr></thead>
							<tbody></tbody>
						</table>
					</div>
					<div class="panel panel-default rc-editor-panel">
						<div class="panel-heading"><h4 class="panel-title" id="rc-editor-title"><?php echo _('Add Rule'); ?></h4></div>
						<div class="panel-body">
							<input type="hidden" id="rc-rule-id" value="0">
							<div class="row">
								<div class="col-sm-4"><label><?php echo _('Rule Name'); ?></label><input type="text" class="form-control" id="rc-rule-name"></div>
								<div class="col-sm-2" id="rc-rule-start-as-col"><label><?php echo _('Start as'); ?></label><div><label><input type="checkbox" id="rc-rule-enabled" checked> <?php echo _('Enabled'); ?></label></div><p class="help-block" id="rc-rule-start-as-help"><?php echo _('Choose whether the new rule should start enabled or disabled.'); ?></p></div>
								<div class="col-sm-2"><label><?php echo _('Mode'); ?></label><select id="rc-rule-mode" class="form-control"><option value="repeat"><?php echo _('Repeat'); ?></option><option value="invert"><?php echo _('Invert'); ?></option></select></div>
								<div class="col-sm-2"><label><?php echo _('Threshold'); ?></label><input type="number" id="rc-rule-threshold" class="form-control" min="1" value="2"></div>
								<div class="col-sm-2"><label><?php echo _('Window (min)'); ?></label><input type="number" id="rc-rule-window" class="form-control" min="1" value="60"></div>
							</div>
							<div class="row rc-row-gap">
									<div class="col-sm-3"><label><?php echo _('Suppression'); ?></label><input type="number" id="rc-rule-suppression" class="form-control" min="0" placeholder="<?php echo _('Default 24hrs'); ?>"><p class="help-block"><?php echo _('Leave blank to use Default 24hrs suppression period or enter 0 to disable.'); ?></p></div>
								<div class="col-sm-3"><label><?php echo _('Alert Reminders'); ?></label><select id="rc-rule-alert-reminder" class="form-control"><?php foreach ($repeatModes as $value => $label): ?><option value="<?php echo htmlspecialchars($value, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($label, ENT_QUOTES, 'UTF-8'); ?></option><?php endforeach; ?></select></div>
								<div class="col-sm-3"><label><?php echo _('Caller Scope'); ?></label><select id="rc-rule-caller-mode" class="form-control"><option value="any"><?php echo _('Any caller'); ?></option><option value="withheld_only"><?php echo _('Withheld only'); ?></option><option value="specific_only"><?php echo _('Specific callers'); ?></option></select></div>
								<div class="col-sm-3" style="margin-top: 32px;"><label><input type="checkbox" id="rc-rule-exclude-withheld"> <?php echo _('Exclude withheld callers'); ?></label></div>
							</div>
							<div class="row rc-row-gap">
							</div>
							<div class="row rc-row-gap"><div class="col-sm-6"><label><?php echo _('Only monitor these callers'); ?></label><textarea id="rc-rule-caller-include" class="form-control" rows="4" placeholder="07812345678"></textarea><p class="help-block" id="rc-caller-include-help"><?php echo _('Enter caller numbers separated by spaces, commas or new lines. Mixed separators are supported. Values are saved as a comma-separated list.'); ?></p><p class="help-block hidden" id="rc-caller-include-unavailable"></p></div><div class="col-sm-6"><label><?php echo _('Ignore these callers'); ?></label><textarea id="rc-rule-caller-exclude" class="form-control" rows="4" placeholder="07812345679"></textarea><p class="help-block" id="rc-caller-exclude-help"><?php echo _('Enter caller numbers separated by spaces, commas or new lines. Mixed separators are supported. Values are saved as a comma-separated list.'); ?></p><p class="help-block hidden" id="rc-caller-exclude-unavailable"></p></div></div>
							<div class="row rc-row-gap"><div class="col-sm-3"><label><?php echo _('DID Scope'); ?></label><select id="rc-rule-did-mode" class="form-control"><option value="all"><?php echo _('All DIDs'); ?></option><option value="selected"><?php echo _('Selected DIDs only'); ?></option></select></div><div class="col-sm-6"><label><?php echo _('Inbound Routes'); ?></label><select id="rc-route-pick" class="form-control"></select></div><div class="col-sm-3" id="rc-did-route-actions-col"><label><?php echo _('Route Actions'); ?></label><div class="rc-route-actions"><button type="button" class="btn btn-xs btn-default" id="rc-add-did-include"><?php echo _('Include Route'); ?></button> <button type="button" class="btn btn-xs btn-default" id="rc-add-did-exclude"><?php echo _('Exclude Route'); ?></button></div></div></div>
							<div class="row rc-row-gap"><div class="col-sm-6" id="rc-did-include-col"><label><?php echo _('Included Routes'); ?></label><ul id="rc-did-include-list" class="rc-list"></ul></div><div class="col-sm-6" id="rc-did-exclude-col"><label><?php echo _('Excluded Routes'); ?></label><ul id="rc-did-exclude-list" class="rc-list"></ul></div></div>
							<div class="row rc-row-gap"><div class="col-sm-12"><label><?php echo _('Schedules'); ?></label><table class="table table-condensed" id="rc-schedule-table"><thead><tr><th><?php echo _('Day'); ?></th><th><?php echo _('Start'); ?></th><th><?php echo _('End'); ?></th><th></th></tr></thead><tbody></tbody></table><button type="button" class="btn btn-xs btn-default" id="rc-add-schedule"><?php echo _('Add Schedule Period'); ?></button><p class="help-block"><?php echo _('Overnight periods are rejected in this phase to avoid ambiguous schedule semantics.'); ?></p></div></div>
												<div class="row rc-row-gap rc-alert-call-row"><div class="col-sm-3 rc-alert-call-actions-col"><label><?php echo _('Actions'); ?></label><div><label><input type="checkbox" checked disabled> <?php echo _('GUI (always enabled)'); ?></label></div><div><label><input type="checkbox" id="rc-rule-alert-call-enabled"> <?php echo _('Alert Call'); ?></label></div><div><label><input type="checkbox" id="rc-rule-email-enabled"> <?php echo _('Email'); ?></label></div><div class="rc-row-gap"><label><?php echo _('Alert Call Strategy'); ?></label><select id="rc-rule-alert-call-strategy" class="form-control"><option value="ringall"><?php echo _('Ring All'); ?></option><option value="ordered"><?php echo _('Ordered'); ?></option></select></div></div><div class="col-sm-6 rc-alert-call-destination-col rc-alert-call-destination-col-wide"><label><?php echo _('Alert Call Destinations'); ?></label><input type="hidden" id="rc-rule-alert-call-destinations" value=""><div class="input-group"><input type="text" id="rc-rule-alert-call-destination-input" class="form-control" placeholder="2001, 2002, 07812345678"><span class="input-group-btn"><button type="button" class="btn btn-default" id="rc-rule-alert-call-destination-add"><?php echo _('Add'); ?></button></span></div><ol id="rc-rule-alert-call-destination-list" class="rc-list rc-alert-call-destination-list"></ol><p class="help-block"><?php echo _('Drag to reorder using the handle. The numbered order is persisted. Ordered stages add one destination at a time and only retain earlier destinations in later stages when their own Keep Trying option is enabled. Ring All contacts every enabled destination on every cycle. Keep Trying is disabled for Ring All.'); ?></p></div><div class="col-sm-3 rc-alert-call-right-col"><div class="rc-alert-call-recording-col"><label><?php echo _('System Recording'); ?></label><select id="rc-rule-alert-call-recording-id" class="form-control"><option value=""><?php echo _('None'); ?></option><?php foreach ($systemRecordings as $recording): $recordingId = isset($recording['id']) ? (int)$recording['id'] : 0; if ($recordingId <= 0) { continue; } $recordingName = trim((string)($recording['name'] ?? '')); if ($recordingName === '') { $recordingName = sprintf(_('Recording #%d'), $recordingId); } ?><option value="<?php echo $recordingId; ?>"><?php echo htmlspecialchars($recordingName, ENT_QUOTES, 'UTF-8'); ?></option><?php endforeach; ?></select><p class="help-block"><?php echo _('Optionally play a System Recording before the generated alert message. Default: None.'); ?></p></div><div class="rc-row-gap rc-alert-call-callerid-upstream-col"><div class="checkbox"><label><input type="checkbox" id="rc-rule-alert-call-handle-callerid-upstream" checked> <?php echo _('Caller ID will be managed elsewhere'); ?></label></div></div><div class="rc-row-gap rc-alert-call-callerid-col"><label><?php echo _('Alert Call Caller ID'); ?></label><input type="text" id="rc-rule-alert-call-callerid" class="form-control" placeholder="+441234567890"><p class="help-block" id="rc-rule-alert-call-callerid-help"><?php echo _('Not used while Caller ID will be managed elsewhere is enabled.'); ?></p></div></div></div>
							<div class="row rc-row-gap"><div class="col-sm-12"><label><?php echo _('Email Recipients'); ?></label><input type="text" id="rc-rule-email-recipients" class="form-control" placeholder="your@demodomain.name, my@demodomain.name" title="your@demodomain.name, my@demodomain.name"><p class="help-block"><?php echo _('Use commas to separate multiple email addresses.'); ?></p></div></div>
							<div class="rc-actions rc-rule-actions"><button type="button" class="btn btn-primary" id="rc-save-rule"><?php echo _('Save Rule'); ?></button><button type="button" class="btn btn-danger hidden" id="rc-cancel-edit"><?php echo _('Cancel Edit'); ?></button></div>
						</div>
					</div>
				</div>
			</div>
		</div>
	</div>

	<div class="row rc-section">
		<div class="col-sm-12"><div class="panel panel-default"><div class="panel-heading"><h3 class="panel-title"><?php echo _('Recent Incidents'); ?></h3></div><div class="panel-body"><div class="table-responsive"><table class="table table-striped table-condensed" id="rc-recent-incidents-table"><thead><tr><th>ID</th><th><?php echo _('Created'); ?></th><th><?php echo _('Rule'); ?></th><th><?php echo _('Mode'); ?></th><th><?php echo _('Subject'); ?></th><th><?php echo _('Status'); ?></th><th><?php echo _('Accepted By'); ?></th><th><?php echo _('Suppression Expires'); ?></th><th><?php echo _('Updated'); ?></th></tr></thead><tbody></tbody></table></div></div></div></div>
	</div>

	<div class="row rc-section">
		<div class="col-sm-12"><div class="panel panel-default"><div class="panel-heading"><h3 class="panel-title"><?php echo _('Alert History'); ?></h3></div><div class="panel-body"><div class="table-responsive"><table class="table table-striped table-condensed" id="rc-alert-history-table"><thead><tr><th><?php echo _('ID'); ?></th><th><?php echo _('Time'); ?></th><th><?php echo _('Rule'); ?></th><th><?php echo _('Mode'); ?></th><th><?php echo _('Subject'); ?></th><th><?php echo _('Event'); ?></th><th><?php echo _('Action'); ?></th><th><?php echo _('Status'); ?></th><th><?php echo _('Stage'); ?></th><th><?php echo _('Success'); ?></th><th><?php echo _('Failure Detail'); ?></th></tr></thead><tbody></tbody></table></div></div></div></div>
	</div>

	<script>
		window.repeatCallerBootstrap = {
			engineStatus: <?php echo json_encode($engineStatus); ?>,
			globalSettings: <?php echo json_encode($globalSettings); ?>,
			rules: <?php echo json_encode($rules); ?>,
			activeIncidents: <?php echo json_encode($activeIncidents); ?>,
			recentIncidents: <?php echo json_encode($recentIncidents); ?>,
			alertHistory: <?php echo json_encode($alertHistory); ?>,
			suppressedIncidents: <?php echo json_encode($suppressedIncidents); ?>,
			inboundRoutes: <?php echo json_encode($inboundRoutes); ?>,
			systemRecordings: <?php echo json_encode($systemRecordings); ?>
		};
	</script>
	<script src="modules/repeatcaller/assets/js/repeatcaller.js?v=<?php echo $assetVer; ?>"></script>
	</div>
</div>

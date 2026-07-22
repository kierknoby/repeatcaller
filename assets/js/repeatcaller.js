(function ($) {
	'use strict';

	var refreshState = {
		lastTokens: {
			activeIncidents: '',
			claimedIncidents: '',
			suppressedIncidents: '',
			alertHistory: '',
			engineStatus: ''
		},
		pollInFlight: false,
		timerId: null,
		actionInFlightCount: 0
	};

	var liveClocks = {
		pbx: {baseMs: null, receivedAtMs: 0, selector: '#rc-pbx-time'}
	};
	var liveClockIntervalId = null;
	var runStatusUi = {
		minimumVisibleMs: 3000,
		runningVisibleSinceMs: 0,
		holdTimerId: null,
		backendRunning: false
	};
	var systemRecordingsById = {};
	var currentRulesById = {};
	var currentActiveIncidents = [];
	var currentRecentIncidents = [];
	var currentSuppressedIncidents = [];
	var currentAlertHistory = [];
	var currentEngineLastSuccessfulRun = '';
	var tableBatchSize = 15;
	var tableBatchStates = {};
	var tableBatchSelectors = [
		'#rc-rules-table',
		'#rc-active-incidents-table',
		'#rc-suppressed-incidents-table',
		'#rc-recent-incidents-table',
		'#rc-alert-history-table',
		'#rc-schedule-table'
	];
	var ruleStatusTimers = {};

	// Country caller number formats for help text examples
	// Country caller formats - country code maps to country name and preferred local format example
	// The backend accepts all three formats (local, international without +, international with +)
	// The UI hint shows only the preferred local format to guide users
	var countryCallerFormats = {
		'44': { name: 'UK', local: '07812345678' },
		'1': { name: 'US/Canada', local: '2125551234' },
		'61': { name: 'Australian', local: '0412345678' },
		'64': { name: 'New Zealand', local: '0211234567' },
		'353': { name: 'Irish', local: '0871234567' },
		'27': { name: 'South African', local: '0821234567' },
		'33': { name: 'French', local: '0612345678' },
		'49': { name: 'German', local: '015112345678' },
		'31': { name: 'Dutch', local: '0612345678' },
		'32': { name: 'Belgian', local: '0470123456' },
		'41': { name: 'Swiss', local: '0791234567' },
		'43': { name: 'Austrian', local: '06641234567' },
		'351': { name: 'Portuguese', local: '0912345678' },
		'34': { name: 'Spanish', local: '612345678' },
		'39': { name: 'Italian', local: '3331234567' },
		'46': { name: 'Swedish', local: '0701234567' },
		'358': { name: 'Finnish', local: '0401234567' },
		'48': { name: 'Polish', local: '501234567' },
		'91': { name: 'Indian', local: '9876543210' },
		'81': { name: 'Japanese', local: '09012345678' },
		'86': { name: 'Chinese', local: '13812345678' },
		'65': { name: 'Singapore', local: '91234567' },
		'55': { name: 'Brazilian', local: '11987654321' },
		'971': { name: 'UAE', local: '0501234567' },
		'966': { name: 'Saudi Arabian', local: '0501234567' }
	};

	function getCallerFormatHint(countryCode) {
		var code = $.trim(String(countryCode || ''));
		var format = countryCallerFormats[code];
		if (!format) {
			return 'Enter caller numbers in local or international format.';
		}
		return 'Enter caller numbers in ' + format.name + ' format, e.g. ' + format.local;
	}

	function getCallerFormatExample(countryCode) {
		var code = $.trim(String(countryCode || ''));
		var format = countryCallerFormats[code];
		if (!format) {
			return '01234567890';
		}
		var localNumber = String(format.local || '');
		// If local number starts with 0 (trunk prefix), use as-is; leading 0 provides country context
		// Otherwise, prepend country code to make context explicit
		if (localNumber.charAt(0) === '0') {
			return localNumber;
		}
		return code + localNumber;
	}

	function getCallerE164Example(countryCode) {
		var code = $.trim(String(countryCode || ''));
		var format = countryCallerFormats[code];
		if (!format) {
			return '+441234567890';
		}
		// Convert local format to E.164 by removing leading 0 and prepending +<countrycode>
		var localNumber = String(format.local || '');
		var nationalNumber = localNumber.replace(/^0+/, '');
		if (!nationalNumber) {
			nationalNumber = localNumber;
		}
		return '+' + code + nationalNumber;
	}

	function clearRunStatusHoldTimer() {
		if (runStatusUi.holdTimerId !== null) {
			window.clearTimeout(runStatusUi.holdTimerId);
			runStatusUi.holdTimerId = null;
		}
	}

	function setRunStatusText(text) {
		$('#rc-lock-state').text(text);
	}

	function isProcessingVisible() {
		if (runStatusUi.backendRunning) {
			return true;
		}
		if (runStatusUi.runningVisibleSinceMs === 0) {
			return false;
		}
		return (Date.now() - runStatusUi.runningVisibleSinceMs) < runStatusUi.minimumVisibleMs;
	}

	function updateRunNowButtonState() {
		var $button = $('#rc-run-now');
		if (!$button.length) {
			return;
		}
		if (isProcessingVisible()) {
			$button.prop('disabled', true).addClass('disabled');
			return;
		}
		$button.prop('disabled', false).removeClass('disabled');
	}

	function showRunStatusRunning() {
		if (runStatusUi.runningVisibleSinceMs === 0) {
			runStatusUi.runningVisibleSinceMs = Date.now();
		}
		clearRunStatusHoldTimer();
		setRunStatusText('Processing');
		updateRunNowButtonState();
	}

	function refreshRunStatusDisplay() {
		if (runStatusUi.backendRunning) {
			showRunStatusRunning();
			return;
		}

		if (runStatusUi.runningVisibleSinceMs === 0) {
			setRunStatusText('Waiting');
			updateRunNowButtonState();
			return;
		}

		var elapsedMs = Date.now() - runStatusUi.runningVisibleSinceMs;
		if (elapsedMs >= runStatusUi.minimumVisibleMs) {
			runStatusUi.runningVisibleSinceMs = 0;
			clearRunStatusHoldTimer();
			setRunStatusText('Waiting');
			updateRunNowButtonState();
			return;
		}

		setRunStatusText('Processing');
		updateRunNowButtonState();
		clearRunStatusHoldTimer();
		runStatusUi.holdTimerId = window.setTimeout(function () {
			runStatusUi.holdTimerId = null;
			refreshRunStatusDisplay();
		}, runStatusUi.minimumVisibleMs - elapsedMs);
	}

	function updateRunStatusFromEngine(lockState) {
		runStatusUi.backendRunning = String(lockState || '').toLowerCase() === 'running';
		refreshRunStatusDisplay();
	}

	function setRunStatusRunningFromRunStart() {
		showRunStatusRunning();
	}

	function loadSystemRecordingsLookupFromBootstrap() {
		var rows = (window.repeatCallerBootstrap && window.repeatCallerBootstrap.systemRecordings) || [];
		systemRecordingsById = {};
		$.each(rows, function (_, row) {
			if (!row || typeof row !== 'object') {
				return;
			}
			var id = parseInt(row.id || 0, 10);
			var name = $.trim(String(row.name || ''));
			if (!id || id < 1 || name === '') {
				return;
			}
			systemRecordingsById[String(id)] = name;
		});
	}

	function formatClockYmdHms(msValue) {
		var dt = new Date(msValue);
		function pad2(n) {
			return n < 10 ? '0' + n : String(n);
		}
		return dt.getUTCFullYear()
			+ '-' + pad2(dt.getUTCMonth() + 1)
			+ '-' + pad2(dt.getUTCDate())
			+ ' ' + pad2(dt.getUTCHours())
			+ ':' + pad2(dt.getUTCMinutes())
			+ ':' + pad2(dt.getUTCSeconds());
	}

	function formatDisplayDateTime(rawValue) {
		var value = $.trim(String(rawValue || ''));
		var monthNames = [
			'January', 'February', 'March', 'April', 'May', 'June',
			'July', 'August', 'September', 'October', 'November', 'December'
		];
		var match = value.match(/^(\d{4})-(\d{2})-(\d{2})(?:[ T](\d{2}):(\d{2})(?::(\d{2}))?)?$/);
		if (!match) {
			return value === '' ? '-' : value;
		}
		var monthIndex = parseInt(match[2], 10) - 1;
		if (monthIndex < 0 || monthIndex >= monthNames.length) {
			return value;
		}
		var day = parseInt(match[3], 10);
		var hour = match[4] || '00';
		var minute = match[5] || '00';
		return day + ' ' + monthNames[monthIndex] + ' ' + match[1] + ' ' + hour + ':' + minute;
	}

	function parseClockYmdHms(rawValue) {
		var value = String(rawValue || '').trim();
		var match = value.match(/^(\d{4})-(\d{2})-(\d{2}) (\d{2}):(\d{2}):(\d{2})$/);
		if (!match) {
			return null;
		}
		return Date.UTC(
			parseInt(match[1], 10),
			parseInt(match[2], 10) - 1,
			parseInt(match[3], 10),
			parseInt(match[4], 10),
			parseInt(match[5], 10),
			parseInt(match[6], 10)
		);
	}

	function renderLiveClock(key) {
		var clock = liveClocks[key];
		if (!clock || clock.baseMs === null) {
			return;
		}
		var elapsedSeconds = Math.floor((Date.now() - clock.receivedAtMs) / 1000);
		$(clock.selector).text(formatClockYmdHms(clock.baseMs + (elapsedSeconds * 1000)));
	}

	function renderLiveClocks() {
		renderLiveClock('pbx');
	}

	function syncLiveClockFromValue(key, rawValue, shouldRenderNow) {
		var clock = liveClocks[key];
		if (!clock) {
			return;
		}
		var parsedMs = parseClockYmdHms(rawValue);
		if (parsedMs === null) {
			clock.baseMs = null;
			$(clock.selector).text(rawValue || '-');
			return;
		}
		clock.baseMs = parsedMs;
		clock.receivedAtMs = Date.now();
		if (shouldRenderNow) {
			renderLiveClock(key);
		}
		if (liveClockIntervalId === null) {
			liveClockIntervalId = window.setInterval(renderLiveClocks, 1000);
		}
	}

	function normalizeChangeTokens(rawTokens) {
		var t = rawTokens || {};
		return {
			activeIncidents: String(t.activeIncidents || ''),
			claimedIncidents: String(t.claimedIncidents || ''),
			suppressedIncidents: String(t.suppressedIncidents || ''),
			alertHistory: String(t.alertHistory || ''),
			engineStatus: String(t.engineStatus || '')
		};
	}

	function hasTokenBaseline(tokens) {
		return !!(tokens.activeIncidents || tokens.claimedIncidents || tokens.suppressedIncidents || tokens.alertHistory || tokens.engineStatus);
	}

	function token() {
		return String($('.repeatcaller').attr('data-csrf-token') || $('input[name="token"]').val() || '');
	}

	function showMessage(message, level) {
		var text = String(message || '');
		var type = 'info';
		if (level === 'success') {
			type = 'success';
		} else if (level === 'error') {
			type = 'error';
		} else if (level === 'warning') {
			type = 'warning';
		}
		if (window && typeof window.fpbxToast === 'function') {
			try {
				window.fpbxToast(text, '', type);
				$('#rc-message').hide();
				return;
			} catch (e1) {
			}
		}
		var $msg = $('#rc-message');
		$msg.removeClass('alert-success alert-danger alert-info').addClass(level === 'error' ? 'alert-danger' : 'alert-success');
		$msg.text(text).show();
	}

	function ajax(command, payload, done, onComplete, options) {
		options = options || {};
		var silent = !!options.silent;
		payload = payload || {};
		payload.command = command;
		payload.token = token();
		return $.ajax({
			url: 'ajax.php?module=repeatcaller&command=' + encodeURIComponent(command),
			type: 'POST',
			dataType: 'json',
			data: payload
		}).done(function (response) {
			if (!response || response.status !== true) {
				if (!silent) {
					showMessage((response && response.message) || 'Request failed.', 'error');
				}
				if (onComplete) { onComplete(); }
				return;
			}
			done(response);
			if (onComplete) { onComplete(); }
		}).fail(function () {
			if (!silent) {
				showMessage('Request failed.', 'error');
			}
			if (onComplete) { onComplete(); }
		});
	}

	function clearPollTimer() {
		if (refreshState.timerId !== null) {
			window.clearTimeout(refreshState.timerId);
			refreshState.timerId = null;
		}
	}

	function scheduleChangeCheck(delayMs) {
		clearPollTimer();
		refreshState.timerId = window.setTimeout(function () {
			checkForUiChanges();
		}, delayMs);
	}

	function canPollNow() {
		if (document.hidden) {
			return false;
		}
		if (refreshState.pollInFlight) {
			return false;
		}
		if (refreshState.actionInFlightCount > 0) {
			return false;
		}
		return true;
	}

	function beginAction() {
		refreshState.actionInFlightCount += 1;
	}

	function endAction() {
		if (refreshState.actionInFlightCount > 0) {
			refreshState.actionInFlightCount -= 1;
		}
		if (refreshState.actionInFlightCount === 0 && !document.hidden) {
			syncChangeToken();
			scheduleChangeCheck(30000);
		}
	}

	function syncChangeToken() {
		if (document.hidden || refreshState.pollInFlight) {
			return;
		}
		refreshState.pollInFlight = true;
		ajax('getuichangetoken', {}, function (response) {
			refreshState.lastTokens = normalizeChangeTokens(response.changeTokens || {});
		}, function () {
			refreshState.pollInFlight = false;
		}, {silent: true});
	}

	function refreshChangedSections(nextTokens) {
		if (nextTokens.activeIncidents !== refreshState.lastTokens.activeIncidents) {
			loadActiveIncidents({silent: true});
		}
		if (nextTokens.claimedIncidents !== refreshState.lastTokens.claimedIncidents) {
			loadClaimedIncidents({silent: true});
		}
		if (nextTokens.suppressedIncidents !== refreshState.lastTokens.suppressedIncidents) {
			loadSuppressedIncidents({silent: true});
		}
		if (nextTokens.alertHistory !== refreshState.lastTokens.alertHistory) {
			loadAlertHistory({silent: true});
		}
		if (nextTokens.engineStatus !== refreshState.lastTokens.engineStatus) {
			loadEngineStatus({silent: true});
		}
	}

	function checkForUiChanges() {
		if (!canPollNow()) {
			if (document.hidden) {
				clearPollTimer();
				return;
			}
			scheduleChangeCheck(30000);
			return;
		}

		refreshState.pollInFlight = true;
		ajax('getuichangetoken', {}, function (response) {
			var nextTokens = normalizeChangeTokens(response.changeTokens || {});
			if (!hasTokenBaseline(refreshState.lastTokens)) {
				refreshState.lastTokens = nextTokens;
				return;
			}
			if (refreshState.actionInFlightCount === 0) {
				refreshChangedSections(nextTokens);
			}
			refreshState.lastTokens = nextTokens;
		}, function () {
			refreshState.pollInFlight = false;
			if (!document.hidden) {
				scheduleChangeCheck(30000);
			} else {
				clearPollTimer();
			}
		}, {silent: true});
	}

	function setupAutoRefreshPolling() {
		$(document).off('visibilitychange.repeatcaller').on('visibilitychange.repeatcaller', function () {
			if (document.hidden) {
				clearPollTimer();
				return;
			}
			checkForUiChanges();
		});

		if (!document.hidden) {
			checkForUiChanges();
		}
	}

	function withBusy($element, fn) {
		var oldText = $element.text();
		$element.prop('disabled', true).addClass('disabled');
		fn(function () {
			$element.prop('disabled', false).removeClass('disabled');
			$element.text(oldText);
		});
	}

	function scrollToPageTop() {
		$('html, body').stop(true).animate({scrollTop: 0}, 300);
	}

	function scrollToRuleEditor() {
		var $editor = $('.rc-editor-panel');
		if (!$editor.length) {
			return;
		}
		var targetTop = Math.max(0, Math.floor($editor.offset().top) - 60);
		$('html, body').stop(true).animate({scrollTop: targetTop}, 300);
	}

	function esc(v) {
		return $('<div>').text(v === null || v === undefined ? '' : String(v)).html();
	}

	function detectionModeLabel(rawMode) {
		var mode = String(rawMode || '').toLowerCase();
		if (mode === 'repeat') {
			return 'Repeat';
		}
		if (mode === 'invert') {
			return 'Invert';
		}
		return 'Unknown';
	}

	var repeatModeLabels = {
		never: 'Never',
		'5m': 'Every 5 Minutes',
		hourly: 'Hourly',
		daily: 'Daily',
		escalating: 'Escalating',
		fibonacci: 'Escalating'
	};

	var statusLabels = {
		open: 'Open',
		active: 'Open',
		claimed: 'Accepted',
		resolved: 'Resolved',
		suppressed: 'Suppressed',
		expired: 'Expired',
		closed: 'Closed',
		recorded: 'Recorded',
		sent: 'Sent',
		accepted: 'Accepted',
		declined: 'Declined',
		busy: 'Busy',
		no_answer: 'No Answer',
		unreachable: 'Unreachable',
		congestion: 'Congestion',
		failed: 'Failed',
		answered_no_response: 'Answered, No Response'
	};

	function normalizeCode(rawValue) {
		return $.trim(String(rawValue || '')).toLowerCase();
	}

	function titleizeFallback(rawValue) {
		return $.trim(String(rawValue || ''))
			.replace(/[_\-]+/g, ' ')
			.replace(/\s+/g, ' ')
			.replace(/\b\w/g, function (m) { return m.toUpperCase(); });
	}

	function mapCode(rawValue, labels) {
		var code = normalizeCode(rawValue);
		if (code === '') {
			return '';
		}
		return labels[code] || titleizeFallback(code);
	}

	function clearRuleStatusTimers() {
		$.each(ruleStatusTimers, function (ruleId, timerId) {
			if (timerId !== null) {
				window.clearTimeout(timerId);
			}
			delete ruleStatusTimers[ruleId];
		});
	}

	function tableBatchUnitSize(selector) {
		return selector === '#rc-rules-table' ? 2 : 1;
	}

	function ensureTableBatchState(selector) {
		if (!tableBatchStates[selector]) {
			tableBatchStates[selector] = {visibleUnits: tableBatchSize};
		}
		if (!isFinite(tableBatchStates[selector].visibleUnits) || tableBatchStates[selector].visibleUnits < tableBatchSize) {
			tableBatchStates[selector].visibleUnits = tableBatchSize;
		}
		return tableBatchStates[selector];
	}

	function tableBatchUnitsFromRows($rows, unitSize) {
		var units = [];
		var idx;
		for (idx = 0; idx < $rows.length; idx += unitSize) {
			var unitRows = [];
			var offset;
			for (offset = 0; offset < unitSize && (idx + offset) < $rows.length; offset += 1) {
				unitRows.push($rows.get(idx + offset));
			}
			if (unitRows.length) {
				units.push(unitRows);
			}
		}
		return units;
	}

	function tableBatchControlsContainer($table) {
		var tableId = $.trim(String($table.attr('id') || ''));
		if (tableId === '') {
			return $();
		}
		var selector = '.rc-table-batch-controls[data-table-id="' + tableId + '"]';
		var $container = $(selector).first();
		if ($container.length) {
			return $container;
		}
		$container = $('<div class="rc-table-batch-controls"/>').attr('data-table-id', tableId);
		$container
			.append('<button type="button" class="btn btn-xs btn-default rc-table-show-more">Show more</button> ')
			.append('<button type="button" class="btn btn-xs btn-default rc-table-show-less">Show less</button>');
		var $anchor = $table.closest('.table-responsive');
		if (!$anchor.length) {
			$anchor = $table;
		}
		$anchor.after($container);
		return $container;
	}

	function updateTableRowBatching(selector) {
		var $table = $(selector);
		if (!$table.length) {
			return;
		}
		var state = ensureTableBatchState(selector);
		var unitSize = tableBatchUnitSize(selector);
		var $rows = $table.find('tbody > tr');
		var hasEmptyStateOnly = $rows.length === 1 && $rows.first().hasClass('rc-empty-state');
		var $controls = tableBatchControlsContainer($table);

		if (!$rows.length || hasEmptyStateOnly) {
			$rows.show();
			if ($controls.length) {
				$controls.hide();
			}
			state.visibleUnits = tableBatchSize;
			return;
		}

		var units = tableBatchUnitsFromRows($rows, unitSize);
		var totalUnits = units.length;
		if (totalUnits <= tableBatchSize) {
			state.visibleUnits = tableBatchSize;
			$rows.show();
			if ($controls.length) {
				$controls.hide();
			}
			return;
		}

		if (state.visibleUnits > totalUnits) {
			state.visibleUnits = totalUnits;
		}

		$.each(units, function (unitIndex, unitRows) {
			var shouldShow = unitIndex < state.visibleUnits;
			$.each(unitRows, function (_, rowElement) {
				$(rowElement).toggle(shouldShow);
			});
		});

		if ($controls.length) {
			$controls.find('.rc-table-show-more').toggle(state.visibleUnits < totalUnits);
			$controls.find('.rc-table-show-less').toggle(state.visibleUnits > tableBatchSize);
			$controls.show();
		}
	}

	function updateAllTableRowBatching() {
		$.each(tableBatchSelectors, function (_, selector) {
			updateTableRowBatching(selector);
		});
	}

	function rememberRules(rules) {
		currentRulesById = {};
		$.each(rules || [], function (_, rule) {
			var ruleId = parseInt(rule && rule.id || 0, 10);
			if (ruleId > 0) {
				currentRulesById[String(ruleId)] = rule;
			}
		});
	}

	function rememberIncidents(selector, incidents) {
		var rows = (incidents || []).slice();
		if (selector === '#rc-active-incidents-table') {
			currentActiveIncidents = rows;
			return;
		}
		if (selector === '#rc-recent-incidents-table') {
			currentRecentIncidents = rows;
		}
	}

	function rememberSuppressedIncidents(items) {
		currentSuppressedIncidents = (items || []).slice();
	}

	function rememberAlertHistory(items) {
		currentAlertHistory = (items || []).slice();
	}

	function sameRuleId(item, ruleId) {
		return parseInt(item && item.rule_id || 0, 10) === parseInt(ruleId || 0, 10);
	}

	function latestItemForRule(items, ruleId) {
		var latest = null;
		$.each(items || [], function (_, item) {
			if (!sameRuleId(item, ruleId)) {
				return;
			}
			if (latest === null) {
				latest = item;
				return;
			}
			var leftKey = String(item.last_matched_at || item.updated_at || item.detected_at || item.created_at || '');
			var rightKey = String(latest.last_matched_at || latest.updated_at || latest.detected_at || latest.created_at || '');
			if (leftKey > rightKey || (leftKey === rightKey && parseInt(item.id || 0, 10) > parseInt(latest.id || 0, 10))) {
				latest = item;
			}
		});
		return latest;
	}

	function latestItemForRuleSubject(items, ruleId, subjectKey, allowedStates, excludeRuleId) {
		var latest = null;
		var normalizedSubject = normalizeCode(subjectKey || '');
		var statesSet = {};
		$.each(allowedStates || [], function (_, stateValue) {
			statesSet[normalizeCode(stateValue)] = true;
		});
		$.each(items || [], function (_, item) {
			var itemRuleId = parseInt(item && item.rule_id || 0, 10);
			if (parseInt(ruleId || 0, 10) > 0 && itemRuleId !== parseInt(ruleId || 0, 10)) {
				return;
			}
			if (parseInt(excludeRuleId || 0, 10) > 0 && itemRuleId === parseInt(excludeRuleId || 0, 10)) {
				return;
			}
			if (normalizedSubject !== '') {
				var itemSubject = normalizeCode(item && item.subject_key || '');
				if (itemSubject !== normalizedSubject) {
					return;
				}
			}
			if (allowedStates && allowedStates.length) {
				var itemState = normalizeCode(item && item.state || '');
				if (!statesSet[itemState]) {
					return;
				}
			}
			if (latest === null) {
				latest = item;
				return;
			}
			var leftKey = String(item.updated_at || item.last_matched_at || item.detected_at || item.created_at || '');
			var rightKey = String(latest.updated_at || latest.last_matched_at || latest.detected_at || latest.created_at || '');
			if (leftKey > rightKey || (leftKey === rightKey && parseInt(item.id || 0, 10) > parseInt(latest.id || 0, 10))) {
				latest = item;
			}
		});
		return latest;
	}

	function incidentStateLabel(incident) {
		if (!incident) {
			return '';
		}
		var label = mapCode(incident.state, statusLabels);
		return label !== '' ? label : 'Open';
	}

	function suppressionStatusText(rule) {
		if (rule.suppression_minutes_override === null || typeof rule.suppression_minutes_override === 'undefined' || String(rule.suppression_minutes_override) === '') {
			return 'Default 24hrs';
		}
		var suppressionMinutes = parseInt(rule.suppression_minutes_override, 10);
		if (!isFinite(suppressionMinutes) || suppressionMinutes < 0) {
			return 'Default 24hrs';
		}
		if (suppressionMinutes === 0) {
			return 'disabled';
		}
		return formatCountUnit(suppressionMinutes, 'minute', 'minutes');
	}

	function statusOutcomeSentence(rule) {
		var assessment = (rule && typeof rule.status_assessment === 'object' && rule.status_assessment !== null) ? rule.status_assessment : {};
		var ruleId = parseInt(rule && rule.id || 0, 10);
		var subjectKey = $.trim(String(assessment.subject_key || ''));

		var suppression = latestItemForRuleSubject(currentSuppressedIncidents, ruleId, subjectKey, [], 0);
		if (suppression) {
			return 'This rule is currently suppressed until ' + formatDisplayDateTime(suppression.suppression_expires_at || '') + '.';
		}

		var ownActiveIncident = latestItemForRuleSubject(currentActiveIncidents, ruleId, subjectKey, ['active'], 0);
		if (ownActiveIncident) {
			return 'This rule has an active incident.';
		}
		var ownClaimedIncident = latestItemForRuleSubject(currentRecentIncidents, ruleId, subjectKey, ['claimed'], 0);
		if (ownClaimedIncident) {
			return 'This rule has an accepted incident.';
		}

		return 'This rule has no active or accepted incident.';
	}

	function statusProgressWindowPhrase(rule) {
		var windowMinutes = parseInt(rule && rule.observation_window_minutes || 0, 10);
		if (!isFinite(windowMinutes) || windowMinutes < 0) {
			windowMinutes = 0;
		}
		if (windowMinutes > 0 && (windowMinutes % 60) === 0) {
			return formatCountUnit(windowMinutes / 60, 'hour', 'hours');
		}
		return formatCountUnit(windowMinutes, 'minute', 'minutes');
	}

	function statusProgressSentence(rule, matchedCalls, threshold) {
		var progress = matchedCalls + ' of ' + threshold + ' matching calls detected within the last ' + statusProgressWindowPhrase(rule) + '.';
		if (threshold > 0 && matchedCalls >= threshold) {
			progress += ' Alert threshold reached.';
		}
		return progress;
	}

	function statusFreshnessSentence(rule) {
		var assessment = (rule && typeof rule.status_assessment === 'object' && rule.status_assessment !== null) ? rule.status_assessment : {};
		var lastChecked = $.trim(String(assessment.last_evaluated_at || ''));
		if (lastChecked === '') {
			lastChecked = $.trim(String(currentEngineLastSuccessfulRun || ''));
		}
		return 'Last checked: ' + formatDisplayDateTime(lastChecked || '-') + '. New calls may not be included until the next monitor run.';
	}

	function ruleStatusSentence(rule) {
		var ruleId = parseInt(rule && rule.id || 0, 10);
		var activeIncident = latestItemForRule(currentActiveIncidents, ruleId);
		var latestIncident = latestItemForRule(currentRecentIncidents, ruleId);
		var activeSuppression = latestItemForRule(currentSuppressedIncidents, ruleId);
		var source = activeIncident || activeSuppression || latestIncident;
		var matchedCalls = parseInt(source && source.matched_call_count || 0, 10);
		if (!isFinite(matchedCalls) || matchedCalls < 0) {
			matchedCalls = 0;
		}
		var threshold = parseInt(rule && rule.threshold_count || 0, 10);
		if (!isFinite(threshold) || threshold < 0) {
			threshold = 0;
		}
		return statusProgressSentence(rule, matchedCalls, threshold)
			+ ' ' + statusOutcomeSentence(rule)
			+ ' ' + statusFreshnessSentence(rule);
	}

	function setRuleExplainerText(ruleId, text) {
		var numericRuleId = parseInt(ruleId || 0, 10);
		if (!numericRuleId || numericRuleId < 1) {
			return;
		}
		$('#rc-rules-table tbody tr[data-rule-id="' + numericRuleId + '"]').next('.rc-rule-explainer-row').find('.rc-rule-explainer-text').text(text);
	}

	function setRuleStatusHighlight(ruleId, enabled) {
		var numericRuleId = parseInt(ruleId || 0, 10);
		if (!numericRuleId || numericRuleId < 1) {
			return;
		}
		var $ruleRow = $('#rc-rules-table tbody tr[data-rule-id="' + numericRuleId + '"]');
		var $explainerRow = $ruleRow.next('.rc-rule-explainer-row');
		$explainerRow.toggleClass('rc-rule-status-active', !!enabled);
	}

	function showRuleStatus(ruleId, $button) {
		var numericRuleId = parseInt(ruleId || 0, 10);
		var rule = currentRulesById[String(numericRuleId)] || null;
		if (!rule) {
			return;
		}
		var timerKey = String(numericRuleId);
		if (ruleStatusTimers[timerKey] !== null && typeof ruleStatusTimers[timerKey] !== 'undefined') {
			window.clearTimeout(ruleStatusTimers[timerKey]);
			delete ruleStatusTimers[timerKey];
			setRuleExplainerText(numericRuleId, ruleExplanationSentence(rule));
			setRuleStatusHighlight(numericRuleId, false);
			if ($button && $button.length) {
				$button.attr('aria-pressed', 'false');
				$button.blur();
			}
			return;
		}
		if ($button && $button.length) {
			$button.attr('aria-pressed', 'true');
		}
		setRuleStatusHighlight(numericRuleId, true);
		setRuleExplainerText(numericRuleId, ruleStatusSentence(rule));
		ruleStatusTimers[timerKey] = window.setTimeout(function () {
			delete ruleStatusTimers[timerKey];
			setRuleExplainerText(numericRuleId, ruleExplanationSentence(rule));
			setRuleStatusHighlight(numericRuleId, false);
			if ($button && $button.length) {
				$button.attr('aria-pressed', 'false');
				$button.blur();
			}
		}, 15000);
	}

	function renderEngine(engine) {
		if (!engine) {
			return;
		}
		currentEngineLastSuccessfulRun = $.trim(String(engine.last_successful_run || ''));
		var enabled = !!engine.enabled;
		var snoozedUntil = String(engine.global_snoozed_until || '');
		var selectedSnoozeSeconds = String(engine.selected_snooze_seconds || '');
		var isSnoozed = snoozedUntil !== '';
		var canSnooze = enabled && !isSnoozed;
		var canResume = enabled && isSnoozed;
		var $snoozeButtons = $('.rc-snooze');
		var banner = enabled ? 'Monitoring enabled.' : 'Monitoring disabled.';
		if (snoozedUntil !== '') {
			banner += ' Snoozed until ' + snoozedUntil + '.';
		}
		$('#rc-engine-banner').text(banner);
		$('#rc-enable').prop('disabled', enabled).toggleClass('disabled', enabled);
		$('#rc-disable').prop('disabled', !enabled).toggleClass('disabled', !enabled);
		$snoozeButtons.prop('disabled', !canSnooze).toggleClass('disabled', !canSnooze);
		$('#rc-resume').prop('disabled', !canResume).toggleClass('disabled', !canResume);
		$snoozeButtons.removeClass('rc-snooze-active');
		if (canResume && selectedSnoozeSeconds !== '') {
			$snoozeButtons.filter(function () {
				return String($(this).data('seconds')) === selectedSnoozeSeconds;
			}).addClass('rc-snooze-active');
		}
		$('#rc-enabled-rule-count').text(parseInt(engine.enabled_rule_count || 0, 10));
		$('#rc-active-incident-count').text(parseInt(engine.active_incident_count || 0, 10));
		$('#rc-last-run').text(engine.last_successful_run || '-');
		syncLiveClockFromValue('pbx', engine.pbx_time || '-', false);
		updateRunStatusFromEngine(engine.lock_state || '');
	}

	function ruleScopeSummary(rule) {
		var caller = String(rule.caller_mode || 'any');
		if (caller === 'withheld_only') {
			return 'Withheld only';
		}
		if (caller === 'specific_only') {
			return 'Specific';
		}
		return 'Any';
	}

	function didScopeSummary(rule) {
		return String(rule.did_scope_mode || 'all') === 'selected' ? 'Selected routes' : 'All routes';
	}

	function actionsSummary(rule) {
		var items = ['GUI'];
		if (parseInt(rule.email_enabled || 0, 10)) {
			items.push('Email');
		}
		if (parseInt(rule.alert_call_enabled || 0, 10)) {
			items.push('Alert Call');
		}
		return items.join(', ');
	}

	function listWithAnd(items) {
		if (!items || !items.length) {
			return '';
		}
		if (items.length === 1) {
			return items[0];
		}
		if (items.length === 2) {
			return items[0] + ' and ' + items[1];
		}
		return items.slice(0, -1).join(', ') + ' and ' + items[items.length - 1];
	}

	function listWithOr(items) {
		if (!items || !items.length) {
			return '';
		}
		if (items.length === 1) {
			return items[0];
		}
		if (items.length === 2) {
			return items[0] + ' or ' + items[1];
		}
		return items.slice(0, -1).join(', ') + ' or ' + items[items.length - 1];
	}

	function formatCountUnit(countValue, singular, plural) {
		var count = parseInt(countValue || 0, 10);
		if (!isFinite(count) || count < 0) {
			count = 0;
		}
		return String(count) + ' ' + (count === 1 ? singular : plural);
	}

	function incidentSubjectDisplay(row) {
		var data = row || {};
		var rawSubject = $.trim(String(data.subject_label || data.subject_key || ''));
		var modeValue = normalizeCode(data.mode || data.incident_mode || '');

		if (modeValue === 'invert') {
			var threshold = parseInt(data.threshold_count || data.incident_threshold_count || 0, 10);
			if (!isFinite(threshold) || threshold < 0) {
				threshold = 0;
			}
			var windowMinutes = parseInt(data.observation_window_minutes || data.incident_observation_window_minutes || 0, 10);
			if (!isFinite(windowMinutes) || windowMinutes < 0) {
				windowMinutes = 0;
			}
			var callWord = threshold === 1 ? 'call' : 'calls';
			return 'Fewer than ' + threshold + ' ' + callWord + ' within a ' + windowMinutes + '-minute window';
		}

		return rawSubject !== '' ? rawSubject : '-';
	}

	function callerScopeDescription(rule) {
		var callerMode = normalizeCode(rule.caller_mode || 'any');
		if (callerMode === 'withheld_only') {
			return 'withheld callers';
		}
		if (callerMode === 'specific_only') {
			var callerValues = [];
			$.each((rule.caller_lists && rule.caller_lists.include) || [], function (_, row) {
				var callerValue = $.trim(String(row.raw_value || row.normalized_value || ''));
				if (callerValue !== '') {
					callerValues.push(callerValue);
				}
			});
			return callerValues.length > 0 ? listWithOr(callerValues) : 'any caller';
		}
		return 'any caller';
	}

	function didScopeDescription(rule) {
		var didMode = normalizeCode(rule.did_scope_mode || 'all');
		if (didMode === 'selected') {
			var routeValues = [];
			$.each((rule.did_lists && rule.did_lists.include) || [], function (_, row) {
				var routeLabel = $.trim(String(row.route_label || ''));
				var didValue = $.trim(String(row.did_value || ''));
				var routeValue = '';
				if (routeLabel !== '' && didValue !== '') {
					routeValue = routeLabel + ' (' + didValue + ')';
				} else if (routeLabel !== '') {
					routeValue = routeLabel;
				} else if (didValue !== '') {
					routeValue = didValue;
				}
				if (routeValue !== '') {
					routeValues.push(routeValue);
				}
			});
			return routeValues.length > 0 ? listWithOr(routeValues) : 'any inbound route';
		}
		return 'any inbound route';
	}

	function actionsDescription(rule) {
		var items = ['through the GUI'];
		if (parseInt(rule.email_enabled || 0, 10)) {
			items.push('by email');
		}
		if (parseInt(rule.alert_call_enabled || 0, 10)) {
			items.push('by phone');
		}
		return listWithAnd(items);
	}

	function scheduleDescription(rule) {
		var scheduleCount = parseInt(rule.schedule_count || 0, 10);
		if (scheduleCount > 1) {
			return ', during its configured schedule periods';
		}
		return '';
	}

	function repeatDescription(rule) {
		var rawRepeat = normalizeCode(rule.repeat_mode_override || 'never');
		if (rawRepeat === '') {
			rawRepeat = 'never';
		}
		if (rawRepeat === 'never') {
			return 'without follow-up reminders';
		}
		if (rawRepeat === '5m') {
			return 'repeating every 5 minutes';
		}
		if (rawRepeat === 'hourly') {
			return 'repeating hourly';
		}
		if (rawRepeat === 'daily') {
			return 'repeating daily';
		}
		if (rawRepeat === 'escalating' || rawRepeat === 'fibonacci') {
			return 'using escalating reminders';
		}
		return 'using ' + repeatModeLabel(rawRepeat).toLowerCase() + ' reminders';
	}

	function ruleExplanationSentence(rule) {
		var mode = normalizeCode(rule.mode || 'repeat');
		var actions = actionsDescription(rule);
		var caller = callerScopeDescription(rule);
		var did = didScopeDescription(rule);
		var callerListCount = (rule.caller_lists && rule.caller_lists.include && rule.caller_lists.include.length) || 0;
		var callerVerb = callerListCount > 1 ? 'call' : 'calls';
		var threshold = parseInt(rule.threshold_count || 0, 10);
		if (!isFinite(threshold) || threshold < 0) {
			threshold = 0;
		}
		var windowPhrase = formatCountUnit(rule.observation_window_minutes, 'minute', 'minutes');
		var schedulePhrase = scheduleDescription(rule);
		var repeatPhrase = repeatDescription(rule);

		if (mode === 'invert') {
			var callWord = threshold === 1 ? 'call' : 'calls';
			return 'This rule alerts ' + actions + ' when ' + caller + ' ' + callerVerb + ' ' + did + ' fewer than ' + threshold + ' ' + callWord + ' within each completed ' + windowPhrase + ' window' + schedulePhrase + ', ' + repeatPhrase + '.';
		}

		return 'This rule alerts ' + actions + ' when ' + caller + ' ' + callerVerb + ' ' + did + ' ' + threshold + ' or more times within ' + windowPhrase + schedulePhrase + ', ' + repeatPhrase + '.';
	}

	function repeatModeLabel(rawRepeatMode) {
		var label = mapCode(rawRepeatMode, repeatModeLabels);
		if (label !== '') {
			return label;
		}
		return 'Never';
	}

	function suppressionSummary(rule) {
		if (rule.suppression_minutes_override === null || typeof rule.suppression_minutes_override === 'undefined' || String(rule.suppression_minutes_override) === '') {
			return 'Default 24hrs';
		}
		var suppressionMinutes = parseInt(rule.suppression_minutes_override, 10);
		if (!isFinite(suppressionMinutes) || suppressionMinutes < 0) {
			return 'Default 24hrs';
		}
		if (suppressionMinutes === 0) {
			return 'Disabled';
		}
		return formatCountUnit(suppressionMinutes, 'minute', 'minutes');
	}

	function recordingSummary(rule) {
		var recordingId = parseInt(rule.alert_call_recording_id || 0, 10);
		if (!recordingId || recordingId < 1) {
			return 'None';
		}
		var label = systemRecordingsById[String(recordingId)] || '';
		if (label !== '') {
			return label;
		}
		return 'Recording #' + recordingId;
	}

	function renderRules(rules) {
		var rows = [];
		var columnCount = $('#rc-rules-table thead th').length || 12;
		clearRuleStatusTimers();
		rememberRules(rules);
		var sortedRules = (rules || []).slice().sort(function (left, right) {
			var leftName = String((left && left.name) || '').toLocaleLowerCase();
			var rightName = String((right && right.name) || '').toLocaleLowerCase();
			var byName = leftName.localeCompare(rightName, undefined, {numeric: true, sensitivity: 'base'});
			if (byName !== 0) {
				return byName;
			}
			return parseInt((left && left.id) || 0, 10) - parseInt((right && right.id) || 0, 10);
		});
		$.each(sortedRules, function (_, rule) {
			var modeLabel = detectionModeLabel(rule.mode || 'repeat');
			if (modeLabel === 'Unknown') {
				modeLabel = titleizeFallback(rule.mode) || 'Unknown';
			}
			var repeatLabel = repeatModeLabel(rule.repeat_mode_override || 'never');
			var ruleClass = parseInt(rule.enabled || 0, 10) ? '' : ' class="rc-rule-disabled"';
			rows.push('<tr data-rule-id="' + parseInt(rule.id, 10) + '"' + ruleClass + '>'
				+ '<td>' + esc(rule.name) + '</td>'
				+ '<td><input type="checkbox" class="rc-rule-enabled-toggle" ' + (parseInt(rule.enabled || 0, 10) ? 'checked' : '') + '></td>'
				+ '<td>' + esc(modeLabel) + '</td>'
				+ '<td>' + esc(rule.threshold_count) + ' / ' + esc(rule.observation_window_minutes) + 'm</td>'
				+ '<td>' + esc(ruleScopeSummary(rule)) + '</td>'
				+ '<td>' + esc(didScopeSummary(rule)) + '</td>'
				+ '<td>' + esc(rule.schedule_count || 0) + '</td>'
				+ '<td>' + esc(actionsSummary(rule)) + '</td>'
				+ '<td>' + esc(recordingSummary(rule)) + '</td>'
				+ '<td>' + esc(repeatLabel) + '</td>'
				+ '<td>' + esc(suppressionSummary(rule)) + '</td>'
				+ '<td>' + esc(rule.active_incident_count || 0) + '</td>'
				+ '<td class="rc-rule-controls"><button type="button" class="btn btn-xs btn-default rc-rule-status">Status</button> '
				+ '<button type="button" class="btn btn-xs btn-default rc-edit-rule">Edit</button> '
				+ '<button type="button" class="btn btn-xs btn-danger rc-delete-rule" aria-label="Delete" title="Delete">X</button></td>'
				+ '</tr>');
			rows.push('<tr class="rc-rule-explainer-row' + (parseInt(rule.enabled || 0, 10) ? '' : ' rc-rule-disabled') + '">'
				+ '<td colspan="' + columnCount + '"><span class="rc-rule-explainer-text">' + esc(ruleExplanationSentence(rule)) + '</span></td>'
				+ '</tr>');
		});
		if (!rows.length) {
			rows.push('<tr class="rc-empty-state"><td colspan="' + columnCount + '" class="text-muted">No rules configured yet.</td></tr>');
		}
		$('#rc-rules-table tbody').html(rows.join(''));
		updateTableRowBatching('#rc-rules-table');
	}

	function updateRuleEditorTitle(editing) {
		$('#rc-editor-title').text(editing ? 'Editing Rule' : 'Add Rule');
	}

	function setEditingRuleRow(ruleId) {
		var editingRuleId = parseInt(ruleId || 0, 10);
		$('#rc-rules-table tbody tr').removeClass('rc-rule-editing');
		if (!editingRuleId || editingRuleId < 1) {
			return;
		}
		$('#rc-rules-table tbody tr[data-rule-id="' + editingRuleId + '"]').addClass('rc-rule-editing').next('.rc-rule-explainer-row').addClass('rc-rule-editing');
	}

	function renderIncidents(selector, incidents, active) {
		var rows = [];
		rememberIncidents(selector, incidents);
		$.each(incidents || [], function (_, i) {
			var detectionMode = detectionModeLabel(i.mode);
			var rawState = String(i.state || '');
			var stateLabel = mapCode(rawState, statusLabels) || '-';
			var subjectDisplay = incidentSubjectDisplay(i);
			if (active) {
				rows.push('<tr data-incident-id="' + parseInt(i.id, 10) + '">'
					+ '<td>' + esc(i.id) + '</td>'
					+ '<td>' + esc(i.first_matched_at) + '</td>'
					+ '<td>' + esc(i.rule_name) + '</td>'
					+ '<td>' + esc(detectionMode) + '</td>'
					+ '<td>' + esc(subjectDisplay) + '</td>'
					+ '<td>' + esc(i.caller_normalized || i.caller_display || '-') + '</td>'
					+ '<td>' + esc(i.last_matched_at) + '</td>'
					+ '<td>' + esc(i.matched_call_count) + '</td>'
					+ '<td>' + esc(i.threshold_count) + '</td>'
					+ '<td>' + esc(i.observation_window_minutes) + 'm</td>'
					+ '<td title="' + esc(rawState) + '">' + esc(stateLabel) + '</td>'
					+ '<td>' + esc(i.updated_at) + '</td>'
					+ '<td><button type="button" class="btn btn-xs btn-warning rc-claim-incident">Accept</button></td>'
					+ '</tr>');
			} else {
				rows.push('<tr>'
					+ '<td>' + esc(i.id) + '</td>'
					+ '<td>' + esc(i.created_at) + '</td>'
					+ '<td>' + esc(i.rule_name) + '</td>'
					+ '<td>' + esc(detectionMode) + '</td>'
					+ '<td>' + esc(subjectDisplay) + '</td>'
					+ '<td title="' + esc(rawState) + '">' + esc(stateLabel) + '</td>'
					+ '<td>' + esc(i.claimed_by || '-') + '</td>'
					+ '<td>' + esc(i.suppression_expires_at || '-') + '</td>'
					+ '<td>' + esc(i.updated_at) + '</td>'
					+ '</tr>');
			}
		});
		if (!rows.length) {
			var emptyColumnCount = $(selector + ' thead th').length || 1;
			var emptyText = active ? 'No active incidents recorded yet.' : 'No recent incidents recorded yet.';
			rows.push('<tr class="rc-empty-state"><td colspan="' + emptyColumnCount + '" class="text-muted">' + emptyText + '</td></tr>');
		}
		$(selector + ' tbody').html(rows.join(''));
		updateTableRowBatching(selector);
	}

	function formatSuppressedIncidentReason(row) {
		var suppressionExpiresAt = formatDisplayDateTime(row.suppression_expires_at);
		var relatedIncidentId = parseInt(row.related_incident_id || 0, 10);
		var relatedIncidentLabel = relatedIncidentId > 0 ? 'incident #' + relatedIncidentId : 'the previous incident';
		var suppressionMinutes = parseInt(row.suppression_minutes || 0, 10);
		if (normalizeCode(row.related_incident_state || '') === 'claimed') {
			return 'New incident suppressed until ' + suppressionExpiresAt + ' because ' + relatedIncidentLabel + ' was accepted.';
		}
		if (suppressionMinutes > 0) {
			return 'New incident suppressed until ' + suppressionExpiresAt + ' because the previous incident remains within the ' + formatCountUnit(suppressionMinutes, 'minute', 'minutes') + ' suppression period.';
		}
		return 'New incident suppressed until ' + suppressionExpiresAt + ' because suppression was active.';
	}

	function renderSuppressedIncidents(items) {
		var $table = $('#rc-suppressed-incidents-table');
		if (!$table.length) {
			return;
		}
		rememberSuppressedIncidents(items);
		var rows = [];
		$.each(items || [], function (_, row) {
			var modeLabel = detectionModeLabel(row.mode || 'repeat');
			if (modeLabel === 'Unknown') {
				modeLabel = titleizeFallback(row.mode) || 'Unknown';
			}
			var subjectDisplay = incidentSubjectDisplay(row);
			var relatedIncidentId = parseInt(row.related_incident_id || 0, 10);
			var clearedAtRaw = $.trim(String(row.cleared_at || ''));
			var isCleared = clearedAtRaw !== '' && clearedAtRaw !== '0000-00-00 00:00:00' && clearedAtRaw !== '0000-00-00';
			var clearedLabel = isCleared ? formatDisplayDateTime(clearedAtRaw) : '-';
			var relatedIncidentCell = relatedIncidentId > 0 ? 'Incident #' + relatedIncidentId : '-';
			var actionCell = '-';
			if (!isCleared) {
				actionCell = '<button type="button" class="btn btn-xs btn-default rc-clear-suppression" data-suppression-history-id="' + esc(row.id || 0) + '">Clear Suppression</button>';
			}
			rows.push('<tr>'
				+ '<td>' + esc(formatDisplayDateTime(row.created_at || row.detected_at || '')) + '</td>'
				+ '<td>' + esc(row.rule_name || '-') + '</td>'
				+ '<td>' + esc(modeLabel) + '</td>'
				+ '<td>' + esc(subjectDisplay) + '</td>'
				+ '<td>' + esc(row.matched_call_count || 0) + '</td>'
				+ '<td>' + esc((row.threshold_count || 0) + ' / ' + (row.observation_window_minutes || 0) + 'm') + '</td>'
				+ '<td>' + esc(formatDisplayDateTime(row.suppression_expires_at || '')) + '</td>'
				+ '<td>' + esc(clearedLabel) + '</td>'
				+ '<td>' + esc(formatSuppressedIncidentReason(row)) + '</td>'
				+ '<td>' + relatedIncidentCell + '</td>'
				+ '<td>' + actionCell + '</td>'
				+ '</tr>');
		});
		if (!rows.length) {
			rows.push('<tr class="rc-empty-state"><td colspan="11" class="text-muted">No suppressed incidents recorded yet.</td></tr>');
		}
		$table.find('tbody').html(rows.join(''));
		updateTableRowBatching('#rc-suppressed-incidents-table');
	}

	function renderAlertHistory(items) {
		rememberAlertHistory(items);
		var modeLabels = {
			repeat: 'Repeat',
			invert: 'Invert'
		};
		var eventLabels = {
			initial: 'Initial',
			reminder: 'Reminder'
		};
		var actionLabels = {
			gui: 'GUI',
			email: 'Email',
			alert_call: 'Alert Call'
		};

		function alertCallFailureSummary(rawStatus, rawFailureDetail) {
			var statusKey = normalizeCode(rawStatus);
			var detail = $.trim(String(rawFailureDetail || ''));

			if (statusKey === 'claimed' || statusKey === 'accepted') {
				return 'Incident accepted';
			}
			if (statusKey === 'declined') {
				return 'Recipient declined the Alert Call';
			}
			if (statusKey === 'answered_no_response') {
				return 'Answered, but no response received';
			}

			var dialMatch = detail.match(/DIALSTATUS=([A-Z_0-9]+)/i);
			if (dialMatch) {
				var dialStatus = String(dialMatch[1] || '').toUpperCase();
				if (dialStatus === 'BUSY') {
					return 'Recipient was busy';
				}
				if (dialStatus === 'NOANSWER') {
					return 'No answer';
				}
				if (dialStatus === 'CHANUNAVAIL') {
					return 'Recipient unavailable';
				}
				if (dialStatus === 'CONGESTION') {
					return 'Call could not be completed';
				}
				if (dialStatus === 'CANCEL') {
					return 'Call cancelled';
				}
				return 'Call failed';
			}

			if (statusKey === 'busy') {
				return 'Recipient was busy';
			}
			if (statusKey === 'no_answer') {
				return 'No answer';
			}
			if (statusKey === 'unreachable') {
				return 'Recipient unavailable';
			}
			if (statusKey === 'congestion') {
				return 'Call could not be completed';
			}
			if (detail !== '') {
				return 'Call failed';
			}

			return '';
		}

		function renderFailureDetailCell(h) {
			var actionType = normalizeCode(h.action_type);
			var rawStatus = String(h.delivery_status || '');
			var rawFailureDetail = String(h.failure_detail || '');
			var trimmedRaw = $.trim(rawFailureDetail);

			if (actionType !== 'alert_call') {
				return esc(rawFailureDetail || '-');
			}

			var friendly = alertCallFailureSummary(rawStatus, rawFailureDetail);
			if (friendly === '' && trimmedRaw === '') {
				return '-';
			}

			if (trimmedRaw !== '') {
				return '<span title="' + esc(trimmedRaw) + '">' + esc(friendly || 'Call failed') + '</span>';
			}

			return esc(friendly || '-');
		}

		var rows = [];
		$.each(items || [], function (_, h) {
			var modeLabel = mapCode(h.incident_mode, modeLabels);
			if (modeLabel === '') {
				modeLabel = detectionModeLabel(h.incident_mode);
			}
			if (modeLabel === 'Unknown') {
				modeLabel = titleizeFallback(h.incident_mode) || 'Unknown';
			}
			var subjectDisplay = incidentSubjectDisplay(h);
			var eventLabel = mapCode(h.event_type, eventLabels) || '-';
			var rawStatus = String(h.delivery_status || '');
			var statusLabel = mapCode(rawStatus, statusLabels) || '-';
			var actionLabel = mapCode(h.action_type, actionLabels) || '-';
			rows.push('<tr>'
				+ '<td>' + esc(h.incident_id) + '</td>'
				+ '<td>' + esc(h.created_at || h.updated_at) + '</td>'
				+ '<td>' + esc(h.rule_name || h.rule_id) + '</td>'
				+ '<td>' + esc(modeLabel) + '</td>'
				+ '<td>' + esc(subjectDisplay) + '</td>'
				+ '<td>' + esc(eventLabel) + '</td>'
				+ '<td>' + esc(actionLabel) + '</td>'
				+ '<td title="' + esc(rawStatus) + '">' + esc(statusLabel) + '</td>'
				+ '<td>' + esc(h.stage_n) + '</td>'
				+ '<td>' + esc(h.successful_at || '-') + '</td>'
				+ '<td>' + renderFailureDetailCell(h) + '</td>'
				+ '</tr>');
		});
		if (!rows.length) {
			var columnCount = $('#rc-alert-history-table thead th').length || 11;
			rows.push('<tr class="rc-empty-state"><td colspan="' + columnCount + '" class="text-muted">No alert history recorded yet.</td></tr>');
		}
		$('#rc-alert-history-table tbody').html(rows.join(''));
		updateTableRowBatching('#rc-alert-history-table');
	}

	function collectRouteList($list) {
		var items = [];
		$list.find('li').each(function () {
			var route = $(this).data('route');
			if (route && route.route_key) {
				items.push({
					list_type: String($(this).data('listType')),
					route_key: String(route.route_key),
					route_label: String(route.route_label || route.route_key),
					did_value: String(route.did_value || ''),
					cid_value: String(route.cid_value || '')
				});
			}
		});
		return items;
	}

	function addRouteToList($list, route, listType) {
		if (!route || !route.route_key) {
			return;
		}
		var exists = false;
		$list.find('li').each(function () {
			if ($(this).data('route') && $(this).data('route').route_key === route.route_key) {
				exists = true;
				return false;
			}
		});
		if (exists) {
			return;
		}
		var $li = $('<li/>').text((route.route_label || route.route_key) + ' [' + route.route_key + ']');
		$li.data('route', route);
		$li.data('listType', listType);
		$li.append(' ');
		$li.append($('<button type="button" class="btn btn-xs btn-link">remove</button>').on('click', function () {
			$li.remove();
		}));
		$list.append($li);
	}

	function normaliseAlertCallDestinationEntries(rawValue, defaultKeepTryingEnabled) {
		var parts = String(rawValue || '').split(/[,;\n\r]+/);
		var unique = {};
		var ordered = [];
		var keepTryingDefault = defaultKeepTryingEnabled === undefined ? true : !!defaultKeepTryingEnabled;
		$.each(parts, function (_, part) {
			var token = $.trim(String(part || ''));
			var destination = token;
			var keepTrying = keepTryingDefault;
			var match;
			if (!token) {
				return;
			}
			match = token.match(/^(.*)\|([01])$/);
			if (match) {
				destination = $.trim(match[1]);
				keepTrying = match[2] === '1';
			}
			if (!destination || unique[destination]) {
				return;
			}
			unique[destination] = true;
			ordered.push({destination: destination, keepTrying: keepTrying});
		});
		return ordered;
	}

	function normaliseAlertCallDestinations(rawValue) {
		var ordered = [];
		$.each(normaliseAlertCallDestinationEntries(rawValue, true), function (_, row) {
			ordered.push(row.destination);
		});
		return ordered;
	}

	function updateAlertCallDestinationHiddenField() {
		var values = [];
		$('#rc-rule-alert-call-destination-list li').each(function () {
			var destination = String($(this).attr('data-destination') || '');
			var keepTryingFlag = $(this).attr('data-keep-trying') === '0' ? '0' : '1';
			if (destination !== '') {
				values.push(destination + '|' + keepTryingFlag);
			}
		});
		$('#rc-rule-alert-call-destinations').val(values.join(', '));
	}

	function updateAlertCallDestinationOrderLabels() {
		$('#rc-rule-alert-call-destination-list li').each(function (index) {
			$(this).find('.rc-alert-call-destination-order').text(String(index + 1) + '.');
		});
		updateAlertCallDestinationHiddenField();
	}

	function buildAlertCallDestinationItem(destination, keepTryingEnabled) {
		var keepTrying = keepTryingEnabled === undefined ? true : !!keepTryingEnabled;
		var $li = $('<li class="list-group-item rc-alert-call-destination-item"/>').attr('data-destination', destination).attr('data-keep-trying', keepTrying ? '1' : '0');
		var $order = $('<span class="rc-alert-call-destination-order"/>').text('1.');
		var $dragHandle = $('<button type="button" class="btn btn-xs btn-default rc-alert-call-destination-drag-handle" draggable="true" title="Drag to reorder" aria-label="Drag to reorder"/>')
			.append($('<i class="fa fa-bars" aria-hidden="true"/>'));
		var $value = $('<span class="rc-alert-call-destination-value"/>').text(destination);
		var $keepTryingToggle = $('<label class="rc-alert-call-destination-keep-trying"/>')
			.append($('<input type="checkbox" class="rc-alert-call-destination-keep-trying-checkbox"/>').prop('checked', keepTrying).on('change', function () {
				$li.attr('data-keep-trying', $(this).is(':checked') ? '1' : '0');
				updateAlertCallDestinationHiddenField();
			}))
			.append(' Keep Trying');
		var $remove = $('<button type="button" class="btn btn-xs btn-link rc-alert-call-destination-remove"/>').text('Remove').on('click', function () {
			$li.remove();
			updateAlertCallDestinationOrderLabels();
		});
		$li.append($order).append($dragHandle).append($value).append($keepTryingToggle).append($remove);

		$dragHandle.on('dragstart', function (event) {
			$li.addClass('rc-dragging');
			event.originalEvent.dataTransfer.setData('text/plain', destination);
			event.originalEvent.dataTransfer.effectAllowed = 'move';
		});
		$dragHandle.on('dragend', function () {
			$li.removeClass('rc-dragging');
		});
		$li.on('dragover', function (event) {
			event.preventDefault();
			event.originalEvent.dataTransfer.dropEffect = 'move';
		});
		$li.on('drop', function (event) {
			event.preventDefault();
			var $source = $('#rc-rule-alert-call-destination-list .rc-dragging').first();
			if (!$source.length || $source[0] === $li[0]) {
				return;
			}
			if ($source.index() < $li.index()) {
				$li.after($source);
			} else {
				$li.before($source);
			}
			updateAlertCallDestinationOrderLabels();
		});

		return $li;
	}

	function addAlertCallDestination(destination, keepTryingEnabled) {
		var value = $.trim(String(destination || ''));
		if (value === '') {
			return;
		}
		var exists = false;
		$('#rc-rule-alert-call-destination-list li').each(function () {
			if (String($(this).attr('data-destination') || '') === value) {
				exists = true;
				return false;
			}
		});
		if (exists) {
			return;
		}
		$('#rc-rule-alert-call-destination-list').append(buildAlertCallDestinationItem(value, keepTryingEnabled));
		updateAlertCallDestinationOrderLabels();
	}

	function renderAlertCallDestinations(rawValue, defaultKeepTryingEnabled) {
		var destinations = normaliseAlertCallDestinationEntries(rawValue, defaultKeepTryingEnabled);
		var $list = $('#rc-rule-alert-call-destination-list');
		$list.empty();
		$.each(destinations, function (_, destinationRow) {
			$list.append(buildAlertCallDestinationItem(destinationRow.destination, destinationRow.keepTrying));
		});
		updateAlertCallDestinationOrderLabels();
	}

	function addAlertCallDestinationsFromInput() {
		var raw = $('#rc-rule-alert-call-destination-input').val();
		var defaultKeepTrying = true;
		$.each(normaliseAlertCallDestinationEntries(raw, defaultKeepTrying), function (_, destinationRow) {
			addAlertCallDestination(destinationRow.destination, destinationRow.keepTrying);
		});
		$('#rc-rule-alert-call-destination-input').val('');
	}

	function resetRuleEditor() {
		$('#rc-rule-id').val('0');
		$('.rc-editor-panel').removeClass('rc-editor-edit-mode');
		updateRuleEditorTitle(false);
		setEditingRuleRow(0);
		$('#rc-save-rule').prop('disabled', false).removeClass('disabled');
		$('#rc-cancel-edit').prop('disabled', false).removeClass('disabled');
		$('#rc-cancel-edit').addClass('hidden');
		$('#rc-rule-name').val('');
		$('#rc-rule-enabled').prop('checked', true);
		$('#rc-rule-mode').val('repeat');
		$('#rc-rule-threshold').val('2');
		$('#rc-rule-window').val('60');
		$('#rc-rule-suppression').val('');
		$('#rc-rule-repeat').val('never');
		$('#rc-rule-email-recipients').val('');
		$('#rc-rule-caller-mode').val('any');
		$('#rc-rule-exclude-withheld').prop('checked', false);
		$('#rc-rule-caller-include').val('');
		$('#rc-rule-caller-exclude').val('');
		updateCallerScopeEditorState();
		$('#rc-rule-did-mode').val('all');
		updateDidScopeEditorState();
		$('#rc-rule-email-enabled').prop('checked', false);
		$('#rc-rule-alert-call-enabled').prop('checked', false);
		$('#rc-rule-alert-call-strategy').val('ringall');
		$('#rc-rule-alert-call-destinations').val('');
		$('#rc-rule-alert-call-destination-input').val('');
		$('#rc-rule-alert-call-destination-list').empty();
		$('#rc-rule-alert-call-recording-id').val('');
		$('#rc-rule-alert-call-callerid').val('');
		$('#rc-did-include-list').empty();
		$('#rc-did-exclude-list').empty();
		$('#rc-schedule-table tbody').empty();
		addScheduleRow(-1, '00:00', '24:00', true);
		updateAlertCallAndEmailState();
	}

	function ensureRecordingOptionExists(recordingId) {
		var value = $.trim(String(recordingId || ''));
		if (value === '' || !/^\d+$/.test(value)) {
			return;
		}
		var $select = $('#rc-rule-alert-call-recording-id');
		if (!$select.find('option[value="' + value + '"]').length) {
			$select.append('<option value="' + esc(value) + '">Recording #' + esc(value) + '</option>');
		}
	}

	function updateDidScopeEditorState() {
		var useSelectedRoutes = $('#rc-rule-did-mode').val() === 'selected';
		$('#rc-route-pick').prop('disabled', !useSelectedRoutes).toggleClass('rc-control-disabled', !useSelectedRoutes);
		$('#rc-add-did-include, #rc-add-did-exclude').prop('disabled', !useSelectedRoutes).toggleClass('disabled', !useSelectedRoutes);
		$('#rc-did-include-list, #rc-did-exclude-list').toggleClass('rc-control-disabled', !useSelectedRoutes).attr('aria-disabled', useSelectedRoutes ? 'false' : 'true');
	}

	function updateCallerScopeEditorState() {
		var callerMode = $('#rc-rule-caller-mode').val() || 'any';
		var requiresSpecificCallers = callerMode === 'specific_only';
		var includeEnabled = requiresSpecificCallers;
		var excludeEnabled = callerMode !== 'withheld_only';
		var includeUnavailableText = '';
		var excludeUnavailableText = '';
		var baseIncludeHelpText = 'Only these callers will trigger this rule.';
		var baseExcludeHelpText = 'Calls from these numbers will not trigger this rule.';
		var formatHint = getCallerFormatHint($('#rc-setting-country').val());
		var formatExample = getCallerFormatExample($('#rc-setting-country').val());
		var e164Example = getCallerE164Example($('#rc-setting-country').val());

		if (callerMode === 'any') {
			includeUnavailableText = 'This field is only used when Specific callers is selected.';
		} else if (callerMode === 'withheld_only') {
			includeUnavailableText = 'Caller number lists are not used for withheld-only rules.';
			excludeUnavailableText = 'Caller number lists are not used for withheld-only rules.';
		}

		var includeHelpText = baseIncludeHelpText + ' ' + formatHint;
		var excludeHelpText = baseExcludeHelpText + ' ' + formatHint;

		$('#rc-rule-caller-include').prop('disabled', !includeEnabled).toggleClass('rc-control-disabled', !includeEnabled).attr('aria-required', requiresSpecificCallers ? 'true' : 'false').attr('placeholder', formatExample);
		$('#rc-rule-caller-exclude').prop('disabled', !excludeEnabled).toggleClass('rc-control-disabled', !excludeEnabled).attr('placeholder', formatExample);
		$('#rc-rule-alert-call-callerid').attr('placeholder', e164Example);
		$('#rc-rule-alert-call-destination-input').attr('placeholder', '2001, 2002, ' + formatExample);
		$('#rc-caller-include-help').text(includeHelpText).toggleClass('text-danger', requiresSpecificCallers);
		$('#rc-caller-exclude-help').text(excludeHelpText);
		$('#rc-caller-include-unavailable').text(includeUnavailableText).toggleClass('hidden', includeUnavailableText === '');
		$('#rc-caller-exclude-unavailable').text(excludeUnavailableText).toggleClass('hidden', excludeUnavailableText === '');
		$('#rc-rule-exclude-withheld').prop('disabled', callerMode === 'withheld_only').toggleClass('disabled', callerMode === 'withheld_only');
	}

	function updateAlertCallAndEmailState() {
		var alertCallEnabled = $('#rc-rule-alert-call-enabled').is(':checked');
		var emailEnabled = $('#rc-rule-email-enabled').is(':checked');

		// Alert Call fields
		$('#rc-rule-alert-call-strategy').prop('disabled', !alertCallEnabled).toggleClass('rc-control-disabled', !alertCallEnabled);
		$('#rc-rule-alert-call-destination-input').prop('disabled', !alertCallEnabled).toggleClass('rc-control-disabled', !alertCallEnabled);
		$('#rc-rule-alert-call-destination-add').prop('disabled', !alertCallEnabled).toggleClass('btn-default', alertCallEnabled).toggleClass('btn-disabled', !alertCallEnabled);
		$('#rc-rule-alert-call-destination-list').find('input, button').prop('disabled', !alertCallEnabled).toggleClass('rc-control-disabled', !alertCallEnabled);
		$('#rc-rule-alert-call-recording-id').prop('disabled', !alertCallEnabled).toggleClass('rc-control-disabled', !alertCallEnabled);
		$('#rc-rule-alert-call-callerid').prop('disabled', !alertCallEnabled).toggleClass('rc-control-disabled', !alertCallEnabled);

		// Email fields
		$('#rc-rule-email-recipients').prop('disabled', !emailEnabled).toggleClass('rc-control-disabled', !emailEnabled);
	}

	function pad2(value) {
		return value < 10 ? ('0' + value) : String(value);
	}

	function isValidBoundedTimeValue(value) {
		return /^(?:[01]\d|2[0-3]):[0-5]\d$/.test(String(value || ''));
	}

	function splitBoundedTime(value, fallback) {
		if (isValidBoundedTimeValue(value)) {
			return String(value).split(':');
		}
		return String(fallback).split(':');
	}

	function buildHourOptions(includeAllDay) {
		var hourOptions = includeAllDay
			? ['<option value="all_day">24 Hours</option>', '<option value="00">00</option>']
			: ['<option value="00">00</option>'];
		for (var hour = 1; hour < 24; hour += 1) {
			hourOptions.push('<option value="' + pad2(hour) + '">' + pad2(hour) + '</option>');
		}
		return hourOptions;
	}

	function buildMinuteOptions() {
		var minuteOptions = ['<option value="00">00</option>'];
		for (var minute = 1; minute < 60; minute += 1) {
			minuteOptions.push('<option value="' + pad2(minute) + '">' + pad2(minute) + '</option>');
		}
		return minuteOptions;
	}

	function boundedTimeFromControls($hourSelect, $minuteSelect) {
		var hour = String($hourSelect.val() || '');
		var minute = String($minuteSelect.val() || '');
		var combined = hour + ':' + minute;
		return isValidBoundedTimeValue(combined) ? combined : '';
	}

	function parseScheduleDayValue(rawValue) {
		var value = String(rawValue || '');
		if (!/^-?\d+$/.test(value)) {
			return null;
		}
		var day = parseInt(value, 10);
		if (day < -1 || day > 6) {
			return null;
		}
		return day;
	}

	function addScheduleRow(day, start, end, allDayOverride) {
		var $row = $('<tr/>');
		var isAllDay = allDayOverride === true || (String(start || '') === '00:00' && String(end || '') === '24:00');
		var daySel = '<select class="form-control input-sm rc-schedule-day">'
			+ '<option value="-1">Any</option><option value="0">Sun</option><option value="1">Mon</option><option value="2">Tue</option><option value="3">Wed</option><option value="4">Thu</option><option value="5">Fri</option><option value="6">Sat</option></select>';
		var $daySelect = $(daySel).val(String(day === undefined ? 1 : day));
		var $startHour = $('<select class="form-control input-sm rc-schedule-start-hour">').html(buildHourOptions(true).join(''));
		var $startMinute = $('<select class="form-control input-sm rc-schedule-start-minute">').html(buildMinuteOptions().join(''));
		var $endHour = $('<select class="form-control input-sm rc-schedule-end-hour">').html(buildHourOptions(false).join(''));
		var $endMinute = $('<select class="form-control input-sm rc-schedule-end-minute">').html(buildMinuteOptions().join(''));
		var boundedStart = isValidBoundedTimeValue(start) ? String(start) : '09:00';
		var boundedEnd = isValidBoundedTimeValue(end) ? String(end) : '17:00';
		var startParts = splitBoundedTime(boundedStart, '09:00');
		var endParts = splitBoundedTime(boundedEnd, '17:00');
		$startHour.val(isAllDay ? 'all_day' : startParts[0]);
		$startMinute.val(startParts[1]);
		$endHour.val(endParts[0]);
		$endMinute.val(endParts[1]);
		$row.append($('<td/>').append($daySelect));
		$row.append($('<td/>').append($startHour).append(' ').append($startMinute));
		$row.append($('<td/>').append($endHour).append(' ').append($endMinute));
		$row.append($('<td/>').append($('<button type="button" class="btn btn-xs btn-danger rc-schedule-remove">x</button>').on('click', function () {
			$row.remove();
			normalizeScheduleEditorState();
			updateTableRowBatching('#rc-schedule-table');
		})));
		$row.find('.rc-schedule-day, .rc-schedule-start-hour, .rc-schedule-start-minute').on('change', function () {
			applyScheduleRowState($row);
			normalizeScheduleEditorState();
		});
		$row.find('.rc-schedule-end-hour, .rc-schedule-end-minute').on('change', function () {
			var endValue = boundedTimeFromControls($endHour, $endMinute);
			if (endValue) {
				$row.data('boundedEnd', endValue);
			}
		});
		applyScheduleRowState($row);
		$('#rc-schedule-table tbody').append($row);
		normalizeScheduleEditorState();
		updateTableRowBatching('#rc-schedule-table');
	}

	function applyScheduleRowState($row) {
		var $startHour = $row.find('.rc-schedule-start-hour');
		var $startMinute = $row.find('.rc-schedule-start-minute');
		var $endHour = $row.find('.rc-schedule-end-hour');
		var $endMinute = $row.find('.rc-schedule-end-minute');
		var isAllDay = $startHour.val() === 'all_day';
		var $endCell = $endHour.closest('td');
		if (isAllDay) {
			var currentEnd = boundedTimeFromControls($endHour, $endMinute);
			if (currentEnd) {
				$row.data('boundedEnd', currentEnd);
			}
			$startMinute.prop('disabled', true).addClass('disabled');
			$endHour.val('').prop('disabled', true).addClass('disabled');
			$endMinute.val('').prop('disabled', true).addClass('disabled');
			$endCell.addClass('text-muted');
			return;
		}
		if (!/^(?:[01]\d|2[0-3])$/.test(String($startHour.val() || ''))) {
			$startHour.val('09');
		}
		if (!/^[0-5]\d$/.test(String($startMinute.val() || ''))) {
			$startMinute.val('00');
		}
		$startMinute.prop('disabled', false).removeClass('disabled');
		if (!/^(?:[01]\d|2[0-3])$/.test(String($endHour.val() || '')) || !/^[0-5]\d$/.test(String($endMinute.val() || ''))) {
			var restoredEnd = splitBoundedTime(String($row.data('boundedEnd') || '17:00'), '17:00');
			$endHour.val(restoredEnd[0]);
			$endMinute.val(restoredEnd[1]);
		}
		$endHour.prop('disabled', false).removeClass('disabled');
		$endMinute.prop('disabled', false).removeClass('disabled');
		$endCell.removeClass('text-muted');
	}

	function normalizeScheduleEditorState() {
		var $rows = $('#rc-schedule-table tbody tr');
		var $continuousRow = null;
		$rows.each(function () {
			var $row = $(this);
			var day = parseScheduleDayValue($row.find('.rc-schedule-day').val());
			var allDay = $row.find('.rc-schedule-start-hour').val() === 'all_day';
			if (day === -1 && allDay) {
				$continuousRow = $row;
				return false;
			}
		});

		if ($continuousRow) {
			$rows.not($continuousRow).remove();
			$('#rc-add-schedule').prop('disabled', true).addClass('disabled');
		} else {
			$('#rc-add-schedule').prop('disabled', false).removeClass('disabled');
		}

		if (!$('#rc-schedule-table tbody tr').length) {
			$('#rc-add-schedule').prop('disabled', false).removeClass('disabled');
			addScheduleRow();
		}
		updateTableRowBatching('#rc-schedule-table');
	}

	function collectSchedules() {
		var schedules = [];
		var bad = false;
		var hasContinuous = false;
		$('#rc-schedule-table tbody tr').each(function () {
			var day = parseScheduleDayValue($(this).find('.rc-schedule-day').val());
			var $startHour = $(this).find('.rc-schedule-start-hour');
			var $startMinute = $(this).find('.rc-schedule-start-minute');
			var $endHour = $(this).find('.rc-schedule-end-hour');
			var $endMinute = $(this).find('.rc-schedule-end-minute');
			var allDay = String($startHour.val() || '') === 'all_day';
			if (day === null) {
				bad = true;
				return false;
			}
			if (allDay) {
				schedules.push({day: day, start: '', end: '', all_day: 1});
				if (day === -1) {
					hasContinuous = true;
					return false;
				}
				return;
			}
			var start = boundedTimeFromControls($startHour, $startMinute);
			var end = boundedTimeFromControls($endHour, $endMinute);
			if (!start || !end || start >= end) {
				bad = true;
				return false;
			}
			schedules.push({day: day, start: start, end: end});
		});
		if (bad) {
			showMessage('Invalid schedule period. Overnight ranges are not supported in this phase.', 'error');
			return null;
		}
		if (hasContinuous) {
			return [{day: -1, start: '', end: '', all_day: 1}];
		}
		return schedules;
	}

	function normaliseRuleEmailRecipients(rawValue) {
		var items = [];
		$.each(String(rawValue || '').split(/[,;\n\r]+/), function (_, part) {
			var email = $.trim(String(part || ''));
			if (email !== '') {
				items.push(email);
			}
		});
		return items;
	}

	function isValidRuleEmailRecipient(email) {
		var candidate = $.trim(String(email || ''));
		if (candidate === '' || candidate.indexOf('@') === -1 || candidate.indexOf('.') === -1) {
			return false;
		}
		var parts = candidate.split(/[@.]/).filter(function (part) {
			return $.trim(part) !== '';
		});
		if (parts.length < 3) {
			return false;
		}
		return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(candidate);
	}

	function saveRule(onDone) {
		var schedules = collectSchedules();
		if (schedules === null) {
			if (onDone) { onDone(); }
			return;
		}

		var emailEnabled = $('#rc-rule-email-enabled').is(':checked');
		var callEnabled = $('#rc-rule-alert-call-enabled').is(':checked');
		var emailRecipients = normaliseRuleEmailRecipients($('#rc-rule-email-recipients').val());
		if (emailEnabled) {
			if (!emailRecipients.length) {
				showMessage('Email is enabled. Enter at least one recipient address.', 'error');
				if (onDone) { onDone(); }
				return;
			}
			for (var i = 0; i < emailRecipients.length; i += 1) {
				if (!isValidRuleEmailRecipient(emailRecipients[i])) {
					showMessage('Email recipients must include an @, a ., and at least three parts (for example: alerts@example.com).', 'error');
					if (onDone) { onDone(); }
					return;
				}
			}
		}

		var callerIncludes = String($('#rc-rule-caller-include').val() || '').split(/\n+/).map(function (v) { return $.trim(v); }).filter(Boolean);
		var callerExcludes = String($('#rc-rule-caller-exclude').val() || '').split(/\n+/).map(function (v) { return $.trim(v); }).filter(Boolean);
		var callers = [];
		$.each(callerIncludes, function (_, value) { callers.push({list_type: 'include', raw_value: value}); });
		$.each(callerExcludes, function (_, value) { callers.push({list_type: 'exclude', raw_value: value}); });
		var dids = collectRouteList($('#rc-did-include-list')).concat(collectRouteList($('#rc-did-exclude-list')));
		updateAlertCallDestinationHiddenField();
		var callDestinations = normaliseAlertCallDestinationEntries($('#rc-rule-alert-call-destinations').val(), true);
		if (callEnabled && !callDestinations.length) {
			showMessage('Alert Call is enabled. Enter at least one Alert Call destination.', 'error');
			if (onDone) { onDone(); }
			return;
		}

		ajax('saverule', {
			rule_id: $('#rc-rule-id').val(),
			name: $('#rc-rule-name').val(),
			enabled: $('#rc-rule-enabled').is(':checked') ? 1 : 0,
			mode: $('#rc-rule-mode').val(),
			threshold_count: $('#rc-rule-threshold').val(),
			observation_window_minutes: $('#rc-rule-window').val(),
			suppression_minutes_override: $('#rc-rule-suppression').val(),
			repeat_mode_override: $('#rc-rule-repeat').val(),
			email_recipients: emailRecipients.join(', '),
			caller_mode: $('#rc-rule-caller-mode').val(),
			exclude_withheld: $('#rc-rule-exclude-withheld').is(':checked') ? 1 : 0,
			did_scope_mode: $('#rc-rule-did-mode').val(),
			email_enabled: $('#rc-rule-email-enabled').is(':checked') ? 1 : 0,
			alert_call_enabled: $('#rc-rule-alert-call-enabled').is(':checked') ? 1 : 0,
			alert_call_strategy: $('#rc-rule-alert-call-strategy').val(),
			alert_call_keep_trying: 1,
			alert_call_destinations: $('#rc-rule-alert-call-destinations').val(),
			alert_call_recording_id: $('#rc-rule-alert-call-recording-id').val(),
			alert_call_callerid: $('#rc-rule-alert-call-callerid').val(),
			schedules: JSON.stringify(schedules),
			callers: JSON.stringify(callers),
			dids: JSON.stringify(dids)
		}, function (response) {
			showMessage('Rule saved.', 'success');
			if (response && $.isArray(response.rules)) {
				renderRules(response.rules || []);
			} else {
				loadRules();
			}
			resetRuleEditor();
			loadEngineStatus();
			scrollToPageTop();
		}, onDone);
	}

	function loadRule(id) {
		ajax('getrule', {rule_id: id}, function (response) {
			var rule = response.rule || {};
			$('.rc-editor-panel').addClass('rc-editor-edit-mode');
			updateRuleEditorTitle(true);
			setEditingRuleRow(rule.id || id);
			$('#rc-cancel-edit').removeClass('hidden');
			$('#rc-rule-id').val(rule.id || 0);
			$('#rc-rule-name').val(rule.name || '');
			$('#rc-rule-enabled').prop('checked', parseInt(rule.enabled || 0, 10) === 1);
			$('#rc-rule-mode').val(rule.mode || 'repeat');
			$('#rc-rule-threshold').val(rule.threshold_count || 2);
			$('#rc-rule-window').val(rule.observation_window_minutes || 60);
			if (rule.suppression_minutes_override === null || typeof rule.suppression_minutes_override === 'undefined' || String(rule.suppression_minutes_override) === '') {
				$('#rc-rule-suppression').val('');
			} else {
				$('#rc-rule-suppression').val(rule.suppression_minutes_override);
			}
			$('#rc-rule-repeat').val(rule.repeat_mode_override || 'never');
			$('#rc-rule-email-recipients').val(rule.email_recipients || '');
			$('#rc-rule-caller-mode').val(rule.caller_mode || 'any');
			$('#rc-rule-exclude-withheld').prop('checked', parseInt(rule.exclude_withheld || 0, 10) === 1);
			updateCallerScopeEditorState();
			$('#rc-rule-did-mode').val(rule.did_scope_mode || 'all');
			updateDidScopeEditorState();
			$('#rc-rule-email-enabled').prop('checked', parseInt(rule.email_enabled || 0, 10) === 1);
			$('#rc-rule-alert-call-enabled').prop('checked', parseInt(rule.alert_call_enabled || 0, 10) === 1);
			$('#rc-rule-alert-call-strategy').val(rule.alert_call_strategy || 'ringall');
			renderAlertCallDestinations(rule.alert_call_destinations || '', true);
			ensureRecordingOptionExists(rule.alert_call_recording_id);
			$('#rc-rule-alert-call-recording-id').val(rule.alert_call_recording_id || '');
			$('#rc-rule-alert-call-callerid').val(rule.alert_call_callerid || '');
			var includeCallers = [];
			var excludeCallers = [];
			$.each((rule.caller_lists && rule.caller_lists.include) || [], function (_, row) { includeCallers.push(row.raw_value || row.normalized_value || ''); });
			$.each((rule.caller_lists && rule.caller_lists.exclude) || [], function (_, row) { excludeCallers.push(row.raw_value || row.normalized_value || ''); });
			$('#rc-rule-caller-include').val(includeCallers.join('\n'));
			$('#rc-rule-caller-exclude').val(excludeCallers.join('\n'));

			$('#rc-did-include-list').empty();
			$('#rc-did-exclude-list').empty();
			$.each((rule.did_lists && rule.did_lists.include) || [], function (_, row) { addRouteToList($('#rc-did-include-list'), row, 'include'); });
			$.each((rule.did_lists && rule.did_lists.exclude) || [], function (_, row) { addRouteToList($('#rc-did-exclude-list'), row, 'exclude'); });

			$('#rc-schedule-table tbody').empty();
			$.each(rule.schedules || [], function (_, s) {
				var rowDay = parseInt(s.day, 10);
				var rowStart = String(s.start || '').substring(0, 5);
				var rowEnd = String(s.end || '').substring(0, 5);
				var isAllDay = parseInt(s.all_day || 0, 10) === 1 || (rowStart === '00:00' && rowEnd === '24:00');
				addScheduleRow(rowDay, rowStart, rowEnd, isAllDay);
			});
			if (!rule.schedules || !rule.schedules.length) {
				addScheduleRow();
			}
			normalizeScheduleEditorState();
			updateAlertCallAndEmailState();
			scrollToRuleEditor();
		});
	}

	function loadRules() {
		return ajax('getrules', {}, function (response) {
			renderRules(response.rules || []);
		});
	}

	function loadActiveIncidents(options) {
		var opts = options || {};
		if ($('#rc-active-incidents-table').length) {
			return ajax('getincidents', {view: 'active'}, function (response) {
				renderIncidents('#rc-active-incidents-table', response.incidents || [], true);
			}, null, {silent: !!opts.silent});
		}
		return $.Deferred().resolve().promise();
	}

	function loadClaimedIncidents(options) {
		var opts = options || {};
		if ($('#rc-recent-incidents-table').length) {
			return ajax('getincidents', {view: 'claimed'}, function (response) {
				renderIncidents('#rc-recent-incidents-table', response.incidents || [], false);
			}, null, {silent: !!opts.silent});
		}
		return $.Deferred().resolve().promise();
	}

	function loadIncidents(options) {
		return $.when(loadActiveIncidents(options), loadClaimedIncidents(options), loadSuppressedIncidents(options));
	}

	function loadSuppressedIncidents(options) {
		var opts = options || {};
		if (!$('#rc-suppressed-incidents-table').length) {
			return $.Deferred().resolve().promise();
		}
		return ajax('getsuppressedincidents', {}, function (response) {
			renderSuppressedIncidents(response.suppressedIncidents || []);
		}, null, {silent: !!opts.silent});
	}

	function loadAlertHistory(options) {
		var opts = options || {};
		if (!$('#rc-alert-history-table').length) {
			return $.Deferred().resolve().promise();
		}
		return ajax('getalerthistory', {}, function (response) {
			renderAlertHistory(response.alertHistory || []);
		}, null, {silent: !!opts.silent});
	}

	function loadEngineStatus(options) {
		var opts = options || {};
		return ajax('getenginestatus', {}, function (response) {
			renderEngine(response.engineStatus || {});
		}, null, {silent: !!opts.silent});
	}

	function loadInboundRoutes() {
		ajax('getinboundroutes', {}, function (response) {
			var options = [];
			$.each(response.routes || [], function (_, r) {
				options.push('<option value="' + esc(r.route_key) + '">' + esc((r.route_label || r.route_key) + ' [' + r.route_key + ']') + '</option>');
			});
			$('#rc-route-pick').html(options.join(''));
			window._rcInboundRoutes = response.routes || [];
			updateDidScopeEditorState();
		});
	}

	function findRoute(routeKey) {
		var routes = window._rcInboundRoutes || [];
		var found = null;
		$.each(routes, function (_, r) {
			if (String(r.route_key) === String(routeKey)) {
				found = r;
				return false;
			}
		});
		return found;
	}

	function saveGlobalSettings(enabledOverride, onDone) {
		var payload = {
			default_country_code: $('#rc-setting-country').val(),
			incident_history_prune_policy: $('#rc-setting-incident-prune').val(),
			alert_history_prune_policy: $('#rc-setting-alert-prune').val(),
			suppression_history_prune_policy: $('#rc-setting-suppression-prune').val()
		};
		if (enabledOverride !== undefined && enabledOverride !== null) {
			payload.enabled = enabledOverride;
		}
		ajax('saveglobalsettings', payload, function (response) {
			showMessage(response.message || 'Settings saved.', 'success');
			renderEngine(response.engineStatus || {});
			scrollToPageTop();
		}, onDone);
	}

	function bindEvents() {
		$(document).off('click.repeatcaller', '.rc-table-show-more').on('click.repeatcaller', '.rc-table-show-more', function () {
			var tableId = $.trim(String($(this).closest('.rc-table-batch-controls').attr('data-table-id') || ''));
			if (tableId === '') {
				return;
			}
			var selector = '#' + tableId;
			var state = ensureTableBatchState(selector);
			state.visibleUnits += tableBatchSize;
			updateTableRowBatching(selector);
		});

		$(document).off('click.repeatcaller', '.rc-table-show-less').on('click.repeatcaller', '.rc-table-show-less', function () {
			var tableId = $.trim(String($(this).closest('.rc-table-batch-controls').attr('data-table-id') || ''));
			if (tableId === '') {
				return;
			}
			var selector = '#' + tableId;
			var state = ensureTableBatchState(selector);
			state.visibleUnits = tableBatchSize;
			updateTableRowBatching(selector);
		});

		$('#rc-rule-alert-call-destination-add').off('click.repeatcaller').on('click.repeatcaller', function () {
			addAlertCallDestinationsFromInput();
		});
		$('#rc-rule-alert-call-destination-input').off('keydown.repeatcaller').on('keydown.repeatcaller', function (event) {
			if (event.key === 'Enter') {
				event.preventDefault();
				addAlertCallDestinationsFromInput();
			}
		});

		$('#rc-run-now').off('click.repeatcaller').on('click.repeatcaller', function () {
			var $button = $(this);
			if ($button.prop('disabled')) {
				return;
			}
			setRunStatusRunningFromRunStart();
			beginAction();
			withBusy($button, function (done) {
				$button.text('Processing...');
				ajax('runmonitor', {}, function (response) {
					showMessage(response.message || 'Monitor run completed.', response.already_running ? 'error' : 'success');
					renderEngine(response.engineStatus || {});
					var refreshes = $.when(
						loadRules(),
						loadIncidents(),
						loadAlertHistory()
					);

					syncChangeToken();

					refreshes.done(function () {
						$('html, body').stop(true).animate({ scrollTop: 0 }, 300);
					});
				}, function () {
					var statusRefresh = loadEngineStatus({silent: true});
					if (statusRefresh && typeof statusRefresh.always === 'function') {
						statusRefresh.always(function () {
							done();
							endAction();
							updateRunNowButtonState();
						});
						return;
					}
					done();
					endAction();
					updateRunNowButtonState();
				});
			});
		});

		$('#rc-enable').off('click.repeatcaller').on('click.repeatcaller', function () {
			var $button = $(this);
			var oldText = $button.text();
			beginAction();
			$('#rc-enable, #rc-disable').prop('disabled', true).addClass('disabled');
			$button.text('Enabling...');
			saveGlobalSettings(1, function () {
				$button.text(oldText);
				$button.blur();
				loadEngineStatus();
				syncChangeToken();
				endAction();
			});
		});
		$('#rc-disable').off('click.repeatcaller').on('click.repeatcaller', function () {
			var $button = $(this);
			var oldText = $button.text();
			beginAction();
			$('#rc-enable, #rc-disable').prop('disabled', true).addClass('disabled');
			$button.text('Disabling...');
			saveGlobalSettings(0, function () {
				$button.text(oldText);
				$button.blur();
				loadEngineStatus();
				syncChangeToken();
				endAction();
			});
		});
		$('#rc-save-global').off('click.repeatcaller').on('click.repeatcaller', function () {
			var $button = $(this);
			beginAction();
			withBusy($button, function (done) {
				$button.text('Saving...');
				saveGlobalSettings(undefined, function () {
					done();
					syncChangeToken();
					endAction();
				});
			});
		});
		$('#rc-setting-country').off('change.repeatcaller input.repeatcaller keyup.repeatcaller').on('change.repeatcaller input.repeatcaller keyup.repeatcaller', function () { updateCallerScopeEditorState(); });
		$('#rc-prune-now').off('click.repeatcaller').on('click.repeatcaller', function () {
			if (!window.confirm('Run pruning now using the selected retention policies? This removes eligible historical rows and cannot be undone.')) {
				return;
			}
			beginAction();
			ajax('prunehistory', {
				incident_history_prune_policy: $('#rc-setting-incident-prune').val(),
				alert_history_prune_policy: $('#rc-setting-alert-prune').val(),
				suppression_history_prune_policy: $('#rc-setting-suppression-prune').val()
			}, function (response) {
				showMessage(response.message || 'Pruning completed.', 'success');
				renderAlertHistory(response.alertHistory || []);
				loadSuppressedIncidents({silent: true});
				renderIncidents('#rc-recent-incidents-table', response.recentIncidents || [], false);
				loadEngineStatus();
				syncChangeToken();
			}, function () {
				endAction();
			});
		});

		$('#rc-clear-alert-history').off('click.repeatcaller').on('click.repeatcaller', function () {
			if (!window.confirm('Clear all Alert History rows? This cannot be undone.')) {
				return;
			}
			beginAction();
			ajax('clearalerthistory', {}, function (response) {
				var deletedCount = parseInt((response && response.deleted_count) || 0, 10) || 0;
				showMessage('Alert History cleared (' + deletedCount + ' rows).', 'success');
				renderAlertHistory((response && response.alertHistory) || []);
				syncChangeToken();
			}, function () {
				endAction();
			});
		});

		$(document).off('click.repeatcaller', '.rc-clear-suppression').on('click.repeatcaller', '.rc-clear-suppression', function () {
			var $button = $(this);
			var historyId = parseInt($button.data('suppression-history-id') || 0, 10);
			if (!historyId) {
				return;
			}
			if (!window.confirm('Clear this suppression and allow the subject to trigger again?')) {
				return;
			}
			beginAction();
			withBusy($button, function (done) {
				$button.text('Clearing...');
				ajax('clearsuppression', {suppression_history_id: historyId}, function (response) {
					showMessage(response.message || 'Suppression cleared.', 'success');
					renderSuppressedIncidents(response.suppressedIncidents || []);
					renderEngine(response.engineStatus || {});
					syncChangeToken();
					scrollToPageTop();
				}, function () {
					done();
					endAction();
				});
			});
		});

		$(document).off('click.repeatcaller', '.rc-snooze').on('click.repeatcaller', '.rc-snooze', function () {
			var $button = $(this);
			var oldText = $button.text();
			beginAction();
			$('.rc-snooze, #rc-resume').prop('disabled', true).addClass('disabled');
			$button.text('Snoozing...');
			ajax('setsnooze', {seconds: $(this).data('seconds')}, function (response) {
				showMessage(response.message || 'Monitoring snoozed.', 'success');
				renderEngine(response.engineStatus || {});
				syncChangeToken();
			}, function () {
				$button.text(oldText);
				$button.blur();
				loadEngineStatus();
				endAction();
			});
		});

		$('#rc-resume').off('click.repeatcaller').on('click.repeatcaller', function () {
			var $button = $(this);
			var oldText = $button.text();
			beginAction();
			$('.rc-snooze, #rc-resume').prop('disabled', true).addClass('disabled');
			$button.text('Resuming...');
			ajax('resumemonitoring', {}, function (response) {
				showMessage(response.message || 'Monitoring resumed.', 'success');
				renderEngine(response.engineStatus || {});
				syncChangeToken();
			}, function () {
				$button.text(oldText);
				$button.blur();
				loadEngineStatus();
				endAction();
			});
		});

		$('#rc-cancel-edit').off('click.repeatcaller').on('click.repeatcaller', function () { resetRuleEditor(); });
		$('#rc-add-schedule').off('click.repeatcaller').on('click.repeatcaller', function () { addScheduleRow(); });
		$('#rc-rule-caller-mode').off('change.repeatcaller').on('change.repeatcaller', function () { updateCallerScopeEditorState(); });
		$('#rc-rule-did-mode').off('change.repeatcaller').on('change.repeatcaller', function () { updateDidScopeEditorState(); });
		$('#rc-rule-alert-call-enabled').off('change.repeatcaller').on('change.repeatcaller', function () { updateAlertCallAndEmailState(); });
		$('#rc-rule-email-enabled').off('change.repeatcaller').on('change.repeatcaller', function () { updateAlertCallAndEmailState(); });
		$('#rc-save-rule').off('click.repeatcaller').on('click.repeatcaller', function () {
			var $button = $(this);
			beginAction();
			withBusy($button, function (done) {
				$button.text('Saving...');
				saveRule(function () {
					done();
					syncChangeToken();
					endAction();
				});
			});
		});

		$('#rc-add-did-include').off('click.repeatcaller').on('click.repeatcaller', function () {
			var route = findRoute($('#rc-route-pick').val());
			addRouteToList($('#rc-did-include-list'), route, 'include');
		});
		$('#rc-add-did-exclude').off('click.repeatcaller').on('click.repeatcaller', function () {
			var route = findRoute($('#rc-route-pick').val());
			addRouteToList($('#rc-did-exclude-list'), route, 'exclude');
		});

		$(document).off('click.repeatcaller', '.rc-edit-rule').on('click.repeatcaller', '.rc-edit-rule', function () {
			loadRule($(this).closest('tr').data('rule-id'));
		});
		$(document).off('click.repeatcaller', '.rc-rule-status').on('click.repeatcaller', '.rc-rule-status', function () {
			showRuleStatus($(this).closest('tr').data('rule-id'), $(this));
		});
		$(document).off('click.repeatcaller', '.rc-delete-rule').on('click.repeatcaller', '.rc-delete-rule', function () {
			var ruleId = $(this).closest('tr').data('rule-id');
			if (!window.confirm('Delete this rule? Historical incidents and alerts are preserved.')) {
				return;
			}
			beginAction();
			ajax('deleterule', {rule_id: ruleId}, function (response) {
				showMessage(response.message || 'Rule deleted.', 'success');
				renderRules(response.rules || []);
				loadEngineStatus();
				syncChangeToken();
			}, function () {
				endAction();
			});
		});

		$(document).off('change.repeatcaller', '.rc-rule-enabled-toggle').on('change.repeatcaller', '.rc-rule-enabled-toggle', function () {
			var ruleId = $(this).closest('tr').data('rule-id');
			beginAction();
			ajax('setruleenabled', {rule_id: ruleId, enabled: $(this).is(':checked') ? 1 : 0}, function (response) {
				renderRules(response.rules || []);
				loadEngineStatus();
				syncChangeToken();
			}, function () {
				endAction();
			});
		});

		$(document).off('click.repeatcaller', '.rc-claim-incident').on('click.repeatcaller', '.rc-claim-incident', function () {
			var incidentId = $(this).closest('tr').data('incident-id');
			beginAction();
			ajax('claimincident', {incident_id: incidentId}, function (response) {
				showMessage(response.message || 'Incident accepted.', 'success');
				renderIncidents('#rc-active-incidents-table', response.activeIncidents || [], true);
				renderIncidents('#rc-recent-incidents-table', response.recentIncidents || [], false);
				loadEngineStatus();
				syncChangeToken();
			}, function () {
				endAction();
			});
		});
	}

	$(function () {
		loadSystemRecordingsLookupFromBootstrap();
		syncLiveClockFromValue('pbx', $('#rc-pbx-time').text(), true);
		bindEvents();
		resetRuleEditor();
		loadInboundRoutes();
		loadEngineStatus();
		loadRules();
		loadIncidents();
		loadAlertHistory();
		updateAllTableRowBatching();
		setupAutoRefreshPolling();
	});
})(jQuery);

# Repeat Caller User Guide

## What Repeat Caller Does

Repeat Caller supports two distinct detection modes:

- Repeat mode: detects when the same caller reaches the configured threshold within the selected time window. Use this for repeated contact attempts, urgent repeat enquiries, or nuisance/repeat calling scenarios.
- Invert mode: detects when fewer than the configured number of matching calls occur within the selected time window. Use this for expected check-ins, lone-worker workflows, welfare checks, scheduled contact, or missing expected activity.

When a rule condition is met, Repeat Caller creates an incident so a team member can respond.

It does not only count missed calls. Answered, busy, failed, abandoned, and other qualifying inbound journeys may count when they match your rule. The configured caller scope, route scope, schedule, threshold, and window decide what counts.

## Before You Begin

Before configuring rules, confirm:

- the module is installed
- the Repeat Caller background job is present
- CDR records are available
- Inbound Routes are configured
- FreePBX email is configured if Email alerts are required
- Alert Call destinations and optional System Recording are available if Alert Call is required

For technical requirements and install/update details, see README.md.

## Opening Repeat Caller

Open:

Reports > Repeat Caller

Main sections:

- Engine Status
- Active Incidents
- Global Settings
- Rules
- Add Rule (switches to Editing Rule when editing an existing rule)
- Recent Incidents
- Alert History
- Run Now

The top Engine Status section contains the operational controls: Enable All Rules, Disable All Rules, Snooze, Resume All Rules, and Run Now.

## First-Time Setup

1. Open Global Settings.
2. Select Enable All Rules globally.
3. Set the default country code if needed.
4. Configure incident, alert, and suppression history retention policies.
5. Configure rule conditions, alert actions, and schedules as required.
6. Save Global Settings.
7. Create your first rule.
8. Run a controlled test and review results.

This establishes your baseline detection behaviour, incident lifecycle defaults, and notification channels.

## Creating Your First Rule

Example objective:
Create an incident when the same caller rings the same inbound route 3 times within 10 minutes.

Suggested values:

- Rule Name: Repeated Caller
- Start as: Enabled
- Mode: Repeat
- Threshold: 3
- Window: 10
- Caller Scope: Any Caller
- DID Scope: choose your intended route scope
- Schedules: Any day, 24 hours
- GUI: enabled
- Alert Call: optional
- Email: optional

Save the rule, place controlled test calls, then verify Active Incidents and Alert History.

## Rule Settings Explained

- Rule Name: identifies the rule in tables and alerts.
- Start as: used only when creating a new rule to choose whether the rule
	starts enabled or disabled. When editing an existing rule, the current
	state is shown but can only be changed from the main table.
- Mode: Repeat or Invert detection logic. Use Repeat for repeated contact attempts; use Invert when you expect activity and need to detect when it does not occur.
- Threshold: number of matching calls required for rule evaluation.
- Window: observation period in minutes.
- Suppression: controls incident lifecycle hold period before expiry/re-arm logic.
	Leave blank to use the default 24 hours (1440 minutes). Enter 0 to disable
	automatic suppression for that rule.
- Alert Reminders: reminder timing for active incidents.
- Caller Scope: Any caller, withheld-only, or specific callers.
- Exclude withheld callers: excludes withheld identities from this rule.
- Caller Includes: callers to include when using specific-caller scope. Enter caller numbers separated by spaces, commas or new lines. Mixed separators are supported. Values are saved in a normalized comma-separated format.
- Caller Excludes: callers to exclude. Enter caller numbers separated by spaces, commas or new lines. Mixed separators are supported. Values are saved in a normalized comma-separated format.
- DID Scope: All DIDs or Selected DIDs only.
- Inbound Routes: route selector used for DID include/exclude actions.
- Included Routes: routes monitored when Selected DIDs only is used.
- Excluded Routes: routes excluded when All DIDs is used.
- Schedules: day/time periods when calls count for this rule.
- GUI: always enabled history action.
- Alert Call: optional phone-call notifications.
- Email: optional email notifications.
- Alert Call Destinations: accepts one or more internal extensions and/or external telephone numbers separated by commas. External numbers should normally be entered in the same national dialling format an administrator would use from a FreePBX extension. The example/placeholder follows the configured Default Country Code.
- Alert Call destination safeguard: adding an Alert Call destination behaves the same whether you use Add or Enter. It automatically adds the same value to Ignore these callers and shows a one-time warning. Alert Call destinations are automatically added to Ignore these callers to reduce the risk of self-triggering if an alert call routes back through a monitored DID.
- Alert Call Caller ID safeguard: when Alert Call is enabled and Caller ID managed elsewhere is off, Repeat Caller may also add the configured Alert Call Caller ID to Ignore these callers as an additional safeguard for external return paths. Administrators can remove the Ignore entry if it is not appropriate.

Alert reminder emails now show one reminder line:

- Alert Reminder: displays the Alert Reminder scheduling actually used for that alert, such as Never, Hourly, Daily, or Escalating.

Alert reminder emails also start with the FreePBX System Identifier when it is available, for example: Repeat Caller incident alert from MY-PBX-NAME. If the identifier is unavailable, the email uses a sensible fallback.
- Ring All: attempts all currently eligible destinations for that reminder point.
- Ordered: attempts destinations in saved order, moving forward when unaccepted.
- Keep Trying: controls whether unsuccessful destinations remain eligible later.
- System Recording: optional recording played before generated message.
- Caller ID managed elsewhere: enabled by default for new rules. Repeat Caller will not set the Caller ID for Alert Calls. Caller presentation is managed elsewhere, for example by Outbound Routes, trunks, another module, an SBC, or your network provider. When you tick this option, the Alert Call Caller ID field is cleared immediately, disabled, and its example placeholder disappears.
- Alert Call Caller ID: sets the caller ID presented on outbound alert calls. The preferred format is international E.164 with a leading +, for example +447812345678. When the field is editable and empty, the example placeholder follows the configured Default Country Code.

If you untick Caller ID managed elsewhere while Alert Call is enabled, Alert Call Caller ID becomes mandatory and the previous unsaved value is restored automatically if one was entered earlier in the session. Saving with Caller ID managed elsewhere still checked persists a blank Caller ID and permanently forgets the prior value.

Internal safeguard note:

- Repeat Caller marks module-originated internal Alert Call legs and excludes those marked internal legs from detection.
- This marker does not survive an external PSTN leave-and-return path where the call re-enters as a new inbound journey.
- Carrier rewriting and changed caller presentation may still require administrator judgement.

In the current editor layout, Email Recipients is positioned directly above the
Save Rule action row.

## Rule Row Controls

In the Rules table, each rule row has three controls:

- Status: temporarily replaces the explainer sentence under the row with a
	plain-English status summary for that rule only. The summary separates
	progress toward threshold (within the configured alert window), incident
	lifecycle state, and monitor freshness. The status view remains visible for
	15 seconds, then reverts automatically.
- Edit: opens that rule in the editor.
- X: deletes the rule after confirmation.

When Status is active, only the explainer bar is highlighted in light grey.
The rest of the row is unchanged.

While editing an existing rule, the row actions for that rule are greyed out
and cannot be used until you save or cancel the edit.

## Table Row Display

Repeat Caller admin tables show up to 15 rows by default.

- If more rows exist, Show more reveals the next 15 rows.
- Show less returns the table to the initial 15-row view.
- This is a presentation control only; backend retrieval and incident logic are
	unchanged.

## Repeat Mode

Repeat mode creates an incident when matching call count reaches the threshold within the configured window.

Examples:

- 3 calls within 10 minutes
- 5 calls within 30 minutes

When route-aware identity applies, the same caller reaching different inbound routes is tracked separately.

## Invert Mode

Invert mode checks a complete observation window and creates an incident when the threshold was not reached.

Example:

Create an incident when fewer than 3 matching calls occur during a complete 10-minute window.

Invert does not trigger immediately at the start of a window; it evaluates after the window has elapsed.

## Caller and Route Scope

Caller controls:

- Any Caller
- Specific callers via Caller Includes and Caller Excludes
- Withheld handling through caller-scope options and Exclude withheld callers

Route controls:

- All DIDs with optional Excluded Routes
- Selected DIDs only via Included Routes

All DIDs matches every inbound route except routes listed in Excluded Routes.
Selected DIDs only matches routes listed in Included Routes.
Route scope follows your FreePBX Inbound Routes configuration.

## Schedules

Schedules control when calls count for a rule.

- Any day, 24 hours covers all times.
- Specific day/time rows limit when matching calls are counted.
- Calls outside schedule do not count for that rule.
- Overnight ranges are not supported in this release and should be split or avoided.

## Threshold Evaluation in Repeat Mode

Repeat mode uses a rolling observation window. The monitor evaluates each
new call within the most recent N minutes, where N is the configured
observation window. Each call creates a fresh window evaluation looking back
N minutes from that call's time.

Example: a 30-minute window rule at 09:11 looks back to 08:41 and sees 3
matching calls (threshold met). At 09:26, the same caller triggers a fresh
evaluation: the window looks back to 08:56 and may see more recent calls. At
09:57, the window looks back to 09:27 and may see fewer calls in that recent
window.

The threshold latch prevents re-triggering until the rolling call count falls
below the threshold, the monitor observes that clear state, and new qualifying
calls occur after the clear is recorded.

## Suppression

Suppression is an incident-lifecycle control.

Repeat Caller uses a default 24 hours (1440 minutes) suppression period when no
rule override is set.

Rule-level Suppression override replaces that default for the rule.

Rule-level Suppression values behave as follows:

- blank: use the default 24 hours (1440 minutes) suppression period
- numeric value: override the default for the rule
- 0: disable automatic suppression for that rule

Suppression determines how long a new qualifying episode for the same rule
and subject is blocked after the current condition has cleared and the latch
has re-armed.

Suppression lifecycle timing:

- Suppression starts when an incident is created. The suppression period is
	calculated from the moment of incident creation and stored immediately on
	the incident row.
- Accepting an incident records responsibility and controls further alerts for
	the existing incident. Acceptance does not start, extend, or reset the
	suppression period.
- While the qualifying condition remains true (rolling call count for Repeat,
	or continuing failed windows for Invert), further matching activity updates
	the accepted incident and does not create suppression-history rows.
- The qualifying condition must first clear: the rolling call count must fall
	below the configured threshold (Repeat), or a window must meet or exceed
	the threshold (Invert).
- The monitor must observe that clear state and re-arm the threshold latch.
- If a new qualifying episode for the same rule and subject occurs after the
	condition has cleared and the latch has re-armed, but before suppression
	expires, Repeat Caller blocks that new episode and records it in Suppressed
	Incidents.
- The threshold latch is intentional and prevents duplicate suppression rows
	while one unbroken qualifying condition is still in progress. After clear
	is observed, new qualifying thresholds can trigger again.

Suppression examples:

- Repeat: a rule triggers at 10:05 with 30-minute suppression. Any new
	qualifying call sequence between 10:05 and 10:35 (after the rolling count
	drops and the latch re-arms) is blocked and recorded in Suppressed Incidents.
- Invert: a rule's first failing window closes at 10:00 with 60-minute
	suppression. Any new failing window between 10:00 and 11:00 (after a passing
	window clears the incident and the latch re-arms) is blocked and recorded in
	Suppressed Incidents.

Comparison:

- Suppression: incident lifecycle timing
- Alert Reminders: reminder timing for active incidents
- Snooze Monitoring: temporary pause for Alert Call and Email delivery

## Alert Reminders

Alert Reminders options:

- Never
- Every 5 Minutes
- Hourly
- Daily
- Escalating

These reminders apply to incidents that are already active.

Escalating starts with shorter reminder intervals and gradually increases them up to a daily interval.

Alert Reminders control reminder delivery cadence for incidents that are already
active. They do not change incident retention or prune historical rows.

One complete Ordered Alert Call progression is one alert cycle. Alert Reminders do not begin while that cycle is still active, pending, or deferred. The reminder interval begins only after the complete cycle finishes without acceptance. Each reminder starts a new complete Ordered sequence from stage 0, so alert cycles never overlap. Ring All follows the same model as a single-stage cycle.

## Understanding Incidents

Common status meanings:

- Open: active and unaccepted incident needing action.
- Accepted: responsibility has been taken.
- Resolved: incident condition has been resolved.
- Suppressed: incident is held under suppression timing.
- Expired: incident timed out under lifecycle rules.
- Closed: incident is no longer active.

Further matching calls can continue to update an active incident.

Active Incidents timestamp semantics:

- First Matched: earliest matching call in the tracked window that
	contributes to that incident.
- Last Matched: most recent matching call contributing to that incident.
- Created (in Recent Incidents): incident creation time, which can be later
	than First Matched when threshold is reached after earlier matching calls.

## Suppressed Alerts History

Suppressed Incidents shows alerts that were actually prevented by active suppression. It does not list future suppression state.

Use the Clear Suppression action when you want a rule and subject combination to trigger again immediately. Clearing preserves the audit row and marks the suppression as cleared.

Clear Suppression affects current suppression state for that rule/subject.
Prune Suppression History removes old suppression audit rows only and does not
change current suppression state.

## History Pruning

History pruning keeps operator tables manageable while preserving current
runtime state.

Global Settings provides three retention controls:

- Prune Incident History removes old completed incident records.
- Prune Alert History removes old Alert Call and email delivery records.
- Prune Suppression History removes old suppression audit records.

History pruning does not remove rules, global settings, active incidents, or
current monitoring state.

Repeat Caller automatically removes old internal detection records during
pruning to prevent unnecessary database growth.

Retention policies determine how long records are kept. The pruning schedule
determines how often expired records are removed.

Never disables automatic pruning.

Available pruning schedule options are:

- Never
- Hourly
- Daily (default)
- Weekly
- Monthly
- Yearly

Choose retention based on your operational history requirements, reporting
needs, and troubleshooting expectations.

## Accepting an Incident in the GUI

1. Find the incident in Active Incidents.
2. Use the Accept button in the Action column.
3. Confirm it moves to accepted history/state.

Accepting records responsibility. First acceptance wins. Accepted incidents remain visible in history, and later matching calls may continue updating the same incident while its condition remains active.

Acceptance does not start, extend, or reset suppression. Suppression is set
when the incident is created and scoped to the same rule and subject.
While the qualifying condition remains active, matching activity continues to
update the accepted incident and no additional alerts or reminders are sent.
Once the condition clears and the latch re-arms, any new qualifying episode is
a separate event: if suppression has not yet expired it is blocked and recorded
in Suppressed Incidents; if suppression has already expired the new episode
creates a new incident and follows the normal alert process.

Accepted incidents appear in Suppressed Incidents only when a fresh qualifying
attempt is blocked during still-active suppression after the monitor has first
observed a clear in the previous threshold condition.

## Receiving Email Alerts

Email notifications include practical context such as:

- rule name
- subject/caller context
- event type
- current incident mode
- reminder settings context
- matched-call timing information

Customer-facing notice included in alerts:

This incident has not been accepted. You can accept it by phone if Alert Calls are enabled, or through the GUI.

A successful handoff to the PBX mailer does not guarantee external delivery.

If mail is missing, see the troubleshooting section below.

## Receiving Alert Calls

Alert Call flow:

1. Optional System Recording plays first.
2. Generated incident message plays.
3. Press 1 to accept.
4. Press 2 to decline the current Alert Call.
5. Invalid input retries playback/response.
6. No response leaves the incident unaccepted.
7. Up to three playback attempts may occur during one answered Alert Call.

Operational notes:

- Alert Call destinations and Alert Call Caller ID are administrator-controlled settings; only configure trusted values that are appropriate for your PBX.
- Declining does not close or resolve the incident.
- Declining ends the current attempt. It does not accept or close the incident, and later eligibility follows the selected strategy and that destination's Keep Trying setting.
- Only an explicit ACCEPTED response stops Alert Call progression.
- Ordered stages add one new destination at a time. Earlier destinations remain eligible in later stages only when their own Keep Trying option is enabled.
- Each completed Ordered stage pauses for 60 seconds before the next stage, and a multi-destination stage advances only after every attempt in that stage finishes without acceptance.
- A reminder cannot start until the previous cycle is fully complete. Each new reminder begins a fresh Ordered sequence from stage 0, so cycles do not overlap.
- Ring All contacts every enabled destination on every cycle. Keep Trying is not applicable to Ring All and is shown unticked and disabled.
- Ignore Callers affects inbound detection only and does not filter Alert Call destinations.
- Acceptance cancels pending future attempts and late callbacks cannot restart escalation.
- No same-recipient rapid retry loop occurs within one reminder point.

## Snooze Monitoring

Snooze Monitoring is global.

Available Snooze buttons are 5m, 15m, 30m, 1h, 3h, 6h, 12h, and 24h.

While snoozed:

- incident detection continues
- incident counts can continue to update
- GUI history continues
- Alert Call and Email delivery are deferred

Delivery resumes when snooze ends or monitoring is resumed.

Snooze is not suppression.

## Alert History

Alert History helps operators review:

- incident ID
- timestamp and rule
- event
- action
- status
- failure detail
- stage index and success time where relevant

## Clearing Alert History

Use the Clear Alert History button when you want to remove alert-history rows from the operator table.

Clear Alert History is an immediate manual action. Prune Alert History is the
configured automatic retention policy.

Friendly wording is shown for Alert Call outcomes. Raw telephony diagnostics may appear as tooltip detail when available.

To clear Alert History:

1. Select Clear Alert History.
2. Confirm the action.

Only Alert History rows are deleted. Rules, incidents, settings, seen calls,
and incident lifecycle data are not deleted.

## Running a Manual Check

Use Run Now to trigger the normal monitor process immediately.

- Run Now availability on page load reflects the current monitoring state.
- It does not replace the scheduled job.
- Use it after controlled test calls or configuration changes.
- Review Engine Status and Alert History afterwards.

## Practical Examples

1. Repeated sales enquiry
- Repeat mode
- 3 calls within 10 minutes
- GUI enabled, Email optional

2. Priority support caller
- Specific caller list
- 2 calls within 5 minutes
- Alert Call and Email enabled

3. Repeated calls to one department
- Selected inbound route scope
- Same caller tracked separately from other routes when route-aware identity applies

4. Expected-call monitoring
- Invert mode
- Fewer than the expected number of calls during a completed window

## Troubleshooting

### No incident appears

Check:

- module enabled
- rule enabled
- schedule currently active
- caller scope
- DID/inbound-route scope
- threshold and window values
- CDR availability
- background job execution
- whether the calls were already processed
- Run Now outcome in Engine Status and Alert History

### Email does not arrive

Check:

- Email enabled globally
- Email enabled for the rule
- recipients configured
- FreePBX mail settings
- Email From Address
- PBX mail logs
- Alert History status (Sent or Failed)

### Alert Call does not arrive

Check:

- Alert Call enabled for the rule
- destinations saved
- outbound dialling path
- caller ID configuration
- Asterisk Manager availability
- Alert History outcome
- Snooze Monitoring state
- whether recipient declined or became ineligible

### Alert Call connects but has no audio

Check:

- selected System Recording exists
- generated prompt language files exist
- FreePBX/Asterisk language configuration
- outbound channel audio path
- relevant Asterisk logs

### A rule does not save

Check:

- Rule Name present
- valid threshold and window
- caller includes present for Specific callers
- route includes present for Selected DIDs only
- valid schedule rows
- no overnight schedule range

### Reminders are not sent

Check:

- Alert Reminders is not Never
- incident remains active
- monitoring is not snoozed
- Alert Call or Email remains enabled for the rule
- next reminder is due
- recipient has not declined or become ineligible

## Where To Get Technical Information

- [README.md](README.md)
- [TESTING.md](TESTING.md)
- GitHub Issues: https://github.com/kierknoby/repeatcaller/issues

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

The top Engine Status section contains the operational controls: Enable Monitoring, Disable Monitoring, Snooze, Resume, and Run Now.

## First-Time Setup

1. Open Global Settings.
2. Enable Repeat Caller monitoring.
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
- Enabled: Yes
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
- Enabled: turns the rule on or off.
- Mode: Repeat or Invert detection logic. Use Repeat for repeated contact attempts; use Invert when you expect activity and need to detect when it does not occur.
- Threshold: number of matching calls required for rule evaluation.
- Window: observation period in minutes.
- Suppression: controls incident lifecycle hold period before expiry/re-arm logic.
	Leave blank to use the default 24hrs (1440 minutes). Enter 0 to disable
	automatic suppression for that rule.
- Repeat Alerts: reminder timing for active incidents.
- Caller Scope: Any caller, withheld-only, or specific callers.
- Exclude withheld callers: excludes withheld identities from this rule.
- Caller Includes: callers to include when using specific-caller scope.
- Caller Excludes: callers to exclude.
- DID Scope: all inbound routes or selected inbound routes.
- Inbound Routes: route selector for include/exclude lists.
- Included Routes: routes monitored when selected-route scope is used.
- Excluded Routes: routes excluded from this rule.
- Schedules: day/time periods when calls count for this rule.
- GUI: always enabled history action.
- Alert Call: optional phone-call notifications.
- Email: optional email notifications.
- Alert Call Destinations: accepts one or more internal extensions and/or external telephone numbers separated by commas. External numbers should normally be entered in the same national dialling format an administrator would use from a FreePBX extension. The example/placeholder follows the configured Default Country Code.
- Ring All: attempts all currently eligible destinations for that reminder point.
- Ordered: attempts destinations in saved order, moving forward when unaccepted.
- Keep Trying: controls whether unsuccessful destinations remain eligible later.
- System Recording: optional recording played before generated message.
- Alert Call Caller ID: sets the caller ID presented on outbound alert calls. The preferred format is international E.164 with a leading +, for example +447812345678. The example/placeholder follows the configured Default Country Code.

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

- All inbound routes
- Selected inbound routes via Included Routes and Excluded Routes

Includes are applied before exclusions. Route scope follows your FreePBX Inbound Routes configuration.

## Schedules

Schedules control when calls count for a rule.

- Any day, 24 hours covers all times.
- Specific day/time rows limit when matching calls are counted.
- Calls outside schedule do not count for that rule.
- Overnight ranges are not supported in 1.0.0 and should be split or avoided.

## Suppression

Suppression is an incident-lifecycle control.

Repeat Caller uses a default 24hrs (1440 minutes) suppression period when no
rule override is set.

Rule-level Suppression override replaces that default for the rule.

Rule-level Suppression values behave as follows:

- blank: use the default 24hrs (1440 minutes) suppression period
- numeric value: override the default for the rule
- 0: disable automatic suppression for that rule

Suppression controls how long Repeat Caller keeps an incident active before it
may expire or re-arm.

Comparison:

- Suppression: incident lifecycle timing
- Repeat Alerts: reminder timing for active incidents
- Snooze Monitoring: temporary pause for Alert Call and Email delivery

## Repeat Alerts

Repeat Alerts options:

- Never
- Every 5 Minutes
- Hourly
- Daily
- Escalating

These reminders apply to incidents that are already active.

Escalating starts with shorter reminder intervals and gradually increases them up to a daily interval.

Repeat Alerts controls reminder delivery cadence for incidents that are already
active. It does not change incident retention or prune historical rows.

## Understanding Incidents

Common status meanings:

- Open: active and unaccepted incident needing action.
- Accepted: responsibility has been taken.
- Resolved: incident condition has been resolved.
- Suppressed: incident is held under suppression timing.
- Expired: incident timed out under lifecycle rules.
- Closed: incident is no longer active.

Further matching calls can continue to update an active incident.

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

## Receiving Email Alerts

Email notifications include practical context such as:

- rule name
- subject/caller context
- event type
- current incident mode
- reminder settings context
- matched-call timing information

Customer-facing notice included in alerts:

This alert is currently unaccepted. You will receive a notification once it is accepted by phone or through the GUI.

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
- Declining excludes that recipient from further Alert Calls for that incident.
- Keep Trying controls whether unsuccessful destinations remain eligible on later reminders.
- Ordered advances to the next destination when current attempt ends unaccepted.
- Ring All contacts all currently eligible destinations.
- No same-recipient rapid retry loop occurs within one reminder point.

## Snooze Monitoring

Snooze Monitoring is global.

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
- route includes present for Selected inbound routes
- valid schedule rows
- no overnight schedule range

### Reminders are not sent

Check:

- Repeat Alerts is not Never
- incident remains active
- monitoring is not snoozed
- Alert Call or Email remains enabled for the rule
- next reminder is due
- recipient has not declined or become ineligible

## Where To Get Technical Information

- [README.md](README.md)
- [TESTING.md](TESTING.md)
- GitHub Issues: https://github.com/kierknoby/repeatcaller/issues

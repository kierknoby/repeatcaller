# Repeat Caller 1.0.1 for FreePBX 16 and 17

**Release date:** 5 August 2026

## Introduction

Repeat Caller supports two distinct operating modes, and the correct mode
depends on the use case:

- Repeat mode: detects when the same caller reaches the configured threshold
  within the selected time window. Suitable for repeated contact attempts,
  urgent repeat enquiries, or nuisance/repeat calling scenarios.
- Invert mode: detects when fewer than the configured number of matching calls
  occur within the selected time window. Suitable for expected check-ins,
  lone-worker workflows, welfare checks, scheduled contact, or missing
  expected activity.

It turns qualifying inbound call journeys into actionable incidents for GUI
review, optional Alert Call notifications, and optional email notifications on
FreePBX/PBXact 16 and 17.

Rules support repeat and invert detection modes, caller and DID scoping,
schedule windows, repeat-notification modes, and suppression controls. The
admin page presents active and historical lifecycle views so operators can
review incidents, acceptances, alerts, and suppression decisions in one place.

## Compatibility

Use with FreePBX/PBXact 16 or 17.

- FreePBX/PBXact 16 and 17
- PHP 7.4+
- MariaDB 5.5-compatible schema (utf8/InnoDB key-size compatible)

### Development release compatibility

Repeat Caller 1.0.0 was a development release and should not be used as an
upgrade source for later releases. Upgrade compatibility guarantees begin with
the first stable release.

## Requirements

- FreePBX/PBXact 16 or 17
- CDR rows available in asteriskcdrdb.cdr
- Inbound Routes configured for DID/CID route matching
- FreePBX Job runner enabled for scheduled background processing
- FreePBX mail support configured for email notifications
- Email From Address configured in Advanced Settings for email sending
- Asterisk Manager access available for Alert Call originate
- Optional FreePBX System Recordings for introductory Alert Call playback

## Installing

Repeat Caller is a community module and is not currently listed in the
FreePBX online module repository.

Do not use:

```sh
fwconsole ma installlocal repeatcaller
```

Use `fwconsole ma install repeatcaller` with one of the methods below.
For each method, ensure module files are present at
`/var/www/html/admin/modules/repeatcaller/` before running the fwconsole
commands. The fwconsole commands intentionally run from a neutral directory
(`cd ~`).

Option 1: Install from pre-staged module files

Place the module files in `/var/www/html/admin/modules/repeatcaller/`, then:

```sh
cd ~
fwconsole ma install repeatcaller
fwconsole chown
fwconsole reload
```

Option 2: Install from GitHub

Git commands require the modules/repository directory context. After cloning,
switch back to a neutral directory before running fwconsole commands.

FreePBX 16 / PBXact 16 (CentOS 7)

Check whether Git is installed:

```sh
rpm -q git
```

If Git is not installed:

```sh
yum install -y git
```

FreePBX 17 / PBXact 17 (Debian 12)

Check whether Git is installed:

```sh
dpkg -l git
```

If Git is not installed:

```sh
apt update
apt install -y git
```

Then run the following commands as root:

```sh
cd /var/www/html/admin/modules
git clone https://github.com/kierknoby/repeatcaller.git repeatcaller
cd ~
fwconsole ma install repeatcaller
fwconsole chown
fwconsole reload
```

Option 3: Install from a local copy

Copy or symlink a local `repeatcaller` directory into
`/var/www/html/admin/modules/repeatcaller/`, then run the fwconsole commands
from a neutral directory:

```sh
cd ~
fwconsole ma install repeatcaller
fwconsole chown
fwconsole reload
```

The module appears under Reports > Repeat Caller.

## Updating Repeat Caller

Do not uninstall during an update. Uninstall removes Repeat Caller tables,
rules, settings, incidents, alert state, alert history, suppression history,
the managed alert dialplan fragment/include, and the deployed AGI script.

Check version before and after updating:

```sh
fwconsole ma list | grep -i repeatcaller
grep "<version>" /var/www/html/admin/modules/repeatcaller/module.xml
```

Option 1: Update from pre-staged module files

Replace the module files in `/var/www/html/admin/modules/repeatcaller/`, then
run the fwconsole commands from a neutral directory:

```sh
cd ~
fwconsole ma install repeatcaller
fwconsole chown
fwconsole reload
```

Option 2: Update from GitHub

Git commands require the module repository directory context. After fetching
and resetting, switch back to a neutral directory before running fwconsole
commands.

```sh
cd /var/www/html/admin/modules/repeatcaller
git fetch origin main
git reset --hard FETCH_HEAD
cd ~
fwconsole ma install repeatcaller
fwconsole chown
fwconsole reload
```

Option 3: Update from a local copy

Re-copy or re-link your local `repeatcaller` directory into
`/var/www/html/admin/modules/repeatcaller/`, then run the fwconsole commands
from a neutral directory:

```sh
cd ~
fwconsole ma install repeatcaller
fwconsole chown
fwconsole reload
```

After updating, open Reports > Repeat Caller and confirm rules, settings,
active/recent incidents, alert history, and suppression history are still
present.

## Background Processing

Repeat Caller registers a FreePBX job class and task:

```text
repeatcaller :: monitor
```

Useful checks:

```sh
fwconsole job --list | grep -i repeatcaller
fwconsole job --run=<job_id> --force
```

The job runs on a one-minute schedule and executes the monitor pipeline that
scans CDR journeys, evaluates rules, updates incidents, and processes alerts.

Repeat Caller does not install a daemon, systemd service, custom AMI listener,
webhook sender, or SMS sender.

## Data Model

Canonical Repeat Caller tables:

- `repeatcaller_settings`: module settings, engine status timestamps, global
  repeat/suppression/pruning controls, snooze state, and recipients.
- `repeatcaller_rules`: rule definitions, detection mode, thresholds/windows,
  caller/DID scope, repeat override, suppression override, and alert action
  toggles.
- `repeatcaller_rule_schedules`: per-rule day/time windows.
- `repeatcaller_rule_callers`: per-rule caller include/exclude lists.
- `repeatcaller_rule_dids`: per-rule inbound route scope rows stored by mode
  (All DIDs uses exclusions, Selected DIDs only uses inclusions).
- `repeatcaller_seen_calls`: deduplicated inbound call journeys already
  processed.
- `repeatcaller_rule_subject_state`: per-rule/subject evaluation state,
  threshold state, suppression expiry, and active incident linkage.
- `repeatcaller_incidents`: incident lifecycle rows (active, accepted,
  suppressed, expired, closed) with timestamps and acceptance metadata.
- `repeatcaller_incident_alert_state`: per-incident reminder scheduling state.
- `repeatcaller_incident_alert_history`: per-action alert attempts and outcomes
  (GUI/Alert Call/email) with dedupe keys and delivery status.
- `repeatcaller_incident_suppression_history`: suppression audit rows recording
  prevented qualifying incident attempts, timestamps, and clear state.

## Detection and Incident Behaviour

Repeat Caller evaluates inbound journeys collapsed from CDR rows and matches
them against enabled rules.

Fresh installation boundary: a new Repeat Caller installation starts
processing calls from the time it is installed. Calls already present in CDR
data before that time do not retrospectively create incidents, alerts, or
suppression history. Existing installations and normal upgrades retain their
current processing continuity.

- Repeat mode: creates an incident when matching call count reaches threshold
  within the configured window.
- Invert mode: creates an incident when a full configured window completes
  without reaching threshold.

Invert activation timing:

- A newly created enabled Invert rule starts its first observation window from
  when it becomes active, subject to its configured schedule.
- It does not retrospectively evaluate completed windows from before creation
  or activation.
- Re-enabling an Invert rule establishes a new activation boundary.
- Changing an enabled rule from Repeat to Invert, or changing an enabled
  Invert rule's observation window or schedule, re-anchors the Invert
  observation window from the change time.
- Existing persisted Invert window state is preserved across ordinary monitor
  runs and upgrades.

Matching can include:

- caller scope (any, withheld-only, specific caller lists). Caller lists accept spaces, commas or new lines as separators and save back as comma-separated values.
- inbound route scope (all routes with optional exclusions, or selected-route
  inclusions only)
- schedule windows (day/time segments)

Subject identity is tracked per rule and matched caller/route context so the
same rule can independently track separate journeys.

Incident lifecycle behavior includes:

- creating incidents only when rule conditions are met
- updating matched call counts/timestamps on existing tracked incidents
- accepting incidents by GUI or Alert Call action
- closing incidents when condition-clear logic is observed
- expiring eligible incidents after suppression expiry handling

For repeat incidents, timestamp semantics are:

- First Matched: earliest matching call in the tracked window that
  contributes to that incident.
- Last Matched: most recent matching call contributing to that incident.

Processed call journeys are recorded in `repeatcaller_seen_calls` to prevent
duplicate incident creation from the same journey.

## Alerting

Repeat Caller provides incident visibility and two optional notification
methods:

- GUI incidents, which are always recorded
- Alert Call, which is optional per rule and can be answered from the phone
- Email notifications, which are optional per rule

Alert attempts are reserved in alert history with dedupe keys so the same
incident stage/action/recipient combination is not repeatedly scheduled.

Email recipients are configured per rule, and email delivery is enabled or
disabled per rule.

Alert Call Destinations accepts one or more internal extensions and/or
external telephone numbers separated by commas. External numbers should
normally be entered in the same national dialling format an administrator
would use from a FreePBX extension. The example/placeholder follows the
configured Default Country Code.

When an Alert Call destination is added in the rule editor, Repeat Caller
automatically adds the same value to Ignore these callers and shows a one-time
warning. Alert Call destinations are automatically added to Ignore these
callers to reduce the risk of self-triggering if an alert call routes back
through a monitored DID.

When Alert Call is enabled and Caller ID managed elsewhere is disabled, Repeat
Caller may also add the configured Alert Call Caller ID to Ignore these
callers as an additional self-trigger safeguard for external return paths.
Administrators can remove the Ignore entry if it is not appropriate.

Repeat alert emails now show one reminder line:

- Alert Reminder: displays the repeat cadence actually used for that alert, such as Never, Hourly, Daily, or Escalating.

Repeat alert emails also start with the FreePBX System Identifier when it is available, for example: Repeat Caller incident alert from MY-PBX-NAME. If the identifier is unavailable, the email uses a sensible fallback.

Alert Call Caller ID sets the caller ID presented on outbound alert calls. The
preferred format is international E.164 with a leading +, for example
+447812345678. The example/placeholder follows the configured Default Country
Code.

Alert Call destinations and Alert Call caller ID values are administrator-
controlled PBX configuration. Only use trusted values that are appropriate for
your dialplan, routing, and outbound calling policy.

Alert Call progression follows the selected strategy. Ordered stages add one
new destination at a time. Earlier destinations remain eligible in later stages
only when their own Keep Trying option is enabled; for example, destinations
1/2/3 with settings enabled/disabled/enabled produce 1, then 1+2, then 1+3,
then 1+3 repeatedly. Each completed stage pauses for 60 seconds before the
next one. A multi-destination stage advances only after every attempt in that
stage finishes without acceptance. Only an explicit ACCEPTED response stops
progression; NOANSWER, BUSY, DECLINED, answered-no-response, unavailable,
congestion, and failed attempts do not stop progression. Ring All includes
every enabled destination on every cycle. Keep Trying is not applicable to
Ring All and is shown unticked and disabled. Ignore Callers applies only to
inbound detection and never filters Alert Call destinations. Acceptance
cancels pending future attempts and late callbacks cannot restart escalation.

Repeat Caller also marks internally originated Alert Call legs and excludes
those marked internal legs from detection. This internal marker is useful for
on-box call legs only and does not survive a call that leaves through a
carrier and re-enters as a new inbound journey. Carrier rewriting and caller
presentation differences may still require administrator judgement.

Alert Call supports optional introductory System Recording playback followed by
a spoken summary of incident details such as caller and DID where available.

Caller ID managed elsewhere is enabled by default for new rules. While it is
enabled, Repeat Caller does not set Alert Call Caller ID, leaves the field
blank and disabled, hides the example placeholder, and keeps any typed value
only for the current editor session. Unticking it restores the previous
unsaved value and makes Alert Call Caller ID mandatory when Alert Call is
enabled. Saving with it checked persists a blank Caller ID and forgets the
prior value. Caller presentation may still be managed elsewhere by routing,
trunks, or providers.

DTMF behavior during Alert Call:

- Press 1: accepts the incident (Alert Call acceptance source)
- Press 2: declines that Alert Call attempt
- Invalid digit: plays retry prompt and retries while attempts remain
- No valid response: records an answered-no-response outcome and leaves the
  incident unaccepted

Declining affects that call attempt path and does not accept or close the
incident. Repeat notifications can continue while the incident remains active
according to repeat mode and eligibility.

## Repeat Alert Modes

Repeat Caller lets each rule repeat its alerts using one of these modes:

- Never
  - Initial alert only.
- Every 5 minutes
  - Repeats every 5 minutes while the incident remains active.
- Hourly
  - Repeats every hour while the incident remains active.
- Daily
  - Repeats every 24 hours while the incident remains active.
- Escalating
  - Uses a Fibonacci-style escalating backoff schedule, starting with shorter
    reminders and gradually increasing the interval up to daily. The wait between
    reminders follows the escalating sequence on a 5-minute base:

    5 min, 5 min, 10 min, 15 min, 25 min, 40 min, 65 min, 105 min, …

    Capped at 24 hours once the interval reaches the daily ceiling.

Stored legacy repeat mode values from earlier builds are treated as Escalating.

## Suppression

Suppression is tracked per rule and subject using the default 1440 minute
(24 hour) suppression period or an optional per-rule suppression override.

Rule suppression values behave as follows:

- blank: use the default 1440 minute (24 hour) suppression period
- numeric value: override the default for that rule
- 0: disable automatic suppression for that rule

When a qualifying new incident attempt occurs while suppression is active,
Repeat Caller blocks that incident creation and writes a suppression audit row.
Suppression rows are not future placeholders; they represent prevented,
qualifying attempts.

Suppression lifecycle timing:

- Accepting an incident starts or maintains suppression for that rule and
  subject, but acceptance itself does not create a Suppressed Incidents row.
- While the original threshold condition remains continuously true, further
  matching calls update the accepted incident and do not create
  suppression-history rows.
- The rolling threshold condition must first clear (drop below threshold in
  the configured window).
- The monitor must observe that clear state and re-arm the threshold latch.
- If the same caller reaches threshold again before suppression expires,
  Repeat Caller blocks that fresh incident attempt and records it immediately
  in Suppressed Incidents.
- The threshold latch is intentional and prevents duplicate suppression rows
  while one unbroken qualifying condition is still in progress.

Suppressed Incidents view shows audit rows including matching count, threshold
window context, suppression expiry, and related incident.

The Suppressed Incidents view shows prevented qualifying incident attempts, not
every subject that is currently under suppression.

Clear Suppression is available per suppression row. Clearing suppression allows
immediate retrigger on the next qualifying condition and preserves the audit
row by setting its cleared timestamp.

Suppression history has its own prune policy and can be pruned independently
from incident and alert history.

## Data Retention

Global Settings includes three pruning controls:

- Prune Incident History removes old completed incident records.
- Prune Alert History removes old notification and Alert Call delivery records.
- Prune Suppression History removes old suppression audit records.

Pruning affects historical records only. It does not remove rules, settings,
active incidents, or current monitoring state.

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

## Snooze Monitoring

Snooze Monitoring is a global control in Engine Status.

Available durations are 5 minutes, 15 minutes, 30 minutes, 1 hour, 3 hours, 6 hours,
12 hours, and 24 hours.

- While snoozed, detection and incident lifecycle processing continue.
- Alert Call and Email deliveries are deferred while snooze is active.
- Resume All Rules can be triggered manually before snooze expiry.
- There is no per-rule or per-incident snooze.

## User Interface

Reports > Repeat Caller includes these main sections:

- Engine Status: enabled rules, active incidents, last run, run state, PBX
  time, Enable All Rules/Disable All Rules, Snooze, Resume All Rules, Run Now.
- Global Settings: country code, lookback, global suppression, global repeat,
  pruning policies, and maintenance actions.
- Rules: summary table plus Add Rule editor for mode/threshold/window, caller
  and route scope, schedules, repeat mode, suppression setting, rule-level
  email recipients, and alert actions. Blank suppression uses the default
  24hrs (1440 minutes) period; 0 disables automatic suppression for that rule.
  The Start as control is used only when creating a new rule to choose whether
  it starts enabled or disabled.
  The actions checklist order is GUI, Alert Call, then Email, and the email
  recipient field appears directly above Save Rule. The editor title switches
  to Editing Rule when modifying an existing rule. DID scope uses a simple
  model: All DIDs supports optional exclusions, and Selected DIDs only uses
  explicit inclusions.
- Rule controls: each rule row includes Status, Edit, and X (delete). While an
  existing rule is being edited, those row actions are greyed out and cannot be
  used until editing is cancelled or saved.
- Rule explanation rows: plain-language explanation beneath each rule row, with
  disabled and edit highlighting. Selecting Status temporarily replaces the
  explainer sentence with a plain-English status summary for that rule. Status
  wording is split into progress within the configured alert window, incident
  lifecycle state, and monitor freshness, then restores the default explanation
  after 15 seconds.
- Active Incidents: live incident table with an Action column for acceptance.
- Recent Incidents: historical/accepted lifecycle view.
- Alert History: per-event action history and delivery outcomes, with incident
  ID shown first.
- Suppressed Incidents: suppression audit rows with clear-suppression action.
- Table presentation: each admin table initially shows 15 rows, with Show more
  and Show less controls that expand/collapse additional rows in batches of 15.

UI refresh behavior uses AJAX command allowlisting and section change tokens so
only changed sections refresh. Tables remain in local responsive wrappers with
horizontal scroll where needed.

## Design Notes and Development Considerations

### FreePBX Routing and Dialling

Repeat Caller is an administrator-configured FreePBX/PBXact module. Alert Call
destinations, caller ID, recordings, and outbound routing remain governed by
the PBX configuration. Repeat Caller does not replace FreePBX outbound routes
or impose an alternative dialling policy. The PBX administrator remains
responsible for ensuring configured destinations, caller presentation, and
routing behavior are appropriate for the deployment.

### Domain-focused Components

Repeat Caller keeps core incident, alerting, and persistence logic close to
the operational workflows they support. Some components intentionally contain
multiple related operations because they represent a single domain area,
rather than splitting closely related behaviour across many small classes with
limited value.

### CDR-based Detection

Repeat Caller uses CDR-based analysis rather than attempting to replace the
live call-processing path. This provides reliable historical context for
repeat activity windows while remaining compatible with the wide variety of
FreePBX routing configurations.

Repeat Caller detects caller activity from PBX CDR data. Highly customised
dialplans, unusual call flows, incomplete CDR records, or carrier-specific
number presentation can affect how calls are observed and matched. Production
rules should be tested with representative calls before they are relied on for
operational handling.

### Administrator Trust Boundary

As with FreePBX itself, Repeat Caller assumes configuration is performed by a
trusted administrator. It does not attempt to duplicate outbound route
controls, dial plan policy, or PBX security decisions.

### Administrative Configuration

The module is designed for trusted PBX administrators. It provides sensible
handling for common inputs, but it does not attempt to prevent every possible
configuration choice that a PBX administrator may intentionally apply.

### Incident Acceptance Behaviour

Accepting an incident stops ordinary future reminder stages for the current
incident state while rule-and-subject suppression remains active. Later
matching calls can still update the accepted incident internally during that
suppression window, but no reminder stages or notifications are reserved or
sent until suppression expires. After expiry, genuinely new qualifying
activity can make the accepted incident alert-eligible again.

### Compatibility and Development Notes

Legacy compatibility handling remains where required for upgrades and existing
stored data. Internal compatibility identifiers may remain in code or stored
values where needed, while operator-facing text uses current product language.
This is intentional so upgrades can preserve data integrity without carrying
legacy terminology into active administration workflows.

### FreePBX-Native Design

Repeat Caller is built around existing FreePBX concepts rather than
attempting to create a separate telephony layer. It works with established
FreePBX components including inbound routes, scheduled jobs, CDR data, System
Recordings, and administrator-controlled routing.

## Security Model

- Fixed AJAX command allowlist in controller dispatch.
- Module-owned session CSRF token required for AJAX handlers.
- Input normalization and bounded numeric validation for settings/rule fields.
- Settings writes are key-allowlisted.
- SQL operations use prepared statements in repository/controller paths.
- Reconcile lock uses database named locks to prevent concurrent monitor runs.
- Alert and suppression records use dedupe/uniqueness constraints to reduce
  duplicate processing.
- Alert Call originate uses Asterisk Manager access and does not spawn shell
  commands.
- Passive page load does not force monitor execution; monitor is job-driven or
  manually triggered.

## Current Limitations

- Detection is periodic (job cadence), not event-driven.
- Detection depends on CDR visibility/completion timing.
- Very short call flaps between job runs may be missed.
- Caller and DID fidelity depends on CDR/source data quality.
- Withheld or malformed caller identifiers can reduce matching precision.
- Alert Call delivery depends on AMI availability, dialplan deployment,
  playback assets, and destination reachability.
- Email delivery depends on FreePBX mail configuration and downstream relays.
- Snooze is global rather than per rule/incident.
- No webhook or SMS delivery channel is implemented.

## Release History

### 1.0.1, patch release, 5 August 2026

- Rule explanation-row styling is now consistent across enabled, disabled,
  temporary Status, and editing states.

#### Rule editor reliability

- Caller include/exclude lists now save and reload correctly from the rule
  editor while preserving list semantics.
- Legacy plain-text caller list entry remains supported for compatibility.
- Alert Call destination Add now behaves the same whether triggered by Add or
  Enter.
- Alert Call destination changes now keep the associated Ignore these callers
  entry consistent without creating duplicate destinations.
- Alert Call self-trigger warnings now remain visible for approximately
  6 seconds without changing global FreePBX toast behavior.
- Run Now now initializes from the current bootstrap state so initial
  availability is shown correctly on load.

#### DID scope controls

- Allows individual inbound routes to be excluded when All DIDs is selected.
- Keeps Selected DIDs only mode limited to explicit route inclusions.
- Clears stale opposite-mode route selections when the DID scope changes.

#### Accepted incident suppression

- Prevents accepted incidents from reserving or sending further alerts while
  rule-and-subject suppression remains active.
- Preserves normal Repeat Alerts for active unaccepted incidents.
- Allows genuinely new qualifying activity after suppression expiry to trigger
  a fresh alert stage.

#### Snooze controls

- Adds 30-minute, 3-hour, 6-hour, 12-hour, and 24-hour global Snooze Monitoring options.

#### Global controls

- Renames the global Enable Monitoring and Disable Monitoring buttons to
  Enable All Rules and Disable All Rules for clearer rule-processing terminology.

#### Alert Call self-trigger safeguard

- Adding an Alert Call destination now auto-adds the same value to Ignore
  these callers as a safe default, with a one-time warning in the editor.
- Administrators can remove the Ignore entry if needed; it is not silently
  re-added during save, reload, or normal rendering.
- When Repeat Caller sets Alert Call Caller ID, that Caller ID may also be
  added to Ignore these callers as an additional external return-path
  safeguard.
- Internal Repeat Caller originated Alert Call legs are marked and excluded
  from detection; this marker does not survive external PSTN hairpin
  leave-and-return paths.

#### Alert Call Caller ID handling

- Caller ID managed elsewhere is enabled by default for new rules and appears
  directly above Alert Call Caller ID in the rule editor.
- While enabled, Repeat Caller does not set Alert Call Caller ID, leaves the
  field blank and disabled, and hides the example placeholder.
- Unticking restores the previous unsaved editor value and makes Alert Call
  Caller ID mandatory when Alert Call is enabled.
- Saving while enabled persists a blank Caller ID and forgets the previous
  value.
- Caller presentation remains the responsibility of PBX routing, trunks, or
  providers.

#### Ordered Alert Call progression

- Ordered stages add one new destination at a time.
- Earlier destinations remain eligible in later stages only when their own
  Keep Trying option is enabled.
- Each completed stage pauses for 60 seconds before the next stage.
- A multi-destination stage advances only after every attempt in that stage
  finishes without acceptance.
- Only explicit ACCEPTED responses stop progression; other outcomes do not.
- Ring All includes every enabled destination on every cycle, while Keep
  Trying is not applicable and remains unticked and disabled.
- Ignore Callers applies only to inbound detection and never filters Alert
  Call destinations.
- Acceptance cancels pending future attempts and late callbacks cannot restart
  escalation.

#### Invert activation timing

- Newly created enabled Invert rules begin their first observation window from
  activation time, subject to schedule.
- Completed windows from before creation or activation are not retrospectively
  evaluated.
- Re-enabling an Invert rule creates a new activation boundary.
- Changing an enabled rule from Repeat to Invert, or changing an enabled
  Invert rule's schedule or observation window, re-anchors the Invert
  observation window from the time of change.
- Persisted Invert observation state is retained across normal monitor runs
  and upgrades.

## Validation

For operator workflows, see [USER_GUIDE.md](USER_GUIDE.md). For test scope and
execution notes, see [TESTING.md](TESTING.md).

Useful local checks:

```sh
php -l Repeatcaller.class.php
php -l Job.php
php -l page.repeatcaller.php
php -l install.php
php -l uninstall.php
php -l views/main.php
php -l src/Schema.php
php -l src/RepeatCallerRepository.php
php -l src/BackgroundProcessor.php
php -l src/CdrScanner.php
php -l src/IncidentAlertProcessor.php
php -r '$xml = simplexml_load_file("module.xml"); echo $xml ? "module.xml parsed\n" : "module.xml failed\n";'
php tests/repeat_install_contract.php
php tests/repeat_repository_contract.php
php tests/repeat_runtime_contract.php
php tests/repeat_admin_contract.php
php tests/repeat_release_contract.php
```

On a real FreePBX/PBXact system:

```sh
fwconsole ma list | grep -i repeatcaller
fwconsole reload
fwconsole job --list | grep -i repeatcaller
fwconsole job --run=<job_id> --force
tail -f /var/log/asterisk/full | grep -i repeatcaller
```

## Uninstalling

Uninstall removes Repeat Caller job registration, Repeat Caller tables, managed
dialplan include/fragment, and the deployed AGI callback script. Back up first
if you need existing rules/history. These commands run from a neutral
directory, and the module path removal uses an absolute path.

```sh
cd ~
fwconsole ma uninstall repeatcaller --force
rm -rf /var/www/html/admin/modules/repeatcaller
fwconsole chown
fwconsole reload
```

## Licence

GPLv3+. See LICENSE.

## AI Disclosure

This module has been developed with AI assistance for code generation, review,
testing, and documentation. Changes should still be reviewed, tested, and
accepted by a human maintainer before deployment.

## Author

@kierknoby, Kieran Knowles-Byrne // FreePBX UK

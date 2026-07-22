# Repeat Caller 1.0.0 for FreePBX 16 and 17

**Release date:** 22 July 2026

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

Option 1: Install from an unpacked module directory

```sh
cd /var/www/html/admin/modules/repeatcaller
fwconsole ma install repeatcaller
fwconsole chown
fwconsole reload
```

Option 2: Install from GitHub

```sh
cd /var/www/html/admin/modules
git clone https://github.com/kierknoby/repeatcaller.git repeatcaller
cd repeatcaller
fwconsole ma install repeatcaller
fwconsole chown
fwconsole reload
```

Option 3: Install from a local copy

Copy or symlink a local `repeatcaller` directory into
`/var/www/html/admin/modules/`, then:

```sh
cd /var/www/html/admin/modules/repeatcaller
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

Option 1: Update from an unpacked module directory

Replace the module files in `/var/www/html/admin/modules/repeatcaller/`, then:

```sh
cd /var/www/html/admin/modules/repeatcaller
fwconsole ma install repeatcaller
fwconsole chown
fwconsole reload
```

Option 2: Update from GitHub

```sh
cd /var/www/html/admin/modules/repeatcaller
git fetch origin main
git reset --hard FETCH_HEAD
fwconsole ma install repeatcaller
fwconsole chown
fwconsole reload
```

Option 3: Update from a local copy

Re-copy or re-link your local `repeatcaller` directory, then:

```sh
cd /var/www/html/admin/modules/repeatcaller
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
- `repeatcaller_rule_dids`: per-rule inbound route include/exclude lists.
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

- Repeat mode: creates an incident when matching call count reaches threshold
  within the configured window.
- Invert mode: creates an incident when a full configured window completes
  without reaching threshold.

Matching can include:

- caller scope (any, withheld-only, specific caller lists)
- inbound route scope (all routes or selected include/exclude route lists)
- schedule windows (day/time segments)

Subject identity is tracked per rule and matched caller/route context so the
same rule can independently track separate journeys.

Incident lifecycle behavior includes:

- creating incidents only when rule conditions are met
- updating matched call counts/timestamps on existing tracked incidents
- accepting incidents by GUI or Alert Call action
- closing incidents when condition-clear logic is observed
- expiring eligible incidents after suppression expiry handling

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

Alert Call Caller ID sets the caller ID presented on outbound alert calls. The
preferred format is international E.164 with a leading +, for example
+447812345678. The example/placeholder follows the configured Default Country
Code.

Alert Call destinations and Alert Call caller ID values are administrator-
controlled PBX configuration. Only use trusted values that are appropriate for
your dialplan, routing, and outbound calling policy.

Alert Call supports optional introductory System Recording playback followed by
a spoken summary of incident details such as caller and DID where available.

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

Suppressed Incidents view shows audit rows including matching count, threshold
window context, suppression expiry, and related incident.

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

- While snoozed, detection and incident lifecycle processing continue.
- Alert Call and Email deliveries are deferred while snooze is active.
- Resume can be triggered manually before snooze expiry.
- There is no per-rule or per-incident snooze.

## User Interface

Reports > Repeat Caller includes these main sections:

- Engine Status: enabled rules, active incidents, last run, run state, PBX
  time, Enable/Disable Monitoring, Snooze, Resume, Run Now.
- Global Settings: country code, lookback, global suppression, global repeat,
  pruning policies, and maintenance actions.
- Rules: summary table plus Add Rule editor for mode/threshold/window, caller
  and route scope, schedules, repeat mode, suppression setting, rule-level
  email recipients, and alert actions. Blank suppression uses the default
  24hrs (1440 minutes) period; 0 disables automatic suppression for that rule.
  The actions checklist order is GUI, Alert Call, then Email, and the email
  recipient field appears directly above Save Rule. The editor title switches
  to Editing Rule when modifying an existing rule.
- Rule controls: each rule row includes Status, Edit, and X (delete).
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
incident state. If a new qualifying call occurs after acceptance, the incident
can enter the existing re-alert path where that behavior is configured. That is
intentional: a new qualifying caller event represents new activity, not a
continuation of the already-accepted alert stage.

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
if you need existing rules/history.

```sh
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

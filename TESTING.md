# Repeat Caller Installation Testing Checklist

Use this checklist for real FreePBX installation testing on FreePBX 16 and 17
with MySQL/MariaDB.

## Fresh Install

1. Install the module with:

```bash
cd /var/www/html/admin/modules/repeatcaller
fwconsole ma install repeatcaller
fwconsole chown
fwconsole reload
```

2. Confirm the page loads under Reports > Repeat Caller.
3. Confirm the FreePBX job is registered:

```bash
fwconsole job --list | grep -i repeatcaller
```

4. Confirm current tables exist:

```sql
SHOW TABLES LIKE 'repeatcaller%';
```

Expected v1 fresh-install tables include:

- `repeatcaller_settings`
- `repeatcaller_rules`
- `repeatcaller_rule_schedules`
- `repeatcaller_rule_callers`
- `repeatcaller_rule_dids`
- `repeatcaller_seen_calls`
- `repeatcaller_rule_subject_state`
- `repeatcaller_incidents`
- `repeatcaller_incident_alert_state`
- `repeatcaller_incident_alert_history`
- `repeatcaller_incident_suppression_history`

## Settings and Rules

5. Save Global Settings and confirm reload persists values.
6. Create a rule and reload it through the editor.
7. Confirm Inbound Route DID choices populate from FreePBX inbound routes.
8. Confirm rule-level mode, alert actions, schedules, and recipients persist after save/reload.
9. In the Rules table, confirm each row shows Edit, Status, and X controls on one line. Press Status and confirm the explainer bar turns light grey, shows rule status text for 15 seconds, then reverts.

## Runtime

10. Trigger matching inbound calls and confirm incident creation.
11. Trigger further matching calls and confirm same-incident updates.
12. Confirm initial incident and alert behaviour when configured:
	- GUI incident creation
	- Alert Call when enabled and configured
	- Email when enabled and recipients are valid
13. Confirm reminder timing for the selected Alert Reminders mode and configured alert actions. In alert reminder emails, confirm the reminder line is shown as `Alert Reminder: <mode>`, the older reminder-mode lines are no longer present, and the opening line includes the FreePBX System Identifier when available.
14. Ensure an active incident is available for acceptance testing.
15. Accept an active incident using the GUI and confirm it moves to accepted history/state.
16. If Alert Call is enabled, answer the Alert Call, press 1, and confirm the incident is accepted.
17. Trigger a post-accept matching call and confirm the same accepted incident updates without new reminders.
18. Snooze globally, confirm deferred Alert Call and Email delivery, then select Resume All Rules and confirm deferred delivery.
19. Delete a rule with an active incident and confirm the rule disappears while incident history remains visible.
20. Confirm the available pruning schedule options:
	- Never
	- Hourly
	- Daily (default)
	- Weekly
	- Monthly
	- Yearly
21. Run manual pruning and review returned delete counts.

## Alert Call contract coverage

22. Run the focused documentation and behavior contracts:

```bash
cd /workspaces/repeatcaller
php tests/repeat_admin_contract.php
php tests/repeat_alerting_contract.php
php tests/repeat_release_contract.php
```

The alerting contract covers first-destination NOANSWER progression, cumulative Ordered stages, mixed Keep Trying values, waiting for all sibling attempts, repeated stages after the final destination, acceptance and late-callback protection, Ring All editor and destination behaviour, and Ignore Callers separation. The admin and release contracts cover the Ring All/Ordered editor round-trip and documentation/help-text parity.

## Additional real FreePBX Alert Call checks

These are additional manual installation checks beyond the contract suites:

23. Confirm that no Alert Reminder launches while an Ordered cycle is active, pending, or still in its 60-second pause.
24. Confirm that the reminder interval begins only after the final Ordered stage completes without acceptance.
25. Confirm that a reminder starts a fresh Ordered sequence at stage 0.
26. Confirm that a reminder cycle cannot overlap another cycle.
27. Confirm that acceptance during any stage prevents later stages and future reminders.
28. Confirm that Ring All schedules its reminder only after its single-stage cycle completes.
29. Configure an Ordered rule with destination 1 enabled, destination 2 disabled, and destination 3 enabled. Confirm that the first stage calls destination 1, the next stage calls destination 1 again when Keep Trying is enabled for destination 1 and introduces destination 2, and the following stage calls destination 1 and destination 3 while destination 2 remains absent.
30. Configure an Ordered rule where the first destination has Keep Trying disabled. Confirm that after its introduction stage, that destination does not return in later stages while the next destination is introduced.
31. Configure a multi-destination Ordered stage and confirm the stage does not advance until every call in that stage has finished without acceptance.
32. Confirm that the next Ordered stage waits approximately 60 seconds after the previous stage completes.
33. Confirm that decline, NOANSWER, BUSY, and answered-without-acceptance outcomes continue progression rather than stop it.
34. Confirm that an explicit ACCEPTED response stops progression and prevents pending later calls from being sent.
35. Confirm that Ring All calls every enabled destination on every cycle.
36. Confirm that Ring All shows Keep Trying as visible, unticked, and disabled.
37. Confirm that Ignore Callers does not prevent an Alert Call destination from being called.

## Upgrade and Preservation

38. Run the supported update sequence:

```bash
cd /var/www/html/admin/modules/repeatcaller
git fetch origin main
git reset --hard FETCH_HEAD
fwconsole ma install repeatcaller
fwconsole chown
fwconsole reload
```
39. Confirm rules, incidents, and history remain intact.
40. Review uninstall warning and cleanup expectations before removal.

## Caller ID Managed Elsewhere Check

41. Create or edit a rule with Alert Call enabled.
42. Enter an Alert Call Caller ID.
43. Tick `Caller ID managed elsewhere`.
44. Confirm the Caller ID field becomes blank and disabled and the placeholder disappears.
45. Untick it and confirm the previous value returns.
46. Tick it again and save.
47. Reopen the rule and confirm the field is blank and the previous value has been forgotten.

## Final 1.0.1 Regression Checks

48. In the rule editor, add an Alert Call destination using Add, then repeat using Enter; confirm both methods behave the same.
49. Confirm Alert Call destination changes keep the associated Ignore these callers entry consistent without creating duplicate destinations.
50. In Caller Includes and Caller Excludes, enter values using a mix of spaces, commas, and new lines; save and reload; confirm include/exclude meaning is preserved.
51. Reload the Repeat Caller page and confirm Run Now shows the correct initial availability state.
52. Add an Alert Call destination and confirm the self-trigger warning remains visible for approximately six seconds before disappearing.
53. With Alert Call enabled and Caller ID managed elsewhere disabled, set or change an Alert Call Caller ID and save; confirm the Caller ID is added to Ignore these callers as an external return-path safeguard when the safeguard is triggered, without creating duplicate entries.
54. Remove a Caller ID-generated Ignore these callers entry, change an unrelated rule setting, save, and confirm the Ignore entry is not silently recreated.

## Useful Checks

Run the job manually:

```bash
fwconsole job --list | grep -i repeatcaller
fwconsole job --run=<job_id> --force
```

Inspect current incidents:

```sql
SELECT id, rule_id, subject_key, state, first_matched_at, last_matched_at, matched_call_count
FROM repeatcaller_incidents
ORDER BY updated_at DESC;
```

Inspect alert history:

```sql
SELECT incident_id, rule_id, action_type, event_type, stage_n, delivery_status, created_at
FROM repeatcaller_incident_alert_history
ORDER BY created_at DESC;
```

## Uninstall Reminder

Uninstall drops Repeat Caller-owned tables, including incidents and alert history.
Back up data first if you need to preserve installation-test results.

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
13. Confirm reminder timing for the selected Repeat Alerts mode and configured alert actions.
14. Ensure an active incident is available for acceptance testing.
15. Accept an active incident using the GUI and confirm it moves to accepted history/state.
16. If Alert Call is enabled, answer the Alert Call, press 1, and confirm the incident is accepted.
17. Trigger a post-accept matching call and confirm the same accepted incident updates without new reminders.
18. Snooze globally, confirm deferred Alert Call and Email delivery, then resume and confirm deferred delivery.
19. Delete a rule with an active incident and confirm the rule disappears while incident history remains visible.
20. Confirm the available pruning schedule options:
	- Never
	- Hourly
	- Daily (default)
	- Weekly
	- Monthly
	- Yearly
21. Run manual pruning and review returned delete counts.

## Upgrade and Preservation

22. Run the supported update sequence:

```bash
cd /var/www/html/admin/modules/repeatcaller
git fetch origin main
git reset --hard FETCH_HEAD
fwconsole ma install repeatcaller
fwconsole chown
fwconsole reload
```
23. Confirm rules, incidents, and history remain intact.
24. Review uninstall warning and cleanup expectations before removal.

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

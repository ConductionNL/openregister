# Design: automation-run-records

No screen in OpenRegister; buildiq's runs dialog is the screen. OpenRegister is not one of the canvas apps.

## D-1: one table for both kinds

`openregister_automation_records`: `id`, `kind` (`notification`, `lifecycle-action`), `key`, `name` (notification slug or action name), `transition` (lifecycle only), `schema_id`, `register_id`, `object_uuid`, `event_id`, `outcome`, `reason`, `channels` (json counts per channel, notification only), `message`, `recorded_at`. Indexed on (`key`, `recorded_at`) and (`object_uuid`). One table keeps the read route one query and gives buildiq one shape to map.

The dedup log stays as it is: its job is idempotency over a short window, and stretching it into a history would make its prune a data loss.

## D-2: what counts as one notification dispatch

One evaluation of a declared notification for one object event. Its per-recipient deliveries are in `NotificationHistory`; the record aggregates them by `eventId` and rule into counts per channel and an outcome: `sent` (all delivered), `partial`, `failed`, `skipped` with the gate that stopped it.

## D-3: lifecycle action outcomes

The executor writes a record per declared action it reaches: `skipped` when the condition is false, `succeeded` after the handler returns, `failed` with the exception message when it throws (the executor still throws after recording, so fail loud stays). Writing happens after the transition's transaction resolves, so a rolled-back transition records `failed` with "transition rolled back", not `succeeded`.

## D-4: read access

The route is `NoAdminRequired`. A non-admin sees records whose schema they may read objects of, and only for objects they may read. An app reads in process through `AutomationRecordService::find(filters)`, under the current user, the same rules. No recipient identity is ever returned.

## D-5: retention

`automation_record_retention_days`, default 90, pruned by a daily job. A key prefix MAY get its own retention later; not in this change.

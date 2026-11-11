# Proposal: a reset to the shipped baseline is reachable

## Why

The live pass of 5 Oct (learniq defect D13) found `learner-profile` without the learner's own read rule (`{"group":"authenticated","match":{"ncUserId":"$userId"}}`) while the shipped baseline still has it. The shipped-configuration guard reads that difference as a local removal and keeps it on every upgrade, by design: nothing on record tells "never received" from "removed on purpose", and healing it automatically would also re-grant rules a municipality removed deliberately (analysis in `for-ruben/openregister-d13-shipped-guard-cannot-tell-never-received-from-removed.md`).

The guard already has the way back, `previewReset()` and `resetToBaseline()`, but nothing calls them (task 4.1b of `local-changes-to-app-shipped-configuration`). Ruben decided (5 Oct, DECISIONS row 66): make the reset reachable, as an occ command and an admin action, for ONE part at a time, so an administrator decides per case and nothing changes silently.

## What changes

- `ShippedBaselineResetService` previews and applies a reset of one part of one schema (a dotted path such as `authorization.read`). It writes only that part back through `SchemaMapper`, so every other local edit stays as it is, and records the reset on the audit trail (actor, schema, part, from, to) only after the write succeeded.
- `occ openregister:schema:reset-to-shipped <schema> <part>` shows what it would change and writes nothing. With `--apply --actor=<admin uid>` it performs the reset as that administrator. Both arguments are required: there is no "reset everything".
- Admin routes `GET /api/schemas/{id}/shipped-baseline/reset?path=<part>` (preview) and `POST /api/schemas/{id}/shipped-baseline/reset` with `{"path": "<part>"}` (apply).
- `ShippedConfigurationGuard::resetToBaseline()` gains `record: false` and returns `from`/`to`; `recordReset()` writes the audit row.

## Impact

- New: `lib/Service/ShippedBaseline/ShippedBaselineResetService.php`, `lib/Command/ResetSchemaToShippedCommand.php`, `lib/Controller/ShippedBaselineController.php`, two routes, one `<command>` in `appinfo/info.xml`.
- Changed: `ShippedConfigurationGuard` (backwards compatible: `record` defaults to true).
- No automatic heal; no schema is touched unless an administrator names it and the part.

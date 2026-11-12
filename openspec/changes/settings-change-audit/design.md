# Design: settings-change-audit

## D-1: one entry per key, not one per request

An administrator who changes six keys in one save produces six rows. A
reader filtering on one key finds it; a reader who wants the save finds six
rows with the same request id. Diffing per key is what makes the row
answerable.

## D-2: masked, but recorded

A rotated secret is the change a security officer most wants to see and
least wants to read. The row says the key changed, by whom, when, and shows
`***` for both values. Declared once on the register configuration.

## D-3: on the chain

A settings row is an audit-trail row with a `settings` subject instead of
an object, hashed into the same chain, so tampering with it is as evident
as tampering with an object change. No new table.

## D-4: kind

Code, in OpenRegister. Consuming apps get it by riding the plane.

## Open questions (Ruben)

`SettingsChangeAuditor::recordImport()` is built and tested but `loadConfiguration()` does not call it (task 1.1c). One answer is needed.

1. **What "the number of keys overwritten" counts.** An import writes registers, schemas and objects through `ConfigurationService::importFromApp()`; it writes no keys into the leaf app's own app config, so a count of changed app-config keys would always be 0.
   - Proposed: count what the import wrote, from the lists `importFromApp()` returns (`registers`, `schemas`, `objects`), as three counts on the one `settings.imported` row: `{app, forced, registers, schemas, objects}`.
   - Options: (a) as proposed; (b) app-config keys changed before and after the import (honest but nearly always 0); (c) no count, only `{app, forced, version}`.
   - Also: record every import, or only `load(force)`? Proposed: every import that changed something, with `forced` on the row; a repair-step import with nothing to change writes no row.

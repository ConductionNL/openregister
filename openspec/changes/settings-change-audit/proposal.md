---
kind: code
depends_on: [apphost-settings-plane, audit-log-page]
---

# Proposal: settings-change-audit

## Summary

Record who changed a setting. Object writes are on the hash-chained audit
trail; settings writes are not. The settings plane's `update` is the one
door every app's configuration goes through once it rides the plane, so
this change writes an audit entry there: app, key, old value, new value,
actor, time, on the same chain, with secrets masked, and lists them on the
audit page.

## Ledger rows

| row | capability | rating | size |
|---|---|---|---|
| Q10.13 | Is a settings change recorded anywhere | partial | S |

## Why

The register's note: "Object changes are audited through OpenRegister.
Settings changes are not: the `audit` hits under `lib/Settings/` are schema
fields on data, not a log of configuration changes." The best competitor,
verbatim from the `best` column: "GLPI 11: configuration objects audited
(`_round4/compare/proposed-rows.md`)".

The register's `why`: "a record of who changed a setting is the settings
plane's audit, not per app".

## What changes

- `GenericSettingsService::update()` computes the per-key diff against the
  stored configuration and writes one audit entry per changed key with
  `action: settings.updated`, `app`, `key`, `old`, `new`, actor and time,
  through `AuditHashService` so the entry is on the chain.
- Keys declared secret in the app's register configuration
  (`x-openregister-secret: true`) are recorded as changed with both values
  masked.
- `load(force)` writes one entry naming the import and the count of keys
  it overwrote.
- OpenRegister's own settings handlers (`SettingsService` domains) route
  through the same writer.
- The audit page (`audit-log-page`) gains a kind filter `settings` and shows
  the diff per row; the export carries it.
- Per-user preferences (`GenericPreferencesController`) are not audited.

## Consumers

- dossiq: route dossiq settings writes through the settings plane once it
  audits. Specified in dossiq by the dossiq lane (register row Q10.13).
- Every app on the plane, for free.

## ADRs

- openregister ADR-003 (immutable hash-chained audit trail): the entry is
  on the same chain, not a second log.
- ADR-076: the plane is the one door.
- ADR-005 (security): secrets never reach a log.

## Impact

- Extends: `apphost-settings-plane` requirement "Generic settings surface"
  and `audit-log-page` requirement "An instance-wide audit list with
  filters".
- Affected code: `GenericSettingsService`, `SettingsService`,
  `AuditTrailService` (a `settings` subject kind), the audit leaf's list and
  export.
- Size: S.

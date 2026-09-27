---
kind: code
---

# Proposal: history-schema-and-settings-edits-audited

## Summary

A functional administrator can see who changed a schema or a register, when, and what changed: a property added, a type narrowed, an authorization rule widened. Every create, update and delete of a schema or a register writes a row on the same hash-chained audit trail that record changes use. An administrator reads those rows on the schema's and the register's detail page, and through the audit trail API. A change made by an import or a migration says so, so a person's edit and an app update do not look alike.

## Rows this closes

| matrix | row | capability | own rating |
|---|---|---|---|
| openregister | hist-admin-actions | See admin and configuration actions, such as role, token and locale changes, in the audit trail, not only record changes. | partial |

**hist-admin-actions** (openregister's matrix)

- Demand: feature request, https://github.com/strapi/strapi/issues/23493 (the row's origin).
- Competitor yes cells:
  - directus (Directus), no evidence URL, source path cited: "source read at v12.4.1, not driven: system collections default to accountability all directus:packages/system-data/src/collections/collections.yaml:11 and only activity, presets, revisions and oauth tables opt out (:22,:58,:66,:127); roles, policies and settings services extend ItemsService (directus:api/src/services/roles.ts:12, policies.ts:9, settings.ts:17), so their create, update and delete write an activity row directus:api/src/services/items.ts:331-341".

This change closes the schema and register half of the row. The LLM, file and search settings half already has a home, see "Out of scope". The row is fully closed when both have landed.

## Why

Schema and register edits leave no audit row.

- Every schema edit passes through `SchemaMapper::update()` (`lib/Db/SchemaMapper.php:3064-3118`), which reads the old row (`:3073-3078`) and dispatches `SchemaUpdatedEvent` with the old and the new schema (`:3115`). The same holds for create (`:1112`) and delete (`:3209`), and for registers (`lib/Db/RegisterMapper.php:611`, `:758`, `:825`). `lib/AppInfo/Application.php` registers 36 listeners, ten classes, on those six events (for example `:3297`, `:3406`, `:3488`). None of them writes to the audit trail.
- Schemas touch the audit mapper for statistics only (`lib/Controller/SchemasController.php:321`, `getStatisticsGroupedBySchema`).
- The writer that records a before and an after exists for settings, `SettingsChangeAuditor::recordUpdate()` (`lib/Service/Rbac/SettingsChangeAuditor.php:210-231`), and writes through the sealing path `AuditTrailMapper::insertAuditTrails()` (`:298-315`). Nothing calls anything like it for a schema or a register.
- The gap blocks other work. `local-changes-to-app-shipped-configuration` task 2.2 is blocked because "entity edits do not reach the object audit trail, so there is nowhere to read the actor and the moment from", and it says "Naming a schema edit on the trail is its own change". This is that change.

## What changes

- A listener on the six schema and register events writes one audit row per create, update and delete, sealed on the existing chain.
- An update row carries the changed fields as `{path, old, new}`, with each property of a schema diffed on its own path, so "`properties.omschrijving.maxLength` 200 to 80" is one entry.
- Values above 2 KB are stored as a hash and a length. Keys that name a credential are recorded as changed with both values masked.
- A row carries the cause and run from `WriteCause::current()`, so an import, a migration or a person is named.
- `GET /api/schemas/{id}/changes` and `GET /api/registers/{id}/changes` list the rows for one entity, newest first, paginated, administrator-only.
- A "Changes" tab on the schema detail page and a "Changes" section on the register detail page show them to administrators.

## Consumers

- `local-changes-to-app-shipped-configuration` task 2.2: its divergence report reads the actor and moment of a local schema edit from these rows.
- `audit-log-page`: the rows appear on the instance audit list under the actions `schema.*` and `register.*`.

## ADRs

- openregister ADR-003 (immutable hash-chained audit trail): rows go through `insertAuditTrails()`, which seals them; there is no second log.
- openregister ADR-002 (organisation tenancy): the row carries the entity's organisation, and the read endpoints are administrator-only like `GET /api/audit-trails`.
- hydra ADR-005 (security): credentials are masked before the row is written, because an audit row cannot be redacted afterwards.
- hydra ADR-058 (bounded object queries): the read endpoints are paginated with a hard page cap.
- hydra ADR-004 (frontend): the tab and section use the existing detail page layout.

## Impact

- Extends the capability `audit-trail-immutable` (its requirement "Every mutation MUST produce an immutable audit trail entry" covers objects only).
- Affected code: a new `lib/Service/Audit/EntityEditAuditor.php` and `lib/Listener/EntityEditAuditListener.php`, `lib/AppInfo/Application.php` (six registrations), `lib/Controller/SchemasController.php` and `lib/Controller/RegistersController.php` (a `changes` action each), `appinfo/routes.php`, `src/views/schema/SchemaDetails.vue`, `src/views/register/RegisterDetail.vue`.
- Backwards compatible. New rows use new action names; no existing row or reader changes.
- Size: M.

## Out of scope

- The LLM, file and search settings. `settings-change-audit` owns "OpenRegister's own settings handlers (`SettingsService` domains) route through the same writer" (its proposal, "What changes"). Its task 1.3 is ticked, but its own note says "Not yet wired: the LLM, file, Solr and cache handlers, which save through their own classes", and `lib/Service/Settings/LlmSettingsHandler.php:175` and `lib/Service/Settings/FileSettingsHandler.php:177` indeed save without `OwnSettingsChangeRecorder`. That remainder belongs there, not in a second spec.
- Role and group changes. Nextcloud writes those to its own `admin_audit` log.
- Undoing a schema edit from its row. The row records; restoring is `schema-migration`'s.

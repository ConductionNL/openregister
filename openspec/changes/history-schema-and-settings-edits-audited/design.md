# Design: history-schema-and-settings-edits-audited

Read at openregister development c53dd0685c.

## D-1: listen at the mapper, not at the controllers

Schemas and registers are changed through many doors: the controllers, `updateFromArray`, the tool providers, configuration imports and repair steps. The mapper is the one place they all pass. `SchemaMapper::update()` (`lib/Db/SchemaMapper.php:3064-3118`) fetches the old row without the organisation filter (`:3073-3078`), writes, and dispatches `SchemaUpdatedEvent(newSchema, oldSchema)` (`:3115`). Create dispatches at `:1112`, delete at `:3209`. `RegisterMapper` does the same (`lib/Db/RegisterMapper.php:611`, `:739-761`, `:825`).

A new `lib/Listener/EntityEditAuditListener.php` is registered in `lib/AppInfo/Application.php` for `SchemaCreatedEvent`, `SchemaUpdatedEvent`, `SchemaDeletedEvent`, `RegisterCreatedEvent`, `RegisterUpdatedEvent` and `RegisterDeletedEvent`. It hands the entities to `lib/Service/Audit/EntityEditAuditor.php`, which builds and writes the row. A door that bypasses the mapper bypasses the audit too; the unit test for D-5 asserts that no controller writes `openregister_schemas` or `openregister_registers` except through the mapper.

## D-2: the row

One `AuditTrail` (`lib/Db/AuditTrail.php`) per event:

| field | value |
|---|---|
| `action` | `schema.created`, `schema.updated`, `schema.deleted`, `register.created`, `register.updated`, `register.deleted` |
| `schema` / `schemaUuid` | set on a schema row |
| `register` / `registerUuid` | set on a register row |
| `object`, `objectUuid` | null: there is no object |
| `organisationId` | the entity's organisation |
| `changed` | see below |
| `user`, `userName` | the session user, or `system` when there is none, as `SettingsChangeAuditor::row()` does (`lib/Service/Rbac/SettingsChangeAuditor.php:271-289`) |
| `cause`, `causeRun` | from `WriteCause::current()` (`lib/Service/WriteCause.php:169`), so `import`, `migration` or `person` |

`changed` for an update is `{"slug": "...", "versionBefore": "...", "versionAfter": "...", "fields": [{"path": "...", "old": ..., "new": ...}]}`. For a create it is the slug, title, version and property names. For a delete it is the slug, title, version, property count and the sha256 of the full definition, so a later reader can prove which definition was deleted without the row carrying it.

## D-3: the diff is per path, and skips what the server derives

`EntityEditAuditor::diff()` compares the old and new `jsonSerialize()` output:

- `properties` is diffed per property and per keyword: `properties.omschrijving.maxLength`. A new property is one entry with `old: null`; a removed one has `new: null`.
- `configuration` and `authorization` are diffed per key, because a widened read rule is the change an auditor looks for first.
- `updated`, `created` and `facets` are skipped. `facets` is regenerated from the properties on every update (`lib/Db/SchemaMapper.php:3089`), so recording it would duplicate every property change.
- Values compare loosely for scalars, the same `"1"` over `1` rule `SettingsChangeAuditor` applies, so a save that changed nothing writes nothing.

## D-4: large values and credentials

- A value whose JSON is longer than 2,048 bytes is stored as `{"sha256": "...", "length": n}`. A schema can carry a large `hooks` block or an enum of thousands of codes; a per-edit copy would make the audit table larger than the schemas.
- A key named `password`, `secret`, `token`, `apiKey`, `clientSecret` or `privateKey`, at any depth, is recorded as changed with both sides masked, the way `SettingsChangeAuditor` masks a declared secret (`lib/Service/Rbac/SettingsChangeAuditor.php:329-335`). The trail is append-only, so a credential written into it can never be taken out.

## D-5: writing never fails the edit

The listener runs after the entity is stored. Like `SettingsChangeAuditor::write()` (`:298-315`), `EntityEditAuditor` catches a failed write, logs it at ERROR with the entity id, and returns. Failing the request would report a failed save for a change that happened. The ERROR line is what an operator alerts on.

## D-6: reading the rows

`SchemasController::changes(int $id)` and `RegistersController::changes(int $id)`, routed as `GET /api/schemas/{id}/changes` and `GET /api/registers/{id}/changes`, return the rows for that entity, newest first, with `_page` and `_limit` (default 20, maximum 100). Both are administrator-only: no `#[NoAdminRequired]`, the same posture as `GET /api/audit-trails` (`lib/Controller/AuditTrailController.php`, "Admin-only at the framework level"). They read through the existing audit mapper filters on `schema` or `register` plus `action`, so no new query path is added.

On the page, `src/views/schema/SchemaDetails.vue` gets a fifth tab, "Changes", beside the four at `:88-118`, shown only when the user is an administrator. `src/views/register/RegisterDetail.vue` gets a "Changes" section in its `CnDetailPage`. Both list who, when, cause and the changed paths, with old and new values side by side.

## Declarative-vs-imperative decision

Imperative. The row is a consequence of an entity event, not a rule a schema author declares, and there is no per-schema choice to make: every schema and register edit is audited. A listener on the existing events is the smallest imperative path, and it keeps the controllers untouched.

## Risks

- **Security.** Rows are readable only by administrators. Credentials are masked before writing (D-4). A deleted schema's definition is kept only as a hash.
- **Performance.** One extra insert per schema or register write, which are administrative and rare. Imports that write many schemas write one row each; the rows carry the import's cause and run, so they are reachable as a set.
- **Multitenancy.** The old row is read without the organisation filter (`lib/Db/SchemaMapper.php:3073-3078`), which is right for the diff. The audit row carries the entity's own organisation, and the read endpoints are administrator-only, so no tenant sees another tenant's edits through them.

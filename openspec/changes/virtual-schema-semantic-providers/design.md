# Design: Virtual schemas + inheritance-aware semantic providers

## Principle
Everything reuses existing seams; nothing forks the ADR-048 resolver. A virtual
schema is a normal `Schema` row, so it is already enumerated by
`SchemaMapper::findAll()` and discoverable by `SemanticTypeResolver`.

## Reused building blocks (do not reinvent)
| Need | Existing asset |
| --- | --- |
| External/virtual object source | `x-openregister-object-source` schema key (`Schema::getObjectSource()`), `ObjectSourceProvider`, `ObjectSourceRegistry`, read-path delegation (`GetObject::resolveObjectSource`, `ObjectService::paginateObjectSource`), DI (`Application::registerObjectSourceProviders`/`bootObjectSourceProviders`) |
| Reference provider shape | `CalDavVtodoObjectSourceProvider` (read-only, `toObjectEntity` no-persist, `isEnabled` gates on app) |
| Schema inheritance | `SchemaMapper::resolveAllOf` (merges parent properties+required; the `allOf` typed field on `Schema`) |
| Implemented-types | `JsonLdContextService::getImplementedTypes` (single-schema today) |
| Resolver + app-enabled gate | `SemanticTypeResolver::resolveSchemaByImplements` + `isSchemaProvidedByEnabledApp` |

## Part 1 — inheritance-aware implemented-types (Q1)
`getImplementedTypes($schema)` today reads only the one schema's markers. Change:
compute the union of the schema's own markers **and** each `allOf` ancestor's
implemented-types, recursively, with a visited-set circular guard (mirror
`resolveAllOf`). To keep `JsonLdContextService` dependency-light, do the ancestor
walk in `SemanticTypeResolver` (which already has `SchemaMapper`): resolve each
`allOf` ref → load the parent Schema → union its `getImplementedTypes`. A child
implements the union of own + all ancestors; it may add, never remove. Additive:
no `allOf` ⇒ unchanged.

## Part 2 — NC-entity semantic seed map (Q3)
A hardcoded constant, e.g.:
```php
NcEntitySemanticMap = [
  'user'  => ['register' => 'directory', 'schema' => 'nc-user',  'schemaOrg' => 'schema:Person',       'provider' => 'user-directory-source',  'requiredApp' => null /* core */],
  'group' => ['register' => 'directory', 'schema' => 'nc-group', 'schemaOrg' => 'schema:Organization', 'provider' => 'group-source',           'requiredApp' => null],
  // follow-ons: contact→Person/contacts, event→Event/calendar, file→DigitalDocument/files, deck→Action, talk→Conversation, task→Action
]
```
A Repair/seed step materialises one virtual register + schema per row (idempotent;
matches OR's existing register-import-via-Repair pattern). The `schemaOrg` value
IS the map — it becomes the schema's `x-schema-org`, feeding the single
`getImplementedTypes` → resolver path. No parallel resolution branch.

## Part 3 — always-available Directory register (Q2/Q4)
Seed a virtual register `directory` (`application: openregister`, always enabled)
with:
- `nc-user`: `x-schema-org: schema:Person`, `x-openregister-object-source.provider: user-directory-source`, minimal read properties (id=uid, displayName, email).
- `nc-group`: `x-schema-org: schema:Organization`, `provider: group-source`, properties (id=gid, displayName).

Two new providers implementing `ObjectSourceProvider`, shaped like
`CalDavVtodoObjectSourceProvider`:
- `UserDirectoryObjectSourceProvider` — `IUserManager` (search/get/count); `find` by uid; `toObjectEntity` sets uuid=uid, register/schema, no persist; user-scoped (admins see all, users see themselves per instance policy).
- `GroupObjectSourceProvider` — `IGroupManager` (search/get/count).
- `isEnabled()` → true (core). Writes rejected (already enforced in SaveObject/DeleteObject for object-source schemas).

Registered via the existing `registerObjectSourceProviders`/`bootObjectSourceProviders`.

## Resolution & picker (no change)
`resolveSchemaByImplements('https://schema.org/Organization')` enumerates
findAll() → `nc-group` implements it → returned. The nc-vue object picker queries
`/api/objects/directory/nc-group`, which `paginateObjectSource` routes to
`GroupObjectSourceProvider` — no frontend change. The ADR-048 app-enabled gate
uses the schema/register `application` field; `directory` is `openregister`
(always enabled) so it never degrades.

## Ordering with leaf adherers (Q4)
Once `nc-group` provides `schema:Organization` always, leaf `organization`/`Payee`
schemas are additional adherers. `SemanticTypeResolver` picks deterministically
(first-by-slug) + WARN-logs; the `referenceSemanticApp` hint biases toward a
specific richer provider (e.g. shillinq Payee) when the consumer names it. OR owns
the thin canonical identity; specialised masters stay in their apps (ADR-022).

## Non-goals
- No write path to virtual objects (read/pick only).
- No Contacts/Calendar/Files/etc providers in this change (follow-ons).
- No physical removal of leaf `organization` duplicates (deferred behind the URI).

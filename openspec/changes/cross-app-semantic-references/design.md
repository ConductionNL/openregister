# Design: Cross-app object references by canonical semantic type

## Guiding principle

Additive extension of ADR-019. The reference *linkage key* changes from an
app-coupled identifier (schema slug for `$ref`, integration id for
`referenceType`) to a **canonical semantic-type URI** resolved across *all
installed schemas*, with a **null-safe** resolver and an **explicit disabled
affordance** when no provider is installed.

## Building blocks that already exist (reuse, do not reinvent)

| Need | Existing asset |
| --- | --- |
| Schema-side semantic type | `Schema.configuration.jsonld.type` — validated by `JsonLdContextService::validateMapping()`, serialized as object `@type`, schema.org-first per ADR-011 |
| IRI validation | `JsonLdContextService::isAbsoluteIri()` |
| Property reference marker precedent | `referenceType` (+ `PropertyReferenceTypeValidator`), surfaced in `fieldsFromSchema` (`schema.js`) and rendered in `CnFormDialog::resolveReferenceWidget()` |
| Cross-register schema enumeration | `SchemaMapper::findAll()` (org/RBAC-scoped, no register filter) |
| Config→entity resolver precedent | `RegisterResolverService` (request-scoped cache, typed exceptions) |
| Absent-app detection | `useAppStatus` / `isAppInstalled` / `resolveProviderAvailability` (reason ∈ `available|missing-app|not-configured|unknown`) |
| "{App} not available" copy | `CnIntegrationWidget.vue` empty-state |
| Disabled-field plumbing | `CnFormDialog` binds `:disabled` on every widget; `helper-text` from `field.description` |

## What is genuinely new

1. `configuration.implements: string[]` (alias, defaults to the union of
   `jsonld.type` and the `x-schema-org` marker).
2. A schema-level `x-schema-org` marker that survives save/import: a **top-level**
   `x-schema-org` (the fleet's ADR-011 annotation form, a sibling of
   `properties`) is folded into `configuration['x-schema-org']` on hydrate — the
   same fold already applied to `x-openregister-*` blocks — so the live schema
   keeps the marker instead of dropping it (the previous behaviour that left
   every real imported schema unresolvable).
3. Property keyword `referenceSemanticType: <IRI>`.
4. `SemanticTypeResolver::resolveSchemaByImplements(uri): ?Schema`, which
   additionally treats a provider whose owning app is **disabled** as absent.
5. Discovery of resolved providers to the frontend.
6. `CnFormDialog` degradation branch for an unresolved semantic reference.

## Data model

### Provider side (e.g. shillinq `Payee`)

Fleet schemas declare the marker at the **top level** (schema.org-first,
ADR-011); on save it is folded into `configuration` and becomes resolvable with
no manual `implements`:
```jsonc
{
  "title": "Payee",
  "x-schema-org": "schema:Organization",   // top-level, folded on save →
  "properties": { /* ... */ }
  // configuration['x-schema-org'] = "schema:Organization"  (persisted)
  // implements defaults to ["https://schema.org/Organization"]
}
```
Equivalently, a schema MAY carry `configuration.jsonld.type`,
`configuration['x-schema-org']`, or an explicit `configuration.implements[]` —
all three feed `JsonLdContextService::getImplementedTypes()`.

### Consumer side (pipelinq `product`)
```jsonc
"buyCost": {
  "title": "Buy cost", "type": "number",
  "description": "Purchase/cost price. Sourced from the vendor when available."
},
"vendor": {
  "title": "Vendor", "type": "string", "format": "uuid",
  "referenceSemanticType": "https://schema.org/Organization",
  "referenceSemanticApp": "shillinq"
}
```
`referenceSemanticApp` (optional) is a **hint only** — used for the "install
shillinq" tooltip copy and as a tie-break, never a hard requirement. Resolution
is still by URI, so any app exposing `schema.org/Organization` satisfies it.

## Resolution algorithm (`SemanticTypeResolver`)

```
resolveSchemaByImplements(uri):
  cache-hit? return it
  candidates = SchemaMapper.findAll()  # org/RBAC-scoped, all registers
      .filter(s => uri in getImplementedTypes(s))   # implements[] ∪ jsonld.type ∪ x-schema-org
      .filter(s => owning app of s is installed AND enabled)   # disabled app ⇒ absent
  if empty: cache null; return null            # standalone-safe
  pick = deterministic(candidates):            # any adherer is acceptable — no clever tie-break
      first by slug, optionally biased to the consuming register when that hint is given
  cache pick; return pick
```
- **No sophisticated tie-break** (product-owner decision): any schema adhering to
  the URI is an acceptable provider. Selection is just deterministic-first-by-slug,
  with an optional trivial bias to the consuming register kept from the earlier
  design because it costs nothing.
- **App-enabled gate**: a provider whose owning app is not enabled is skipped as
  if uninstalled — via `IAppManager::isEnabledForUser` (falling back to
  `isInstalled`), fully null-safe. The owning app id is taken from the schema's
  own `application` field first (the reliable per-schema signal — a register's
  `application` column is frequently null), then the owning register's
  `application`. Core `openregister` / schemas with no declared owning app are
  never filtered out.
- Never throws on "not found" — returns `null`.
- Request-scoped cache keyed by `uri` (+ consuming register when biasing),
  mirroring `JsonLdContextService::$contextCache`.
- A `WARN`-level log (not an error) when >1 candidate matches, naming the pick,
  so ambiguous vocab is observable without breaking rendering.

## Discovery surface

Extend the existing integrations OCS capability payload
(`availability.js` already reads `openregister.integrations.providers[]`) with a
parallel `semanticProviders` list: `[{ uri, register, schema, appId, available,
reason }]`, computed server-side from resolvable schemas + `getRequiredApp`
availability. Plus a thin `@NoAdminRequired` `SchemasController` action
`resolveByImplements(uri)` for on-demand lookup. The frontend prefers the
capability payload (no round-trip) and falls back to the endpoint.

## Frontend flow (`fieldsFromSchema` + `CnFormDialog`)

1. `fieldsFromSchema` surfaces `referenceSemanticType` (+ `referenceSemanticApp`)
   onto the field descriptor next to where `referenceType` is surfaced today.
2. `CnFormDialog` field rendering gains a branch, ordered:
   - `resolveReferenceWidget(field)` (existing `referenceType` integration) →
   - **NEW** `field.referenceSemanticType`:
     - resolve URI → provider via the capability payload;
     - **resolved** → render the standard `$ref`-style searchable object
       dropdown bound to `{register, schema}` of the provider, storing the UUID;
     - **unresolved** → render a **disabled** field (`:disabled="true"`) with a
       tooltip: `t('The {type} app is not installed', {type: label(uri)})`,
       label derived from the URI (last path segment) and `referenceSemanticApp`
       hint; copy sourced from `resolveProviderAvailability` reason-codes.
   - else existing auto-field.
3. Backward compatible: absent `referenceSemanticType` → unchanged.

## Ambiguity & governance

- schema.org-first (ADR-011); a small Conduction canonical vocabulary under
  `https://openregister.app/ns#` only where schema.org has no fit.
- Multiple providers for one URI → any adherer is acceptable; the resolver just
  picks deterministically (first by slug) and WARN-logs. No preferred-provider
  map is introduced (product-owner decision); a future change may add one if
  collisions ever become a real problem.

## Non-goals

- No write-back / ownership transfer: the referenced object stays owned by its
  home app; this is a read/pick reference only.
- No automatic buy-cost sync from the vendor — `buyCost` is a plain field; a
  later change may add a declarative pull (`@ref`) once the reference exists.

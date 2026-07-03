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

1. `configuration.implements: string[]` (alias, defaults to `[jsonld.type]`).
2. Property keyword `referenceSemanticType: <IRI>`.
3. `SemanticTypeResolver::resolveSchemaByImplements(uri): ?Schema`.
4. Discovery of resolved providers to the frontend.
5. `CnFormDialog` degradation branch for an unresolved semantic reference.

## Data model

### Provider side (e.g. shillinq `Payee`)
```jsonc
"configuration": {
  "jsonld": { "type": "https://schema.org/Organization" }
  // implements defaults to ["https://schema.org/Organization"]
}
```

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
      .filter(s => uri in (s.configuration.implements ?? [s.configuration.jsonld.type]))
  if empty: cache null; return null            # standalone-safe
  pick = tie-break(candidates):
      1. same register as the consuming schema (when known)
      2. schema whose register's app == referenceSemanticApp hint
      3. first by slug (deterministic)
  cache pick; return pick
```
- Never throws on "not found" — returns `null`.
- Request-scoped cache keyed by `uri` (+ consuming register when tie-breaking),
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
- Multiple providers for one URI → deterministic tie-break above +
  `referenceSemanticApp` hint to narrow. A future change may add an
  admin-level preferred-provider map if collisions become common.

## Non-goals

- No write-back / ownership transfer: the referenced object stays owned by its
  home app; this is a read/pick reference only.
- No automatic buy-cost sync from the vendor — `buyCost` is a plain field; a
  later change may add a declarative pull (`@ref`) once the reference exists.

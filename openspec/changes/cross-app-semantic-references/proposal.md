---
kind: code
---

# Proposal: Cross-app object references by canonical semantic type

## Problem

Conduction apps are independently installable but share the OpenRegister object
foundation. Real workflows cross app boundaries, yet OR references are keyed to
a **specific schema identity** (`$ref` = slug/id) or a **specific integration
id** (`referenceType`). Neither expresses *"a reference to any object of KIND X,
provided by whatever app supplies it, or null if none is installed."*

Concrete driver: pipelinq just dropped its redundant `supplier` schema (a thin
subset of shillinq's `Payee` procurement master). We now want pipelinq's
`product` to carry a **buy cost + vendor** where the vendor is shillinq's
`Payee` — *if shillinq is installed* — without pipelinq hard-coupling to
shillinq's slug or existence, and with the field degrading gracefully (disabled
+ explained) when shillinq is absent so pipelinq still runs standalone.

## Proposed Change

Add a semantic-type reference layer on top of ADR-019's reference mechanism.
Reuse existing infrastructure wherever it exists — this is deliberately additive.

1. **Schema advertises its semantic type** — reuse the existing, validated,
   `@type`-serialized `configuration.jsonld.type` (ADR-011 schema.org-first).
   Add an optional `configuration.implements: ["<uri>", …]` alias defaulting to
   `[jsonld.type]` so one schema can advertise multiple capability URIs.
2. **Property references a semantic type** — new property keyword
   `referenceSemanticType: "<absolute-IRI>"` (semantic sibling of
   `referenceType`), validated as a well-formed IRI (reuse
   `JsonLdContextService::isAbsoluteIri`), value stored as the target UUID.
3. **Resolver** — a `SemanticTypeResolver` (sibling of the done
   `RegisterResolverService`) with `resolveSchemaByImplements(uri): ?Schema`
   over `SchemaMapper::findAll()`, request-cached, returning **null** (never an
   exception) when no installed schema matches; deterministic tie-break when
   several match (same-register → configured preferred → first by slug).
4. **Discovery** — expose resolved semantic providers to the frontend via the
   existing OCS capabilities payload the availability engine already reads
   (`availability.js`), plus a `@NoAdminRequired` lookup on `SchemasController`.
5. **Form degradation (nc-vue)** — a new `CnFormDialog` branch: when
   `referenceSemanticType` is set but no provider resolves, render a **disabled**
   field with a `title=`/helper-text tooltip sourced from the existing
   `resolveProviderAvailability` reason-codes and "{App} not available" copy —
   instead of the current silent fallback to a plain input. When a provider IS
   resolved, render the standard `$ref`-style searchable object dropdown.

### Scope

**In scope**: the `implements` alias + validation, `referenceSemanticType`
property keyword + validator, the resolver + discovery surface, the nc-vue
form-degradation branch, and the first adopter (`product.vendor` + `buyCost`
referencing `schema.org/Organization`).

**Out of scope**: changing existing `$ref`/`referenceType` behaviour; a
governance registry of canonical URIs beyond "schema.org-first + a small
Conduction vocabulary"; write-back / bidirectional sync of the referenced
object (it stays owned by its home app).

## Impact

- **OpenRegister**: additive schema `configuration` key, new property keyword +
  validator, new resolver service, capability/endpoint surface. No change to
  existing reference resolution paths.
- **nextcloud-vue**: new descriptor field in `fieldsFromSchema`, new degradation
  branch in `CnFormDialog` (backward-compatible — untouched when the keyword is
  absent).
- **Consuming apps**: opt-in per property; apps without the provider app keep
  working (field disabled).
- **Security (ADR-005)**: read-only reference; resolver is org/RBAC-scoped via
  `SchemaMapper::findAll()`; no new IDOR surface (value is a UUID the user picks
  from RBAC-filtered results).

## Dependencies

- ADR-048 (hydra) — records the cross-app semantic-reference decision.
- ADR-019 (integration-registry), ADR-011 (schema-standards), ADR-022
  (apps-consume-OR-abstractions).
- Existing: `JsonLdContextService`, `SchemaMapper::findAll()`,
  `PropertyReferenceTypeValidator`, `RegisterResolverService`,
  `CnFormDialog` + `CnIntegrationWidget/availability.js`.

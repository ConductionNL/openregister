---
kind: code
depends_on: [object-source-providers]
---

# Proposal: external-register-view-leaf

## Summary

Show a record from an external register inside the app, beside the object
it belongs to. `object-source-providers` lets a schema's objects be served
read-only from a provider instead of the magic tables. Nothing yet renders
such a record on another object's page keyed by one of its fields: a BAG
address beside a case, a KvK extract beside a company. This change adds a
generic leaf surface, `external-register`, that takes a sourced schema and a
key property on the host object and renders the matching record, degrading
when the source is absent.

## Ledger rows

| row | capability | rating | size |
|---|---|---|---|
| 5.13 | External register views embedded in the app | partial | M |

The row is statutory in the register: a municipality must consult the
basisregistraties it is obliged to use.

## Why

The register's note: "`/api/external/bag, brk, woz` lookups,
`src/components/map/AddressSearch.vue`; no embedded register view". The
`best` column reads "no competitor scores yes".

The register's `why`: "an external register shown inside the app is an
object source provider, not a dossiq page", and its `dossiq_half`: "place
the BAG, BRK and WOZ lookups as leaf widgets on the case location".

The connectors themselves are integriq's (ADR-019, ADR-091 §6): a BAG
source is an OpenConnector source. What OpenRegister owns is the projection
(a sourced schema) and the surface that renders it on another object.

## What changes

- An `ExternalRegisterProvider` integration leaf (ADR-019, group `data`)
  with `widget` and `tab` surfaces. A manifest placement names the sourced
  schema and the host property that keys it:
  `{ "schema": "bag-adres", "key": "address.identifier", "display": [...] }`.
- The surface reads the sourced schema's object by key through the object
  source read path, so RBAC, tenancy and fail-closed rules are the provider's
  (object-source-providers, "Object-source reads enforce RBAC and fail
  closed"). A missing provider renders an explained empty state, never an
  error.
- Three sourced schemas seeded as examples, disabled until a source is
  configured: `bag-adres`, `brk-perceel`, `woz-waarde`, each with a
  generic HTTP object-source provider that maps a configured OpenConnector
  source's response through a declared mapping (`x-openregister-object-source`
  with `provider: "openconnector-http"`).
- A refresh action on the surface re-reads the record; the surface shows
  when the record was fetched and from which source.
- The record is never copied into the host object; a consuming app that
  needs a snapshot writes it through its own property.

## Consumers

- dossiq: place the BAG, BRK and WOZ lookups as leaf widgets on the case
  location. Specified in dossiq by the dossiq lane (register row 5.13).
- integriq: owns the BAG, BRK and WOZ sources and their credentials.
- pipelinq (KvK extract on a company), humaniq (BRP record on an
  employee), zaakafhandelapp.

## ADRs

- ADR-019 (integration registry): one provider, three artefacts.
- ADR-091 §6: the national register protocols live in OpenConnector.
- ADR-103 (virtual schemas and semantic providers): a sourced schema is a
  virtual schema.
- ADR-066: a render-and-read leaf.
- ADR-022.

## Impact

- Extends: `object-source-providers` requirements "Pluggable object-source
  provider interface" and "Read path delegates to the provider when an
  object source is present"; `integration-registry`.
- Affected code: `lib/Service/Integration/Providers/ExternalRegisterProvider.php`,
  `lib/Service/ObjectSource/OpenConnectorHttpProvider.php`, three seeded
  schemas, the leaf Vue surfaces.
- Size: M.

# Add Addresses Register

## Why

This change implements the `[openregister]` subset of the Hydra-level umbrella change
`shared-pdok-via-openconnector`. OpenRegister needs a canonical, fleet-wide `addresses`
register so every Conduction app has a single authoritative read path for stored address
data — with RBAC, audit trail, retention, and spatial query at no extra implementation cost.
The umbrella's full architecture, design rationale, and migration story live at
`hydra/openspec/changes/shared-pdok-via-openconnector/design.md`.

## What

- A PostalAddress schema JSON at `data/schemas/postal-address.json` (schema.org-aligned,
  BAG-identifier aware, `geo`-typed `location` property per `geo-metadata-kaart`).
- An `addresses` register definition JSON at `data/registers/addresses-register.json`
  with slug `addresses` and uniqueness constraint on `bagAddressId`.
- An install step (migration or seed-data loader) that creates the register and schema
  on first install; the register starts empty.
- PHPUnit tests for schema validation and spatial query integration.
- Test fixtures in `tests/fixtures/addresses/` (Conduction HQ, Tilburg Stadhuis,
  woonplaats-Tilburg).

## Capabilities

### New Capabilities

- `addresses-register`: Canonical `addresses` register in OpenRegister — a PostalAddress
  schema (schema.org-aligned, BAG fields, `geo`-typed `location`); uniqueness enforcement
  on `bagAddressId`; full spatial query support via OR's existing `GeoFilterParser` and
  `GeoFilterApplier`; RBAC and audit trail via OR standard model (ADR-022).

## Affected Repos

openregister only.

## References

- Umbrella spec:
  `hydra/openspec/changes/shared-pdok-via-openconnector/`
- Umbrella design (canonical architecture):
  `hydra/openspec/changes/shared-pdok-via-openconnector/design.md`
- `geo-metadata-kaart` (shipped 2026-05-02) — provides the `geo` property type,
  `GeoFilterParser`, and `GeoFilterApplier` consumed by this register.

## Out of Scope

- The `openconnector` PDOK adapter (write-through path) — covered by sibling spec
  `openconnector/openspec/changes/add-pdok-adapter/`.
- The `procest` frontend shim migration — covered by sibling spec
  `procest/openspec/changes/migrate-pdok-to-openconnector/`.
- Spatial index optimisation (PostGIS GIST etc) — `geo-metadata-kaart`'s
  `geo-spatial-queries` follow-up spec owns it.
- Configurable per-register uniqueness UI — out of scope for this change.
- BAG WFS / tile services — mydash `map-support` owns map widgets.

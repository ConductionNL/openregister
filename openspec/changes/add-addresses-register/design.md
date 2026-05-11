# Design: add-addresses-register

> Cross-repo architecture, design rationale, normalized response schema, and the
> write-through / migration story all live in the umbrella spec:
> `hydra/openspec/changes/shared-pdok-via-openconnector/design.md`
>
> This design document covers only OpenRegister-specific implementation details.

## File Locations

| Artefact | Path in repo |
|---|---|
| PostalAddress schema JSON | `data/schemas/postal-address.json` |
| Addresses register definition | `data/registers/addresses-register.json` |
| Install migration / seed-data loader | `lib/Migration/` or `data/seeds/` (follow existing OR convention) |
| Test fixtures | `tests/fixtures/addresses/` |
| PHPUnit schema-validation tests | `tests/Unit/Schema/PostalAddressSchemaTest.php` |
| PHPUnit spatial-query integration tests | `tests/Service/AddressesRegisterSpatialTest.php` |

## Schema JSON

The PostalAddress schema follows JSON Schema draft 2020-12 and is schema.org-aligned
(`https://schema.org/PostalAddress`) extended with BAG identifiers and PDOK provenance.
The full schema JSON is specified in the umbrella design (see reference above).

Key structural decisions:
- `location` property uses `"type": "geo"` — the `geo` type introduced by
  `geo-metadata-kaart`. OR stores the value as a GeoJSON Point in WGS84 on magic tables.
- Only `location`, `source`, and `displayName` are required. This lets the register
  uniformly store PDOK's three address granularities: address-level (street + number +
  postcode + city), street-level (street + city only), and woonplaats-level (city only).
- `postalCode` carries `pattern: "^[0-9]{4}\\s?[A-Z]{2}$"` — enforced by OR schema
  validation on create/update.
- `source` is an enum: `["pdok", "manual", "import"]`. Addresses inserted by the
  openconnector PDOK adapter carry `source: "pdok"`.
- `bagAddressId` uniqueness is a register-level constraint, not a schema-level keyword —
  see Register Definition section below.

## Register Definition

`data/registers/addresses-register.json` must include:
- `slug: "addresses"`
- Schema reference to `postal-address`
- A uniqueness rule on `bagAddressId` (non-null values only)

The register starts empty. The openconnector PDOK adapter populates it through write-through
on `lookup` and `reverse` calls. No address objects are pre-loaded by the install step.

## Uniqueness Constraint

`bagAddressId` uniqueness is enforced in OR's object create/update path for the
`addresses` register. The constraint applies only to non-null values — objects without
a `bagAddressId` (e.g. woonplaats records and `source: "manual"` entries) are not
subject to the constraint. Duplicate non-null `bagAddressId` on create MUST return
HTTP 409.

The openconnector PDOK adapter's write-through path uses check-then-upsert to avoid
triggering this constraint on concurrent lookups. See umbrella design for the upsert
flow detail.

## Spatial Queries

The `addresses` register gains spatial query support at zero implementation cost in this
change. `GeoFilterParser` and `GeoFilterApplier` (shipped by `geo-metadata-kaart`) are
already wired into OR's listing pipeline and activate automatically for any register that
carries a property of `type: "geo"`. No new spatial primitives are introduced.

Supported query patterns on the `location` property:
- `GET /addresses?geo.near=<lat>,<lng>&geo.radius=<m>` — Haversine radius search
- `GET /addresses?geo.bbox=<minLng>,<minLat>,<maxLng>,<maxLat>` — axis-aligned bbox
- `POST /addresses/geo-search` — polygon (POST body is a GeoJSON Polygon)

## RBAC and Audit Trail

Read/write access to the `addresses` register is governed by OR's existing role/scope
model per ADR-022. No addresses-specific authorization layer is introduced. OR's standard
immutable audit trail is active for all create/update/delete operations on address objects
by default — this requires no extra implementation.

## Seed Data

The `addresses` register starts **empty** in production installs. The three test fixtures
below live in `tests/fixtures/addresses/` for use by PHPUnit and E2E tests only. They are
NOT loaded into the production register.

**fixture-lauriergracht.json** — Conduction HQ (full address-level, all required fields)

```json
{
  "streetAddress": "Lauriergracht",
  "houseNumber": "14h",
  "postalCode": "1016RD",
  "addressLocality": "Amsterdam",
  "addressRegion": "Noord-Holland",
  "addressCountry": "NL",
  "location": {"type": "Point", "coordinates": [4.882, 52.371]},
  "bagAddressId": "0363200000218908",
  "pdokId": "adr-0363200000218908",
  "displayName": "Lauriergracht 14h, 1016RD Amsterdam",
  "source": "pdok",
  "fetchedAt": "2026-05-01T10:00:00Z"
}
```

**fixture-stadhuisplein-tilburg.json** — Municipal building, Tilburg Stadhuis

```json
{
  "streetAddress": "Stadhuisplein",
  "houseNumber": "1",
  "postalCode": "5038TC",
  "addressLocality": "Tilburg",
  "addressRegion": "Noord-Brabant",
  "addressCountry": "NL",
  "location": {"type": "Point", "coordinates": [5.08268, 51.56037]},
  "bagAddressId": "0855200000095886",
  "pdokId": "adr-0855200000095886",
  "displayName": "Stadhuisplein 1, 5038TC Tilburg",
  "source": "pdok",
  "fetchedAt": "2026-05-01T10:00:00Z"
}
```

**fixture-woonplaats-tilburg.json** — Woonplaats (normalization test only)

> NOTE: This fixture has no `postalCode`, `houseNumber`, or `streetAddress`.
> It IS a valid OR PostalAddress object (required fields `location`, `source`,
> `displayName` are present). It seeds correctly into the register and is used
> to test that the schema accepts woonplaats-granularity results from PDOK.

```json
{
  "addressLocality": "Tilburg",
  "addressRegion": "Noord-Brabant",
  "addressCountry": "NL",
  "location": {"type": "Point", "coordinates": [5.0913, 51.5555]},
  "pdokId": "wpl-3b4d4a4f7c14",
  "displayName": "Tilburg",
  "source": "pdok",
  "fetchedAt": "2026-05-01T10:00:00Z"
}
```

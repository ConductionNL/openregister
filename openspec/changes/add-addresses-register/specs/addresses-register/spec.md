---
status: draft
---
# Addresses Register

## Purpose

The OR `addresses` register provides a canonical, fleet-wide PostalAddress store backed
by OpenRegister's RBAC, audit trail, and spatial query capabilities. It is the
authoritative read path for all Conduction app address data: consumers query it using
OR's standard listing API and geo filters. The `openconnector` PDOK adapter is the
designated write-through path — it populates the register by writing PDOK Locatieserver
lookup and reverse-geocode results through.

**Depends on:** `geo-metadata-kaart` (shipped 2026-05-02) — the `geo` property type,
GeoJSON storage, `GeoFilterParser`, and `GeoFilterApplier` are all consumed by this
register without modification.

**Cross-repo contract:** See
`hydra/openspec/changes/shared-pdok-via-openconnector/specs/or-addresses-register/spec.md`
for the full umbrella requirement set. This spec defines the requirements scoped to the
openregister repo only; the write-through and consumer-app contracts live in the umbrella.

## ADDED Requirements

### Requirement: Addresses Register Exists After Install

OpenRegister SHALL ship a register with slug `addresses` and an attached PostalAddress
schema. The register MUST be created as part of this change's install step, available
immediately after `occ upgrade` without manual admin action. The register starts empty.

#### Scenario: Addresses register is present after install

- GIVEN openregister is installed or updated to the version shipping this change
- WHEN an admin navigates to the OR registers list
- THEN a register with slug `addresses` SHALL be listed
- AND the register SHALL have a PostalAddress schema attached
- AND the register SHALL contain zero address objects (empty on install)

#### Scenario: Addresses register survives subsequent upgrades

- GIVEN the `addresses` register and its PostalAddress schema were created by this
  change's install step
- WHEN openregister is upgraded to a later version
- THEN the register SHALL still exist with all its objects and schema intact
- AND no duplicate register or schema SHALL be created by re-running the install step

### Requirement: PostalAddress Schema

The `addresses` register MUST use a schema titled `PostalAddress` with properties aligned
to `https://schema.org/PostalAddress` extended with BAG identifiers, a `geo`-typed
`location` property, PDOK provenance fields (`pdokId`, `fetchedAt`), and a `source`
enum. The required properties MUST be `location`, `source`, and `displayName`.
`postalCode`, `houseNumber`, `streetAddress`, and `addressLocality` are OPTIONAL so the
register can store PDOK address-level, street-level, and woonplaats-level results
uniformly.

#### Scenario: Full address-level object is accepted

- GIVEN the `addresses` register exists with the PostalAddress schema
- WHEN an object is created with `streetAddress`, `houseNumber`, `postalCode`,
  `addressLocality`, `displayName`, `location` (GeoJSON Point), and `source: "pdok"`
- THEN the object SHALL be created successfully and assigned an OR UUID
- AND a GET request for the object SHALL return all submitted fields plus OR metadata

#### Scenario: Woonplaats-only object (no postalCode or houseNumber) is accepted

- GIVEN the `addresses` register exists with the PostalAddress schema
- WHEN an object is created with only `displayName: "Tilburg"`, `addressLocality:
  "Tilburg"`, `location: {"type": "Point", "coordinates": [5.0913, 51.5555]}`, and
  `source: "pdok"` — with no `postalCode`, `houseNumber`, or `streetAddress`
- THEN the object SHALL be created successfully and assigned an OR UUID

#### Scenario: Object missing required field `location` is rejected

- GIVEN the `addresses` register exists with the PostalAddress schema
- WHEN an attempt is made to create an object without the `location` property
- THEN OR SHALL return HTTP 422 with a validation error indicating `location` is required

#### Scenario: Invalid postalCode pattern is rejected

- GIVEN the PostalAddress schema enforces `pattern: "^[0-9]{4}\\s?[A-Z]{2}$"` on
  `postalCode`
- WHEN an object is created with `postalCode: "ABCDEF"`
- THEN OR SHALL return HTTP 422 with a validation error for the `postalCode` field

#### Scenario: Invalid source enum value is rejected

- GIVEN the PostalAddress schema enforces `source` as an enum of
  `["pdok", "manual", "import"]`
- WHEN an object is created with `source: "browser"`
- THEN OR SHALL return HTTP 422 with a validation error for the `source` field

### Requirement: `location` Property Uses `geo` Type

The PostalAddress schema `location` property MUST use the `geo` type introduced by
`geo-metadata-kaart`. The location value MUST be a GeoJSON Point geometry in WGS84
(EPSG:4326). The `geo` type is consumed as-is; no new geo primitives are introduced by
this change.

#### Scenario: GeoJSON Point location is stored and returned correctly

- GIVEN a PostalAddress object is created with
  `location: {"type": "Point", "coordinates": [4.88525, 52.37025]}`
- WHEN the object is retrieved via the OR API
- THEN `location` SHALL equal `{"type": "Point", "coordinates": [4.88525, 52.37025]}`
- AND coordinate precision SHALL be preserved (no rounding or truncation)

#### Scenario: Non-Point geometry is rejected

- GIVEN the PostalAddress schema constrains `location` to a GeoJSON Point via the
  `geo` type
- WHEN an attempt is made to create an object with
  `location: {"type": "Polygon", "coordinates": [[...]]}`
- THEN OR SHALL return HTTP 422 with a validation error indicating only Point geometry
  is accepted for this property

### Requirement: Uniqueness on `bagAddressId`

The OR `addresses` register MUST enforce uniqueness on `bagAddressId` across all objects
when that field is present and non-null. Attempting to create a second object with the
same non-null `bagAddressId` MUST be rejected with HTTP 409. The constraint MUST NOT
apply to null or absent `bagAddressId` values — multiple objects without a `bagAddressId`
SHALL be allowed.

#### Scenario: Duplicate bagAddressId is rejected on create

- GIVEN an OR address object exists with `bagAddressId: "0363200000218908"`
- WHEN a request is made to create a second object with the same `bagAddressId`
- THEN OR SHALL return HTTP 409 (Conflict) with an error indicating a uniqueness
  violation on `bagAddressId`

#### Scenario: Multiple objects without bagAddressId are allowed

- GIVEN the uniqueness constraint applies only to non-null `bagAddressId` values
- WHEN two objects are created without a `bagAddressId` property
  (e.g. woonplaats-level or `source: "manual"` records)
- THEN both objects SHALL be created successfully
- AND they SHALL be assigned distinct OR UUIDs

### Requirement: RBAC and Audit Trail via OR Standard Model

Read and write access to the `addresses` register MUST be governed by OR's existing
role/scope model per ADR-022. No addresses-specific authorization layer is introduced by
this change. Every create, update, and delete operation on an address object SHALL
generate an immutable audit event via OR's standard audit trail — this is the default
for all OR registers and requires no additional implementation.

#### Scenario: Unauthenticated request is rejected

- GIVEN the `addresses` register has default OR access settings
- WHEN an unauthenticated request attempts `GET /api/addresses`
- THEN OR SHALL return HTTP 401

#### Scenario: User with write scope can create an address

- GIVEN a user has OR write scope for the `addresses` register
- WHEN the user sends a valid `POST /api/addresses` with a PostalAddress object
- THEN OR SHALL create the object, assign a UUID, and return HTTP 201
- AND an audit event SHALL be recorded for the creation

#### Scenario: User without write scope is denied on create

- GIVEN a user has OR read scope but not write scope for the `addresses` register
- WHEN the user attempts `POST /api/addresses` with a valid PostalAddress object
- THEN OR SHALL return HTTP 403

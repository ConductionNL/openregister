# Tasks: add-addresses-register

> This change implements the `[openregister]` subset of the Hydra-level umbrella
> `shared-pdok-via-openconnector`. The full architecture, design rationale,
> normalized response schema, and migration story live in the umbrella.
> See `hydra/openspec/changes/shared-pdok-via-openconnector/design.md`.

## Tasks

### OR-1. PostalAddress schema JSON (S)

- [ ] OR-1.1 Create `data/schemas/postal-address.json` implementing the PostalAddress
  schema: properties `@id`, `streetAddress`, `houseNumber`, `houseNumberAddition`,
  `postalCode` (with pattern `^[0-9]{4}\\s?[A-Z]{2}$`), `addressLocality`,
  `addressRegion`, `addressCountry` (default `"NL"`), `location` (type `"geo"`),
  `bagAddressId`, `bagBuildingId`, `pdokId`, `displayName`, `source`
  (enum: `["pdok", "manual", "import"]`), `fetchedAt` (format `date-time`);
  required: `["location", "source", "displayName"]`.
  - **Acceptance:** Schema JSON validates against JSON Schema draft 2020-12; all
    required fields listed; `location` has `"type": "geo"` matching geo-metadata-kaart
    convention; `postalCode` has the specified pattern.

- [ ] OR-1.2 Create `data/registers/addresses-register.json` defining the `addresses`
  register with slug `addresses`, linked to the PostalAddress schema, with a uniqueness
  constraint on `bagAddressId` (non-null values only).
  - **Acceptance:** Register definition JSON includes slug `addresses`, schema reference
    to `postal-address`, and a uniqueness rule for `bagAddressId`.

### OR-2. Install fixture and seed data (S)

- [ ] OR-2.1 Create `tests/fixtures/addresses/` with three test fixture JSON files for
  PHPUnit and E2E use:
  - `fixture-lauriergracht.json` — Conduction HQ, source `pdok`, `bagAddressId` set,
    all required fields present.
  - `fixture-stadhuisplein-tilburg.json` — Tilburg Stadhuis, source `pdok`,
    `bagAddressId` set, all required fields present.
  - `fixture-woonplaats-tilburg.json` — woonplaats result, source `pdok`, no
    `postalCode` or `houseNumber` (normalization and schema-permissiveness test); the
    file MUST include a comment/note indicating it is a valid OR object (required fields
    satisfied) used to exercise the optional-field path.
  - **Acceptance:** Three fixture files present; woonplaats fixture is a valid OR object
    satisfying `location`, `source`, `displayName`; two address fixtures satisfy all
    required fields and both have `bagAddressId` set.

- [ ] OR-2.2 Add an OR install step (migration or seed-data loader following existing OR
  convention) that registers the `addresses` register and PostalAddress schema on first
  install or upgrade; the register starts empty.
  - **Acceptance:** After `occ upgrade`, the `addresses` register appears in OR's
    register list with the PostalAddress schema attached and zero objects pre-loaded.

### OR-3. Uniqueness constraint enforcement (M)

- [ ] OR-3.1 Implement uniqueness enforcement for `bagAddressId` in OR's object
  create/update path for the `addresses` register. The constraint MUST only apply to
  non-null values. Duplicate `bagAddressId` on create MUST return HTTP 409.
  - **Acceptance:** PHPUnit test confirms: creating two objects with the same non-null
    `bagAddressId` returns 409 on the second; creating two objects without `bagAddressId`
    both succeed with distinct UUIDs.

### OR-4. Schema-level tests (S)

- [ ] OR-4.1 Write PHPUnit unit tests for PostalAddress schema validation covering:
  valid full address object passes; missing `location` returns 422; invalid `postalCode`
  pattern returns 422; invalid `source` enum returns 422; non-Point `location` type
  returns 422.
  - **Acceptance:** All five test cases pass under `composer check:strict` with zero
    PHPCS/PHPStan errors in new files.

- [ ] OR-4.2 Write a PHPUnit integration test (or Newman) that exercises `geo.near`,
  `geo.bbox`, and POST `geo-search` against the `addresses` register loaded with the
  two valid address fixtures. These queries use `GeoFilterParser` + `GeoFilterApplier`
  shipped by `geo-metadata-kaart` without modification — the test verifies integration
  only, not re-implementation.
  - **Acceptance:** Integration test confirms each of the three geo query patterns
    returns the expected fixture addresses.

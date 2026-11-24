# Tasks: modelling-type-catalogue-metadata

## 1. Data

- [x] 1.1 Migration adding nullable `classification` (string) and `catalogue` (json) to `openregister_schemas`; `addType` and getters and setters on `lib/Db/Schema.php`; both fields in `jsonSerialize()`. Verify: `SchemaCatalogueFieldsTest` round-trips both through the mapper on PostgreSQL and MariaDB.
- [x] 1.2 Save-path validation in `SchemasController` create and update: closed vocabularies for `classification` and `updateFrequency`, e-mail check on `contact.email`, 400 naming the field. Verify: unit test per refusal, and `PUT /api/schemas/{id}` with `classification: "secret"` answers 400 naming `classification`.

## 2. Reading

- [x] 2.1 `GET /api/schemas?classification=<value>` filter, value checked against the vocabulary before it reaches `SchemaMapper::findAll()`. Verify: API test lists only `internal` schemas for `classification=internal`.
- [x] 2.2 Omit `catalogue.contact` for an anonymous caller of the schema list and detail. Verify: anonymous `GET /api/schemas/{id}` on a public schema carries `classification` and no `contact`.

## 3. Output and exchange

- [x] 3.1 `OasService` writes `x-openregister-classification` and `x-openregister-catalogue` on each schema component; JSON-LD maps maintainer to `dcat:contactPoint` and frequency to `dct:accrualPeriodicity`. Verify: `OasServiceTest` asserts both extensions.
- [x] 3.2 Configuration export and import carry both fields. Verify: export then import of a register keeps `classification` and `catalogue` byte for byte.

## 4. Interface

- [ ] 4.1 nextcloud-vue: a Catalogue tab in `CnSchemaFormDialog` with the classification select, the frequency select and the text fields; Open Register passes the vocabulary. Verify: component test in nextcloud-vue.
- [ ] 4.2 Open Register's schemas index shows the classification as a column and filter. Verify: `tests/e2e/schema-catalogue-metadata.spec.ts` sets a classification in the dialog and filters the list on it.

## 5. Docs

- [x] 5.1 `docs/` page on describing a record type for a catalogue, with the two vocabularies.

Acceptance:
- The classification never changes who may read a schema or its objects.
- A schema with neither field set renders and exports exactly as before.

## Status, 2026-11-13

**Built: the data layer, the save validation, the list filter, the anonymous
view, and the output and exchange (1.1, 1.2, 2.1, 2.2, 3.1, 3.2, 5.1).**

- `classification` is a nullable, indexed column; `catalogue` a nullable json
  column (migration `Version1Date20261113140000`). Both are on `jsonSerialize()`
  only when set, so a schema without them serialises exactly as before. `<version>`
  moves to 2.2.0-dev.2 so the migration runs.
- `SchemaCatalogue` holds the two closed vocabularies and the save-path check; it
  runs in `SchemasController`'s shared `validateSchemaBody()`, so create and update
  both refuse an unknown classification or update frequency or a malformed contact
  e-mail with a 400 that names the field. `EmailFormat` is the one e-mail check
  (`or-form-and-journey-registry`'s `EmailFormat` had not landed, so it is added
  here in `lib/Formats/` as design D-2 says).
- `GET /api/schemas?classification=` (and `?filters[classification]=`) filters,
  the value checked against the vocabulary before it reaches the mapper; an unknown
  value is a 400. The filter is only a WHERE clause on the same RBAC- and
  tenant-scoped list.
- An anonymous caller of the list and the detail does not see `catalogue.contact`.
  The OpenAPI document and the JSON-LD context are published to anonymous readers,
  so they omit the contact too.
- `OasService` writes `x-openregister-classification` and `x-openregister-catalogue`
  on each schema component; the JSON-LD context maps `maintainer` to
  `dcat:contactPoint` and `updateFrequency` to `dct:accrualPeriodicity`, never over
  a property of that name. Export already carries both through `jsonSerialize()`;
  import hydrates them and `ImportedSchemaComparison` compares them when the file
  carries them.

**The classification is descriptive only.** Nothing reads it as an access
decision; RBAC stays the only gate (openregister ADR-006), and a test asserts a
reader keeps their access.

**Not built, and why:**

- **4.1**, the Catalogue tab in `CnSchemaFormDialog`, is a nextcloud-vue change
  (design D-4). **4.2**, the e2e, needs a live instance and the tab.
- The schemas index column and filter (4.2) ride on the same e2e.


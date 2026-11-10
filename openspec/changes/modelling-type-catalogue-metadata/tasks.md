# Tasks: modelling-type-catalogue-metadata

## 1. Data

- [ ] 1.1 Migration adding nullable `classification` (string) and `catalogue` (json) to `openregister_schemas`; `addType` and getters and setters on `lib/Db/Schema.php`; both fields in `jsonSerialize()`. Verify: `SchemaCatalogueFieldsTest` round-trips both through the mapper on PostgreSQL and MariaDB.
- [ ] 1.2 Save-path validation in `SchemasController` create and update: closed vocabularies for `classification` and `updateFrequency`, e-mail check on `contact.email`, 400 naming the field. Verify: unit test per refusal, and `PUT /api/schemas/{id}` with `classification: "secret"` answers 400 naming `classification`.

## 2. Reading

- [ ] 2.1 `GET /api/schemas?classification=<value>` filter, value checked against the vocabulary before it reaches `SchemaMapper::findAll()`. Verify: API test lists only `internal` schemas for `classification=internal`.
- [ ] 2.2 Omit `catalogue.contact` for an anonymous caller of the schema list and detail. Verify: anonymous `GET /api/schemas/{id}` on a public schema carries `classification` and no `contact`.

## 3. Output and exchange

- [ ] 3.1 `OasService` writes `x-openregister-classification` and `x-openregister-catalogue` on each schema component; JSON-LD maps maintainer to `dcat:contactPoint` and frequency to `dct:accrualPeriodicity`. Verify: `OasServiceTest` asserts both extensions.
- [ ] 3.2 Configuration export and import carry both fields. Verify: export then import of a register keeps `classification` and `catalogue` byte for byte.

## 4. Interface

- [ ] 4.1 nextcloud-vue: a Catalogue tab in `CnSchemaFormDialog` with the classification select, the frequency select and the text fields; Open Register passes the vocabulary. Verify: component test in nextcloud-vue.
- [ ] 4.2 Open Register's schemas index shows the classification as a column and filter. Verify: `tests/e2e/schema-catalogue-metadata.spec.ts` sets a classification in the dialog and filters the list on it.

## 5. Docs

- [ ] 5.1 `docs/` page on describing a record type for a catalogue, with the two vocabularies.

Acceptance:
- The classification never changes who may read a schema or its objects.
- A schema with neither field set renders and exports exactly as before.

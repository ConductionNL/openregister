# Design: modelling-type-catalogue-metadata

Read at openregister development 0ca409ee04.

## D-1: a column for the classification, a JSON block for the rest

The classification is filtered on, so it is a column: `openregister_schemas.classification`,
a nullable string. The catalogue fields are read, not filtered, so they sit in one
nullable JSON column `catalogue`, typed with `addType(fieldName: 'catalogue', type: 'json')`
beside the existing json fields in `lib/Db/Schema.php:496-527`.

Putting the block inside `configuration` was rejected. `Schema::validateConfigurationArray()`
(`lib/Db/Schema.php:2667`) keeps an allowlist (`$passThrough`, :2684) and drops
any key it does not know without a word. A catalogue block there would need the
allowlist edited and would still share its per-key isolation with keys that mean
something to the runtime. A column keeps catalogue data out of that path.

## D-2: the vocabulary is closed and validated on save

`classification` accepts `open`, `internal`, `confidential`, `strictly-confidential`
or null, the four values of the Objects API (`objects-api:src/objects/core/constants.py:11-15`),
spelled in English kebab case. `updateFrequency` accepts a closed list. An unknown
value is refused with a 400 that names the field, from the same save path that
already validates schema input in `SchemasController::update()`.

`contact.email` is validated as an e-mail address. `lib/Formats/` has no e-mail
format today (it holds `BsnFormat`, `CronFormat`, `Iso8601DateTimeFormat`,
`SemVerFormat`, `UserFormat`, `UuidFormat`). The open change
`or-form-and-journey-registry` adds `EmailFormat` there; reuse it if it has landed,
otherwise add it here in the same place so there is one e-mail check.

## D-3: the list filter

`GET /api/schemas?classification=internal` reaches `SchemaMapper::findAll()` through
`$params['filters']` (`lib/Controller/SchemasController.php:267-273`). The mapper
turns every filter key into an `eq` on a column of that name
(`lib/Db/SchemaMapper.php:1054-1066`), with no allowlist. This change does not
widen that: the controller passes `classification` through only when its value is
one of the four vocabulary values, so an unknown value is a 400 before it reaches
the query. The filter honours the same RBAC and multitenancy as the
list does today, because it is only a WHERE clause on the same query.

## D-4: the edit dialog

`src/modals/schema/EditSchema.vue` renders nextcloud-vue's `CnSchemaFormDialog`,
whose tabs are Properties, Configuration and Security (nextcloud-vue development,
`src/components/CnSchemaFormDialog/CnSchemaFormDialog.vue` `dialogTabs()`). The
Catalogue tab is added there, reading and writing `classification` and `catalogue`
on the item it already edits. Open Register's part is the API and the vocabulary;
the tab is one nextcloud-vue change, listed in tasks.md.

## D-5: export, import and output

A register export (`lib/Service/Configuration/ExportHandler.php`) writes schemas as
JSON, so both fields travel once they are in `jsonSerialize()`. The OpenAPI output
(`lib/Service/OasService.php`) gains `x-openregister-classification` and
`x-openregister-catalogue` on each schema component. The JSON-LD context maps
`catalogue.maintainer` to `dcat:contactPoint` and `updateFrequency` to
`dct:accrualPeriodicity`.

## Declarative-vs-imperative decision

No lifecycle, aggregation, calculation, notification, relation or widget is
involved. This is descriptive metadata on the schema entity.

## Seed data

No register JSON changes in Open Register itself. An app that ships a descriptor
may add, for example, on a gemeente's `meldingen` schema:
`"classification": "internal", "catalogue": {"maintainer": {"organisation": "Gemeente Voorbeeld", "department": "Openbare ruimte"}, "contact": {"name": "Team data", "email": "data@voorbeeld.nl"}, "sourceSystem": "Meldingen app", "updateFrequency": "daily"}`.

## Risks

- Reading the classification as an access decision. The spec says it is not, and
  RBAC stays the only gate (openregister ADR-006).
- A contact e-mail is personal data on a public schema listing. Anonymous readers
  of `GET /api/schemas` see only schemas RBAC already shows them; the contact block
  is omitted for an anonymous caller.

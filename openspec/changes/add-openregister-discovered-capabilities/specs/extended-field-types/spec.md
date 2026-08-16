# Spec: extended-field-types

**Status:** proposed
**Scope:** openregister
**Tier:** or-core-extensions
**Depends on:** schema-driven-read-coercion (consumed for read coercion), graphql-api (consumed for scalar mapping)

## Motivation (context for the new spec)

OR's existing JSON-Schema property type vocabulary covers the base
JSON types (`string`, `boolean`, `number`, `integer`, `array`,
`object`) plus the `Nc*` reference envelopes for cross-app Nextcloud
entity linking (`NcMail`, `NcContact`, `NcCalendar`, etc. — declared
in `linked-entity-types`). Specter's intelligence pipeline surfaced
six high-demand property types not covered by either set:

| Type | Specter demand | Used for |
|---|---|---|
| `calendar-range` | 47 mentions | Start/end datetime pairs for events, bookings, validity windows |
| `recurrence` | 47 mentions | Recurring events, schedules, deadlines (RFC 5545 RRULE) |
| `uuid` (typed scalar) | 43 mentions | Strongly-typed identifier fields (vs `string` + `format: uuid`) |
| `gallery-cover-url` | 31 mentions | Cover-image URLs with rendering metadata |
| `image-url` | 27 mentions | Image references with format / dimensions metadata |
| `color` | 27 mentions | Hex / RGBA / OKLCH colour values |

Per `schema-driven-read-coercion`, `SchemaTypeConverter` is the
single point where database row values are coerced to PHP-typed
property values. Per ADR-031, each new type lands as a declarative
schema annotation + a single converter dispatch entry; no per-type
PHP service class. Per `graphql-api`, each new type also registers
as a GraphQL scalar so REST + GraphQL serve identical shapes.

This spec declares the six new types and the shape contract each
type must satisfy.

## ADDED Requirements

### Requirement: REQ-EFT-001 — SchemaTypeConverter SHALL dispatch on extended type names alongside the base JSON types

The system MUST register the six new property types as additional dispatch entries on the single converter. `SchemaTypeConverter::convertValue(mixed $value, string $schemaType): mixed`
(the single read-coercion entry point per `schema-driven-read-coercion`)
MUST recognise the six new type names declared by this spec
(`calendar-range`, `recurrence`, `uuid`, `color`, `gallery-cover-url`,
`image-url`, `geo-point`) in addition to the existing base JSON
types. Each new type MUST register as one dispatch-table entry; the
per-type read coercion + validation logic MUST live inline in that
entry, not in a separate service class (per ADR-031). The
`PropertyValidatorHandler::$validTypes` static map MUST be extended
to include the seven new type names.

#### Scenario: Schema declaring an extended type validates and persists

- **GIVEN** a schema with property `eventColor: { "type": "color" }`
- **WHEN** an object is saved with `eventColor: "#a4b8ff"`
- **THEN** validation MUST pass via the `color` type-handler entry in
  `PropertyValidatorHandler::$validTypes`
- **AND** the value MUST persist as the literal string `"#a4b8ff"` in
  the magic-table JSON column
- **AND** on read, `SchemaTypeConverter::convertValue("#a4b8ff", "color")`
  MUST return the string `"#a4b8ff"` (no transformation needed; the
  type is a typed string)

#### Scenario: Unknown type in schema produces a validation error at import time

- **GIVEN** a schema with property `foo: { "type": "unicorn" }` (not
  a registered type)
- **WHEN** the schema is imported via `ConfigurationService::importFromApp()`
- **THEN** the import MUST fail with a structured error naming the
  unknown type and the property
- **AND** no DDL change MUST be applied to the magic table

### Requirement: REQ-EFT-002 — The `calendar-range` type SHALL store a start/end datetime envelope

The `calendar-range` type MUST persist a start/end datetime envelope as a JSON object. A `calendar-range` value is an object with the shape
`{ "start": "<ISO 8601 datetime>", "end": "<ISO 8601 datetime>" }`.
`start` and `end` MUST both be present; `end` MUST be ≥ `start`. The
storage shape in the magic-table JSON column is the literal object.
The optional `format: <date|datetime>` annotation on the schema
property MUST control whether the values carry a time component
(`date`: `YYYY-MM-DD` only; `datetime` (default): full ISO 8601).
Indexing semantics: a B-tree index on `(start, end)` MUST be
queryable via the existing query parameter API
(e.g. `?validityRange.start.gte=2026-01-01&validityRange.end.lte=2026-12-31`).
OAS rendering MUST use a named component `CalendarRange` defined
once and referenced from every property of this type. GraphQL
mapping: a custom scalar `CalendarRange` per REQ-EFT-007.

#### Scenario: Valid calendar-range saves and round-trips

- **GIVEN** schema `Booking` has `period: { "type": "calendar-range" }`
- **WHEN** a `Booking` is saved with
  `period: { "start": "2026-05-01T09:00:00Z", "end": "2026-05-01T17:00:00Z" }`
- **THEN** the save MUST succeed
- **AND** a `GET` MUST return the same envelope unchanged

#### Scenario: end before start is rejected

- **GIVEN** same schema
- **WHEN** a `Booking` is saved with
  `period: { "start": "2026-05-01T17:00:00Z", "end": "2026-05-01T09:00:00Z" }`
- **THEN** the save MUST fail with HTTP 422
- **AND** the error message MUST be
  `Property 'period': calendar-range 'end' (2026-05-01T09:00:00Z) must be >= 'start' (2026-05-01T17:00:00Z)`

#### Scenario: format: date drops the time component

- **GIVEN** schema with `period: { "type": "calendar-range", "format": "date" }`
- **WHEN** a `Booking` is saved with
  `period: { "start": "2026-05-01", "end": "2026-05-03" }`
- **THEN** the save MUST succeed
- **AND** a save with `period: { "start": "2026-05-01T09:00:00Z", … }`
  MUST be rejected with the message
  `Property 'period' declared format 'date'; 'start' must match YYYY-MM-DD`

#### Scenario: calendar-range query operators work

- **GIVEN** 100 `Booking` rows with varying `period`
- **WHEN** `GET /api/objects/{register}/booking?period.start.gte=2026-05-01&period.end.lte=2026-05-31`
  is called
- **THEN** only rows whose `period.start >= 2026-05-01` AND
  `period.end <= 2026-05-31` MUST be returned
- **AND** the query MUST use the `(start, end)` index (no table scan
  on >10K rows)

### Requirement: REQ-EFT-003 — The `recurrence` type SHALL store an RFC 5545 RRULE string and emit upcoming occurrences on read

The `recurrence` type MUST persist as an RFC 5545 RRULE string. A `recurrence` value is a string conforming to RFC 5545 RRULE syntax
(`FREQ=...;INTERVAL=...;BYDAY=...;UNTIL=...`). Storage is the literal
string. Validation MUST be performed via `sabre/vobject`'s RRULE
parser (already in the Nextcloud stack — no new dependency, per
proposal Open Question 2). On read, the converter MAY enrich the
value with an `_occurrences` virtual field listing the next N
occurrences (N configurable per request via `?recurrenceOccurrences=N`,
default 5, max 100). The base value (the RRULE string) MUST always
be preserved unchanged for round-trip integrity.

#### Scenario: Valid RRULE saves and parses

- **GIVEN** schema `Meeting` has `pattern: { "type": "recurrence" }`
- **WHEN** a `Meeting` is saved with
  `pattern: "FREQ=WEEKLY;BYDAY=MO,WE;UNTIL=20261231T235959Z"`
- **THEN** the save MUST succeed (RRULE parses cleanly)
- **AND** the stored value MUST be the literal string above

#### Scenario: Invalid RRULE is rejected

- **GIVEN** same schema
- **WHEN** a `Meeting` is saved with `pattern: "BANANA=DAILY"`
- **THEN** the save MUST fail with HTTP 422
- **AND** the error message MUST be exactly
  `Property 'pattern': invalid RRULE 'BANANA=DAILY' (sabre/vobject parse error)`

#### Scenario: Read enriches with upcoming occurrences

- **GIVEN** a `Meeting` with
  `pattern: "FREQ=WEEKLY;BYDAY=MO;COUNT=10"`
- **WHEN** `GET /api/objects/{register}/meeting/<uuid>?recurrenceOccurrences=3`
  is called
- **THEN** the response MUST include `pattern: "FREQ=WEEKLY;BYDAY=MO;COUNT=10"`
  (unchanged)
- **AND** the response MUST include
  `_occurrences: ["<first-monday-iso>", "<second-monday-iso>", "<third-monday-iso>"]`

### Requirement: REQ-EFT-004 — The `uuid` type SHALL be a typed scalar validating the UUID v4 format

The `uuid` type MUST validate as UUID v4. A `uuid` value is a string matching the UUID v4 regex
`^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$`
(case-insensitive). Storage is the literal string. The type
differs from `{ "type": "string", "format": "uuid" }` in two ways:
(1) the GraphQL scalar mapping is `UUID` (typed) rather than
`String` (per the existing `graphql-api` `UuidType` scalar — REQ-EFT-007
extends usage); (2) the OAS rendering produces a named `UUID`
component instead of inlined `format: uuid`.

#### Scenario: Valid UUID saves

- **GIVEN** schema with `parentId: { "type": "uuid" }`
- **WHEN** an object is saved with
  `parentId: "f47ac10b-58cc-4372-a567-0e02b2c3d479"`
- **THEN** the save MUST succeed

#### Scenario: Non-v4 UUID is rejected

- **GIVEN** same schema
- **WHEN** an object is saved with `parentId: "not-a-uuid"`
- **THEN** the save MUST fail with HTTP 422
- **AND** the error message MUST be
  `Property 'parentId': 'not-a-uuid' is not a valid UUID v4`

#### Scenario: OAS exposes UUID as a named component

- **GIVEN** the schema is registered
- **WHEN** the OAS document is rendered
- **THEN** the `parentId` field MUST reference
  `#/components/schemas/UUID` (not inline)
- **AND** `#/components/schemas/UUID` MUST be defined as
  `{ "type": "string", "format": "uuid", "pattern": "<v4 regex>" }`

### Requirement: REQ-EFT-005 — The `color` type SHALL accept hex / rgba / oklch notations and validate per declared format

The `color` type MUST accept one of three notations per the property's declared `format`. A `color` value is a string carrying one of three notations,
controlled by an optional `format` annotation on the property:
`hex` (default — `#RRGGBB` or `#RRGGBBAA`), `rgba`
(`rgba(R, G, B, A)`), `oklch` (`oklch(L% C H)`). Storage is the
literal string. Validation MUST be the appropriate regex per
declared format. On read, the value is returned unchanged (no
normalisation). GraphQL scalar: `Color`.

#### Scenario: Hex (default) accepts both 6 and 8 digit forms

- **GIVEN** schema with `accent: { "type": "color" }` (default hex)
- **WHEN** an object is saved with `accent: "#a4b8ff"`
- **THEN** the save MUST succeed
- **WHEN** another save uses `accent: "#a4b8ffcc"` (8-digit with alpha)
- **THEN** the save MUST also succeed

#### Scenario: rgba format requires 4 components

- **GIVEN** schema with `accent: { "type": "color", "format": "rgba" }`
- **WHEN** an object is saved with `accent: "rgba(164, 184, 255, 0.8)"`
- **THEN** the save MUST succeed
- **WHEN** another save uses `accent: "rgba(164, 184, 255)"` (only 3)
- **THEN** the save MUST fail with HTTP 422 and message
  `Property 'accent' format 'rgba' requires 4 components; got 3`

#### Scenario: Wrong format for declared notation is rejected

- **GIVEN** schema with `accent: { "type": "color", "format": "hex" }`
- **WHEN** an object is saved with `accent: "rgba(0,0,0,1)"`
- **THEN** the save MUST fail with HTTP 422 and message
  `Property 'accent' declared format 'hex'; 'rgba(0,0,0,1)' does not match hex regex`

### Requirement: REQ-EFT-006 — The `gallery-cover-url` and `image-url` types SHALL store URL + optional dimensions/alt-text envelopes

The two image-URL types MUST persist as canonical envelopes. Both types accept either a bare URL string OR an object with the
shape `{ "url": "<...>", "width"?: <int>, "height"?: <int>, "altText"?: "<...>", "format"?: "<jpeg|png|webp|avif|svg>" }`.
The bare-URL form is shorthand for `{ url: "<...>" }`. Storage is
the canonical object form (the converter normalises bare-string
input on write). The two types differ semantically only — `gallery-cover-url`
implies "this image is the cover of a gallery" and consumers MAY
render it larger; `image-url` is generic. Both validate the URL via
PHP's `filter_var(..., FILTER_VALIDATE_URL)`. GraphQL scalar:
`ImageUrl` (shared by both types).

#### Scenario: Bare URL string is normalised to envelope

- **GIVEN** schema with `cover: { "type": "gallery-cover-url" }`
- **WHEN** an object is saved with `cover: "https://example.org/img.png"`
- **THEN** the stored value MUST be the canonical object
  `{ "url": "https://example.org/img.png" }`
- **AND** on read, the response MUST return the canonical object form

#### Scenario: Full envelope with dimensions and alt-text persists

- **GIVEN** same schema
- **WHEN** an object is saved with
  `cover: { "url": "https://example.org/img.png", "width": 1200, "height": 630, "altText": "Hero image", "format": "png" }`
- **THEN** the save MUST succeed
- **AND** every field MUST round-trip unchanged

#### Scenario: Invalid URL is rejected

- **GIVEN** same schema
- **WHEN** an object is saved with `cover: "not a url"`
- **THEN** the save MUST fail with HTTP 422 and message
  `Property 'cover': 'not a url' is not a valid URL`

#### Scenario: Unknown format value is rejected

- **GIVEN** same schema
- **WHEN** an object is saved with
  `cover: { "url": "https://example.org/x.bmp", "format": "bmp" }`
- **THEN** the save MUST fail with HTTP 422 and message
  `Property 'cover': format 'bmp' is not one of jpeg|png|webp|avif|svg`

### Requirement: REQ-EFT-007 — Each new type SHALL register a corresponding GraphQL scalar per the graphql-api scalar pattern

Each new property type MUST register a GraphQL scalar class. Per `graphql-api` REQ "Custom scalar types MUST map to OpenRegister
property formats", six new scalar classes MUST be added under
`lib/Service/GraphQL/Scalar/`: `CalendarRangeType`, `RecurrenceType`,
`UuidType` (already exists for `format: uuid`; reused unchanged for
the new `type: uuid`), `ColorType`, `ImageUrlType` (shared by
`gallery-cover-url` and `image-url`). Each scalar follows the
existing pattern: `parseValue` validates incoming GraphQL input,
`serialize` formats outgoing data, `parseLiteral` handles inline
GraphQL syntax. Each scalar's contract MUST be identical to the
REST validation contract per REQ-EFT-002..006 (same regex, same
error structure) so REST and GraphQL accept exactly the same inputs.

#### Scenario: GraphQL scalar accepts the same input as REST

- **GIVEN** a schema with `accent: { "type": "color" }` registered
- **WHEN** a GraphQL mutation `createX(input: { accent: "#a4b8ff" })`
  is executed
- **THEN** the mutation MUST succeed via `ColorType::parseValue`
- **AND** the value MUST persist identically to the REST equivalent

#### Scenario: GraphQL scalar rejects the same inputs as REST

- **GIVEN** same schema with declared `format: hex`
- **WHEN** a GraphQL mutation `createX(input: { accent: "rgba(0,0,0,1)" })`
  is executed
- **THEN** the mutation MUST fail with a `GraphQL\Error\Error`
  carrying the same message as the REST rejection per REQ-EFT-005

### Requirement: REQ-EFT-008 — The OAS document SHALL expose each new type as a named component

Each new type MUST appear in the OAS document as a named component. `OpenApiGenerator` MUST emit one named component per new type
(`#/components/schemas/CalendarRange`, `#/components/schemas/Recurrence`,
`#/components/schemas/UUID`, `#/components/schemas/Color`,
`#/components/schemas/ImageUrl`). Schema properties using these types
MUST reference the named component, not inline the type definition.
This minimises OAS document size and gives downstream clients
(OpenAPI generators) a stable type to reuse.

#### Scenario: OAS references the named CalendarRange component

- **GIVEN** schema `Booking` has `period: { "type": "calendar-range" }`
- **WHEN** the OAS document is rendered
- **THEN** `Booking.period` MUST be `{ "$ref": "#/components/schemas/CalendarRange" }`
- **AND** `#/components/schemas/CalendarRange` MUST be defined once at
  the document root with the shape
  `{ "type": "object", "required": ["start", "end"], "properties": { "start": { "type": "string", "format": "date-time" }, "end": { "type": "string", "format": "date-time" } } }`

#### Scenario: Multiple properties of the same type reuse one component

- **GIVEN** schemas `Booking.period` and `Reservation.window` both
  declared as `calendar-range`
- **WHEN** the OAS document is rendered
- **THEN** both fields MUST reference the same
  `#/components/schemas/CalendarRange` component (defined once, used
  twice)

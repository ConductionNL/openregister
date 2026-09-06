# Configuration import

## ADDED Requirements

### Requirement: The import endpoint answers in JSON (REQ-CIM-001)

`POST /api/configurations/import` SHALL answer with a JSON body for every
outcome, including a failure caused by a defect in the endpoint itself.

It SHALL catch `Throwable` rather than `Exception`. A `TypeError` raised inside
the handler is a bug rather than bad input, and letting it escape produces
Nextcloud's HTML error page with an HTTP 500 — from which a caller cannot tell a
malformed descriptor from a crash.

#### Scenario: A defect in the handler answers in JSON

- **WHEN** the import handler raises an `Error`
- **THEN** the response is JSON carrying the message, not an HTML error page.

### Requirement: The import result's ids are read from either shape (REQ-CIM-002)

Linking the imported registers, schemas and objects to the configuration SHALL
accept a list holding entities, bare ids, or a mix.

`ImportHandler` appends an `ObjectEntity` at two sites and a bare id at two
others, so a descriptor carrying seed objects yields a mixed list. Mapping it
with `$obj->getId()` reaches that call on an int.

Anything that is neither an entity with `getId()` nor a scalar id SHALL be
dropped, so the stored id list never holds a hole.

#### Scenario: A descriptor carrying seed objects imports

- **GIVEN** a register descriptor whose import returns object ids rather than
  object entities
- **WHEN** it is imported
- **THEN** the import succeeds and the configuration lists the imported ids.

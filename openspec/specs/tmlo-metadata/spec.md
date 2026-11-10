---
status: done
retrofit: true
---

# tmlo-metadata Specification

## Purpose

@e2e exclude backend TMLO metadata foundation — covered by PHPUnit

Foundation capability for TMLO (Toepassingsprofiel Metadatastandaard Lokale Overheden) archival metadata on OpenRegister objects. Owns the cross-cutting contract that the sibling specs (`tmlo-metadata-schema`, `tmlo-auto-populate`, `tmlo-export`, `tmlo-query-api`) compose against:

- The canonical `TmloService` surface and its constants/enums (archiefnominatie, archiefstatus, MDTO namespace, allowed status transitions).
- The register-level enablement gate (`isTmloEnabled`) consulted by every TMLO operation.
- Field-value validation for the six core TMLO sub-fields.
- The state-machine governing `archiefstatus` transitions plus the per-target-status required-field rules.
- The shared TMLO controller error envelope (422 for invalid input, 500 for unexpected failures, 400 when the register has TMLO disabled).

Retrofit note: spec authored 2026-05-24 from the implementation in `lib/Service/TmloService.php` + `lib/Controller/TmloController.php`. Sibling specs already covered fields/migration, auto-population, export, and query/summary; this spec captures the foundation behaviour they all assume.

## Requirements

### Requirement: TmloService exposes canonical TMLO constants

The system SHALL expose canonical TMLO constants on `OCA\OpenRegister\Service\TmloService` so that all TMLO consumers reference a single source of truth. The class SHALL declare:

- `ARCHIEFNOMINATIE_BLIJVEND_BEWAREN = 'blijvend_bewaren'` and `ARCHIEFNOMINATIE_VERNIETIGEN = 'vernietigen'` plus the aggregate `VALID_ARCHIEFNOMINATIE` array.
- `ARCHIEFSTATUS_ACTIEF = 'actief'`, `ARCHIEFSTATUS_SEMI_STATISCH = 'semi_statisch'`, `ARCHIEFSTATUS_OVERGEBRACHT = 'overgebracht'`, `ARCHIEFSTATUS_VERNIETIGD = 'vernietigd'` plus the aggregate `VALID_ARCHIEFSTATUS` array.
- `MDTO_NAMESPACE = 'https://www.nationaalarchief.nl/mdto'` for MDTO XML export elements.
- `TMLO_FIELDS` — the ordered list of the six core TMLO sub-field names (`classificatie`, `archiefnominatie`, `archiefactiedatum`, `archiefstatus`, `bewaarTermijn`, `vernietigingsCategorie`).
- `VALID_TRANSITIONS` — the `from => [allowed targets]` map of archival status transitions.

#### Scenario: Constants referenced by TmloController for status summary

- **WHEN** `TmloController::summary()` builds its zeroed count array
- **THEN** it SHALL key the array with `TmloService::ARCHIEFSTATUS_ACTIEF`, `ARCHIEFSTATUS_SEMI_STATISCH`, `ARCHIEFSTATUS_OVERGEBRACHT`, `ARCHIEFSTATUS_VERNIETIGD`

#### Scenario: MDTO export uses the namespace constant

- **WHEN** MDTO XML elements are created
- **THEN** every element SHALL be created in the `TmloService::MDTO_NAMESPACE` XML namespace

### Requirement: Register-level TMLO enablement gate

The system SHALL gate every TMLO operation behind a register-level enablement check. `TmloService::isTmloEnabled(Register $register): bool` SHALL return `true` if and only if the register's `configuration['tmloEnabled']` is strictly equal to boolean `true`. Any other value (missing key, `null`, `false`, `'true'` as a string, `1` as an int) SHALL return `false`.

#### Scenario: Register with tmloEnabled=true returns true

- **GIVEN** a register whose `configuration` contains `['tmloEnabled' => true]`
- **WHEN** `TmloService::isTmloEnabled($register)` is called
- **THEN** the method SHALL return `true`

#### Scenario: Register without tmloEnabled key returns false

- **GIVEN** a register whose `configuration` does not contain a `tmloEnabled` key
- **WHEN** `TmloService::isTmloEnabled($register)` is called
- **THEN** the method SHALL return `false`

#### Scenario: populateDefaults short-circuits when TMLO disabled

- **GIVEN** an object whose register has TMLO disabled
- **WHEN** `TmloService::populateDefaults()` is called
- **THEN** the object SHALL be returned unchanged (no `tmlo` field mutation)

### Requirement: TMLO field-value validation

The system SHALL provide `TmloService::validateFieldValues(array $tmlo): array` that validates the four constrained TMLO sub-fields and SHALL return an array of human-readable error strings (empty when valid). The method SHALL:

- Reject any `archiefnominatie` value not in `VALID_ARCHIEFNOMINATIE` (only non-null values are checked; null/missing is permitted).
- Reject any `archiefstatus` value not in `VALID_ARCHIEFSTATUS` (only non-null values are checked).
- Reject any `bewaarTermijn` value that is not a valid ISO-8601 duration parseable by `DateInterval` (e.g. `P7Y`, `P5Y6M`).
- Reject any `archiefactiedatum` value that is not a strict `Y-m-d` ISO-8601 calendar date (`DateTime::createFromFormat('Y-m-d', …)` must round-trip).

Each error message SHALL include the offending value and (for enum-typed fields) the allowed values, to aid API consumers.

#### Scenario: All-null TMLO array is valid

- **WHEN** `validateFieldValues([])` is called
- **THEN** the method SHALL return an empty array

#### Scenario: Invalid archiefnominatie produces a descriptive error

- **WHEN** `validateFieldValues(['archiefnominatie' => 'foo'])` is called
- **THEN** the returned array SHALL contain one error mentioning `blijvend_bewaren, vernietigen` and the offending value `foo`

#### Scenario: Malformed bewaarTermijn produces an error

- **WHEN** `validateFieldValues(['bewaarTermijn' => 'not-a-duration'])` is called
- **THEN** the returned array SHALL contain one error referencing valid ISO-8601 duration examples and the offending value

#### Scenario: Non-strict calendar date is rejected

- **WHEN** `validateFieldValues(['archiefactiedatum' => '2025-13-99'])` is called
- **THEN** the returned array SHALL contain one error indicating the value is not a valid `YYYY-MM-DD` date

### Requirement: Archival status state-machine

The system SHALL enforce a strict state-machine for `archiefstatus` transitions via `TmloService::validateStatusTransition(array $tmlo, string $oldStatus): array`. The transitions SHALL be:

- `actief` → `semi_statisch` (only).
- `semi_statisch` → `overgebracht` OR `vernietigd`.
- `overgebracht` → terminal (no further transitions allowed).
- `vernietigd` → terminal (no further transitions allowed).

Additionally, when transitioning to `overgebracht`, the object SHALL have non-empty values for `archiefactiedatum`, `classificatie`, and `archiefnominatie`, and `archiefnominatie` SHALL be `blijvend_bewaren`. When transitioning to `vernietigd`, the object SHALL have non-empty values for `archiefactiedatum`, `classificatie`, `archiefnominatie`, and `vernietigingsCategorie`, and `archiefnominatie` SHALL be `vernietigen`. A no-op transition (new status equal to old, or new status null) SHALL return an empty error array.

#### Scenario: Allowed transition with all required fields

- **WHEN** transitioning from `semi_statisch` to `overgebracht` with `archiefactiedatum`, `classificatie`, and `archiefnominatie='blijvend_bewaren'` set
- **THEN** `validateStatusTransition` SHALL return an empty array

#### Scenario: Disallowed transition is rejected

- **WHEN** transitioning from `actief` directly to `vernietigd`
- **THEN** the returned array SHALL contain an error listing the allowed targets from `actief` (`semi_statisch`)

#### Scenario: Transition to terminal state from terminal state is rejected

- **WHEN** transitioning from `overgebracht` to any other status
- **THEN** the returned array SHALL contain an error indicating `overgebracht` is a terminal state with no allowed transitions

#### Scenario: Missing required field for destruction is reported

- **WHEN** transitioning to `vernietigd` without `vernietigingsCategorie`
- **THEN** the returned array SHALL contain an error stating `vernietigingsCategorie` is required for the transition

#### Scenario: archiefnominatie mismatch for transfer is reported

- **WHEN** transitioning to `overgebracht` with `archiefnominatie='vernietigen'`
- **THEN** the returned array SHALL contain an error stating `archiefnominatie` must be `blijvend_bewaren` for the transition

### Requirement: TmloController error envelope

The system SHALL standardise the error envelope returned by `TmloController` endpoints (`exportSingle`, `exportBatch`, `summary`) so that API consumers can rely on consistent failure shapes:

- An `InvalidArgumentException` raised by `TmloService` (notably the "no TMLO metadata" guard in `generateMdtoXml`) SHALL be returned to the client as HTTP `422 Unprocessable Entity` with a JSON body `{"error": "<message>"}`.
- Any other `Exception` SHALL be logged at error level via the injected `LoggerInterface` and returned as HTTP `500 Internal Server Error` with a JSON body `{"error": "MDTO export failed: <message>" | "MDTO batch export failed: <message>" | "TMLO summary failed: <message>"}` (per endpoint).
- The `summary` endpoint SHALL additionally short-circuit with HTTP `400 Bad Request` and `{"error": "TMLO is not enabled on this register"}` whenever `TmloService::isTmloEnabled()` returns `false` for the requested register, BEFORE looking up the schema or performing any object queries.

Successful XML responses SHALL be wrapped in a `DataResponse` with HTTP `200 OK` and the `Content-Type: application/xml; charset=UTF-8` header explicitly set.

#### Scenario: Export of object without TMLO metadata returns 422

- **GIVEN** an object whose `tmlo` field is null or empty
- **WHEN** `GET /api/objects/{register}/{schema}/{id}/export/mdto` is called
- **THEN** the response SHALL be HTTP 422 with body `{"error": "Object <uuid> has no TMLO metadata. MDTO export requires TMLO metadata."}`

#### Scenario: Summary on register with TMLO disabled returns 400

- **GIVEN** a register whose `configuration.tmloEnabled` is not `true`
- **WHEN** `GET /api/objects/{register}/{schema}/tmlo/summary` is called
- **THEN** the response SHALL be HTTP 400 with body `{"error": "TMLO is not enabled on this register"}`
- **AND** no object queries SHALL be issued

#### Scenario: Unexpected failure during export returns 500 and is logged

- **GIVEN** the object store raises an unexpected `Exception` during `exportSingle`
- **WHEN** the controller catches the exception
- **THEN** the response SHALL be HTTP 500 with body `{"error": "MDTO export failed: <message>"}`
- **AND** an error-level log entry SHALL be written via the injected `LoggerInterface` including the exception under the `exception` context key

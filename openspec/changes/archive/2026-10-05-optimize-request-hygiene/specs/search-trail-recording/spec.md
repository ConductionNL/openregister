# Capability delta: `search-trail-recording`

## Purpose

Recording a search trail currently reads the recording-mode settings twice per search and INSERTs the trail row synchronously inside the read request. This delta memoizes the effective mode per request and defers the trail write until after the response, preserving the documented best-effort contract and the trail schema.

## ADDED Requirements

### Requirement: The effective recording mode MUST be resolved from settings at most once per request

The system SHALL memoize the resolved effective recording mode for the lifetime of the request-scoped handler. Both the recording-mode gate applied before buffering and the enabled check inside the trail-logging path SHALL consult the memoized value, so a search costs at most one settings read for trail recording — not one per check.

#### Scenario: Repeated mode checks read settings once
- **WHEN** the effective recording mode is consulted multiple times while recording a single search (mode gate plus logging gate)
- **THEN** the retention settings are read exactly once and every check observes the same resolved mode

### Requirement: Search-trail entries MUST be persisted after the response, not inside the search request

The system SHALL buffer recorded search-trail entries in-request and persist them after the response has been generated (deferred flush registered at first buffering). The persisted entries SHALL carry the same fields as before (query, result count, total results, response time, execution type) — the trail schema is unchanged. The flush SHALL be fail-soft per entry: a failed insert is logged and dropped without surfacing an error, and entries buffered when the process terminates fatally MAY be lost (the trail is best-effort by contract).

#### Scenario: The search response does not wait for the trail write
- **WHEN** a recordable search executes
- **THEN** no trail insert is issued while producing the search response
- **AND** the buffered entry is persisted by the deferred flush with the values captured at search time

#### Scenario: A failing deferred insert never surfaces
- **GIVEN** the trail storage rejects the insert during the deferred flush
- **WHEN** the flush runs
- **THEN** the failure is logged as a warning, the entry is dropped, and no error reaches any caller

#### Scenario: Disabled recording buffers nothing
- **WHEN** the effective mode is `none` and a search executes
- **THEN** no entry is buffered and the deferred flush persists nothing

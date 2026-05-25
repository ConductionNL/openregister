---
status: draft
---

# zoeken-filteren

## Purpose

Extend the zoeken-filteren capability with the search-trail analytics and audit
API. The existing spec covers search composition and filtering; it has no
requirement for the endpoint surface that lets operators inspect, aggregate,
export, and prune the search-trail log (the record of who searched for what).
Reverse-specced from `SearchTrailController`.

## ADDED Requirements

### Requirement: Search Trail Analytics and Audit API

The system MUST expose an analytics and audit API over the search-trail log so
operators can review search activity, surface aggregate insight, export the
trail, and apply retention. The API MUST provide: a paginated list of trail
entries returning the same `{results, total, page, pages, limit, offset}`
pagination envelope used by the objects list endpoint; retrieval of a single
trail entry by id returning HTTP `404` when it does not exist; aggregate search
statistics over an optional `from`/`to` window; popular search terms; a search
activity time-series; per-(register, schema) search statistics; and user-agent
statistics. The API MUST support exporting the trail (CSV or JSON), and MUST
provide retention operations: age-based cleanup, single-entry delete, and a
clear-all purge. Date-window and pagination parameters MUST be parsed through a
shared parameter-extraction helper, and system/query parameters (`_route`,
`id`) MUST be stripped before they reach the service.

#### Scenario: List search trail entries with pagination envelope
- **GIVEN** search-trail entries exist
- **WHEN** a GET request is sent to the search-trail list endpoint
- **THEN** the response MUST be the shared pagination envelope (`results`, `total`, `page`, `pages`, `limit`, `offset`)
- **AND** `_route` and `id` MUST be stripped from the parameters passed to the service

#### Scenario: Fetch a missing trail entry yields 404
- **GIVEN** no search-trail entry exists for the requested id
- **WHEN** the show endpoint is invoked with that id
- **THEN** the response MUST be HTTP `404` with `{error: "Search trail not found"}`

#### Scenario: Aggregate statistics honour the date window
- **GIVEN** a statistics request carrying optional `from` and `to` parameters
- **WHEN** the statistics endpoint is invoked
- **THEN** the service MUST receive the parsed `from`/`to` window and return aggregate search statistics

#### Scenario: Export the search trail
- **GIVEN** search-trail entries exist
- **WHEN** the export endpoint is invoked
- **THEN** the trail MUST be returned in the requested export format (CSV or JSON)

#### Scenario: Retention operations prune the trail
- **GIVEN** stale search-trail entries
- **WHEN** the cleanup, single-delete, or clear-all endpoint is invoked
- **THEN** the matching entries MUST be removed and the operation result MUST be reported

## Non-Functional Requirements

- **i18n (ADR-007)**: This is an operator-facing analytics/audit JSON API. The
  only app-authored string is the `{error: "Search trail not found"}` 404
  diagnostic, which is operator copy and exempt from translation; trail rows and
  aggregates carry recorded search terms and counts, not localisable UI copy. CSV
  and JSON exports emit raw recorded data. (ADR-007 n/a.)
- **REST/error contract (ADR-002)**: Follows OpenRegister REST conventions —
  the shared `{results, total, page, pages, limit, offset}` pagination envelope
  (identical to the objects list endpoint), `404` for a missing entry, and the
  shared parameter-extraction helper that strips system params (`_route`, `id`)
  before they reach the service.

## Acceptance Criteria

- [x] `SearchTrailController` carries `@spec zoeken-filteren#...` annotations pointing at this requirement.
- [x] The list endpoint returns the shared pagination envelope and strips `_route`/`id`; a missing entry returns `404`.
- [x] Statistics endpoints honour the optional `from`/`to` window; popular-terms, time-series, per-(register,schema), and user-agent stats are exposed.
- [x] Export (CSV/JSON) and retention (cleanup/delete/clear-all) operations behave as specified.
- [x] `openspec validate retrofit-2026-05-25-bw2-ctrl-1 --strict` passes.

---
status: proposed
---

# Realtime Updates — cursor-polling HTTP endpoints (delta)

**OpenSpec change**: `retrofit-2026-05-24-b-ctrl-graphql-rt-dash`

**Cross-references**: [realtime-updates main spec](../../../../specs/realtime-updates/spec.md), `lib/Controller/RealtimeController.php`.

## Purpose of this delta

The `realtime-updates` capability specifies SSE as the primary transport and a
client-side polling fallback against the generic REST list API. It does **not**
specify the dedicated cursor-polling HTTP endpoints implemented by
`RealtimeController` — `GET /api/realtime/events` and `GET /api/realtime/cursor`.
These provide a lightweight, RBAC- and tenant-scoped poll surface that clients
use directly (rather than diffing full REST list pages). This delta documents
their **observed** contracts. No behaviour change.

---

## ADDED Requirements

### Requirement: The cursor-polling events endpoint MUST return a scoped, bounded event envelope

The cursor-polling events endpoint MUST return change events newer than the
supplied cursor in a fixed envelope, and MUST scope every response to the
authenticated caller's active organisation, never leaking cross-tenant events.
The endpoint is `GET /api/realtime/events` (`RealtimeController::events`,
`@NoAdminRequired`, `@NoCSRFRequired`).

#### Scenario: Anonymous caller is rejected
- **GIVEN** an unauthenticated request to `GET /api/realtime/events`
- **WHEN** `RealtimeController::events` resolves `IUserSession::getUser()` as `null`
- **THEN** the endpoint MUST return HTTP 401 with body `{"error": "Unauthorized"}`
- **AND** no events MUST be queried or returned

#### Scenario: Authenticated poll returns a bounded envelope
- **GIVEN** an authenticated caller with an active organisation and `?since=42&limit=100`
- **WHEN** the endpoint queries `RealtimeEventMapper::findSince` with the org-scoped filters (`register`, `schema`, `objectUuid`, `eventType`, `organisation`)
- **THEN** the response MUST be `{events: CloudEvent[], cursor: int, hasMore: bool}`
- **AND** `cursor` MUST be the id of the last returned event, or `since` (or `0`) when no events were returned
- **AND** `hasMore` MUST be `true` only when the number of returned events equals the effective limit

#### Scenario: Limit is clamped and active-org scoping is enforced
- **GIVEN** an authenticated caller requests `?limit=99999`
- **WHEN** the endpoint computes the effective limit
- **THEN** the limit MUST be clamped to the range `1..1000`
- **AND** results MUST be filtered to the caller's active organisation UUID
- **AND** when the caller has no resolvable active organisation, the endpoint MUST return an empty envelope `{events: [], cursor: since ?? 0, hasMore: false}` rather than HTTP 500

### Requirement: The head-cursor endpoint MUST be tenant-scoped and fail closed

The head-cursor endpoint MUST return the highest event id visible to the
caller's active organisation so clients can fast-forward past historical events
on initial subscription, and MUST NOT expose a global head pointer. The endpoint
is `GET /api/realtime/cursor` (`RealtimeController::cursor`, `@NoAdminRequired`,
`@NoCSRFRequired`).

#### Scenario: Anonymous caller is rejected
- **GIVEN** an unauthenticated request to `GET /api/realtime/cursor`
- **WHEN** `RealtimeController::cursor` finds no session user
- **THEN** the endpoint MUST return HTTP 401 with body `{"error": "Unauthorized"}`

#### Scenario: Head cursor is scoped to the active organisation
- **GIVEN** an authenticated caller with an active organisation
- **WHEN** the endpoint resolves the head cursor
- **THEN** it MUST return `{cursor: <RealtimeEventMapper::getMaxIdForOrganisation(org)>}`
- **AND** the global head pointer MUST NOT be returned (no cross-tenant write-rate side channel)

#### Scenario: No active organisation fails closed
- **GIVEN** an authenticated caller with no resolvable active organisation
- **WHEN** the endpoint resolves the head cursor
- **THEN** it MUST return `{cursor: 0}` (fail closed — no head pointer to mine)

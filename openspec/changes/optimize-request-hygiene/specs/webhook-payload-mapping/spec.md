# Capability delta: `webhook-payload-mapping`

## Purpose

Request interception currently costs a webhook table scan on every object write — even with zero interception webhooks — and delivers synchronously with the shared HTTP client's 30s total / 10s connect timeout, letting one slow endpoint stall all writes. This delta adds a cached fast-path flag for the zero-webhook case and a hard timeout cap for the request-blocking interception path.

## ADDED Requirements

### Requirement: Request interception MUST short-circuit via a cached tenant-agnostic flag when no interception webhook exists

The system SHALL maintain a per-event-type boolean flag — "does ANY enabled webhook configured for request interception exist for this event type, across ALL organisations" — in a distributed cache. Before querying webhooks, `interceptRequest` SHALL consult this flag: a cached `false` SHALL return the original request data without ANY webhook table query; a cached `true` SHALL proceed with the organisation-filtered interception lookup; a cache miss SHALL compute the flag from a tenant-agnostic scan (no organisation filter) and store it. The flag MUST NOT be cached per organisation: the cached answer must be valid for every tenant, so one tenant's "no webhooks" can never disable another tenant's interception hooks. The flag SHALL be invalidated on every webhook insert, update, and delete, and SHALL additionally expire via a TTL as a safety net. Without a cache backend the system SHALL fall back to computing the flag on each call (pre-cache behaviour).

#### Scenario: Zero interception webhooks — object write skips the webhook table
- **GIVEN** the cached flag for `object.creating` is `false`
- **WHEN** an object create request is intercepted
- **THEN** the original request data is returned
- **AND** no webhook table query of any kind is executed

#### Scenario: Cache miss computes and stores the flag
- **GIVEN** no cached flag exists for `object.creating` and no enabled interception webhook exists in any organisation
- **WHEN** an object create request is intercepted
- **THEN** the tenant-agnostic scan runs once, the flag is stored as `false`, and the original request data is returned without the organisation-filtered lookup

#### Scenario: Global true still applies organisation filtering
- **GIVEN** the cached flag for `object.creating` is `true` because another organisation has an interception webhook
- **WHEN** an object create request is intercepted for an organisation without interception webhooks
- **THEN** the organisation-filtered lookup runs and finds no applicable webhooks
- **AND** the original request data is returned

#### Scenario: Webhook CRUD invalidates the flag
- **GIVEN** a cached `false` flag for `object.creating`
- **WHEN** a webhook is created, updated, or deleted
- **THEN** the cached flags for all event types are invalidated so the next interception recomputes them

### Requirement: Request-interception delivery MUST be hard-capped at 2 seconds connect and total

Because interception blocks the object write by design, the synchronous interception delivery SHALL apply a hard cap of 2 seconds to both the connect timeout and the total request timeout, expressed as a named class constant. The cap SHALL only lower timeouts: a webhook whose own configured timeout is shorter keeps it, and a non-positive per-webhook timeout (HTTP-client semantics: wait forever) SHALL be forced to the cap. Asynchronous post-save deliveries SHALL keep the per-webhook timeout and SHALL NOT receive the cap.

#### Scenario: Slow interception endpoint cannot stall writes beyond the cap
- **GIVEN** an interception webhook whose configured timeout is 30 seconds
- **WHEN** it is delivered on the interception path
- **THEN** the delivery uses a 2-second total timeout and a 2-second connect timeout

#### Scenario: Shorter per-webhook timeout is preserved
- **GIVEN** an interception webhook whose configured timeout is 1 second
- **WHEN** it is delivered on the interception path
- **THEN** the delivery uses a 1-second total timeout (the cap never raises a timeout)

#### Scenario: Async delivery keeps per-webhook timeout
- **GIVEN** a webhook delivered through the asynchronous post-save path with a configured 30-second timeout
- **WHEN** the delivery request is issued
- **THEN** the total timeout is 30 seconds and no interception connect-timeout cap is applied

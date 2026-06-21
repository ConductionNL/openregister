---
kind: code
status: proposed
---

## Why

The BRP/HaalCentraal person-lookup leaf (`PersonLookupController::brpPerson` →
`BrpPersoonProvider::lookupByBsn` → `ExternalIntegrationRouter`) round-trips the
raw HAL+JSON person object but **discards the upstream response metadata** —
specifically the `X-Correlation-ID` response header and the request round-trip
duration. The consuming app (pipelinq) persists these into the
**legally-required Wet-BRP audit record** (`brpLookupVerzoek`):

- `haalcentraalCorrelationId` ← upstream `X-Correlation-ID` response header —
  the trace identifier RvIG/HaalCentraal returns per request; the Wet BRP +
  Logius/Digikoppeling logging norms require it to be retained so a lookup can
  be reconstructed and reconciled against the source's own logs in a privacy
  audit or subject-access request.
- `responseDuurMs` ← measured round-trip duration in milliseconds — retained as
  part of the request/response audit trail.
- `responseStatus` / `responseCode` ← the upstream HTTP status.

Today pipelinq's bespoke `HaalCentraalClient::lookupPersoon()` extracts these
itself (it reads `X-Correlation-ID`, times the call, and stamps
`_correlationId` / `_responseDurationMs` / `_responseStatus` onto the person,
which `BrpController` then unmaps into the `brpLookupVerzoek` fields). A re-point
of pipelinq onto the OR leaf (ADR-022) is **blocked** because the leaf drops
exactly these fields — the re-point would write audit records missing
legally-required data.

## What Changes

- **Router (general, foundation-safe):** add
  `ExternalIntegrationRouter::callWithMeta()` — a superset of `call()` that
  returns `{ body, meta }`, where `meta` is
  `{ status, durationMs, correlationId, headers }` extracted from the
  OpenConnector `CallLog` (`statusCode`, `responseTime` ms, response `headers`,
  and the case-insensitive `X-Correlation-ID`). `call()` is left untouched so
  every existing leaf keeps its lean body-only contract; any leaf can opt into
  meta. No request/response body or BSN is ever read into `meta`.
- **Leaf:** `BrpPersoonProvider::lookupByBsn()` routes through `callWithMeta()`
  and returns `{ results, total, meta: { correlationId, durationMs, status } }`
  on success. The degraded contract (`{ unavailable, cause, results, total }`)
  is unchanged.
- **Controller:** `PersonLookupController::brpPerson()` returns the success
  envelope including `meta`; the 503 degraded path is unchanged.

## Impact

- Affected leaf: `BrpPersoonProvider`, `PersonLookupController`.
- Affected foundation: `ExternalIntegrationRouter` (additive method only).
- Consumer contract: pipelinq's BRP re-point maps `meta.correlationId` →
  `haalcentraalCorrelationId`, `meta.durationMs` → `responseDuurMs`,
  `meta.status` → `responseStatus`/`responseCode`.
- Privacy: BSN remains strictly out of logs and out of `meta` — only the
  correlation id, duration, and status are surfaced.

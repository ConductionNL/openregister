# Design — integration-brp-audit-metadata

## Which audit fields and why Wet-BRP needs them

The consuming app (pipelinq) persists a `brpLookupVerzoek` record per BRP
lookup (`BrpController::saveLookupVerzoek`). Three of its fields are derived
from the **upstream HaalCentraal response** and cannot be reconstructed by the
consumer alone — they must be relayed by the OR leaf:

| Wet-BRP audit field (pipelinq)   | Source on the upstream response          | Why retained |
|----------------------------------|------------------------------------------|--------------|
| `haalcentraalCorrelationId`      | `X-Correlation-ID` response header        | RvIG/HaalCentraal's per-request trace id. Wet BRP + Logius/Digikoppeling logging norms require it to be retained so a lookup can be reconciled against the source's own logs during a privacy audit or a subject-access request. |
| `responseDuurMs`                 | measured round-trip duration (ms)         | Part of the request/response audit trail (performance + completeness of the record). |
| `responseStatus` / `responseCode`| upstream HTTP status code                 | Records the outcome (geslaagd / niet-gevonden / fout) at the transport layer. |

pipelinq's own client (`HaalCentraalClient::lookupPersoon`) extracts exactly
these today: it reads `X-Correlation-ID` (case-insensitively), times the call
with `microtime()`, and stamps `_correlationId` / `_responseDurationMs` /
`_responseStatus` onto the returned person; `BrpController` then unmaps them
(lines 264–267, 329–330) into the `brpLookupVerzoek` fields above. The OR leaf
must surface the same three so the re-point is field-complete.

## Response shape

The leaf and the endpoint return, on success:

```jsonc
{
  "results": [ /* raw HAL+JSON person objects, 0 or 1 */ ],
  "total": 1,
  "meta": {
    "correlationId": "abcd-1234-...",   // X-Correlation-ID, null when absent
    "durationMs": 137,                   // OpenConnector-measured round-trip (ms)
    "status": 200                        // upstream HTTP status
  }
}
```

The degraded path is **unchanged**: `503 { error, details: { cause } }` at the
controller, `{ unavailable, cause, results: [], total: 0 }` at the provider.

## Where the metadata comes from

OpenConnector's `CallService::call()` returns a `CallLog` whose `getResponse()`
payload is `{ statusCode, statusMessage, responseTime, size, remoteIp, headers,
body, encoding }` (`CallService::buildResponseData`). The router already unwraps
`body` (+ base64 `encoding`) in `decodeResponse()`. The new `extractMeta()`
reads ONLY `statusCode`, `responseTime` (float ms → int), and `headers` (Guzzle
`array<string,string[]>`, flattened) + the case-insensitive `X-Correlation-ID`.
It never touches `body`, so no BSN or payload data can leak into `meta`.

## Why a new method, not a changed `call()`

`call()` returns the decoded body directly and is consumed by every external
leaf (xWiki, KvK, OpenCorporates, …). Changing its return shape would ripple
across all of them. `callWithMeta()` is an additive, opt-in superset — strictly
foundation-safe — so only the BRP leaf (and any future audit-bearing leaf)
takes the richer envelope.

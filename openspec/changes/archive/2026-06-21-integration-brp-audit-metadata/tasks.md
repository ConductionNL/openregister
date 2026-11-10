# Tasks — integration-brp-audit-metadata

## 1. Router (foundation, additive)

- [x] 1.1 Add `ExternalIntegrationRouter::callWithMeta()` returning
      `{ body, meta }` — a superset of `call()`; `call()` left untouched.
- [x] 1.2 Add `invokeWithMeta()` + `extractMeta()` reading the OpenConnector
      `CallLog` `getResponse()` payload (`statusCode`, `responseTime` ms,
      `headers`) and the case-insensitive `X-Correlation-ID`; never reads body.
- [x] 1.3 Add `flattenHeaders()` + `firstHeaderValue()` helpers.

## 2. Leaf

- [x] 2.1 `BrpPersoonProvider::lookupByBsn()` routes through `callWithMeta()`
      and returns `{ results, total, meta: { correlationId, durationMs, status } }`.
- [x] 2.2 `shapeMeta()` / `emptyMeta()` shape the leaf's stable `meta` contract;
      degraded contract unchanged; BSN never in `meta`.

## 3. Controller

- [x] 3.1 `PersonLookupController::brpPerson()` relays the success envelope
      including `meta`; 503 degraded path unchanged.

## 4. Tests + quality

- [x] 4.1 Router unit: `callWithMeta` surfaces body + status + duration +
      correlationId; case-insensitive correlation header; defaults when absent;
      degrades on auth error.
- [x] 4.2 Provider unit: `lookupByBsn` surfaces `meta`; BSN not in `meta`;
      meta defaults when router omits meta; degraded paths unchanged.
- [x] 4.3 phpcs/lint + psalm clean on all touched files (fix what we touch +
      pre-existing in touched files).

## 5. Verify

- [x] 5.1 Run router + provider unit suites (27/27 green, PHP 8.4 container).
- [x] 5.2 Service-layer live-verify on :8080 (degrades non-fatal; meta
      pass-through proven with a stubbed upstream where reachable).

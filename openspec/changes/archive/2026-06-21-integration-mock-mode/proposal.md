---
kind: code
status: proposed
---

## Why

The external integration leaves (KvK, OpenCorporates, BRP/HaalCentraal, and the
SMS/WhatsApp dispatch leaf) all route through `ExternalIntegrationRouter` to an
OpenConnector `source` that carries the real upstream base URL + credentials.
Without real credentials (a KvK API key, an OpenCorporates token, RvIG OAuth2 +
PKIoverheid certificate, a CM.com/MessageBird/Twilio key, a Meta system-user
token) **none of these leaves can be demonstrated end-to-end** — every call
degrades to `503 { details: { cause } }` because the source is unconfigured.

We need the leaves to be **demonstrably functional end-to-end without any real
credentials or environments**, while keeping the real upstream path 100% intact
for sources that are properly configured.

## What Changes

- **Router (general, foundation-safe, additive):** `ExternalIntegrationRouter`
  reads the resolved source's `configuration`. When a source is flagged
  `configuration.mock === true`, `call()` and `callWithMeta()` **short-circuit**
  and return the canned `configuration.mockResponse` body **without performing a
  real HTTP call**. For `callWithMeta()` a synthesized `meta`
  (`{ status:200, durationMs, correlationId, headers }`, overridable via
  `configuration.mockMeta`) is returned so a meta-consuming leaf (BRP) still
  gets a fully-shaped Wet-BRP audit envelope. The real CallService path is
  untouched for non-mock sources; mock is **opt-in per source**.
- **No per-provider changes:** because the short-circuit lives in the router,
  every external leaf (KvK / OpenCorporates / BRP / message-dispatch) gets mock
  mode for free — the canned body is shaped EXACTLY like the real upstream, so
  the leaves' existing extractors and the consuming app's mappers consume it
  unchanged.
- **Source fixtures (paired OpenConnector change):** the 8 seeded source
  fragments set `configuration.mock:true` + a realistic upstream-shaped
  `configuration.mockResponse` and `isEnabled:true` so a fresh install is
  live-in-mock-mode with no secret.

## Impact

- Affected foundation: `ExternalIntegrationRouter` (additive — `call()` /
  `callWithMeta()` gain a mock short-circuit; the degraded + real contracts for
  non-mock sources are byte-for-byte unchanged).
- Affected leaves: none modified — KvK / OpenCorporates / BRP / message-dispatch
  all benefit transparently.
- Paired config change (OpenConnector): `integration-mock-sources` flags the 8
  source fragments mock+enabled with canned fixtures.
- Security: mock means NO upstream call and NO secret is read; the router only
  reads transport `configuration` (never a credential) to detect the flag. A
  non-array `mockResponse` yields an empty `{}` body (never a 500).

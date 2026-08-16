# Tasks — integration-mock-mode

## 1. Router (foundation, additive)

- [x] 1.1 `ExternalIntegrationRouter::call()` reads the resolved source's
      `configuration` and, when `configuration.mock === true`, returns the
      canned `configuration.mockResponse` body without a real CallService call.
- [x] 1.2 `ExternalIntegrationRouter::callWithMeta()` mirrors 1.1 and returns
      `{ body, meta }` where `meta` is synthesized
      (`status:200`, non-zero `durationMs`, a fresh fake `correlationId`),
      overridable via `configuration.mockMeta`.
- [x] 1.3 `readSourceConfiguration()` extracts the `configuration` array off
      the resolved source defensively (ObjectEntity `getObject()`, plain array,
      `jsonSerialize`, or `getConfiguration()`) — reads transport config only,
      never a credential.
- [x] 1.4 `resolveMockBody()` returns `configuration.mockResponse` (empty `{}`
      when absent/non-array — never a 500); `mockMeta()` synthesizes the meta
      envelope. The non-mock real + degraded paths are unchanged.

## 2. Tests + quality

- [x] 2.1 Router unit: a mock-flagged source returns the canned body; an
      ExplodingCallService proves no real call; empty body when mockResponse
      absent; `callWithMeta` returns body + synthesized meta; `mockMeta`
      override honoured; a non-mock source still uses the real CallService path.
- [x] 2.2 Integration unit (`IntegrationMockModeTest`): each leaf (KvK,
      OpenCorporates, BRP+meta, SMS, WhatsApp) returns its canned fixture
      end-to-end through a REAL router; CompanyLookupController +
      MessageDispatchController return 200 with the mock body.
- [x] 2.3 phpcs/lint + psalm clean on all touched files (fix what we touch +
      pre-existing in touched files).

## 3. Verify

- [x] 3.1 Run router + integration unit suites (PHP 8.4 container) — green.
- [x] 3.2 Service-layer verify: each provider in mock mode returns the fixture
      with no upstream call (proven by the ExplodingCallService /
      NeverCalledCallService stubs).

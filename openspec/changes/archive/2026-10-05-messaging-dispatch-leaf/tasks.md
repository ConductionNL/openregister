# Tasks — messaging-dispatch-leaf

## 1. Provider leaf

- [x] 1.1 Add `MessageDispatchProvider` (extends `AbstractIntegrationProvider`,
      `storage = external`, `requiredApp = openconnector`) with metadata
      (`id = message-dispatch`, label, icon, group `external`).
- [x] 1.2 Implement `dispatch(source, body, path, headers)` — validate `source`
      against the fixed `ALLOWED_SOURCES` allow-list (the five seeded slugs),
      set the per-call target source, POST through
      `ExternalIntegrationRouter::call`, reset the source in `finally`, and
      return `{ status: 'sent', source, response }` or a degraded
      `{ unavailable, cause }`.
- [x] 1.3 `getOpenConnectorSource()` returns the per-call target source so the
      router resolves the caller-chosen source; `list()` returns `[]`
      (send-only leaf); `health()` defers to `router->probe()`.

## 2. Controller + routes

- [x] 2.1 Add `MessageDispatchController` with `smsSend()` (gated to
      cmcom-sms/messagebird-sms/twilio-sms) and `whatsappSend()` (gated to
      whatsapp-cloud-api/whatsapp-bsp), both `#[NoAdminRequired]` +
      `#[NoCSRFRequired]`, relaying a degraded descriptor as a 503-with-cause.
- [x] 2.2 Add `POST /api/integrations/sms/send` and
      `POST /api/integrations/whatsapp/send` routes.

## 3. DI

- [x] 3.1 Register `MessageDispatchProvider` as a DI service (mirroring
      `KvkProvider`); the controller autowires from it. Do NOT add the leaf to
      the `bootBuiltinIntegrationProviders` registry loop (send-only, no
      listable surface).

## 4. Tests + quality

- [x] 4.1 Unit tests for the provider: metadata, allow-list, auth shape,
      isEnabled, list-empty, dispatch POST delegation, source set/reset,
      unknown-source rejection, degraded source-missing + upstream-down,
      health probe.
- [x] 4.2 `lint` + `phpcs --warning-severity=0` + `phpmd` + `psalm` clean on the
      changed files.

## 5. Verify

- [x] 5.1 Live: with the dormant seeded sources, a send through the leaf /
      endpoint resolves the source and degrades non-fatally
      (`503 upstream-service-down`) rather than `openconnector-source-missing`
      or a fatal.

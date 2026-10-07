---
kind: code
status: proposed
---

## Why

Pipelinq holds bespoke outbound-messaging transport clients — `TwilioSmsClient`,
`MessageBirdSmsClient`, `CmComSmsClient` (SMS) and `WhatsAppProviderClient`
(Meta Cloud API + BSP) — designed to never carry a vendor SDK: each delegates
the raw HTTP send to OpenConnector (ADR-005 / ADR-022). But they call a
non-existent method — `OCA\OpenConnector\Service\SourceService::executeAction(...)`
— so today every SMS / WhatsApp send throws
`PermanentSmsProviderException('openconnector SourceService lacks executeAction')`.
The transport is dead-on-arrival.

The canonical, already-landed path for "POST to an external service whose
credentials live on an OpenConnector source" is the integration leaf:
`ExternalIntegrationRouter::call(provider, method, path, options)` →
`CallService::call($source, $path, $method, $config)`. The BRP person-lookup
leaf already POSTs a request body through it (`RaadpleegMetBurgerservicenummer`).
A side-effecting send is mechanically identical to that POST — same router,
same `CallService::call`, same degrade-don't-throw contract — so the raw
dispatch is cleanly leaf-able.

This change centralises **only** the per-provider DISPATCH (the HTTP POST +
the credentials, which move onto OpenConnector sources) onto the canonical
OR/OpenConnector path. Everything that makes outbound messaging an
orchestration STAYS in pipelinq (exactly as BRP kept its BSN elfproef/masking
and KvK kept its Dutch→prospect mapping app-side): provider selection +
failover ordering, the STOP/opt-out webhook receiver, WhatsApp
template-approval, the 24h customer-service session window, consent + budget
gating, dedupe, and delivery-status reconciliation.

## What Changes

- **Add `MessageDispatchProvider`** (external, OpenConnector-backed) — a
  stateless, side-effecting **send** leaf. It POSTs one caller-composed message
  body to one named OpenConnector source (the per-call target chosen from a
  fixed allow-list of the five seeded messaging sources) via
  `ExternalIntegrationRouter`, round-trips the raw provider response, and
  degrades null-safely to `{ unavailable, cause }` (AD-23). The source slug is
  validated against the allow-list before the router is touched, so a caller
  can never point the leaf at an arbitrary source (no SSRF / source-confusion).
  It is a send-only leaf with no listable surface, so it is NOT added to the
  IntegrationRegistry boot loop (it would render an empty object-sidebar tab).
- **Add `MessageDispatchController`** with two endpoints:
  - `POST /api/integrations/sms/send` — `{ source, path, body, headers? }`,
    `source` gated to `cmcom-sms` / `messagebird-sms` / `twilio-sms`.
  - `POST /api/integrations/whatsapp/send` — same shape, `source` gated to
    `whatsapp-cloud-api` / `whatsapp-bsp`.
  Both relay a degraded descriptor as a `503` with `details.cause`.
- **Register** the provider as a DI service (mirroring `KvkProvider`); the
  controller autowires from it; add the two routes.

The matching OpenConnector `seed-messaging-sources` change seeds the five
dormant sources this leaf resolves.

## Capabilities

### Added Capabilities
- `integration-message-dispatch`: a new external integration leaf + REST
  surface that POSTs one outbound SMS / WhatsApp message through a named,
  admin-owned OpenConnector source, degrading non-fatally when the source is
  unconfigured / down.

## Impact

- **Code:** `lib/Service/Integration/Providers/MessageDispatchProvider.php`,
  `lib/Controller/MessageDispatchController.php`,
  `appinfo/routes.php` (+2 routes), `lib/AppInfo/Application.php` (+1 service),
  `tests/Unit/Service/Integration/Providers/MessageDispatchProviderTest.php`.
- **Behaviour:** an in-process consumer (pipelinq) or an authenticated POST to
  `/api/integrations/{sms,whatsapp}/send` with a seeded `source` POSTs the
  message through the OpenConnector source; with the dormant placeholder
  (no credential) it returns a `503` `upstream-service-down` rather than
  fatalling.
- **Consumers:** pipelinq `SmsAdapter` / `WhatsAppAdapter` per-provider clients
  re-point their dispatch from the (non-existent) `SourceService::executeAction`
  at this leaf — the orchestration around the send is unchanged.
- **Secrets:** none — the provider credentials live on the OpenConnector
  sources (seeded dormant, no key) in the paired change.
- **Security:** the base URL is admin-owned on each source; the source slug is
  allow-listed; only the operator-composed `path` + message `body` come from
  the caller — no end-user input reaches the base URL (no SSRF). Message bodies
  may carry PII, so the leaf never logs the request body.

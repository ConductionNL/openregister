# integration-message-dispatch

## ADDED Requirements

### Requirement: Outbound-messaging dispatch leaf

OpenRegister SHALL provide a `MessageDispatchProvider` integration leaf
(`storage = external`, `requiredApp = openconnector`) that POSTs one outbound
message (SMS or WhatsApp) through a named, admin-owned OpenConnector source via
`ExternalIntegrationRouter::call`, round-trips the raw provider response, and
degrades null-safely to `{ unavailable, cause }` (one of the AD-23 4-state
causes) rather than throwing when the source is unconfigured or down.

The leaf SHALL accept the target source per call (chosen from a fixed
allow-list of the seeded messaging sources — `cmcom-sms`, `messagebird-sms`,
`twilio-sms`, `whatsapp-cloud-api`, `whatsapp-bsp`) and SHALL reject any source
slug outside that allow-list before the router is invoked, so no caller can
point the leaf at an arbitrary source. The leaf SHALL NOT interpret the message
body shape — the consuming app composes the vendor-shaped payload — and SHALL
NOT log the request body (message bodies may carry PII). The leaf is send-only:
it exposes no listable linked things and is not surfaced as an object-sidebar
tab.

The provider SHALL carry only the raw dispatch. Provider selection + failover
ordering, the STOP / opt-out webhook receiver, WhatsApp template-approval, the
24h customer-service session window, consent + budget gating, dedupe, and
delivery-status reconciliation SHALL remain in the consuming app (pipelinq).

#### Scenario: dispatch POSTs through the named source

- GIVEN a seeded, enabled OpenConnector source `twilio-sms`
- WHEN the leaf dispatches a message with `source = twilio-sms`, a
  vendor-shaped `body`, and a send `path`
- THEN it issues a `POST` to that path through `ExternalIntegrationRouter`
  against the `twilio-sms` source and returns
  `{ status: 'sent', source: 'twilio-sms', response }`
- @e2e exclude Backend transport — verified by PHPUnit against a mocked router, not a browser flow.

#### Scenario: an unknown source is rejected before the router

- GIVEN a dispatch request with `source = some-other-source`
- WHEN the leaf validates the source against the allow-list
- THEN it returns `{ unavailable: true, cause: 'openconnector-source-missing' }`
  without invoking the router
- @e2e exclude Backend guard — verified by PHPUnit, not a browser flow.

#### Scenario: a missing/down source degrades non-fatally

- GIVEN a seeded but dormant source (no credential) or an unreachable upstream
- WHEN the leaf dispatches a message through it
- THEN it returns `{ unavailable: true, cause }` (e.g. `upstream-service-down`)
  rather than throwing, so the consuming app never fatals (AD-23)
- @e2e exclude Backend degraded path — verified by PHPUnit + the live dormant-source check, not a browser flow.

### Requirement: Outbound-messaging send endpoints

OpenRegister SHALL expose `POST /api/integrations/sms/send` and
`POST /api/integrations/whatsapp/send`, each accepting `{ source, path, body,
headers? }`, gating `source` to that channel's allowed seeded sources (SMS:
`cmcom-sms` / `messagebird-sms` / `twilio-sms`; WhatsApp: `whatsapp-cloud-api`
/ `whatsapp-bsp`), dispatching through `MessageDispatchProvider`, and relaying a
degraded descriptor as a `503` carrying `details.cause`. A request missing
`source` or `path`, or carrying a `source` not valid for the channel, SHALL
return a `400`.

#### Scenario: a degraded dispatch becomes a 503 with cause

- GIVEN the seeded messaging sources are dormant (no credential)
- WHEN an authenticated client POSTs to `/api/integrations/sms/send` with a
  valid SMS `source` and a `path`
- THEN the endpoint responds `503` with `details.cause = 'upstream-service-down'`
  rather than a fatal
- @e2e exclude Cross-app backend behaviour — verified against the send endpoint, not a browser flow.

#### Scenario: a source outside the channel is rejected

- GIVEN a POST to `/api/integrations/sms/send` with `source = whatsapp-cloud-api`
- WHEN the endpoint validates the source against the SMS channel's allowed set
- THEN it responds `400` (the WhatsApp source is not valid for the SMS channel)
- @e2e exclude Backend guard — verified against the send endpoint, not a browser flow.

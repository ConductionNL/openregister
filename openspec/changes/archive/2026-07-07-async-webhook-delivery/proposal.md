---
kind: fix
depends_on: []
adr: openspec/architecture/adr-009-performance-invariants.md
---

## Why

Webhook delivery blocks the object-write API response (ADR-009 Rule 5). The
listener fires inline on object lifecycle events and delivers synchronously:

- `lib/Listener/WebhookEventListener.php:121-148` handles
  `ObjectCreatedEvent`/`ObjectUpdatingEvent`/etc. inline.
- `lib/Service/WebhookService.php:601-645` (`dispatchEvent`) loops
  `foreach ($webhooks as $webhook) { $this->deliverWebhook(...) }`
  **synchronously**.
- `deliverWebhook()` (`:664`) → `sendRequest()` (`:1126`) makes the outbound HTTP
  call in-request with a 30s timeout / 10s connect-timeout (`:186-187,1154`).

Only *retries* are queued (`scheduleRetry`, `:744`); the **first** attempt is a
blocking outbound call on the API worker. With N webhooks subscribed to an
event, every create/update/delete holds the response open for up to N × 30s in
the worst case — a write endpoint's latency becomes a function of a third
party's uptime. This is the single highest-impact perf issue found: it is on the
write hot path of every consuming app.

Compounding it, the listener does work even when **no** webhook is configured:

- `extractPayload()` calls `$object->jsonSerialize()` (`:174,186-187,206`)
  unconditionally, *before* `dispatchEvent()` checks whether any webhook matches.
- `findForEvent()` runs a DB query on **every** object write, even on instances
  that have never configured a webhook.

## What Changes

- Dispatch the **first** delivery attempt through the existing background-job
  queue (`WebhookDeliveryJob`, already used for retries) instead of calling
  `deliverWebhook()` synchronously from the listener. The write returns as soon
  as the delivery is enqueued.
- Short-circuit the listener with a cheap, cacheable "are there any webhooks for
  this app/event?" boolean check *before* serializing the payload or querying
  `findForEvent()`. Only serialize once a matching webhook is confirmed.

## Impact

- Affected: `lib/Listener/WebhookEventListener.php`, `lib/Service/WebhookService.php`,
  `lib/BackgroundJob/WebhookDeliveryJob.php` (reused).
- Behavioural change: webhook delivery becomes asynchronous — consumers relying
  on synchronous delivery within the write response must not (webhooks are
  fire-and-forget by contract). Document the at-least-once, async semantics.
- Risk: ensure ordering/idempotency expectations still hold; the retry job
  already exists, so the delivery/΅backoff machinery is unchanged — only the
  trigger point moves off the request thread.

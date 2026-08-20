## 1. Move first delivery off the request thread

- [ ] 1.1 In `WebhookService::dispatchEvent()` (`lib/Service/WebhookService.php:601-645`), replace the synchronous `foreach { deliverWebhook() }` with enqueuing one `WebhookDeliveryJob` per matching webhook (the job already handles delivery + backoff for retries).
- [ ] 1.2 Confirm `WebhookDeliveryJob` performs the first attempt identically to the old inline path (same payload, headers, signing, timeout).

## 2. Short-circuit when nothing subscribes

- [ ] 2.1 In `WebhookEventListener` (`lib/Listener/WebhookEventListener.php:121-148`), add a cheap cacheable check "does this app/event have any webhook?" BEFORE calling `extractPayload()` / `jsonSerialize()`.
- [ ] 2.2 Cache the "any webhooks configured" boolean and invalidate it on webhook create/delete so `findForEvent()` does not run a DB query on every object write for instances with no webhooks.

## 3. Verification

- [ ] 3.1 Test: an object create with N slow (timeout) webhooks returns in ~constant time (delivery enqueued), not N × 30s.
- [ ] 3.2 Test: on an instance with zero webhooks, an object write performs no payload serialization and no `findForEvent` query (assert via query log / spy).
- [ ] 3.3 Test: webhook still delivered (via job) with correct payload/signature; retry/backoff unchanged.
- [ ] 3.4 `composer check:strict` passes.

## Acceptance criteria

- Object write latency is independent of webhook target availability.
- No payload serialization or webhook DB query on writes when no webhook matches.
- Delivery semantics (at-least-once, signed, retried) are preserved via the job.

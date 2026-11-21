---
kind: code
---

## Why

A webhook posts every event to one fixed `url`. OpenCatalogi's federation broadcast
(`opencatalogi-delegate-broadcast-to-or-webhooks`, tasks 1 to 3) tells every peer
directory that this catalogue changed: one event, a different URL per peer, with
retries and a visible dead letter per peer. Today it keeps its own retry loop because
OpenRegister has no way to send one event to a URL the caller names. One Webhook row
per peer would work, but peers come and go with the directory listing and the rows
would need to be kept in step by hand.

## What Changes

- A webhook MAY set `configuration.deliverPerTarget: true`. Such a webhook holds the
  event, method, headers, secret, timeout and retry policy, and every delivery names
  its own URL. Its own `url` is never posted to.
- `WebhookService::triggerWebhookForEvent(string $eventName, array $payload, string $url): int`
  enqueues one `WebhookDeliveryJob` per per-target webhook subscribed to the event,
  carrying the URL, and returns how many it enqueued.
- The delivery, its log row (`url`), the outbound address checks (redirects included)
  and the retries (`WebhookRetryJob`, the manual retry route) all use that URL.
- An ordinary `dispatchEvent()` / `dispatch()` skips a per-target webhook: it has no
  fixed target.
- An owned webhook never delivers per target, whatever its configuration says, and a
  target URL handed to an ordinary webhook is ignored: no caller can redirect a
  webhook someone else configured.
- Webhooks without the key behave exactly as before.

## Impact

- `lib/Service/WebhookDelivery.php`, `lib/Service/WebhookService.php`,
  `lib/Service/WebhookDeliveryRecorder.php`, `lib/BackgroundJob/WebhookDeliveryJob.php`,
  `lib/BackgroundJob/WebhookRetryJob.php`, `lib/Controller/WebhooksController.php`.
- No migration, route, schema or setting. One new public method; no signature changes.

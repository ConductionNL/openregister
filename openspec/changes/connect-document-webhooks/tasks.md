# Tasks: connect-document-webhooks (openregister half)

<!-- HYDRA CAP: max 20 unindented `- [ ]` lines. This file uses 4. -->

## 1. Declaration and delivery

- [x] 1.1 Add `lib/Event/AppWebhookEvents.php`: `declare()`, `payloadOf()`, `catalogue()`, request-scoped, refusing undeclarable classes. Verify: PHPUnit `tests/Unit/Event/AppWebhookEventsTest.php`.
- [x] 1.2 `WebhookEventListener::extractPayload()` falls back to `AppWebhookEvents::payloadOf()`. Verify: PHPUnit dispatches a declared app event and asserts the exact payload reaches `WebhookService::dispatchEvent()`.

## 2. Catalogue

- [x] 2.1 `WebhooksController::events()` appends the declared events. Verify: the events golden test is unchanged and PHPUnit asserts a declared event follows the built-in ones.

## 3. End to end

- [ ] 3.1 With filinq declaring its events, a webhook for `OCA\Filinq\Event\Webhook\DocumentSignedEvent` receives one signed delivery when a signing request completes (filinq task 1.3). Live test; not done in unit tests.

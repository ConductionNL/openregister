---
kind: code
depends_on: []
---

# Proposal: connect-document-webhooks (openregister half)

filinq's change `connect-document-webhooks` (filinq PR #2100) raises four
document events (signed, anonymised, generated, registered) and leaves
their delivery to OpenRegister's webhooks. OpenRegister cannot deliver
them today:

- `WebhookEventListener::extractPayload()` only builds a payload for the
  event classes it names, so any other event is dropped with a warning.
- The listener is registered for OpenRegister's own events only.
- `WebhooksController::events()` returns a hard-coded catalogue, so
  neither OpenRegister's webhook page nor filinq's section can list an
  app's events.

## What changes

- New `OCA\OpenRegister\Event\AppWebhookEvents`: an app declares one of
  its event classes from `boot()` with a name, description, category and
  the method that returns the payload. OpenRegister subscribes its
  webhook listener to that class.
- `WebhookEventListener` delivers a declared event with the payload the
  app's method returns, unchanged.
- `WebhooksController::events()` lists the declared events after the
  built-in ones.

The app's event class needs no OpenRegister type, so the app keeps
working without OpenRegister installed. A class inside OpenRegister's
own namespace, a class that is not an event, or a payload method that is
missing, non-public, static or needs arguments is refused and nothing is
subscribed.

## Out of scope

- Delivery, retries, signing and logs: unchanged, `WebhookService` does
  them as for every event.
- Organisation scoping of webhooks for events raised without an active
  organisation: unchanged; filinq's live test (its task 1.3) shows
  whether it needs work.

## Cross-app dependencies

- **filinq**: declares its four document events from
  `ObjectEventRegistrar::boot()` (filinq PR #2100).

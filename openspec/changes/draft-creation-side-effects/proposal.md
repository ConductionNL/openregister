---
kind: code
depends_on: [draft-owner-activation-and-lifetime]
---

# Proposal: draft-creation-side-effects

Decision 227 (Ruben, 12 October 2026): one rule for creation side effects. Stacked on `draft-owner-activation-and-lifetime`, which named the gap in its task 3.6.

## Why

A draft is the destination object in status `draft` (decision 180). It may be half typed, and nobody but its owner knows it. OpenRegister registers about twenty listeners on `ObjectCreatedEvent`, `ObjectUpdatedEvent` and `ObjectDeletedEvent`: flows, webhooks, activity, push, metrics, notifications, text extraction, the assistant index. All of them fired on a draft's first autosave, and none fired when the draft became a real object. So a webhook announced "ticket created" for two typed characters, and said nothing when the ticket was sent.

`draft-owner-activation-and-lifetime` fixed one listener, the notification rules, with a status check inside it. Sixteen more status checks would be sixteen places to forget one.

## What changes

- **One class decides.** `ObjectEventGate` is the only class that creates an object event. Every save and delete goes through it.
- **A draft has events of its own.** Its first save, its later saves and its delete dispatch `DraftCreatedEvent`, `DraftUpdatedEvent` and `DraftDeletedEvent`. Nextcloud delivers an event to the listeners on its own class, so a listener on `ObjectCreatedEvent` never receives a draft.
- **Leaving draft is the creation.** The form submit and the owner's activation dispatch `ObjectCreatedEvent` once, after the whole write succeeded, and then `ObjectActivatedEvent` with the moment of receipt. The save that leaves draft dispatches no `ObjectUpdatedEvent`.
- **Seven listeners must see a draft.** They keep the stored draft itself right and tell nobody anything. They are registered in one block, `Application::registerDraftListeners()`, and listed in the spec.
- **The notification listener loses its own check.** `AnnotationNotificationListener` goes back to what it was: it listens to `ObjectCreatedEvent`, which now is the right moment.
- **A test keeps the rule.** `ObjectEventRuleTest` fails when a class creates an object event itself, when a listener outside the list is registered on a draft event, and when a listener sits on both `ObjectCreatedEvent` and `ObjectActivatedEvent`.

## What an app that listens to OpenRegister gets

Nothing to do. A listener on `ObjectCreatedEvent` never sees a draft and sees every object once: when it is created outright, or when it leaves draft. An app that skipped drafts with a status check can drop the check. An app must not do creation work on `ObjectActivatedEvent` too: that event follows `ObjectCreatedEvent` for the same object and is for the moment of receipt alone.

## Decisions taken while building, for Ruben to overrule

- **Which listeners must see a draft.** Cache invalidation for lists and facets, the translation sidecar, and the four cleanups on delete. Everything else is held, also the ones that only look inward: text extraction, the assistant index, automatic lifecycle transitions, metrics, read state, push.
- **Push is held.** The owner's other tab does not refresh when a draft autosaves. The ruling names push as a side effect.
- **A draft's delete is silent for side-effect listeners.** They never saw it created, so they are not told it is gone.

## Out of scope

- The `ObjectCreatingEvent`, `ObjectUpdatingEvent` and `ObjectDeletingEvent` listeners. They run before the write and decide what is stored (identifiers, calculations, validation, quota). They see a draft as before.
- dossiq's own listeners. Another lane handles those; this rule gives them the signal.

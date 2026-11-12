---
kind: code
depends_on: [notification-message-and-body]
---

## Why

OpenRegister's `x-openregister-notifications` engine localises a rule's `subject` and puts it into BOTH the notification title AND its body — so every dialect notification renders with an identical title and body (title `"Incoming call from X"`, body `"Incoming call from X"`). The body line in the Nextcloud notification UI and the web-push OS popup is wasted, and a rule has no way to say "title = what happened, body = what to do". The hydra contract change `notification-message-and-body` (ADR-031, `kind: config`) defines the optional `message` body field plus the auto-derived body rule; this change is the OpenRegister engine that *implements* that contract.

This change is the **tail of a two-change chain** (ADR-032):

1. `notification-message-and-body` (hydra, `kind: config`) — the dialect/contract delta + ADR-031 update. **(this change's `depends_on`)**
2. **THIS change** `openregister-notification-body` (openregister, `kind: code`) — the engine implementation.

## What Changes

- **Validation.** `NotificationAnnotationValidator` accepts an optional `message` field on a rule, validated with the same shape contract as `subject` (string OR per-locale map with non-empty locales and a valid `defaultLocale`). A malformed `message` is rejected with a new `notification-bad-message` error code.
- **Body resolution.** `AnnotationNotificationDispatcher` localises a rule's `message` per recipient (and once per rule for broadcast channels) through the SAME localiser/interpolator used for `subject`. When a rule declares no `message` but DOES declare `actions[]`, the dispatcher auto-derives the body `"Open in {AppName}."` using the `originApp`'s display name (via `IAppManager`, falling back to the capitalised app id). When a rule has neither `message` nor `actions`, the body is empty (back-compat).
- **Threading.** The resolved body is threaded into both delivery paths: `emitNotification` adds it to the notification subject-parameters under `_message`, and `enqueueWebPush` passes it as the web-push job `body` argument (the job already reads `argument['body']` for the payload body). The web-push `title` stays the subject.
- **Rendering.** `AnnotationNotifier::prepare()` calls `setParsedMessage()` when `_message` is a non-empty string, leaving the body unset otherwise (back-compat).
- **Tests + ADR-031 alignment.** Unit tests for the validator (`message` accepted/rejected), the dispatcher (explicit body, auto-derived body, empty body), and the notifier (`setParsedMessage` set/not-set).

No new OpenRegister schemas, no new routes, no new dependencies.

## Capabilities

### Modified Capabilities
- `notificatie-engine`: the declarative dialect-resolution behaviour gains the title(`subject`)/body(`message`) model — the optional `message` field, per-recipient + broadcast body localisation, the auto-derived `"Open in {AppName}."` body for actions-bearing rules, and threading the body into the in-app notification (`setParsedMessage`) and the web-push payload body. Implemented in `NotificationAnnotationValidator`, `AnnotationNotificationDispatcher`, and `AnnotationNotifier`.

## Impact

- **Modified (lib):**
  - `lib/Service/Notification/NotificationAnnotationValidator.php` — accept + validate `message`; `notification-bad-message` error.
  - `lib/Service/Notification/AnnotationNotificationDispatcher.php` — `resolveMessageBody()` + `resolveAppDisplayName()` helpers; per-recipient + broadcast body resolution; `enqueueWebPush()` gains a `message` param (job `body` = message, `title` = subject); `emitNotification()` gains a `message` param (`_message` in link params); nullable `IAppManager` injected.
  - `lib/Notification/AnnotationNotifier.php` — `setParsedMessage()` on non-empty `_message`.
  - `lib/BackgroundJob/WebPushDispatchJob.php` — confirmed: `buildPayload()` already maps `argument['body']` → payload `body` (now the message); no change required.
- **Tests:** `tests/Unit/Service/Notification/NotificationAnnotationValidatorTest.php`, `tests/Unit/Service/Notification/AnnotationNotificationDispatcherTest.php`, `tests/Unit/Notification/AnnotationNotifierTest.php`.
- **Spec:** `openspec/specs/notificatie-engine/spec.md` gains the title/body-model requirement.
- **Rollback:** revert the four lib files + the three test files; the `IAppManager` injection is nullable + default-null so it is back-compatible. No data migration.

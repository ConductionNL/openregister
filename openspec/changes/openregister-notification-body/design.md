# Design — openregister-notification-body

## Context

OpenRegister's annotation notification engine (`AnnotationNotificationDispatcher` + `AnnotationNotifier`) localises a rule's `subject` per recipient and renders it as the notification TITLE. It never sets a distinct body, so `setParsedSubject` is the only text set and `notify_push` / the in-app UI / the web-push popup show title == body (the web-push job even hard-codes `'body' => $subject`). The hydra contract change `notification-message-and-body` defines the optional `message` body field and the auto-derivation rule; this change implements it without touching the dialect's existing surface.

## Decisions

### Reuse `resolveLocalizedSubject` for the body

`message` shares `subject`'s exact shape and resolution rules. Rather than duplicate the locale/interpolation logic, a thin `resolveMessageBody()` wrapper calls the existing `resolveLocalizedSubject()` (passing an empty `fallbackName` so an unresolvable map degrades to `''` instead of leaking the rule name). The validator's `validateMessage()` is a near-copy of the `subject` validation, emitting one canonical `notification-bad-message` error for every malformed shape.

### Auto-body keyed on declared actions

`resolveMessageBody()` takes a `hasActions` flag (computed from the already-resolved `resolvedActions`, so a declared-but-unresolvable action does not trigger an auto-body for a notification that will show no button). When there is no `message` and `hasActions` is true, it returns `"Open in {AppName}."`; otherwise `''`. `{AppName}` is resolved by `resolveAppDisplayName()` via the injected nullable `IAppManager` (`getAppInfo($app)['name']`), falling back to `ucfirst($app)` when the manager is absent or the info has no usable name. The lookup is wrapped in a `try/catch` so it can never fail the dispatch.

### Thread the body through both delivery paths

- `emitNotification()` gains a `string $message` param and adds `'_message' => $message` to `$linkParams` (next to `_actions`/`_tag`). `AnnotationNotifier::prepare()` reads `_message` and calls `setParsedMessage()` only when it is a non-empty string — so a rule with an empty body renders exactly as before.
- `enqueueWebPush()` gains a `string $message` param and sets the job argument `'body' => $message` (was `$subject`), keeping `'title' => $subject`. `WebPushDispatchJob::buildPayload()` already maps `argument['body']` → payload `body`, so no job change is needed.

### Nullable IAppManager injection

`IAppManager` is added as a trailing nullable constructor param (`?IAppManager $appManager = null`). NC autowires it by type in production; the default-null keeps the existing unit-test constructors (which pass arguments positionally and omit the tail) working unchanged.

## Risks / Trade-offs

- **The auto-body string is English-shaped.** It is a fallback for actions-bearing rules; authors needing a localised body declare `message`. A later change can localise it via the openregister l10n files.
- **The body only lands on channels with a body line** (in-app notification + web-push). Single-line channels (email subject, talk) continue to use the subject — documented in ADR-031.

## Migration

Zero-migration. Existing rules carry no `message`; rules without actions keep an empty body (identical to today). Rules that already declare `actions[]` gain the auto-body — a strict improvement over the prior duplicated/empty body.

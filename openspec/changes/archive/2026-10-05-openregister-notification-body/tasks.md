# Tasks — openregister-notification-body

## 1. Validation

- [x] 1.1 `NotificationAnnotationValidator` — accept an optional `message` field; validate the same shape as `subject` (string OR per-locale map with non-empty locales + valid `defaultLocale`); emit `notification-bad-message` on any malformed shape (new private `validateMessage()`).
- [x] 1.2 Update the validator class docblock to document `message` as the i18n notification body.

## 2. Dispatcher body resolution

- [x] 2.1 Inject a nullable `IAppManager $appManager = null` into `AnnotationNotificationDispatcher` (trailing constructor param + docblock).
- [x] 2.2 Add `resolveMessageBody()` — localise the rule's `message` via the existing `resolveLocalizedSubject()`; when empty AND the rule has actions, auto-derive `"Open in {AppName}."`; else return `''`.
- [x] 2.3 Add `resolveAppDisplayName()` — `IAppManager::getAppInfo($app)['name']`, guarded for null manager, falling back to `ucfirst($app)`.
- [x] 2.4 In the dispatch loop, extract `$messageTemplate`, compute `$broadcastMessage` (once per rule) and `$recipientMessage` (per recipient), and thread them into `enqueueWebPush()` and `emitNotification()`.

## 3. Delivery threading

- [x] 3.1 `enqueueWebPush()` — add `string $message` param; set job arg `'body' => $message` (keep `'title' => $subject`).
- [x] 3.2 `emitNotification()` — add `string $message` param; add `'_message' => $message` to `$linkParams`.
- [x] 3.3 `AnnotationNotifier::prepare()` — after `setParsedSubject`, call `setParsedMessage($params['_message'])` when `_message` is a non-empty string; leave unset otherwise.
- [x] 3.4 Confirm `WebPushDispatchJob::buildPayload()` maps `argument['body']` → payload `body` (now the message); no change required.

## 4. Tests + verification

- [x] 4.1 Validator tests: `message` string accepted, per-locale map accepted, malformed rejected with `notification-bad-message`, empty string rejected.
- [x] 4.2 Dispatcher tests: explicit per-locale body, auto-derived `"Open in {AppName}."` (actions, no message), empty body (neither message nor actions).
- [x] 4.3 Notifier tests: `setParsedMessage` called for non-empty `_message`; never called when `_message` absent.
- [x] 4.4 `php -l` + phpcs clean on every changed PHP file; PHPUnit green for the three test classes.

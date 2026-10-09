# Tasks: opt-out-before-send (openregister)

Spec only until Ruben approves ConductionNL/hydra#739. Build after integriq's events exist; until then the seam answers `authority-unavailable` and the tests use a stub listener.

## 1. The seam

- [x] 1.1 Deduplication check: confirm OpenRegister has no other outbound opt-out or suppression reader (`git grep -n -i "opt.out\|unsubscribe\|suppress" lib/Service/Flow lib/Service/Notification lib/Service/Party`).
  - acceptance: the hits are listed in the PR body.
- [x] 1.2 `OptOutAuthority::ask()`, with the string-named event, the `class_exists()` guard, the fixed exempt floor and the config switch.
  - spec_ref: `specs/external-recipient-opt-out/spec.md#requirement-the-send-email-flow-step-asks-integriq-before-it-mails-an-external-address-req-ero-001`
  - files: `lib/Service/Notification/OptOutAuthority.php`, `tests/Unit/Service/Notification/OptOutAuthorityTest.php`
  - acceptance: absent class, unhandled event and a throwing listener each give `authority-unavailable` for `service` and `send: true` for `besluit`.
  - test: `vendor/bin/phpunit --no-coverage --filter OptOutAuthorityTest`

## 2. The flow step

- [x] 2.1 `messageCategory` in `SendEmailNode` config keys, validation and form.
  - spec_ref: `#requirement-the-send-email-step-declares-a-message-category-req-ero-002`
  - files: `lib/Service/Flow/Nodes/SendEmailNode.php`, its test
  - test: `vendor/bin/phpunit --no-coverage --filter SendEmailNodeTest`
- [x] 2.2 One `ask()` before the loop in `sendToAddresses()`. New buckets `optedOut` and `authorityUnavailable`. No rate-limit use and no `FlowEmailSentEvent` for a skipped address.
  - files: `lib/Service/Flow/FlowMessagingService.php`, its test
  - acceptance: the opted-out scenario passes with a real dispatcher and a stub listener. Red before.
  - test: `vendor/bin/phpunit --no-coverage --filter FlowMessagingServiceTest`
- [x] 2.3 The flow builder shows the new buckets in the run log. English string in `l10n/en.js`, translated per `docs/l10n-workflow.md` §6.15.
  - files: `src/` run-log component, `l10n/`
  - test: `npm run test:l10n && npm run test:l10n:parity`
  - done: the run log lives in nextcloud-vue, so the component and its strings (en, nl) are there: `CnFlowStepOutcomes`, rendered by `CnRunDetailSidebar` under each step (nextcloud-vue `feat/static-select-options-and-outcome-labels`). OpenRegister gets it with the release; no `src/` string was added here. The send-email step's `messageCategory` is now a select with labelled options (`SendEmailNode::configForm()`).

## 3. The party path

- [x] 3.1 `notifyParties()` takes a category and asks once. Outcomes `refused-opted-out` and `authority-unavailable`.
  - spec_ref: `#requirement-a-parties-notification-asks-integriq-before-it-mails-a-party-req-ero-003`
  - files: `lib/Service/Party/PartyNotificationService.php`, its test
  - test: `vendor/bin/phpunit --no-coverage --filter PartyNotificationServiceTest`
- [x] 3.2 `messageCategory` on a notification rule: validator, dispatcher pass-through.
  - files: `lib/Service/Notification/NotificationAnnotationValidator.php`, `lib/Service/Notification/AnnotationNotificationDispatcher.php`
  - acceptance: `notification-bad-message-category` on an unknown value.
  - test: `vendor/bin/phpunit --no-coverage --filter NotificationAnnotationValidatorTest`

## 4. The link and the headers

- [x] 4.1 The shared `UnsubscribeHeaders` helper (Ruben, 2026-10-05). Public service, guarded path, returns false instead of throwing.
  - spec_ref: `#requirement-openregister-owns-one-shared-list-unsubscribe-helper-req-ero-005`
  - files: `lib/Service/Notification/UnsubscribeHeaders.php`, `tests/Unit/Service/Notification/UnsubscribeHeadersTest.php`
  - acceptance: both helper scenarios pass. Document it in OpenRegister's published contract so dossiq and pipelinq can rely on it.
  - test: `vendor/bin/phpunit --no-coverage --filter UnsubscribeHeadersTest`
- [x] 4.2 `EmailSender::sendToAddress()` takes the unsubscribe material and calls the helper. The body link is appended by the callers.
  - spec_ref: `#requirement-an-external-mail-carries-the-unsubscribe-link-req-ero-004`
  - files: `lib/Service/Notification/EmailSender.php`, its test
  - test: `vendor/bin/phpunit --no-coverage --filter EmailSenderTest`

## 4a. The defect found while reading

- [x] 4a.1 A party mail's body is its subject (`AnnotationNotificationDispatcher.php:3221`). Resolve the rule's `message` at the caller (`:533-539`), pass it to `dispatchToParties()` as `body`, fall back to the subject.
  - spec_ref: `#requirement-a-party-mail-carries-the-rule-s-message-as-its-body-req-ero-006`
  - files: `lib/Service/Notification/AnnotationNotificationDispatcher.php`, its test
  - acceptance: both body scenarios pass. Red before.
  - test: `vendor/bin/phpunit --no-coverage --filter AnnotationNotificationDispatcherTest`

## 5. Verify

- [x] 5.1 Regression: opencatalogi and softwarecatalog do not call these paths. Confirm with `git grep -n "send-email\|notifyParties"` in both repos.
- [x] 5.2 Live check with integriq installed: opt out through the link, run a flow that mails that address, see it under `optedOut`.
- [x] 5.3 `COMPOSER_PROCESS_TIMEOUT=0 composer check:strict` once, then `npm run lint`.

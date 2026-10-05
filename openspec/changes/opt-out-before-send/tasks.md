# Tasks: opt-out-before-send (openregister)

Spec only until Ruben approves ConductionNL/hydra#739. Build after integriq's events exist; until then the seam answers `authority-unavailable` and the tests use a stub listener.

## 1. The seam

- [ ] 1.1 Deduplication check: confirm OpenRegister has no other outbound opt-out or suppression reader (`git grep -n -i "opt.out\|unsubscribe\|suppress" lib/Service/Flow lib/Service/Notification lib/Service/Party`).
  - acceptance: the hits are listed in the PR body.
- [ ] 1.2 `OptOutAuthority::ask()`, with the string-named event, the `class_exists()` guard, the fixed exempt floor and the config switch.
  - spec_ref: `specs/external-recipient-opt-out/spec.md#requirement-the-send-email-flow-step-asks-integriq-before-it-mails-an-external-address-req-ero-001`
  - files: `lib/Service/Notification/OptOutAuthority.php`, `tests/Unit/Service/Notification/OptOutAuthorityTest.php`
  - acceptance: absent class, unhandled event and a throwing listener each give `authority-unavailable` for `service` and `send: true` for `besluit`.
  - test: `vendor/bin/phpunit --no-coverage --filter OptOutAuthorityTest`

## 2. The flow step

- [ ] 2.1 `messageCategory` in `SendEmailNode` config keys, validation and form.
  - spec_ref: `#requirement-the-send-email-step-declares-a-message-category-req-ero-002`
  - files: `lib/Service/Flow/Nodes/SendEmailNode.php`, its test
  - test: `vendor/bin/phpunit --no-coverage --filter SendEmailNodeTest`
- [ ] 2.2 One `ask()` before the loop in `sendToAddresses()`. New buckets `optedOut` and `authorityUnavailable`. No rate-limit use and no `FlowEmailSentEvent` for a skipped address.
  - files: `lib/Service/Flow/FlowMessagingService.php`, its test
  - acceptance: the opted-out scenario passes with a real dispatcher and a stub listener. Red before.
  - test: `vendor/bin/phpunit --no-coverage --filter FlowMessagingServiceTest`
- [ ] 2.3 The flow builder shows the new buckets in the run log. English string in `l10n/en.js`, translated per `docs/l10n-workflow.md` §6.15.
  - files: `src/` run-log component, `l10n/`
  - test: `npm run test:l10n && npm run test:l10n:parity`

## 3. The party path

- [ ] 3.1 `notifyParties()` takes a category and asks once. Outcomes `refused-opted-out` and `authority-unavailable`.
  - spec_ref: `#requirement-a-parties-notification-asks-integriq-before-it-mails-a-party-req-ero-003`
  - files: `lib/Service/Party/PartyNotificationService.php`, its test
  - test: `vendor/bin/phpunit --no-coverage --filter PartyNotificationServiceTest`
- [ ] 3.2 `messageCategory` on a notification rule: validator, dispatcher pass-through.
  - files: `lib/Service/Notification/NotificationAnnotationValidator.php`, `lib/Service/Notification/AnnotationNotificationDispatcher.php`
  - acceptance: `notification-bad-message-category` on an unknown value.
  - test: `vendor/bin/phpunit --no-coverage --filter NotificationAnnotationValidatorTest`

## 4. The link and the headers

- [ ] 4.1 `EmailSender::sendToAddress()` accepts headers and sets them through the guarded path. The body link is appended by the callers.
  - spec_ref: `#requirement-an-external-mail-carries-the-unsubscribe-link-req-ero-004`
  - files: `lib/Service/Notification/EmailSender.php`, its test
  - test: `vendor/bin/phpunit --no-coverage --filter EmailSenderTest`

## 5. Verify

- [ ] 5.1 Regression: opencatalogi and softwarecatalog do not call these paths. Confirm with `git grep -n "send-email\|notifyParties"` in both repos.
- [ ] 5.2 Live check with integriq installed: opt out through the link, run a flow that mails that address, see it under `optedOut`.
- [ ] 5.3 `COMPOSER_PROCESS_TIMEOUT=0 composer check:strict` once, then `npm run lint`.

# Ask integriq before mailing an external address

Part of the hydra change `opt-out-before-send` (ConductionNL/hydra#739). That change holds the fleet contract, the sender table and Ruben's decisions of 2026-10-05. This is OpenRegister's share.

## Why

OpenRegister mails people outside Nextcloud on two paths, and neither asks whether the person opted out.

- The `openregister.send-email` flow step (`lib/Service/Flow/Nodes/SendEmailNode.php:52`) mails external addresses when `externalRecipients` is `object` or `any` (`lib/Service/Flow/FlowMessagingService.php:100-106`). `sendToAddresses()` (`:596`) checks the rate limiter (`:609`) and the channel kill switch, and then sends (`lib/Service/Notification/EmailSender.php:135`, send `:155`).
- A `parties` recipient on an `x-openregister-notifications` rule (`lib/Service/Notification/AnnotationNotificationDispatcher.php:3207-3222`) mails each party's address through `PartyNotificationService::notifyParties()` (`lib/Service/Party/PartyNotificationService.php:107`). Only a party indicator can stop it (`:110`).

A person who clicked an integriq unsubscribe link is mailed anyway.

## What changes

- Both paths ask integriq once per batch through `OCA\Integriq\Event\OutboundSendDecisionRequestedEvent`, named by string and guarded with `class_exists()`, as `ConnectionReporter` already does (`lib/Service/Connection/ConnectionReporter.php:51-63`, `:280`, `:322`).
- An opted-out address is skipped. The flow step reports it in a new `optedOut` bucket. The party path reports it as `refused-opted-out`.
- Without integriq, only exempt categories are mailed. The rest is reported `authority-unavailable`.
- The flow step and the notification rule gain an optional `messageCategory`, default `service`.
- A non-exempt mail carries integriq's unsubscribe link in the body, and its `List-Unsubscribe` headers when the mailer allows them.
- **One shared header helper.** OpenRegister owns `UnsubscribeHeaders`, which sets `List-Unsubscribe` and `List-Unsubscribe-Post` behind the guarded `getSymfonyEmail()` path. `EmailSender` uses it, and dossiq and pipelinq call it instead of keeping their own copy (Ruben, 2026-10-05, decision 6).
- **A defect fixed on the way.** A party mail's body is its subject today: `dispatchToParties()` is given only the subject (`lib/Service/Notification/AnnotationNotificationDispatcher.php:533-539`) and passes `body: $subject` to `notifyParties()` (`:3221`). This change passes the rule's resolved message as the body, and falls back to the subject only when the rule has none.

## Capabilities

### New capabilities

- `external-recipient-opt-out`: OpenRegister asks integriq before it mails an address outside Nextcloud.

## Impact

- `lib/Service/Flow/FlowMessagingService.php`, `lib/Service/Flow/Nodes/SendEmailNode.php`
- `lib/Service/Party/PartyNotificationService.php`, `lib/Service/Notification/AnnotationNotificationDispatcher.php`, `lib/Service/Notification/NotificationAnnotationValidator.php`
- `lib/Service/Notification/EmailSender.php` gains optional headers.
- A new public `lib/Service/Notification/UnsubscribeHeaders.php`, which sibling apps resolve from OpenRegister (they already depend on it, ADR-083).
- A new `lib/Service/Notification/OptOutAuthority.php` holds the guarded dispatch, so both paths share one fail mode.
- Dependent apps: dossiq records flow mail through `FlowEmailSentListener`. A skipped address raises no `FlowEmailSentEvent`, so dossiq records nothing for it. opencatalogi and softwarecatalog do not use these paths.
- Notifications to Nextcloud users (`field`, `users`, `groups`, `role`) do not change.

## Reuse

ADR-011 check: OpenRegister has no email or phone format in `lib/Formats/`. Normalisation is integriq's job, inside its listener. Nothing is duplicated.

## Rollback

`IAppConfig` key `openregister.outbound_optout_check` (default `true`). Off restores today's behaviour on both paths.

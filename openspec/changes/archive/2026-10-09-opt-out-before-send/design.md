# Design: ask integriq before mailing an external address

The fleet contract, the category list, the fail mode and Ruben's decisions of 2026-10-05 live in hydra's `openspec/changes/opt-out-before-send/design.md` (ConductionNL/hydra#739). This file covers OpenRegister. Lines are at `development` `910e3717`.

## 1. Why an event and not a service

OpenRegister cannot depend on integriq. It already talks to integriq through integriq's events, named as strings "so OpenRegister stays installable without integriq" (`lib/Service/Connection/ConnectionReporter.php:51-63`). It checks `class_exists()` first (`:280`) and dispatches typed (`:322`). This change copies that shape. It references no integriq class in a type, a `use` statement or a class header, so autoload never fails (ADR-083).

## 2. One shared seam

A new `OptOutAuthority` service in `lib/Service/Notification/` owns the question:

```php
ask(string $channel, string $category, list<string> $addresses, string $correlationId): array<string, array{send:bool, code:string, unsubscribe:?array}>
```

- It builds `OCA\Integriq\Event\OutboundSendDecisionRequestedEvent` by string, with `sourceApp: 'openregister'`.
- Class missing, event unhandled, or listener throws: every address gets `send: false`, code `authority-unavailable`, and a warning is logged with the category and the address count (decision 1). An exempt category gets `send: true` and no link. The exempt floor is the fleet constant: `besluit`, `statutory`, `account`, `security`.
- The config key `openregister.outbound_optout_check` (default `true`) turns the seam off. It is a security-relevant key (ADR-102): any value other than `false` reads as on.

Both send paths call this one service, so they cannot drift apart on the fail mode.

## 3. The flow step

`sendToAddresses()` (`lib/Service/Flow/FlowMessagingService.php:596`) loops the addresses (`:607`). The rate limiter runs first per address (`:609`).

- **Before the loop**: one `ask()` for all addresses, with the step's `messageCategory`.
- **In the loop**: an address with `send: false` goes into a new bucket and is skipped. `opted-out` and `no-consent` go into `optedOut`. `authority-unavailable` goes into `authorityUnavailable`. The rate limiter is not consumed for a skipped address.
- **A skipped address raises no `FlowEmailSentEvent`** (the `announce()` call after a dispatch, `:618`). So dossiq's `FlowEmailSentListener` records nothing for it.
- **The body**: a non-exempt mail gets integriq's link line appended. The headers go through `EmailSender`.

The step config gains `messageCategory`:

- `SendEmailNode::configKeys()` (`lib/Service/Flow/Nodes/SendEmailNode.php:125-126`) adds `messageCategory`.
- `validateConfig()` (`:142`) refuses a value outside the fleet list, with a clear message.
- `configForm()` (`:176`) adds a select with the eight categories. The default is `service`.
- The field is not called `category`. `SendEmailNode::getCategory()` (`:256`) already returns the palette category, and a config key of the same name would read as the same thing.

## 4. The party path

`PartyNotificationService::notifyParties()` (`lib/Service/Party/PartyNotificationService.php:107`) loops recipients. A party indicator refusal comes first (`:110`). A party with no address comes next.

- **Before the loop**: collect the addresses, one `ask()`.
- **In the loop**: after the indicator check and the no-address check, a `send: false` gives outcome `refused-opted-out` or `authority-unavailable`, beside the existing `refused-by-indicator`.
- `notifyParties()` takes a new optional `category` argument. `AnnotationNotificationDispatcher` passes the rule's `messageCategory` (`lib/Service/Notification/AnnotationNotificationDispatcher.php:3218-3223`).
- `NotificationAnnotationValidator` accepts `messageCategory` on a rule and refuses an unknown value with `notification-bad-message-category`. The existing codes, such as `notification-bad-channel` (`lib/Service/Notification/NotificationAnnotationValidator.php:493`), show the shape.

## 5. Headers: one shared helper

`EmailSender::sendToAddress()` (`lib/Service/Notification/EmailSender.php:135`) builds an `IMessage` and calls `setPlainBody()`. `IMessage` has no header setter. pipelinq solved this behind `method_exists($message, 'getSymfonyEmail')` (`pipelinq/lib/Service/Marketing/Transport/InstanceMailerTransport.php:154`).

Ruben decided that OpenRegister owns one helper for this (2026-10-05, decision 6). So:

- A new `OCA\OpenRegister\Service\Notification\UnsubscribeHeaders` with `apply(IMessage $message, array $unsubscribe): bool`. It takes integriq's unsubscribe material, sets `List-Unsubscribe: <oneClickUrl>` and `List-Unsubscribe-Post: List-Unsubscribe=One-Click` when the message exposes `getSymfonyEmail()`, and returns whether it could.
- When it cannot, it logs once at debug and returns false. The caller still sends: the body link is always there.
- It is a public OpenRegister service. dossiq and pipelinq already hold OpenRegister as a hard dependency (ADR-083), so they inject it. pipelinq's own `applyHeaders()` delegates to it.
- `sendToAddress()` gains an optional `?array $unsubscribe = null` and calls the helper.

## 6. A defect fixed in this change

A party mail's body is its subject. `dispatchToParties()` (`lib/Service/Notification/AnnotationNotificationDispatcher.php:3184`) takes only `subject`. Its caller passes `$broadcastSubject` (`:533-539`). It then calls `notifyParties()` with `body: $subject` (`:3221`). So a party gets the subject twice and never the rule's message.

The fix: the caller resolves the rule's `message` the way it resolves the subject, and passes it as a new `body` argument. When the rule has no `message`, the body falls back to the subject, which is today's behaviour. The unsubscribe line is appended after the body.

## 7. Out of scope

- Notifications to Nextcloud users through `field`, `users`, `groups`, `role` and `object-acl` recipients (`lib/Service/Notification/NotificationRecipientResolver.php:187-201`). They need `userExists()` and follow user preferences.

## 8. Performance

One `ask()` per step run or per notification rule fire, in chunks of 500 inside integriq. A flow step with ten thousand addresses makes twenty queries in integriq and no HTTP calls.

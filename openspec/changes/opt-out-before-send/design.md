# Design: ask integriq before mailing an external address

The fleet contract, the category list, the fail mode and the open decisions live in hydra's `openspec/changes/opt-out-before-send/design.md` (ConductionNL/hydra#739). This file covers OpenRegister. Lines are at `development` `910e3717`.

## 1. Why an event and not a service

OpenRegister cannot depend on integriq. It already talks to integriq through integriq's events, named as strings "so OpenRegister stays installable without integriq" (`lib/Service/Connection/ConnectionReporter.php:51-63`). It checks `class_exists()` first (`:280`) and dispatches typed (`:322`). This change copies that shape. It references no integriq class in a type, a `use` statement or a class header, so autoload never fails (ADR-083).

## 2. One shared seam

A new `OptOutAuthority` service in `lib/Service/Notification/` owns the question:

```php
ask(string $channel, string $category, list<string> $addresses, string $correlationId): array<string, array{send:bool, code:string, unsubscribe:?array}>
```

- It builds `OCA\Integriq\Event\OutboundSendDecisionRequestedEvent` by string, with `sourceApp: 'openregister'`.
- Class missing, event unhandled, or listener throws: every address gets `send: false`, code `authority-unavailable`. An exempt category gets `send: true` and no link. The exempt floor is the fleet constant: `besluit`, `statutory`, `account`, `security`.
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

## 5. Headers

`EmailSender::sendToAddress()` (`lib/Service/Notification/EmailSender.php:135`) builds an `IMessage` and calls `setPlainBody()`. `IMessage` has no header setter. pipelinq solved this behind `method_exists($message, 'getSymfonyEmail')` (`pipelinq/lib/Service/Marketing/Transport/InstanceMailerTransport.php:154`).

`sendToAddress()` gains an optional `array $headers = []`. When the message exposes `getSymfonyEmail()`, it sets them. When it does not, it logs once at debug and sends without them. The body link is always there, so the person can always unsubscribe.

This is the helper hydra's open decision 6 asks about. When Ruben agrees, dossiq and pipelinq can call it instead of keeping their own copy.

## 6. Out of scope

- Notifications to Nextcloud users through `field`, `users`, `groups`, `role` and `object-acl` recipients (`lib/Service/Notification/NotificationRecipientResolver.php:187-201`). They need `userExists()` and follow user preferences.
- The `body: $subject` argument in the party dispatch (`AnnotationNotificationDispatcher.php:3221`). It looks like the body is set to the subject. It is not part of this change and is reported in the PR body.

## 7. Performance

One `ask()` per step run or per notification rule fire, in chunks of 500 inside integriq. A flow step with ten thousand addresses makes twenty queries in integriq and no HTTP calls.

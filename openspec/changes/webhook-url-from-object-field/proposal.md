---
kind: code
---

## Why

A notification rule's `webhook` channel posts to one fixed `webhook.url` per rule.
Some integrations subscribe per object instead: decidiq stores each subscriber's
callback on the decision itself (`outcomeCallbackUrl`), so one rule has to reach a
different URL for every decision (decidiq change
`decision-outcome-reaches-the-subscriber`, task 2). Without a way to name that
URL, the outcome never reaches the subscriber.

## What Changes

- A non-persistent `webhook` block MAY name `urlField`: the property of the object
  that fired which holds the URL. The dispatcher reads that property when the rule
  fires, trims it, and applies the checks a fixed `url` gets: a valid absolute URL,
  http or https only, posted through Nextcloud's HTTP client, which refuses local
  and private addresses, without following redirects.
- When the property is absent, empty, not a string or malformed, nothing is sent.
  The reason is logged, and the delivery is recorded as failed with that reason.
- The validator accepts `urlField` in place of `url` when it is a property name.
  A persistent webhook still needs a fixed `url`, because its Webhook entity is
  provisioned once, before any object exists.
- Rules without `urlField` behave exactly as before.

## Impact

- `lib/Notifications/Service/Notification/AnnotationNotificationDispatcher.php`
- `lib/Notifications/Service/Notification/NotificationAnnotationValidator.php`
- Their unit tests. No new class, route, schema or setting.

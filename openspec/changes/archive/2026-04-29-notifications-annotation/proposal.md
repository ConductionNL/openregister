# Notifications Annotation

## Problem
The implemented `notificatie-engine` already covers the heavy lifting (INotificationManager integration, channel dispatchers, user preferences, retry, throttling, audit). What's missing is a **declarative shortcut**: today an app must register a Webhook entity with payload mapping + an event listener + a notification rule + recipient resolver glue, all imperatively in PHP.

A schema annotation collapses that into one declarative block per notification, auto-registering the underlying Webhook + rule + listener at install time.

## Proposed Solution
Add the `x-openregister-notifications` schema annotation. On schema save, an installer hook reads each notification block and creates the corresponding Webhook entity (or updates an existing one keyed by notification name + schema id). The existing `notificatie-engine` machinery does all the actual delivery work; this change is a thin DSL on top.

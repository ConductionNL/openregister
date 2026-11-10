---
kind: code
depends_on: [openregister-notification-body]
---

## Why

Notification subjects/bodies interpolate `{{prop}}` from the triggering object's data. When `prop` is a **relation** field (e.g. `client`), the value is a UUID, so a notification reads "Incoming call from `bcc616d4-…`" instead of "Incoming call from Acme Gemeente BV". The UUID is meaningless to the recipient.

## What Changes

The dispatcher's `{{prop}}` interpolation now resolves a **UUID-shaped** field value to the related object's **display name** before substituting it. Non-UUID values pass through unchanged. Resolution goes through OpenRegister's `ObjectService::find()` (RBAC-scoped, mirroring the action-deeplink resolver), is cached per dispatcher instance, and falls back to the raw value when the object can't be resolved or has no name.

So `{{client}}` → "Acme Gemeente BV" in both the title (`subject`) and body (`message`).

## Capabilities

### Modified Capabilities

- `notificatie-engine` — `{{prop}}` interpolation resolves relation UUIDs to the related object's display name.

## Impact

- `lib/Service/Notification/AnnotationNotificationDispatcher.php` — `interpolate()` + new `resolveRelationDisplayName()` + a per-instance cache.
- Back-compat: non-UUID placeholders unchanged; unresolvable UUIDs keep the raw value; no ObjectService → no change.

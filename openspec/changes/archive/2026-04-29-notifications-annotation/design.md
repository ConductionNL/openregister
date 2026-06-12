# Design: Notifications Annotation

## Approach
A schema with `x-openregister-notifications` is a declarative request to wire up subscribers. On schema save, an installer creates / updates a Webhook entity per declared notification, with the existing `mapping` reference filled in for template rendering and the existing `events` array set to the event types implied by the trigger. The actual delivery is the existing `WebhookEventListener` + `WebhookDeliveryJob` + `Notifier` chain — no new dispatcher, no new channel adapters, no new throttle store.

## Files Affected
- `lib/Service/SchemaService.php` — schema-save validation gains `x-openregister-notifications` rules.
- `lib/Service/Notification/NotificationsAnnotationInstaller.php` — reads the annotation, creates/updates Webhook entities + INotificationManager rules, idempotent across schema reinstalls.
- `lib/Service/Notification/RecipientResolver.php` — translates declarative recipient kinds (`users`, `groups`, `field`, `relation`, `object-acl`) into the uid-list that `WebhookService::buildPayload` already produces. Most kinds delegate to existing services (NC `IGroupManager`, OR `RelationsService`, OR ACL).
- (existing) `lib/Service/WebhookService.php` — no change.
- (existing) `lib/Notification/Notifier.php` — no change.

## Out of scope
- Multi-step approval flows (workflow engine territory).
- Custom delivery channels not already in `notificatie-engine` (custom SMS providers, signed-payload variants).
- Opt-in/opt-out consent ladders per recipient (beyond the existing user preferences).

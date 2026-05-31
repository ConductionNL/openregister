# Design: Notificatie Engine

## Approach
Extend the existing partially-implemented spec with new requirements.

## Files Affected
- `lib/BackgroundJob/WebhookDeliveryJob.php`
- `lib/Cron/WebhookRetryJob.php`
- `lib/Listener/WebhookEventListener.php`
- `lib/Notification/Notifier.php`
- `lib/Service/NotificationService.php`
- `lib/Service/Webhook/CloudEventFormatter.php`
- `lib/Service/WebhookService.php`

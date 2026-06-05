# Retrofit — notificatie-engine

Maps 6 PHP methods from the notificatie-engine Bucket 2a cluster to the existing spec requirement for INotificationManager integration. No new REQs needed — the spec's first named requirement covers all observed behaviors.

## Affected code units
- lib/Notification/Notifier.php::getID
- lib/Notification/Notifier.php::prepare
- lib/Notification/Notifier.php::prepareConfigurationUpdate (private)
- lib/Service/NotificationService.php::notifyConfigurationUpdate
- lib/Service/NotificationService.php::sendUpdateNotification (private)
- lib/Service/NotificationService.php::markConfigurationUpdated

## Skipped
- src/views/account/sections/NotificationsSection.vue::save — @spec tag convention not established for Vue files

## Approach
- All methods implement the first named requirement: "The system MUST integrate with Nextcloud's INotificationManager for in-app notifications"
- Notifier implements INotifier contract for displaying configuration_update_available notifications with i18n
- NotificationService resolves recipients from groups, creates and sends Nextcloud notifications, and marks them as processed
- Spec status is `partial` — only configuration update notifications are currently implemented; the rest of the notificatie-engine spec is not yet implemented

Source: openspec/coverage-report.md generated 2026-04-23. See retrofit playbook.

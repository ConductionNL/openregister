## ADDED Requirements

### Requirement: An admin MAY opt a webhook into private/loopback targets via a per-hook flag

The webhook delivery pipeline MUST enforce its SSRF guard by default, rejecting
targets that resolve to loopback, RFC-1918, link-local (incl. cloud-metadata
169.254.169.254), or the IPv6 equivalents. An admin MAY opt an individual
`Webhook` entity out of the IP-range portion of this guard via a per-hook
boolean flag `allowPrivateTargets` (default `false`).

- The flag MUST default to `false`; existing webhooks MUST keep the current
  blocking behaviour with no migration of behaviour.
- The flag is per-hook; enabling it on one webhook MUST NOT affect any other
  webhook.
- When the flag is `true`, the SSRF guard MUST bypass the private/loopback
  IP-range checks for that hook at BOTH delivery time AND HTTP-redirect time, so
  a hook opted into private targets also follows private redirects.
- The http/https scheme restriction MUST remain enforced regardless of the flag.
- Setting the flag MUST be available only through the existing admin-gated
  webhook create/update endpoints; no additional instance-wide flag is
  introduced.

#### Scenario: Default webhook still blocks a private target
- **WHEN** a webhook with `allowPrivateTargets` absent or `false` is delivered to `http://localhost:8000`
- **THEN** the delivery MUST be rejected by the SSRF guard with a blocked-IP-range error

#### Scenario: Opted-in webhook delivers to a private IPv4 target
- **WHEN** a webhook with `allowPrivateTargets: true` is delivered to `http://localhost:8000`
- **THEN** the SSRF guard MUST allow the request to proceed

#### Scenario: Opted-in webhook delivers to a private IPv6 target
- **WHEN** a webhook with `allowPrivateTargets: true` targets an IPv6 loopback literal (`http://[::1]:8000`)
- **THEN** the SSRF guard MUST allow the request to proceed

#### Scenario: Opted-in webhook follows a private redirect
- **WHEN** a webhook with `allowPrivateTargets: true` receives a redirect whose `Location` resolves to a private/loopback address
- **THEN** the redirect re-validation MUST allow the redirect to be followed

#### Scenario: Non-http scheme is rejected even when opted in
- **WHEN** a webhook with `allowPrivateTargets: true` targets a non-http(s) scheme
- **THEN** the SSRF guard MUST still reject the request on the scheme check

#### Scenario: Admin sets the flag via the webhook endpoint
- **WHEN** an admin creates or updates a webhook with `allowPrivateTargets: true` via the admin-gated endpoint
- **THEN** the value MUST persist on the `Webhook` entity and be returned in its serialized form

## MODIFIED Requirements

### Requirement: The system MUST support multiple notification channels
Notifications MUST be deliverable via Nextcloud in-app notifications, push notifications (via notify_push), email (via n8n workflow), and outbound webhooks. Each channel MUST be independently configurable per rule. Outbound webhook delivery MUST enforce an SSRF guard that rejects loopback, RFC-1918, link-local, and equivalent IPv6 private targets by default, UNLESS the target `Webhook` entity has its per-hook `allowPrivateTargets` flag set to `true` by an admin, in which case the IP-range checks MUST be bypassed for that hook at delivery AND redirect time while the http/https scheme restriction remains enforced.

#### Scenario: Deliver in-app notification
- **WHEN** a notification rule with channel `in-app` and recipient user `behandelaar-1` fires
- **THEN** a Nextcloud notification MUST appear in the user's notification panel via `INotificationManager::notify()`
- **AND** clicking the notification MUST navigate to the object detail view

#### Scenario: Deliver push notification via notify_push
- **WHEN** a notification rule with channel `push` and recipient user `medewerker-1` fires and the `notify_push` app is installed
- **THEN** the system MUST create an `INotification` via `INotificationManager` (which notify_push automatically intercepts)
- **AND** the push notification MUST be delivered to the user's connected devices within 5 seconds
- **AND** if notify_push is not installed, the notification MUST still be delivered as a standard in-app notification

#### Scenario: Deliver email notification via n8n workflow
- **WHEN** a notification rule with channel `email` and recipient `user@example.nl` fires and an n8n workflow `notification-email-sender` is configured
- **THEN** the system MUST trigger the n8n workflow via webhook with payload containing `to`, `subject`, `body` (HTML), and `objectUrl` (deep link)
- **AND** the email MUST include a link back to the object in the OpenRegister UI

#### Scenario: Deliver webhook notification
- **WHEN** a notification rule with channel `webhook` and URL `https://external-system.example.test/hooks/intake` fires
- **THEN** the system MUST delegate to the existing `WebhookService::deliverWebhook()` with a payload containing `event`, `object`, `changed`, `timestamp`, and `register`/`schema` identifiers
- **AND** the webhook MUST include an `X-Webhook-Signature` HMAC-SHA256 header if a secret is configured

#### Scenario: Webhook to a private target is blocked by default
- **WHEN** a notification rule targets a webhook whose URL resolves to a private/loopback address and `allowPrivateTargets` is `false`
- **THEN** the delivery MUST be rejected by the SSRF guard and logged as a delivery failure

#### Scenario: Channel-specific failure isolation
- **WHEN** a notification rule with channels `["in-app", "email", "webhook"]` fires and the webhook endpoint returns HTTP 503
- **THEN** the in-app notification MUST still be delivered successfully
- **AND** the email MUST still be delivered successfully
- **AND** the webhook failure MUST be logged and retried independently

### Requirement: Notification delivery MUST be reliable with retry and dead-letter handling
Failed notification deliveries MUST be retried with configurable backoff strategies. Permanently failed notifications MUST be moved to a dead-letter queue for admin inspection. Retried webhook deliveries MUST apply the same SSRF policy as the initial attempt, including the target hook's `allowPrivateTargets` flag, so a retry of an opted-in hook MUST reach a private target and a retry of a default hook MUST remain blocked.

#### Scenario: Webhook delivery failure and exponential retry
- **WHEN** a webhook notification to `https://external.example.test/hooks` fails with HTTP 503 and the retry mechanism activates
- **THEN** the system MUST retry using the webhook's configured `retryPolicy` (exponential, linear, or fixed)
- **AND** for exponential policy: retry after 2 minutes, then 4 minutes, then 8 minutes
- **AND** after `maxRetries` failed attempts, the notification MUST be marked as `failed` in the `WebhookLog`

#### Scenario: Retry of an opted-in webhook reaches a private target
- **WHEN** a webhook with `allowPrivateTargets: true` targeting `http://localhost:8000` is retried after a transient failure
- **THEN** the retry MUST apply the same flag and the SSRF guard MUST allow the request to proceed

#### Scenario: Dead-letter queue for permanently failed notifications
- **WHEN** a webhook notification has exhausted all retries (e.g., 5 attempts over 62 minutes) and the final retry fails
- **THEN** the notification MUST be moved to a dead-letter queue
- **AND** the admin MUST be able to view failed notifications with event data, target URL, failure count, last error message, and last attempt timestamp
- **AND** the admin MUST be able to manually retry or dismiss individual dead-letter entries

#### Scenario: In-app notification delivery failure logging
- **WHEN** `INotificationManager::notify()` throws an exception for user `broken-user`
- **THEN** the failure MUST be logged with the user ID, notification subject, and exception message
- **AND** delivery to other recipients MUST continue unaffected

#### Scenario: Retry does not duplicate already-delivered notifications
- **WHEN** a notification rule with channels `["in-app", "webhook"]` has the in-app notification succeed but the webhook fail, and the webhook is retried
- **THEN** the in-app notification MUST NOT be re-sent
- **AND** only the failed webhook delivery MUST be retried

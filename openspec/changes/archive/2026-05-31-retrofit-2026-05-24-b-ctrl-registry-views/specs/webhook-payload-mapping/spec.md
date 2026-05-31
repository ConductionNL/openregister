# Webhook Payload Mapping

## Purpose
Configurable webhook payload mapping plus the webhook management/delivery REST surface. This delta
documents the webhook delivery-log listing endpoints (`logs`, `allLogs`) on `WebhooksController`,
which complement the already-specified `logStats` health-monitoring endpoint.

## ADDED Requirements

### Requirement: Webhook delivery logs MUST be listable per-webhook and globally
`WebhooksController` MUST expose two delivery-log listing endpoints in addition to the
already-specified `logStats` endpoint: `GET /api/webhooks/{id}/logs` (per-webhook) and
`GET /api/webhooks/logs` (all webhooks). Both are `@NoAdminRequired` / `@NoCSRFRequired`, accept
`limit` (default 50) and `offset` (default 0) query parameters, and return `{ results, total }`.
The per-webhook endpoint MUST validate webhook existence first and the global endpoint MUST
support optional `webhook_id` and `success` filtering.

#### Scenario: List logs for a specific webhook
- **GIVEN** webhook ID `7` exists with delivery history
- **WHEN** `GET /api/webhooks/7/logs?limit=50&offset=0` is called
- **THEN** the response MUST return HTTP 200 with `{ "results": [...WebhookLog...], "total": <count> }`
- **AND** logs MUST be fetched via `WebhookLogMapper::findByWebhook(webhookId, limit, offset)`

#### Scenario: Logs for a non-existent webhook
- **GIVEN** no webhook exists with ID `999`
- **WHEN** `GET /api/webhooks/999/logs` is called
- **THEN** the response MUST return HTTP 404 with `{ "error": "Webhook not found" }`

#### Scenario: Log retrieval failure
- **GIVEN** the log mapper throws a non-`DoesNotExistException`
- **WHEN** the logs endpoint is called
- **THEN** the error MUST be logged and the response MUST return HTTP 500 with `{ "error": "Failed to retrieve webhook logs" }`

#### Scenario: List all logs with default total
- **WHEN** `GET /api/webhooks/logs` is called with no filters
- **THEN** the response MUST return paginated logs from `WebhookLogMapper::findAll(limit, offset)`
- **AND** `total` MUST be the count of all logs (unpaginated)

#### Scenario: Filter all logs by webhook_id
- **GIVEN** `GET /api/webhooks/logs?webhook_id=7`
- **WHEN** `webhook_id` is non-empty and not `"0"`
- **THEN** logs MUST be scoped to webhook `7` via `findByWebhook()` and `total` MUST reflect that webhook's full count

#### Scenario: Filter all logs by success status
- **GIVEN** `GET /api/webhooks/logs?success=false`
- **WHEN** `success` is one of `true`/`1`/`false`/`0`
- **THEN** the returned logs MUST be filtered to entries whose `getSuccess()` matches the boolean
- **AND** `total` MUST be recomputed against the success-filtered full set

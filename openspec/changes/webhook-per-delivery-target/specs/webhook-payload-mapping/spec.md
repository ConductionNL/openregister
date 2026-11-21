# webhook-payload-mapping

## ADDED Requirements

### Requirement: A per-target webhook delivers to the URL each delivery names

A webhook whose configuration sets `deliverPerTarget: true` and that has no owner
SHALL be a per-target webhook. `WebhookService::triggerWebhookForEvent()` SHALL enqueue
one delivery per per-target webhook subscribed to the event, to the URL the caller
names, and SHALL return the number enqueued. The request and its log row SHALL use
that URL, and the outbound address checks SHALL apply to it as to a fixed URL. An
ordinary dispatch SHALL skip a per-target webhook, and a delivery of a per-target
webhook that names no URL SHALL send nothing. A URL named for an ordinary or owned
webhook SHALL be ignored.

#### Scenario: a broadcast reaches the peer the caller names

- **GIVEN** an administrator's webhook for `opencatalogi.federation.broadcast` with `deliverPerTarget: true`
- **WHEN** `triggerWebhookForEvent('opencatalogi.federation.broadcast', $payload, 'https://peer.example/api/directory')` runs
- **THEN** one `WebhookDeliveryJob` is enqueued carrying that URL and the call returns 1
- **AND** the job posts the payload to `https://peer.example/api/directory` and logs that URL
- @e2e exclude {unit-tested in tests/Unit/Service/WebhookPerTargetDeliveryTest.php; the end-to-end path is opencatalogi's federation suite}

#### Scenario: a private target is refused

- **GIVEN** the same webhook
- **WHEN** a delivery names `http://169.254.169.254/latest/meta-data`
- **THEN** no request is sent and the delivery is recorded as failed
- @e2e exclude {unit-tested}

#### Scenario: an ordinary webhook cannot be redirected

- **GIVEN** a webhook without `deliverPerTarget`, or one owned by a user
- **WHEN** a delivery for it names another URL
- **THEN** the request goes to the webhook's own URL
- **AND** `triggerWebhookForEvent()` enqueues nothing for it
- @e2e exclude {unit-tested}

### Requirement: A retry goes to the same target

A failed per-target delivery SHALL be retried, by `WebhookRetryJob` and by the manual
retry route, to the URL on its log row. Every other webhook SHALL be retried to its own
URL as before.

#### Scenario: a failed broadcast is retried to its peer

- **GIVEN** a failed per-target delivery logged with `https://peer.example/x`
- **WHEN** the retry job runs
- **THEN** the next attempt goes to `https://peer.example/x` with the attempt number raised by one
- @e2e exclude {unit-tested}

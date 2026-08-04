## ADDED Requirements

### Requirement: Webhook delivery does not block the object-write response

Webhook delivery triggered by an object lifecycle event SHALL be performed
asynchronously via a background job, including the first delivery attempt. The
latency of an object create/update/delete response SHALL NOT depend on the
availability or response time of any webhook target.

#### Scenario: Slow webhook targets do not slow the write

- **WHEN** an object is created and N webhooks subscribed to the event are slow
  or unreachable
- **THEN** the write response returns promptly after enqueuing delivery
- **AND** its latency does not scale with N or with the webhook timeout

#### Scenario: Delivery still occurs with correct semantics

- **WHEN** the delivery job runs
- **THEN** the webhook is delivered with the same payload and signature as the
  synchronous path
- **AND** retry/backoff behaviour is unchanged

### Requirement: No webhook work when nothing subscribes

An object write SHALL NOT serialize a webhook payload or query webhook
subscriptions when no webhook matches the app/event. A cheap, cached check SHALL
short-circuit before any payload serialization.

#### Scenario: Zero-webhook instance does no webhook work per write

- **WHEN** an object is written on an instance with no webhooks configured
- **THEN** no payload is serialized for webhook purposes
- **AND** no per-write webhook-subscription query is executed

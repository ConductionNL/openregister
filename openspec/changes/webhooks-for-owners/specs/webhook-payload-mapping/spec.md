# webhook-payload-mapping

## ADDED Requirements

### Requirement: A person with the right may own webhook subscriptions

A webhook SHALL have an optional owner. A user holding the `webhook.own` action
right SHALL be able to create, list, read, update, test and delete webhooks they
own, and read their delivery logs, through `/api/webhooks`. They SHALL NOT see
or change any webhook they do not own; such a webhook SHALL answer 404. The
right SHALL be seeded to administrators only. A webhook without an owner SHALL
remain an administrator's.

#### Scenario: a project owner creates a subscription

- **GIVEN** an administrator who granted `webhook.own` to the group `planninq-owners`
- **AND** a project owner in that group who can read the planninq register and its task schema
- **WHEN** the project owner calls `POST /api/webhooks` with a URL, the object created and updated events, and `filters` naming the planninq register and task schema
- **THEN** the response is 201 and the webhook's `owner` is the project owner
- **AND** `GET /api/webhooks` for that owner lists only this webhook
- @e2e exclude {specified only; task 4.2 adds tests/e2e/ci/owned-webhooks.spec.ts}

#### Scenario: another user's webhook is not found

- **GIVEN** a second member of `planninq-owners`
- **WHEN** they call `GET /api/webhooks/{id}` for the first owner's webhook
- **THEN** the response is 404
- @e2e exclude {specified only; task 4.2 adds tests/e2e/ci/owned-webhooks.spec.ts}

#### Scenario: a user without the right is refused as today

- **GIVEN** a user who does not hold `webhook.own` and is not an administrator
- **WHEN** they call `GET /api/webhooks`
- **THEN** the response is 403
- @e2e exclude {specified only; task 2.1 adds WebhooksControllerTest, task 4.2 adds tests/e2e/ci/owned-webhooks.spec.ts}

### Requirement: An owned webhook is limited to object events in one scope

An owned webhook SHALL subscribe to one or more of the object created, updated
and deleted events, never to an empty event list, and SHALL name one register
and one or more schemas that its owner may read. It SHALL NOT intercept
requests or allow private targets. A refusal SHALL name the field.

#### Scenario: an owned webhook without a scope is refused

- **GIVEN** the project owner from above
- **WHEN** they call `POST /api/webhooks` with a URL and `events: []` and no `filters`
- **THEN** the response is 422 and its message names `events`
- @e2e exclude {specified only; task 2.2 adds OwnedWebhookValidatorTest, task 4.2 adds tests/e2e/ci/owned-webhooks.spec.ts}

### Requirement: An owned webhook delivers only what its owner may read

Delivery for an owned webhook SHALL re-read the record as its owner, with
object and property rules applied, and SHALL send that reading instead of the
event's full record. It SHALL NOT deliver a record the owner cannot read, SHALL
NOT send the previous version of an updated record, and SHALL send identifiers
only for a deleted record. When the owner is disabled, deleted or no longer
holds `webhook.own`, the webhook SHALL be disabled and SHALL deliver nothing.

#### Scenario: a hidden property never leaves

- **GIVEN** a task schema whose `budget` property the project owner may not read
- **AND** the project owner's webhook on that schema
- **WHEN** a manager updates a task's `budget` and `title`
- **THEN** the receiver gets a signed POST whose `newObject` carries the new `title` and no `budget`, and no `oldObject`
- @e2e exclude {specified only; task 4.2 adds tests/e2e/ci/owned-webhooks.spec.ts}

#### Scenario: a revoked right stops delivery

- **GIVEN** the project owner's webhook
- **WHEN** an administrator removes the owner from `planninq-owners` and a task is then updated
- **THEN** nothing is delivered and the webhook reads `enabled: false`
- @e2e exclude {specified only; task 3.2 adds WebhookDeliveryJobTest, task 4.2 adds tests/e2e/ci/owned-webhooks.spec.ts}

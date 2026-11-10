# integration-message-dispatch

## ADDED Requirements

### Requirement: A send may be scheduled and bound to an object

The send endpoints SHALL accept an optional `sendAt` in the future and an
optional `object`. With `sendAt` the leaf SHALL store the composed message
as `scheduled` and answer 202 with its id; a `sendAt` in the past SHALL be
refused with 400. Scheduled and sent messages of an object SHALL be listable
by a user with `read` on it, and a scheduled one SHALL be cancellable by its
author or a user with `update` on the object. Scheduling, sending,
cancelling and failing SHALL write an audit entry on the bound object.

#### Scenario: a letter goes out on Monday morning

- **GIVEN** a case and a handler on Friday
- **WHEN** the handler sends an e-mail with `sendAt` Monday 08:00 bound to the case
- **THEN** the case lists one `scheduled` message, and after the Monday sweep it reads `sent` with the provider response
- @e2e exclude {proposal only; task 3.1 adds tests/e2e/ci/scheduled-message.spec.ts when the list ships}

#### Scenario: a cancelled message never leaves

- **GIVEN** a scheduled message and its author
- **WHEN** the author cancels it before it is due
- **THEN** it reads `cancelled`, the sweep skips it and the object's audit trail records the cancellation
- @e2e exclude {cancel path, covered by service unit tests}

### Requirement: The scheduled sweep is bounded and sends once

A timed sweep SHALL claim due messages with a compare-and-set from
`scheduled` to `sending`, dispatch each through the dispatch provider,
record the response, and mark `sent` or `failed` with the cause. A degraded
dispatch SHALL be retried three times and then left `failed`. Batches SHALL
be capped per pass.

#### Scenario: two overlapping passes send once

- **GIVEN** one due message and two sweep passes started together
- **WHEN** both run
- **THEN** exactly one dispatch reaches the provider
- @e2e exclude {claim, covered by sweep unit tests}

### Requirement: E-mail is a channel of the dispatch leaf

The leaf SHALL expose `POST /api/integrations/email/send` over the seeded
source `smtp-mail`, accepting `{to, subject, text, html?, replyTo?}`, SHALL
mint an RFC `Message-ID` for every message and return it, and SHALL relay a
degraded source as 503 with `details.cause`.

#### Scenario: a sent e-mail carries a Message-ID

- **GIVEN** a configured `smtp-mail` source
- **WHEN** a client posts an e-mail
- **THEN** the response carries the `Message-ID` the leaf minted and the message left with that header
- @e2e exclude {backend send, verified against the endpoint with a mail sink, not a browser flow}

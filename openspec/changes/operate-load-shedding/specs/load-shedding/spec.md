# load-shedding

## ADDED Requirements

### Requirement: A failing dependency is not called while its breaker is open

Open Register SHALL keep a circuit breaker per dependency: the database, the LLM provider, each outside database source, each outbound HTTP host, and each webhook receiver host. A breaker SHALL open when, over the last 60 seconds, at least 10 calls were made, at least 5 failed, and failures were at least half of the calls. While open it SHALL refuse calls without contacting the dependency. After a cool-down of 30 seconds, doubling on each reopening up to 300 seconds, it SHALL let exactly one probe through, SHALL close when the probe succeeds and SHALL reopen when it fails. A 4xx other than 429 SHALL NOT count as a failure. The breaker state SHALL be shared by all PHP workers when a distributed memcache is configured.

#### Scenario: an unreachable outside database answers at once

- **GIVEN** schema `percelen` reads from an outside database source that stopped answering, and its breaker opened after five timeouts
- **WHEN** a caseworker opens the `percelen` list, which calls `GET /api/objects/kadaster/percelen`
- **THEN** the response is 503 within a second, with a `Retry-After` header and `dependency` `source`
- **AND** Open Register makes no connection attempt to that database
- @e2e exclude {specified only; task 5.2 adds tests/e2e/ci/load-shedding.spec.ts}

#### Scenario: a record on a dead source is not reported as missing

- **GIVEN** the same open breaker
- **WHEN** a caseworker opens one parcel with `GET /api/objects/kadaster/percelen/00000000-0000-0000-0000-000000000000`
- **THEN** the response is 503 and not 404
- @e2e exclude {specified only; task 5.2 adds tests/e2e/ci/load-shedding.spec.ts}

#### Scenario: the breaker closes after one good probe

- **GIVEN** the source's breaker is open and its cool-down of 30 seconds has passed
- **WHEN** two caseworkers load the list at the same moment and the database answers again
- **THEN** one request is the probe and succeeds, the other is refused with 503, and the next request after that passes
- @e2e exclude {specified only; timing behaviour, task 1.1 covers it in tests/Unit/Service/Resilience/CircuitBreakerTest.php}

### Requirement: A refusal names the dependency class and when to retry

A request refused by an open breaker or by database pressure SHALL answer 503 with `Retry-After` in seconds and an `application/problem+json` body whose `dependency` is one of `database`, `llm`, `source`, `outbound` or `webhook`. The body SHALL NOT name a host, a source's connection details or a credential.

#### Scenario: an anonymous visitor learns nothing about the outside system

- **GIVEN** a public schema whose objects come from an outside database source with an open breaker
- **WHEN** an anonymous visitor calls its public list endpoint
- **THEN** the response is 503 with `dependency` `source` and a `Retry-After`
- **AND** the body contains no host name, port or database name
- @e2e exclude {specified only; task 5.2 adds tests/e2e/ci/load-shedding.spec.ts}

### Requirement: Database pressure sheds only heavy routes

Open Register SHALL treat its database as under pressure when, over the last 60 seconds, at least 50 of its API requests were made and either a fifth took longer than 2 seconds or a tenth ended in a database error. Under pressure it SHALL refuse routes marked sheddable (exports, aggregations, GraphQL, bulk operations and report runs) with 503 and `Retry-After: 30`, and SHALL keep serving every other route. An administrator SHALL be able to turn pressure shedding off.

#### Scenario: an export waits while a caseworker keeps saving

- **GIVEN** the database is under pressure
- **WHEN** a data steward starts an export with `GET /api/objects/zaken/zaak/export` and a caseworker saves one case with `PUT /api/objects/zaken/zaak/{id}`
- **THEN** the export gets 503 with `Retry-After: 30` and `dependency` `database`
- **AND** the save succeeds with 200
- @e2e exclude {specified only; pressure needs a load fixture, task 3.1 covers it in tests/Unit/Middleware/LoadSheddingSheddableTest.php}

### Requirement: A webhook to a failing receiver waits for it

When the breaker for a webhook receiver's host is open, Open Register SHALL NOT send the delivery. It SHALL schedule it on the webhook retry job for after the cool-down, without counting a delivery attempt. A synchronous interception webhook to that host SHALL be skipped at once, and the request SHALL continue as it does today when that webhook fails.

#### Scenario: a failing receiver is not hammered

- **GIVEN** a webhook on `object.updated` whose receiver has failed ten deliveries in a minute
- **WHEN** caseworkers update 200 records in the next minute
- **THEN** the receiver gets no request until the cool-down ends
- **AND** the 200 deliveries are queued for retry with their attempt count unchanged
- @e2e exclude {specified only; task 2.4 covers it in tests/Unit/Service/WebhookServiceBreakerTest.php}

### Requirement: Administrators see and reset breakers

`GET /api/operations/dependencies` SHALL list every breaker with its class, key, state, calls and failures in the window, when it opened, when it next probes, and whether its state is shared or per worker. `POST /api/operations/dependencies/{key}/reset` SHALL close a breaker and write a `dependency.reset` audit row. Both SHALL be administrator-only. The operations console SHALL show the list with a reset button per open breaker. A breaker on a declared connection SHALL report `unavailable` to the connection registry when it opens and `configured` when it closes.

#### Scenario: an administrator resets a breaker after a fix

- **GIVEN** the `llm` breaker is open after the provider's outage
- **WHEN** a functional administrator opens the operations console, sees "LLM provider, open, next probe in 2 minutes" in the "Dependencies" section and presses reset
- **THEN** the breaker reads closed, the next chat request reaches the provider, and the audit trail holds a `dependency.reset` row naming the administrator
- @e2e exclude {specified only; task 5.2 adds tests/e2e/ci/load-shedding.spec.ts}

#### Scenario: a caseworker cannot reset a breaker

- **GIVEN** a signed-in caseworker who is not an administrator
- **WHEN** they call `POST /api/operations/dependencies/llm/reset`
- **THEN** the response is 403 and the breaker keeps its state
- @e2e exclude {specified only; task 5.2 adds tests/e2e/ci/load-shedding.spec.ts}

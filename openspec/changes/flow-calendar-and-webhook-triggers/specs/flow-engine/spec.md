## ADDED Requirements

### Requirement: A calendar trigger starts a flow at a date held on a record

The engine SHALL offer the trigger node type `openregister.trigger-calendar`. Its config names a register, a schema, a `date` or `date-time` property of that schema, an `offset` as an ISO 8601 duration (negative for before the date), and optionally a `workingCalendar`. For every record of the schema with a value in that property, the trigger SHALL queue one run at the date plus the offset, with the record as the run's subject.

When a `workingCalendar` is named, the offset SHALL be counted in that calendar's working time, and a moment that lands outside working hours SHALL move to the next opening. A changed date SHALL move the moment, a cleared date or a deleted record SHALL remove it, and one date value SHALL start at most one run. Moments that passed before the trigger was enabled SHALL NOT fire.

The run SHALL execute as the `runAs` declared on the node, as a schedule trigger does. A calendar trigger without a resolvable `runAs` SHALL be refused on save.

#### Scenario: A reminder a month before the contract ends
- **GIVEN** a calendar trigger on schema `contracten` with date property `einddatum` and offset `-P30D`
- **AND** a contract whose `einddatum` is 31 March 2027
- **WHEN** the clock reaches 1 March 2027
- **THEN** one run is queued with that contract as its subject
- **AND** no second run is queued for the same `einddatum`
- @e2e exclude clock-driven background job, covered by clock-controlled unit tests

#### Scenario: A moved end date moves the reminder
- **GIVEN** the trigger above and a contract whose reminder is due on 1 March 2027
- **WHEN** a user changes `einddatum` to 30 June 2027 before 1 March
- **THEN** no run is queued on 1 March 2027
- **AND** one run is queued on 31 May 2027
- @e2e exclude clock-driven background job, covered by clock-controlled unit tests

#### Scenario: A cleared date or a deleted record cancels the run
- **GIVEN** two contracts with a reminder due next week
- **WHEN** a user clears `einddatum` on the first and deletes the second
- **THEN** neither contract gets a run
- @e2e exclude clock-driven background job, covered by clock-controlled unit tests

#### Scenario: An offset counted in working days skips the weekend and a holiday
- **GIVEN** a calendar trigger with offset `-P5D` and the working calendar `gemeente-standaard`, on which Monday 5 April 2027 is a holiday
- **AND** a record whose date is Friday 9 April 2027
- **WHEN** the moment is computed
- **THEN** it is Thursday 1 April 2027 at the calendar's opening time, five working days before the date
- @e2e exclude date arithmetic, covered by unit tests on the working-time calculator

#### Scenario: Enabling a trigger does not fire for dates already past
- **GIVEN** a schema with three records whose moment lies in the past and one whose moment lies next week
- **WHEN** an author enables a calendar trigger on it
- **THEN** no run is queued for the three past moments
- **AND** the flow reports that 3 moments before enabling were skipped
- **AND** the fourth record gets its run next week
- @e2e exclude clock-driven background job, covered by clock-controlled unit tests

#### Scenario: A calendar trigger on a property that is not a date is refused
- **GIVEN** a calendar trigger whose `dateProperty` names the text property `omschrijving`
- **WHEN** the flow is saved
- **THEN** the save is refused with a message naming `omschrijving` and the date types it accepts
- @e2e exclude engine-internal config validation, covered by the node's unit tests

### Requirement: An incoming-webhook trigger starts a flow from a signed call by another system

The engine SHALL offer the trigger node type `openregister.trigger-webhook`. Saving the node SHALL give it an address `POST /api/flows/hooks/{token}`, where the token is random and names nothing about the flow, and a signing secret that is shown once and stored encrypted.

A call SHALL be accepted only when the header `X-OpenRegister-Signature` holds the HMAC-SHA256 of the raw body under the trigger's secret. The check SHALL run before anything is read from the body or queued. An accepted call SHALL queue one run with the parsed JSON body as its input, executing as the `runAs` declared on the node, and SHALL answer `202 Accepted` with the run's id. The body is data: no part of it is evaluated as an expression or a node config.

A call with a bad or missing signature, an unknown token, or a disabled flow SHALL be answered with the same `404 Not Found` and SHALL leave no run. A body over 1 MB SHALL be answered with `413`. A call repeating an `X-Delivery-Id` seen in the last 24 hours SHALL return the first call's run id and queue nothing. The route SHALL carry Nextcloud's rate limiter.

#### Scenario: A signed call starts a run
- **GIVEN** a flow with an incoming-webhook trigger whose `runAs` is the account `betalingen`
- **WHEN** the payment provider posts `{"order": "A-1042", "status": "paid"}` to the trigger's address with a valid signature
- **THEN** the answer is `202 Accepted` with the id of a new run
- **AND** the run's input is that body and the run executes as `betalingen`
- @e2e exclude a server-to-server call with no screen, covered by API tests

#### Scenario: A call with a wrong signature leaves no trace in the flow
- **GIVEN** the same flow
- **WHEN** a caller posts a body with a signature made under another secret
- **THEN** the answer is `404 Not Found`, the same as for an address that does not exist
- **AND** no run is queued
- @e2e exclude a server-to-server call with no screen, covered by API tests

#### Scenario: A repeated delivery returns the first run
- **GIVEN** a call with `X-Delivery-Id: 7f3a` that started run `R1` ten minutes ago
- **WHEN** the provider retries the same call with the same delivery id
- **THEN** the answer is `202 Accepted` naming `R1`
- **AND** no second run is queued
- @e2e exclude a server-to-server call with no screen, covered by API tests

#### Scenario: A disabled flow refuses calls
- **GIVEN** a flow with an incoming-webhook trigger that an author has disabled
- **WHEN** a correctly signed call arrives
- **THEN** the answer is `404 Not Found` and no run is queued
- @e2e exclude a server-to-server call with no screen, covered by API tests

#### Scenario: The secret is shown once and can be rotated
- **GIVEN** an author saving a new incoming-webhook trigger
- **WHEN** the save completes
- **THEN** the editor shows the address and the secret once
- **AND** a later read of the flow returns the address but not the secret
- **AND** after the author rotates the secret, a call signed with the old one is refused

## ADDED Requirements

### Requirement: A send-email step reaches an address only as far as the step allows

`openregister.send-email` SHALL accept, besides user and group ids, recipient
entries that are email addresses: a literal address, or a `{{ field }}` /
`{{ item.field }}` template whose value is an address, a list of addresses,
or objects carrying an `email` or `emailAddress` key.

The step SHALL declare an `externalRecipients` option with the values
`none` (default), `object` and `any`:

- `none`: every address SHALL be refused.
- `object`: an address SHALL be sent to only when it appears in the item's
  own fields, compared trimmed and case-insensitively.
- `any`: every syntactically valid address SHALL be sent to.

A malformed address SHALL be refused in every mode. Every refused address
SHALL appear in the run report's `refusedRecipients` bucket with a reason
(`external-recipients-off`, `not-on-item` or `invalid-address`); none SHALL
be dropped silently. User ids SHALL resolve exactly as before, and the
recipient's channel preference SHALL be checked for user ids only. Addresses
SHALL count toward the recipient bound and the rate limiter like users.

#### Scenario: The default refuses an address

- **GIVEN** a send-email step with no `externalRecipients` option
- **WHEN** its recipients include `citizen@example.org`
- **THEN** no email MUST be sent to that address
- **AND** the run report MUST list it under `refusedRecipients` with reason
  `external-recipients-off`
- @e2e exclude covered by FlowMessagingServiceExternalRecipientsTest

#### Scenario: The object mode sends only to addresses on the item

- **GIVEN** a send-email step with `externalRecipients` set to `object`
- **AND** an item whose `contacts` field holds `{ "email": "a@example.org" }`
- **WHEN** the recipients are `{{ contacts }}` and the literal `b@example.org`
- **THEN** an email MUST be sent to `a@example.org`
- **AND** `b@example.org` MUST be refused with reason `not-on-item`
- @e2e exclude covered by FlowMessagingServiceExternalRecipientsTest

#### Scenario: The any mode sends to a valid address and refuses a malformed one

- **GIVEN** a send-email step with `externalRecipients` set to `any`
- **WHEN** the recipients are `x@example.org` and `{{ contact }}` where the
  field holds `not an @ address`
- **THEN** an email MUST be sent to `x@example.org`
- **AND** the malformed value MUST be refused with reason `invalid-address`
- @e2e exclude covered by FlowMessagingServiceExternalRecipientsTest

#### Scenario: An unknown config value is refused at save time

- **GIVEN** a send-email step with `externalRecipients` set to `everyone`
- **WHEN** the configuration is validated
- **THEN** it MUST be refused with a message naming the accepted values
- @e2e exclude covered by SendMessagingNodesTest

### Requirement: Every sent email is announced to listeners

For each email that the channel sender reports as dispatched, the engine
SHALL dispatch one `OCA\OpenRegister\Event\FlowEmailSentEvent` through
`IEventDispatcher`, after the send. The event SHALL carry, through typed
getters: `getRegister()`, `getSchema()`, `getObjectUuid()` (null when the
item is not an object), `getRecipient()` (the address or the uid),
`getChannelKind()` (`user` or `external`), `getSubject()`, `getBody()` (the
rendered body), `getFlowId()`, `getRunId()`, `getStepName()` and
`getActingUser()`.

No event SHALL be dispatched for a send that was skipped, rate limited,
refused or failed. A listener that throws SHALL be logged and SHALL NOT fail
the step, because a retried step would send the email twice.

The run context SHALL carry the run's flow id under `flowId`.

#### Scenario: One event per delivered email

- **GIVEN** a send-email step addressing user `bob` and, in `any` mode,
  `x@example.org`, for one item that is object `obj-1` in register `5`,
  schema `9`
- **WHEN** both sends succeed
- **THEN** exactly two `FlowEmailSentEvent`s MUST be dispatched
- **AND** one MUST carry recipient `bob` with channel kind `user`, the other
  `x@example.org` with channel kind `external`
- **AND** both MUST carry register `5`, schema `9`, object uuid `obj-1`, the
  rendered subject and body, the flow id, the run id, the step name and the
  acting user
- @e2e exclude covered by FlowMessagingServiceExternalRecipientsTest

#### Scenario: A failed send is not announced

- **GIVEN** a mailer that throws on send
- **WHEN** a send-email step runs
- **THEN** no `FlowEmailSentEvent` MUST be dispatched
- @e2e exclude covered by FlowMessagingServiceExternalRecipientsTest

### Requirement: A send-notification step reads role fields on the item

`openregister.send-notification` SHALL resolve a `{{ field }}` recipient
whose value is a uid, a list of uids, a list of objects carrying `uid` or
`userId`, or a single such object. Every resolved uid SHALL be verified as
an existing user; an unverified one SHALL be reported as unknown. For a
single object only its `uid` / `userId` / `user_id` SHALL be read.

#### Scenario: Role shapes on a case

- **GIVEN** an item with `handler` = `bob`, `handlerMembers` =
  `["carol", "alice"]`, `reviewers` = `[{ "userId": "bob" }]` and `owner` =
  `{ "uid": "carol", "displayName": "alice" }`
- **WHEN** a send-notification step addresses each field
- **THEN** it MUST notify the uids named and only those
- **AND** the single object's display name MUST NOT be read as a uid
- @e2e exclude covered by FlowMessagingServiceExternalRecipientsTest

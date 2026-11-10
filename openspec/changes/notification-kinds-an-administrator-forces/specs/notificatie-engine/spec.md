# notificatie-engine

## ADDED Requirements

### Requirement: A notification kind may declare a channel the recipient cannot switch off (REQ-NKF-001)

A notification declared in `x-openregister-notifications` MAY carry
`forcedChannels`, a list of channel kinds with a reason. The dispatcher
SHALL deliver on every forced channel regardless of the merged effective
preference for that recipient and kind. The effective-preferences read SHALL
report the kind as forced on those channels, naming the reason, rather than
offering a choice that has no effect. A forced channel that the notification
does not declare in its `channels` block SHALL be refused at schema save
with HTTP 422.

#### Scenario: a decision goes by letter whatever the handler prefers

- **GIVEN** a notification declaring `forcedChannels: [{kind: "email", reason: "statutory notice"}]` and a recipient whose preference for that kind is off
- **WHEN** the notification fires
- **THEN** it is delivered on the e-mail channel

#### Scenario: the preference screen says it is forced

- **GIVEN** the same notification
- **WHEN** the recipient reads their effective preferences
- **THEN** the kind is reported as forced on that channel with the reason
- @e2e exclude {preferences read, covered by unit tests}

#### Scenario: forcing a channel the rule does not have is refused

- **GIVEN** a notification whose `channels` block holds only `nc-notification` and whose `forcedChannels` names `email`
- **WHEN** the schema is saved
- **THEN** the save fails with HTTP 422 naming the channel
- @e2e exclude {annotation validator, covered by unit tests}

### Requirement: A notification kind may declare that it never leaves the organisation (REQ-NKF-002)

A notification MAY carry `internalOnly: true`. The dispatcher SHALL refuse
to deliver such a notification to a recipient that is not internal to the
organisation, SHALL record the refusal in the notification history with the
kind and the recipient, and SHALL deliver to the internal recipients of the
same rule. A notification declaring `internalOnly` together with a channel
that can only reach an external party SHALL be refused at schema save with
HTTP 422 naming the channel.

#### Scenario: an internal note does not reach the applicant

- **GIVEN** an `internalOnly` notification whose resolved recipients are one colleague and one external party
- **WHEN** it fires
- **THEN** the colleague receives it
- **AND** the external party does not

#### Scenario: the refusal is recorded, not swallowed

- **GIVEN** the same dispatch
- **WHEN** the notification history is read
- **THEN** it holds an entry naming the kind, the external recipient and the refusal
- @e2e exclude {history read, covered by unit tests}

#### Scenario: an impossible pairing is refused at save

- **GIVEN** an `internalOnly` notification declaring only a channel that reaches external parties
- **WHEN** the schema is saved
- **THEN** the save fails with HTTP 422 naming the channel
- @e2e exclude {annotation validator, covered by unit tests}

### Requirement: An administrator can read what one recipient will get for one kind (REQ-NKF-003)

The system SHALL answer, for a named recipient and a named notification
kind, which channels will be used, which layer decided each (schema default,
group default, user override, forced) and whether the kind is internal only.
The answer SHALL require the permission that reads other people's
preferences.

#### Scenario: why did this person not get the message

- **GIVEN** a recipient whose group default switched a kind to in-app only
- **WHEN** an administrator reads the answer for that recipient and kind
- **THEN** it names the in-app channel and the group as the deciding layer

#### Scenario: an ordinary user cannot read somebody else's answer

- **GIVEN** a caller without the permission
- **WHEN** they request the answer for another user
- **THEN** the request is refused
- @e2e exclude {authorization, covered by unit tests}

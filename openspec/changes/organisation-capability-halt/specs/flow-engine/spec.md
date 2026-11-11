## ADDED Requirements

### Requirement: An organisation's work in one app can be halted without halting anyone else

Open Register SHALL keep per-organisation halts, each naming the organisation, the app, a node-type prefix (default `<app>.`), a reason, the actor who engaged it and when. Engaging and releasing SHALL require a signed-in actor and SHALL be recorded on the audit trail. While a halt is engaged, a flow step whose node type starts with its prefix SHALL be refused for runs of that organisation, and SHALL run for every other organisation; a step whose run's organisation cannot be established SHALL be refused while a halt matches its node type. Open Register SHALL offer a read that answers, for an organisation, an app and optionally a node type, the halt that applies or none, so an app can stop work that does not run as a flow step.

#### Scenario: one organisation's agent work stops, another's continues

- **GIVEN** an administrator engaged a halt for organisation A and app `hermiq` with reason "Incident 42"
- **WHEN** a flow step of type `hermiq.agent-tick` is about to run for a run of organisation A, and one for a run of organisation B
- **THEN** the step of organisation A is refused with a reason naming "Incident 42"
- **AND** the step of organisation B runs
- **AND** a step of type `openregister.http` of organisation A runs
- @e2e exclude {flow oversight, no page; covered by OrganisationHaltTest}

#### Scenario: an app asks before work outside a flow

- **GIVEN** the same halt
- **WHEN** hermiq asks whether organisation A's `hermiq` work is halted
- **THEN** it gets the halt with its reason, actor and time
- **AND** after the halt is released it gets none, and the audit trail holds an engaged and a released row naming the actor
- @e2e exclude {service read, no page; covered by OrganisationHaltTest}

#### Scenario: a halt that could not be recorded properly is refused

- **GIVEN** no signed-in actor, or no reason, or no app, or an organisation that does not exist, or the same halt already engaged
- **WHEN** a halt is engaged
- **THEN** it is refused with a reason and nothing is stored
- @e2e exclude {refusal path; covered by OrganisationHaltTest and OrganisationHaltControllerTest}

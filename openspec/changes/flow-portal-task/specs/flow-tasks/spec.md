## ADDED Requirements

### Requirement: The external performer type is portal-scoped and never pooled

The performer model SHALL additionally accept `performer_type: external`
(ADR-098 Decision 3 as amended 2026-08-31): a party outside the Nextcloud
instance, referenced by a party reference that resolves to a portal
subject, never by a Nextcloud uid, group or role.

An external task SHALL always be ASSIGNED to its stored party reference at
creation. The verbs `claim`, `unclaim` and `delegate` SHALL be refused for
external tasks with an error naming the performer type: there is no
candidate pool to claim from and no mandate model for parties outside the
instance.

An external task SHALL NOT appear in any Nextcloud user's or group's inbox
query, SHALL NOT be counted in any inbox total, and SHALL NOT be projected
by the Nextcloud-facing notification or calendar projections. Its
visibility inside the instance is through the task's subject object
(tasks anchored to a case remain readable to the case's authorized
caseworkers); its visibility outside the instance is the portal seam
specified by `flow-portal-task`.

Completion authorization for an external task SHALL compare the acting
portal subject to the stored party reference and SHALL deny when the
comparison cannot be evaluated. The audit SHALL record performer type
`external` and the acting subject reference on every verb, exactly as it
records the other performer types.

#### Scenario: An external task is absent from every Nextcloud inbox

- **GIVEN** an external task anchored to a case
- **WHEN** any Nextcloud user requests their inbox
- **THEN** the task MUST NOT appear
- **AND** it MUST NOT be counted in the total
- @e2e exclude covered by TaskInboxService unit tests over an external task

#### Scenario: Claim and delegate are refused on an external task

- **GIVEN** an external task
- **WHEN** claim or delegate is called by any caller
- **THEN** the verb MUST be refused with an error naming the performer type
- @e2e exclude covered by TaskService verb unit tests

#### Scenario: The caseworker still sees the ask on the case

- **GIVEN** an external task anchored to a case and a caseworker authorized
  on that case
- **WHEN** the caseworker requests the tasks anchored to that object
- **THEN** the external task MUST appear with its delivery state
- @e2e the case detail lists the outstanding portal task for the caseworker

#### Scenario: The audit names the external performer

- **GIVEN** an external task completed by its matched portal subject
- **WHEN** the audit is read
- **THEN** the completion entry MUST record performer type `external` and
  the acting subject reference
- @e2e exclude covered by task-audit unit tests

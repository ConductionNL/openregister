## ADDED Requirements

### Requirement: Declarative deadline derived fields
OpenRegister SHALL expose deadline-tracking state on each case as declarative
`x-openregister-calculations` on the `dataSubjectRequest` schema, layered on the existing art-12
maths. The calculations SHALL include `daysRemaining` (whole days until the effective deadline),
`isOverdue` (boolean), and `escalationTier` (a tier derived from `daysRemaining`, e.g.
`on-track` / `reminder` / `escalation` / `breached`). The effective deadline SHALL be
`extendedUntil` when set, otherwise `dueAt`. The calculations MUST reuse the semantics of
`DataSubjectDeadline` (`computeDueAt`/`extend`/`isOverdue`/`daysRemaining`); they MUST NOT
introduce a second, divergent deadline implementation (ADR-011). The derived fields MUST be
available on every case read without a service round-trip.

#### Scenario: Days-remaining and overdue reflect the effective deadline
- **WHEN** a case has `dueAt` set (and no extension) and the reference date is before it
- **THEN** `daysRemaining` MUST be positive and `isOverdue` MUST be false

#### Scenario: Extension moves the effective deadline
- **WHEN** a case has an `extendedUntil` later than its `dueAt`
- **THEN** `daysRemaining` and `isOverdue` MUST be computed against `extendedUntil`, not `dueAt`

#### Scenario: Escalation tier is derived from days remaining
- **WHEN** the effective deadline has passed
- **THEN** `isOverdue` MUST be true and `escalationTier` MUST be `breached`

### Requirement: Case-count aggregations for the tracking surface
OpenRegister SHALL expose cross-case counts as declarative `x-openregister-aggregations` on the
`dataSubjectRequest` schema: counts of open cases, overdue cases, and breached cases. The
aggregations MUST be computed over the RBAC- and tenant-scoped object set — a case the caller
cannot read MUST NOT contribute to any count. The aggregations MUST NOT be implemented as an
app-local aggregation service that loops objects (ADR-031 anti-pattern).

#### Scenario: Open and overdue counts are available declaratively
- **WHEN** a steward requests the case-count aggregations for the register/schema
- **THEN** the response MUST include an open-case count and an overdue-case count derived from the cases' status and effective deadline

#### Scenario: Aggregations respect RBAC and tenant scope
- **WHEN** two tenants hold cases under the same schema
- **THEN** the counts returned to a caller MUST reflect only the cases that caller is authorised to read

### Requirement: Advance-reminder, escalation, and breach notifications
OpenRegister SHALL declare advance-reminder, escalation, and breach-detection notifications for
data-subject-request cases via `x-openregister-notifications` on the `dataSubjectRequest` schema,
using the canonical ADR-031 notification dialect (`scheduled` and/or `threshold` /
`calculatedChange` triggers over the deadline calculations and aggregations). Each notification
rule MUST fire once per condition per case (idempotent deadline-event semantics) rather than on
every scheduler tick, satisfying the ADR-047 "idempotent deadline-event audit" requirement via the
engine's fire-once-per-condition model. The rules MUST use the canonical dialect only; the obsolete
legacy notification dialect (singular `channel`/`recipient`, `idempotencyKey`,
`trigger.lifecycleEnter`) MUST NOT be used (gate-18). Recipient resolution SHALL target the case
`handler` (and MAY escalate to a configured officer role for the escalation/breach tiers).

#### Scenario: Advance reminder fires as the deadline approaches
- **WHEN** a case crosses the advance-reminder tier before its effective deadline
- **THEN** a reminder notification MUST be dispatched to the case handler exactly once for that tier

#### Scenario: Breach detection fires once when the deadline passes
- **WHEN** a case's effective deadline passes without the case being closed
- **THEN** a breach notification MUST be dispatched once
- **AND** the same breach MUST NOT re-notify on subsequent scheduler ticks

#### Scenario: Rules use the canonical notification dialect
- **WHEN** the notification rules are declared on the schema
- **THEN** they MUST use the canonical `x-openregister-notifications` dialect
- **AND** they MUST NOT use the obsolete legacy dialect fields

@e2e A case with a near deadline triggers a single reminder to its handler; after the deadline passes it triggers exactly one breach notification and does not duplicate on the next tick.

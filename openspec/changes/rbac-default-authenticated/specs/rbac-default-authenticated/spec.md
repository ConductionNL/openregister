# rbac-default-authenticated Delta: rbac-default-authenticated

**Status**: proposed
**Scope**: openregister

## Purpose

Absent authorization means AUTHENTICATED, never public. `public` becomes a
deliberate assignment that cannot be reached by omission. Implements the
fail-closed posture of ADR-005 for the one place it was inverted.

## MODIFIED Requirements

### Requirement: An unmarked schema MUST require an authenticated caller

When neither a schema nor its register declares authorization for an action,
access SHALL require a logged-in user. It SHALL NOT be granted to an anonymous
caller. The resolution order — schema, then register, then default — is
unchanged; only the default changes.

#### Scenario: An anonymous caller reads nothing from an unmarked schema

- **GIVEN** a schema with no authorization block, and a register with none
- **WHEN** an anonymous caller reads it in-process with `_rbac: true`
- **THEN** no rows are returned
- **AND** an AUTHENTICATED caller reading the same schema DOES get rows — both
  in one test, because a change that returned nothing to everybody would
  satisfy the first half and quietly break the fleet

#### Scenario: The default applies through a PublicPage controller

- **GIVEN** a leaf app's `#[PublicPage]` controller reading an unmarked schema
  through `ObjectService`
- **THEN** an anonymous request returns no rows
- **NOTE** this is the shape that is actually exposed today. The HTTP object
  API already refuses anonymous callers (measured: `total=0` anonymous vs
  `total=8` admin), so a test that only drives HTTP would pass BEFORE the
  change and prove nothing.

#### Scenario: Explicit authorization is untouched

- **GIVEN** a schema declaring any authorization rule
- **THEN** its behaviour is byte-identical to before this change
- **AND** this is asserted for a `public` rule, an `authenticated` rule and a
  named-group rule — the three shapes in fleet use

#### Scenario: The register-level fallback still resolves before the default

- **GIVEN** a schema with no authorization whose REGISTER declares one
- **THEN** the register's rule decides, and the new default is not reached

### Requirement: A refusal on the default MUST be observable

When access is refused because no authorization was declared — as distinct
from a declared rule denying it — OpenRegister SHALL record that fact once per
schema, naming the schema.

#### Scenario: The operator can find what the default refused

- **GIVEN** an app whose public surface depended on an unmarked schema
- **WHEN** it goes empty after this change
- **THEN** the log names the schema and says the refusal came from the absent
  authorization default
- **AND** the entry is emitted once per schema, not once per row — an
  unthrottled line here turns a busy list endpoint into a log flood, and the
  flood is what gets logging disabled

#### Scenario: A declared denial is not reported as a default refusal

- **GIVEN** a schema whose rule denies this caller
- **THEN** nothing is logged as a default refusal — conflating the two would
  make the audit useless in exactly the situation it exists for

## ADDED Requirements

### Requirement: The fleet's unmarked schemas MUST be audited before the default flips

Each app carrying schemas with no authorization SHALL state, per schema,
whether it is intended to be public. The audit SHALL be recorded in the repo.

#### Scenario: Every unmarked schema has a stated intent

- **GIVEN** the 504 unmarked schemas measured on 2026-08-15 across 15 apps
- **THEN** each is recorded as intended-public, intended-authenticated, or
  intended-restricted, with the intended-public ones given an explicit
  `public` rule BEFORE the default changes
- **AND** the record is a list of schema names, not a count — a count cannot
  be reviewed, and the review is the point

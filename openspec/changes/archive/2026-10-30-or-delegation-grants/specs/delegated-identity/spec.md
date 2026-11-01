## ADDED Requirements

### Requirement: An identity a principal does not hold MUST be authorized before it is used

`delegated-identity` establishes that a scope narrows to a named user and never
widens. It does not say who may name that user. This adds that half.

Establishing an acting identity that is not the principal's own MUST consult the
delegation grant store first, and MUST refuse when no live grant covers it. The
narrowing guarantee is unchanged — a grant permits a principal to act as somebody,
it never grants that somebody more than they already hold.

A scope where principal and acting identity are the same MUST NOT consult the
store at all.

#### Scenario: A grant permits, it does not amplify

- **GIVEN** a principal holding a grant to act as a user
- **WHEN** work runs under that grant and reaches something the acted-as user may
  not do
- **THEN** it is refused
- **AND** the grant does not raise what the acted-as user may do

#### Scenario: An ungranted scope is refused before the work runs

- **WHEN** a scope names an identity the principal holds no grant for
- **THEN** the callable does not execute
- **AND** the refusal names the principal and the identity, not a permission

### Requirement: Widening MUST be checked against the caller, never declared by the callee

Where an invocation chain carries an identity, a callee that declares a different
one MUST NOT thereby acquire it. The grant consulted MUST be the CALLER's.

This is what makes ADR-099's narrowing invariant enforceable rather than merely
stated: without it, a definition anyone may edit can name an identity and be
believed.

#### Scenario: A callee's declared identity does not override its caller's

- **GIVEN** work invoked with one acting identity that invokes a step declaring
  another
- **WHEN** the caller holds no grant for the declared identity
- **THEN** the step runs with the caller's identity, or is refused
- **AND** the declared identity is not adopted

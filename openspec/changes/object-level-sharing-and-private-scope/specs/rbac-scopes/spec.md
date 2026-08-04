## ADDED Requirements

### Requirement: The principal vocabulary includes `private`

The RBAC principal vocabulary SHALL include `private` alongside the existing
`public`, `authenticated`, `admin`, `user:<uid>` and group-name principals.

`private` differs from the others in kind: the existing principals answer "who
does this rule admit", while `private` declares "this object answers to nobody but
its owner, administrators, and whoever is invited on it". It is therefore resolved
against the OBJECT, not only against the schema's rule list.

Adding it SHALL NOT change how any existing principal resolves. A schema or object
that does not use `private` SHALL be decided exactly as before.

#### Scenario: Existing principals are unaffected

- **WHEN** a schema grants read to `public`, to `authenticated`, to a group, or to `user:<uid>`
- **THEN** each resolves exactly as it did before `private` existed

#### Scenario: An unknown principal still fails closed

- **WHEN** a rule names a principal that is not in the vocabulary
- **THEN** it admits nobody

### Requirement: A new principal or operator lands on every enforcement path at once

Any addition to the principal or conditional-operator vocabulary SHALL be
implemented in the single-object verdict path, the relation-path verdict, and BOTH
list-emitting paths, in the same change.

A principal or operator honoured by some paths and not others SHALL be treated as
an access-control defect rather than an inconsistency: over-filtering hides objects
a caller is entitled to, and under-filtering exposes objects they are not. This
requirement exists because two such divergences were found in one change — a dotted
dynamic token that resolved only on the single-object path, and a pseudo-group
admitted only by the list path.

Verdict parity SHALL be demonstrated against a live database, with a session
present, and the demonstration SHALL fail if either implementation is disabled.

#### Scenario: Parity is demonstrated, not assumed

- **WHEN** a new principal or operator is added
- **THEN** one fixture set is run through the single-object path and the list path
- **AND** the verdicts are compared to each other and to the expected verdict
- **AND** disabling either implementation makes the comparison fail

#### Scenario: The demonstration runs with a session

- **WHEN** verdict parity is demonstrated
- **THEN** it runs with an authenticated non-administrator session
- **AND** it does not rely on a context where the list path bypasses RBAC entirely

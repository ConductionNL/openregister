# auth-system

## ADDED Requirements

### Requirement: A token or Consumer may carry a grant narrower than its user

A personal API token and a Consumer SHALL accept an optional `grant` naming
registers, schemas, verbs from the ADR-010 set except `manage`, an optional
row condition in the RBAC match grammar and an optional `expiresAt`. The
permission handler SHALL intersect the grant with the user's rights on every
read, list, write and action, filtering lists at the SQL level. A grant
wider than the issuer's own rights SHALL be refused at issue time.

#### Scenario: a supplier reads only its own cases

- **GIVEN** a token with grant `{schemas: ["case"], verbs: ["read"], match: {"supplier": "@self.tokenSubject"}}`
- **WHEN** the token lists cases
- **THEN** only cases whose `supplier` equals the token's subject are returned and a `PUT` is refused with 403
- @e2e exclude {proposal only; task 4.1 adds tests/e2e/ci/scoped-token.spec.ts when the editor ships}

#### Scenario: manage cannot be granted

- **GIVEN** a user issuing a token
- **WHEN** the grant names `manage`
- **THEN** the issue is refused with 422
- @e2e exclude {validator, covered by unit tests}

#### Scenario: a shrunken user shrinks the token

- **GIVEN** a token granting `update` on schema `case` issued by a user who later loses `update`
- **WHEN** the token writes a case
- **THEN** the write is refused
- @e2e exclude {intersection, covered by PermissionHandler unit tests}

### Requirement: The effective grant is visible and writes name the token

`GET /api/whoami` SHALL report the effective grant of the calling principal,
and every audit entry written through a granted token or Consumer SHALL
carry the token or Consumer id as `actorVia`.

#### Scenario: an auditor sees which token wrote

- **GIVEN** a write made through token T
- **WHEN** the object's audit trail is read
- **THEN** the entry carries the user as actor and T as `actorVia`
- @e2e exclude {audit field, covered by unit tests}

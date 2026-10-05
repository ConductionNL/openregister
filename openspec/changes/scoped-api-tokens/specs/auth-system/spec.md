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

### Requirement: A token carries a required end date and is warned before it lapses (REQ-SAT-003)

Issuing a token SHALL require an end date, and a request without one SHALL
be refused. The end date SHALL be enforced at use. The holder SHALL be
warned before it lapses. Renewing SHALL be a deliberate act, recorded with
the actor and the new end date.

#### Scenario: a six week migration token does not last six years

- **GIVEN** an issue request with no end date
- **WHEN** it is submitted
- **THEN** it is refused, naming the requirement

#### Scenario: an expired token is refused

- **GIVEN** a token past its end date
- **WHEN** it is used
- **THEN** the call is refused

#### Scenario: the holder hears about it first

- **GIVEN** a token approaching its end date
- **WHEN** the warning period is reached
- **THEN** the holder is notified, naming the token and the date
- @e2e exclude {warning over time, covered by unit tests with a clock fixture}

### Requirement: A service account is a principal owned by a team (REQ-SAT-004)

An integration MAY hold a service account: a principal that carries grants
and tokens, is owned by a team rather than by a person, and cannot sign in
interactively. Its tokens SHALL follow every rule an ordinary token
follows, including the end date.

#### Scenario: an integration survives a leaver

- **GIVEN** a service account owned by a team, whose creator leaves
- **WHEN** the creator's account is disabled
- **THEN** the service account and its tokens keep working

#### Scenario: a service account cannot sign in

- **GIVEN** a service account
- **WHEN** an interactive sign-in is attempted with it
- **THEN** it is refused

### Requirement: A token carries its own rate limit, and outbound destinations are allowlisted (REQ-SAT-005)

A token MAY carry a rate limit set at issue. A call over the limit SHALL
be refused, naming the limit. Outbound destinations for webhooks and
automations SHALL be checked against an administered allowlist at save
time, and a destination outside it SHALL be refused then, not at delivery.

#### Scenario: one runaway integration does not take the balie down

- **GIVEN** a token with a rate limit
- **WHEN** it exceeds the limit inside the window
- **THEN** further calls are refused, naming the limit
- @e2e exclude {rate over time, covered by unit tests with a clock fixture}

#### Scenario: a bad destination fails while somebody is watching

- **GIVEN** an administered outbound allowlist
- **WHEN** a webhook is saved with a destination outside it
- **THEN** the save is refused, naming the allowlist

### Requirement: A machine write names its acting human when the instance or schema requires it (REQ-SAT-006)

When `api.requireActingHuman` is `on`, or the target schema declares `x-openregister-acting-human: required`, a create, update or delete made through a token, a Consumer, a service account or a system caller SHALL be refused with 403 `{error: "acting-human-required"}` unless the request names a user in `X-OpenRegister-Acting-User` and `DelegationResolver::resolve(principal, actingAs, now, scope)` returns an allowed verdict for the calling principal acting as that user. A named user without such a grant SHALL be refused with 403 `{error: "acting-human-not-delegated", actingUser}`. An accepted write SHALL record `actingUser` and `actorVia` (the token or Consumer id) on its audit row. A session user acting in their own name SHALL NOT be affected, and reads SHALL NOT be affected.

#### Scenario: an integration that names nobody is refused
- **GIVEN** `api.requireActingHuman` on and a Consumer with write rights on `publicatie`
- **WHEN** the integration creates a publication without `X-OpenRegister-Acting-User`
- **THEN** the response is 403 `acting-human-required` and nothing is stored

#### Scenario: an integration acting for a consenting officer is accepted and recorded
- **GIVEN** officer `m.jansen` granted the Consumer `zaaksysteem-koppeling` delegation to act for her
- **WHEN** the integration creates a publication naming `m.jansen`
- **THEN** it is stored and its audit row names `m.jansen` as acting user and the Consumer as `actorVia`

#### Scenario: naming someone who did not consent is refused
<!-- @e2e exclude Covered by PHPUnit ActingHumanGuardTest::testANamedUserWithoutAGrantIsRefused. -->

- **GIVEN** no delegation grant from `p.bakker` to the Consumer
- **WHEN** the integration writes naming `p.bakker`
- **THEN** the response is 403 `acting-human-not-delegated`

### Requirement: An administrator provisions, lists and revokes machine credentials with a named owner (REQ-SAT-007)

`POST /api/admin/machine-credentials` SHALL create a service account (REQ-SAT-004) with a required owner group, contact name, contact e-mail and purpose, and SHALL issue its token with a required grant and end date, returning the token once. `GET /api/admin/machine-credentials` SHALL list every service account token and every Consumer with owner group, contact, grant summary, expiry and last use. `DELETE /api/admin/machine-credentials/{id}` SHALL revoke it with immediate effect. All three SHALL be administrator only and SHALL write an administrative audit row. A service account SHALL be refused interactive sign-in. A page in the OpenRegister administration settings SHALL offer the same actions.

#### Scenario: a functional administrator issues and later revokes a credential for a supplier
- **GIVEN** an administrator on the machine credentials page
- **WHEN** they provision `leverancier-scan` owned by group `team-dip`, contact `servicedesk@leverancier.nl`, read and create on `document` until 2026-12-31, and later revoke it
- **THEN** the token is shown once, the list shows the owner, contact, grant and expiry, and after revoking a call with the token is refused with 401

#### Scenario: a leaver does not break the integration
<!-- @e2e exclude Covered by PHPUnit MachineCredentialServiceTest::testDisablingTheCreatorKeepsTheServiceAccountWorking. -->

- **GIVEN** a service account provisioned by an administrator who then leaves
- **WHEN** the administrator's account is disabled
- **THEN** the service account's token keeps working until its end date

#### Scenario: a service account cannot sign in
<!-- @e2e exclude Covered by PHPUnit ServiceAccountLoginTest::testInteractiveLoginIsRefused on the login hook. -->

- **GIVEN** a service account
- **WHEN** an interactive sign-in is attempted with its user id
- **THEN** it is refused

# auth-system

## ADDED Requirements

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

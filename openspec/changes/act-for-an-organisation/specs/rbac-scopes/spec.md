---
status: draft
---

# RBAC scopes: code may act for an organisation

## ADDED Requirements

### Requirement: App code MAY act for an organisation without a Nextcloud user
OpenRegister SHALL offer `ObjectService::runForOrganisation(string $organisationUuid, callable $operation)`. Inside the callable the named organisation MUST be the caller's organisation for every tenancy decision: the organisation filter, the `$organisation`, `$activeOrganisation` and `$organisation.uuid` tokens in read rules, and the organisation stamped on new objects. The context MUST be reachable only from PHP code; no request parameter, header or body field may open, close or change it. An empty or unknown organisation MUST be refused before the callable runs. The previous context MUST be in force again when the callable returns or throws, and nested contexts MUST apply the innermost organisation.

#### Scenario: The context names the caller's organisation
- **GIVEN** organisation `org-a` exists
- **WHEN** app code calls `runForOrganisation('org-a', $fn)` and `$fn` asks `OrganisationService::getActiveOrganisation()`
- **THEN** the answer MUST be `org-a`, whoever holds the Nextcloud session
- @e2e exclude {server-side primitive with no request surface; covered by PHPUnit}

#### Scenario: An unknown organisation is refused
- **WHEN** app code calls `runForOrganisation('no-such-org', $fn)`
- **THEN** an `InvalidArgumentException` MUST be thrown and `$fn` MUST NOT run
- @e2e exclude {covered by PHPUnit}

#### Scenario: The context ends with its callable
- **GIVEN** a context for `org-a` with a nested context for `org-b`
- **WHEN** the inner callable throws
- **THEN** `org-a` MUST be the context's organisation again, and after the outer callable no context MUST be active
- @e2e exclude {covered by PHPUnit}

### Requirement: Inside an organisation context the caller is anonymous
The callable MUST run as `runAsAnonymous()` runs it: the Nextcloud session user, an API token's grant and the trusted system scope MUST NOT apply. With RBAC on, only `public` rules MUST decide which rows are readable. `$user` tokens MUST resolve to nothing and deny.

#### Scenario: An administrator's session does not widen a portal read
- **GIVEN** a browser holds an administrator's Nextcloud session
- **WHEN** a portal request reads inside a context for `org-a`
- **THEN** the read MUST be decided as for an anonymous caller in `org-a`, not as the administrator
- @e2e exclude {covered by PHPUnit on the organisation scope and the RBAC handler}

### Requirement: Inside an organisation context the organisation filter always applies
Every read path (list, union search, facets, count, aggregation, export and the single-object read) MUST apply the organisation filter inside a context, also when the caller passes `_multitenancy: false`, when the schema switches multitenancy off, and when the caller's rules would waive it. The filter MUST admit the context's organisation, its parent chain and the shared master data holders of the table being read, and no other organisation. Rows with no organisation are admitted only where the register cascade admits them, as outside a context. A `public` rule MUST NOT lift the filter, and no rule, also not one marked `crossOrganisation: true`, MUST take the read across organisations.

#### Scenario: A public schema stays inside the organisation
- **GIVEN** schema `vacancy` has RBAC `{ "read": ["public"] }` and rows in `org-a` and `org-b`
- **WHEN** code reads the schema inside a context for `org-a`, on any read path
- **THEN** only `org-a`'s rows MUST be returned
- @e2e exclude {covered by the real-SQL tenancy probe}

#### Scenario: Switching multitenancy off does not reach across
- **GIVEN** rows in `org-a` and `org-b`
- **WHEN** code inside a context for `org-a` reads with `_rbac: false` and `_multitenancy: false`
- **THEN** only `org-a`'s rows MUST be returned
- @e2e exclude {covered by the real-SQL tenancy probe}

#### Scenario: A marked rule does not cross inside a context
- **GIVEN** schema `case` has `{ "group": "public", "match": { "status": "open" }, "crossOrganisation": true }`
- **WHEN** code reads the schema inside a context for `org-a`
- **THEN** open rows of `org-b` MUST NOT be returned
- @e2e exclude {covered by the real-SQL tenancy probe}

### Requirement: Writes inside an organisation context belong to that organisation
A new object written inside a context MUST be stamped with the context's organisation. A client-supplied `@self.organisation` MUST NOT override it. The owner MUST be the explicit owner the app passes, otherwise the system identity. An update inside a context MUST NOT find, and so MUST NOT change, an object of another organisation.

#### Scenario: A system-saved booking gets the employer's organisation
- **GIVEN** learniq saves a booking with `_rbac: false` on a public route inside a context for the employer's organisation `org-a`
- **WHEN** the booking is created
- **THEN** its `@self.organisation` MUST be `org-a`
- @e2e exclude {covered by PHPUnit on OrganisationService}

### Requirement: Audit rows name the organisation a context acted for
An audit row written inside a context MUST carry the context's organisation uuid in `resultSummary.organisationContext`, set before the row is inserted so the hash chain seals it. Its `user` MUST read as the system, as for every write without a Nextcloud user.

#### Scenario: A portal write is attributable
- **WHEN** an object is created inside a context for `org-a`
- **THEN** its audit row MUST carry `resultSummary.organisationContext = "org-a"` and `user = "System"`
- @e2e exclude {covered by PHPUnit on OrganisationContextAttribution}

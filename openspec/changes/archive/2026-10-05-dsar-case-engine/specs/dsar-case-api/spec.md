## ADDED Requirements

### Requirement: Case-management REST endpoints with correct auth posture
OpenRegister SHALL expose case-management REST endpoints for data-subject-request cases: create a
case, run a lifecycle transition, attach/trigger evidence harvest, apply a redaction, generate the
signed export bundle, download the bundle, and draft/finalise a denial. Every endpoint MUST be an
authenticated steward endpoint declaring `@NoAdminRequired` (plus `@NoCSRFRequired` only where a
client cannot supply a CSRF token, such as the one-time download) and MUST NOT be `@PublicPage`
(ADR-005). Every controller method MUST be registered in `appinfo/routes.php` and be reachable —
no orphan methods and no route entry pointing at a non-existent method (ADR-016, ADR-029). All
reads and writes MUST flow through `ObjectService` under RBAC + multitenancy.

#### Scenario: Every endpoint is authenticated and registered
- **WHEN** a case-management controller method is defined
- **THEN** it MUST declare an explicit auth annotation (`@NoAdminRequired`, never `@PublicPage`)
- **AND** it MUST have a matching entry in `appinfo/routes.php` targeting an existing method

#### Scenario: Anonymous access is rejected
- **WHEN** an unauthenticated caller invokes any case-management endpoint
- **THEN** the request MUST be rejected before the case is read or written

#### Scenario: Transition endpoint drives the declared lifecycle
- **WHEN** a handler invokes the transition endpoint with a declared transition (e.g. `assign`, `collectEvidence`, `draftDenial`, `finaliseDenial`)
- **THEN** the case status MUST change per the head's declared `x-openregister-lifecycle`
- **AND** the `finaliseDenial` transition MUST pass through the denial-finalise guard

### Requirement: Case-level access control layered on object RBAC
OpenRegister SHALL enforce a case-level access-control check on the case-management endpoints,
layered on top of OpenRegister object RBAC (ADR-022, ADR-023): a handler MAY act on cases assigned
to them (handler-scopes-own), and a configured officer role MAY override across cases. The check
MUST NOT widen access beyond what object RBAC already grants — it only further restricts a
broadly-authorised user to their own cases unless they hold the officer role. The check MUST fail
closed: if the officer-role determination is unavailable, access MUST be denied rather than skipped
(CWE-863 / OWASP A01).

#### Scenario: A handler acts only on their own cases
- **WHEN** a handler invokes a case-management endpoint on a case assigned to them
- **THEN** the action MUST be permitted (subject to object RBAC)
- **AND** the same handler invoking it on a case assigned to another handler, without the officer role, MUST be refused

#### Scenario: An officer overrides across cases
- **WHEN** a caller holding the configured officer role invokes a case-management endpoint on a case not assigned to them
- **THEN** the action MUST be permitted (subject to object RBAC)

#### Scenario: The access check fails closed
- **WHEN** the officer-role determination cannot be resolved for a caller
- **THEN** access MUST be denied rather than skipped

@e2e A handler creates a case, transitions it, and attaches evidence on their own case (allowed); attempting the same on another handler's case is refused; an officer performs the action across cases; all endpoints reject an anonymous caller.

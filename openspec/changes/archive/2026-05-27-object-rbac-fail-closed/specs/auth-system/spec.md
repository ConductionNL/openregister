## ADDED Requirements

### Requirement: Object write operations MUST fail closed for anonymous callers

Creating or updating an object MUST be denied for an anonymous caller (no resolved Nextcloud user) unless the target schema's `authorization` explicitly grants the `public` group the requested write action. A schema with no `authorization` block, or no entry for the action, MUST NOT permit anonymous writes by default. Authenticated callers are out of scope of this requirement (their write authorization is governed by the existing schema RBAC rules).

#### Scenario: Anonymous write to a schema with no authorization rule is denied
- **GIVEN** a schema with no `authorization` block (or no `create`/`update` entry)
- **WHEN** an anonymous caller sends `POST`/`PUT /api/objects/{register}/{schema}`
- **THEN** the request MUST be rejected with HTTP 403
- **AND** no object is created or modified

#### Scenario: Anonymous write to a schema that declares public write is allowed
- **GIVEN** a schema whose `authorization` grants the `public` group the `create` action
- **WHEN** an anonymous caller sends `POST /api/objects/{register}/{schema}`
- **THEN** the request MUST be allowed (the schema opted in to public submissions)

#### Scenario: Authenticated write behaviour is unchanged
- **GIVEN** an authenticated user
- **WHEN** they create or update an object
- **THEN** the authorization outcome MUST be identical to before this change (this requirement only constrains anonymous writes)

### Requirement: SQL/list RBAC match evaluation MUST fail closed on unresolved dynamic variables

When a schema `authorization` rule carries a `match` clause referencing a dynamic variable (e.g. `$organisation`, `$userId`, `$now`) that resolves to `null` for the current principal, the SQL/list-path evaluator MUST treat that match property as unsatisfiable (emit an impossible predicate, `1 = 0`) rather than dropping it. The list path and the single-object find path MUST produce identical authorization verdicts for the same rule and principal.

#### Scenario: Multi-condition match with a null dynamic variable denies on both list and find
- **GIVEN** a read rule `{ "group": "public", "match": { "name": "X", "organisation": "$organisation" } }`
- **AND** a principal for whom `$organisation` resolves to `null`
- **WHEN** the principal lists objects (`GET /api/objects/{register}/{schema}`) and fetches the single object (`GET /api/objects/{register}/{schema}/{uuid}`)
- **THEN** BOTH requests MUST deny access to the object (the unresolved `organisation` predicate is not silently dropped on the list path)

#### Scenario: Rules whose dynamic variables resolve are unaffected
- **GIVEN** the same rule and a principal for whom `$organisation` resolves to a concrete value
- **WHEN** the principal lists and finds objects
- **THEN** access is granted exactly as before — the fail-closed change introduces no new denials for resolvable rules

#### Scenario: Single-condition match parity is preserved
- **GIVEN** a single-condition `match` rule on a dynamic variable that resolves to null
- **WHEN** evaluated on the list and find paths
- **THEN** both paths MUST deny (the SQL path no longer differs from the PHP path)

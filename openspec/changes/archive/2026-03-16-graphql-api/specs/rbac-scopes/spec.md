## ADDED Requirements

### Requirement: GraphQL operations MUST enforce schema-level RBAC identically to REST
The PermissionHandler MUST be called for all GraphQL queries and mutations with the same action mapping as REST endpoints.

#### Scenario: GraphQL read maps to REST read authorization
- **WHEN** a GraphQL query requests data from schema `vertrouwelijk`
- **THEN** `PermissionHandler::checkPermission()` MUST be called with action `read`
- **AND** the same `authorization.read` groups MUST be evaluated as for `GET /api/objects/{register}/{schema}`

#### Scenario: GraphQL mutations map to corresponding CRUD actions
- **WHEN** a `createMelding` mutation is executed
- **THEN** `PermissionHandler::checkPermission()` MUST be called with action `create`
- **AND** `updateMelding` MUST check `update` and `deleteMelding` MUST check `delete`

#### Scenario: Conditional authorization with organisation matching in GraphQL
- **WHEN** schema `dossiers` has authorization `{ "read": [{ "group": "behandelaars", "match": { "_organisation": "$organisation" } }] }`
- **AND** user queries dossiers from a different organisation
- **THEN** those dossiers MUST be silently filtered out by `PermissionHandler::evaluateMatchConditions()`
- **AND** no GraphQL error MUST be raised (consistent with REST behavior)

#### Scenario: Admin bypass in GraphQL
- **WHEN** a user in the `admin` group queries any schema via GraphQL
- **THEN** all RBAC checks MUST be bypassed matching PermissionHandler's admin override

#### Scenario: Cross-schema authorization in nested GraphQL queries
- **WHEN** user can read `orders` but not `klanten`
- **AND** they query `order { title klant { naam } }`
- **THEN** `klant` MUST return `null` with a partial error at `["order", "klant"]` with `extensions.code: "FORBIDDEN"`
- **AND** the `title` field MUST still return data (partial success)

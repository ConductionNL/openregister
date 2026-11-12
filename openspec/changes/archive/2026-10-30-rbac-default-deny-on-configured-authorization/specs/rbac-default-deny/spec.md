## ADDED Requirements

### Requirement: A non-empty authorization block SHALL fail closed per action
When a schema's effective `authorization` block (its own block, or the cascaded register block when the schema has none) is **non-empty**, the RBAC engine SHALL deny any action that is not explicitly granted in that block, for every requester except the retained admin-group and object-owner bypasses. An action is "explicitly granted" only when the block contains a non-empty rule list for that action key (directly or via an expanded named role). The `public`/unauthenticated pseudo-group SHALL NOT be treated as a grant for an omitted action.

#### Scenario: Omitted write action on a read-only schema is denied for a non-member
- **GIVEN** schema `pand` has authorization `{ "read": ["public"] }` (non-empty, no `create`/`update`/`delete` keys)
- **AND** user `jan` is not in group `admin` and is not the owner of any object
- **WHEN** `jan` attempts to create an object in `pand`
- **THEN** the system MUST deny the request (HTTP 403 Forbidden at the API layer)
- **AND** the same denial MUST apply to `update` and `delete`

#### Scenario: Omitted write action is denied even for the public/unauthenticated requester
- **GIVEN** schema `pand` has authorization `{ "read": ["public"] }`
- **AND** the requester is unauthenticated (resolves only to the `public` pseudo-group)
- **WHEN** the requester attempts to create, update, or delete an object in `pand`
- **THEN** the system MUST deny the request
- **AND** the requester MUST still be able to read and list `pand` objects (because `read` is explicitly granted to `public`)

#### Scenario: Explicitly granted action on a non-empty block continues to be allowed
- **GIVEN** schema `vergunningen` has authorization `{ "read": ["public"], "create": ["behandelaars"] }`
- **AND** user `els` is in group `behandelaars`
- **WHEN** `els` creates an object in `vergunningen`
- **THEN** the system MUST allow the request

### Requirement: An empty authorization block SHALL preserve open default-allow
When a schema's effective `authorization` block is empty (null or `[]`, with no register cascade providing one), the RBAC engine SHALL preserve the classic default-allow behaviour and grant all actions. This change SHALL NOT alter behaviour for schemas without any authorization configuration.

#### Scenario: Schema with no authorization allows all CRUD
- **GIVEN** schema `n8n_workflows` has no `authorization` block and its register has none
- **WHEN** any authenticated user performs create, read, update, delete, or list
- **THEN** the system MUST allow every action (behaviour unchanged from before this change)

### Requirement: Admin-group and object-owner bypasses SHALL be retained
The fail-closed default SHALL NOT affect the admin-group bypass or the object-owner bypass. A requester in the `admin` group, and a requester who owns the specific object being acted upon, SHALL retain full access regardless of which actions the authorization block omits. These bypasses are independent of the groups listed in the authorization block.

#### Scenario: Admin retains write access on a read-only schema
- **GIVEN** schema `pand` has authorization `{ "read": ["public"] }`
- **AND** user `beheer` is in group `admin`
- **WHEN** `beheer` creates, updates, or deletes a `pand` object
- **THEN** the system MUST allow the action

#### Scenario: Object owner retains access to their own object on an omitted action
- **GIVEN** schema `aanvragen` has authorization `{ "read": ["behandelaars"] }` (no `update` key)
- **AND** object `aanvraag-1` is owned by user `mara`
- **WHEN** `mara` updates `aanvraag-1`
- **THEN** the system MUST allow the update because she is the object owner

### Requirement: The fail-closed default SHALL be enforced consistently across all RBAC paths
The fail-closed verdict for omitted actions on a non-empty authorization block SHALL be enforced identically by the single-object schema-level path, the row-level per-object path, and the SQL list/search path, so that single-GET checks, row-level checks, and list/browse endpoints agree on access. The SQL list path SHALL encode the denial using the existing impossible-predicate (`1 = 0`) deny-all rather than returning unfiltered rows.

#### Scenario: List endpoint hides rows for an omitted action consistently with single-object
- **GIVEN** schema `pand` has authorization `{ "read": ["public"] }`
- **AND** user `jan` is not admin and owns no `pand` objects
- **WHEN** `jan` requests a list/search filtered for a write-type RBAC action that is not granted
- **THEN** the SQL filter MUST resolve to the impossible predicate (`1 = 0`) so that no rows are returned
- **AND** a single-object check for the same omitted action MUST also deny

#### Scenario: Read and browse remain open when read is explicitly granted to public
- **GIVEN** schema `pand` has authorization `{ "read": ["public"] }`
- **AND** the list/search path enforces the `read` action (not a separate `list` action)
- **WHEN** any requester (including unauthenticated) lists or searches `pand` objects
- **THEN** the system MUST return the objects, because `read` is explicitly granted to `public`

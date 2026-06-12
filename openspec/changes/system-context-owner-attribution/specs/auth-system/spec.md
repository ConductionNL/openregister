## ADDED Requirements

### Requirement: Writes without an active user session MUST be attributed to a system identifier
When an OpenRegister object is created via `ObjectService::saveObject()` and `IUserSession::getUser()` returns `null` (cron job, queue worker, internal service call), the system MUST set `_owner` on the new row to a configured system identifier instead of leaving it empty. The identifier is read from the `openregister.systemUserId` app-config key. The default value is `__system__`, which Nextcloud's user-ID validator rejects for real user creation, guaranteeing the identifier cannot collide with any logged-in user.

#### Scenario: Cron-job write gets system owner
- **GIVEN** a background job (no `IUserSession` user) calls `ObjectService::saveObject(...)` on a register/schema it is allowed to write
- **WHEN** `SaveObject::prepareObjectForCreation()` runs
- **THEN** the persisted `ObjectEntity` MUST have `_owner = '__system__'` (or the configured `openregister.systemUserId` value)
- **AND** the object MUST still receive an `_organisation` value via the existing `OrganisationService::getOrganisationForNewEntity()` fallback to the default organisation

#### Scenario: Logged-in writes are unchanged
- **GIVEN** a user `alice` is in the active `IUserSession`
- **WHEN** `SaveObject::prepareObjectForCreation()` runs
- **THEN** `_owner` MUST be set to `alice` (NOT the system identifier)

#### Scenario: Operator can override the system identifier
- **GIVEN** an operator sets `openregister.systemUserId` to `cron-bot` via OCC or app-config
- **WHEN** a session-less write happens
- **THEN** the persisted row MUST have `_owner = 'cron-bot'`

### Requirement: System-owned rows MUST be visible to admin readers in the RBAC filter
Both `MagicRbacHandler::applyRbacFilters()` and `MagicRbacHandler::buildRbacConditionsSql()` MUST treat rows where `_owner` equals the configured system identifier as visible to:
- any user in the `admin` Nextcloud group (in addition to the existing full RBAC bypass for admins), AND
- any user in any group listed in `openregister.systemReaderGroups` (comma-separated, default empty).

For other users, system-owned rows MUST remain subject to the usual RBAC rule evaluation. The organisation/multitenancy filter is NOT modified — system rows MUST carry an `_organisation` and tenant boundaries hold independently of this carve-out.

#### Scenario: Admin lists call_log and sees system-written rows
- **GIVEN** a `call_log` row exists with `_owner = '__system__'` and `_organisation = <default-org-uuid>`
- **AND** admin user has the default-org-uuid as their active organisation
- **WHEN** admin GETs `/api/objects/openregister/api/objects/openconnector/call_log`
- **THEN** the response `total` MUST include the system-written row
- **AND** the row MUST appear in `results[]`

#### Scenario: Non-admin without reader group does not see system rows from other authorization rules
- **GIVEN** user `bob` is not in `admin` and not in any group listed in `openregister.systemReaderGroups`
- **AND** the schema's `authorization.read` rule does NOT grant `bob` access by group
- **AND** a row exists with `_owner = '__system__'`
- **WHEN** `bob` lists that register/schema
- **THEN** the response MUST NOT include the system-owned row (only rows matching `_owner = 'bob'` or the schema's group rules, exactly as before)

#### Scenario: Configured reader-group member sees system rows
- **GIVEN** `openregister.systemReaderGroups = "log-readers"` and user `carol` is in `log-readers`
- **AND** a row exists with `_owner = '__system__'` in carol's active organisation
- **WHEN** `carol` lists the schema
- **THEN** the system row MUST be visible

#### Scenario: Cross-organisation isolation still holds
- **GIVEN** admin-of-org-A and a system-owned row exists in org-B
- **AND** admin bypass is disabled (SaaS mode) so admin cannot see other orgs
- **WHEN** admin-of-org-A lists the schema
- **THEN** the row MUST NOT appear (the organisation filter excludes it before RBAC evaluates)

### Requirement: The system identifier MUST be discoverable via a single service method
A dedicated service method MUST expose the system identifier so that both `SaveObject` and `MagicRbacHandler` read the same value. The method MUST read `openregister.systemUserId` via `IAppConfig`, falling back to the constant default `__system__` when the key is unset or empty. The companion method MUST return the configured reader groups as a normalised `string[]` (trimmed, no empty entries).

#### Scenario: Default identifier when unset
- **GIVEN** `openregister.systemUserId` is unset
- **WHEN** the service method is called
- **THEN** it MUST return `__system__`

#### Scenario: Reader-groups parse
- **GIVEN** `openregister.systemReaderGroups = " log-readers , audit-readers ,, "`
- **WHEN** the reader-groups method is called
- **THEN** it MUST return `['log-readers', 'audit-readers']` (trimmed, empties removed)

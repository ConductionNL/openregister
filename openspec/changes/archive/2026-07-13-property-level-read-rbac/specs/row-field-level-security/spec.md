# Row and Field Level Security

## ADDED Requirements

### Requirement: writeOnly properties MUST never be returned on any read
A property declared `writeOnly: true` MUST be stripped from every read response for every caller, including admin, while remaining writable. This is the field-level read mechanism for secrets and tokens (standard JSON Schema / OpenAPI keyword) and is fully backward compatible: a property without `writeOnly` is returned exactly as before. It closes openregister#380 at the platform level so ADR-063 MCP tools inherit the redaction without per-dialect field projection.

#### Scenario: writeOnly property stripped from single get for admin and non-admin
- **GIVEN** schema `credential` has property `apiToken` with `writeOnly: true`
- **AND** object `cred-1` has `apiToken: "s3cr3t"` and `name: "prod"`
- **WHEN** admin reads `cred-1` via the object read path
- **THEN** the rendered response MUST contain `name` but MUST NOT contain `apiToken`
- **WHEN** a non-admin reads `cred-1`
- **THEN** the rendered response MUST NOT contain `apiToken`

#### Scenario: writeOnly property stripped from list responses
- **GIVEN** schema `credential` has property `apiToken` with `writeOnly: true`
- **WHEN** any user lists `credential` objects
- **THEN** no object in the list response MUST contain `apiToken`

#### Scenario: writeOnly property remains stored and writable
- **GIVEN** schema `credential` has property `apiToken` with `writeOnly: true`
- **WHEN** a user writes `apiToken: "new-secret"` to `cred-1`
- **THEN** the value MUST be persisted on the stored object
- **AND** a subsequent read MUST still omit `apiToken` from the response

### Requirement: Read-time field stripping MUST be fail-safe against caller field re-widening
Property-level read stripping (both `writeOnly` and property `authorization.read`) MUST be applied server-side after any caller-supplied `fields`, `extend`, or `unset` selection, so a caller can never re-surface a stripped property by naming it. Stripping MUST apply to single get, list, and nested/related object expansion, all of which flow through the single render choke point.

#### Scenario: fields query cannot re-surface a stripped property
- **GIVEN** schema `credential` has property `apiToken` with `writeOnly: true`
- **WHEN** a caller reads `cred-1` with `fields=apiToken`
- **THEN** the response MUST NOT contain `apiToken`

#### Scenario: Stripping applies to nested expanded objects
- **GIVEN** object `case-1` expands a related `credential` object via `_extend`
- **AND** the `credential` schema has a `writeOnly` property `apiToken`
- **WHEN** `case-1` is read with the relation expanded
- **THEN** the expanded `credential` MUST NOT contain `apiToken`

### Requirement: Read-time field stripping MUST bypass for trusted internal reads
Read-time field stripping MUST be bypassed when the render is invoked with `_rbac === false` or while `SystemOperationContext::isActive()` is true, mirroring `PermissionHandler::hasPermission()`. This guarantees the application's own service and repair-step reads receive the full object, including `writeOnly` secrets it needs to operate. Internal reads that use the raw entity (`ObjectEntity::getObject()`) never reach the render path and are inherently unaffected.

#### Scenario: Internal render with _rbac false returns the full object
- **GIVEN** schema `credential` has property `apiToken` with `writeOnly: true`
- **WHEN** an internal caller renders `cred-1` with `_rbac: false`
- **THEN** the rendered object MUST contain `apiToken`

#### Scenario: System operation context returns the full object
- **GIVEN** `SystemOperationContext::isActive()` is true
- **WHEN** `cred-1` is rendered
- **THEN** the rendered object MUST contain `apiToken`

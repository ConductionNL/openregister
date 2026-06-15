---
status: draft
---

# RBAC Scopes — Delta for Disable-Public-Inheritance Flag

This delta extends the existing `rbac-scopes` capability with an opt-out flag for the "logged-in users inherit `public` group rights" semantics. Adds an `inheritFromPublic` boolean (default `true`) at the authorization-block level of schemas and registers, plus a tenant-wide IAppConfig default. When `false`, authenticated users do NOT qualify for `public` rules; they qualify only via their own group memberships.

## ADDED Requirements

### Requirement: Schema and register authorization MUST accept an optional `inheritFromPublic` boolean

Schema and register authorization blocks MUST accept an optional `inheritFromPublic` field, boolean. The default value (when the field is absent or `null`) MUST be resolved via the cascade documented below. Existing schemas and registers that do not set the field MUST behave identically to before this change (default `true`).

#### Scenario: Schema authorization without inheritFromPublic preserves pre-change behaviour

- **GIVEN** a schema whose authorization block has no `inheritFromPublic` field
- **AND** the register's authorization block also has no `inheritFromPublic` field
- **AND** the tenant default IAppConfig key is unset
- **WHEN** RBAC checks run
- **THEN** the effective `inheritFromPublic` value is `true` (the pre-change behaviour)
- **AND** authenticated users qualify for `public` rules as they did before

#### Scenario: Schema sets inheritFromPublic explicitly

- **GIVEN** a schema whose authorization block contains `"inheritFromPublic": false`
- **WHEN** RBAC checks run
- **THEN** the effective value is `false` for that schema
- **AND** authenticated users do NOT qualify for `public` rules on that schema

#### Scenario: Schema authorization round-trip preserves the field

- **GIVEN** a schema saved with `"inheritFromPublic": false` in its authorization block
- **WHEN** the schema is fetched and re-serialised via `Schema::getAuthorization()`
- **THEN** the returned array contains `inheritFromPublic` with value `false`
- **AND** the JSON serialisation includes the field

### Requirement: The effective value of `inheritFromPublic` MUST be resolved via cascade

The cascade order MUST be: schema's authorization → register's authorization → tenant-wide `IAppConfig` key `openregister.rbac.inherit_from_public_default` → hard-coded `true`. The first explicitly-set value wins. `null` MUST be treated as "unset" (cascade falls through to the next level).

#### Scenario: Cascade falls back to register when schema has no value

- **GIVEN** a schema whose authorization has NO `inheritFromPublic` field
- **AND** the parent register's authorization has `"inheritFromPublic": false`
- **WHEN** the resolver is called for that schema
- **THEN** the resolved value is `false`

#### Scenario: Cascade falls back to tenant default when neither schema nor register sets it

- **GIVEN** schema and register without the field
- **AND** IAppConfig `openregister.rbac.inherit_from_public_default` is set to `false`
- **WHEN** the resolver is called
- **THEN** the resolved value is `false`

#### Scenario: Cascade falls back to hard-coded true when nothing is set

- **GIVEN** no schema, register, or tenant default is set
- **WHEN** the resolver is called
- **THEN** the resolved value is `true`

#### Scenario: Schema explicit value wins over register and tenant

- **GIVEN** schema sets `"inheritFromPublic": true`
- **AND** register sets `"inheritFromPublic": false`
- **AND** tenant default is `false`
- **WHEN** the resolver is called
- **THEN** the resolved value is `true` (schema wins)

#### Scenario: null is treated as "unset"

- **GIVEN** schema authorization contains `"inheritFromPublic": null`
- **AND** register sets `"inheritFromPublic": false`
- **WHEN** the resolver is called
- **THEN** the cascade continues past the schema; the resolved value is `false` (from register)

### Requirement: When `inheritFromPublic` is `false`, authenticated users MUST NOT qualify for `public` rules

When the resolved `inheritFromPublic` is `false`, the PHP-side `PermissionHandler::hasPermission` MUST NOT fall back to `hasGroupPermission(public, ...)` for authenticated users; it MUST return only the result of evaluating the user's own group memberships (plus owner / admin checks). Anonymous users see no behaviour change — the public-fallback path was never used for them in the first place.

The SQL-side filter (`MagicRbacHandler::applyRbacFilters` and `buildRbacConditionsSql`) MUST equivalently exclude `public`-grouped rules from contributing conditions for authenticated users. The simple-string `'public'` rule MUST NOT grant unconditional access to authenticated users when the flag is `false`. Conditional `{group: "public", match: ...}` rules MUST NOT add their match conditions to the WHERE clause for authenticated users when the flag is `false`.

#### Scenario: Authenticated user is denied when inheritance is off and only public has access

- **GIVEN** a schema with `inheritFromPublic: false` and authorization `read: [{group: "public", match: <some-match>}]`
- **AND** an authenticated user `alice` not a member of any group named in any rule
- **AND** an object that satisfies the public match
- **WHEN** alice attempts to read the object
- **THEN** access is denied
- **AND** the SQL filter excludes the object from listings for alice

#### Scenario: Anonymous user is granted when public match passes (regardless of flag)

- **GIVEN** the same schema as above
- **WHEN** an anonymous (unauthenticated) request reads the object
- **THEN** access is granted (the public match is satisfied)
- **AND** the SQL filter includes the object

#### Scenario: Authenticated user with explicit group membership is still granted

- **GIVEN** a schema with `inheritFromPublic: false` and authorization `read: [{group: "public", match: ...}, "editors"]`
- **AND** an authenticated user `bob` in the `editors` group
- **WHEN** bob attempts to read the object
- **THEN** access is granted (via the explicit `editors` rule)
- **AND** the SQL filter includes the object for bob

#### Scenario: Owner check is unaffected by the flag

- **GIVEN** a schema with `inheritFromPublic: false` and `read: [{group: "public", match: ...}]`
- **AND** an authenticated user `carol` who is the owner of an object
- **WHEN** carol attempts to read the object
- **THEN** access is granted via the owner shortcut, regardless of the flag

#### Scenario: Admin user is unaffected by the flag

- **GIVEN** a schema with `inheritFromPublic: false`
- **AND** an authenticated user in the `admin` group
- **WHEN** the admin reads any object on this schema
- **THEN** access is granted via the admin bypass, regardless of the flag

### Requirement: When `inheritFromPublic` is `true` (or unset), behaviour MUST be identical to before this change

For any schema, register, or tenant where the resolved `inheritFromPublic` is `true` (the default), authenticated users MUST continue to qualify for `public` rules exactly as they did before this change. The new code paths MUST NOT introduce any behavioural drift for schemas that don't opt out.

#### Scenario: Pre-change schema is unaffected

- **GIVEN** a schema with no `inheritFromPublic` field anywhere in its cascade
- **AND** an authenticated user
- **AND** an object satisfying a public match rule
- **WHEN** the user attempts to read the object
- **THEN** access is granted
- **AND** the result is identical to pre-change behaviour

### Requirement: The simple-string `'authenticated'` rule MUST be unaffected by the flag

The existing `'authenticated'` simple-rule string (recognised by `MagicRbacHandler::processSimpleRule` line 274-276) grants unconditional access to any logged-in user. This behaviour MUST be unchanged by `inheritFromPublic`. The flag concerns the `public` group only.

#### Scenario: 'authenticated' rule still grants access when public inheritance is disabled

- **GIVEN** a schema with `inheritFromPublic: false` and `read: ["authenticated"]`
- **AND** an authenticated user
- **WHEN** the user attempts to read
- **THEN** access is granted (via the `authenticated` rule, independent of the flag)

### Requirement: PHP-side and SQL-side enforcement MUST be identical

For any combination of (user state, flag value, authorization rules, object data), the result of `PermissionHandler::hasPermission` (per-object check) MUST agree with whether the SQL filter (`MagicRbacHandler::applyRbacFilters`) would include the object in a listing. The two layers MUST NOT diverge.

#### Scenario: Per-object check and listing filter agree across the four-state matrix

- **GIVEN** a schema with `read: [{group: "public", match: <m>}]`
- **AND** the four states: (anon, authenticated) × (inheritFromPublic true, false)
- **AND** an object satisfying the public match
- **WHEN** the per-object `hasPermission` check runs AND the listing endpoint runs
- **THEN** for each of the four states, the per-object check's boolean result matches the listing's include/exclude decision for that object

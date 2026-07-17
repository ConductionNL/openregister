# authorization-rbac Specification Delta

## ADDED Requirements

### Requirement: Schemas with no authorization block can be default-closed for writes

A schema that declares NO authorization block grants every write to every
authenticated caller. That default-open posture is the engine's historical
behaviour and SHALL remain the default.

An instance SHALL be able to opt out via the `rbac.enforce_default_closed`
IAppConfig flag (default `false`). When enabled, `create`, `update` and `delete`
SHALL be DENIED on a schema with no authorization block.

The flag SHALL NOT override the admin bypass, nor the object-owner bypass: an
owner retains write access to their own object. Read actions SHALL be unaffected
in either state.

While the flag is disabled, the engine SHALL emit a deprecation warning the first
time each write action reaches a schema with no authorization block, once per
schema per action. A warning per row is not compliant.

An unreadable flag SHALL resolve to `false`. This is a policy choice about the
ABSENCE of rules, not a resolution failure, and SHALL NOT be conflated with the
fail-closed contract for rules that exist.

#### Scenario: An authenticated non-admin cannot create under the flag

- **GIVEN** a schema with no authorization block
- **AND** `rbac.enforce_default_closed` is enabled
- **WHEN** an authenticated non-admin attempts `create`
- **THEN** the action is DENIED

#### Scenario: The default is unchanged on upgrade

- **GIVEN** a schema with no authorization block
- **AND** `rbac.enforce_default_closed` is unset
- **WHEN** an authenticated non-admin attempts `create`
- **THEN** the action is ALLOWED
- **AND** a deprecation warning is emitted once for that schema and action

#### Scenario: An owner keeps write access under the flag

- **GIVEN** a schema with no authorization block and the flag enabled
- **WHEN** the object's owner attempts `update` on their own object
- **THEN** the action is ALLOWED

#### Scenario: Reads stay open under the flag

- **GIVEN** a schema with no authorization block and the flag enabled
- **WHEN** an authenticated non-admin attempts `read`
- **THEN** the action is ALLOWED

#### Scenario: A schema that declares authorization is not governed by the flag

- **GIVEN** a schema whose authorization block grants `create` to a group the
  caller belongs to
- **AND** the flag is enabled
- **WHEN** the caller attempts `create`
- **THEN** the action is ALLOWED

### Requirement: Dynamic condition tokens resolve by dot-syntax on both evaluators

Authorization conditions SHALL resolve `$user.uid`, `$user.email`,
`$user.displayName` and `$organisation.uuid`.

A token bearing a recognised prefix (`$user.` / `$organisation.`) but an
unrecognised field SHALL resolve to null — which the matcher treats as a DENY —
and SHALL be logged. It SHALL NOT be compared as a literal string: a stored value
that happens to equal the token text SHALL NOT satisfy the condition.

Resolution SHALL be implemented identically on BOTH evaluators — the PHP path
(`ConditionMatcher`, find/single-object) and the SQL path (`MagicRbacHandler`,
list). A token supported on one evaluator only is NOT compliant: it makes list
and find disagree about the same object.

`$user.groups` is explicitly NOT supported. It resolves to an array, which the
SQL path cannot express as a scalar equality; it SHALL deny and log until both
paths can express it.

#### Scenario: A dotted user token authorizes its own user

- **WHEN** a condition compares a property against `$user.uid`
- **AND** the object's value equals the signed-in user's uid
- **THEN** the condition MATCHES

#### Scenario: An unknown token denies rather than matching a literal

- **WHEN** a condition compares a property against `$user.unknownThing`
- **AND** the object literally stores the string `"$user.unknownThing"`
- **THEN** the condition DENIES
- **AND** a warning naming the token is logged

#### Scenario: Bare tokens are unaffected

- **WHEN** a condition uses the bare `$user`, `$userId` or `$organisation` token
- **THEN** it resolves exactly as before this change

#### Scenario: The two evaluators agree

- **WHEN** a schema's authorization uses a dotted token
- **THEN** the SQL list path and the PHP find path reach the same verdict for the
  same object

### Requirement: Per-object authorization overrides the schema baseline for write actions

An object's `_authorization` SHALL be CONSUMED by the live permission decision.
Storing, hydrating and serializing the column is NOT compliance: a column that no
decision reads is dead storage, regardless of how faithfully it round-trips.

A non-empty per-object block SHALL override the schema/register baseline for the
action under evaluation, and SHALL NOT alter the verdict of any other action. An
empty block SHALL change nothing.

Overrides SHALL apply to `create`, `update` and `delete` only. A per-object
`read`/`list` override SHALL be IGNORED and logged: the SQL list path builds its
predicate from the schema before any row exists and cannot honour it, so
enforcing it on `find` alone would seal one path while the other leaked.

Per-object overrides SHALL NOT override the admin bypass.

A verdict for an object carrying a non-empty block SHALL NOT be served from the
per-request permission cache: the block is mutable within a request and the cache
key is stable.

#### Scenario: A sealed object denies the write

- **GIVEN** an object whose `_authorization` restricts `update` to `admin`
- **WHEN** an authenticated non-admin attempts `update` on that object
- **THEN** the action is DENIED
- **AND** the denial comes from the permission decision, not from serialization

#### Scenario: Sealing one action does not seal the others

- **GIVEN** a schema with no authorization block
- **AND** an object whose `_authorization` restricts only `update`
- **WHEN** an authenticated non-admin attempts `create`
- **THEN** the action is ALLOWED

#### Scenario: An object can be unlocked against a restrictive schema

- **GIVEN** a schema whose authorization restricts `update` to `admin`
- **AND** an object whose `_authorization` grants `update` to the caller's group
- **WHEN** the caller attempts `update` on that object
- **THEN** the action is ALLOWED

#### Scenario: A per-object read override is refused

- **GIVEN** an object whose `_authorization` restricts `read`
- **WHEN** any caller attempts `read`
- **THEN** the override is IGNORED and a warning is logged
- **AND** the schema's own read rules decide the verdict

#### Scenario: An empty block is backwards compatible

- **GIVEN** an object whose `_authorization` is empty
- **THEN** every verdict matches the schema baseline exactly

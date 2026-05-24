---
retrofit: true
---

# Spec delta: auth-system

**Status:** proposed
**Mode:** ADDED (extends the existing `auth-system` capability spec)
**Scope:** openregister
**Tier:** or-core-security
**Depends on:** existing `auth-system` capability (multi-auth resolution,
three-level RBAC hierarchy, multi-tenancy, CORS, security headers).

## Motivation (context for the new REQs)

The existing `auth-system` spec covers the multi-auth resolution
model, the three-level RBAC hierarchy, multi-tenancy bypass
detection, public-endpoint mechanics, and CORS. The 22 in-scope
methods scanned here implement five further sub-behaviours that
the existing spec leaves implicit:

1. **JWT payload time semantics.** `validatePayload` insists on
   `iat`, defaults `exp` to `iat + 1 hour` when absent, and rejects
   any token whose computed expiry is in the past against `now`.
   None of those defaults are pinned in the existing spec.
2. **OAuth2 Bearer is a session-trust check, not a token validator.**
   `authorizeOAuth` does not parse the bearer token at all. It
   accepts any `Bearer …` header on a request whose
   `IUserSession::isLoggedIn()` is already true. The existing spec
   block "OAuth2 token scopes MUST translate to RBAC verdicts" is
   aspirational; the observed behaviour is much weaker. This is
   captured verbatim — not silently fixed — per the security-critical
   retrofit guardrail.
3. **Basic-Auth + API-key allow-list parameters are dead code.**
   `authorizeBasic($header, array $users=[], array $groups=[])` does
   not consult the `$users` or `$groups` allow-list parameters. The
   trust decision is delegated entirely to
   `IUserManager::checkPassword()`. Similarly `authorizeApiKey`
   trusts whatever key→userId map the caller passes without any
   rate-limit hook.
4. **Property-RBAC rule dispatch + PATCH-unchanged short-circuit.**
   `PropertyRbacHandler` dispatches string-form rules (treated as
   group names) and object-form rules (treated as conditional
   rules with `group` + optional `match`), bypasses on admin,
   qualifies `public` and `authenticated` pseudo-groups, and
   short-circuits `getUnauthorizedProperties()` when the incoming
   property value `===` the existing value (so PATCHing an
   unchanged protected field is a no-op rather than a 403).
5. **RBAC admin Vue surface.** `RbacTable.vue::hasPermission`
   accepts both flat (`{create: [], read: [], …}`) and nested
   (`{register: {create: [], …}, …}`) authorization structures
   per entity type. `PermissionMatrix.vue::loadData` resolves the
   register and schema list on mount via the standard openregister
   stores.

This spec delta captures those five behaviours as testable
requirements. Code already exists for all of them — this is a
retrofit spec.

## ADDED Requirements

### Requirement: REQ-AUTH-100 — JWT payload time validation MUST require `iat`, default missing `exp` to `iat + 1 hour`, and reject expired payloads against the current time

`AuthorizationService::validatePayload(array $payload)` MUST throw
`AuthenticationException("The token has no time of creation")` when
the payload does not contain an `iat` claim. When `iat` is present
but `exp` is absent, the effective expiry MUST be computed as
`iat + 1 hour`. Validation MUST construct `$now = new DateTime()`
and compute `$exp->diff($now)->format('%R')`; an exclamation diff
of `'+'` (meaning `exp` is BEFORE `now`) MUST throw
`AuthenticationException("The token has expired")` with `iat`,
`exp`, and `time checked` timestamps in the details. The check
runs AFTER HMAC signature verification — payload time validation
is not a substitute for signature validation.

#### Scenario: Payload without `iat` is rejected before any clock comparison
- **GIVEN** a JWT whose decoded payload is `{"iss":"foo","sub":"x"}` (no `iat`)
- **WHEN** `validatePayload` is invoked
- **THEN** an `AuthenticationException` MUST be thrown with message `"The token has no time of creation"`
- **AND** details MUST be `{"iat": null}`

#### Scenario: Missing `exp` defaults to `iat + 1 hour`
- **GIVEN** a payload `{"iat": <now - 30 minutes>}` with no `exp` claim
- **WHEN** `validatePayload` is invoked
- **THEN** the computed expiry MUST be `iat + 1 hour` (i.e. `now + 30 minutes`)
- **AND** the call MUST return without throwing

#### Scenario: Expired payload throws with timestamp triple
- **GIVEN** a payload `{"iat": <now - 2 hours>, "exp": <now - 5 minutes>}`
- **WHEN** `validatePayload` is invoked
- **THEN** an `AuthenticationException` MUST be thrown with message `"The token has expired"`
- **AND** details MUST contain `iat`, `exp`, and `time checked` as unix timestamps

### Requirement: REQ-AUTH-101 — `AuthorizationService::authorizeOAuth` MUST gate on `Bearer`-prefix + `IUserSession::isLoggedIn()` only (observed; does NOT parse the bearer token)

The OAuth2 Bearer authorization path MUST verify that the
authorization header starts with `Bearer` and that
`$this->userSession->isLoggedIn()` already returns `true`. The
method MUST NOT parse, introspect, or otherwise validate the bearer
token string. Both `$users` and `$groups` allow-list parameters are
accepted but MUST NOT be consulted. This requirement documents the
**observed** runtime behaviour; the security implications are
called out in Notes.

> ⚠️ **Security drift:** The aspirational requirement "OAuth2 token
> scopes MUST translate to RBAC verdicts" in the main spec is not
> implemented in the AuthorizationService path. A request that
> arrives with a populated Nextcloud session (e.g. via session
> cookie or a prior Basic-Auth round on the same request) will
> pass `authorizeOAuth` for any `Bearer …` header content, including
> arbitrary or empty strings. Hardening (real token parsing, Consumer
> lookup, scope check) is tracked in the main spec's "Not implemented"
> block and SHOULD be raised as a follow-up issue against this
> capability.

#### Scenario: Non-`Bearer` header is rejected with method-not-allowed
- **GIVEN** a request with `Authorization: Basic …` reaching `authorizeOAuth`
- **THEN** an `AuthenticationException` MUST be thrown with message `"Invalid method"`
- **AND** details `reason` MUST read "The authentication method you are using is not allowed on this resource."

#### Scenario: `Bearer` header on a request without an active session is rejected
- **GIVEN** a request with `Authorization: Bearer foo` AND `userSession->isLoggedIn() === false`
- **THEN** an `AuthenticationException` MUST be thrown with message `"Not authorized"`
- **AND** details `reason` MUST read "The token you used has either expired or was not recognized as a valid token"

#### Scenario: `Bearer` header on a request with an existing session passes regardless of token content (observed)
- **GIVEN** a request with `Authorization: Bearer literally-anything` AND `userSession->isLoggedIn() === true` (e.g. session cookie was set by a prior call)
- **WHEN** `authorizeOAuth` runs
- **THEN** the method MUST return without throwing
- **AND** the bearer token string MUST NOT be parsed or matched against any Consumer record
- **NOTE:** This is the observed behaviour. The retrofit captures it verbatim; do NOT treat the scenario as the design target.

### Requirement: REQ-AUTH-102 — `authorizeBasic` and `authorizeApiKey` MUST delegate trust to Nextcloud user resolution; `authorizeBasic`'s `$users`/`$groups` allow-list parameters MUST be treated as dead code

The Basic-Auth authorization path MUST base64-decode the credentials after stripping the `Basic `
prefix, split on the first `:`, call
`IUserManager::checkPassword($username, $password)`, throw
`AuthenticationException("Invalid username or password")` when the
result is `false`, and otherwise call `userSession->setUser($user)`.
The `$users` and `$groups` parameters MUST NOT influence the
control flow — they are accepted for backward signature
compatibility only.

`AuthorizationService::authorizeApiKey(string $header, array $keys)`
MUST resolve the trust decision through the caller-supplied
`$keys` map: when the header value is not a key in the map, throw
`AuthenticationException("Invalid API key")`; when the mapped user
ID does not resolve via `IUserManager::get()`, throw the same
exception with the same message; otherwise call
`userSession->setUser($user)`. The two failure paths MUST produce
indistinguishable exception messages to avoid leaking whether the
key exists.

> ⚠️ **Security drift:** The `$users`/`$groups` parameter dead code
> on `authorizeBasic` means call sites that *think* they are
> restricting Basic-Auth to a subset of users/groups are not — every
> Nextcloud user with valid credentials passes regardless. Either
> wire up the allow-list (the call sites already provide values) or
> drop the parameters from the signature. Tracked as a hardening
> follow-up, not silently fixed here.

#### Scenario: Basic auth rejects bad credentials with generic message
- **GIVEN** `Authorization: Basic <base64('alice:wrong')>`
- **AND** `IUserManager::checkPassword('alice', 'wrong')` returns `false`
- **THEN** an `AuthenticationException` MUST be thrown with message `"Invalid username or password"`

#### Scenario: Basic auth allow-list parameters do not affect outcome
- **GIVEN** valid credentials for user `alice` and `authorizeBasic($header, users: ['bob'], groups: ['ops'])`
- **WHEN** `IUserManager::checkPassword` returns the `alice` user
- **THEN** `userSession->setUser($alice)` MUST be called (allow-list ignored)
- **NOTE:** Observed behaviour. The `$users`/`$groups` parameters are dead code.

#### Scenario: API-key failure modes are indistinguishable
- **GIVEN** an API-key request where the header is `sk_unknown`
- **AND** an API-key request where the header is `sk_known` but `keys['sk_known'] = 'deleted-user'` and `IUserManager::get('deleted-user')` returns `null`
- **THEN** both calls MUST throw `AuthenticationException("Invalid API key")` (identical message, identical empty details)

### Requirement: REQ-AUTH-103 — Property-level RBAC MUST dispatch string vs object rules, bypass for admin, qualify `public` / `authenticated` pseudo-groups, and short-circuit unchanged-value updates

`PropertyRbacHandler::checkRule(mixed $rule, …)` MUST dispatch as
follows:
- `is_string($rule)` → delegate to
  `userQualifiesForGroup($rule, $userGroups, $userId)`,
- `is_array($rule) && isset($rule['group'])` → delegate to
  `checkConditionalRule($rule, …)`,
- otherwise log a warning and return `false`.

`PropertyRbacHandler::userQualifiesForGroup(string $group, array $userGroups, ?string $userId)`
MUST return `true` when `$group === 'public'`, `true` when
`$group === 'authenticated' && $userId !== null`, and otherwise
`in_array($group, $userGroups, true)`. Note that `public`
qualification is unconditional — even an authenticated user
qualifies for `public` rules.

`PropertyRbacHandler::checkPropertyAccess` MUST bypass all rules
when the user's group list contains `'admin'`. When the schema has
no property authorization, or the property has none, or the
specified action has no rules configured, the method MUST return
`true` (open access by default).

`PropertyRbacHandler::isAdmin()` MUST return `false` for an
unauthenticated session and `in_array('admin', $userGroups, true)`
otherwise.

`PropertyRbacHandler::filterReadableProperties()` MUST iterate
schema properties that have authorization configuration, calling
`canReadProperty` for each property that exists on the object, and
`unset` properties that fail the check. Properties without
authorization config MUST pass through untouched.

`PropertyRbacHandler::getUnauthorizedProperties(Schema, object, incomingData, isCreate=false)`
MUST short-circuit when `$isCreate === false` AND the property
exists in both `$object` and `$incomingData` AND
`$incomingData[$prop] === $object[$prop]`. The short-circuit
prevents PATCH-with-echoed-protected-field operations from
returning 403 errors.

`PropertyRbacHandler::checkConditionalRule()` MUST return `false`
immediately when the user does not qualify for the rule's `group`,
`true` when the rule has no `match` clause, and on create
operations MUST call `ConditionMatcher::filterOrganisationMatchForCreate()`
to drop `_organisation` matches (no object exists yet to match
against).

> 🔍 **Observed-but-subtle:** the unchanged-value short-circuit on
> `getUnauthorizedProperties` uses strict equality (`===`). For
> scalar value-equality fields this is benign; for cases where
> knowing the value confers a side-effect (audit/ETag/replay
> detection) the short-circuit hides the attempted write.
> Captured here as observed; not silently changed.

#### Scenario: String rule resolves through `userQualifiesForGroup`
- **GIVEN** a rule `"redacteuren"` for a property `interneAantekening`
- **AND** the current user is in groups `["behandelaars", "redacteuren"]`
- **WHEN** `checkRule` is invoked
- **THEN** the method MUST delegate to `userQualifiesForGroup("redacteuren", …)`
- **AND** return `true`

#### Scenario: Conditional rule delegates to `checkConditionalRule`
- **GIVEN** a rule `{"group": "behandelaars", "match": {"_organisation": "$organisation"}}`
- **WHEN** `checkRule` is invoked
- **THEN** the method MUST delegate to `checkConditionalRule`

#### Scenario: Invalid rule shape returns false and logs a warning
- **GIVEN** a rule `42` (integer) or `{"groupWithoutGKey": "..."}` (object missing `group`)
- **WHEN** `checkRule` is invoked
- **THEN** the method MUST return `false`
- **AND** `LoggerInterface::warning` MUST be called with the rule in context

#### Scenario: `public` rule qualifies an authenticated user too
- **GIVEN** schema with `read: ["public"]`
- **AND** an authenticated user `alice` (not in `admin`)
- **WHEN** `userQualifiesForGroup('public', …)` is invoked
- **THEN** the method MUST return `true` (unconditional)

#### Scenario: PATCH-with-echoed-protected-field is a no-op, not a 403
- **GIVEN** an object with `bsn: "123"` (`bsn` is protected by group `bsn-geautoriseerd`)
- **AND** an incoming PATCH `{"bsn": "123", "naam": "Alice"}`
- **AND** the current user is NOT in `bsn-geautoriseerd`
- **WHEN** `getUnauthorizedProperties` runs with `isCreate=false`
- **THEN** the `bsn` field MUST NOT appear in the unauthorized-properties list
- **AND** the resulting array MUST be `[]`
- **NOTE:** strict (`===`) equality — type-coerced echoes (e.g. integer `123` vs string `"123"`) DO NOT short-circuit

#### Scenario: Create with no match clause short-circuits to true on the organisation field
- **GIVEN** a rule `{"group": "public", "match": {"_organisation": "$organisation"}}`
- **WHEN** `checkConditionalRule` runs with `isCreate=true`
- **THEN** `ConditionMatcher::filterOrganisationMatchForCreate()` MUST be called
- **AND** if the filtered match becomes empty, the method MUST return `true`

#### Scenario: Admin bypass at `checkPropertyAccess`
- **GIVEN** any user in group `admin`
- **WHEN** any `checkPropertyAccess` invocation occurs
- **THEN** the method MUST return `true` immediately after detecting `admin` in `$userGroups`
- **AND** no rule evaluation MUST occur

### Requirement: REQ-AUTH-104 — RBAC admin Vue surface MUST handle flat and nested authorization structures and load registers + schemas via the openregister stores on mount

`src/components/RbacTable.vue::hasPermission(groupId, action)` MUST
support two authorization-data shapes per entity type:
- **Flat shape** (e.g. `entityType === 'application'`):
  `this.authorization` is `{create: [], read: [], update: [], delete: []}`
  and is consulted directly.
- **Nested shape** (e.g. `entityType === 'organisation'`):
  `this.authorization[this.entityType]` is the wrapping object;
  the inner `{create, read, update, delete}` is consulted.

When neither shape applies the method MUST treat the entity as
having no rules and return `false` for every (group, action) pair.
When the action key is missing or its value is not an array, the
method MUST return `false`.

`src/views/settings/sections/PermissionMatrix.vue::loadData()` MUST
be invoked on `mounted()` and on every subsequent admin action
that changes register or schema authorization. Each call MUST:
- set `this.loading = true`,
- await `registerStore.refreshRegisterList()` and store the
  resulting list (preferring the call's `data` field, falling back
  to `registerStore.registerList`),
- await `schemaStore.refreshSchemaList()` and store likewise,
- log to `console.error` if either call throws but never re-throw,
- set `this.loading = false` in a `finally` block.

#### Scenario: Flat authorization is read directly
- **GIVEN** `entityType: 'application'` and `authorization: {read: ["alice"], create: []}`
- **WHEN** `hasPermission('alice', 'read')` is invoked
- **THEN** the method MUST return `true`

#### Scenario: Nested authorization is unwrapped by entity type
- **GIVEN** `entityType: 'organisation'` and `authorization: {organisation: {read: ["org-1"]}}`
- **WHEN** `hasPermission('org-1', 'read')` is invoked
- **THEN** the method MUST return `true`

#### Scenario: Missing action key returns false
- **GIVEN** any entityType with an authorization map that has no `delete` key
- **WHEN** `hasPermission(anyGroup, 'delete')` is invoked
- **THEN** the method MUST return `false`

#### Scenario: `loadData` survives store failures without throwing
- **GIVEN** `registerStore.refreshRegisterList()` rejects with an error
- **WHEN** `loadData` runs on mount
- **THEN** the rejection MUST be caught and logged via `console.error`
- **AND** `this.loading` MUST be `false` after the call settles
- **AND** the component MUST remain mountable (no unhandled rejection)

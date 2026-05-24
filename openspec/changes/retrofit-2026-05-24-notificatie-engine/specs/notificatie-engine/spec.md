---
retrofit: true
mode: extend
---

# Notificatie Engine — Reverse-Spec Delta (partial, pass 1 of N)

## Purpose

This delta extends `openspec/specs/notificatie-engine/spec.md` with five numbered requirements (REQ-101..REQ-105) that codify the observed behaviour of the annotation-driven notification dispatcher and its rate-limit / coalesce / idempotency surrounding services. The existing named requirements remain authoritative for the aspirational surface (the `NotificationRule` entity, multi-channel routing, user preferences, VNG compliance, etc.); this delta narrows in on the concrete pipeline behaviour the code actually encodes today.

## ADDED Requirements

### Requirement: REQ-101 — The dispatcher MUST orchestrate notifications through a schema-annotation pipeline

`AnnotationNotificationDispatcher::dispatch()` MUST read notification rules from the triggering object's schema annotation (`Schema::getConfiguration()['x-openregister-notifications']`), match each rule against the active trigger type, and fan out matching rules to the configured channels. Broadcast channels (`webhook`, `talk`) MUST be dispatched once per rule with the full recipient list in the payload; per-recipient channels (`nc-notification`, `email`, `activity`) MUST be dispatched once per resolved recipient. The dispatcher MUST be invoked from `AnnotationNotificationListener::handle()`, which subscribes to `ObjectCreatedEvent`, `ObjectUpdatedEvent`, and `ObjectTransitionedEvent` and translates each into the matching trigger string.

#### Scenario: Schema without a notification annotation produces no dispatches
- **GIVEN** an object whose schema's configuration does not declare `x-openregister-notifications`
- **WHEN** `AnnotationNotificationDispatcher::dispatch()` is invoked
- **THEN** the dispatcher MUST return without calling any channel emit method
- **AND** no history row MUST be recorded

#### Scenario: Rule with non-matching trigger type is skipped
- **GIVEN** a schema declaring a notification with `trigger.type = "created"`
- **AND** the dispatcher is invoked with trigger `"updated"`
- **THEN** the rule MUST NOT fire on any channel

#### Scenario: Transition trigger matches by action filter
- **GIVEN** a notification with `trigger.type = "transition"` and `trigger.action = "approve"`
- **WHEN** the dispatcher is invoked with trigger `"transition"` and `context.action = "approve"`
- **THEN** the rule MUST fire
- **AND** when invoked with `context.action = "reject"`, the rule MUST NOT fire
- **AND** when `trigger.action` is an array, the rule MUST fire if `context.action` is any element of that array

#### Scenario: calculatedChange trigger requires both old and new data
- **GIVEN** a notification with `trigger.type = "calculatedChange"` and `trigger.field = "score"`
- **AND** trigger.condition = `{"lt": 0.85}` and trigger.previously = `{"gte": 0.85}`
- **WHEN** the listener dispatches with `_newData.score = 0.80` and `_oldData.score = 0.90`
- **THEN** the rule MUST fire (boundary crossing detected)
- **AND** if either `_newData` or `_oldData` is missing from the context, the rule MUST NOT fire (fail-closed)

#### Scenario: ObjectUpdatedEvent emits both updated and calculatedChange triggers
- **GIVEN** an `ObjectUpdatedEvent` with both old and new object instances
- **WHEN** `AnnotationNotificationListener::handle()` processes it
- **THEN** the dispatcher MUST be invoked twice — once with trigger `"updated"` and once with trigger `"calculatedChange"` carrying `_newData` / `_oldData` in the context

#### Scenario: Broadcast channel fires once with recipient list
- **GIVEN** a rule with `channels: ["webhook"]` and three resolved recipients
- **WHEN** the rule fires
- **THEN** `emitWebhook()` MUST be invoked exactly once
- **AND** the JSON payload MUST contain the full recipient UID array under the `recipients` key

#### Scenario: Per-recipient channel fires once per recipient
- **GIVEN** a rule with `channels: ["nc-notification"]` and three resolved recipients
- **WHEN** the rule fires
- **THEN** `emitNotification()` MUST be invoked three times — once per recipient
- **AND** each call MUST receive that recipient's interpolated subject (locale-resolved)

#### Scenario: Numeric operator AND-logic
- **GIVEN** a `calculatedChange` `condition` map with two operators `{"lt": 1.0, "gt": 0.0}`
- **WHEN** `numericConditionMatches()` is invoked with value `0.5`
- **THEN** the result MUST be true (both operators hold)
- **AND** with value `1.5` the result MUST be false (the `lt` operator fails)
- **AND** with a non-numeric value, every ordering operator (`lt`, `lte`, `gt`, `gte`) MUST return false; `eq` and `ne` MUST cast to string and compare

#### Scenario: Schema lookup failure is silent
- **GIVEN** an object whose schema reference cannot be resolved (mapper throws)
- **WHEN** the dispatcher attempts to load the schema
- **THEN** the dispatcher MUST return cleanly without throwing
- **AND** no notifications MUST be dispatched for the orphan object

---

### Requirement: REQ-102 — Recipient resolution MUST support six recipient kinds with attacker-controlled-input verification

`AnnotationNotificationDispatcher::resolveRecipients()` MUST support the kinds `users`, `field`, `groups`, `relation`, `object-acl`, and `expression`. For every UID candidate sourced from object data (`field`, `relation` kinds) or from schema annotation (`users` kind), the resolver MUST verify the candidate against `IUserManager::userExists()` before adding it to the recipient list. Transient lookup failures (`\Throwable` from `userExists`) MUST NOT be cached — only definitive verdicts are cached for the duration of a single request. Per-object ACL recipients (`object-acl` kind) MUST honour the `permission` parameter (`read` includes ACL groups; `manage` returns only the object owner). Expression-kind resolvers MUST be looked up via the injected `IServerContainer` (NOT the static `\OC::$server` accessor) and MUST implement `RecipientResolverInterface::resolve(ObjectEntity, array): array<int, string>`.

#### Scenario: `users` kind verifies every declared UID
- **GIVEN** a recipient block `{kind: "users", users: ["alice", "bob", "nonexistent"]}`
- **AND** Nextcloud has `alice` and `bob` as real users but not `nonexistent`
- **WHEN** `resolveRecipients()` is invoked
- **THEN** the returned recipient list MUST contain `alice` and `bob`
- **AND** MUST NOT contain `nonexistent`

#### Scenario: `field` kind rejects attacker-shaped UIDs from object data
- **GIVEN** an object whose `assignedTo` field is set to the string `"admin"`
- **AND** the user `admin` does NOT exist in Nextcloud
- **AND** a recipient block `{kind: "field", field: "assignedTo"}`
- **WHEN** `resolveRecipients()` is invoked
- **THEN** the resolver MUST NOT include `admin` in the recipient list (fail-closed against attacker-controlled input)
- **AND** if `admin` does exist, the resolver MUST include it (legitimate path)

#### Scenario: `relation` kind extracts UIDs from nested structures
- **GIVEN** an object whose `assignees` field is `[{userId: "alice"}, {uid: "bob"}, "charlie"]`
- **AND** all three users exist
- **WHEN** `extractUidsFromRelation()` is invoked
- **THEN** the result MUST be `["alice", "bob", "charlie"]`
- **AND** each UID MUST also pass the `userExists` check before being added to the final recipient list

#### Scenario: `object-acl` kind with `manage` permission returns only the owner
- **GIVEN** an object owned by `alice` with ACL groups `["team-a", "team-b"]`
- **AND** a recipient block `{kind: "object-acl", permission: "manage"}`
- **WHEN** `resolveObjectAclRecipients()` is invoked
- **THEN** the result MUST contain `alice` only
- **AND** MUST NOT expand any ACL groups

#### Scenario: `object-acl` kind with `read` permission expands ACL groups
- **GIVEN** the same object with `read` permission requested
- **WHEN** `resolveObjectAclRecipients()` is invoked
- **THEN** the result MUST contain the owner `alice` plus every member of `team-a` and `team-b`

#### Scenario: `expression` kind uses the injected container, never the static accessor
- **GIVEN** a recipient block `{kind: "expression", resolver: "OCA\\App\\MyResolver"}`
- **WHEN** `resolveExpressionRecipients()` is invoked
- **THEN** the resolver MUST be obtained via the constructor-injected `IServerContainer::get()`
- **AND** when the resolved instance does NOT implement `RecipientResolverInterface`, the resolver MUST log a warning and return an empty array
- **AND** when the resolver throws, the dispatcher MUST log a warning and continue with other recipient blocks

#### Scenario: `userExists` failure is not cached
- **GIVEN** `IUserManager::userExists("flaky-user")` throws once then succeeds on retry
- **WHEN** `userExists("flaky-user")` is called twice within the same request
- **THEN** the first call MUST log a warning and return false WITHOUT writing to the cache
- **AND** the second call MUST retry the lookup (not return a stale cached false)

#### Scenario: Duplicate recipients are deduplicated
- **GIVEN** multiple recipient blocks that all resolve to include user `alice`
- **WHEN** `resolveRecipients()` finalises
- **THEN** the returned list MUST contain `alice` exactly once
- **AND** insertion order MUST be preserved for the first occurrence

---

### Requirement: REQ-103 — Notification rate limiting MUST use a per-`(rule, recipient)` token bucket

`RateLimiter::tryConsume()` MUST implement a token-bucket algorithm keyed on the SHA-1 hash of `ruleId + "|" + recipient`. The bucket MUST refill linearly at a configurable rate (default: one token every 60 seconds, capped at the configured bucket size — default 10). Per-rule overrides MUST be honoured under the `rateLimit.bucketSize` and `rateLimit.refillSecondsPerToken` keys on the rule spec. Operators MUST be able to kill the limiter globally via the app-config flag `notification_rate_limit_enabled = false`. The limiter MUST fail open: when the cache backend is unavailable, when the limiter is disabled, when either `ruleId` or `recipient` is empty, or when a state read throws, `tryConsume()` MUST return true (dispatch proceeds).

#### Scenario: First call to a fresh bucket succeeds
- **GIVEN** a fresh `(rule, recipient)` pair with no existing bucket state
- **WHEN** `tryConsume()` is invoked
- **THEN** the call MUST return true
- **AND** the persisted bucket state MUST have `tokens = bucketSize - 1`

#### Scenario: Bucket empties after `bucketSize` consecutive dispatches
- **GIVEN** a bucket size of 3 and refill rate of one token per 60 s
- **WHEN** the dispatcher invokes `tryConsume()` four times within the same second
- **THEN** the first three calls MUST return true
- **AND** the fourth call MUST return false
- **AND** an info-level log entry MUST be written containing `"[NotificationRateLimiter] dropped"` plus rule, recipient, and configured limits

#### Scenario: Tokens refill linearly with time
- **GIVEN** an empty bucket (`tokens = 0`) with refill rate of one token per 60 s
- **WHEN** the time provider advances 90 seconds and `tryConsume()` is invoked
- **THEN** the bucket MUST hold 1.5 tokens − 1 = 0.5 tokens after the consume
- **AND** the call MUST return true
- **AND** the next call inside the same second MUST return false (insufficient tokens)

#### Scenario: Refill is capped at `bucketSize`
- **GIVEN** a bucket size of 10 and an empty bucket from 24 hours ago
- **WHEN** `tryConsume()` is invoked now
- **THEN** the refill calculation MUST cap the bucket at 10 tokens (NOT 1440 tokens)

#### Scenario: Per-rule override takes precedence
- **GIVEN** a rule declaring `rateLimit: {bucketSize: 100, refillSecondsPerToken: 1}`
- **WHEN** `tryConsume()` is called against that rule
- **THEN** the resolved limits MUST be `(100, 1)`, not the class defaults `(10, 60)`
- **AND** string-form override values (`"100"`, `"1"`) MUST be accepted as long as they pass `ctype_digit`

#### Scenario: Kill switch disables all rate limiting
- **GIVEN** `notification_rate_limit_enabled = "false"` in app-config
- **WHEN** `tryConsume()` is invoked with any inputs
- **THEN** the call MUST return true without reading or writing bucket state
- **AND** `"0"` MUST also be accepted as a falsy value

#### Scenario: Empty `ruleId` or `recipient` fails open
- **GIVEN** either argument is the empty string
- **WHEN** `tryConsume()` is invoked
- **THEN** the call MUST return true (defensive fail-open — empty keys would group unrelated rules)
- **AND** no bucket state MUST be persisted

#### Scenario: Cache unavailability fails open
- **GIVEN** the constructor caught a `\Throwable` from `ICacheFactory::createDistributed()` and `$this->cache` is null
- **WHEN** `tryConsume()` is invoked
- **THEN** the call MUST return true (dispatch proceeds)
- **AND** the constructor MUST have logged a warning `"[NotificationRateLimiter] cache backend unavailable"`

#### Scenario: Cache key uses a stable SHA-1 of (rule, recipient)
- **GIVEN** rule `"foo"` and recipient `"alice"`
- **WHEN** `key()` is computed
- **THEN** the result MUST be `"notification:rate:" . sha1("foo|alice")`
- **AND** colons or pipes in either argument MUST NOT change the separator semantics (the hash is the authoritative separator)

---

### Requirement: REQ-104 — Notification grouping MUST use a per-`(rule, recipient)` debounce window with optional max-events flush

`NotificationCoalescer::shouldDispatch()` MUST implement a debounce window keyed on SHA-1 of `ruleId + "|" + recipient`. Rules opt into coalescing by declaring a `coalesce: {windowSeconds: <int>}` block (optional `maxEvents: <int>`). When a rule has no `coalesce` block, the coalescer MUST be a no-op (return true). When the rule opts in, the first event within a window MUST open the window and proceed; subsequent events within the open window MUST be silenced and only bump the in-window counter. Once `maxEvents` is reached, the next event MUST force a flush dispatch and reset the window. Operators MUST be able to kill the coalescer globally via `notification_coalesce_enabled = false`. The coalescer MUST fail open when the cache backend is missing, the kill switch is set, or a state read throws.

#### Scenario: Rule without `coalesce` block is a no-op
- **GIVEN** a rule whose spec block does not declare `coalesce`
- **WHEN** `shouldDispatch()` is invoked
- **THEN** the call MUST return true without reading the cache

#### Scenario: First event opens the window and fires
- **GIVEN** a rule with `coalesce: {windowSeconds: 300}` and no existing window state
- **WHEN** `shouldDispatch()` is invoked
- **THEN** the call MUST return true
- **AND** the persisted state MUST be `{count: 1, opened: <now>}`

#### Scenario: Subsequent events inside the window are silenced
- **GIVEN** an open window from 60 seconds ago with `count = 3` and `windowSeconds = 300`
- **WHEN** `shouldDispatch()` is invoked again
- **THEN** the call MUST return false
- **AND** the persisted count MUST become 4
- **AND** the `opened` timestamp MUST NOT change
- **AND** an info-level log entry MUST be written: `"[NotificationCoalescer] silenced rule=... recipient=... count=4 windowSeconds=300"`

#### Scenario: Window expiry opens a fresh window
- **GIVEN** an open window from 600 seconds ago with `count = 5` and `windowSeconds = 300`
- **WHEN** `shouldDispatch()` is invoked
- **THEN** the call MUST return true (the previous window has expired)
- **AND** the persisted state MUST be reset to `{count: 1, opened: <now>}`

#### Scenario: `maxEvents` forces a flush before window expires
- **GIVEN** a rule with `coalesce: {windowSeconds: 3600, maxEvents: 5}`
- **AND** the current window state is `{count: 4, opened: <60s ago>}`
- **WHEN** `shouldDispatch()` is invoked
- **THEN** the call MUST return true (forced flush at the 5th event)
- **AND** the persisted state MUST be reset to `{count: 1, opened: <now>}`

#### Scenario: `inspect()` exposes the current state for diagnostics
- **GIVEN** an existing window state `{count: 12, opened: 1716500000}`
- **WHEN** `inspect()` is called for the same `(rule, recipient)`
- **THEN** the return value MUST be `["count" => 12, "opened" => 1716500000]`
- **AND** when no state exists, `inspect()` MUST return null

#### Scenario: `windowSeconds <= 0` disables coalescing for that rule
- **GIVEN** a rule with `coalesce: {windowSeconds: 0}`
- **WHEN** `shouldDispatch()` is invoked
- **THEN** the call MUST return true without reading the cache (the parse step returned 0 from `resolveWindowSeconds`)

#### Scenario: Kill switch fails open
- **GIVEN** `notification_coalesce_enabled = "false"` in app-config
- **WHEN** `shouldDispatch()` is invoked on a rule with a valid `coalesce` block
- **THEN** the call MUST return true (every event fires immediately)

---

### Requirement: REQ-105 — Idempotency-key deduplication MUST claim the dedup slot before sending

When a rule declares an `idempotencyKey` template, the dispatcher MUST resolve the template against the object, then claim the dedup slot via `NotificationDispatchLogMapper::record()` **before** invoking any channel emit. The claim MUST be the authoritative serialisation point under concurrency, backed by the unique `(notification_slug, idempotency_key)` index installed by migration `Version1Date20260511120000`. On `DuplicateDispatchException` (the index rejected the insert), the dispatcher MUST skip the rule entirely with an info-level log entry. On any other `\Throwable` (table missing in tests, transient infra failure), the dispatcher MUST fail open and proceed with the dispatch, logging at warning level.

The template syntax MUST support `${@self.<field>}` substitution. The token `@self.id` and `@self.uuid` MUST both resolve to `ObjectEntity::getUuid()`. Other tokens MUST be replaced with the corresponding field from the object's stored data (cast to string), or with the empty string when the field is absent or non-scalar. Each resolved token value MUST be truncated to 128 characters to avoid the 512-character `idempotency_key` column being exhausted by adversarial input.

A `null` `dispatchLogMapper` (test contexts that did not wire the mapper) MUST be treated as fail-open — no test should be forced to construct the mapper just to pass the guard.

#### Scenario: Rule without `idempotencyKey` skips the claim
- **GIVEN** a rule whose spec block does not declare `idempotencyKey`
- **WHEN** the dispatcher processes the rule
- **THEN** `claimIdempotencyKey()` MUST NOT be invoked
- **AND** the dispatch MUST proceed normally

#### Scenario: Claim succeeds for an unseen key — dispatch proceeds
- **GIVEN** a rule with `idempotencyKey: "${@self.id}-T30-${@self.dueDate}"`
- **AND** no existing dedup row for the resolved key
- **WHEN** the dispatcher processes the rule
- **THEN** the dispatch log MUST be inserted BEFORE any emit method is called
- **AND** the dispatch MUST proceed

#### Scenario: Concurrent dispatcher loses the unique-index race
- **GIVEN** two concurrent dispatchers attempting to claim the same `(slug, key)` slot
- **WHEN** the database unique index rejects the second insert with `DuplicateDispatchException`
- **THEN** the loser MUST log an info entry `"[AnnotationNotificationDispatcher] deduplicated rule=... key=..."`
- **AND** MUST skip the dispatch entirely (no channel emit, no history record under "dispatched")

#### Scenario: Generic DB failure fails open
- **GIVEN** the dispatch log table is missing (e.g. integration test without the migration applied)
- **WHEN** `claimIdempotencyKey()` is invoked
- **THEN** the resulting `\Throwable` (NOT a `DuplicateDispatchException`) MUST be caught
- **AND** the dispatcher MUST log a warning containing the underlying message
- **AND** MUST return true (dispatch proceeds — infrastructure failure must not silently drop user-visible notifications)

#### Scenario: `${@self.id}` and `${@self.uuid}` resolve to the object UUID
- **GIVEN** an object with `uuid = "abc-123"` and a template `"${@self.uuid}-foo"`
- **WHEN** `resolveIdempotencyKey()` is invoked
- **THEN** the resolved key MUST equal `"abc-123-foo"`
- **AND** the same result MUST hold when the template uses `${@self.id}` instead

#### Scenario: Unknown token resolves to empty string
- **GIVEN** an object whose data array does NOT contain the `dueDate` field
- **AND** a template `"${@self.uuid}-${@self.dueDate}"`
- **WHEN** the key is resolved
- **THEN** the resolved key MUST equal `"abc-123-"` (trailing dash preserved; missing field becomes empty)

#### Scenario: Non-scalar field resolves to empty string
- **GIVEN** an object whose `tags` field is an array (non-scalar)
- **AND** a template `"${@self.uuid}-${@self.tags}"`
- **WHEN** the key is resolved
- **THEN** the substitution for `tags` MUST be empty
- **AND** the resolved key MUST equal `"abc-123-"`

#### Scenario: Per-token value is truncated to 128 characters
- **GIVEN** an object whose `description` field is a 500-character string
- **AND** a template `"${@self.description}"`
- **WHEN** the key is resolved
- **THEN** the resolved key MUST be exactly 128 characters of the source string
- **AND** the total key MUST never exceed the column limit when the template combines multiple long fields

#### Scenario: Prune expired rows is best-effort
- **GIVEN** a stale dedup row whose dedup window has passed
- **WHEN** the next claim is attempted
- **THEN** the dispatcher MUST invoke `pruneExpired()` before the claim
- **AND** a `pruneExpired()` failure MUST NOT block the claim (the mapper swallows it internally)

#### Scenario: Null dispatch-log mapper fails open
- **GIVEN** `$this->dispatchLogMapper === null` (legacy test fixture)
- **WHEN** `claimIdempotencyKey()` is invoked
- **THEN** the call MUST return true without attempting any DB operation

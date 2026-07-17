# Design

## Provenance, and why this is not a cherry-pick

These fixes were authored as GitHub PR #2010 (`2de1328c5`, merged 2026-05-29) and
never reached this repository. The fork point is `f92b128a0` (2026-05-28); this
repo's `development` is 1,507 commits ahead of it, and the commit is reachable
from none of its 464 branches. GitHub `development` is frozen at that commit.

The original carried no openspec artifacts and referenced an audit file that no
longer exists. Every claim below was therefore re-verified against HEAD rather
than inherited. Three of the five fixes conflict with architecture that landed in
the meantime; one sub-fix is actively broken against current code. Applying the
original diff would have regressed live security work.

### Reconciliation against HEAD

| Original fix | Verdict | What changed since May |
|---|---|---|
| 1. `readOnly` enforcement | **Applied** | Wiring point survives. New BC hazard: 95 property-level declarations now ship in importable examples. |
| 2. default-closed flag | **Applied, scoped down** | Constructor already has `IAppConfig`; the `"public": true` opt-in is dropped (below). |
| 3. bulk safeguards | **Applied, 2 of 5 sub-parts** | 3 of 5 were independently re-implemented under other names. |
| 4. dot-syntax | **Applied, extended + scoped down** | Original patched one of two evaluators; `$user.groups` split out. |
| 5. per-object `_authorization` | **Applied, re-derived** | `resolveAuthorization()` gained a fail-closed contract the original predates. |

## Decisions

### The `"public": true` opt-in is dropped, not deferred

The original gated default-closed behind `$authorization['public'] === true`.
That shape does not exist at HEAD and never did: `public` is a **group name**
inside an action's list (`{"read": ["public"]}`), not a boolean sibling key.
`Schema` has no `public` property.

Worse, it would not work. `{"public": true}` makes `empty($authorization)`
**false**, so it falls past the default-open branch into the opt-in-strict deny
at `PermissionHandler.php:1077` — an escape hatch that denies everything instead
of opening it. It was coherent in May, before that line existed.

The BC escape hatch already exists and is idiomatic: declare
`{"create": ["authenticated"]}` on the schema.

### The flag lives in `evaluatePermission()`, not at the default-open branch

The natural home looks like `hasGroupPermission.php:1069`, where
`empty($authorization) === true` returns true. It is the wrong home: the warn-once
key needs the Schema, which that method does not receive.

`evaluatePermission()` has the Schema, and is where the sibling #1955 anonymous
branch already lives. But the admin bypass (`:1059`) and **owner** bypass
(`:1063`) live in `hasGroupPermission` and would be skipped. The policy therefore
re-applies the owner check itself. Without it, enabling the flag would lock every
user out of their own objects — a far worse outcome than the hole being closed.

Flag key is `rbac.enforce_default_closed`, matching the sibling
`rbac.inherit_from_public_default` namespace, not the original's bare
`enforce_default_closed`.

### Reads stay open under the flag

`@PublicPage` is the OR-wide read model, and read filtering also runs at the SQL
layer in `MagicRbacHandler`, which has its own independent default-open
(`:201`). Closing reads means changing both in lockstep and is a separate
decision. The flag governs `create`/`update`/`delete` only.

### An unreadable flag resolves to `false`, and that is not a fail-open

`resolveAuthorization()` now fails **closed**: a resolver that cannot determine
permissions throws and every caller denies. `enforceDefaultClosed()` deliberately
does the opposite — an unreachable `IAppConfig` returns `false` (open).

These are not in tension. The fail-closed contract governs *resolving rules that
exist*. This flag is a policy **choice about the absence of rules**; an unreadable
flag means "the operator has not opted in", which is exactly `false`. Denying on
a config-store blip would turn an outage into a fleet-wide write outage.

### Bulk: existence is resolved against the database, not inferred from the uuid

The original derived CREATE-vs-UPDATE from uuid *presence*. That is wrong in both
directions: bulk save is an upsert, a client may legitimately choose the id of a
**new** object, and absence does not imply a create. Inferring from presence would
reject legitimate inserts on append-only schemas.

`resolveExistingUuids()` resolves the truth once per chunk. Rows without a uuid
are certainly creates and are never queried; the rest resolve in one batched
lookup, split at 500 to stay inside the query planner's `IN()`-expression cap.
This is a bounded constant per chunk, not a per-row round trip.

A lookup failure classifies rows as creates. That is the safe direction for both
consumers: it can only make RBAC demand `create` where `update` might have
sufficed, and the append-only guard's job is to reject updates.

### Bulk: three of five original sub-parts were already done, better

- `@self`/`_owner`/`_organisation` stripping — already present, and **stronger**:
  owner and organisation are unconditionally *overwritten* from the session
  rather than conditionally stripped (`SaveObjects.php:861-869`), with a second
  layer at `hydrateObjectMetadataFields()` and a third at
  `MagicBulkHandler.php:158-168`.
- `_authorization` injection — not possible: the bulk path builds an explicit
  column allowlist and never writes that column.
- `_rbac: false` admin gating — unreachable: `BulkController.php:394` hardcodes
  `_rbac: true`. No controller passes user input into it. An admin gate would
  close no reachable vector.
- `SaveObject::setSelfMetadata` organisation gate — already present at
  `SaveObject.php:3782-3797`, inlined rather than behind a `callerIsAdmin()`.

One deliberate divergence is left standing: live code accepts a client
organisation for **verified members**, where the original allowed **admins
only**. Membership is verified server-side, so this is a policy difference, not a
hole. Left as-is; flagged rather than silently changed.

### Dot-syntax must land on both evaluators or neither

`ConditionMatcher::resolveDynamicValue()` (PHP/find) and
`MagicRbacHandler::resolveDynamicValue()` (SQL/list) are twin interpreters of one
rule grammar. `ConditionMatcher.php:233-240` states the invariant in its own
docblock: a format mismatch makes list and find disagree.

The original patched only `ConditionMatcher`. Applying it as authored would make
`$user.email` resolve on find and stay a literal on list — the same object
appearing in a list result but 403-ing on find. Both are patched here.

### `$user.groups` is dropped

It resolves to an **array**. The SQL twin cannot express that as a scalar
equality without an `IN` predicate, so it cannot reach parity by copy-paste.
Supporting it on the PHP path alone would reintroduce exactly the drift above.
It needs its own design and is tracked separately. It denies today, loudly.

### Per-object overrides are action-scoped, and this is load-bearing

The obvious implementation — merge the object's block over the baseline
action-by-action — is wrong, and a test caught it.

The authorization array cannot express "open" for an action: an action is open
precisely by being **absent** from a block that is itself absent. Once a block is
non-empty, `hasGroupPermission()` denies every unlisted action. So folding an
object's `{"update": [...]}` into an otherwise-absent baseline yields
`{"update": [...]}` — which silently **denies create and delete** as a side
effect of sealing update.

`resolveAuthorization()` therefore takes the action under evaluation and merges
only that action's override. Every other action keeps exactly the verdict it had.

### Per-object overrides are write-only

`MagicRbacHandler` builds its RBAC `WHERE` clause from the **schema's** rules
before any row exists; it structurally cannot consult a per-object column.
Honouring a per-object `read` seal in `PermissionHandler` would enforce it on
`find` while `list` returned the row anyway — a leak dressed as a control.

Refusing the override (and logging) is the honest behaviour. Half-enforced access
control is worse than none, because it is trusted.

### Fix 5 rewrites nothing; it adds

The original replaced the whole body of `resolveAuthorization()`, from a version
that predates the `AuthorizationUnresolvableException` fail-closed contract
(archived change `2026-07-16-authz-fail-closed-and-vocabulary-drift`). Its
rewritten body has no `@throws` and silently yields `null` where the current one
throws. Merging it would have re-opened the CWE-863 fail-open that change closed.

The merge is layered onto the existing body instead. The `@throws` contract and
its `@spec` anchor are preserved verbatim.

### The permission cache is dropped for objects carrying overrides

`buildPermissionCacheKey()` already keys on the object UUID, so there is no
cross-object leak. But per-object `_authorization` can change *within* a request,
and the key is stable — the same pre-mutation-verdict hazard the existing
`schemaHasMatchRule()` drop guards against. The cache is dropped for objects with
a non-empty block, mirroring that precedent.

## Done means consumed, not stored

Fix 5 is a member of the fleet's orphaned-capability defect class: implemented,
specced, tested, and invoked by nothing. `_authorization` has been a column with a
migration, an accessor, hydration, and serialization since 2025 — and zero
readers that feed a decision.

A version of this change that adds `?ObjectEntity $object = null` to
`resolveAuthorization()` without passing it at `PermissionHandler.php:426` would
be *the same defect with better paperwork*. The single live consumer is wired,
and the spec's scenarios assert a **denied write**, not a round-trip.

The same standard applies to the other four: each scenario asserts an
allow/deny/reject outcome on a live path. None asserts that a value was stored,
returned, or not dropped.

## Known gaps

- `MagicRbacHandler` retains its own independent default-open at `:201`. Bounded
  for this change (it governs list/read filtering; the flag governs writes) but
  it means a future read-side default-closed must patch both, following the
  `rbac.inherit_from_public_default` precedent of being read in both classes.
- Property-level `readOnly` on relation arrays the engine writes back
  (`deelzaken`, `eigenschappen`, `statussen`, `zaakinformatieobjecten` in the
  ZaakRegister examples) would reject engine-generated cascade updates if those
  examples were ever imported. No Repair step imports them today. This must be
  assessed before that changes.
- `PermissionHandler` is now 29 non-accessor methods against a threshold of 25.
  Suppressed with justification; it is a decomposition candidate, tracked
  separately.

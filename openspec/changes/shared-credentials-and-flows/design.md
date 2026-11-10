## Context

Two things need sharing, and they are enforced in **two different planes** —
which is the single most important fact about this change.

**Credentials** are already access-controlled *per object*, inside
`CredentialBrokerService`'s four fail-closed guards (ADR-004 Rule 4). Guard 1
reads the credential object's own `scope` / `organisation` data and its `owner`
system field, and dispatches: `personal` → `assertPersonalOwner()` (strict
equality), `organisation` → `assertOrganisationMember()`. A brokered credential
is deliberately **not** read through the object RBAC path — the broker loads it
with `_rbac: false` and substitutes its own, stricter chain.

**Flows** are ordinary OR objects, so their visibility is decided by
`PermissionHandler::checkPermission()`, evaluating the *schema's* `authorization`
block. Critically, that evaluation already receives the specific `ObjectEntity`
and already supports:

- bare `user:<uid>` overrides (`matchesUserOverride()`), honoured by
  `PermissionHandler`'s single-object verdict **and** all three list emitters;
- conditional rules `{ "group": …, "match": {…} }` and the user form
  `{ "user": "<uid>", "match": {…} }` — the code's own comment calls this
  "User-level override (delegation)";
- `match` clauses evaluated by the shared `ConditionMatcher` (ADR-011: the one
  PHP-side matcher; do not reimplement), with dynamic values `$userId` / `$user`
  / `$user.uid` / `$user.groups` / `$organisation` / `$now`.

So per-user and per-group *principals* already exist in RBAC. What does not
exist is a way to bind a grant to **one object** without writing into the schema.

### The constraint that decides the design

`MagicRbacHandler` documents the contract in its own class docblock: there are
two enforcement paths — SQL emission for list endpoints, and the PHP-side verdict
for single-object reads — and **"new conditional operators or dynamic variables
MUST be added to ConditionMatcher / OperatorEvaluator, not re-implemented here."**
The codebase has been bitten twice by these two paths disagreeing: the `$now`
format was normalised to `Y-m-d H:i:s` specifically so "list and find endpoints
produce identical verdicts", and `unwrapResolvedRelation()` exists because a
resolved relation "would flip from allow to deny … (list-vs-find drift)".

Any new operator therefore costs two implementations that must agree, and
divergence is a silent access-control bug — over-filtering hides objects, and
under-filtering leaks them.

## Goals / Non-Goals

**Goals:**

- Share a brokered credential with named users and groups, granting **use** of
  it and never disclosure of the secret.
- Share a flow with named users and groups, distinguishing `read` from `run`,
  without granting `edit`.
- Keep organisation scope working exactly as it does now; the share list is an
  additional, narrower grant.
- Let a flow declare whose credentials a run resolves as — the runner's own
  (default) or the owner's, lent — and record which was used.
- One access decision per plane. No second, parallel authorisation system, and
  nothing a client evaluates for itself (ADR-006 Rules 1 and 3).

**Non-Goals:**

- Federated / cross-instance sharing.
- Per-node credential overrides inside a flow (the declaration is per flow).
- Sharing that crosses a tenant boundary. A share narrows access *within* what
  the organisation already permits; the organisation UUID stays the only tenant
  key (ADR-002 Rule 1).
- Changing how secrets are stored, or adding any path that returns one.

## Decisions

### D1 — The share list lives on the OBJECT, not in the schema's authorization block

`sharedWith[]` becomes a property of the credential and flow objects:

```json
"sharedWith": [
  { "type": "user",  "id": "alice", "permission": "run" },
  { "type": "group", "id": "finance", "permission": "read" }
]
```

**Alternative considered and rejected:** express each share as a schema
authorization rule narrowed to one object —
`{ "user": "alice", "match": { "id": "<flow-uuid>" } }`. This is *already
supported on every path today* and would need no new operator, which makes it
genuinely tempting. Rejected because the schema is the wrong home for
user-authored data:

- **A register re-import would destroy every share.** Schema changes are applied
  by register import, and this repo has the scars: an `importFromApp(force: false)`
  advances the version *without applying*, and union-merging a register conflict
  silently drops modifications. User shares living in a schema would be at the
  mercy of the next import.
- **Every share in the instance would contend on one JSON document.** Concurrent
  grants by different owners would last-write-wins each other.
- **It inverts ownership.** The schema `authorization` block is an admin surface;
  end users granting each other access must not be writing to it.

Object-side storage keeps a share next to the thing shared, versioned with it,
and revocable by its owner alone.

### D2 — One new match operator, implemented on both enforcement paths

The existing operator vocabulary cannot express a share check. `operatorIn()` is
`in_array($objectValue, $operand, true)` — it tests *object value ∈ literal
list*. A share test is the **reverse**: *acting principal ∈ object's array*.
Both plausible spellings fail today:

- `{"match": {"sharedWith": "$userId"}}` compares an array to a string → deny.
- `{"match": {"sharedWith": {"$in": ["$userId"]}}}` calls
  `in_array(array, [uid])` → deny.

So this change adds an operator meaning "the object's array-valued property
contains the resolved value", added to `OperatorEvaluator` (PHP verdict path,
per the ADR-011 contract) **and** to `MagicRbacHandler`'s SQL emitter, because
list endpoints go through the SQL path and the two must return identical
verdicts.

Group shares then need no second mechanism: `$user.groups` already resolves to
the user's group ids, so a group grant is the same operator with an
array-to-array intersection.

**This is the highest-risk item in the change** and the reason the flow half is
harder than the credential half. It is mitigated by D6.

### D3 — Credentials extend the broker's guard chain, NOT the RBAC path

Guard 1 gains a third branch, after the existing two, reading the same
`sharedWith[]` shape. It does **not** route through `ConditionMatcher`.

**Rationale.** The broker deliberately bypasses object RBAC (`_rbac: false`) and
substitutes a stricter chain; that chain *is* the credential security boundary
(ADR-004 Rule 4). Re-routing credential access through the generic RBAC
evaluator to reuse D2's operator would weaken a security-critical boundary to
save code, and would make the broker's verdict depend on schema configuration
that an admin can edit. Two enforcement points is the correct answer here, not
duplication to be eliminated.

The consequence is that `sharedWith[]` is one *data shape* with two readers. The
shape is therefore specified once, and both readers get tests proving they agree
on the same fixtures.

### D4 — `credentialIdentity` on the flow: `runner` (default) or `owner`

- `runner` — resolve credentials as the user who triggered the run. Default,
  and the safe one: a recipient can run the flow with their own credentials.
- `owner` — resolve as the flow owner regardless of who triggered it. The flow
  *lends* the owner's credential.

Enforcement:

- **Only the flow owner may set or change it.** A share recipient never gets
  `edit`, so a recipient cannot flip a `runner` flow to `owner`.
- **It never enables disclosure.** Resolution still goes through the broker, and
  the routed broker path never returns a plaintext secret; `owner` mode grants
  the *use* of the credential, not sight of it.
- **The run records the identity actually used**, so a lent-credential call is
  attributable after the fact.

`FlowRun` already carries `triggeredBy` and `organisation`; the resolved
credential identity is a third, separate field — conflating it with
`triggeredBy` would destroy exactly the distinction that makes `owner` mode
auditable.

### D5 — Run-time resolution uses the existing sessionless in-process assertions

`FlowRunWorker` executes runs from cron with **no user session**. The broker
already has the mechanism: `actingUserId` (personal) and `actingOrganisationId`
(organisation), honoured only when no session exists and documented as settable
only by trusted in-process code — request input never reaches them.

So run-time resolution passes the identity D4 selected as `actingUserId`. No new
trust mechanism is introduced; the existing one is threaded from a new source.

**The invariant this depends on:** those assertions must stay unreachable from
request input. `owner` mode makes forging one more attractive, so the spec states
it as a requirement and the tests assert the HTTP-routed path cannot set it.

Apps that contribute flow nodes (hermiq's agent step is the live example, calling
`resolveInjectable($credentialId, APP_ID, $uid)`) read the identity from the run
rather than assuming the triggering user. hermiq's `CredentialScopeResolver`
gains shared credentials as a *candidate* — it is a selector whose every answer
is re-validated by the broker's guards, so it cannot widen access by itself.

### D6 — Verdict-parity tests are part of the change, not follow-up

Because D2 has two implementations, the change includes a test matrix that runs
the **same fixtures** through the single-object path and the list path and
asserts identical verdicts, for: owner, org member, non-member, shared user,
member of a shared group, revoked share, anonymous, and a malformed
`sharedWith[]` entry.

This is the mitigation for the divergence class that has already produced two
fixes in this file. A share that is honoured on find but dropped on list looks
like an empty page; the reverse leaks an object.

## Risks / Trade-offs

- **[PHP/SQL operator divergence silently mis-authorises]** → D6's parity matrix
  over one fixture set; the operator is added in `OperatorEvaluator` per the
  documented ADR-011 contract rather than inline in the SQL class.

- **[`owner` mode is a real privilege delegation]** → owner-only writable; no
  disclosure path; the resolved identity recorded on every run; the sessionless
  assertion stays unreachable from request input, with a test asserting the
  routed path cannot set it.

- **[A share becomes an accidental tenant bypass]** → tenancy is evaluated
  first and independently; a share can only narrow within an organisation, never
  admit across one. Explicit scenario, and no group is ever consulted as a
  tenant key (ADR-002 Rule 1).

- **[Adding a branch to a security-critical guard chain]** → the branch is
  ordered *after* the existing two, changes no existing verdict, and keeps
  fail-closed behaviour; ADR-004 Rule 4's enumeration is amended so the
  documented chain matches the code.

- **[Register/schema changes not applied]** → `sharedWith[]` and the flow
  property arrive via a **forced** register import; a non-forced import advances
  the version without applying it, which would leave the property absent while
  the code assumes it.

- **[Two readers of one shape drift apart]** → the shape is specified once and
  both readers are tested against the same fixtures (D3).

- **[Sharing invites "share the secret too"]** → out of scope by construction:
  no new path returns a secret, and the use-not-disclosure boundary is a stated
  requirement rather than an implementation detail.

## Migration Plan

1. Add `sharedWith[]` + the flow's `credentialIdentity` to the register JSONs;
   apply with a **forced** import. Both are optional, so existing objects stay
   valid and unshared.
2. Add the operator to `OperatorEvaluator` + the SQL emitter, with the D6 parity
   matrix, before anything consumes it.
3. Add the broker's Guard 1 branch (credentials shareable).
4. Add the flow read/run grants and the migration for the run's resolved
   identity field.
5. Thread run-time identity resolution; default `runner` preserves today's
   behaviour exactly.
6. Leaf UIs (doriath, hermiq) as separate changes once the API is live.

**Rollback:** every element is additive and defaults to current behaviour — an
absent `sharedWith[]` denies as before, and an absent `credentialIdentity`
resolves as `runner`, which is what happens today.

### D7 — A flow share does NOT carry the flow's run history

A recipient sees the flow definition, may trigger it if granted `run`, and sees
**their own** runs. Runs triggered by the owner or by other recipients stay
invisible to them.

**Rationale.** A run log records the subject data the flow touched — records,
fields, errors — so bundling history into a share would turn every share into a
data-exposure decision the sharer did not knowingly make. Keeping history private
means no share can become such a path.

This is the conservative direction on purpose: widening later (a per-flow
`runHistory` declaration, or sharing history outright) is additive and needs no
migration, whereas narrowing after recipients have seen other people's run data
cannot un-disclose it.

**Alternatives considered:** full history (most useful for a genuinely shared
team automation, rejected because the subject data follows the share silently);
and a third owner-only declaration alongside `credentialIdentity` (rejected for
now as another security control to build, document and test for a benefit nobody
has asked for yet — the door stays open).

### D8 — Only the owner grants and revokes

Share management is owner-only for both credentials and flows. Nextcloud admins
retain the global bypass they already have; no organisation-admin path is added.

**Rationale.** It matches the broker's existing owner-centric model
(`assertPersonalOwner()` is strict owner equality) and keeps the share API's
authorisation identical to the object's. An org-admin revocation path would need
an org-admin role check the share API does not currently have, and adding a
second principal who can alter a security-relevant list is not free.

**Consequence to accept:** offboarding a departing employee's shares means
acting as that user or as a Nextcloud admin. If that becomes painful, an
org-admin path is additive.

### D9 — The RBAC predicate matches a DERIVED scalar principal list, not the rich share list

`$contains` compares scalars against an array's members. The share entry this
change declares is an **object** — `{ "type": "user", "id": "alice",
"permission": "run" }` — so `{"sharedWith": {"$contains": "$userId"}}` matches
**nothing**. Verified against the shipped operator, not assumed:

```php
in_array('alice', [['type'=>'user','id'=>'alice','permission'=>'run']], true) === false
```

So the object carries two representations of one fact:

- `sharedWith[]` — the rich list. The management surface and the source of the
  permission verb.
- **two** derived scalar lists — `sharedUsers: ["alice"]` and
  `sharedGroups: ["finance"]` — which the RBAC predicates match.

**Why two unprefixed lists and not one prefixed `["user:alice", …]`:** a match
clause resolves WHOLE tokens and cannot concatenate. There is no way to express
`"user:" + $userId`, so a prefixed list is unmatchable by `$userId` (which
resolves to the bare uid). Splitting by principal kind keeps both predicates
expressible with the EXISTING vocabulary and no new token:

```json
{"group": "authenticated", "match": {"sharedUsers":  {"$contains": "$userId"}}}
{"group": "authenticated", "match": {"sharedGroups": {"$contains": "$user.groups"}}}
```

Two rules, OR'd — which is already how multiple authorization rules combine. The
alternative was a new `$userPrincipal` token resolving to `"user:<uid>"`, i.e.
more RBAC vocabulary (and a second SQL implementation) to buy nothing.

**Alternative considered and rejected:** teach the operator a dot-path into an
array of objects (`sharedWith.id`). It would keep one representation, but the SQL
side needs `jsonb_path_exists` on PostgreSQL and an entirely different construct
on MariaDB — reintroducing exactly the platform-divergence risk that D2 and the
single-builder implementation were designed to remove, in the one predicate that
decides access.

**The hazard this creates, and the mitigation:** two representations can drift,
and a stale derived list is an access-control bug in whichever direction it is
stale — it either hides an object from someone entitled to it or shows it to
someone who is not. So the derived list MUST be computed server-side on every
write to `sharedWith[]` and MUST NOT be accepted from a client, with a repair
step for objects written before this change or through a direct API write.

### D13 — Flows have NO authorization today, and that BLOCKS `credentialIdentity: owner`

Checked against the live instance and the code, not assumed:

- **Read.** Every `flow` schema has `authorization = NULL`. An empty
  authorization block means "open" in both enforcement paths, so every flow is
  readable by every user in the tenant right now.
- **Run.** `flowRun#test` is `#[NoAdminRequired]` with **no ownership check at
  all** — zero owner/forbidden checks in the method. `flowRun#retry` likewise,
  and `FlowMcpToolProvider::runFlow()` is a third unguarded entry point.

So **any authenticated user can already read and run any flow.** Flow "sharing"
as originally specified has no gap to fill: what is missing is not a way to grant
access but any restriction to grant *from*.

**This is a blocking dependency, not a nuance.** `credentialIdentity: owner`
lends the owner's credential to whoever triggers the run. Shipping it while the
run endpoint is unauthorised would let ANY authenticated user run someone else's
flow and cause outbound calls signed with that owner's secret — a
privilege-escalation path created by this change. The broker's guards cannot
catch it, because the flow resolves as the owner *by design*; that is the
declared intent of the mode.

Therefore:

1. Run authorization for flows (owner, plus a `run` share) MUST land BEFORE
   `credentialIdentity: owner` is enabled. Task group 7 is re-sequenced behind
   group 6, and `owner` mode must not be shippable until then.
2. Both halves of flow sharing are **breaking changes to existing behaviour**,
   not additions: restricting read makes flows invisible to non-owners who can
   see them today, and restricting run makes them un-runnable by users who can
   run them today. That is a hardening change wearing a feature's clothes, and
   the blast radius belongs to the product owner, not to this change.

The credential half is unaffected — it granted access where there genuinely was
none, and has already shipped.

### D12 — The register version must be bumped PAST the live schema, not just past the file

The repair steps import with `force: false`, and the gate is
`force === false && version_compare($new, $existing, '<=')` → skip. So the bump
is what makes the import apply at all; without one it silently no-ops while the
shipped file claims a new shape.

The subtlety that actually bit: the comparison is against the version in the
DATABASE, not the version in the previous file. On the dev instance the shipped
`flow_register.json` said `1.1.0` while the live `flow` schema was already at
`1.2.0` — so that import had been skipping silently for some time. Bumping the
file to `1.2.0` would have TIED and skipped again. Both registers therefore went
to `1.3.0`, verified by reading the live schema afterwards:

| schema | version | properties |
|---|---|---|
| `brokeredcredential` | 1.1.0 → 1.3.0 | 7 → 10 |
| `flow` | 1.2.0 → 1.3.0 | 10 → 14 |

The check that matters is not "did the import run" but "does the live schema now
have the properties" — the two differ exactly when the version gate skips.

### D11 — The parity matrix must run WITH a session, because the list path bypasses RBAC in CLI

`applyRbacFilters()` returns early — applying no filter at all — when there is no
user and `PHP_SAPI === 'cli'`. That is deliberate and documented: occ commands,
repair steps and cron are trusted system contexts, and without the bypass a
schema with authorization rules would clamp every CLI query to `1 = 0`.

The consequence for testing is easy to get wrong, and I got it wrong first: a
CLI parity test with no session sees the single-object path evaluate the match
and the list path return everything, which LOOKS like a fail-open divergence and
is not one. The matrix therefore has to create a real non-admin user and log it
in, so the production code path is the one under test.

Note also that the two SQL entry points differ here: `buildRbacConditionsSql()`
(the raw-SQL branch) has NO CLI bypass and returns real conditions, while
`applyRbacFilters()` (the QueryBuilder branch) bypasses. Both are intentional but
they are not the same posture; worth knowing before reading a test result.

### D10 — RBAC grants visibility; the trigger endpoint enforces `run`

`run` is not an object RBAC verb — the actions are create / read / update /
delete. So the schema rule grants a share recipient *visibility* of the flow, and
the trigger endpoint enforces the verb by reading the rich `sharedWith[]`.

That is two enforcement points for one grant, which is worth stating plainly
rather than discovering later: a `read`-only recipient who can see the flow must
still be refused at the trigger, and that refusal needs its own test because no
RBAC rule expresses it.

## Open Questions

- **Operator name.** `$contains` reads naturally but invites confusion with
  substring matching; `$anyOf` / `$includes` are alternatives. Needs a decision
  before the operator is added, since it becomes part of the RBAC vocabulary and
  the SQL emitter's contract.
- **Permission verbs on a credential share.** `use` is clearly needed. Is
  `read` (see that the credential exists, in order to pick it in a UI) a
  separate verb, or implied by `use`?

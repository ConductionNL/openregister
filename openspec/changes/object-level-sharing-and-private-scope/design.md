## Context

Object access is decided at the schema level and defaults to the organisation.
There is no per-object grant, and no way to make one object private. Three share
concepts already exist (file shares through `OCP\Share\IManager`, federated
shares through `FederatedShare` / `OpenRegisterCloudFederationProvider`, and
schema-level object RBAC), plus a bespoke `sharedWith[]` added to credentials and
flows only because the primitive was missing.

The principal vocabulary — `public`, `authenticated`, `admin`, `user:<uid>`,
group names — is resolved in **four** places. Its predecessor change found two
divergences between them in a single week's work, so "all four or none" is a
constraint, not a preference.

## Goals / Non-Goals

**Goals:**

- `private` as a first-class RBAC principal: owner + administrators only.
- Grants per OBJECT, composing with (never replacing) schema-level rules.
- The Files interaction: share link, invite by email, expiry, revocation.
- Federated principals as one more principal, not a parallel system.
- One shared surface in nc-vue — a widget and a detail-page tab — so the fleet
  gets the affordance once.
- Supersede the bespoke `sharedWith[]`, leaving one primitive.

**Non-Goals:**

- Changing the organisation default. `private` is opt-in; nothing changes
  visibility on upgrade.
- Per-property sharing (that is `PropertyRbacHandler`'s concern).
- Migrating existing bespoke `sharedWith[]` data — its own step, once this lands.

## Decisions

### D1 — Core owns the share RECORD on the object's FOLDER; OpenRegister owns the VERDICT

An object share is a real Nextcloud share **on that object's NC folder**. No share
provider is registered — see Q1: `IShareProvider` and `IShare` are bound to Files
(`setNode(Node)`, `getNodeId(): int` non-nullable), so an object cannot be a share
target, but its folder can, and every object has one (created on demand by
`FolderManagementHandler::getObjectFolder()`).

So the share lifecycle — token, expiry, password, mailer, federation handshake,
revocation — is core's, unchanged. The **authorization verdict stays in OR's RBAC
evaluator**, which reads the folder's shares and resolves them into principals.

**Why split it this way.** The surface is where core is strongest, and
`ShareLinkService` already reads exactly the six share types this needs
(`TYPE_USER`, `TYPE_GROUP`, `TYPE_LINK`, `TYPE_EMAIL`, `TYPE_REMOTE`,
`TYPE_REMOTE_GROUP`) on the object's folder, with no cache. The verdict is where
OR is load-bearing: both enforcement paths already meet in its evaluator, and core
cannot see registers, schemas, organisations, or the SQL emitters that filter a
magic-table list query.

**Alternatives rejected.** Registering a provider is not available at all (Q1).
Wholly OR-native means reimplementing links, email delivery, expiry and outbound
federation that core already ships — and this programme's own experience is that
every re-implementation of an existing mechanism is where the divergence bugs come
from.

**What this couples.** A grant on the folder also reaches the FILES in it, and
core's permission bitmask has no object verbs — `run` and `use` ride in `IShare`'s
`IAttributes` bag (Q5). Both are consequences to design around, not surprises to
discover.

### D2 — The bridge is read-through, never cached

OR reads share records from `IManager` at decision time. It does **not** keep an
OR-side copy.

`ShareLinkService` already documents exactly this hazard for file shares: `IShare`
is first-class core state that mutates outside OpenRegister (a user opens the
Files share panel directly), so "any OR-side snapshot table would desync
immediately". A share cache would be an access-control bug in both directions — a
stale grant admits someone whose share was revoked, and a stale revocation hides
an object from someone entitled to it.

**The cost this accepts.** The list path needs the principal set *inside a SQL
query*. Read-through means resolving the caller's share records once per request
and passing the resulting principal set into the emitters, rather than joining a
table. That is a per-request resolve, not a per-row one, and it keeps the two
paths reading the same source.

### D3 — `private` composes as a CEILING, and owner/admin admit unconditionally

`private` is a scope on the object, not a rule in the schema. When set:

- the owner is admitted, always;
- an administrator is admitted, always;
- schema-level group rules are **suppressed** — that is the entire point;
- per-object grants are the only other way in.

Owner and admin admits must be unconditional and evaluated FIRST, or an owner can
lock themselves out of their own object — the failure mode that makes a privacy
feature unusable. `private` narrows; it can never widen, so it cannot be used to
grant access the schema would refuse.

### D3a — The scope is stored as the `scope` key of an authorization block

Decided while implementing group 2, because the spec said "an object MAY declare a
`private` scope" without saying where.

`_authorization` (a JSON column that already exists on every magic table) carries
`{"scope": "private"}`; the schema's `authorization` block carries the same key as
the DEFAULT for its objects. The object wins, in both directions — a default is
not a ceiling, so an owner may put their own object back to `organisation`, which
is the Files model.

**Why this rather than a new `_scope` column.** `inheritFromPublic` is already a
non-action key in exactly this block, with its own cascade, so the shape is not
new. A column would need a migration across every existing magic table (2,731 of
them on the dev instance alone) for a value that is NULL on essentially all rows.
The cost is that the predicate is a JSON extraction rather than an indexed
compare; the `IS NULL` disjunct leads it so an unwritten column is decided without
touching the JSON. Promote to a column if list performance ever demands it.

**Two things this forces, both deliberate.**

*Strict validation at authoring time.* `Schema::validateAuthorizationRules()` now
accepts `scope` and rejects anything outside the vocabulary. That is not
redundant with the runtime fail-closed: validation gives a schema author an error
instead of an invisible object, and fail-closed still covers a value that arrived
by import, by direct write, or from a version that knew a scope this one does not.

*Non-admins cannot yet set it.* `stripSelfInjectionFields()` strips
`authorization` from non-admin writes, so an ordinary owner cannot make their own
object private through the object API. Enforcement is complete and testable
without that; the owner-may-set-`scope` carve-out belongs with the grant API in
group 4, and it is safe there precisely because `scope` can only ever narrow.

### D3b — A grant makes a private row behave as NOT private; the schema stays the ceiling

Decided while starting group 4. The spec is explicit that `private` cannot widen:

> **WHEN** a schema's rules would refuse a user an action, and a private object of
> that schema invites them for it — **THEN** the request is still denied.

So a grant is not an independent admit path. The schema's rules are the CEILING of
who could ever reach an object; `private` narrows that to the owner; a grant
re-opens it, **within the ceiling**, for one principal.

That reading resolves what looked like a contradiction with D3 ("schema group rules
are suppressed for a private object"). Suppressed as a *grant* path — a group rule
no longer admits anyone to a private object on its own — but still applied as a
*ceiling*. Both statements are true of the same evaluation.

It also makes the implementation one substitution rather than a new branch. The
list predicate was:

    owner  OR  (notPrivate AND rules)

and becomes:

    owner  OR  ((notPrivate OR grantedToMe) AND rules)

A grant makes a private row behave, for this caller, exactly as an ordinary row —
and the rules then decide, as they already do. Nothing else in either emitter
changes, and there is no second admit path to keep in step with the first.

For the credential and flow cases this is the right shape: the schema grants `read`
to `authenticated` (the ceiling is "any logged-in user"), the object is `private`
(nobody but the owner), and a grant picks out the one colleague.

### D3c — The tenant edge is enforced by the EXISTING organisation filter, forced on

A grant must never become a cross-tenant hole (ADR-002: the organisation UUID is
the only tenant key). The tempting fix is an `_organisation = :activeOrg` term
inside the grant branch — but that is a second definition of the tenant edge, and
this change exists because second definitions of a rule drift apart.

`MagicSearchHandler::applyAccessControlFilters()` already decides whether to apply
`applyOrganizationFilter()`, and already SKIPS it when the schema has conditional
rules that deliberately cross tenants. So the grant branch must extend that
existing decision — when the caller reaches rows through a grant, the organisation
filter is applied — rather than carrying its own copy of the edge.

Cross-organisation sharing is a real future case (group 7, federated principals)
and is a deliberate decision to take there, not a side effect to inherit here.

### D4 — All four enforcement points, or none

`private` and per-object grants land simultaneously in:

| path | what it decides |
|---|---|
| `PermissionHandler` | the single-object verdict |
| `MagicRbacHandler::hasPermission()` | the relation path's verdict |
| `MagicRbacHandler` QueryBuilder emitter | list endpoints |
| `MagicRbacHandler` raw-SQL emitter | the search path |

A live-database verdict-parity matrix is part of this change. Its predecessor
shipped one and it found a real pre-existing bug on its first run; a principal
honoured on `find` but not `list` presents as an empty page, and the reverse
leaks an object.

The matrix must run WITH a session: `applyRbacFilters()` deliberately bypasses
RBAC when there is no user and `PHP_SAPI === 'cli'`, so a sessionless test reports
a fail-open divergence that does not exist.

### D5 — Federated principals are principals

A remote principal is one more thing an object can be shared with. That is
satisfied structurally rather than with new code: `IShare::TYPE_REMOTE` and
`TYPE_REMOTE_GROUP` sit in the SAME lists as the user and group types, both in
`ObjectGrantResolver` and in `ObjectSharingService`. A remote grant therefore
flows through the same resolve, the same SQL disjunct and the same PHP verdict.
There is no federated branch to keep in step with the local one, which is the
property worth having. `FederatedPrincipalVocabularyTest` pins both lists,
because the property is invisible and nothing else would notice it being edited
away.

**CORRECTION — this decision previously said to reconcile object grants with
`FederatedShare`. That was wrong, and following it would have been a mistake.**

They are not two shapes of one thing:

| | `FederatedShare` | a `TYPE_REMOTE` object grant |
|---|---|---|
| what is shared | a register / schema / object / query | one object |
| who with | an ORGANISATION on a peer instance | a remote USER or group |
| how authorised | OpenRegister's own scoped bearer `shareToken` | core's OCM federation |
| served by | `federation#objects`, proxied by `FederatedObjectSourceProvider` against the peer's OpenRegister base URL | the ordinary RBAC filter |

Folding a per-principal grant into an instance-to-instance transport would give
the grant a second decision path — the exact thing D4 forbids — and would put
object-level RBAC behind a bearer token that was designed to authorise a whole
register. They stay distinct, for the same reason file shares stay distinct
(task 8.2): sharing a container and inviting a person are different acts.

**What is NOT proven.** That an inbound federated grant from a real peer admits a
real remote user needs a SECOND Nextcloud instance and an OCM handshake. No
single-instance test substitutes for it, and none here pretends to — tasks 7.2
and 7.3 stay open with that stated.

### D6 — One primitive: the bespoke `sharedWith[]` is superseded

Credentials and flows grew `sharedWith[]` + `sharedUsers` + `sharedGroups` +
a `$contains` operator because no primitive existed. Once this lands, the broker's
Guard 1c consumes the shared primitive and the per-schema copies are migrated
away.

`$contains` itself stays — it is general RBAC vocabulary now, implemented on both
SQL emitters through one builder, and it is what makes a principal-list predicate
expressible at all.

**THE AUDIT (task 8.1), and it corrects the count above.** This decision used to
say leaving both would be "the fourth share concept". Counting properly, there
are FIVE in openregister alone — 32 files — and only ONE of them is a duplicate:

| concept | shares WHAT | with WHOM | verdict |
|---|---|---|---|
| object grants (this change) | one object | a principal | **the primitive** |
| `ShareLinkService` (7 files) | the FILES in an object's folder | a principal or a link | keep — a file is not an object (8.2) |
| `FederatedShare` (5 files) | a register / schema / object / query | an ORGANISATION on a peer instance | keep — an instance transport (D5) |
| `ShareableConfigType` (11 files) | an app's CONFIGURATION as portable files, over GitHub | other INSTALLATIONS | keep — distribution, not access |
| bespoke `sharedWith[]` (18 files) | a credential, a flow | a principal | **migrate — this is the duplicate** |

`ShareableConfigType` is the one this design had not accounted for at all. It is
not an access-control mechanism: it packages configuration for publication and
installation elsewhere, and a type is explicitly allowed to keep its
configuration outside OpenRegister entirely. Folding it in would confuse
"distribute this configuration" with "let this person read this row".

**Across the fleet**, the same audit finds bespoke principal lists in exactly the
places the primitive is meant to serve:

- **launchpad** — the manifest register carries its own `sharedWith[]`, and
  `ManifestController` selects "objects the user owns OR that list the user in
  `sharedWith`". That is the object-grant primitive, reimplemented.
- **doriath** — `DashboardService` counts `sharedWithMe`. This is the dashboards
  case that motivated the whole change.
- **opencatalogi** — three files, all FILE-oriented (`FileService`,
  `DownloadService`, `EventService`). Not principal grants; nothing to migrate.

So task 8.3's real scope is three consumers, not "the fleet": openregister's
credentials and flows, launchpad's manifests, and doriath's dashboards.

### D7 — nc-vue: one widget, one tab, one component underneath

A `shared-with-me` dashboard widget and a detail-page **Shares** tab, both over
one component, so an app gets either by declaring it. The tab mirrors the Files
share panel: invite by user, group or email; create a link; set expiry; revoke.

### D8 — Flows are sequenced last, and their change is BREAKING

Flows have `authorization = NULL` (open tenant-wide) and three unguarded run
entry points. Making them private-by-default plus shareable removes access that
exists today. It also unblocks `credentialIdentity: owner` from the previous
change, which must not ship before it.

## Risks / Trade-offs

- **[A stale share verdict]** → read-through only (D2); no OR-side share cache,
  matching `ShareLinkService`'s documented reasoning.
- **[A principal honoured on one path only]** → D4's parity matrix over a live
  database, with a session, and a positive control that it fails when one side is
  disabled.
- **[An owner locked out of a private object]** → owner and admin admits are
  unconditional and evaluated first, with an explicit scenario.
- **[`private` used to widen access]** → it suppresses schema group rules and adds
  only owner/admin/invited; it can never admit someone the schema refuses.
- **[Flows losing open access]** → sequenced last, called out as breaking, and
  the gate on `credentialIdentity: owner`.
- **[A per-request share resolve on hot list paths]** → resolved once per request
  and passed to the emitters; measure before optimising, and never by caching
  core state.

## Migration Plan

1. `private` principal + per-object grants across all four paths, with the parity
   matrix. Opt-in, so nothing changes on upgrade.
2. The share provider surface: links, email invites, expiry, revocation.
3. nc-vue widget + tab.
4. Federated principals.
5. Fleet audit; migrate the bespoke `sharedWith[]`; broker Guard 1c consumes the
   primitive.
6. Flows: private-by-default + run authorization (breaking), which unblocks
   `credentialIdentity: owner`.
7. Documentation.

**Rollback:** every step before 6 is additive and opt-in — an object with no
`private` scope and no grants is decided exactly as it is today.

## Open Questions

Each blocks work below it, so each is stated as a decision with its consequence.

### Q1 — ANSWERED: do not register a provider; share the object's FOLDER

Researched in core. The answer is a negative finding followed by a better route.

**You cannot register an object share provider.** `IShareProvider` is bound to
Files at the interface level — `getSharesInFolder($userId, Folder $node, …)`,
`getSharesByPath(Node $path)`, and `getSharesBy`/`getSharedWith` all take a
`Node`. `IShare` itself requires `setNode(Node $node)` and returns
`getNodeId(): int`, non-nullable. An `IShare` **is** a file share. `TYPE_ROOM`
(Talk) and `TYPE_DECK` are not counter-examples: they share files INTO a room or
a card, they do not share rooms or cards.

**Every OR object already has an NC folder, and it is created on demand.**
`FolderManagementHandler::getObjectFolder()` creates the folder when the object
has none, so an object always has a `Node` to hang a share on.

**`ShareLinkService` already reads exactly the right six share types** on that
folder, through core's `IManager`, with no cache:

    TYPE_USER  TYPE_GROUP  TYPE_LINK  TYPE_EMAIL  TYPE_REMOTE  TYPE_REMOTE_GROUP

which is, in order: user grants, group grants, **link shares** (Q4),
**account-less email invitations** (Q2), and **federated principals** — every
surface this change asked for, already reachable through core, already
folder-resolved per object.

**DECIDED.** The object's folder IS the share target. Core owns the share
records exactly as it does today; OR's evaluator reads the folder's shares and
resolves them into principals. That delivers (a)-for-the-surface and
(b)-for-the-verdict without a provider, and the read half largely exists.

**Consequences to accept, and they are real:**

- **Sharing an object also shares its files.** The folder holds the object's
  attachments, so a grant on the folder exposes them. For most objects that is
  the desired Files-like behaviour; where it is not, it needs saying out loud in
  the UI rather than discovering it.
- **File permissions are file verbs.** Core's permission bitmask is
  READ/UPDATE/CREATE/DELETE/SHARE. Object verbs (`run`, `use`) do not exist in
  it, so they ride in `IShare`'s `IAttributes` bag (`setAttribute(scope, key,
  value)`) — the same extension point other apps use. Q5's uniform core set maps
  onto the bitmask; per-schema extensions live in attributes.
- **A folder is created on first share** for an object that had none, which is a
  visible side effect worth expecting.

### Q2 — DECIDED: mirror Files (account-less email link)

An email invitation is `IShare::TYPE_EMAIL` on the object's folder — core's own
account-less link-addressed-to-an-email, which `ShareLinkService` already reads.
Requiring an account first would defeat inviting a colleague who does not have one.

### Q3 — DECIDED: both, with the object overriding the schema

The schema carries a default for new objects; an object may override it. The
schema default is what makes a whole schema private-by-default, which is exactly
what the flow step (group 9) needs. Object-only would not reach it.

### Q4 — Does a public link share on an object contradict ADR-006?

ADR-006 Rule 2 says: *"To publish, grant a read scope, do not set a field"* —
publication is a schema-level RBAC change, deliberately not a per-object flag. A
per-object PUBLIC link share is per-object publication by another route.

**DECIDED: amend ADR-006.** A link is a CAPABILITY — a bearer token, revocable,
expiring, attributable — not a visibility flag, and that is a different thing from
`published: true`. Rule 3's actual concern is that consumers must not treat a data
field as a security boundary; a core-issued token evaluated by core is not a data
field. The ADR gains that distinction explicitly, so the next reader does not have
to infer it.

### Q5 — DECIDED: uniform core set, optional per-schema extensions, ADR-guarded

The core set maps onto core's permission bitmask (READ / UPDATE / CREATE / DELETE
/ SHARE). Per-schema verbs (`run`, `use`) are extensions carried in `IShare`'s
`IAttributes` bag, so the one shares component renders the core set without being
taught each schema, and a schema that needs more declares it.

An ADR governs the extension so the vocabulary cannot sprawl: an extension verb
SHALL be declared by its schema, SHALL be enforced at the endpoint performing the
action (RBAC grants visibility only), and SHALL NOT redefine a core verb.

### Q6 — DECIDED: yes, separate

`read` (see that the credential exists, to pick it in a UI) is weaker than `use`
(spend it through the broker). They are the difference between "you may see this
exists" and "you may spend it", so they are separate verbs and `use` implies
`read`.

### Q7 — DECIDED: yes, they collapse — but only the ACCESS half

`scope: personal` becomes `private` with no invitations; `scope: organisation`
becomes the default organisation scope. One vocabulary for one concept, which is
the point of this change.

**HARD CONSTRAINT: `scope` has two jobs, and only one of them collapses.**

Verified in `NextcloudVaultCredentialStore`: `scope` also selects the **vault
owner the secret is stored under** — `personal` stores it under the current user,
`organisation` under a reserved SYSTEM identity (the empty-string user). That is a
STORAGE decision, not an access-control one.

So the collapse applies to the access dispatch in the broker's Guard 1 only. The
storage selector MUST survive verbatim. Removing `scope` outright, or deriving the
vault owner from the new `private` scope, would look up every existing
organisation credential's secret under the wrong owner and make it unreadable —
the secret is not lost, but nothing can reach it, which is indistinguishable from
loss at the point of use.

The safe shape is therefore: keep the stored `scope` value as the vault-owner
selector, and stop using it as the access discriminator. Sequenced in group 8,
after the primitive is proven, with a test that an organisation credential minted
BEFORE the change is still readable after it.

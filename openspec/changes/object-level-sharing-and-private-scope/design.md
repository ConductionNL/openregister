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

### D1 — Core owns the share RECORD; OpenRegister owns the VERDICT

An OR share provider is registered with `OCP\Share\IManager`, so an object share
is a real Nextcloud share: link shares, email invites, expiry, password and
federation all come from core rather than being rebuilt. The **authorization
verdict stays in OR's RBAC evaluator**, which reads core's share records and
translates them into principals.

**Why split it.** The surface is where core is strongest — it already has the
share lifecycle, the token, the mailer, the expiry job, the federation handshake,
and a UX users recognise. The verdict is where OR is load-bearing: both
enforcement paths (single-object and list) already meet in OR's evaluator, and
that is the only place a decision can be made consistently for `find` and `list`
alike. Moving the verdict into core would split it across a boundary core cannot
see — core does not know about registers, schemas, organisations, or the SQL
emitters that filter list queries.

**Alternatives rejected.** Wholly core (a) cannot express register/schema
scoping or the organisation edge, and cannot filter a magic-table list query.
Wholly OR-native (b) means reimplementing links, email delivery, expiry and
outbound federation that core already ships — and the previous change's own
experience is that every re-implementation of an existing mechanism is where the
divergence bugs come from.

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

A remote principal is one more thing an object can be shared with, resolved
through the existing `OpenRegisterCloudFederationProvider`. `FederatedShare`
already carries `objectUri`, `sharedWith`, `permissions` and `shareToken`, so the
inbound half exists; this change gives it the same principal vocabulary as a local
grant instead of a second decision path.

### D6 — One primitive: the bespoke `sharedWith[]` is superseded

Credentials and flows grew `sharedWith[]` + `sharedUsers` + `sharedGroups` +
a `$contains` operator because no primitive existed. Once this lands, the broker's
Guard 1c consumes the shared primitive and the per-schema copies are migrated
away. Leaving both would be the fourth share concept this change exists to
prevent.

`$contains` itself stays — it is general RBAC vocabulary now, implemented on both
SQL emitters through one builder, and it is what makes a principal-list predicate
expressible at all.

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

- Which NC share type an object share registers as, and whether one provider can
  serve both objects and the existing file shares without confusing the Files UI.
- Whether an email invite to a non-user creates a link share addressed by email
  (Files' behaviour) or requires an account first.
- Whether `private` belongs on the object, on the schema as a default for new
  objects, or both.

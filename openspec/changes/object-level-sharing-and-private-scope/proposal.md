## Why

Object access in OpenRegister is decided at the **schema** level and defaults to
the whole organisation. A schema's `authorization` block names groups and users;
every object of that schema then answers to the same rule. There is no way to say
"this one object is mine, and these three people may see it".

That gap is now blocking real features rather than being theoretical. Sharing a
flow, sharing a credential, and sharing a Launchpad dashboard are the same
problem three times, and each has been reaching for its own answer. The
`shared-credentials-and-flows` change added a per-object `sharedWith[]` list plus
two derived scalar lists and a `$contains` RBAC operator specifically to work
around the absence of a per-object primitive — and that work established the
mechanics, but as a one-off on two schemas.

Meanwhile the same investigation found the other half of the problem: flows have
**no authorization at all** (`authorization = NULL`, so open tenant-wide, and
three unguarded run entry points). So the fleet needs both halves — a way to make
an object private, and a way to invite people back into it.

## What Changes

- **`private` becomes a first-class RBAC principal**, alongside the existing
  `public`, `authenticated`, `admin` and `user:<uid>` vocabulary. An object in a
  `private` scope answers to its owner and to administrators only, whatever the
  schema's group rules say.

- **Access can be granted per OBJECT, not only per schema.** An invitation names
  a user or a group on one object. Today's schema-level rules keep working
  unchanged; per-object grants narrow *within* them.

- **Organisation stays the default scope.** Nothing becomes private implicitly —
  `private` is opt-in, so no existing object changes visibility on upgrade.

- **The share surface mirrors Files**: a share link, and an invitation by email,
  because that is the interaction users already know from Nextcloud.

- **Federation is a share type, not a parallel system.** OR already receives
  federated shares (`OpenRegisterCloudFederationProvider` implements
  `ICloudFederationProvider` with `shareReceived()`), and `FederatedShare` already
  carries `objectUri`, `sharedWith`, `permissions` and a `shareToken`. A remote
  principal should be one more principal an object can be shared with.

- **nc-vue gains a shared surface twice**: a dashboard widget ("shared with me")
  and a detail-page tab, so every app in the fleet gets the same affordance
  without building it.

- **BREAKING, deliberately**: flows gain read and run authorization. They have
  none today, so this removes access that currently exists — see the risk note.

## Capabilities

### New Capabilities

- `object-level-sharing` — the per-object invitation primitive: who may grant,
  what a grant means, how it is enforced on both the single-object and list
  paths, and how it interacts with schema-level rules.
- `private-object-scope` — `private` as an RBAC principal: what it admits, how it
  composes with the schema block, and why it cannot be implicit.
- `share-links-and-email-invites` — the Files-like surface: a tokenised link, an
  emailed invitation, expiry and revocation.

### Modified Capabilities

- `credential-broker` — Guard 1c currently reads a bespoke `sharedWith[]`. Once
  the primitive exists, the broker should consume the shared primitive rather
  than its own copy of the shape.
- `rbac-scopes` — the principal vocabulary gains `private`.

## Impact

### The fork this change turns on

**Does an object share go through Nextcloud's own share machinery, or through an
OpenRegister-native one?**

- **(a) Register an OR `IShareProvider` with `OCP\Share\IManager`.** Objects
  become shareable by the same mechanism as files, so link shares, email
  invites, expiry, and federated shares come from core rather than being
  rebuilt. It is the literal reading of "mimic the Files behaviour" and "tie it
  into NC federation". OR does **not** register one today — verified.
- **(b) An OR-native per-object share.** Full control over the object semantics
  (register/schema scoping, query-shaped shares, organisation interaction), at
  the cost of building links, email delivery, expiry and outbound federation by
  hand — all of which core already has.

This is the first thing the design must settle, because almost every task below
differs between them. My reading is that (a) is right for the *surface* — links,
email, federation, and the UX users expect — while the *authorization verdict*
must stay in OR's RBAC evaluator, because that is where both enforcement paths
already meet. A hybrid is likely: core owns the share records and their
lifecycle, OR's evaluator consumes them as principals.

### Three share concepts already exist and must not become four

Verified in the code:

| concept | mechanism | scope |
|---|---|---|
| **File shares** | `ShareLinkService` → `OCP\Share\IManager` | files inside an object's NC folder; deliberately no cache, `IShare` is the source of truth |
| **Federated shares** | `FederatedShare` + `FederationShareService` + `OpenRegisterCloudFederationProvider` | cross-instance, per object URI or per query filter |
| **Object RBAC** | schema `authorization` block | every object of a schema |

Plus the one this supersedes: the bespoke `sharedWith[]` on credentials and
flows. Part of this change is consolidating that, not adding a fifth.

### Enforcement points that must all agree

The principal vocabulary is resolved in **four** places, and a principal honoured
in some but not all is a silent access-control bug — the
`shared-credentials-and-flows` change already found two such divergences
(`$user.groups` resolving only on `find`; `authenticated` admitting only on
`list`). `private` must land in all four at once:

- `PermissionHandler` — single-object verdict
- `MagicRbacHandler::hasPermission()` — the relation path's verdict
- `MagicRbacHandler` QueryBuilder emitter — list endpoints
- `MagicRbacHandler` raw-SQL emitter — the search path

A verdict-parity matrix over a live database is therefore part of this change,
not a follow-up.

### Fleet-wide surfaces to audit

`openregister` (12 files), `launchpad` (3), `opencatalogi` (3), `doriath` (1)
already reference share concepts. Each needs checking against the new primitive
rather than being left with its own.

### Risks

- **Flows lose open access.** They are readable tenant-wide and runnable by any
  authenticated user today. Restricting both is the point, but it is a breaking
  change for anyone relying on it, and it must land before
  `credentialIdentity: owner` from the previous change can be enabled.
- **A private object that nobody can reach.** If `private` composes wrongly with
  the schema block, an owner could lock themselves out; the owner and admin
  admit paths have to be unconditional.
- **Two representations drifting.** If core owns share records and OR caches
  them, they will desync — `ShareLinkService` already documents this exact
  hazard and refuses to cache for that reason.

### Not in scope

Changing the organisation default; per-property sharing (that is
`PropertyRbacHandler`'s concern); and migrating the existing bespoke
`sharedWith[]` data, which needs its own step once the primitive lands.

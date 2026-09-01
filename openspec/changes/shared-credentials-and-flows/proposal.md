## Why

A brokered credential and a flow are both **single-owner** today. The broker's
access guard admits a `personal` credential only when the acting identity
*equals* its `owner`, and a flow is reachable only through its owner's tenant
view. The one way to let anyone else in is `organisation` scope — which is
all-or-nothing across the entire tenant.

So a team has exactly two options, and neither is what they want: keep the
credential private and be the only person who can run anything that needs it,
or widen it to the whole organisation. There is no "these three colleagues".
The same gap makes an automation unshareable: a flow one person builds cannot be
handed to the colleague who needs to run it, so working flows get rebuilt by
hand per user, each with its own copy of the credential.

Both halves are wanted, and both now: organisation-wide scope **and** a narrow
named list of users and groups.

## What Changes

- **A per-object principal share list.** Brokered credentials and flows gain
  `sharedWith[]` — a list of `{ type: user|group, id, permission }` principals.
  Organisation scope stays exactly as it is; the list is an *additional*,
  narrower grant, not a replacement.

- **The broker's access guard gains a third branch.** Guard 1 currently
  dispatches on `scope`: personal → owner equality, organisation → membership.
  It gains: otherwise, admit iff the acting identity matches a `sharedWith`
  principal (a group principal resolved through `IGroupManager`). Ordering and
  fail-closed behaviour are unchanged, and the remaining three guards
  (`allowedApps`, provider allow-rules, host-lock) still apply to every admitted
  call. **This modifies an ADR-004 Rule 4 guard chain and therefore needs an
  ADR-004 amendment.**

- **Sharing a credential grants USE, never disclosure.** A recipient can cause
  the broker to make calls with the credential; they never receive the secret,
  and no share-related response body, projection, export, or audit row carries
  one. This is not new machinery — it is ADR-004 Rule 1 continuing to hold — but
  it is the property that makes credential sharing safe to offer at all, so it
  is stated as a requirement rather than left implied.

- **Flows become shareable**, with `read` (see it) and `run` (trigger it)
  distinguished. A recipient never gains `edit`, so a share cannot be used to
  rewrite the flow it was granted on.

- **A per-flow declaration of whose credential a shared flow runs with.** New
  flow property, two values:
  - `runner` (**default**) — the flow resolves credentials as the user who
    triggered the run. A recipient can run it, using their own credentials.
  - `owner` — the flow *lends* the owner's credential: it resolves as the flow
    owner regardless of who triggered it.

  `owner` is a deliberate delegation of the owner's authority, so: only the
  owner may set it, a share recipient must never be able to flip it, and it must
  not become a way to extract the lent secret.

- **A flow run records the identity it actually resolved credentials as**, so
  "who caused this call" is answerable after the fact for both modes. `FlowRun`
  already carries `triggeredBy`; a lent-credential run needs both that and the
  identity used.

- **APIs to grant, list, and revoke shares** on both object kinds, plus a
  "shared with me" read.

## Capabilities

### New Capabilities

- `credential-sharing` — the share-list primitive on brokered credentials, the
  broker guard branch that honours it, and the use-not-disclosure boundary.
- `flow-sharing` — sharing flows for read/run, the per-flow credential-identity
  declaration, and how a run resolves and records its credential identity.

### Modified Capabilities

- `credential-broker` — Guard 1 (the scope-dispatched access guard) gains the
  share-principal branch. The existing personal-owner and organisation-member
  requirements are unchanged; this is an additional admit path with its own
  fail-closed rules.

## Impact

**Architectural constraints this change must respect** (all four were checked
against the repo ADRs before scoping, and two of them bound the design):

- **ADR-002 Rule 3** explicitly permits Nextcloud groups as *RBAC principals* —
  which is what a share list is. This is what makes group shares admissible.
- **ADR-002 Rule 1** forbids a group from being a *tenant discriminator*. The
  share list therefore MUST NOT become a tenancy key: the organisation UUID
  stays the only tenant boundary, and a share can only ever narrow access
  *within* what tenancy already permits — never cross a tenant edge.
- **ADR-006 Rules 1 and 3** require visibility to be decided by server-side RBAC
  evaluation on read, not by a data flag a consumer filters on. The share list
  is therefore an **input to** the server's access decision, never a
  client-evaluated field and never a second parallel access system.
- **ADR-004 Rule 1** keeps the secret in the vault and out of metadata; Rule 4's
  four fail-closed guards stay in order. Rule 4's text enumerates the guard
  chain, so it needs amending to describe the new branch.

**The two halves land in different enforcement planes, and this is the main
risk.** Credential access is already decided *per object* inside
`CredentialBrokerService`'s guard chain (it reads the object's own `scope` /
`organisation` / `owner`), so the credential half extends a pattern that already
exists and is already the security boundary. Flow *visibility*, by contrast,
goes through OR's object RBAC evaluation, which is schema-level — per-object
principals are a new shape there. The flow half is consequently the riskier one
and is where the design work needs to concentrate.

**Code:**
- `lib/Service/Credential/CredentialBrokerService.php` — Guard 1 branch
  (security-critical; `loadAdmittedCredential`, `assertPersonalOwner`).
- `lib/Controller/CredentialController.php` — share management endpoints;
  `scope`/share metadata projections must stay secret-free.
- `lib/Settings/credential_broker_register.json`, `lib/Settings/flow_register.json`
  — the `sharedWith[]` property and the flow's credential-identity property.
  Register changes need a **forced** import to apply.
- `lib/Service/Flow/FlowRunService.php` — resolve and record the run's credential
  identity (it already sets `triggeredBy` and `organisation`).
- `lib/Db/FlowRun.php` + a migration — persist the resolved identity.
- Object RBAC read path — per-object share principals for flows.
- `appinfo/routes.php` — new share routes (every method needs an explicit auth
  posture).

**Consuming apps** (separate leaf changes, both depending on this one):
- **doriath** — credential share UI, and "shared with me".
- **hermiq** — flow share UI plus the owner-only credential-identity control.
  hermiq's `CredentialScopeResolver` already selects personal → organisation →
  fallback and will need the shared-credential candidate added; because it is a
  *candidate selector* re-validated by the broker's guards, it cannot widen
  access on its own.

**Execution context:** the cron worker (`FlowRunWorker`) runs flows with **no
user session**, so run-time resolution uses the broker's existing trusted
in-process assertions (`actingUserId` / `actingOrganisationId`). Those MUST stay
unreachable from request input — that is what currently prevents a caller from
asserting someone else's identity, and a lent-credential flow makes it more
attractive to try.

**Not in scope:** federated (cross-instance) sharing, and per-node credential
overrides inside a flow. The declaration is per flow.

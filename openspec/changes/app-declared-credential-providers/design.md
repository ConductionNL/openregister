# Design: App-declared credential providers

## Context

The credential broker's provider catalogue (`lib/Settings/credential-providers.json`,
loaded by `ProviderCatalogue`) is deliberately **runtime-immutable** — ADR-004
Rule 3. It is what turns a stored secret into a *bounded* capability:

- `baseUrl` is the **host-lock** — `resolveAndLockUrl()` rebuilds the URL from
  `baseUrl . path` and denies unless the resolved host equals the base host.
- `allowRules[]` is the **path/method bound** — `assertRuleAllowed()` denies
  unless one `{method, pathPattern}` matches via `fnmatch()`.
- `inject_only: true` marks a provider the broker *cannot* bound (an unbounded
  self-hosted host, or a non-HTTP consumer like the `claude` CLI). Those carry
  no `baseUrl` and no `allowRules`; `request()` refuses them outright and their
  secret is reachable only through `resolveInjectable()`, which runs Guards 1+2
  and hands the plaintext to the trusted same-instance caller.

`CredentialBrokerService::request()` runs four fail-closed guards in order:
owner/organisation (IDOR) → `allowedApps` → allow-rule → host-lock. Every denial
funnels through one static 403 with a secret-free reason. `CredentialController::create()`
rejects any `provider` that `ProviderCatalogue::get()` does not resolve.

The cost of that immutability is documented in ADR-004's own consequences:
*"apps cannot self-serve new hosts."* Observed twice while building
hydra-console — no `codeberg`/`forgejo` entry (forcing a `generic-bearer`
`inject_only` fallback with **no** host-lock and **no** rules), and `github`
allow-rules with no issue-label write (openregister#2165). The second was
fixable only because we own this repo. An external app author's real options
today are "wait for an OpenRegister release" or "keep custody of the secret" —
and the second is precisely the failure ADR-004 exists to prevent, so the
immutability is currently *pushing secrets out of the vault*.

Stakeholders: OpenRegister (custody boundary owner), consuming app authors
(hydra-console first, then openconnector's arbitrary Sources), and the
instance administrator who carries the risk of any egress this instance makes.

## Goals / Non-Goals

**Goals:**

- An app can **declare**, as shipped config, the credential providers it needs —
  host, auth scheme, and the exact methods and paths it will call.
- No app can grant itself a host or a path the reviewed catalogue does not
  already permit **without an explicit human approval step**.
- The audit trail records **who** approved, **when**, and **exactly what** they
  approved (a digest), so an approval cannot be silently re-pointed later.
- A declared provider is usable **only by the app that declared it**.
- The `inject_only` vs proxied distinction survives untouched, along with
  `request()`'s refusal to proxy `inject_only` and `resolveInjectable()`'s
  refusal to serve proxy credentials.
- The shipped catalogue file remains runtime-immutable — no API writes it.

**Non-Goals:**

- Broker *capability* work: OAuth2 token exchange, mTLS, request signing, SMTP.
  A declaration expresses exactly what `injectAuth()` can already do — one
  request header carrying one secret. Anything else is out of scope.
- Letting an app declare an `inject_only` provider (see D5).
- Federating or syncing approvals between instances. Approval is per-instance.
- Changing any existing catalogue entry, credential, or guard order.
- A UI for authoring declarations. A declaration is shipped code, reviewed in
  the app's own repo; OpenRegister only renders it for the approver.

## Decisions

### D1 — Where a declaration lives: a shipped JSON file in the declaring app

An app ships `lib/Settings/credential-providers.json` in **its own** app
directory, in the same shape as OpenRegister's catalogue plus the declaration
fields in D3. A `DeclaredProviderLoader` walks `IAppManager::getInstalledApps()`
and reads `IAppManager::getAppPath($appId).'/lib/Settings/credential-providers.json'`,
caching the parsed result in-memory per request — mirroring `ProviderCatalogue`
and `AppHost\Scheduling\ScheduleManifestLoader`. Read errors, malformed JSON and
schema violations fail **soft to empty** (log a warning, contribute nothing), so
a broken declaration denies rather than half-admits.

*Alternatives rejected:* a `POST /api/credentials/providers` registration API —
it makes the catalogue runtime-writable, which is exactly the property ADR-004
Rule 3 protects, and it puts the declaration in mutable state where a compromised
app can rewrite it between approval and use. A `<credential-provider>` block in
`appinfo/info.xml` — Nextcloud's app metadata is not a place other apps parse,
and the JSON shape must match the catalogue byte-for-byte so the broker's guards
operate on one entry shape.

### D2 — Declarative, not imperative (ADR-031)

ADR-031's rule is: when the platform can express behaviour as declared metadata,
prefer that over a service class. This change is that rule applied to egress. An
app does **not** call a `registerProvider()` service method at boot; it ships a
static, reviewable, diffable JSON file, and OpenRegister reads it. Consequences
that matter here and are the reason the declarative form is not merely stylistic:

- **It is reviewable in the app's own PR.** The exact host and rule set appear
  in a code diff, so the app's own reviewers see the egress request before the
  administrator ever does.
- **It is digestible.** A file has a stable content digest, which is what makes
  D4's approval-pinning possible at all. An imperative registration call has no
  artefact to pin.
- **It cannot execute.** There is no app code path that runs inside
  OpenRegister's credential layer — the loader only parses data.
- **It is inert without approval.** A declaration is *a request*, never an
  effect. Nothing in the file changes broker behaviour until D4's admission
  decision resolves, which is precisely the property an imperative registration
  cannot offer (a call that "registers" has already registered).

The one imperative surface added is the administrator's approve/reject/revoke
action, which is a human decision and therefore correctly a command, not config.

### D3 — Namespacing and app-scoping: a declared provider cannot be shadowed or borrowed

A declared provider's effective identifier is `<appId>:<localId>` (for example
`hydra_console:codeberg`). Base-catalogue identifiers contain no colon, so:

- A declaration **cannot shadow** a reviewed provider — the identifier space is
  disjoint. The resolver consults `ProviderCatalogue` first regardless; a base
  hit wins and the declaration layer is never reached for that id.
- A credential minted against `<appId>:<localId>` has `allowedApps` **forced** to
  `[<appId>]` at mint time, and Guard 2 (`assertAppAllowed()`) then enforces it on
  every call unchanged. A second app cannot borrow the credential even if it
  learns the UUID; and an owner cannot widen `allowedApps` on a declared-provider
  credential through `PUT /api/credentials/{id}`.

*Alternative rejected:* flat identifiers with a first-writer-wins registry —
two apps declaring `codeberg` would then race, and an app could take the name a
future reviewed entry wants. Namespacing removes the whole class.

### D4 — Two admission lanes, and why only one needs a human

This is the core of the security model, and the part the PO should confirm.

**Lane A — narrowing (admitted on discovery, no approval).** A declaration may
carry `extends: <base provider id>`. It then MUST use the base's `baseUrl` and
`authScheme` verbatim, and its `allowRules[]` MUST be a **subset** of the base's
rules — each declared rule must have the same `method` and a `pathPattern` that
is equal to, or strictly narrower than, a base rule (a base pattern that
`fnmatch()`-matches every path the declared pattern can produce). Such a
declaration grants **nothing** the reviewed catalogue does not already permit —
it can only *reduce* an app's reach below what it would get by using the base
provider directly. Requiring an approval click for a strictly-safer configuration
would train administrators to click through approvals, which is itself a security
regression. Any `extends` declaration that fails the subset test is **rejected
outright** — it is a bug in the app, not an escalation request, and is never
silently promoted to Lane B.

**Lane B — novel (unusable until an administrator approves).** Any declaration
without `extends`, or one naming a host or path the reviewed catalogue does not
already permit, is `pending`. `resolveProvider()` denies it, so a credential
minted against it fails closed with a distinct, secret-free reason. An
administrator reviews the rendered host + full rule set in OpenRegister's
credential settings and approves, rejects, or later revokes. This is the lane
that resolves both observed costs: `hydra_console:codeberg` at a Forgejo host
(cost 1), and a GitHub declaration carrying issue-label rules the base lacks
(cost 2) — an app author can now ask for those without an OpenRegister release,
and a human still decides.

**Approval is pinned to a content digest.** The approval record stores a digest
over the canonical serialisation of the exact declaration entry approved. On
every resolution the loader recomputes the digest; on mismatch the approval is
**invalid** and the provider returns to `pending`. This closes the
time-of-check/time-of-use hole that would otherwise make the whole approval
theatre: ship a benign declaration, get approved, widen it in the next app
update. Re-approval requires a human to look at the new rule set.

*Alternatives rejected:*
- **Unrestricted app-declared providers** (a runtime-writable catalogue). Any
  app could point another user's secret at a host of its choosing. This deletes
  ADR-004 Rule 3 and the entire value of the broker.
- **Narrowing-only, no approval lane.** Safe, and it is Lane A — but it cannot
  express a host absent from the catalogue, so Codeberg (cost 1) stays
  unsolved and the `generic-bearer` fallback remains the only option. Rejecting
  Lane B would mean shipping a change that does not fix the reported problem.
- **App-scoping (`allowedApps`) as the sole control.** Scoping stops *borrowing*,
  not the declaring app itself aiming a user's token at an attacker-controlled
  host. Necessary, not sufficient — kept as D3, on top of D4.
- **Keep the catalogue immutable; add only a declaration *schema* for reviewed
  inclusion** (declarations as machine-checkable PR material). It removes no
  release from the loop and cannot express a **per-install** host at all — a
  municipality's own Forgejo domain can never appear in a file shipped to every
  instance. This is the option ADR-004's `$fleetComment` already named as
  needing "a per-install, admin-approved provider registration", which is D4.

### D5 — A declaration MUST be a host-locked proxy entry; `inject_only` is rejected

A declared entry MUST carry `baseUrl` and a non-empty `allowRules[]`.
`"inject_only": true` in a declaration is a validation error.

The reasoning is the inverse of what it first looks like. `inject_only` needs no
host and no rules, so it looks like the *easy* thing to allow — but it is the
path where the raw secret **leaves OpenRegister** into the calling app, bounded
by nothing except Guards 1+2. An app-declared `inject_only` entry would therefore
be a secret-egress path with no bound at all, created by the app that receives
the secret. Meanwhile it would add nothing: the reviewed `generic-apikey`,
`generic-bearer`, `generic-basic`, `generic-oauth2` and `generic-jwt` entries
already serve exactly that case for any app, today, unchanged.

So the distinction is preserved and sharpened: `inject_only` remains a
**reviewed-catalogue-only** concept; declarations exist to move apps *up* to a
host-locked proxy entry, which is what hydra-console wanted and could not have.
`request()` still refuses `inject_only`; `resolveInjectable()` still returns
`null` for proxy providers, and therefore returns `null` for **every** declared
provider — a declared credential is always a `request()` credential.

### D6 — Resolution order and the guard chain stay identical

`resolveProvider()` becomes: base catalogue → admitted declaration → deny. An
admitted declaration returns the **same entry array shape** the base catalogue
returns, so `isInjectOnly()`, `assertRuleAllowed()`, `resolveAndLockUrl()`,
`injectAuth()`, `performCall()` and both guard chains are untouched. No new guard
is introduced and no existing guard is bypassed; admission is a *resolution*
concern that happens strictly before Guard 3. That is deliberate — the smaller
the diff inside the guard chain, the smaller the review surface on a custody
boundary.

### D7 — Approval state is an auditable object, not an app-config flag

Approvals are recorded as `credentialProviderApproval` objects in the existing
credential-broker register, written through `ObjectService` like any other
OpenRegister object. This buys ADR-003's immutable hash-chained audit trail for
free: who approved, when, from what previous state, with the digest they saw.
Properties: `providerIdentifier`, `declaringApp`, `declarationDigest`, `status`
(`pending` / `approved` / `rejected` / `revoked`), `decidedBy`, `decidedAt`,
`decisionNote`, `baseUrl`, `allowRulesSnapshot`. Storing the rule snapshot means
the trail shows what was approved even after the app changes or is uninstalled.

*Alternative rejected:* `IAppConfig` key/values. Cheaper to read, but it has no
history — "who approved this" would be unanswerable, which is a hard requirement.
Resolution cost is handled by caching the approval map in-memory per request, the
same way `ProviderCatalogue` caches the file.

### D8 — Fail-closed lifecycle

Disabling or uninstalling the declaring app removes its declarations from
discovery, so its declared providers stop resolving and every credential minted
against them denies — never silently proxies. Approval records are **kept** (the
audit trail is immutable); re-enabling the app re-admits the provider only if the
digest still matches the kept approval. Revocation by an administrator takes
effect on the next resolution, with no cache TTL beyond the current request.

## Seed Data (ADR-001)

This change introduces one schema (`credentialProviderApproval`), so seed data is
required. It ships in `lib/Settings/credential_broker_register.json` under
`components.objects[]` and is imported by the existing credential-broker Repair
step.

**Safety rule specific to this schema:** an approval object is an *admission
decision*. A seed row that reads as `approved` would admit a provider on a fresh
install with no human ever deciding — the exact failure this change exists to
prevent. Therefore **every seed row MUST carry `status: "revoked"`** and a
digest that matches nothing (`sha256:0000…0000`), so that even if a real app
later declared the same identifier, the row could never admit it.

Seed rows (all ADR-001 `example-` prefixed and self-identifying):

- `example-approval-pending-forge` — `providerIdentifier:
  "example_app:example-forge"`, `declaringApp: "example_app"`, `baseUrl:
  "https://forge.example.org/api/v1"`, `allowRulesSnapshot: [{ "method": "GET",
  "pathPattern": "/repos/*" }]`, `status: "revoked"`, `decidedBy:
  "example-admin"`, `decisionNote: "Example seed row — demonstrates the pending
  Lane B shape. Ships revoked so it can never admit anything."`,
  `declarationDigest: "sha256:0000000000000000000000000000000000000000000000000000000000000000"`.
- `example-approval-revoked-forge` — same declaring app, `providerIdentifier:
  "example_app:example-forge-legacy"`, `status: "revoked"`, `decisionNote:
  "Example seed row — demonstrates a revoked decision and its audit fields."`,
  same nil digest.

Related items: none. An approval object has no files, notes, tasks or contacts —
it is a decision record whose only relation is the immutable audit trail
ADR-003 generates for it, which needs no seeding. No `@self.seedExemption` marker
is used: both rows carry the `example-` prefix.

## Risks / Trade-offs

- **An administrator approves a declaration they do not understand** (rule sets
  are dense; approval fatigue is real) → the admin view renders the resolved
  host and every method+path in full, states plainly which lane the declaration
  is in, and Lane A never asks for a click — so the only approvals an
  administrator ever sees are the ones that actually widen reach.
- **Digest drift on every innocent app update** — an app adding an unrelated
  provider changes the file, and if the digest covered the whole file every
  approval would break → the digest is computed **per declaration entry**, over
  a canonical serialisation, not over the file. Only the changed entry
  re-enters `pending`.
- **Subset checking for `allowRules` is where Lane A can go wrong.** A wrong
  "narrower than" test silently widens reach with no human in the loop → the
  test is conservative: same `method`, and the base pattern must
  `fnmatch()`-match the declared pattern with any `*` in the declared pattern
  treated as "could be anything". Anything not provably narrower is **rejected**,
  never escalated. This path carries dedicated tests including the adversarial
  cases (`/repos/*` vs `/repos/*/../../admin`, `*` at the pattern head).
- **A declared host is per-install and unreviewed by Conduction** — an approved
  Forgejo host is trusted by that instance's administrator only → accepted
  deliberately; that is the whole point of a per-install approval, and the
  approval record names the human who took it.
- **Path traversal through a declared `pathPattern`** → unchanged from today:
  `normalisePath()` runs before matching and `resolveAndLockUrl()` re-derives
  and re-checks the host. Declarations add no new URL construction.
- **A compromised app re-declares with a widened rule set** → digest pinning
  (D4) returns it to `pending` and it denies until a human re-approves.
- **Cost: more moving parts on a custody boundary** → mitigated by D6 — the
  guard chain itself does not change, only provider *resolution* does.

## Migration Plan

Additive; no data migration. Order:

1. Ship the `credentialProviderApproval` schema + seed rows via the existing
   credential-broker Repair step (register version bump).
2. Ship the loader, validator, resolver and admin surface. With no app shipping
   a declaration, behaviour is byte-identical to today.
3. hydra-console ships the first declaration (`codeberg`) and its administrator
   approves it; its `generic-bearer` fallback credential is retired.

Rollback: disabling the feature is removing the declaration layer from
`resolveProvider()` — declared credentials then deny (fail closed) and no base
provider is affected. Approval objects remain as an audit record.

## Open Questions

- **Should Lane A really skip approval?** The design argues a strict subset
  grants nothing new and that click-fatigue is the larger risk, but an
  administrator may reasonably want to see *every* provider any app can use,
  even the narrower ones. A middle option is: Lane A is admitted without
  approval but is always **listed** in the admin view as "auto-admitted
  (narrowing)". This design assumes that middle option; PO to confirm.
- **Should a Lane B approval expire?** A host approved in 2026 may not deserve
  trust in 2028. Not specified here; re-approval is currently triggered only by
  digest drift.
- **Does an organisation-scoped credential need an organisation-level approval**
  distinct from the instance administrator's? Assumed no for now — the host-lock
  is an instance-wide egress decision.

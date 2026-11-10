## Context

Verified against `openregister` `development` @ `2aec4e9` (2026-09-11):

- `FlowController::run()` — whole-flow run, gated by `flow.run`
  (`FlowAccess::may()` → `OpenRegisterActionAuthService::can()`), a flat named
  right with **no subject/object dimension**. Per
  `openspec/specs/flow-engine/spec.md`, `flow.run` is seeded `@authenticated`:
  any signed-in user, fleet-wide, unless narrowed.
- `FlowRunController::test()` — the ONLY existing "start partway through a
  flow" capability (`startAt`), added by `or-flow-partial-run` explicitly as
  an *editor* action. It has no `FlowAccess` collaborator at all; its only
  check is that the flow uuid resolves.
- OpenRegister's OTHER authorization system — object-level RBAC
  (register/schema/object permissions, ADR-022/023) — governs every direct
  object mutation (`object-op` in nextcloud-vue, the objects API). It has no
  concept of "may run flow node X."
- `IFlowNodeConfigForm::configForm()` already lets a node type declare typed,
  labelled fields with an `optionsFrom` URL the editor fetches for select
  options — a live catalogue reference, not an inline list. This is an
  existing, precedented mechanism.

The two systems have never been asked to cooperate before. This change is the
first time "run this specific flow behaviour" and "does the caller hold rights
on this specific object" need to be evaluated together.

## Decisions

### RN-1 — Authorization for running one node against one subject (DECIDED 2026-09-12 — Ruben chose (c))

Three shapes were considered:

**(a) Reuse `flow.run` as-is.** Simplest, but per Context above it authorizes
"any signed-in user may run this against any object they can name" — no
tie to the subject at all. A case worker's document-generation button would
then also let them generate documents on cases they have no access to via
OpenRegister's own object RBAC, purely by knowing (or enumerating) case ids.
Rejected as unsafe on its own.

**(b) Gate on the subject's object RBAC only** (does the caller have write/
update permission on the subject object, the same check `object-op` patch
already goes through), with no flow-specific right at all. This ties the
check to the right axis (the case), but it means ANY node any app ships
becomes directly invokable by anyone who can write to some object of the
right register/schema, the moment the app names it in a manifest action —
with no per-node review. A node authored for use only inside a supervised
graph (say, one that sends an external notification, or advances a legal
deadline) would become callable standalone the day someone points a button
at it, whether or not its author ever intended that.

**(c) Both, combined — RECOMMENDED: `IFlowDirectlyInvokable` (opt-in, per
node type) AND object RBAC on the subject (per call).** A node must
explicitly declare itself safe to invoke outside its graph (the interface
from proposal.md — an author decision, made once, in code review, the same
way `IFlowNodeConfigKeys` and `IFlowNodeConfigForm` are opt-in per node
type). Then each call additionally requires the caller to hold write/update
permission on the subject object via the EXISTING object-RBAC path — the
same check `object-op`'s patch/create already performs, reused rather than
reinvented. `flow.run` is not consulted at all for this endpoint: it answers
a different question (may this user operate the flow engine generally) and
combining it with (c) would only make the check stricter than necessary for
no added subject-safety, since `flow.run` carries no subject information to
combine with.

Recommendation is (c). It is the only shape where BOTH "this node was built
to be called this way" and "this caller may act on this object" are true
before the node runs — matching how every other direct-mutation surface in
OpenRegister already reasons (declared capability + object permission), and
adding no NEW security primitive, only composing two that already exist.

**Why this is not decided here anyway:** (c) is a real design commitment for
OpenRegister — it means the object-RBAC system now gates flow execution,
which it has never done before, and every future app wanting a "run this
node from a button" surface inherits that pairing. That is exactly the kind
of change that should not ship on one dossiq feature's say-so. Ruben's
decision needed: adopt (c), or a different shape; and if (c), whether the
object-RBAC check is "write" or a new, narrower permission verb (e.g. a
node-invocation-specific action distinct from the object's general write
right, for cases where "may edit this case" and "may generate documents on
this case" should be separable).

**Resolution (2026-09-12).** Ruben chose (c) as recommended: opt-in node
(`IFlowDirectlyInvokable`) AND the subject's existing object-RBAC permission,
both required, `flow.run` consulted for neither half — it is subject-blind
and seeded `@authenticated`, so it would add no safety and only make the
check stricter for callers it should not be stricter for. The object-RBAC
check uses the EXISTING `update` verb (the same one `object-op` patch/create
evaluates via `PermissionHandler::hasPermission()`), not a new
node-invocation-specific verb: introducing a narrower verb is real product
surface (a new admin-configurable permission, its own seeding story, its own
UI) that no concrete need has asked for yet — `documents-on-the-case` 3.3
needs "may edit this case", which `update` already answers. Revisit if a
future caller genuinely needs "may invoke node X" separable from "may edit
the object" — this design does not foreclose that, it just does not build it
speculatively.

Implemented in `lib/Controller/FlowNodeRunController.php` (a controller
separate from `FlowController`, since neither its authorization shape nor its
CRUD-adjacent concerns match), `lib/Service/Flow/IFlowDirectlyInvokable.php`
(the marker interface), and `FlowRunService::executeNode()` (the "run exactly
one node, not to the end" mode — dispatches the named step directly through
`RegistryStepDispatcher` rather than walking the graph with `FlowEngine`, so
routing to whatever the node points at in its authoring graph is structurally
impossible, not merely unauthorized).

### RN-2 — Config sourcing via `IFlowNodeConfigForm`, not a new manifest grammar

`documents-on-the-case` 3.3 (dossiq) framed this as "`@pick:template` names
nothing" — a manifest-token problem. It resolves as a NODE problem instead:
`DossiqMergeTemplateNode` implements the already-existing
`IFlowNodeConfigForm::configForm()`, declaring `templateSlug` as a `select`
field with `optionsFrom` pointing at dossiq's template list endpoint. The new
`{flowId}/{nodeId}/run` response for a GET-shaped "describe before running"
call (or the manifest action's dispatcher, per the nextcloud-vue proposal)
reads that declaration generically — no new register/schema/filter fields on
the manifest action, no per-app bespoke picker grammar. This is a strict reuse
of an existing, already-shipped interface and needs no product decision.

Implemented as `GET /api/flows/{flowId}/nodes/{nodeId}/run`
(`FlowNodeRunController::form()`), the same URL as the POST that runs the
node. Gated on the SAME node-eligibility half of RN-1 (opt-in required, same
404 for a node that has not implemented `IFlowDirectlyInvokable`) but NOT on
subject permission — there is no subject yet, only a question about which
fields a form needs, and `configForm()` describes field shape, never subject
data.

### RN-3 — The endpoint takes `nodeId` scoped to a flow, not a bare node type

A node's config (and its `IFlowDirectlyInvokable` eligibility) is a property
of the flow document it lives in, not of the node TYPE alone — two flows
could each carry a `DossiqMergeTemplateNode` step with different upstream
wiring. Scoping the route under `{flowId}` keeps "run node X of flow Y"
unambiguous and keeps the RN-1(c) subject check anchored to one real,
inspectable graph position, consistent with how `flow#run` and `flow#test`
are already flow-scoped.

## Risks / trade-offs

- (c) adds one more RBAC lookup per direct-invoke call; negligible relative to
  the node's own work (template render + object write).
- A node author must remember to implement `IFlowDirectlyInvokable`
  deliberately; an oversight fails CLOSED (the endpoint 404s/403s), which is
  the safe direction to fail in.

# ADR-031: Schema-declarative business logic over service classes

## Status
Proposed

## Date
2026-05-06

## Context

OpenRegister has grown a set of schema extension points —
`x-openregister-lifecycle`, `x-openregister-aggregations`,
`x-openregister-calculations`, `x-openregister-notifications`,
`x-openregister-relations`, and `x-openregister-widgets` — that let
an app declare behaviour as schema metadata in its register file
(`lib/Settings/{app}_register.json`) instead of writing PHP service
code.

ADR-024 made the **frontend** declarative (manifest-driven UI,
`CnAppRoot` shell from `src/manifest.json`). ADR-022 said apps consume
OR abstractions over local duplication. But there is no ADR yet that
ties these together with the **backend** declarative path: when OR can
express behaviour as schema metadata, prefer that over a service class.

The 2026-05-06 readiness audit on decidesk surfaced the gap concretely:

- Decidesk's schema register declares 4 lifecycle blocks, 2
  notifications, 1 aggregation, 1 calculation. Recent commits migrated
  Meeting/Motion/Amendment lifecycles, ActionItem calculations + an
  aggregation, Meeting notifications + calendar-provider — proving the
  declarative engine is real and works end-to-end.
- Yet 16 PHP services still exist (~5,500 LOC). Roughly **~3,000 LOC
  across MotionService, VotingService, AgendaService, QuorumService,
  ActionItemAnalyticsService, VotingBehaviourService,
  DecisionNotificationService, MinutesService, and the
  OverdueActionItemsJob** implements state machines, aggregations,
  calculations, or notifications that the schema engine could now
  express declaratively.
- New specs are not yet steering authors toward the declarative path:
  the `besluiten-management` proposal (relocated 2026-04-30) is full
  prose, with zero `x-openregister-*` references.
- The Hydra builder's container CLAUDE.md, the reviewer prompts, and
  the spec-writer skills (`opsx-ff`, `app-create`) make zero mention of
  `x-openregister-lifecycle/-aggregations/-calculations/-notifications`.
  Builder Rule 1 ("copy existing patterns") therefore points at the
  wrong reference: with services as the dominant pattern, the builder
  faithfully produces another service.

Without an explicit "declarative-first" rule:

- Hydra continues to ship service code that should have been schema
  metadata. The migration target slips further away every cycle.
- Spec authors describe behaviour in prose ("lifecycle: submitted →
  debating → voting → adopted") without specifying *whether* it lands
  in the register or as service code. Both satisfy the contract; only
  one inherits the OR-side benefits (audit-trailed transitions, RBAC
  per state, replayability, automatic CloudEvents, MCP discovery,
  declarative GraphQL).
- Reviewers gate on the wrong thing: `hydra-gate-route-auth` enforces
  correct auth attributes on a controller method that *shouldn't have
  been written* in the first place.
- Apps drift: each one re-implements lifecycle/aggregation/notification
  logic in subtly different shapes, blocking clean later migration.

This ADR closes the gap. ADR-022 said "consume OR abstractions over
local duplication". This ADR adds: when the abstraction is a schema
extension, it is consumed *in the schema register*, not in a service.

## Decision

### Schema-declarative business logic is the default

**When OR provides an `x-openregister-*` extension that fits the
requirement, apps MUST declare the behaviour in their schema register
(`lib/Settings/{app}_register.json`) instead of writing a PHP service
class.**

The supported extensions and what they replace:

| Extension | Replaces (in the service layer) | Schema-engine benefits |
|---|---|---|
| `x-openregister-lifecycle` | State machines, transition guards, `setStatus`/`transitionTo`/`advance*Phase` service methods | Audit trail of every transition, RBAC per state, replayable on restore, automatic CloudEvent on every transition, lifecycle-aware queries |
| `x-openregister-aggregations` | "Get summary", "count by status", "average", "participation rate" service methods that loop OR objects and reduce | Single declarative pass; reused by GraphQL queries, dashboard widgets, MCP exposure |
| `x-openregister-calculations` | Virtual / derived fields (`daysOpen`, `statusBadge`, `quorum`, `isOverdue`) computed in PHP at read time | Available on every object without a service round-trip; cacheable; usable by aggregations and lifecycle guards |
| `x-openregister-notifications` | App-local NotificationService methods that watch object events and dispatch to NC notifications + email | Recipient resolution + notification-channel fan-out + email templates + thresholds + scheduled — all declarative |
| `x-openregister-relations` | App-local link tables, relation-filling service methods | Typed relations across the OR foundation; cross-app queries; respect RBAC; cascade rules |
| `x-openregister-widgets` | App-local dashboard-widget service code | Schema-derived widget definitions consumed by `CnDashboardPage`; one widget definition serves every consumer |

This list updates as OR adds extensions. The OR team owns it; PRs
against this ADR land alongside the new extension's release.

### How to apply this rule

1. **For every new feature/spec.** Before the spec's `design.md` is
   finalised, decide for every behaviour whether it is declarative
   (schema register) or imperative (service). If a fit exists in the
   table above and OR's extension is stable, the spec's `tasks.md`
   MUST land the behaviour as a register patch — not a service class.
   If no fit exists, write the service and reference the gap.
2. **For existing services**, migration is opportunistic. Don't
   re-architect existing apps just to satisfy this ADR. But a feature
   that *modifies* `MotionService.castVote()` is the right time to
   migrate the relevant code path to `x-openregister-lifecycle` and
   delete the now-unused service method.
3. **PHP guards remain a legitimate seam.** A lifecycle transition can
   declare `requires: OCA\App\Lifecycle\FooGuard` to call into PHP for
   non-trivial precondition checks (quorum, role, external state).
   The guard is short, focused, single-method, and called *by* the
   lifecycle engine — it doesn't replace it. Working example:
   `decidesk/lib/Settings/decidesk_register.json` Meeting schema's
   `MeetingTransitionGuard` reference.

### What apps SHOULD still write in PHP

Per ADR-003, apps SHOULD still write service code for:

- **External API integrations** (CalDAV, Peppol, ZGW, ORI, TenderNed,
  IMAP, vendor SaaS). The OR engine cannot reach outside systems; the
  adapter is yours to write.
- **Document/PDF/document-template generation** with app-specific
  templates (e.g. `MinutesGenerationService.generateDraft`, ALV PDF
  rendering). The schema engine has no opinion on rendered output.
- **NLP / domain-specific text processing** (e.g.
  `ActionItemExtractionService` extracting action items from minutes
  text). Domain heuristics belong in code.
- **Domain rule engines** that operate *above* schema metadata —
  e.g. `WorkflowService` that *selects* which lifecycle template
  applies for a given governance domain (legislative vs association
  vs corporate). The selector is in PHP; the lifecycle it selects is
  declarative.
- **Lifecycle guards** as called from `x-openregister-lifecycle.requires`.
- **Background jobs that orchestrate external systems** (mail polling,
  IMAP sync, third-party webhooks).
- **Background jobs that walk an object queue and apply a transition**
  (e.g. "every day, mark overdue ActionItems"). OpenRegister does not
  yet have a schema-extension for declarative scheduled processing on
  an object queue. Two patterns are correct here:
    1. **Use a derived field instead of persisting the state.** If the
       transition is purely a function of object state + time
       (e.g. `dueDate < now`), declare it as
       `x-openregister-calculations` (`isOverdue` boolean) and have
       consumers read the calculated field. No job needed; the value
       is fresh on every read. ActionItem already does this for
       `isOverdue` / `daysLate` / `daysOpen`.
    2. **Use OR's `ScheduledWorkflow` + n8n adapter.** When the work
       genuinely needs to run on a schedule (because consumers can't
       compute the answer themselves, or a side effect like a
       notification must fire on a cadence), define an n8n workflow
       and create a `ScheduledWorkflow` entity tying it to a
       register+schema+interval. The `ScheduledWorkflowJob` TimedJob
       evaluates schedules and dispatches via the n8n adapter. See
       `openregister/openspec/specs/workflow-operations/spec.md`. The
       workflow itself is imperative (n8n holds the logic), but it
       lives outside the app and is operator-configurable. This is
       also the path for cross-cutting periodic work like SLA
       evaluations, retention sweeps, and integration polling.

   Authoring a per-app `*Job` class that walks `findAll()` and calls
   `saveObject()` is **only correct when neither (1) nor (2) fits** —
   e.g. the job orchestrates an external system that n8n can't reach.
   That makes it an external-system orchestrator, falling back under
   the previous bullet.

### Anti-patterns

These have all been observed in the fleet (decidesk specifically) and
should be treated as review-blocking on net-new code:

- **Custom state-machine service** for an object whose schema could
  declare `x-openregister-lifecycle`. Examples in decidesk
  pre-migration: `MotionService.transition*`, `AgendaService.advanceBobPhase`,
  the original `MeetingTransitionGuard` registerService (already
  migrated 2026-05-02 in commits `905fa61` / `70af1f4`).
- **Aggregation service** that loops OR objects and computes
  counts/averages/participation rates. Use `x-openregister-aggregations`.
  Example: `ActionItemAnalyticsService.getSummary` (mid-migration via
  commit `e8b1812`).
- **Calculation service** that returns derived fields. Use
  `x-openregister-calculations` with `@self.created` / `@self.{field}`
  references. Example: `ActionItemService.getDaysOpen`,
  `getStatusBadge` — already migrated via `5496c40` and `a533e78`.
- **Custom notification service** that watches events and dispatches.
  Use `x-openregister-notifications` with declarative recipient
  resolvers. Example: `DecisionNotificationService.notifyOnPublish`.
- **Custom relation/link table** between OR objects (whether a real DB
  table or a service-side glue method). Use `x-openregister-relations`
  on the schema. ADR-022 already prohibits parallel link tables; this
  ADR makes the positive form explicit.

### Exceptions

A custom service is acceptable only when:

1. **OR's extension is missing or insufficient.** Open an issue on the
   `openregister` repo referencing this ADR; describe what the
   extension would need. Use a service in the meantime; remove it
   when the extension lands. Document the exception in the spec's
   `design.md` so the reviewer sees it.
2. **The behaviour spans schemas in a way the extension can't
   express** (e.g. a calculation that joins three schemas and applies
   a domain-specific rule the engine can't model). Justify in
   `design.md` and reference the schema-engine limitation.
3. **Profiled performance.** A declarative implementation that
   profiled measurably worse than the bespoke one — with numbers — is
   grounds for keeping the service. Rare; ask first.

Every exception is documented in the spec's `design.md` under a
"Declarative-vs-imperative decision" heading and surfaced to the
reviewer. "We didn't know OR had this extension" is not an exception.

### Enforcement

- **Spec generation** (`opsx-ff` and Specter `app-create` /
  `generate_spec_content`): when a spec mentions "lifecycle",
  "transition", "state machine", "aggregation", "summary", "count",
  "notification", "alert", "calculation", "derived field", "virtual
  field", or "scheduled", the generator MUST produce a
  declarative-vs-imperative decision in `design.md` and a register
  patch in `tasks.md` for the declarative side. The provisional
  default is declarative.

- **Builder** (Al Gorithm, hydra-builder): before authoring a new
  `lib/Service/*Service.php` whose method names suggest lifecycle
  (`transition*`, `setStatus*`, `advance*`), aggregation (`getSummary*`,
  `getStats*`, `count*`, `*Rate`), calculation (`compute*Field*`,
  `derive*`, `get*Display*`), or notification (`notifyOn*`,
  `dispatch*Notification*`) semantics, the builder MUST check whether
  the requirement is expressible via the extensions in the table
  above. If yes, the builder writes a register-file patch instead.
  This rule is mirrored in `images/builder/CLAUDE.md` alongside Rule 0.

- **Reviewer + Security reviewer**: a new Service class whose method
  names match lifecycle/aggregation/calculation/notification semantics
  is a review finding. Severity: **WARNING** on the first cycle of an
  existing app (during opportunistic migration); **BLOCKING** on
  net-new apps and on new schemas in any app.

- **Future**: a soft `hydra-gate-30-prefer-declarative` mechanical
  gate (regex-detection of suspect Service method names; warn-only)
  is a follow-up. Intentionally deferred until we have a
  false-positive baseline from manual review. Track as a Hydra issue
  when this ADR lands.

## Consequences

### Positive

- **Apps shrink.** Decidesk's MotionService, VotingService,
  AgendaService, QuorumService, ActionItemAnalyticsService,
  DecisionNotificationService — ~3,000 LOC of orchestration —
  collapse to schema metadata + thin guards. Pipelinq and procest
  never grow that mass in the first place.
- **Cross-app uniformity.** A "submitted → adopted" lifecycle works
  the same in decidesk (motion), procest (zaak), and pipelinq
  (complaint) — same audit format, same RBAC hooks, same CloudEvents,
  same MCP discovery, same GraphQL exposure.
- **Builder produces less code, less wrong.** The builder writes a
  JSON patch on `lib/Settings/{app}_register.json` instead of
  authoring + testing a new service class. Faster to generate, fewer
  attack surfaces, fewer review findings, smaller PRs.
- **The OR engine compounds.** Every improvement to the
  lifecycle/aggregation/notification engine (bulk-transition,
  priority-aware notifications, schema-derived widgets) lands across
  the whole fleet without per-app work.
- **Specter's intelligence brief becomes more valuable.** Market
  features mapped to `x-openregister-*` extensions in the brief
  short-circuit the design step entirely.

### Negative

- **OR is a bottleneck.** A new declarative pattern an app needs but
  OR can't yet express requires an OR change before the app can use
  the rule. Mitigation: exception (1) above + fast OR iteration on
  extension requests + the OR team prioritising long-tail extensions.
- **Authors need to know the extensions exist.** The onboarding curve
  for a new Conduction developer now includes the seven
  `x-openregister-*` extensions and the schemas they apply to.
  Mitigated by `decidesk/lib/Settings/decidesk_register.json` as the
  canonical example, the design-system tutorial paired with the
  decidesk migration cleanup (Stage B of the readiness plan), and
  explicit prompting in `app-create` / `opsx-ff`.
- **Migration discipline.** Without the soft gate, mechanical-pattern
  violations slip through review. Mitigated by the explicit reviewer
  instruction; revisit if false-positive rate from manual review is
  acceptable enough to harden into a gate.

### Migration

This ADR does not require apps to migrate existing services. Migration
is opportunistic:

- **Net-new apps**: declarative-first from day one. No legacy services.
- **Net-new schemas in existing apps**: declarative-first.
- **Net-new features modifying existing services**: migrate the touched
  code path (e.g. a feature touching `MotionService.castVote()`
  migrates the voting lifecycle to `x-openregister-lifecycle` as part
  of the feature).
- **Periodic cleanup PRs**: each app picks one or two services per
  release cycle; not blocking.

Decidesk is the leading-example reference. The
canonical-example checklist (Stage B of the manifest-readiness plan
tracked alongside this ADR) targets MotionService, VotingService,
QuorumService, ActionItemAnalyticsService, DecisionNotificationService,
and OverdueActionItemsJob as the five high-leverage migrations that
will leave decidesk as the clean canonical reference for the rest of
the fleet.

## See also

- **ADR-022** — apps consume OR abstractions. This ADR is the
  schema-engine dual to that principle. ADR-022's abstractions table
  is updated alongside this ADR to include the seven schema
  extensions.
- **ADR-024** — app manifest. The frontend declarative principle;
  this ADR is the backend declarative principle. Together they
  describe the no-code-glue app target shape.
- **ADR-019** — integration registry. The first concrete instance of
  declaratively-extended OR; this ADR generalises the same idea to
  schema-level behaviour.
- **ADR-001** — data layer (no custom Entity/Mapper). Schema-declarative
  metadata builds on top of this; ADR-031 only applies because all
  data already lives in OR.
- **ADR-003** — backend rules. Lists what apps SHOULD build (external
  integrations, document gen, domain rule engines) — exactly the
  surface that remains as PHP after declarative migration.
- **ADR-013** — container model strategy. The builder runs on Haiku,
  which makes the "follow the explicit rule, don't infer" property
  of this ADR especially load-bearing — Haiku copies the dominant
  pattern unless told otherwise.
- **`decidesk/lib/Settings/decidesk_register.json`** — working examples
  of `x-openregister-lifecycle` (Meeting, Motion, Amendment),
  `-aggregations` (ActionItem), `-calculations` (ActionItem), and
  `-notifications` (Meeting, Decision).
- **`images/builder/CLAUDE.md`** — the builder's container instruction
  sheet, updated alongside this ADR with the declarative-first rule.

## Alternatives considered

1. **Strengthen ADR-022 wording without a new ADR.** Rejected. ADR-022
   is the *principle* (consume OR abstractions). The schema extensions
   are a specific class of abstraction with their own contract,
   migration story, and enforcement points — they earn their own ADR.
   ADR-022's abstractions table gains a row referencing this ADR.

2. **Hard-fail mechanical gate from day one.** Rejected. False-positive
   rate is unknown — a service named `getSummaryReport` that returns
   a rendered PDF should NOT be flagged as "should have been
   x-openregister-aggregations". Start with reviewer judgment +
   warning-level findings; promote to gate when the false-positive
   rate is measured and acceptable.

3. **Auto-migrate existing services on the next pipeline run.**
   Rejected. The migration touches data shape (audit trail format,
   transition events) — automated migration risks silently breaking
   existing consumers. Opportunistic migration tied to feature work
   keeps the blast radius bounded.

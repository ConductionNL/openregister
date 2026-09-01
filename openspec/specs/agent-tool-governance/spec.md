# Agent Tool Governance Specification

**Status**: in-progress (was: active — backend shipped + unit-verified; frontend source-only, bundle deferred; new change in flight)
**Standards**: EU AI Act (Reg. 2024/1689) Art. 12 (record-keeping) & Art. 14 (human oversight)
**Feature tier**: V1

**OpenSpec changes:**
- `openspec/changes/archive/2026-07-13-agent-tool-governance-and-disclosure/` — the Hermiq consumer side of ADR-063: schema-scoped grants with default-deny, progressive tool disclosure via `hermiq.searchTools`, and the per-agent art.12/14 oversight surface (kind: code) — **DONE**
- `openspec/changes/archive/2026-07-13-hermiq-prefer-tool-hints/` — prefers OpenRegister's now-forwarded `scope`/`destructiveHint`/`readOnlyHint` descriptor hints over the verb-suffix classification heuristic, and fails CLOSED (was fail-open) on a hint-less, non-3-segment id (kind: code) — **DONE**
- `openspec/changes/hydra-console-agent-leaves/` — MODIFIED delta: argument-scoped grant form (e.g. `openregister.runFlow?flowId=…&label=in:a,b,c`) enforced at `FacadeToolInvoker`, flow-run owner attribution (refuse unattributed dispatch), and the one approval-gated command grant with a closed label vocabulary (kind: code) — **in-progress**

## Purpose

Hermiq is the sole agent consumer (ADR-034) of the MCP tool catalog OpenRegister derives under
ADR-063 — a coarse `{appId}.{schema}.{search|get|create|update|delete}` template per opted-in
schema, served through the blessed `ToolRegistryFacade`. A catalog that large breaks three things
on Hermiq's side of the facade, and this capability owns all three:

1. **Progressive disclosure** — a resolved catalog above a configurable threshold is not stuffed
   into the model context; a single `hermiq.searchTools` meta-tool is exposed instead, and full
   descriptors load only for the tools the model selects.
2. **Schema-scoped per-agent grants** — the flat `{appId}.{toolName}` whitelist is unusable for
   hand-curation over a coarse derived catalog, so `Agent.tools` gains a wildcard/verb-subset
   grammar with **default-deny** on write/destructive tools.
3. **The art.12/14 oversight surface** — a per-agent view of who invoked what, when, on which
   data, read from OpenRegister's MCP invocation AuditTrail.

Hermiq CONSUMES the derived catalog; it never derives it and ships no tool code of its own
(ADR-063, gate-27). The authoritative authorization boundary stays OpenRegister RBAC at invoke
time — everything here is a governance/UX layer that only ever NARROWS what an agent can reach.
## Requirements

<!--
  RELOCATED SUBSET — the canonical home for this capability is hermiq.

  ADR-099 §5 moved the tool-grant grammar (ToolGrantSet, ToolGrantCodec,
  ToolGrantResolver, ToolReachResolver, ToolGrantResolutionException) into
  OCA\OpenRegister\Service\Capability. The requirements below are the ones that
  code implements, copied VERBATIM from hermiq so their headings — and therefore
  every `@spec` anchor pointing at them — resolve unchanged.

  🔴 THIS FILE IS NOT THE WHOLE SPEC. 4 further requirement(s) live in
  hermiq's `openspec/specs/agent-tool-governance/spec.md` and are NOT duplicated here: they
  describe behaviour hermiq still owns — the `Agent.tools` binding, the approval
  gate, the oversight surface, the CLI transport. Read that file for them.

  WHY A COPY AT ALL. A `@spec` tag is dereferenced by gate-46 against the
  REPOSITORY it sits in, so a cross-repo reference is not expressible: an
  openregister class citing a hermiq spec resolves to nothing, which is the
  ~300-dead-tag shape this fleet already carries from archived changes. The
  duplication is therefore structural rather than an oversight — and it is
  bounded to exactly the requirements the moved code implements, which is why
  this file is a subset and not the original.
-->

### Requirement: Schema-scoped whitelist grants with default-deny for write/destructive tools

The system MUST let a per-agent tool whitelist (`Agent.tools`) be expressed as schema-scoped grants over the derived catalog — an exact tool id, a schema wildcard (`{app}.{schema}.*`), an explicit verb subset (`{app}.{schema}.{verb}`), or a write modifier (`{app}.{schema}.*:write`) — and MUST resolve those grants against the catalog the facade returns. A schema wildcard MUST grant read verbs only; a write or destructive tool MUST be included only when named explicitly or via the write modifier (default-deny).

A grant entry MAY additionally carry argument constraints (`{toolId}?arg=value&other=in:a,b,c`) and MAY end in a `#noapproval` fragment, giving the full grammar `{toolId}[?{constraints}][#noapproval]`. The system MUST split the `#noapproval` fragment off BEFORE splitting on the constraint opener `?`, so that a fragment can never be absorbed into a constraint value or into the base tool id. The fragment MUST NOT participate in grant expansion: a grant resolves to the same catalog id with or without it.

Classification of a tool id as requiring an explicit grant MUST be the UNION of two rules. The first is the existing write/destructive classification, whose precedence is unchanged: (1) the catalog descriptor's declared `scope`/`destructiveHint`/`readOnlyHint` hint, when the descriptor sets one, wins — even over a conflicting verb suffix; (2) otherwise, a 3-segment `{app}.{schema}.{verb}` id classifies from its verb suffix (`create`/`update`/`delete`); (3) otherwise (a hint-less id that is not a 3-segment derived id — a curated or hand-written id) the system MUST classify it write/destructive (fail CLOSED) rather than treat it as read. The second rule is `reach`: a tool whose resolved reach is `instance` or higher MUST also require an explicit grant, whatever its scope. A LOW reach MUST NOT relax the first rule — a tool that is write/destructive under it stays default-denied regardless of reach.

Per-tool annotations (`readOnlyHint`/`destructiveHint`/`scope`/`reach`) MUST be treated as untrusted UX signals used only to RESTRICT — never as the authoritative authorization, which remains OpenRegister RBAC.

`Agent.tools` remains a `string[]` (ADR-035 Decision 4 froze the shape); only the MEANING of each string is extended, so no OpenRegister schema migration is required.

<!-- Previous behavior: classification was the write/destructive rule alone; the grant grammar had no
     fragment, so `#` was unused and a grant ending in `#noapproval` would have been read as part of
     the base id or of the last constraint value. -->

#### Scenario: A schema wildcard grants read verbs only

- **GIVEN** an agent whose `Agent.tools` contains `{app}.{schema}.*`
- **WHEN** the resolver expands the grant against the derived catalog
- **THEN** the resolved set MUST include that schema's read tools (`search`, `get`)
- **AND** the resolved set MUST NOT include that schema's write/destructive tools
  (`create`/`update`/`delete`)
@e2e exclude Resolver-internal expansion with no UI surface; asserted by existing unit tests on the grant resolver.

#### Scenario: A write tool is granted only when named explicitly

- **GIVEN** an agent whose `Agent.tools` contains `{app}.{schema}.*` and `{app}.{schema}.delete`
- **WHEN** the resolver expands the grants
- **THEN** the resolved set MUST include `{app}.{schema}.delete` (named explicitly)
- **AND** the resolved set MUST include the schema's read tools from the wildcard
@e2e exclude Resolver-internal expansion; asserted by existing unit tests on the grant resolver.

#### Scenario: An untrusted read-only hint cannot bypass authorization

- **GIVEN** a tool whose annotation claims `readOnlyHint:true` but whose invocation is denied by
  OpenRegister RBAC for the acting user
- **WHEN** the agent invokes that tool
- **THEN** the system MUST let OpenRegister RBAC deny the invocation at invoke time
- **AND** the annotation MUST NOT be used to grant access the RBAC layer would refuse
@e2e exclude Requires an RBAC-denied invocation driven from a model turn; asserted by unit test.

#### Scenario: A declared hint overrides a conflicting verb suffix

- **GIVEN** a 3-segment derived id whose verb suffix would classify it read (e.g. `.get`) but whose
  catalog descriptor declares `destructiveHint: true`
- **WHEN** the resolver classifies the id
- **THEN** the descriptor's `destructiveHint` MUST win — the id is classified write/destructive
@e2e exclude Classification-precedence assertion; asserted by existing unit tests on the grant resolver.

#### Scenario: A hint-less curated tool fails closed

- **GIVEN** a 2-segment curated/hand-written tool id whose catalog descriptor sets none of
  `scope`/`destructiveHint`/`readOnlyHint`
- **WHEN** the resolver classifies the id for an empty-`Agent.tools` ("all tools") default-deny
  resolution, or the id is invoked without being part of an agent's resolved set
- **THEN** the system MUST classify it write/destructive: excluded from the default-deny resolution,
  and routed through the `human-approval-gate` approval gate rather than dispatched directly
@e2e exclude Fail-closed classification of an un-annotated id; asserted by existing unit tests.

#### Scenario: An external-reach read tool is default-denied

- **GIVEN** an agent with an empty `Agent.tools` ("all discovered tools allowed")
- **AND** a catalog tool whose `scope` is `read` and whose resolved `reach` is `external`
- **WHEN** the resolver applies default-deny
- **THEN** the resolved set MUST NOT include that tool
- **AND** the resolved set MUST still include `read`-scoped tools whose reach is `self` or `user`
@e2e exclude Default-deny resolution over a synthesised catalog; asserted by unit test on the grant resolver.

#### Scenario: A low reach does not relax the write/destructive rule

- **GIVEN** a catalog tool whose `scope` is `delete` and whose resolved `reach` is `self`
- **WHEN** the resolver applies default-deny for an empty `Agent.tools`
- **THEN** the resolved set MUST NOT include that tool
- **AND** the verdict MUST be identical to the verdict this resolver produced before reach existed
@e2e exclude Non-regression assertion comparing pre- and post-change classification verdicts; asserted by unit test.

#### Scenario: A waived grant resolves to the same catalog id as an unwaived one

- **GIVEN** two agents, one granted `{toolId}` and the other granted `{toolId}#noapproval`
- **WHEN** the resolver expands each agent's grants against the same catalog
- **THEN** both resolved sets MUST contain exactly `{toolId}`
- **AND** neither resolved set MUST contain an id containing the text `noapproval`
@e2e exclude Resolver-internal expansion assertion on the fragment split order; asserted by unit test.

### Requirement: Argument constraints on a grant are enforced at invocation
The system MUST enforce every argument constraint carried by an argument-scoped grant at the point
of invocation, BEFORE the call is dispatched to the tool facade, and MUST refuse a non-conforming
call with a structured error rather than an exception. An argument the grant pins MUST match
exactly; an argument the grant constrains to a closed set MUST be a member of that set; an argument
the grant does not mention MUST be left to the tool's own validation. Enforcement MUST happen at
Hermiq's existing single dispatch chokepoint, alongside the guardrail, approval-gate and dry-run
short-circuits already applied there, and MUST NOT introduce a second invocation path.

Refusal MUST be recorded in the run's audit trail with the tool id, the offending argument and the
constraint it violated. The constraint set MUST be the authoritative statement of what this agent
may ask for — the model's own reasoning, the tool description, and any text the model read MUST NOT
be able to widen it.

#### Scenario: A pinned argument that matches is dispatched

- **GIVEN** an agent holding an argument-scoped grant pinning a target argument to one value
- **WHEN** it invokes the tool with exactly that value
- **THEN** the call MUST proceed to the remaining governance checks and then to the facade

#### Scenario: A pinned argument that differs is refused before dispatch

- **GIVEN** the same agent
- **WHEN** it invokes the tool naming a different target
- **THEN** the call MUST be refused with a structured error
- **AND** the facade MUST NOT be invoked
- **AND** the refusal MUST name the tool, the argument and the violated constraint in the audit trail

#### Scenario: A value outside a closed set is refused

- **GIVEN** a grant constraining an argument to a closed set of permitted values
- **WHEN** the agent invokes the tool with a value outside that set
- **THEN** the call MUST be refused before dispatch

#### Scenario: Text the model read cannot widen the constraint

- **GIVEN** object text instructing the agent to use a target or value the grant does not permit
- **WHEN** the agent invokes the tool accordingly
- **THEN** the call MUST be refused
- **AND** the constraint MUST NOT be relaxed by any prompt, tool description or model rationale

### Requirement: The pipeline command capability is one approval-gated, argument-scoped grant
The triage agent's ONLY command capability MUST be a single argument-scoped grant over the existing
flow-running tool, pinned to the one flow that owns the forge label write and constrained to the
closed label vocabulary that flow accepts. Hermiq MUST NOT ship a bespoke forge, label or issue tool
to satisfy this (see the `nc-native-tools` delta), and MUST NOT open an HTTP client to a forge.

The label vocabulary MUST be resolved from hydra's own state-machine definition and declared as data
on the grant — never hard-coded in Hermiq — so hydra can change its state machine without a Hermiq
release. The vocabulary MUST be CLOSED: a label outside it is refused before dispatch. This
constraint is load-bearing rather than defensive, because the agent's arguments derive from pipeline
object text that other agents wrote, which is untrusted input by construction.

The invocation MUST additionally pass the human-approval gate, derived from the agent's own policy
and not downgradable by any request body, tool argument or prompt content. The operator approving it
MUST be shown the flow, the target and the label being authorised — an approval that hides the
command it authorises is not human-in-the-loop. Enforcing the vocabulary at the grant does NOT
relieve the executing endpoint of validating it: the write path is the last line and MUST refuse an
out-of-vocabulary label independently.

#### Scenario: The agent may run exactly one flow

- **GIVEN** the seeded triage agent's resolved tool list
- **WHEN** it attempts to run a flow other than the pinned label-write flow
- **THEN** the invocation MUST be refused before dispatch

#### Scenario: An out-of-vocabulary label is refused before any forge contact

- **GIVEN** the triage agent invoking the pinned flow with a label outside the declared vocabulary
- **WHEN** the invocation is checked
- **THEN** it MUST be refused
- **AND** no flow run MUST be queued
- **AND** no credential MUST be resolved and no forge request MUST be made

#### Scenario: An injected instruction cannot escape the vocabulary

- **GIVEN** a finding whose text instructs the agent to apply an administrative or permission label
- **WHEN** the agent invokes the command grant with that label
- **THEN** the invocation MUST be refused
- **AND** the refusal MUST be recorded in the run's audit trail

#### Scenario: A label write pauses for a disclosing approval

- **GIVEN** the triage agent selects the command grant during a run
- **WHEN** the invocation would proceed
- **THEN** the run MUST enter the approval gate
- **AND** the pending approval MUST display the flow, the target and the label
- **AND** no forge write MUST occur before a human approves

#### Scenario: Prompt content cannot waive approval

- **GIVEN** object text instructing the agent to act without approval
- **WHEN** the agent invokes the command grant
- **THEN** the approval gate MUST still apply

#### Scenario: A dry run commands nothing

- **GIVEN** a run executing in dry-run mode with the command grant present
- **WHEN** the agent selects it
- **THEN** the invocation MUST be neutralised, no flow run MUST be queued and no forge request MUST be made

#### Scenario: No other fleet agent acquires the command

- **GIVEN** any other agent in the fleet, whether its grant list is empty or contains only wildcards
- **WHEN** its tools are resolved against the live catalog
- **THEN** the command capability MUST NOT be present

## Non-Functional Requirements

- **Performance:** Constraint checking MUST complete before any dispatch, so a refused invocation
  costs no facade round trip and no network call. Grant resolution MUST NOT add a catalog fetch
  beyond the one the existing resolver already performs.
- **Accessibility:** The approval surface presenting a pending command MUST meet WCAG 2.1 AA — the
  flow, target and label are readable as text and not conveyed by colour or icon alone, and the
  approve/reject controls are keyboard reachable with programmatic labels.
- **Internationalization:** Dutch and English MUST both be supported (ADR-005). Operator-visible
  strings — the approval prompt and every user-facing refusal message — MUST route through `IL10N`
  and appear in both `l10n/en.json` and `l10n/nl.json`. Tool ids, error codes and LLM-facing
  descriptions stay untranslated English identifiers.

## Acceptance Criteria

- An argument-scoped grant is expressible as one `Agent.tools` string and resolves to the underlying
  catalog tool id with no second catalog entry.
- A narrowed write tool still classifies write/destructive for default-deny, dry-run and approval.
- A conforming invocation dispatches; a non-conforming one is refused before the facade, with the
  tool, argument and violated constraint in the audit trail.
- An agent-queued flow run records the acting owner's UID; an unresolvable owner refuses the
  invocation and queues nothing.
- The triage agent can run exactly the pinned flow and no other.
- A label outside the declared vocabulary is refused with no flow run, no credential resolution and
  no forge request.
- An injected out-of-vocabulary label attempt is refused and the refusal is in the audit trail.
- A command invocation enters the approval gate and the pending approval displays flow, target and
  label; prompt content cannot waive it.
- A dry run queues no flow run and makes no forge request.
- No agent with an empty or wildcard-only grant list resolves the command capability.
- Hermiq's tool catalog gains no forge, label or issue tool, and Hermiq opens no HTTP client to a
  forge.
- Every scenario above is referenced by a Playwright e2e test or carries a reason-bearing
  `@e2e exclude` (gate-19).

## Notes

- **Why this is the deliverable and not a forge service.** The pivot's rule is that porting hydra
  creates no code, and that where code seems necessary the missing flow abstraction is specified
  instead. The grounding sweep confirmed the flow-invocation tool exists but is ungrantable,
  unparameterised and unattributed; those three gaps are exactly what stood between "no code" and a
  bespoke `ForgeLabelService`. Closing them generically leaves the forge write entirely outside
  Hermiq, and gives any future app the same narrowing for free.
- **What Hermiq does NOT own here.** The OpenConnector node or endpoint that performs the label
  write, the flow that composes it, and the vocabulary's members are all owned outside this repo —
  by `hydra-console-openbuild-app`'s `hydra-console-commands` capability (`Requirement: The command
  endpoint performs the forge write server-side`) and by hydra's own state machine. This delta fixes
  only that the grant is narrowable, enforced, attributed and gated.
- **The OpenConnector side does not exist yet.** OpenConnector today registers no MCP tool provider
  and contributes no flow node or resolver, so there is presently nothing for the pinned flow's
  terminal step to call. That is a cross-repo prerequisite, recorded as a deferred question — not
  something this change works around with Hermiq code.
- **Nothing here is statically verifiable in Hermiq's CI.** The tool facade, the flow engine and the
  credential broker live under `OCA\OpenRegister\*`, absent from this repo's analysis environment.
  Live verification only.
- Related ADRs: ADR-022 (consume the fleet's abstractions), ADR-031 (declarative over imperative),
  ADR-035 (frozen `Agent.tools` shape), ADR-041 (cross-app commands), ADR-063 (MCP verb/scope
  hints), ADR-065 (one flow engine).


<!--
  Two further scenarios from the same delta. They sit UNDER a requirement the
  promoted spec already carried, so appending the requirement block would have
  duplicated its heading; only the scenarios that were missing are copied, and
  verbatim, so their anchors resolve unchanged.
-->

#### Scenario: An argument-scoped grant resolves to the underlying tool

- **GIVEN** an agent whose `Agent.tools` contains an argument-scoped grant narrowing a curated tool
  to one pinned target
- **WHEN** the resolver expands the grants against the catalog
- **THEN** the resolved set MUST contain that tool's catalog id, with its declared input schema
- **AND** the resolver MUST NOT invent a second catalog entry for the narrowed form

#### Scenario: Narrowing does not downgrade classification

- **GIVEN** an argument-scoped grant over a tool classified write/destructive
- **WHEN** the tool is classified for default-deny, dry-run and approval purposes
- **THEN** it MUST still classify write/destructive
- **AND** the narrowing MUST NOT cause it to be treated as read-only or auto-allowed

## ADDED Requirements

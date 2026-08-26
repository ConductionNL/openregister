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
### Requirement: Progressive tool disclosure for large catalogs
The system MUST NOT place every tool descriptor into the model context when an agent's resolved tool
catalog exceeds a configurable threshold (`IAppConfig('hermiq', 'tools.disclosureThreshold')`,
default **30**); it MUST instead expose a single `hermiq.searchTools` meta-tool and load full
descriptors only for the tools the model selects via that meta-tool (deferred loading). Below the
threshold, all resolved descriptors MAY be placed in context as today.

#### Scenario: A resolved catalog exceeds the disclosure threshold

- **GIVEN** an agent whose resolved (grant-filtered) tool catalog contains more tools than the
  configured disclosure threshold
- **WHEN** the engine assembles the agent's turn
- **THEN** the system MUST place only the `hermiq.searchTools` meta-tool (plus any always-on tools)
  into the model context
- **AND** the system MUST NOT place the full set of tool descriptors into the context

#### Scenario: The model searches for and then invokes a deferred tool

- **GIVEN** progressive disclosure is active for an agent turn
- **WHEN** the model calls `hermiq.searchTools` with a query
- **THEN** the system MUST return only descriptors from that agent's already-resolved
  (grant-filtered, default-denied) set that match the query
- **AND** the system MUST make the matched tools invocable on a subsequent turn
- **AND** the system MUST NOT return, or make invocable, any tool outside the agent's resolved set

#### Scenario: A small catalog does not trigger disclosure

- **GIVEN** an agent whose resolved catalog does not exceed the threshold
- **WHEN** the engine assembles the turn
- **THEN** the system MAY place all resolved descriptors directly into context
- **AND** the `hermiq.searchTools` meta-tool need not be present

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

### Requirement: Per-agent tool-invocation oversight surface (AI Act art.12/14)
The system MUST provide, per agent and tenant-scoped, an oversight view of that agent's tool
invocations — tool id, acting identity, parameter summary, result summary, data touched, and
timestamp — sourced from OpenRegister's MCP invocation audit log, with a retention note and an
export (CSV + JSON). The system MUST NOT fabricate rows when no invocations have been recorded.

#### Scenario: An operator reviews an agent's tool activity

- **GIVEN** an agent that has invoked several tools across past runs
- **WHEN** an authorized operator opens the agent's oversight view
- **THEN** the system MUST list the recorded invocations (newest first) with tool id, acting
  identity, parameter summary, result summary, data touched, and timestamp, scoped to the operator's
  tenant
- **AND** the system MUST offer an export of those rows

#### Scenario: The richer invocation audit shape is not yet available

- **GIVEN** OpenRegister has not yet written the richer per-invocation MCP audit entries
- **WHEN** the oversight view loads
- **THEN** the system MUST degrade to the coarser `run`/tool-call audit entries already available
- **AND** the system MUST indicate the reduced detail rather than erroring or fabricating rows

#### Scenario: An agent has no recorded invocations

- **GIVEN** an agent that has never invoked a tool
- **WHEN** the oversight view loads
- **THEN** the system MUST render an empty state
- **AND** the system MUST NOT display any fabricated invocation row

### Requirement: The tool-catalogue surface exposes reach alongside scope

The system MUST include each tool's resolved `reach` in the grant-annotated tool catalogue it returns for an agent, alongside the existing `scope` and grant annotation, so that an operator configuring grants can see how far each tool reaches without reading source. The system MUST NOT return a catalogue entry with no `reach`; where a descriptor declares none, the fail-closed `external` value MUST be returned rather than an absent or null field.

#### Scenario: Every catalogue entry carries a reach

- **GIVEN** an agent with a resolved tool catalogue containing both native and derived tools
- **WHEN** an authorized operator reads that agent's tool catalogue
- **THEN** every entry MUST carry a `reach` drawn from the closed vocabulary
- **AND** no entry MUST carry an absent or null `reach`
@e2e Playwright: seed an agent, GET its tool catalogue through the API and assert every entry carries a reach from the closed vocabulary.

#### Scenario: A tool whose descriptor declares no reach is returned as external

- **GIVEN** a catalogue entry whose descriptor declares no `reach`
- **WHEN** the catalogue is returned
- **THEN** that entry's `reach` MUST be `external`
@e2e exclude Requires a descriptor with no reach in the live catalogue, which the shipped provider does not produce; asserted by unit test on the catalogue assembler.

## User Stories

- As a municipal CISO, I want to see exactly which tools an agent invoked, when, and on which data,
  so that I can demonstrate EU AI Act Art. 14 human oversight rather than merely assert it.
- As an operator, I want to grant an agent a whole schema's read access in one entry, so that I do
  not hand-curate dozens of derived tool ids.
- As an operator, I want a wildcard to NEVER silently hand an agent `delete`, so that the safe
  choice is the zero-config one.
- As an agent builder, I want a large tool catalog not to blow my model's context, so that accuracy
  and cost stay sane as the fleet grows.
- As a security reviewer, I want tool annotations treated as untrusted, so that a spoofed
  `readOnlyHint` cannot escalate past OpenRegister RBAC.

## Acceptance Criteria

- [x] `Agent.tools` accepts exact ids, `{app}.{schema}.*`, `{app}.{schema}.{verb}`, and
      `{app}.{schema}.*:write`, resolved against the facade's catalog (`ToolGrantResolver`)
- [x] A schema wildcard resolves to read verbs only; write/destructive derived tools require an
      explicit grant (default-deny)
- [x] Classification prefers a catalog descriptor's declared `scope`/`destructiveHint`/`readOnlyHint`
      hint over the id's own verb suffix, even when they conflict (`hermiq-prefer-tool-hints`)
- [x] An empty `Agent.tools` preserves "all discovered tools allowed" for READ-classified ids
      (derived reads, or a hand-written/curated id carrying a read-classifying hint), stripping every
      id classified write/destructive — including a hint-less, non-3-segment id, which now FAILS
      CLOSED instead of silently passing (`hermiq-prefer-tool-hints`)
- [x] Above `tools.disclosureThreshold` (default 30) only `hermiq.searchTools` enters the context;
      the full resolved set is held off-context for deferred loading (`ToolSearchService`)
- [x] `hermiq.searchTools` never returns a tool outside the agent's resolved set, and is handled
      Hermiq-internally (no facade round-trip)
- [x] An un-granted write/destructive invocation routes through the `human-approval-gate` state
      machine instead of executing (see that spec's delta)
- [x] `GET /api/agents/{agentId}/tool-catalog` returns the grant-annotated catalog; `PUT
      .../tool-grants` persists `Agent.tools` via `ObjectService` (single write-path), owner-only
- [x] `GET /api/agents/{agentId}/tool-invocations` returns tenant-scoped rows (newest first) with a
      retention note and CSV/JSON export, degrading gracefully and never fabricating
- [ ] Frontend grant editor + oversight view live-verified in a browser (source shipped; bundle
      deferred — see Notes)

## Notes

- **ADR-063 consumer.** The derived catalog, its per-tool annotations, and the MCP invocation audit
  entry shape are all produced by OpenRegister (`or-mcp-schema-dialect`,
  `or-mcp-derived-tool-provider`, `or-mcp-tool-attribute`). Hermiq reads them through the unchanged
  `ToolRegistryFacade` ABI and OR's `AuditTrailMapper`. Adding a new app/schema to the fleet must
  expose new tools to a Hermiq agent with **zero Hermiq code change** — only a schema opt-in
  upstream plus a grant edit on the agent.
- **Upstream gap CLOSED (write/destructive classification).** OpenRegister's
  `McpProviderBridge::getFunctions()` now forwards the `destructiveHint`/`scope`/`readOnlyHint`
  annotation keys onto the descriptor additively, whenever a provider (a schema's
  `x-openregister-mcp` dialect, or a `#[McpTool(...)]`-annotated service tool) sets them
  (verified against HEAD 2026-07-13 — `openregister` `10e605cea`). `ToolGrantResolver` now prefers
  those hints; the verb-suffix heuristic on a 3-segment derived id is the fallback for un-annotated
  tools, unchanged. A hint-less id that is ALSO not a 3-segment derived id (a 2-segment
  hand-written/curated/legacy id) now FAILS CLOSED — classified write/destructive — a deliberate
  reversal of the prior "never classified this way" behaviour, which left curated write tools
  unable to ever trip default-deny or the approval gate (`hermiq-prefer-tool-hints`).
- **Known upstream limitation (agent principal).** OR's `createToolInvocationEntry()` records the
  ambient Nextcloud **session user**, not an agent principal — the `IMcpToolProvider` ABI does not
  thread an acting-agent identity into `invokeTool()`. The oversight surface therefore CORRELATES
  invocations to an agent via that agent's owner plus its schedules' owners. A first-class
  agent-identity column upstream would make this exact rather than correlated.
- **Frontend bundle deferred.** `src/api/toolOversight.js`, `src/components/ToolGrantEditor.vue`
  and `src/components/ToolInvocationTable.vue` ship as source and are wired into `AgentDetail.vue`;
  they are syntax-checked but not webpack-built or browser-verified in this change.
- Related: **ADR-063** (MCP as platform abstraction), **ADR-034** (Hermiq is the agent consumer),
  **ADR-035 D4** (`Agent.tools` whitelist), **ADR-004** (governance via OR AuditTrail),
  `human-approval-gate` (the destructive-invocation gate), `nc-native-tools` (the provider the
  meta-tool registers through), `run-audit-log` (the degraded oversight fallback).


<!--
  RELOCATED WITH THE CODE (ADR-099 §5).

  The requirements below arrived from hermiq's
  `hydra-console-agent-leaves` change delta rather than from its promoted
  spec, because they had not been promoted there yet. They are copied
  VERBATIM — same headings, so every `@spec` anchor that already pointed at
  them still resolves. Rewording one here would break the tags it exists to
  serve.
-->

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

### Requirement: A flow invoked as an agent tool is attributed to an owning UID
When an agent invokes a tool that queues a flow run, the queued run MUST carry the acting owner's
Nextcloud UID, resolved from the run the agent is executing under, so the flow's own steps execute
as an identified person and the run is attributable after the fact. The system MUST NOT queue an
agent-initiated flow run with an absent, empty or system owner. Where the owner cannot be resolved,
the invocation MUST be refused rather than dispatched.

Attribution is required specifically because a flow's terminal step may command an external system:
an unattributed run of such a flow is an unattributed command, and "who told it to do that" must be
answerable from the record.

#### Scenario: An agent-queued flow run names the acting owner

- **GIVEN** an agent run executing on behalf of an identified user
- **WHEN** the agent invokes the flow-running tool
- **THEN** the queued flow run MUST record that user's UID as its owner
- **AND** the flow's steps MUST execute as that owner

#### Scenario: An unresolvable owner refuses the invocation

- **GIVEN** an agent run with no resolvable owning UID
- **WHEN** the agent invokes the flow-running tool
- **THEN** the invocation MUST be refused
- **AND** no flow run MUST be queued

#### Scenario: The record answers who commanded the pipeline

- **GIVEN** a completed flow run whose terminal step wrote to an external system
- **WHEN** the audit trail is read
- **THEN** it MUST name the owning UID, the invoking agent, the tool id and the constrained arguments

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

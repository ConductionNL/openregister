# agent-object-leaf (delta)

Extends the existing agent leaf so it works on the `hydra-console` OpenBuild app's
pages, and adds the triage surface as **data** rather than code. Two corrections to
declarations that are wrong today (the leaf's render surfaces; an invisible
empty-context state), plus additions that are all seed objects: the branch base this
work must sit on, one read-only triage agent, and one triage `agentflow`.

No new HTTP endpoint, no new run path, no new tool. The forge write this surface
ultimately commands is **not** in this capability and **not** Hermiq code — see the
`nc-native-tools` and `agent-tool-governance` deltas in this same change.

## MODIFIED Requirements

### Requirement: Agent integration leaf registration
Hermiq MUST register an OpenRegister integration provider with id `hermiq-agent`
that contributes both a `tab` component and a `widget` component through the
integration registry, so an Agent surface appears on any OpenRegister object in
any OpenBuild app that renders the integration registry. Registration MUST use
the load-order-safe registration hook provided by the OpenRegister
`app-leaf-provider-registration` change and MUST be gated on Hermiq being
installed and enabled for the user, so absence hides the surface rather than
rendering a broken or erroring tab.

Both halves of the registration — the PHP `LeafDescriptor` and the JS
`registerIntegration()` call — MUST declare the SAME `surfaces` set, EXPLICITLY,
drawn entirely from OpenRegister's authoritative `LeafDescriptor::VALID_SURFACES`
vocabulary (`user-dashboard`, `app-dashboard`, `detail-page`, `single-entity`).
The declared set MUST include the dashboard surfaces, because the leaf ships a
`widget` component with a default grid size and consuming apps place that widget on
dashboards. Neither half MAY declare its surfaces by omission: a silent half is what
let the two drift apart without the cross-layer parity gate noticing.

<!-- Previous behavior: the requirement fixed only that a `tab` and a `widget` are
contributed, and said nothing about render surfaces. In practice the PHP descriptor
declared `['detail-page', 'single-entity']` while the JS half declared no `surfaces`
key at all while shipping `widget: CnAgentRunsWidget` with
`defaultSize: { w: 4, h: 4 }` — the JS half advertised a dashboard-placeable widget
the PHP half said was not dashboard-placeable, so dashboard-first consumers could not
place it. Correcting a wrong declaration; no new mechanism. -->

#### Scenario: Object detail page in a consuming app shows the Agent tab
- **GIVEN** Hermiq is enabled and an OpenBuild app renders an OpenRegister object detail page
- **WHEN** the integration registry is read for that object
- **THEN** the `hermiq-agent` provider MUST be present and its `tab` and `widget` MUST render on the object

#### Scenario: Hermiq disabled hides the surface
- **GIVEN** Hermiq is not enabled for the user
- **WHEN** an object detail page is rendered
- **THEN** the Agent tab MUST NOT appear, and no error MUST be shown for its absence

#### Scenario: Both registration halves declare the same explicit surface set
- **GIVEN** the PHP `LeafDescriptor` and the JS `registerIntegration()` call
- **WHEN** their declared `surfaces` are compared
- **THEN** both MUST name the same set explicitly
- **AND** every named surface MUST be a member of `LeafDescriptor::VALID_SURFACES`
- **AND** neither half MUST rely on omission to express its surfaces

#### Scenario: The agent widget is placeable on a consuming app's dashboard
- **GIVEN** an OpenBuild dashboard page that renders the integration registry
- **WHEN** the registry is queried for widgets available on that surface
- **THEN** the `hermiq-agent` widget MUST be offered and MUST render at its declared default size

### Requirement: Declarative bounded agent-context allowlist
Hermiq's agent leaf and run-on-object endpoint MUST build the forwarded object
context from ONLY the properties named by a schema's `x-openregister-agent-context`
allowlist, and MUST fail closed: when the allowlist is absent or empty the
context MUST be empty, never the whole object, and a property named in the
allowlist but not present on the instance MUST be omitted rather than error. A
schema declares this allowlist as a list of property names an agent surface may
read from an object of that schema.

When resolution yields ZERO properties for the current object, the leaf MUST make
that visible to the user, in text, before or alongside the reply — it MUST NOT send
an empty context and render a confident answer as though it were grounded in the
object. Fail-closed context is correct security; an ungrounded answer presented as
grounded is a correctness defect, and the two MUST be distinguishable in the surface.

<!-- Previous behavior: the requirement fixed the fail-closed resolution rule but was
silent on what the USER sees when it resolves to nothing. The observable result was an
agent answering about an object it had received no properties for, indistinguishable
from an agent answering about an object it had read. Adding the disclosure obligation;
the resolution rule itself is unchanged. -->

#### Scenario: Only allowlisted fields reach the agent
- **GIVEN** a schema allowlist naming title, status, and description
- **WHEN** a context is built for an object that also holds an unlisted confidential field
- **THEN** the context MUST contain only title, status, and description and MUST NOT contain the confidential field

#### Scenario: No allowlist yields an empty context
- **GIVEN** a schema with no `x-openregister-agent-context` declaration
- **WHEN** a context is built for an object of that schema
- **THEN** the context MUST be empty and no object property MUST be forwarded

#### Scenario: A missing allowlisted property does not error
- **GIVEN** a schema allowlisting a property that a particular instance does not carry
- **WHEN** context is built for that instance
- **THEN** the property MUST be omitted and context building MUST succeed

#### Scenario: The user is told the object contributed no context
- **GIVEN** an object whose schema allowlist resolves to zero properties
- **WHEN** the user opens the agent chat on that object
- **THEN** the surface MUST state in text that no object context is available
- **AND** it MUST NOT present the reply as grounded in that object

## ADDED Requirements

### Requirement: The implementation branch carries both the graph builder and the current leaf
The implementation branch MUST be based on `feat/agent-graph-builder` with
`origin/development` merged in, so it carries BOTH the agent graph builder AND the
current leaf — specifically the `mount(el, props)` cross-Vue-major escape hatch
(hermiq#44 / #47, v0.1.94) and the OpenRegister flow-engine consumer (hermiq#35).
The merge MUST be accepted on OBSERVED leaf rendering, NOT on a textually clean
merge: a leaf that registers, contributes a tab, and renders an empty body is the
documented failure mode this merge exists to remove, and it is indistinguishable from
success in any static check.

#### Scenario: Leaf body renders under a Vue-major-mismatched host
- **GIVEN** the merge of `origin/development` into `feat/agent-graph-builder` is complete
- **WHEN** the `hermiq-agent` leaf is opened on a console detail page whose host bundle is a different Vue major than the leaf's
- **THEN** the leaf's tab body MUST render its own content through the `mount(el, props)` hand-off
- **AND** an empty or unrendered body MUST be treated as merge failure, not as an empty result

#### Scenario: A clean merge alone is not acceptance
- **GIVEN** the merge completes with no textual conflicts
- **WHEN** no leaf render has been observed on a live console page
- **THEN** the branch base MUST NOT be considered done

### Requirement: A seeded read-only triage agent, as data
The system MUST seed exactly one Agent object named "Hydra Triage" through an
idempotent repair step following the established `lib/Repair/Seed*.php` pattern:
written via OpenRegister's `ObjectService` in system context, matched by its seeded
name so a re-run neither duplicates it nor overwrites an operator's edits. The agent
is CONFIGURATION, not code: its `tools`, `prompt`, `requiresApproval` and
`delegationAllowlist` fields are its entire behaviour, and retuning it MUST NOT
require a release.

Its `tools` grant list MUST consist of read grants over the pipeline data surface the
chain head provides — `{app}.{schema}.*` wildcards where the register exposes derived
schema tools, otherwise the read-only tool ids that surface that data — plus AT MOST
ONE command grant, which MUST be the argument-scoped, approval-gated flow-invocation
grant specified in the `agent-tool-governance` delta. It MUST NOT carry any `:write`
wildcard, MUST NOT be granted write access to any hydra schema, and MUST NOT be
granted a bespoke Hermiq forge tool, because no such tool exists (see the
`nc-native-tools` delta). Hydra owns its own state; the console commands it only
through the label channel.

#### Scenario: Seeding twice creates one agent
- **GIVEN** the repair step has already run and created the Hydra Triage agent
- **WHEN** the repair step runs again on upgrade
- **THEN** exactly one agent named "Hydra Triage" MUST exist
- **AND** its stored configuration MUST NOT be overwritten

#### Scenario: Read grants resolve to read tools only
- **GIVEN** the seeded agent's read grants and a catalog containing every verb for the named schemas
- **WHEN** its grants are resolved against the live catalog
- **THEN** only read tools MUST be present in the resolved whitelist
- **AND** no create, update or delete tool for any hydra schema MUST be present

#### Scenario: The agent cannot write hydra objects
- **GIVEN** the seeded agent's full resolved tool list
- **WHEN** it is inspected for hydra-schema write capability
- **THEN** no tool that mutates a hydra object MUST be present

#### Scenario: Grants that resolve to nothing are reported
- **GIVEN** the seeded agent names grants, does not use the explicit no-tools sentinel, and resolves to zero tools because the register it names is absent or a slug is misspelled
- **WHEN** its grants are resolved against the live catalog
- **THEN** the resolution MUST be reported as a misconfiguration
- **AND** the agent MUST NOT be silently run as a chat-only agent

#### Scenario: A deliberately tool-less agent is not reported
- **GIVEN** an agent whose grants are the explicit no-tools sentinel
- **WHEN** its grants resolve to zero tools
- **THEN** this MUST NOT be reported as a misconfiguration

### Requirement: The triage loop is a seeded agentflow, not bespoke code
The system MUST express the automated triage loop as a seeded `agentflow` object in
the `hermiq` register — data resolved by the existing `HermiqFlowResolver` and walked
by OpenRegister's flow engine — and MUST NOT implement it as a Hermiq service, a
controller path, or a background job of its own. The flow declares its trigger
(`trigger`, `triggerRegister`, `triggerSchema`), its nodes and its edges; the only
Hermiq-contributed node it uses is the existing `hermiq.agent-step`. The terminal
command step MUST be the OpenConnector-backed node or endpoint that owns the forge
write; the flow MUST NOT contain a Hermiq-authored HTTP step.

Because the agent-step node swallows a failed turn to an EMPTY STRING and cannot
distinguish failure from silence, the flow MUST branch explicitly on an empty triage
result and MUST NOT reach the command step on one. Where the command node is not yet
available on the instance, the flow MUST terminate having recorded the proposed
label, and MUST NOT degrade to writing anything.

The interactive leaf surface remains available for the same work; the flow is the
unattended path, not a replacement for the operator one.

#### Scenario: A new finding triggers the seeded triage flow
- **GIVEN** the seeded, enabled triage `agentflow` declaring its trigger on the pipeline finding schema
- **WHEN** a finding object is created
- **THEN** the resolver MUST list that flow for the fired trigger
- **AND** the engine MUST queue a run that walks the agent step

#### Scenario: An empty agent-step result never reaches the command step
- **GIVEN** a triage run whose underlying turn fails and whose agent step therefore yields an empty string
- **WHEN** the flow evaluates its next edge
- **THEN** the flow MUST treat the empty result as "no result"
- **AND** the flow MUST NOT proceed to the command step

#### Scenario: The flow contains no Hermiq-authored HTTP step
- **GIVEN** the seeded flow definition
- **WHEN** its node types are enumerated
- **THEN** every node MUST be a built-in engine node, the `hermiq.agent-step` node, or the OpenConnector-backed command node
- **AND** no node MUST open an HTTP client from Hermiq code

#### Scenario: The command node being unavailable does not fail open
- **GIVEN** an instance where the OpenConnector-backed command node is not installed
- **WHEN** the triage flow runs
- **THEN** the run MUST terminate with the proposed label recorded and no forge write attempted

### Requirement: A run or flow dispatch is owned by the person who made and activated it
Every run this change dispatches MUST resolve to an owning Nextcloud UID, and that
owner MUST be recorded on the run. For a user-initiated run from the leaf or a
console action the owner is the acting user. For a trigger-fired `agentflow` there is
no acting user, so the owner MUST be the NC UID of the person who authored and
activated the flow, carried on the flow object itself. A run whose owner cannot be
resolved MUST NOT dispatch — it MUST fail loudly rather than execute unattributed,
because a triage run that ends in a pipeline command is a command somebody issued.

The console MUST NOT be able to downgrade an approval requirement through the request
body, and no new run path, approval path or audit path is introduced: the kill-switch,
the budget hard cap, the human-approval gate, the redacted audit trail and the
delegation caps all apply unchanged.

#### Scenario: A trigger-fired flow run is attributed to the flow's owner
- **GIVEN** an enabled `agentflow` carrying the NC UID of the person who authored and activated it
- **WHEN** its trigger fires with no acting user in context
- **THEN** the queued run MUST be attributed to that UID
- **AND** the agent step MUST execute as that owner

#### Scenario: An unresolvable owner blocks dispatch
- **GIVEN** a flow with no owner on the object and no acting user in context
- **WHEN** its trigger fires
- **THEN** the run MUST NOT dispatch
- **AND** the condition MUST be reported rather than defaulted to an empty or system owner

#### Scenario: A gated run records its gate outcome
- **GIVEN** the organisation is under the kill-switch or over its budget hard cap
- **WHEN** a console action or the triage flow dispatches a run
- **THEN** the run MUST skip execution and record the matching skipped status
- **AND** the leaf's run widget MUST display it

#### Scenario: The console cannot bypass approval
- **GIVEN** an agent whose policy requires approval
- **WHEN** a console action dispatches a run carrying any request-body value
- **THEN** the run MUST still enter the approval gate

### Requirement: An asynchronous run has a defined landing place
A run dispatched from a console action MUST be asynchronous — the endpoint dispatches
the governed run and returns 202 with a correlation id, never a synchronous result —
and MUST have a defined landing place for its output: a `resultField` appropriate to
the target schema. Where a schema declares no such field, the run's outcome MUST
still be readable from the OpenRegister audit trail, which the leaf's run widget
already reads. Hermiq MUST NOT require a schema change it does not own in order for a
result to be readable.

#### Scenario: A triage run lands on the target schema's result field
- **GIVEN** a console action dispatching a run on a pipeline object with a `resultField` named
- **WHEN** the governed run completes successfully
- **THEN** the result MUST be written to that field
- **AND** a corresponding audit entry MUST be readable by the run widget

#### Scenario: A schema with no result field still yields a readable outcome
- **GIVEN** a target schema declaring no result field
- **WHEN** a run completes against an object of that schema
- **THEN** the outcome MUST be readable from the audit trail
- **AND** no schema change MUST be required of the owning repository

#### Scenario: The endpoint never runs inline
- **GIVEN** any console-dispatched run
- **WHEN** the request is handled
- **THEN** the response MUST be 202 with a correlation id
- **AND** the agent MUST NOT execute within that request

## Non-Functional Requirements

- **Performance:** Run dispatch MUST return within normal Nextcloud request latency
  because it only enqueues; it MUST NOT block on an LLM call. Context building MUST
  read only the current object and its schema, adding no per-property round trip.
- **Accessibility:** The leaf's chat and run widget MUST meet WCAG 2.1 AA on consuming
  pages: every input carries a programmatic label (for `NcSelect`, via `inputLabel` /
  `ariaLabelCombobox`, never a manual `<label>`), the "no context available" state is
  conveyed in text and not by colour alone, and asynchronous state changes (queued →
  complete) are announced rather than only visually swapped.
- **Internationalization:** Dutch and English MUST both be supported (ADR-005). Every
  operator-visible string added here — the surface label, the empty-context notice, run
  status text — MUST route through `IL10N` / `t()` and MUST appear in both
  `l10n/en.json` and `l10n/nl.json`.

## Acceptance Criteria

- The branch base merge is complete and the leaf body has been observed rendering on a
  live console detail page.
- The PHP and JS halves declare an identical, explicit surface set drawn entirely from
  `LeafDescriptor::VALID_SURFACES`, including the dashboard surfaces, and the
  cross-layer parity gate passes.
- The `hermiq-agent` widget is offered and renders on a console dashboard page.
- Pipeline objects of at least three schemas each yield a non-empty bounded context
  containing only allowlisted properties, verified live.
- A schema with no allowlist yields an empty context and the surface says so, in text,
  in both Dutch and English.
- The Hydra Triage agent exists exactly once after two repair-step runs, with an
  operator edit surviving the second run.
- The agent's resolved tool list contains read tools only, plus at most the one
  argument-scoped command grant, and nothing that writes a hydra object.
- A grant naming a nonexistent schema is reported as resolving to nothing.
- The seeded triage `agentflow` is listed by the resolver for its trigger, walks its
  agent step, and stops on an empty result without reaching the command step.
- A trigger-fired run is attributed to the flow's owner UID; an ownerless flow does not
  dispatch.
- A console-dispatched run returns 202 with a correlation id and its result appears on
  the named result field plus the audit trail.
- A kill-switched or over-budget run records its skipped status in the run widget.
- Every scenario above is referenced by a Playwright e2e test or carries a
  reason-bearing `@e2e exclude` (gate-19).

## Notes

- **Cross-repo dependency.** This capability consumes the `hydra` register, its schema
  slugs and its `x-openregister-agent-context` allowlists from
  `hydra-register-data-plane`, and the console's pages, dashboards and action buttons
  from `hydra-console-openbuild-app` — including that change's
  `Detail pages reserve a slot for the hermiq agent leaf` requirement. Both live in the
  `apps-extra/hydra` repository, so OpenSpec cannot gate the ordering; it is a human
  contract.
- **Nothing here is statically verifiable in Hermiq's CI.** `OCA\OpenRegister\*` is
  absent from this repo's analysis environment, so `LeafDescriptor`, `ObjectService`,
  the flow engine, the audit trail and the tool facade are all unanalysable. Every
  cross-app assertion above is live-verified only; a green analyzer proves nothing
  about them.
- **`cli` execution mode is out of scope here.** It is personal-scope-only
  (`assertPersonalScopeCredential`) and belongs to `hydra-exec-personal-cli-runner`.
- Related ADRs: ADR-001 (declarative seed data), ADR-019 (integration registry
  render/link), ADR-022 (consume the fleet's abstractions), ADR-031 (declarative over
  imperative), ADR-041 (cross-app commands via typed events), ADR-065 (one flow
  engine), ADR-066 (cross-app leaf registration).

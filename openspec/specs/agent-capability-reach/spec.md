# agent-capability-reach Specification

## Purpose
TBD - created by archiving change agent-capability-reach. Update Purpose after archive.
## Requirements
### Requirement: Every tool descriptor declares a reach on a closed, ordered vocabulary

The system MUST classify every tool in an agent's catalogue on a `reach` axis whose values are exactly `self`, `user`, `instance` and `external`, ordered `self` < `user` < `instance` < `external`. `reach` MUST be orthogonal to `scope`: it declares the widest set of principals a successful invocation can AFFECT or DISCLOSE TO, not the data verb the invocation performs and not the provenance of data it merely reads. `self` means only the invoking agent's own memory or state. `user` means only the acting user's own data and permission set, with no effect any other principal can observe. `instance` means other users of this Nextcloud can observe the effect. `external` means the effect, or the data, leaves this Nextcloud. The system MUST NOT remove, rename or reinterpret `scope`, `readOnlyHint` or `destructiveHint`; both axes are retained and answer different questions.

#### Scenario: A read tool that leaves the instance is not classified as low risk

- **GIVEN** a tool whose `scope` is `read` and whose invocation issues an outbound HTTP request to a
  caller-supplied URL
- **WHEN** the system classifies it
- **THEN** its `reach` MUST be `external`
- **AND** its `scope` MUST remain `read`
@e2e exclude Descriptor-shape assertion with no UI surface in this change; asserted by unit test on the descriptor table and re-asserted through the tool-catalogue API scenario below.

#### Scenario: A destructive tool confined to the agent's own memory is classified as low reach

- **GIVEN** a tool whose `scope` is `delete` and whose invocation soft-deletes an entry only the
  invoking agent can recall
- **WHEN** the system classifies it
- **THEN** its `reach` MUST be `self`
@e2e exclude Descriptor-shape assertion; asserted by unit test on the descriptor table.

#### Scenario: Reading data belonging to other users is not by itself instance reach

- **GIVEN** a tool that reads records the acting user is already permitted to see, some of which
  were created by other users
- **WHEN** the system classifies it
- **THEN** its `reach` MUST be `user`, because no other principal observes an effect
@e2e exclude Classification-rule assertion over the descriptor table; asserted by unit test.

#### Scenario: The reach of every catalogue entry is readable through the tool-catalogue API

- **GIVEN** an agent with a resolved tool catalogue
- **WHEN** an authorized operator reads that agent's tool catalogue
- **THEN** every returned entry MUST carry its `reach` alongside its `scope`
- **AND** the system MUST NOT return an entry with no `reach`
@e2e Playwright: seed an agent, GET its tool catalogue through the API and assert every entry carries a reach drawn from the closed vocabulary.

### Requirement: An undeclared or unrecognised reach resolves to external

The system MUST treat a tool that declares no `reach`, or that declares a value outside the closed vocabulary, as `external`. The system MUST NOT default such a tool to `self`, to `user`, or to any value derived from its `scope` alone. A `reach` the descriptor DOES declare MUST win over any value the system would otherwise infer. Where no `reach` is declared, the system MAY infer one from a 3-segment `{app}.{schema}.{verb}` id whose verb is in the closed ADR-063 verb vocabulary — `search` and `get` inferring `user`, `create`, `update` and `delete` inferring `instance` — and MUST fall back to `external` for every other id shape.

#### Scenario: A hint-less curated tool fails closed to external

- **GIVEN** a curated 2-segment tool id whose descriptor declares no `reach`
- **WHEN** the system resolves its reach
- **THEN** the resolved reach MUST be `external`
- **AND** the tool MUST be treated as at least as restricted as an explicitly `external` tool
@e2e exclude Fail-closed default on an absent annotation, reachable only by constructing a descriptor without one; asserted by unit test on the reach resolver.

#### Scenario: An unrecognised reach value is not trusted

- **GIVEN** a descriptor declaring a `reach` value outside the closed vocabulary
- **WHEN** the system resolves its reach
- **THEN** the resolved reach MUST be `external`
- **AND** the system MUST NOT accept the unrecognised value as a vocabulary member
@e2e exclude Malformed-descriptor path with no UI surface; asserted by unit test on the reach resolver.

#### Scenario: A derived tool's verb infers its reach when none is declared

- **GIVEN** a 3-segment `{app}.{schema}.{verb}` id whose descriptor declares no `reach`
- **WHEN** the verb is `search` or `get`
- **THEN** the inferred reach MUST be `user`
- **AND** when the verb is `create`, `update` or `delete` the inferred reach MUST be `instance`
- **AND** when the verb is outside that closed vocabulary the resolved reach MUST be `external`
@e2e exclude Inference rule over derived catalogue ids; asserted by unit test on the reach resolver.

### Requirement: Default-deny and the approval gate key off reach in union with the existing rule

The system MUST treat a tool as requiring an explicit grant, and MUST route an un-granted invocation of it through the human-approval gate, when it is classified write/destructive under the existing rule OR when its resolved `reach` is `instance` or higher. The two rules MUST compose as a union: a low `reach` MUST NOT make any tool more permissive than it is today, so a tool that is write/destructive under the existing `scope`/`destructiveHint`/`readOnlyHint` classification MUST remain gated regardless of its reach. The system MUST pass the catalogue descriptor to the classification used by the approval gate, so that a declared hint and a declared reach are both visible on the gating path and not only on the default-deny path. A refusal the gate produces MUST name the reach that triggered it, so a run trace distinguishes a reach-triggered gate from a verb-triggered one.

#### Scenario: An egress read tool becomes gated

- **GIVEN** an agent whose `Agent.tools` does not explicitly name a `read`-scoped tool whose reach is
  `external`
- **WHEN** the agent's run attempts to invoke that tool
- **THEN** the system MUST NOT dispatch the invocation
- **AND** the system MUST route it through the human-approval gate
- **AND** the refusal returned to the model MUST name the reach that triggered the gate
@e2e exclude Requires driving a model turn to attempt an un-granted tool call, which no Hermiq UI produces; asserted by unit test on the invoker and by the existing approval-gate integration coverage.

#### Scenario: A low reach does not un-gate a destructive tool

- **GIVEN** a tool classified write/destructive by its `scope` whose resolved reach is `self`
- **WHEN** the system decides whether it needs an explicit grant
- **THEN** the tool MUST still require an explicit grant
- **AND** an un-granted invocation of it MUST still route through the approval gate
@e2e exclude Absence-of-relaxation assertion on the classification path; asserted by unit test comparing the pre-change and post-change verdicts for the same descriptor.

#### Scenario: A declared destructive hint is honoured on the gating path

- **GIVEN** a 3-segment derived id whose verb suffix reads `get` but whose descriptor declares
  `destructiveHint: true`
- **WHEN** the approval gate classifies the invocation
- **THEN** the descriptor's hint MUST win and the invocation MUST be gated
- **AND** the gate MUST NOT reach a different verdict from the default-deny classification for the
  same id and descriptor
@e2e exclude Closes a descriptor-not-threaded bypass on an internal call path; asserted by unit test asserting the two classification call sites agree.

#### Scenario: An explicitly granted external tool is not gated

- **GIVEN** an agent whose `Agent.tools` names an `external`-reach tool by its exact id
- **WHEN** the agent's run invokes that tool
- **THEN** the system MUST NOT require a new approval solely because the tool's reach is `external`
- **AND** OpenRegister RBAC MUST still authorize the invocation at invoke time
@e2e exclude Requires driving a model turn; asserted by unit test on the invoker's gate predicate.

### Requirement: A delegation cannot launder reach

The system MUST evaluate a sub-agent delegation's effective reach as the MAXIMUM of the delegation tool's own reach and the highest reach among the target agent's resolved grants. The system MUST NOT let an agent whose own grants are all below `external` obtain `external` effect by delegating to an agent that holds `external` grants without that effective reach being accounted for. This requirement MUST compose with, and MUST NOT weaken, the existing delegation protections: a delegation targeting an agent with `requiresApproval` is refused outright, and a delegation attempted while the organisation kill-switch is engaged is refused before the target is invoked.

#### Scenario: Delegating to an agent with external grants is evaluated at external reach

- **GIVEN** a calling agent holding only `user`-reach grants
- **AND** a target agent holding a grant for an `external`-reach tool
- **WHEN** the calling agent delegates to that target
- **THEN** the delegation's effective reach MUST be `external`
- **AND** the delegation MUST NOT be treated as an `instance`-reach action
@e2e exclude Requires driving a delegation turn between two seeded agents from a model context; asserted by unit test on the delegation reach computation.

#### Scenario: Existing delegation refusals are unchanged

- **GIVEN** a target agent with `requiresApproval` set, or an engaged organisation kill-switch
- **WHEN** a delegation targets that agent
- **THEN** the system MUST refuse the delegation as it does today
- **AND** the reach computation MUST NOT cause the delegation to be permitted where it is refused
  today
@e2e exclude Regression guard on an engine-layer refusal reached without passing through the UI; asserted by unit test on the delegation service.

### Requirement: A grant may carry a noapproval waiver fragment parsed before any other grant parsing

The system MUST accept an optional `#noapproval` fragment at the end of a grant entry, giving the grammar `{toolId}[?{constraints}][#noapproval]`, for example `hermiq.mail.send?to=in:user@example.com#noapproval`. The system MUST split the fragment off BEFORE splitting the grant on the argument-constraint opener `?` and before any base-id or constraint parsing, so that a fragment can never be absorbed into a constraint value or into the base tool id. A grant entry carrying no fragment MUST parse byte-for-byte as it does today, so every stored `Agent.tools` value keeps its current meaning and no data migration is required. `Agent.tools` MUST remain a `string[]`.

#### Scenario: A waiver on an argument-scoped grant does not corrupt the constraint

- **GIVEN** a grant entry of the form `{toolId}?{argument}=in:{a},{b}#noapproval`
- **WHEN** the resolver parses it
- **THEN** the parsed closed set MUST contain exactly `{a}` and `{b}`
- **AND** no parsed constraint value MUST contain the text `noapproval`
@e2e exclude Parser-internal assertion on the split order; asserted by unit test on the grant resolver with the exact failure string.

#### Scenario: A waiver on a bare exact-id grant still resolves to the tool

- **GIVEN** a grant entry of the form `{toolId}#noapproval` with no argument constraints
- **WHEN** the resolver expands it against the catalogue
- **THEN** the resolved set MUST contain `{toolId}`
- **AND** the resolved set MUST NOT contain any id containing the text `noapproval`
@e2e exclude Parser-internal assertion; asserted by unit test on the grant resolver.

#### Scenario: An existing grant list parses unchanged

- **GIVEN** a stored `Agent.tools` list in which no entry contains a fragment
- **WHEN** the resolver parses it after this change
- **THEN** the resolved set and the parsed argument constraints MUST be identical to those produced
  before this change
@e2e exclude Backward-compatibility assertion over the pre-change parser output; asserted by unit test using the existing grant-form fixtures.

#### Scenario: A waiver survives a persist and read-back

- **GIVEN** an agent owned by the acting user
- **WHEN** the owner persists a grant list containing an entry ending in `#noapproval`
- **THEN** reading the agent's grants back MUST return that entry with the fragment intact
@e2e Playwright: seed an agent, PUT a grant list containing a `#noapproval` entry through the tool-grants API, then GET it back and assert the fragment is preserved verbatim.

### Requirement: The waiver suppresses the approval gate and nothing else

The system MUST treat `#noapproval` as suppressing the human-approval gate for that one grant entry, and MUST NOT let it widen a grant, add a tool to the resolved set, relax an argument constraint, or affect OpenRegister RBAC. The system MUST consult the waiver only AFTER grant expansion has placed the tool in the agent's resolved set and AFTER argument-constraint enforcement has accepted the invocation's arguments. A waiver naming a tool that is not otherwise granted MUST have no effect whatsoever.

#### Scenario: A waiver does not make an ungranted tool runnable

- **GIVEN** an agent whose `Agent.tools` contains only `{toolA}#noapproval`
- **WHEN** the agent's run attempts to invoke a different tool `{toolB}`
- **THEN** the system MUST refuse the invocation exactly as it would with no waiver present
- **AND** the waiver MUST NOT place `{toolB}` in the resolved set
@e2e exclude Requires driving a model turn to attempt an out-of-set tool call; asserted by unit test on the resolver and the invoker.

#### Scenario: A waiver does not relax an argument constraint

- **GIVEN** a grant `{toolId}?{argument}=in:{a},{b}#noapproval`
- **WHEN** the agent invokes `{toolId}` with `{argument}` set to a value outside that set
- **THEN** the system MUST refuse the invocation before dispatch with the existing
  `grant_constraint_violated` outcome
- **AND** the waiver MUST NOT cause the constraint check to be skipped
@e2e exclude Requires driving a constrained tool call from a model turn; asserted by unit test on the invoker's ordering of constraint check and gate.

#### Scenario: A waiver does not bypass OpenRegister RBAC

- **GIVEN** a granted, waived tool whose invocation OpenRegister RBAC denies for the acting user
- **WHEN** the agent invokes it
- **THEN** OpenRegister RBAC MUST still deny the invocation at invoke time
- **AND** the waiver MUST NOT be used to obtain access the RBAC layer would refuse
@e2e exclude Requires an RBAC-denied invocation driven from a model turn; asserted by unit test and covered by the existing untrusted-hint requirement in agent-tool-governance.

#### Scenario: A waived, granted, conforming invocation runs without an approval

- **GIVEN** an agent holding a granted, argument-conforming, waived grant for a gated tool
- **WHEN** the agent invokes that tool within the grant's constraints
- **THEN** the system MUST dispatch the invocation without creating a pending approval
@e2e exclude Requires driving a model turn against a live tool; asserted by unit test on the invoker's gate predicate.

### Requirement: Only the agent owner may persist a grant list carrying a waiver

The system MUST verify server-side that the acting user is the agent's owner before persisting any grant list for that agent, on EVERY path that can persist one — not only on Hermiq's own tool-grants endpoint. A client-side check, a disabled form control, or explanatory text in the UI MUST NOT be treated as satisfying this requirement. Where an agent's grants are persisted through the generic OpenRegister object write path, that path MUST be owner-scoped by the Agent schema's own authorization declaration rather than by an app-side pre-write guard, because data authorization is OpenRegister's layer (ADR-023 Rule 1).

#### Scenario: A non-owner is refused on Hermiq's tool-grants endpoint

- **GIVEN** an agent owned by user A
- **WHEN** authenticated user B attempts to persist a grant list for that agent through Hermiq's
  tool-grants endpoint
- **THEN** the system MUST refuse the write with a forbidden response
- **AND** the stored grant list MUST be unchanged
@e2e Playwright: seed an agent as the owner, then attempt the same grant write as a second authenticated user and assert a forbidden response and an unchanged grant list.

#### Scenario: A non-owner is refused on the generic object write path

- **GIVEN** an agent owned by user A
- **WHEN** authenticated user B attempts to write that agent's `tools` through the generic
  OpenRegister object write path
- **THEN** the system MUST refuse the write
- **AND** the stored grant list MUST be unchanged
@e2e Playwright: attempt the same `tools` write as a second authenticated user directly against the OpenRegister object path and assert it is refused and the grant list is unchanged.

#### Scenario: The owner is not obstructed

- **GIVEN** an agent owned by the acting user
- **WHEN** that owner persists a grant list containing a waiver
- **THEN** the system MUST accept the write
@e2e Playwright: persist a waiver-bearing grant list as the agent's owner and assert it is accepted.

### Requirement: Waiving approval is recorded as a distinct audited event

The system MUST record the addition or removal of a `#noapproval` waiver as its own audited event, distinct from the ordinary "grants changed" write, carrying the acting user, the agent, the exact grant entry affected, and whether the waiver was added or removed. The system MUST NOT rely on a reader diffing two grant arrays to discover that human oversight was switched off. The event MUST be written through the same OpenRegister audit path all other Hermiq governance events use (ADR-004), and MUST be greppable by a single stable action token.

#### Scenario: Adding a waiver writes a distinct audit event

- **GIVEN** an agent whose grant list contains no waiver
- **WHEN** its owner persists a grant list in which one entry now ends in `#noapproval`
- **THEN** the system MUST write an audit event whose action token identifies a waiver being added
- **AND** that event MUST carry the acting user, the agent and the exact grant entry
@e2e Playwright: persist a waiver through the API and assert the agent's audit/oversight surface reports a waiver-added event naming the grant entry.

#### Scenario: Removing a waiver writes a distinct audit event

- **GIVEN** an agent whose grant list contains a waived entry
- **WHEN** its owner persists a grant list in which that entry no longer ends in `#noapproval`
- **THEN** the system MUST write an audit event whose action token identifies a waiver being removed
@e2e exclude Symmetric to the add case, which is covered above; asserted by unit test on the audit writer.

#### Scenario: An ordinary grant change is not reported as a waiver event

- **GIVEN** an agent whose grant list contains no waiver before or after a change
- **WHEN** its owner adds an ordinary grant entry
- **THEN** the system MUST NOT write a waiver audit event
@e2e exclude Absence-of-event assertion; asserted by unit test on the audit writer.

### Requirement: The grant model is documented for operators

The system MUST ship an operator-facing documentation page covering the complete grant model: every grant form (exact id, `{app}.{schema}.*`, `{app}.{schema}.*:write`, argument constraints in both the pinned and closed-set forms, and the `#noapproval` fragment), the `reach` vocabulary with a worked example for each of `self`, `user`, `instance` and `external`, the default-deny rule, the conditions under which the human-approval gate fires, and plainly what waiving approval gives up. The page MUST state that `hermiq.webSearch` and `hermiq.webFetch` become approval-gated unless explicitly granted, because that is the one capability an existing agent can lose. The page MUST NOT use realistic-looking secrets, real email addresses or real identifiers in its examples.

#### Scenario: An operator can read the grant syntax without reading the source

- **GIVEN** the shipped documentation
- **WHEN** an operator looks up how to grant one schema's read verbs
- **THEN** the documentation MUST show the grant form and a worked example
- **AND** the documentation MUST state that a schema wildcard never grants write verbs
@e2e exclude Documentation content, verified by review against the requirement rather than by a browser assertion.

#### Scenario: The cost of waiving approval is stated plainly

- **GIVEN** the shipped documentation
- **WHEN** an operator reads the section on `#noapproval`
- **THEN** the documentation MUST state that the human-approval gate is suppressed for that grant
- **AND** the documentation MUST state that the waiver narrows nothing else and widens nothing
- **AND** the documentation MUST state that adding or removing a waiver is audited
@e2e exclude Documentation content, verified by review against the requirement.


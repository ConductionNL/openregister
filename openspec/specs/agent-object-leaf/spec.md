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

<!--
  RELOCATED SUBSET — the canonical home for this capability is hermiq.

  ADR-099 §5 moved the tool-grant grammar (ToolGrantSet, ToolGrantCodec,
  ToolGrantResolver, ToolReachResolver, ToolGrantResolutionException) into
  OCA\OpenRegister\Service\Capability. The requirements below are the ones that
  code implements, copied VERBATIM from hermiq so their headings — and therefore
  every `@spec` anchor pointing at them — resolve unchanged.

  🔴 THIS FILE IS NOT THE WHOLE SPEC. 6 further requirement(s) live in
  hermiq's `openspec/specs/agent-object-leaf/spec.md` and are NOT duplicated here: they
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

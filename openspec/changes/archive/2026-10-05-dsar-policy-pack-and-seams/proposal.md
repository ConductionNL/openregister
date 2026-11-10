---
kind: config
depends_on: [dsar-case-subsystem, dsar-case-engine]
---

## Why

Phase-1 (`dsar-case-subsystem` + `dsar-case-engine`) gave OpenRegister a generic, stateful
data-subject-request case workflow, but it left every jurisdiction-specific value **hard-coded as a
provisional default**: escalation-tier boundaries, the denial-ground enum keys, retention-window
durations, intake channels, and the DPO/FG role mapping all live inside the register JSON and the
engine change with placeholder values (see `dsar-case-subsystem` Open Questions). ADR-047 rules
that **jurisdiction is data, not code**: a jurisdiction or tenant must be able to supply its AVG/DSAR
policy as a **policy pack** (a config object) and bind two **integration seams** — identity
verification and regulator escalation — without touching OpenRegister core. This change defines the
policy-pack config contract that the Phase-1 declarative lifecycle/deadline/notification config
**reads from**, so that the provisional Phase-1 defaults become policy-pack-driven data.

This is the **head of the Phase-2 chain** and is deliberately `kind: config` only. Per ADR-032 a
`mixed` change (declarative config + imperative registries + Vue UI) is an anti-pattern, so Phase-2
is split into three chained changes: this policy-pack **contract/schema** (config), then
`dsar-integration-seams` (the two ADR-019 registries + interfaces + default providers, `kind: code`,
`depends_on` this), then `dsar-case-ui` (the `AvgIndex.vue` full case-management extension,
`kind: code`, `depends_on` both). This change authors the head-of-chain (config) artifacts only; the
seam registries and the UI are specced in their own successor changes. The split decision is flagged
for the human in DEFERRED_QUESTIONS.

## What Changes

- **Add a `dsarPolicyPack` schema + register** (`lib/Settings/dsar_policy_pack_register.json`): a
  per-jurisdiction/tenant config object supplying — deadline durations + escalation thresholds
  (reminder/escalation/breach tier boundaries the Phase-1 `escalationTier` calculation and
  reminder/escalation/breach notifications consume), the denial-grounds enum **with jurisdiction
  wording** (generic ground keys from Phase-1 mapped to human-readable labels + statutory citation),
  retention windows (named window → duration, feeding Phase-1 `retentionWindow`/`retainUntil`),
  intake channels, the DPO/FG role mapping (ties to ADR-023 action-level authorization), and
  letter/notification template references. Every property carries a human-friendly `title` +
  `description` (ADR-011).
- **Bind the Phase-1 declarative config to the policy pack** (declarative reference, not code): the
  Phase-1 `x-openregister-calculations` (`escalationTier`), `-notifications` (reminder/escalation/
  breach thresholds), and the `denialGround` enum + `retentionWindow` selectors resolve their
  now-provisional values from the active `dsarPolicyPack` object for the case's jurisdiction/tenant,
  so the boundaries become **data**. No hard-coded threshold remains in the register JSON.
- **Define the two integration-seam CONTRACTS as config** (the contract text + a `null`/default
  provider identity per seam recorded in the policy pack) — `identityVerifyProvider` and
  `regulatorEscalateProvider` fields on the policy pack naming which registered provider a
  jurisdiction binds, defaulting to the OR-shipped safe-default (fail-closed) provider when unbound.
  The interfaces + registries + resolution themselves are **imperative** and specced in the
  successor `dsar-integration-seams` change.
- **Seed data**: a realistic default (jurisdiction-neutral) policy pack plus an illustrative NL-shaped
  example pack, using safe placeholders — so a fresh install has a working, fail-closed default and a
  reference for what a leaf-app jurisdiction pack looks like.

**Explicitly out of scope — successor Phase-2 chain changes (specced separately, `depends_on` this):**
`dsar-integration-seams` (`kind: code`) — the `IdentityVerifyProvider` and `RegulatorEscalateProvider`
interfaces, their ADR-019 registries, resolution, and the OR-shipped null/default (fail-closed)
providers; `dsar-case-ui` (`kind: code`) — extending `src/views/avg/AvgIndex.vue` + the `avg` store
module to full case-management (list/detail/transition/evidence/bundle/deny) driven by the policy
pack. **Explicitly out of scope — Phase 3 `avg-consume-or-workflow` (pipelinq):** the actual NL
bindings (BSN/BRP/RvIG identity, AP-complaint escalation) and the NL policy-pack values. OR ships
only the contract + safe defaults; NL data + bindings live in the pipelinq consumer.

## Capabilities

### New Capabilities
- `dsar-policy-pack`: the `dsarPolicyPack` schema/register config contract — deadline durations +
  escalation thresholds, denial-grounds enum with jurisdiction wording, retention windows, intake
  channels, DPO/FG role mapping, letter/notification templates, and the two seam-provider selector
  fields — expressed declaratively (ADR-031), with a fail-closed default pack, and consumed by the
  Phase-1 declarative lifecycle/deadline/notification config.

### Modified Capabilities
<!-- None as delta specs. The Phase-1 capabilities dsar-deadline-tracking and dsar-case-lifecycle
     live in the sibling UNARCHIVED changes dsar-case-subsystem / dsar-case-engine — they are not yet
     in openspec/specs/, so a MODIFIED delta would reference a non-existent base spec. Instead, the
     policy-pack → Phase-1 binding (escalation thresholds, denial-ground wording, retention windows
     resolving FROM the active pack) is captured as ADDED requirements on the new dsar-policy-pack
     capability below. No existing OR requirement is altered. See DEFERRED_QUESTIONS. -->
- _none_

## Impact

- **Config (this change)**: new `lib/Settings/dsar_policy_pack_register.json` (schema + register +
  seed default/example packs); a declarative binding on `lib/Settings/data_subject_request_register.json`
  so Phase-1 `escalationTier`/notifications/`denialGround`/`retentionWindow` resolve from the active
  policy pack.
- **Consumes (from Phase-1, unchanged)**: the `dataSubjectRequest` register, its
  `x-openregister-lifecycle`/`-calculations`/`-aggregations`/`-notifications`, the `denialGround` enum
  keys, and the `retentionWindow` selector — this change supplies their values, it does not alter their
  structure.
- **APIs**: no new routes — the policy pack is served by OR's existing object APIs; leaf apps read/write
  packs through `ObjectService` (RBAC + multitenancy).
- **Downstream (successor context, not specced here)**: `dsar-integration-seams` and `dsar-case-ui`
  `depends_on` this; pipelinq's `avg-consume-or-workflow` (Phase 3) supplies the NL policy pack + the
  two NL seam bindings and retires its app-local AVG surface. No app is migrated by this change.

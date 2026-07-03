## ADDED Requirements

### Requirement: Policy pack is a declarative per-jurisdiction config object
OpenRegister SHALL express the AVG/DSAR policy pack as a declarative `dsarPolicyPack` schema on a
dedicated register (`lib/Settings/dsar_policy_pack_register.json`), NOT as a PHP `PolicyService` or
hard-coded constants (ADR-031). A pack SHALL supply, as configuration data: deadline durations,
escalation-tier thresholds, the denial-grounds enum with jurisdiction wording, retention windows,
intake channels, the DPO/FG role mapping, letter/notification template references, and the two
integration-seam provider selectors. Every schema property MUST declare a human-friendly `title` and
`description` (ADR-011). Packs MUST be read and written through `ObjectService` (RBAC +
multitenancy), never via a custom Entity/Mapper (ADR-001), and every pack edit MUST be recorded in
the existing immutable hash-chained audit trail (`AuditTrailMapper`).

#### Scenario: A pack is stored as a declarative config object
- **WHEN** a jurisdiction pack is created on the `dsar-policy-packs` register
- **THEN** it MUST be a plain OpenRegister object served by the existing object APIs
- **AND** no bespoke PHP policy service or hard-coded threshold constant MUST be introduced to hold its values

#### Scenario: Every pack property is human-labelled
- **WHEN** the `dsarPolicyPack` schema is imported
- **THEN** every property (including nested items of the denial-grounds, escalation-tier, and retention-window collections) MUST carry a `title` and a `description`

@e2e An administrator opens the policy-pack surface, views the default pack's deadline/escalation/denial/retention values, and each field shows its human-readable title.

### Requirement: Deadline and escalation thresholds resolve from the active pack
The Phase-1 escalation-tier calculation and the reminder/escalation/breach notifications on the `dataSubjectRequest` case SHALL resolve their tier boundaries from the active `dsarPolicyPack`.
Resolution MUST be declarative (a cross-object calculation reference) for the case's jurisdiction or
tenant, so that NO escalation-tier boundary remains hard-coded in the register JSON. When no
jurisdiction pack is bound, the default pack MUST supply the values, and the case MUST NOT be left
without a resolvable threshold.

#### Scenario: Escalation boundaries come from the pack, not code
- **WHEN** a jurisdiction pack sets its escalation tier boundaries (e.g. reminder T-7d, escalation T-3d, breach T+0)
- **THEN** the case's `escalationTier` calculation and reminder/escalation/breach notifications MUST use those pack-supplied boundaries
- **AND** changing the boundaries in the pack MUST take effect without any PHP code change

#### Scenario: Default pack applies when no jurisdiction pack is bound
- **WHEN** a case has no bound jurisdiction pack
- **THEN** the default pack's escalation thresholds MUST apply
- **AND** the case MUST always resolve a threshold (never an unresolved/empty boundary)

@e2e A steward changes an escalation-tier boundary on a jurisdiction pack and observes a case's escalation state recompute against the new boundary without a redeploy.

### Requirement: Denial-ground wording and retention windows are supplied by the pack
The pack SHALL map each generic Phase-1 `denialGround` key to a jurisdiction `label` and statutory
`citation`, and SHALL define named retention windows (window key → duration) that supply the Phase-1
`retentionWindow`/`retainUntil` values. The Phase-1 `denialGround` enum keys and `retentionWindow`
selector MUST NOT carry jurisdiction wording or durations in the register JSON — those MUST come from
the active pack. No existing Phase-1 lifecycle transition or denial guard behaviour is altered by
this resolution.

#### Scenario: A denial ground shows jurisdiction wording from the pack
- **WHEN** a handler selects a `denialGround` key on a case whose active pack maps that key to a label and citation
- **THEN** the label and statutory citation MUST come from the pack
- **AND** the register JSON MUST NOT hold jurisdiction-specific denial wording

#### Scenario: A retention window resolves its duration from the pack
- **WHEN** a case selects a named `retentionWindow` (e.g. `standard`)
- **THEN** the window's duration MUST be read from the active pack and applied to `retainUntil`
- **AND** changing the window duration in the pack MUST change resolution without a code change

@e2e A steward selects a denial ground on a case and sees the pack's jurisdiction label and citation, then selects a retention window and sees the pack-defined duration applied.

### Requirement: Integration-seam provider selection is fail-closed config
The pack SHALL name which registered provider a jurisdiction binds for each of the two integration
seams via an `identityVerifyProvider` and a `regulatorEscalateProvider` selector field. When a
selector is unset, or names a provider that is not registered, resolution MUST fall back to the
OR-shipped safe-default (fail-closed) provider — it MUST NOT fail open. The default pack MUST set both
selectors to the OR safe-default provider id. This change supplies the selector fields and default
ids as configuration; the provider interfaces, registries, and resolution are delivered by the
successor `dsar-integration-seams` change.

#### Scenario: An unbound seam defaults to the fail-closed provider
- **WHEN** a pack leaves `identityVerifyProvider` or `regulatorEscalateProvider` unset
- **THEN** the selector MUST resolve to the OR safe-default provider id
- **AND** the safe-default MUST be a fail-closed (refusing) provider, never a fail-open success

#### Scenario: An unknown provider id fails closed
- **WHEN** a pack names a seam provider id that is not registered
- **THEN** resolution MUST fall back to the fail-closed safe-default
- **AND** it MUST NOT silently proceed as if the seam were satisfied

@e2e An administrator inspects the default pack and sees both seam selectors set to the OR safe-default fail-closed provider id.

### Requirement: A fail-closed default pack ships as seed
OpenRegister SHALL ship a jurisdiction-neutral **default** `dsarPolicyPack` as seed data so a fresh
install has a working, fail-closed baseline (generic denial-ground labels, conservative thresholds,
both seam selectors set to the OR safe-default provider), plus an illustrative NL-shaped **example**
pack for reference. All seeded packs MUST use safe placeholders only (nil UUID
`00000000-0000-0000-0000-000000000000`, `YOUR_TOKEN_HERE`, `<role-id>`, `<provider-id>`); no
realistic-looking secrets, UUIDs, or authoritative jurisdiction citations. The NL-shaped pack MUST be
marked illustrative — the authoritative NL policy values are supplied by the Phase-3 pipelinq consumer,
not OR core.

#### Scenario: Fresh install has a working default pack
- **WHEN** OpenRegister is installed and seeded
- **THEN** a jurisdiction-neutral default pack MUST exist with resolvable deadline/escalation/denial/retention values
- **AND** its two seam selectors MUST point at the OR safe-default fail-closed provider

#### Scenario: Seed packs use only safe placeholders
- **WHEN** the seed packs are inspected
- **THEN** every id, token, role, and provider reference MUST be an obvious placeholder (nil UUID, `YOUR_TOKEN_HERE`, `<role-id>`, `<provider-id>`)
- **AND** no realistic-looking secret or authoritative jurisdiction citation MUST be present

@e2e On a fresh seeded install an administrator sees a default policy pack listed with a fail-closed configuration and an illustrative NL example pack.

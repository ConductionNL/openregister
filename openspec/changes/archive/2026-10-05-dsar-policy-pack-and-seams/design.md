## Context

Phase-1 (`dsar-case-subsystem` config head + `dsar-case-engine` imperative successor) shipped a
generic, stateful data-subject-request workflow on the `dataSubjectRequest` register: an N-state
`x-openregister-lifecycle`, deadline-tracking `x-openregister-calculations`/`-aggregations`, and
reminder/escalation/breach `x-openregister-notifications`, plus the engine's evidence/bundle/
redaction/denial-guard/retention-sweep surface. But Phase-1 deliberately left every
jurisdiction-specific *value* as a **provisional hard-coded default** — its own Open Questions call
out the escalation-tier boundaries (reminder T-7d / escalation T-2d / breach T+0), the
`denialGround` enum keys (generic, no wording), and the retention-window durations as candidate
Phase-2 policy-pack data.

ADR-047 rules that **jurisdiction is data, not code**: a jurisdiction/tenant supplies its AVG/DSAR
policy as a **policy pack** (a config object) and binds two **integration seams** (identity-verify,
regulator-escalate). ADR-031 says such configuration is expressed as schema-declarative register
config, not as PHP service classes. This change authors the **policy-pack config contract** — the
`dsarPolicyPack` schema/register the Phase-1 declarative lifecycle/deadline/notification config reads
its now-provisional values from. It is `kind: config`: it adds a register JSON and a declarative
binding, no PHP.

Constraints: the pack lives on an OR register, read/written through `ObjectService` (RBAC +
multitenancy); it must ship a **fail-closed default** so a fresh install works without any leaf-app
pack; and NL specifics (BSN/BRP/RvIG, AP) MUST NOT appear in OR core — they are Phase-3 pipelinq
data.

## Goals / Non-Goals

**Goals:**
- Add a `dsarPolicyPack` schema + register (`lib/Settings/dsar_policy_pack_register.json`) supplying:
  deadline durations + escalation-tier thresholds, the denial-grounds enum **with jurisdiction
  wording** (generic Phase-1 keys → label + statutory citation), retention windows (named window →
  duration), intake channels, the DPO/FG role mapping (ADR-023), letter/notification template
  references, and the two seam-provider selector fields (`identityVerifyProvider`,
  `regulatorEscalateProvider`). Every property carries `title` + `description` (ADR-011).
- Bind Phase-1's `escalationTier` calculation, reminder/escalation/breach notification thresholds,
  `denialGround` wording, and `retentionWindow` durations to resolve from the **active policy pack**
  for the case's jurisdiction/tenant — declaratively, so no hard-coded threshold remains.
- Define the two integration-seam **contracts as config** (contract text + a `null`/default provider
  identity per seam recorded in the pack), fail-closed to the OR-shipped safe default when unbound.
- Seed a jurisdiction-neutral **default** pack (fail-closed working baseline) and an illustrative
  NL-shaped **example** pack, with safe placeholders only.

**Non-Goals:**
- No `IdentityVerifyProvider`/`RegulatorEscalateProvider` interfaces, ADR-019 registries, resolution
  code, or null/default provider classes — that is the successor `dsar-integration-seams`
  (`kind: code`, `depends_on` this).
- No `AvgIndex.vue`/store extension — that is the successor `dsar-case-ui` (`kind: code`,
  `depends_on` this + seams).
- No NL bindings (BSN/BRP/RvIG identity, AP-complaint escalation) and no real NL policy values — that
  is Phase-3 `avg-consume-or-workflow` (pipelinq).
- No change to Phase-1's lifecycle/aggregation *structure* — this change supplies values, it does not
  reshape the graph.

## Decisions

### Declarative-vs-imperative decision (ADR-031)

Default is declarative (schema register). This change authors **only the declarative column**; the
seam registries and UI are scoped into the successor code changes and listed here so the reviewer
sees the whole Phase-2 picture.

| Behaviour | Chosen path | Rationale |
|---|---|---|
| **Policy-pack object** (deadline durations, escalation thresholds, retention windows, intake channels, DPO/FG role map, template refs) | **Declarative** — a `dsarPolicyPack` schema/register | These are pure configuration *values* per jurisdiction/tenant — the exact ADR-031 case for a schema-config object over a `PolicyService`. Gets RBAC, multitenancy, audit trail, versioning, MCP discovery for free via `ObjectService`. Zero PHP. |
| **Denial-grounds enum with wording** | **Declarative** — array-of-objects property on the pack (generic key ← Phase-1 → label + statutory citation) | Phase-1 ships the generic enum *keys*; the *wording* is jurisdiction data. Mapping key→label+citation is a config table, not logic. |
| **Binding Phase-1 thresholds/enum to the pack** | **Declarative** — Phase-1 `escalationTier` calculation + `-notifications` + `denialGround`/`retentionWindow` resolve their values from the active pack | A calculation/notification referencing another object's field is exactly what `x-openregister-calculations`/`@ref` express (ADR-031 declarative cross-object calc). No resolver service. |
| **Which pack is "active" for a case** (jurisdiction/tenant selection) | **Declarative** — a `jurisdiction`/`tenant` key on the pack + the case's tenant scope selects it | Selection is a scoped lookup the object layer already does (multitenancy); no dispatch code. |
| **Seam provider *selection*** (`identityVerifyProvider`/`regulatorEscalateProvider` = which registered provider a jurisdiction binds) | **Declarative** — two string selector fields on the pack, defaulting to the safe-default provider id | The *choice* of provider is config; the pack names a provider id. Fail-closed default id when unbound. |
| **Seam *interface* + *registry* + *resolution*** (`IdentityVerifyProvider`/`RegulatorEscalateProvider` contracts, the ADR-019 registries, resolving a selector id → provider, the null/default fail-closed providers) | **Imperative — deferred to `dsar-integration-seams`** | An interface + registry + runtime resolution is the legitimate ADR-019 registry exception (like the Phase-1 `EvidenceSourceProvider` registry); a schema can't express a PHP interface. Specced in the successor code change. |
| **Case-management UI** (list/detail/transition/evidence/bundle/deny driven by the pack) | **Frontend — deferred to `dsar-case-ui`** | Vue view + store per ADR-004; not a declarative or backend construct. |

### Reuse before build (ADR-011)

Searched `lib/Settings/`, `lib/Service/Gdpr/`, `lib/Formats/`, `src/store/modules/` before proposing:

- **`data_subject_request_register.json`** (Phase-1) — this change binds to its `escalationTier`
  calculation, notification thresholds, `denialGround` enum keys, and `retentionWindow` selector; it
  does not duplicate them, it supplies their values.
- **`decidesk_register.json` / existing OR register JSONs** — the `dsar_policy_pack_register.json`
  follows the same register+schema+`x-openregister-*` fold-at-import shape; no new import path.
- **`ObjectService` (RBAC + multitenancy), `SchemaService`, `RegisterService`** — the pack is a plain
  OR object served by the existing object APIs; no custom Entity/Mapper (ADR-001).
- **`AuditTrailMapper` + hash chain** — pack edits are audited through the existing immutable trail.
- **Phase-1 `EvidenceSourceProvider` registry pattern** — the successor `dsar-integration-seams`
  registries mirror it (noted so that author reuses the shape, not this change).

### Object / schema service patterns (ADR-001, ADR-003)

The policy pack is an OR object under a `dsar-policy-packs` register / `dsarPolicyPack` schema.
Reads/writes flow through `ObjectService`. `SchemaService`/`RegisterService` own the definition loaded
from the register JSON. The `x-openregister-*` blocks fold into the schema `configuration` at import,
the same path Phase-1 uses.

### Fail-closed default

A jurisdiction-neutral **default** pack ships as seed data so a fresh install has a working baseline:
generic ground labels, conservative thresholds, and both seam selectors set to the OR-shipped
safe-default (fail-closed) provider id. When no jurisdiction pack is bound, the default applies and
identity-verify / regulator-escalate resolve to a provider that **refuses** (Phase-1/seams fail-closed
posture, CWE-863) rather than silently succeeding — the actual refusal is enforced by the successor
seams change; this change records the default selector id.

## Risks / Trade-offs

- **[Config head with no runnable seams/UI]** — this change adds the pack schema but the seam
  registries and the UI that consume it land in the two successor changes. → Mitigation: the three are
  a `depends_on` chain; the pack is exercisable via OR's object APIs the moment it imports, and
  Phase-1's declarative config immediately reads real values from it.
- **[Phase-1 binding references a not-yet-populated pack]** — Phase-1 calculations/notifications
  resolve from the active pack; before any pack exists they must not break. → Mitigation: ship the
  default pack as seed so an active pack always exists; Phase-1's provisional defaults remain the
  fallback if resolution finds no pack (documented, non-breaking).
- **[Seam selector points at an unregistered provider]** — a pack may name a provider id no registry
  has. → Mitigation: the default selector id is the OR safe-default (always registered by the seams
  change); an unknown id fails closed (refuse), never fails open — enforced in the seams change,
  contract stated here.
- **[Jurisdiction wording drift]** — statutory citations in the example pack could go stale. →
  Mitigation: the shipped NL-shaped pack is explicitly an *illustrative example* with placeholder
  citations; the authoritative NL wording is Phase-3 pipelinq data, not OR core.
- **[Schema addition is a migration]** — a new register/schema. → Mitigation: it is a **new** register,
  additive; it removes/renames nothing on the Phase-1 register (the binding adds calculation/reference
  fields only, all optional). No repair-step migration; register import UNION-merges additively.
- **[Seed data leaking realistic values]** — packs carry role ids and provider ids. → Mitigation: nil
  UUID, `YOUR_TOKEN_HERE`, `<role-id>` placeholders; no realistic-looking secrets/UUIDs (gitleaks).

## Seed Data

A new `dsarPolicyPack` schema is added, so realistic seed packs are provided, using **safe
placeholders** (nil UUID `00000000-0000-0000-0000-000000000000`, `YOUR_TOKEN_HERE`, `<role-id>`,
`<provider-id>`). Seeded via the OR seed path.

**Pack 1 — Default (jurisdiction-neutral, fail-closed working baseline):**
```json
{
  "id": "00000000-0000-0000-0000-000000000000",
  "name": "Default AVG/DSAR policy pack",
  "jurisdiction": "default",
  "deadlineDurationDays": 30,
  "extensionDurationDays": 60,
  "escalationTiers": [
    { "tier": "reminder", "offsetDays": -7 },
    { "tier": "escalation", "offsetDays": -2 },
    { "tier": "breach", "offsetDays": 0 }
  ],
  "denialGrounds": [
    { "key": "manifestly-unfounded", "label": "Manifestly unfounded or excessive request", "citation": "GDPR Art 12(5)" },
    { "key": "third-party-data", "label": "Would adversely affect third-party rights", "citation": "GDPR Art 15(4)" },
    { "key": "overriding-legitimate-grounds", "label": "Compelling legitimate grounds override the objection", "citation": "GDPR Art 21(1)" }
  ],
  "retentionWindows": [
    { "key": "standard", "durationMonths": 12 },
    { "key": "extended", "durationMonths": 24 }
  ],
  "intakeChannels": ["web-form", "email", "post"],
  "roleMapping": { "dpo": "<role-id>", "handler": "<role-id>" },
  "templates": { "acknowledgement": "template:ack-default", "denial": "template:denial-default" },
  "identityVerifyProvider": "or.default.identity-verify.null",
  "regulatorEscalateProvider": "or.default.regulator-escalate.null"
}
```

**Pack 2 — NL-shaped illustrative example (reference only; real NL data is Phase-3 pipelinq):**
```json
{
  "id": "00000000-0000-0000-0000-000000000000",
  "name": "Voorbeeld NL AVG-beleidspakket (illustratief)",
  "jurisdiction": "nl-example",
  "deadlineDurationDays": 30,
  "extensionDurationDays": 60,
  "escalationTiers": [
    { "tier": "reminder", "offsetDays": -7 },
    { "tier": "escalation", "offsetDays": -3 },
    { "tier": "breach", "offsetDays": 0 }
  ],
  "denialGrounds": [
    { "key": "manifestly-unfounded", "label": "Kennelijk ongegrond of buitensporig verzoek", "citation": "AVG art. 12 lid 5 (illustratief)" }
  ],
  "retentionWindows": [
    { "key": "boekhoudplicht", "durationMonths": 84 }
  ],
  "intakeChannels": ["web-form", "email"],
  "roleMapping": { "fg": "<role-id>", "handler": "<role-id>" },
  "templates": { "denial": "template:denial-nl-example" },
  "identityVerifyProvider": "<provider-id>",
  "regulatorEscalateProvider": "<provider-id>"
}
```

## Migration Plan

Additive only. A **new** `dsar-policy-packs` register/schema is added; the declarative binding on the
Phase-1 `data_subject_request_register.json` adds calculation/reference fields only (all optional),
removing/renaming nothing. No repair-step migration is required — register import UNION-merges the new
register and the additive binding. Rollback is reverting the two register JSONs. The seed packs are
illustrative and can be re-seeded or removed independently.

## Open Questions

- Whether the "active pack" is selected purely by tenant scope, or also by an explicit
  `jurisdiction` field on the case (recommended: tenant scope, with an optional per-case override) —
  final selection semantics deferred to the seams/UI changes that consume the resolution.
- Whether letter/notification templates are `template:` references resolved by a document leaf
  (recommended, ADR-022 content-in-leaves) vs inline strings on the pack — provisional as references.
- Whether the two seam selector fields belong on the pack (recommended, keeps binding declarative) or
  in a separate bindings object — deferred to the `dsar-integration-seams` author.

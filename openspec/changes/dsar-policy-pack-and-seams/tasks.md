# Tasks — dsar-policy-pack-and-seams (kind: config, depends_on: dsar-case-subsystem, dsar-case-engine)

Head of the ADR-047 Phase-2 chain. This change patches register JSON only — it adds the
`dsarPolicyPack` schema/register and binds Phase-1's declarative thresholds/wording/windows to it.
No PHP services, registries, controllers, routes, or Vue — the two integration-seam registries and
the `AvgIndex.vue` extension land in the successor changes `dsar-integration-seams` (kind:code) and
`dsar-case-ui` (kind:code). See DEFERRED_QUESTIONS for the split decision.

## 1. Policy-pack schema + register (config)

<<<<<<< HEAD
- [ ] 1.1 Add `lib/Settings/dsar_policy_pack_register.json` — a `dsar-policy-packs` register + `dsarPolicyPack` schema with a `jurisdiction`/tenant key, `deadlineDurationDays`, `extensionDurationDays`, an `escalationTiers` collection (tier + offsetDays), `intakeChannels`, `roleMapping` (dpo/fg/handler), and `templates` (references); every property + nested item property carries a `title` + `description` (ADR-011).
- [ ] 1.2 Add the `denialGrounds` collection (generic `key` ← Phase-1 → jurisdiction `label` + statutory `citation`) and the `retentionWindows` collection (`key` → duration) to the `dsarPolicyPack` schema, with `title`/`description` on every item property.
- [ ] 1.3 Add the two integration-seam selector fields `identityVerifyProvider` and `regulatorEscalateProvider` (string provider ids, fail-closed to the OR safe-default id when unset/unknown), with `title`/`description`.

## 2. Bind Phase-1 to the pack (declarative)

- [ ] 2.1 Bind the Phase-1 `escalationTier` `x-openregister-calculations` and the reminder/escalation/breach `x-openregister-notifications` on `data_subject_request_register.json` to resolve their tier boundaries from the active pack via a declarative cross-object reference (ADR-031) — remove every hard-coded threshold from the register JSON, falling back to the default pack when no jurisdiction pack is bound.
- [ ] 2.2 Bind the Phase-1 `denialGround` enum wording and the `retentionWindow` durations to resolve from the active pack; ensure the register JSON holds only the generic keys/selectors, not jurisdiction wording or durations, and that no existing Phase-1 lifecycle transition or denial guard is altered.

## 3. Seed data

- [ ] 3.1 Seed a jurisdiction-neutral **default** `dsarPolicyPack` (generic denial labels, conservative thresholds, retention windows, both seam selectors set to the OR safe-default fail-closed provider id) and an illustrative **NL-shaped example** pack — per the design Seed Data section, safe placeholders only (nil UUID `00000000-0000-0000-0000-000000000000`, `YOUR_TOKEN_HERE`, `<role-id>`, `<provider-id>`).

## 4. Verification

- [ ] 4.1 Validate both register JSONs parse and import cleanly (`x-openregister-*` blocks fold into `configuration`); confirm the Phase-1 register change is additive (calculation/reference fields only, all optional; no existing required property removed/renamed) and the new register is standalone/additive.
- [ ] 4.2 Run the config-relevant Hydra gates (`notification-dialect` gate-18, `schema-property-titles` gate-28) and `openspec validate --change dsar-policy-pack-and-seams --strict`; fix any pre-existing issues touched.
=======
- [x] 1.1 Add `lib/Settings/dsar_policy_pack_register.json` — a `dsar-policy-packs` register + `dsarPolicyPack` schema with a `jurisdiction`/tenant key, `deadlineDurationDays`, `extensionDurationDays`, an `escalationTiers` collection (tier + offsetDays), `intakeChannels`, `roleMapping` (dpo/fg/handler), and `templates` (references); every property + nested item property carries a `title` + `description` (ADR-011).
- [x] 1.2 Add the `denialGrounds` collection (generic `key` ← Phase-1 → jurisdiction `label` + statutory `citation`) and the `retentionWindows` collection (`key` → duration) to the `dsarPolicyPack` schema, with `title`/`description` on every item property.
- [x] 1.3 Add the two integration-seam selector fields `identityVerifyProvider` and `regulatorEscalateProvider` (string provider ids, fail-closed to the OR safe-default id when unset/unknown), with `title`/`description`.

## 2. Bind Phase-1 to the pack (declarative)

- [x] 2.1 Bind the Phase-1 `escalationTier` `x-openregister-calculations` and the reminder/escalation/breach `x-openregister-notifications` on `data_subject_request_register.json` to resolve their tier boundaries from the active pack via a declarative cross-object reference (ADR-031) — remove every hard-coded threshold from the register JSON, falling back to the default pack when no jurisdiction pack is bound.
- [x] 2.2 Bind the Phase-1 `denialGround` enum wording and the `retentionWindow` durations to resolve from the active pack; ensure the register JSON holds only the generic keys/selectors, not jurisdiction wording or durations, and that no existing Phase-1 lifecycle transition or denial guard is altered.

## 3. Seed data

- [x] 3.1 Seed a jurisdiction-neutral **default** `dsarPolicyPack` (generic denial labels, conservative thresholds, retention windows, both seam selectors set to the OR safe-default fail-closed provider id) and an illustrative **NL-shaped example** pack — per the design Seed Data section, safe placeholders only (nil UUID `00000000-0000-0000-0000-000000000000`, `YOUR_TOKEN_HERE`, `<role-id>`, `<provider-id>`).

## 4. Verification

- [x] 4.1 Validate both register JSONs parse and import cleanly (`x-openregister-*` blocks fold into `configuration`); confirm the Phase-1 register change is additive (calculation/reference fields only, all optional; no existing required property removed/renamed) and the new register is standalone/additive.
- [x] 4.2 Run the config-relevant Hydra gates (`notification-dialect` gate-18, `schema-property-titles` gate-28) and `openspec validate --change dsar-policy-pack-and-seams --strict`; fix any pre-existing issues touched.
>>>>>>> origin/development

## Acceptance Criteria

- The `dsarPolicyPack` schema/register supplies deadline durations, escalation-tier thresholds, denial-grounds enum with jurisdiction wording, retention windows, intake channels, DPO/FG role mapping, template references, and the two seam selectors — all human-labelled (ADR-011).
- Phase-1's `escalationTier` calculation, reminder/escalation/breach notifications, `denialGround` wording, and `retentionWindow` durations resolve from the active pack; no hard-coded threshold/wording/duration remains in the register JSON.
- Seam provider selection is fail-closed: an unset or unknown selector resolves to the OR safe-default fail-closed provider id; the default pack sets both selectors to that id.
- A jurisdiction-neutral default pack seeds a working, fail-closed baseline; an illustrative NL-shaped example pack is present and marked illustrative.
- No PHP service/registry/controller/route and no Vue is added by this change; both register JSON changes are additive and non-breaking.

## Quality Checklist

- Declarative-first: the pack + the Phase-1 binding are register config, not new Service classes (ADR-031); the seam interfaces/registries/resolution and the UI are correctly deferred to `dsar-integration-seams` / `dsar-case-ui`.
- Reused the Phase-1 `data_subject_request_register.json` (escalationTier/notifications/denialGround/retentionWindow) and `ObjectService`/audit-trail rather than duplicating them (ADR-011, ADR-022); the binding supplies values, it does not reshape the graph.
- Canonical `x-openregister-notifications` dialect only; no obsolete legacy notification fields (gate-18).
- Every added property (including nested collection item properties) declares `title` + `description` (ADR-011, gate-28).
- Fail-closed posture on the two seam selectors (unset/unknown → refusing safe-default, never fail-open) is stated in the config and spec (CWE-863).
- Behavioural spec scenarios carry `@e2e` and are referenced by Playwright e2e (or a reason-bearing `@e2e exclude`); the seam registries + UI e2e land with their own successor changes.
- Seed and example ids/tokens use obvious placeholders (nil UUID, `YOUR_TOKEN_HERE`, `<role-id>`, `<provider-id>`) and no authoritative jurisdiction citations — no realistic-looking secrets/UUIDs (gitleaks).

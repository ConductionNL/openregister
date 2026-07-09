---
kind: code
depends_on: [dsar-policy-pack-and-seams]
---

## Why

Phase-2's head `dsar-policy-pack-and-seams` (kind:config) added the `dsarPolicyPack` config contract,
including two **provider selector fields** (`identityVerifyProvider`, `regulatorEscalateProvider`)
that name which registered provider a jurisdiction binds for each integration seam. But a selector
string only names an id — nothing yet defines the seam **interfaces**, the **registries** leaf apps
register providers into, the **resolution** from a pack selector to a live provider, or the
**fail-closed default** provider that must answer when a seam is unbound. Per ADR-031 that surface is
genuinely code (a PHP interface + registry + runtime resolution — the legitimate ADR-019 registry
exception; a schema cannot express a PHP interface), and per ADR-047 the two seams — identity
verification and regulator escalation — are exactly the pluggable extension points OR **defines** and
a leaf app **binds** later.

This is the **middle change of the Phase-2 chain**: policy-pack (config, the selectors) →
**THIS `dsar-integration-seams`** (code, the seam interfaces + registries + resolution + fail-closed
defaults) → `dsar-case-ui` (code, the `AvgIndex.vue` case-management surface). It ships the two seams
so the Phase-1 case engine calls identity-verify and regulator-escalate **through the registry**
(never a hardcoded provider), resolving the provider by the pack's selector, and fails **closed**
(CWE-863 / ADR-005) when a seam is unbound or names an unknown provider — a missing provider MUST NOT
silently skip an identity check. It defines NO NL bindings: pipelinq's NL→BSN/BRP identity provider
and NL→AP escalation provider are Phase-3, out of scope here.

## What Changes

- **Identity-verify seam** — an `IdentityVerifyProvider` interface (verify a data-subject's identity
  → `verified` / `failed` / `needs-more`), an `IdentityVerifyRegistry` (leaf apps register providers,
  first-wins collision policy, mirroring the existing OR `IntegrationRegistry`/`ObjectSourceRegistry`
  pattern, ADR-019), resolution driven by the active policy pack's `identityVerifyProvider` selector,
  and an OR-shipped **fail-closed default/null provider** (verification unavailable ⇒ NOT
  auto-verified; returns a refusing/unverified result, never success).
- **Regulator-escalate seam** — a `RegulatorEscalateProvider` interface (escalate/dossier a case to a
  supervisory authority), a `RegulatorEscalateRegistry`, resolution driven by the pack's
  `regulatorEscalateProvider` selector, and an OR-shipped **fail-closed default/null provider**
  (escalation unavailable ⇒ refuses, never a silent success).
- **Registry bootstrap + safe defaults** — both registries are registered as shared per-request
  services in `lib/AppInfo/Application.php`, each with its OR safe-default provider registered so a
  fresh install always resolves *some* provider; the default pack (from the head change) points both
  selectors at these default ids.
- **Case-engine wiring point** — the Phase-1 case engine invokes identity-verify (at the
  `verifying` lifecycle state) and regulator-escalate (at denial/escalation) **via the registry**,
  resolving the provider from the active pack's selector; an unbound/unknown selector resolves to the
  fail-closed default (refuse), never fail-open.

**Explicitly out of scope:** the policy-pack config contract + selector fields (delivered by the
head `dsar-policy-pack-and-seams`), the `AvgIndex.vue` case-management UI (successor `dsar-case-ui`),
and all NL bindings — pipelinq's NL→BSN/BRP identity provider and NL→AP regulator provider (Phase-3
`avg-consume-or-workflow`). This change defines the two generic seams; leaf apps register providers
into them later.

## Capabilities

### New Capabilities
- `dsar-identity-verify-seam`: the `IdentityVerifyProvider` interface, the identity-verify registry,
  policy-pack-selector-driven resolution, and the OR fail-closed default provider that leaf identity
  providers (NL→BSN/BRP) bind into later.
- `dsar-regulator-escalate-seam`: the `RegulatorEscalateProvider` interface, the regulator-escalate
  registry, policy-pack-selector-driven resolution, and the OR fail-closed default provider that leaf
  regulator providers (NL→AP) bind into later.

### Modified Capabilities
<!-- None. The Phase-1 case-engine and the policy-pack head are specced in unarchived changes
     (dsar-case-engine, dsar-policy-pack-and-seams), so their requirements are not yet in
     openspec/specs/. The case-engine wiring point and the pack-selector resolution are therefore
     expressed as ADDED requirements on the two new seam capabilities above, not as delta specs. -->

## Impact

- **New code (imperative, ADR-019/ADR-031 registry exception):** `lib/Service/Gdpr/Seam/` (or the
  Phase-1 DSAR service namespace) — `IdentityVerifyProvider` + `RegulatorEscalateProvider` interfaces,
  `IdentityVerifyRegistry` + `RegulatorEscalateRegistry`, and the two fail-closed default provider
  classes.
- **Bootstrap:** `lib/AppInfo/Application.php` registers both registries as shared services and
  registers each OR default provider (mirrors the existing `IntegrationRegistry`/`ObjectSourceRegistry`
  bootstrap).
- **Case-engine wiring:** the Phase-1 case engine (`dsar-case-engine`) calls the two seams through
  the registries, resolving providers from the active `dsarPolicyPack` selectors.
- **Depends on:** `dsar-policy-pack-and-seams` (the selector fields + default provider ids). **Consumed
  by (later):** `dsar-case-ui`; NL leaf providers in Phase-3 pipelinq.
- **No new schema / register / migration.** These are code registries, not stored objects — no seed
  data beyond the head change's default pack.
- **Security:** fail-closed is the invariant (ADR-005, CWE-863). A missing/unknown provider resolves
  to a refusing default; identity verification is never auto-satisfied and escalation is never
  silently skipped.

# Tasks — dsar-integration-seams (kind: code, depends_on: dsar-policy-pack-and-seams)

Middle of the ADR-047 Phase-2 chain (policy-pack config → **THIS** code seams → `dsar-case-ui`).
This change adds two pluggable integration seams — identity-verify and regulator-escalate — as PHP
interfaces + registries + resolution + fail-closed defaults, mirroring the existing OR
`IntegrationRegistry`/`ObjectSourceRegistry` (ADR-019). NO NL bindings (BSN/BRP identity, AP
regulator = Phase-3 pipelinq), NO schema/register/migration/seed, NO Vue (that is `dsar-case-ui`).
Fail-closed is the security invariant (ADR-005, CWE-863).

## 1. Identity-verify seam

- [x] 1.1 Define the `IdentityVerifyProvider` PHP interface (stable provider `id` + verify-for-case → a status result of exactly `verified`/`failed`/`needs-more`) under the DSAR service namespace; SPDX/@license docblock + @spec tags per ADR-011/gate conventions.
- [x] 1.2 Add the `IdentityVerifyRegistry` shared per-request service (`addProvider()`, `private array $providers` keyed by id, first-wins collision + `LoggerInterface` warning) mirroring `lib/Service/Integration/IntegrationRegistry.php`.
- [x] 1.3 Add a `resolve($selectorId)` on the registry that returns the registered provider for the active `dsarPolicyPack` `identityVerifyProvider` selector, and returns the OR fail-closed default when the id is unset or not registered — MUST NOT return null.
- [x] 1.4 Add the OR fail-closed default `IdentityVerifyProvider` (returns unverified: `failed`/`needs-more`, never `verified`); SECURITY-REVIEW: it must never auto-verify and resolution must never fail open (CWE-863).

## 2. Regulator-escalate seam

- [x] 2.1 Define the `RegulatorEscalateProvider` PHP interface (stable provider `id` + escalate-for-case → an outcome carrying a regulator reference + status) under the DSAR service namespace; SPDX/@license docblock + @spec tags.
- [x] 2.2 Add the `RegulatorEscalateRegistry` shared per-request service (same `addProvider()` + first-wins + logger shape as the identity registry / `ObjectSourceRegistry`).
- [x] 2.3 Add a `resolve($selectorId)` on the registry driven by the pack's `regulatorEscalateProvider` selector, falling back to the OR fail-closed default when unset/unknown — MUST NOT return null.
- [x] 2.4 Add the OR fail-closed default `RegulatorEscalateProvider` (refuses: reports escalation not performed, never a silent success); SECURITY-REVIEW: escalation must never be silently skipped or recorded as done when the seam is unbound (CWE-863).

## 3. Bootstrap + case-engine wiring

- [x] 3.1 Register both registries as shared per-request services and register each OR fail-closed default provider at bootstrap in `lib/AppInfo/Application.php`, mirroring the existing `IntegrationRegistry`/built-in-provider registration.
- [x] 3.2 Wire the Phase-1 case engine (`dsar-case-engine`) to call identity-verify at the `verifying` lifecycle state via `IdentityVerifyRegistry::resolve(pack.identityVerifyProvider)` — never a hardcoded provider; the returned status drives the transition.
- [x] 3.3 Wire the Phase-1 case engine to call regulator-escalate at the denial/escalation point via `RegulatorEscalateRegistry::resolve(pack.regulatorEscalateProvider)` — never a hardcoded provider; SECURITY-REVIEW: no `if ($provider !== null)` fail-open branch (hydra-gate-unsafe-auth-resolver anti-pattern).

## 4. Verification

- [x] 4.1 Add unit tests: registration + first-wins collision per registry; resolve returns the pack-selected provider; resolve of unset/unknown selector returns the fail-closed default (never null); identity default = unverified; regulator default = refuse.
- [x] 4.2 Run the relevant Hydra gates (spdx-headers, forbidden-patterns, stub-scan, unsafe-auth-resolver, orphan-auth, spec-coverage gate-16, e2e-coverage gate-19) and `openspec validate --change dsar-integration-seams --strict`; fix any pre-existing issues touched.

## Acceptance Criteria

- Both seams ship a narrow PHP interface: `IdentityVerifyProvider` (verify → `verified`/`failed`/`needs-more`) and `RegulatorEscalateProvider` (escalate → regulator-reference + status).
- Each seam has a shared registry (first-wins collision, logged) that leaf apps register providers into from their own bootstrap, mirroring `IntegrationRegistry`/`ObjectSourceRegistry`.
- Resolution is driven by the active `dsarPolicyPack` selector (`identityVerifyProvider`/`regulatorEscalateProvider`); the case engine calls both seams through the registry, never a hardcoded provider.
- An unset or unknown selector resolves to the OR fail-closed default — identity default is unverified, regulator default refuses — and resolution never returns null (fail-closed, CWE-863).
- Both OR default providers register at bootstrap so a fresh install always resolves a provider; the default pack (head change) selects them.
- No NL bindings, no new schema/register/migration/seed, and no Vue are added by this change.

## Quality Checklist

- Imperative seam registries + interfaces are the justified ADR-019/ADR-031 registry exception, documented in design.md's declarative-vs-imperative table; the *selection* stays declarative on the pack (head change).
- Reused the existing OR `IntegrationRegistry`/`ObjectSourceRegistry` shape and the Phase-1 `EvidenceSourceProvider` sibling rather than inventing a new registry mechanism (ADR-011, ADR-022).
- Fail-closed is enforced in code (resolution never returns null; defaults refuse), specced with a fail-closed scenario per seam, and called out for security review (CWE-863, hydra-gate-unsafe-auth-resolver).
- Every new PHP file under lib/ carries SPDX/@license/@copyright + @spec tags (gate-16, spdx gate); no forbidden debug helpers or stub bodies.
- Behavioural spec scenarios carry `@e2e`; the case-management UI e2e lands with the successor `dsar-case-ui`.
- Any test fixtures use safe placeholders only (nil UUID `00000000-0000-0000-0000-000000000000`, `<provider-id>`, `YOUR_TOKEN_HERE`); no realistic-looking secrets/UUIDs (gitleaks).

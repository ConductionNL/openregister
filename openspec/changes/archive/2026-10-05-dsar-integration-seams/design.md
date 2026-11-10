## Context

ADR-047 rules that OpenRegister owns the AVG/DSAR case workflow and defines **two pluggable
integration seams** a leaf app binds: **identity verification** (NL → BSN/BRP/RvIG) and **regulator
escalation** (NL → AP complaint dossier). Phase-2's head `dsar-policy-pack-and-seams` (kind:config)
shipped the `dsarPolicyPack` config object whose two selector fields — `identityVerifyProvider` and
`regulatorEscalateProvider` — *name* which registered provider a jurisdiction binds per seam, and
whose default pack points both at an OR safe-default id. That head deliberately deferred the actual
seam machinery: a selector string is inert until an interface + registry + resolution + a default
provider exist.

This change (the middle of the Phase-2 chain: policy-pack → **THIS** → `dsar-case-ui`) delivers that
machinery as code. Per ADR-031 an interface + registry + runtime resolution is genuinely imperative —
the legitimate ADR-019 registry exception; a schema cannot express a PHP interface — so this change is
`kind: code`. It is scoped to **two seams, generic only, no NL bindings**. The Phase-1 case engine
(`dsar-case-engine`) is the caller: it invokes identity-verify at the `verifying` lifecycle state and
regulator-escalate at denial/escalation, always **through the registry**, resolving the provider from
the active pack's selector.

Constraints: fail-closed is the hard invariant (ADR-005 no fail-open, CWE-863). A missing, unset, or
unknown provider MUST resolve to a **refusing** default — identity is never auto-verified and
escalation is never silently skipped. No NL specifics (BSN/BRP/RvIG, AP) in OR core — those are
Phase-3 pipelinq providers registered into these registries. Stakeholders: the OR team (owns the two
seam contracts + registries + defaults), `dsar-case-ui` (consumes resolution state in the UI),
pipelinq (registers the NL leaf providers in Phase-3).

## Goals / Non-Goals

**Goals:**
- Define an `IdentityVerifyProvider` interface — verify a data-subject's identity for a case →
  `verified` / `failed` / `needs-more` — and a `RegulatorEscalateProvider` interface — escalate/dossier
  a case to a supervisory authority.
- Ship an `IdentityVerifyRegistry` and a `RegulatorEscalateRegistry`, each a shared per-request
  service leaf apps register providers into via `addProvider()`, first-wins collision policy, mirroring
  the existing OR `IntegrationRegistry` / `ObjectSourceRegistry`.
- Resolve, per seam, the active provider from the case's active `dsarPolicyPack` selector
  (`identityVerifyProvider` / `regulatorEscalateProvider`) via the registry; an unset/unknown selector
  falls back to the OR fail-closed default.
- Ship an OR **fail-closed default/null provider** per seam (identity default returns unverified;
  regulator default refuses) registered at bootstrap so a fresh install always resolves *some* provider.
- Wire the Phase-1 case engine to call both seams through the registries (never a hardcoded provider).

**Non-Goals:**
- No policy-pack config contract or selector fields — that is the head `dsar-policy-pack-and-seams`.
- No `AvgIndex.vue` / store / UI — that is the successor `dsar-case-ui`.
- No NL bindings: NL→BSN/BRP identity provider, NL→AP regulator provider — those are Phase-3 pipelinq
  leaf providers registered *into* these registries; out of scope here.
- No new schema/register/migration/seed — these are code registries, not stored objects. The only
  seed (the default pack) belongs to the head change.
- No change to the Phase-1 lifecycle graph shape — this change supplies the two call-outs the engine
  makes at existing states.

## Decisions

### Declarative-vs-imperative decision (ADR-031)

Default is declarative. This change is deliberately **imperative** for both seams — the ADR-019 /
ADR-031 §"PHP guards / registries remain a legitimate seam" registry exception. A PHP interface,
a registry that collects provider implementations, and runtime resolution of a selector-id → live
provider cannot be expressed as schema config; the *selection* (which provider) is declarative and
lives on the pack (head change), the *contract + resolution + refusal* is code.

| Behaviour | Chosen path | Rationale |
|---|---|---|
| **Seam provider *selection*** (which provider a jurisdiction binds) | **Declarative — already delivered by the head** | Two string selector fields on `dsarPolicyPack`. The *choice* is config data. This change does not re-add them. |
| **`IdentityVerifyProvider` / `RegulatorEscalateProvider` interfaces** | **Imperative — PHP interface** | A provider contract (method signatures, verify/escalate result shape) is a PHP type; a schema cannot express an interface. Legitimate ADR-019 seam. |
| **`IdentityVerifyRegistry` / `RegulatorEscalateRegistry`** (collect providers, resolve by id, first-wins) | **Imperative — shared registry service** | Exactly mirrors OR's existing `IntegrationRegistry` (`lib/Service/Integration/IntegrationRegistry.php`) and `ObjectSourceRegistry` (`lib/Service/ObjectSource/ObjectSourceRegistry.php`): `addProvider()` from each app's bootstrap, single shared per-request instance, first-wins with a collision warning. ADR-019. |
| **Resolution** (pack selector id → live provider, fail-closed default when unset/unknown) | **Imperative — registry `resolve()` method** | Runtime dispatch on config-supplied id, with a refusing fallback. This is the ObjectSourceRegistry read-path pattern (resolve a provider by the id declared in config). |
| **OR fail-closed default / null providers** (identity ⇒ unverified, regulator ⇒ refuse) | **Imperative — default provider classes** | A refusing implementation of each interface, registered at bootstrap. The security invariant lives in code (CWE-863), not config. |
| **Case-engine call-out** (invoke seams at `verifying` / denial-escalation) | **Imperative — engine resolves + calls via registry** | The Phase-1 engine already owns the lifecycle transitions; this change adds the two registry-mediated calls at those points. Never a hardcoded provider. |

### Registry pattern — grounded in the real OR code

Both registries mirror the two existing OR registries verbatim in shape:
- `lib/Service/Integration/IntegrationRegistry.php` — `addProvider()`, `private array $providers`
  keyed by id, first-registration-wins + `LoggerInterface` collision warning, registered as a **shared**
  per-request service via `IRegistrationContext` so every app sees one instance (see its class
  docblock: "single per-request service … so all apps see the same instance").
- `lib/Service/ObjectSource/ObjectSourceRegistry.php` — the resolution reference: "resolves a provider
  by the id declared in a schema's config … and delegates to it when the provider is enabled." Our
  `resolve($selectorId)` returns the registered provider for the id, or the OR fail-closed default when
  the id is unset or not registered.

Bootstrap registration follows `lib/AppInfo/Application.php` where OR registers its built-in
`IntegrationProvider` implementations — the two new registries + their OR default providers register
the same way. Phase-1's `EvidenceSourceProvider` registry (from `dsar-case-engine`) is the closest
sibling and is the shape to reuse.

### Reuse before build (ADR-011)

Searched `lib/Service/Integration/`, `lib/Service/ObjectSource/`, `lib/Service/Lifecycle/`,
`lib/AppInfo/Application.php`, and the Phase-1 `dsar-case-engine` design before proposing:
- `IntegrationRegistry` / `ObjectSourceRegistry` — the exact registry+resolution shape to mirror; not
  duplicated, followed.
- `EvidenceSourceProvider` registry (Phase-1 `dsar-case-engine`) — the sibling DSAR registry; same
  bootstrap + first-wins pattern.
- `dsarPolicyPack` selector fields + default provider ids (head change) — resolution reads these; this
  change does not re-declare them.
- The Phase-1 case engine's `verifying` state and denial/escalation transitions — the call-out sites;
  this change adds the two registry-mediated calls, it does not reshape the lifecycle.

### Fail-closed resolution (ADR-005, CWE-863)

`resolve($selectorId)`:
1. selector id is registered → return that provider;
2. selector id unset OR not registered → return the OR fail-closed default provider (logs a warning),
   NEVER null, NEVER a success shortcut.

The identity default returns an **unverified/needs-more** result (a case is never auto-verified when
verification is unavailable). The regulator default **refuses** the escalation (an escalation is never
recorded as done when the seam is unbound). The caller (case engine) treats a null/absent provider as
impossible — resolution always yields a provider — so there is no `if ($provider !== null)` fail-open
branch (the `hydra-gate-unsafe-auth-resolver` anti-pattern).

## Risks / Trade-offs

- **[Registry with no leaf providers on a fresh install]** — only the OR defaults are registered, so
  every case fails identity-verify closed. → Mitigation: that is the intended safe baseline; leaf
  providers (Phase-3 pipelinq) bind real verification. The default pack's selectors already point at
  the OR default ids, so resolution is well-defined from install.
- **[Selector points at an unregistered provider]** — a pack may name a provider id no registry has
  (typo, uninstalled leaf). → Mitigation: `resolve()` falls back to the fail-closed default and logs;
  never fails open. Contract stated in both seam specs.
- **[Silent fail-open regression]** — a future caller could treat "provider unavailable" as "skip the
  check". → Mitigation: `resolve()` never returns null; the default is a refusing provider; a
  fail-closed scenario is specced per seam and is security-review-relevant (called out in tasks).
- **[Interface churn as NL leaf lands]** — the Phase-3 NL provider might want a richer result shape. →
  Mitigation: keep the interfaces narrow (verify → status enum; escalate → dossier ref + status);
  additive method/field extensions are non-breaking for existing (default) implementations.
- **[Case-engine wiring depends on unarchived Phase-1 specs]** — the engine caller is specced in
  `dsar-case-engine` (not yet archived). → Mitigation: the wiring point is expressed as ADDED
  requirements on the two new seam capabilities here, not as a delta on Phase-1's spec.

## Migration Plan

Additive, code-only. New interfaces + registries + default providers under the DSAR service namespace;
two shared-service registrations + two default-provider registrations in `lib/AppInfo/Application.php`
(mirroring the existing `IntegrationRegistry` bootstrap); the Phase-1 case engine gains two
registry-mediated call sites. No schema, register, migration, or seed. Rollback is removing the
services + registrations and the two engine call sites. Because the OR defaults register at bootstrap
and the default pack already selects them, resolution is well-defined the moment this change lands even
with zero leaf providers.

## Seed Data

None. These are code registries, not stored OpenRegister objects — no new schema or register is added,
so no seed data is required. The only DSAR seed (the fail-closed **default policy pack**) is owned by
the head change `dsar-policy-pack-and-seams`. Any test fixtures use safe placeholders only (nil UUID
`00000000-0000-0000-0000-000000000000`, `<provider-id>`, `YOUR_TOKEN_HERE`); no realistic secrets/UUIDs.

## Open Questions

- Whether identity-verify runs synchronously at the `verifying` transition or as an async job when a
  leaf provider calls an external scheme (BRP/RvIG). Recommended: interface returns a status (`verified`
  / `failed` / `needs-more`) so both a synchronous and a deferred provider satisfy it; the async
  orchestration is a leaf-provider concern, not the seam contract. Flagged in DEFERRED_QUESTIONS.
- Whether the two registries stay separate classes or share a small generic base. Recommended: two
  explicit classes (matching `IntegrationRegistry` vs `ObjectSourceRegistry` staying separate) for
  clarity and independent evolution; revisit only if a third seam appears. Flagged in DEFERRED_QUESTIONS.

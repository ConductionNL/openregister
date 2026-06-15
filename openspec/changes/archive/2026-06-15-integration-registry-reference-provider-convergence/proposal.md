# Integration Registry ↔ IReferenceProvider Convergence (Investigation / Spike)

## Why

ADR-041 (Cross-App Commands via Events; the Integration-Registry / Reference-Provider
Boundary, Proposed 2026-06-15) ratifies typed `IEventDispatcher` events as the canonical
cross-app *command* mechanism and **bounds** the OpenRegister integration registry (the
*leaf* system, ADR-019) so it is no longer mis-used as a cross-app RPC bus. In doing so it
surfaces a **deferred open question** it explicitly hands to a dedicated investigation
(Decision #3 + Consequences "Deferred open question"):

> Cross-app rendering of linked things SHOULD align with NC's native
> `OCP\Collaboration\Reference\IReferenceProvider` (cross-app by design) rather than growing
> a bespoke cross-app provider-contribution mechanism on the OR registry. The OR registry
> keeps its bespoke layer only for the CRUD-over-linked-entities it genuinely adds beyond
> read-only references. **Whether and how it converges with `IReferenceProvider` is deferred
> to a dedicated investigation.**

This change **is** that investigation. It is a **SPIKE / decision-record**, not a registry
refactor. The deliverable is a responsibilities matrix, a go/no-go recommendation, and a
migration blast-radius enumeration — captured as a decision record committed to the
OpenRegister docs. **No production registry code is changed by this change.** A tiny
read-only PoC snippet inside the doc is permitted to illustrate feasibility; nothing is
wired into boot.

## What Changes

- **ADD** an investigation deliverable: a responsibilities matrix splitting the OR
  integration registry's surface into *pure READ/RENDER* responsibilities (candidates for
  delegation to `IReferenceProvider`) versus *genuinely value-adding* responsibilities (the
  CRUD write verbs `create/update/delete`, the link tables, the `(register, schema, objectId)`
  scoping — none of which `IReferenceProvider` offers).
- **ADD** a concrete comparison of the two systems' contracts (OR `IntegrationProvider`
  `list/get/create/update/delete` + metadata, `IntegrationRegistry`, `AbstractIntegrationProvider`,
  `ExternalIntegrationRouter`, the 22 built-in providers and OR's *existing*
  `ObjectReferenceProvider`) against NC's `IReferenceProvider` / `IReference` /
  `ISearchableReferenceProvider` / `IDiscoverableReferenceProvider` / `RenderReferenceEvent` /
  `IRegistrationContext::registerReferenceProvider()` / `IReferenceManager` caching.
- **ADD** a go/no-go **recommendation** — one of *converge* / *partial-converge* /
  *keep-separate-but-align* — with rationale, a phased follow-up plan (if converging), and the
  risks.
- **ADD** a migration **blast-radius** enumeration: manifest `referenceType` markers, the 22
  built-in providers, the frontend single-entity widgets / `useIntegrationRegistry`, and the
  ADR-019 / ADR-036 surface that depends on the registry contract.
- **NO** change to `lib/Service/Integration/*`, `lib/Reference/*`, `Application.php`,
  routes, schemas, or any frontend code. This is a spike.

## Impact

- **Affected specs:** `integration-registry-reference-provider-convergence` (new — states the
  investigation's required outputs as verifiable requirements).
- **Affected docs:** new decision record under `docs/development-notes/`.
- **Affected code:** none (read-only investigation).
- **Downstream:** the recommendation feeds a future implementation change (out of scope here)
  and resolves the ADR-041 deferred open question so the fleet knows whether to keep building
  on the bespoke registry or align with `IReferenceProvider`.

# ADR-019: Integration Registry Pattern

## Status
Proposed

## Date
2026-04-21

## Context

Conduction apps (OpenCatalogi, Procest, Pipelinq, MyDash, Decidesk, DocuDesk, ZaakAfhandelApp, Larpingapp, Softwarecatalog, OpenRegister itself) all consume the same set of "things linked to an object" — files, notes, tasks, calendar events, mail, contacts, deck cards, talk conversations, and an expanding catalogue of NC-ecosystem and external services.

Until now this was implemented in two rigid places:

- `OCA\OpenRegister\Service\LinkedEntityService::TYPE_COLUMN_MAP` — a hardcoded PHP constant naming the 8 supported NC entity types.
- `@conduction/nextcloud-vue::CnObjectSidebar` — a Vue component with 5 hardcoded tabs and inline imports for each.

Adding a new integration required modifying both core OR and the shared component library. External services (OpenProject, XWiki, ...) had no path at all. Of the 8 backend-supported types, only 5 had sidebar UI and only 2 had widget components — a glaring asymmetry that grew worse with every new backend integration that landed without UI.

## Decision

Adopt a **two-sided integration registry** pattern as the canonical mechanism for declaring "things that can be linked to or rendered alongside an OpenRegister object."

### The contract — one provider, three artifacts

Every integration ships a vertical slice declared via:

1. A PHP class implementing `OCA\OpenRegister\Service\Integration\IntegrationProvider` (registered via DI tag `IntegrationProvider`).
2. A frontend registration call `OCA.OpenRegister.integrations.register({ id, label, icon, tab, widget, ... })`.

The two registrations share the same `id` — backend and frontend are paired by id, not by import.

### Three-stage filter

What the user actually sees is decided by three independent filters, each with distinct ownership:

| Stage | Owner | Question |
|---|---|---|
| **Registry** | Provider author (system) | Does this integration exist + is the required NC app installed? |
| **Schema** | Schema author (data designer) | Is this integration relevant to objects of this schema? |
| **Component** | Page author (app developer) | Should this integration appear on THIS surface? |

Stage 1 is `IntegrationRegistry::getEnabled()`. Stage 2 is the schema's `configuration.linkedTypes` whitelist. Stage 3 is the rendering component's `excludeIntegrations` prop (or equivalent layout choice).

Each stage has clear ownership; debugging "why isn't X showing?" walks the three stages in order.

### Widget parity is a hard rule

Registering an integration without **both** a sidebar tab component **and** a card widget component is a CI-enforced failure. The check runs in pre-commit, repository CI, and the hydra quality gate. Tab-only or widget-only integrations are not permitted.

### Four widget surfaces with graceful fallback

Widgets render across four surfaces: `user-dashboard`, `app-dashboard`, `detail-page`, `single-entity`. A registered widget receives the `surface` as a prop and may branch internally. Optional surface-specific components (`widgetCompact`, `widgetExpanded`, `widgetEntity`) are used when present. A new surface added in the future falls back to the main `widget` — no re-registration required from existing integrations.

### External integrations route through OpenConnector

Providers may declare `getStorageStrategy() === 'external'` and reference an OpenConnector source. OR's `ExternalIntegrationRouter` handles dispatch + auth-status surfacing. OR does not own credentials — OpenConnector does. The provider declares its `authRequirements()` so OR can show a unified admin UI and surface auth status via OCS capabilities.

### Schema validator is registry-driven

`Schema::validateLinkedTypesValue()` consults `IntegrationRegistry::listIds()` rather than a hardcoded constant. New integrations are immediately valid as `linkedTypes` values without core changes.

### Reference-property auto-rendering

A new schema property marker `referenceType: <integration-id>` causes `CnFormDialog` and `CnDetailGrid` to render the matching integration's `single-entity` widget inline next to the property. The integration registry is the single source of truth for "how to render a linked thing of this type" everywhere it appears, not just in sidebars and dashboards.

## Consequences

### Positive

- **Extensibility**: any Conduction app, third-party integrator, or external-service connector can add an integration without modifying OR core or `@conduction/nextcloud-vue`.
- **Consistency**: every integration is rendered the same way, with the same lifecycle, the same RBAC hooks, the same auth surface, the same parity contract.
- **Discoverability**: integrations are advertised via OCS capabilities — mobile apps, partner integrations, and other NC apps can discover what's available without proprietary endpoints.
- **Parallelism**: leaf changes (one per integration) hang off this contract and run in parallel through hydra's pool. The current backend-vs-UI asymmetry cannot recur — parity is enforced.
- **Future flexibility**: the contract is "linked thing"–shaped so `RelationsService` (object↔object) can be unified under the same registry in a future change without breaking changes.

### Negative

- **Onboarding ceremony**: adding a new integration means more files than before (provider, tab, widget, registration, spec delta, tests). Mitigated by `scripts/scaffold-integration.sh <id>` which generates the skeleton.
- **Bundle discipline**: an integration that fails to register (wrong load order, missed `register()` call) silently vanishes. Mitigated by the parity CI gate catching missing declarations pre-merge and a dev-mode warning when a backend provider has no frontend counterpart.
- **One more abstraction**: developers reading sidebar/dashboard code must understand "why isn't this just a static import?" Mitigated by the developer guide and this ADR.

### Migration risks

- **Schema `linkedTypes` referencing not-yet-registered ids**: handled — validation is permissive on read (warns but doesn't reject), strict on write only when adding.
- **External consumers of `LinkedEntityService::TYPE_COLUMN_MAP`**: the constant is private-by-convention and not documented as public API; we don't expect external consumers. It is `@deprecated` here and removed in a follow-up cleanup change once built-in providers stabilise.
- **`CnObjectSidebar` props/slots**: every existing prop and slot is preserved. Snapshot tests guard against regressions on the 5 existing tabs.

## Companion ADR

This ADR codifies the **mechanism**. A separate companion ADR — **ADR-020: Apps Consume OpenRegister Abstractions** — codifies the broader **principle**: Conduction apps hook into OpenRegister's abstractions (registers, schemas, objects, integrations, RBAC, audit, archival, ...) rather than building parallel mechanisms. ADR-020 is authored separately; ADR-019 is the first concrete instance of that principle being applied systematically.

## Implementation reference

- Umbrella change: `openregister/openspec/changes/pluggable-integration-registry/` (proposal, design, tasks, spec, hydra.json)
- Implementation files: `openregister/lib/Service/Integration/`, `nextcloud-vue/src/integrations/`
- Developer guide: `openregister/docs/integrations/README.md`
- Scaffold script: `openregister/scripts/scaffold-integration.sh`
- Parity check: `openregister/scripts/check-integration-parity.sh`

## References

- ADR-004 — Frontend (Vue 2, axios, components)
- ADR-007 — i18n (nl + en required)
- ADR-010 — NL Design System
- ADR-011 — Schema standards
- ADR-017 — Component composition
- ADR-018 — Widget header actions
- ADR-020 — Apps consume OR abstractions (companion, separate change)

## Ownership

OpenRegister team owns the registry contract, the built-in providers, and the schema validator changes. `@conduction/nextcloud-vue` maintainers own the frontend registry, surface contracts, and the three new widgets. Each integration leaf change has its own owner.

# Pluggable Integration Registry

## Problem

OpenRegister can already link objects to eight Nextcloud-native entity types (files, notes, todos, tags, audit trail, and — via the in-flight `nextcloud-entity-relations` and separate Talk work — mail, calendar, contacts, deck, and talk). That linkage is implemented at the database and API layers. **The user interface is far behind:**

| Layer | Present | Missing |
|---|---|---|
| Backend (controllers + services) | 10 entity types wired | — |
| Sidebar tabs (`CnObjectSidebar`) | 5 (files, notes, tasks, tags, audit trail) | email, calendar, deck, contacts, talk |
| Card widgets (dashboard / detail page) | 2 (CnNotesCard, CnTasksCard) | files, tags, audit trail, and all 5 missing tab types |

Two further problems sit underneath that gap:

1. **The mechanism is not extensible.** `LinkedEntityService::TYPE_COLUMN_MAP` is a hardcoded PHP constant. `CnObjectSidebar` hardcodes its five tabs with inline imports. Adding an integration today requires changes to the core of both OpenRegister and `@conduction/nextcloud-vue`.
2. **External services have no path.** Consumers have asked for OpenProject, XWiki, and similar integrations. The current design gives them nowhere to plug in — the `TYPE_COLUMN_MAP` is NC-native by construction.

The consuming apps (OpenCatalogi, Procest, Pipelinq, ZaakAfhandelApp, MyDash, Softwarecatalog, Decidesk, DocuDesk, Larpingapp) would all benefit from a richer, consistent set of integrations on every detail page and dashboard — but the current architecture makes every new integration a core-library change.

## Context

- **Existing work already done / in flight:**
  - `LinkedEntityService` with 8 canonical types (`mail`, `contacts`, `notes`, `todos`, `calendar`, `talk`, `deck`, `files`) — `TYPE_COLUMN_MAP` constant in [lib/Service/LinkedEntityService.php](lib/Service/LinkedEntityService.php)
  - **Schema-level `linkedTypes` configuration** already exists — [lib/Db/Schema.php:1729](lib/Db/Schema.php#L1729) hardcodes the same 8 types as `VALID_LINKED_TYPES` and each schema's `configuration` array may declare which subset applies to its objects. This is the **relevance layer** — the current design intent is "registry says what exists; schema says what's relevant."
  - `nextcloud-entity-relations` (tracking issue #1095) — backend for mail, calendar, contact, deck
  - Talk backend — `ChatController` (881 LOC) and `ConversationController` (958 LOC) already landed
- **Existing UI components in `@conduction/nextcloud-vue`:**
  - `CnObjectSidebar` with 5 hardcoded tabs + `hidden-tabs` prop for per-component filtering (the **component-level filter**)
  - `CnNotesCard` and `CnTasksCard` as the only two card widgets
  - A broad component library (`CnDashboardPage`, `CnDetailPage`, `CnWidgetRenderer`) that is ready to consume dynamic widget sets
- **The three UI surfaces a widget must render in:**
  1. **General user dashboard** (Nextcloud home dashboard — personal overview across all apps)
  2. **App-specific dashboard** (e.g., Procest's case dashboard, Pipelinq's kanban overview)
  3. **Item detail page** (the right-rail sidebar AND in-page widget cards on a single object)

  All three must be served by the same registered widget — either one component flexible enough for all contexts, or named variants the integration declares together.
- **Established patterns:**
  - Sub-resource endpoints — `/api/objects/{register}/{schema}/{id}/{entity}` (from `object-interactions`)
  - ObjectCleanupListener cascade-on-delete
  - NL Design System theming (components use Nextcloud CSS variables only)
- **Org-wide ADRs that constrain this:** ADR-004 (Frontend — Vue 2, axios, components), ADR-007 (i18n — nl + en required), ADR-010 (NL Design System), ADR-011 (Schema standards), ADR-017 (Component composition).
- **Meta-ADR question (raised during exploration, pending):** Conduction apps should *consume* OpenRegister's abstractions (registers, schemas, objects, integrations) rather than build parallel mechanisms. No org-wide ADR currently codifies this principle. A companion org-wide ADR ("Apps consume OpenRegister abstractions") is recommended but lives outside this umbrella in `hydra/openspec/architecture/`.
- **Consuming apps with case / detail views that will gain from this:** Procest (zaakafhandeling), Pipelinq (kanban), ZaakAfhandelApp (ZGW), OpenCatalogi (catalog entries), Decidesk (decisions/minutes), DocuDesk (document workflows), MyDash (personal dashboard), Softwarecatalog, Larpingapp.

## Proposed Solution

Introduce a **pluggable integration registry** that decouples integration definitions from the core of OpenRegister and `@conduction/nextcloud-vue`. An "integration" is a first-class, declarable concept with a uniform contract covering backend provider, sidebar tab, and card widget.

The design is structured as a **three-stage filter** — existence, relevance, context:

```
    ┌─────────────────────────────────┐
    │ 1. REGISTRY (existence)         │   "what integrations exist on this NC instance?"
    │    • PHP + JS registries        │
    │    • Provider::isEnabled() gate │   (skips when required NC app is absent)
    └──────────────┬──────────────────┘
                   ▼
    ┌─────────────────────────────────┐
    │ 2. SCHEMA (relevance)           │   "which of those are relevant to objects of this schema?"
    │    • linkedTypes config         │
    │    • becomes registry-driven    │   (new: replaces hardcoded VALID_LINKED_TYPES)
    └──────────────┬──────────────────┘
                   ▼
    ┌─────────────────────────────────┐
    │ 3. COMPONENT / APP (context)    │   "which of THOSE should THIS surface show?"
    │    • hiddenTabs / excludeIntegr │
    │    • per-page, per-dashboard    │
    └─────────────────────────────────┘
```

Each stage already has a hook point — the change makes them all pluggable + consistent + registry-driven.

### Core pieces

1. **`IntegrationProvider` contract (PHP interface)** — every backend integration implements this. Declares id, label, icon, required NC app, storage strategy (`magic-column` | `link-table` | `external`), and exposes sub-resource CRUD for the object-level API.
2. **`IntegrationRegistry` service (PHP)** — DI-tag-based registration. Core reads this registry when dispatching `/api/objects/{register}/{schema}/{id}/{integrationId}` requests. Replaces the hardcoded `TYPE_COLUMN_MAP` constant (kept internally as an implementation detail of the built-in providers).
3. **Schema validator becomes registry-driven** — the hardcoded `VALID_LINKED_TYPES` in `Schema::validateLinkedTypesValue()` is replaced by `IntegrationRegistry::listIds()`. Adding a new integration no longer requires patching schema validation — a provider self-declares its id and the schema validator accepts it.
4. **JS integration registry (`window.OCA.OpenRegister.integrations`)** — mirror contract on the frontend. Each integration registers its tab component + widget component(s), label, icon, ordering, and grouping. `CnObjectSidebar`, `CnDashboardPage`, and `CnDetailPage` resolve UI elements dynamically from the registry.
5. **Widget-parity contract (hard rule)** — registering an integration without both a tab component *and* a card widget component is a CI-enforced failure. The registry rejects registration; a quality gate script validates every provider has `widget` set. The same widget must render correctly in all three surfaces: user dashboard, app dashboard, item detail page.
6. **External integration routing via OpenConnector** — providers may declare `storage: "external"` and reference an OpenConnector source. The registry routes their sub-resource calls through the connector rather than a local link table. This is the plumbing for OpenProject, XWiki, and future external services.
7. **Migration of existing types** — `files`, `notes`, `todos`, `tags`, `audit-trail` are refactored into built-in `IntegrationProvider` implementations. Behaviour is unchanged; structure is uniform. The schema-level `linkedTypes` config becomes the relevance layer for all of them, not just the four link-table types.
8. **Fill the parity gaps** — ship `CnFilesCard`, `CnTagsCard`, and `CnAuditTrailCard` widgets so the hard rule is satisfied for the five migrated types.
9. **Documentation + ADR** — add an ADR for the registry pattern and a developer guide ("How to add an integration") alongside the OpenRegister developer docs. Location TBD (see open question on ADR location).

### What this change does not do

This is the **umbrella**. The individual integrations (email, calendar, deck, contacts, talk, forms, maps, collectives, openproject, xwiki, ...) are **leaf changes** that depend on this umbrella. They are not built here.

## Scope

### In scope

- `IntegrationProvider` PHP interface (relations-shaped — generic "linked thing", not NC-entity-specific) and `AbstractIntegrationProvider` base class
- `IntegrationRegistry` PHP service with DI-tag-based registration + in-request caching
- `IntegrationProvider::authRequirements()` declaration + admin UI surface for missing credentials (the umbrella defines the mechanism; OpenConnector handles the actual auth flows)
- `IntegrationProvider::requiresPermission(): ?string` — optional integration-level RBAC, null by default
- Schema validator refactor — `Schema::validateLinkedTypesValue()` consumes the registry rather than a hardcoded list
- Frontend JS registry (`window.OCA.OpenRegister.integrations`) + `useIntegrationRegistry` composable
- Three-stage filter implementation (registry → schema `linkedTypes` → component override) in `CnObjectSidebar`, `CnDashboardPage`, `CnDetailPage`
- Widget rendering across **four** surfaces with graceful fallback to main `widget`: `user-dashboard`, `app-dashboard`, `detail-page`, `single-entity` (the last is for reference-property rendering, see below)
- **Reference-property rendering** — `CnFormDialog` / `CnDetailGrid` detect schema properties typed as references to NC entities and auto-render the matching integration's `single-entity` widget surface. Schema property type system extended with a `referenceType` marker.
- Widget-parity CI check (`scripts/check-integration-parity.sh`) — runs locally, in hydra quality gate, in repo CI
- Migrate existing 5 types (files, notes, tasks, tags, audit trail) into `IntegrationProvider` implementations
- Ship missing widgets: `CnFilesCard`, `CnTagsCard`, `CnAuditTrailCard`
- External/OpenConnector routing mechanism (`ExternalIntegrationRouter`) — the plumbing; no specific external service shipped here
- OCS capabilities exposure — full registry (id + label + group + enabled + permission + auth status) advertised via `/ocs/v2.php/cloud/capabilities`
- Deprecate `LinkedEntityService::TYPE_COLUMN_MAP` constant — marked deprecated in this change; removal scheduled in a follow-up cleanup change after built-in providers stabilise
- ADR `hydra/openspec/architecture/adr-019-integration-registry.md` — org-wide (multiple Conduction apps consume the registry)
- Companion ADR flagged but **not authored here**: `adr-022-apps-consume-or-abstractions.md` (org-wide principle — separate small change in hydra)
- Developer guide: `docs/integrations/README.md` — "How to add an integration"
- Scaffold script: `scripts/scaffold-integration.sh <id>` — generates the skeleton a leaf change needs
- Spec delta: new `generic-integrations` capability
- Full nl + en translations for all new strings (ADR-007)
- Backwards compatibility: existing `CnObjectSidebar` consumers continue to work unchanged (all current props + slots honoured)

### Out of scope

- Building any specific new integration (email, calendar, deck, contacts, talk, forms, maps, collectives, polls, bookmarks, activity, photos, flow, analytics, time tracker, shares, cospend, openproject, xwiki) — each of these is a **leaf change** that depends on this umbrella.
- Changing the on-disk storage of existing link tables — migration is structural/API-level only; data model is preserved.
- Reworking the OpenConnector source/consumer API itself — this change uses it as-is.
- Fusing `LinkedEntityService` with `RelationsService` — they remain distinct in this umbrella. The contract is *shaped* to allow future unification (provider talks about "linked things" generically) but the unification is a separate later change.
- Removing `LinkedEntityService::TYPE_COLUMN_MAP` — deprecated here, removed in a follow-up cleanup change.
- Authoring the companion `adr-022-apps-consume-or-abstractions.md` — flagged as required, lives in hydra, separate change.
- Dashboard / detail page composition per consuming app — each app chooses which integrations to include on which surfaces.
- Migration of existing schema reference properties to use the new `referenceType` marker — schemas opt in as needed; no bulk migration.

## ADR-028 task-cap waiver

ADR-028 caps tasks per change at 15. This umbrella's `tasks.md` contains ~70 tasks. The waiver rationale:

- The contract, the registry, the schema-validator refactor, the migration, the parity gate, the OCS capabilities work, and the five built-in provider migrations form a single cohesive change. Splitting them across multiple changes would require interleaved `depends_on` chains (e.g. "frontend registry depends on backend interface depends on schema validator") that ship slower and review worse than one umbrella.
- The 20 leaf changes that hang off this umbrella each stay within the 15-task cap. The task volume here is concentrated by design — the umbrella owns the cross-cutting wiring; leaves own per-integration vertical slices.
- Hydra's builder SHOULD batch this umbrella across multiple turns rather than attempting a single-turn implementation; turn-budget pressure is acknowledged and budgeted for.

This waiver SHALL be re-evaluated after the umbrella is implemented; if reviewers find a clean split point, the next umbrella that exceeds the cap should follow that pattern instead.

## CI follow-up — spec validation

This PR introduces 21 changes (umbrella + 20 leaves) with rich cross-references. There is currently no spec-validation workflow on `openspec/changes/**`. As a follow-up (separate change), a `.github/workflows/spec-validate.yml` SHOULD be added that:

- resolves every markdown link in `openspec/changes/**` and fails on 404,
- validates `hydra.json` files against the project schema,
- reports `tasks.md` checkbox counts so ADR-028 deviations are visible at PR-time.

Two of the blockers in this PR (broken cross-references in shares + openproject + umbrella) would have been caught by such a workflow. Tracked separately to keep this umbrella focused on the integration registry itself.

## Leaf plan (for reference, not built here)

20 leaf changes will hang off this umbrella, grouped into three waves. Each leaf follows the same shape: `IntegrationProvider` implementation + sidebar tab + card widget + registry entry + spec delta + tests.

| Wave | Leaves | Rationale |
|---|---|---|
| **W1 — Backend ready, UI missing** | email, calendar, deck, contacts, talk | Backends already exist. Pure UI + registry wiring. |
| **W2 — New NC-native ecosystem** | forms, polls, bookmarks, maps, collectives, activity, photos, flow, analytics, time-tracker, shares, cospend | Greenfield per integration. |
| **W3 — External via OpenConnector** | openproject, xwiki | Prove the external-routing pattern. |

Dependency graph is flat: every leaf `depends_on: ["pluggable-integration-registry"]` and on nothing else in the set. They can run in parallel through hydra's 5-slot pool.

## Success criteria

- [ ] An internal developer (or consuming app team) can add a new integration by writing one PHP provider + one Vue tab + one Vue widget + one registry call. No changes to `CnObjectSidebar`, `Schema` validation, or OpenRegister core required.
- [ ] Every integration in the registry has a sidebar tab AND a card widget — enforced by `check-integration-parity.sh` in pre-commit, repo CI, and hydra quality gate.
- [ ] The same widget component renders correctly in all three surfaces: user dashboard, app dashboard, item detail page.
- [ ] Existing `CnObjectSidebar` consumers continue to work with zero code changes — every current prop + slot honoured.
- [ ] The three-stage filter (registry existence → schema relevance → component context) is observable: developers can ask "why isn't this integration showing?" and get a clear answer from any of the three stages.
- [ ] The five previously-hardcoded types (files, notes, tasks, tags, audit trail) are available via the registry, with widgets for all five.
- [ ] An external integration can be declared and backed by OpenConnector with no code changes in OpenRegister core.
- [ ] Adding a new integration id (beyond the current 8) does not require patching `Schema::VALID_LINKED_TYPES`.
- [ ] nl + en translations present for every new user-facing string.
- [ ] A leaf change scaffold can be generated in one command: `./scripts/scaffold-integration.sh integration-forms` creates the provider + tab + widget stubs + spec delta + hydra.json with the correct dependency on this umbrella.

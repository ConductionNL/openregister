# Reverse-spec service-facade bundle — chunk 1

## Why

A scanner flagged 112 public/private methods across 19 top-level `lib/Service/*.php`
files as missing the `@spec` traceability tag mandated by ADR-003. These files are
predominantly **facades** — they delegate to handlers, mappers, and lazily-resolved
peer-app services, holding little business logic of their own. This reverse-spec change
documents the *observed behavior* of the genuinely uncovered orchestration and attaches
every method to a spec: either a new requirement against the capability that already owns
its domain (bias-to-extend), an existing requirement that already documents it, or an
`@spec exclude <reason>` annotation for boilerplate (per ADR-003's two-tool convention).

No code behavior changes — this is annotation-only.

## What changes

- **53 methods spec'd**, **59 methods excluded** (facades are mostly plumbing).
- **5 new requirements** across 5 capability specs documenting the genuinely uncovered
  orchestration:
  1. `generic-integrations` — the Tier-2 dedicated-mapper link-service pattern shared by
     the six optional-peer-app link services (Analytics, Calendar, Deck, Email, Flow,
     Poll): link / create-and-link / unlink / cache-refreshing list / picker, all behind
     a `class_exists` + availability guard with graceful degradation (ADR-019 AD-23).
  2. `datetime-input-handling` — the canonical `DateTimeNormalizer` entry points
     (`normalize` / `formatForDatabase` / `formatForIso8601`) that all OR datetime
     conversion delegates to (the capability spec body was a deferred placeholder).
  3. `webhook-payload-mapping` — the `MappingService::executeMapping()` transformation
     engine semantics (dot-notation source lookup, Twig rendering, pass-through, list
     mode, key encoding) that the webhook spec referenced but never specced as its own
     behavior.
  4. `files-sidebar-tabs` — the backend `FileSidebarService` that powers the Files-app
     sidebar tab: cross-magic-table reverse lookup of objects referencing a file plus
     the document extraction / anonymization status read.
  5. `zoeken-filteren` — the search-trail **analytics** statistics surface
     (`getPopularSearchTerms` / `getRegisterSchemaStatistics` / `getSearchActivity` /
     `getSearchStatistics` / `getUserAgentStatistics`) that enriches raw mapper
     aggregations with percentages, effectiveness ratings, success rates, and
     browser-distribution parsing.

- **8 methods annotated against existing requirements** (no new REQ):
  - `ConditionMatcher::objectMatchesConditions` → `rbac-scopes` Conditional Match
    Evaluation (AND-logic entry point; matcher internals already specced).
  - `WorkflowEngineRegistry` `createEngine` / `updateEngine` / `deleteEngine` /
    `discoverEngines` / `resolveAdapterById` → `workflow-engine-abstraction` Engine
    Registration and Discovery + Credential-management requirements.
  - `McpDiscoveryService::getCapabilityIds` → `mcp-discovery` Capability Coverage
    (the requirement names this method as the canonical capability-id source).
  - `SearchTrailService::createSearchTrail` → `zoeken-filteren` Saved-searches-and-
    search-trails requirement (self-clearing already documented there).

## Excluded methods (boilerplate — `@spec exclude`)

| File | Methods | Reason class |
|---|---|---|
| `ObjectService` | 40 | Facade: handler/mapper delegations, context getters/setters, deprecated throwing stubs. CRUD / lock / merge / search / facet / relation behaviors already owned by object-interactions / object-lifecycle / zoeken-filteren / faceting-configuration. |
| `SettingsService` | 5 | Pure utility helpers (`convertToBytes`, `formatBytes`, `maskToken`, `compareFields`, `getExpectedSchemaFields`) backing the Solr/settings admin surface. |
| `MigrationService` | 4 | Legacy facade — blob storage retired; `migrateToMagicTable` / `migrateToBlobStorage` return no-op reports, `resolveRegisterAndSchema` / `getStorageStatus` are mapper-read plumbing. |
| `MappingService` | 4 | Cache-wrapped read (`getMapping`), cache invalidation (`invalidateMappingCache`), and two pure helpers (`encodeArrayKeys`, `coordinateStringToArray`). |
| `EmailService` | 2 | Legacy link-table delete plumbing (`unlinkEmail`, `deleteLinksForObject`) being phased out per linked-entity-types "Remove Email-Specific Link Infrastructure". |
| `VectorizationService` | 2 | One-line facade delegations to the underlying `vectorService` (`semanticSearch`, `hybridSearch`). |
| `UploadService` | 1 | Source-dispatch helper (`getUploadedJson`) routing to per-source processors. |
| `SearchTrailService` | 1 | Thin find + name-enrich (`getSearchTrail`). |

## Impact

- Specs: 5 capability specs gain a `## ADDED Requirements` delta (one new REQ each).
- Code: annotation-only (`@spec` / `@spec exclude` docblock tags); zero behavior change.
- Risk: none — documentation of existing behavior.

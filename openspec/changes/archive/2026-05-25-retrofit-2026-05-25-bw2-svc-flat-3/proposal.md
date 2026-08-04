# Retrofit — backend coverage, Service facades chunk 3 (2026-05-25)

Reverse-spec / annotation pass over batch `bw2-svc-flat-3`: **111 uncovered public methods
across 21 top-level `lib/Service/*.php` files**. These are the top-level service facades —
thin wrappers, integration link services, and search/index plumbing that mostly delegate to
mappers, handlers, or late-bound peer-app services. Per ADR-003 every method ends tagged:
genuinely-novel un-homed behaviour is reverse-spec'd into a new capability; everything else is
either annotated to an existing capability requirement or carries an `@spec exclude <reason>`.

## Why

These 21 files were flagged by `/opsx-coverage-scan` as carrying public methods without a
`@spec` tag. The bias for facade plumbing is `@spec exclude` — a thin route into an
already-specced collaborator does not warrant its own requirement. Only two clusters carry
real behaviour with no existing capability home, and those are the ones reverse-spec'd here.

## Counts

- **111 methods** triaged total.
- **7 spec'd** (reverse-spec → 4 new REQs across 2 new capabilities):
  - `schema-property-exploration` (2 REQs): `SchemaService::exploreSchemaProperties`,
    `SchemaService::updateSchemaFromExploration`.
  - `file-risk-classification` (2 REQs): `RiskLevelService::computeRiskLevel`,
    `updateRiskLevel`, `getRiskLevel`, `initMetadataKey`, `getAllRiskLevels`.
- **24 annotated** to existing capability tasks (no new REQs):
  - `UserService` self-service surface (9) → `retrofit-2026-05-24-b-svc-compute-profile-org`
    task-3 (`profile-actions`), which already names every one of these methods.
  - `ContactMatchingService` matchers (4) → `retrofit-2026-05-24-contacts-actions` task-3
    (`contacts-actions` REQ "ContactMatchingService MUST match contacts ...").
  - `CalendarEventService` CalDAV link/CRUD (4) → `retrofit-2026-05-24-calendar-integration`
    task-1 (`calendar-integration` REQ-003 "REST link/unlink flow for CalDAV VEVENTs").
  - `AuthorizationService` (6) → `auth-system` (cross-cap reference tasks in this change's
    `tasks.md`; the `auth-system` spec already pins every method).
  - `ManifestService::getEnrichedManifest` (1) → `manifest-user-context` (matches the
    file-level `@spec`).
- **80 excluded** as facade plumbing (`@spec exclude <reason>`), reasons name the owning
  collaborator/capability:
  - `ApplicationService` (6) — CRUD delegation to `ApplicationMapper`.
  - `ObjectServiceMapperAdapter` (7) — mapper-shaped facade over `ObjectService`.
  - `IndexService` (23) — search-index facade routing to handlers/backend (`search-index`).
  - the six ADR-019 Tier-2 integration link services — `CospendLinkService` (6),
    `FormLinkService` (8), `MapLinkService` (5), `PhotoLinkService` (5),
    `TimeTrackerLinkService` (5), `ShareLinkService` (6) — owned by their `integration-*` caps.
  - `ConfigurationService` (3) — app-presence probe + delegation to Git handlers.
  - `TmloService` (3) — MDTO XML / schema-defaults, owned by `tmlo-export`/`tmlo-auto-populate`
    (explicitly DROPped from the tmlo retrofit as "not foundation behaviour").
  - `RetentionService::validateNotImmutable` (1) — archival-status immutability guard,
    sibling of the already-annotated archival methods (`archival-destruction-workflow`).
  - `ChatService::testChat` (1) — simplified facade stub.
  - `AuthorizationAuditService::logSchemaAuthorizationChange` (1) — structured audit logging,
    sibling of `logRegisterAuthorizationChange` (`rbac-scopes` REQ-002), no distinct contract.

## New REQs

4 (cap ≤5): 2 in `schema-property-exploration`, 2 in `file-risk-classification`.

Source batch: `/tmp/or-scan/bw2-svc-flat-3.json`.
See [retrofit playbook](../../../.github/docs/claude/retrofit.md).

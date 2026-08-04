# Tasks

Reverse-spec + annotation pass for batch `bw2-svc-flat-3` (111 methods / 21 service
facades). Tasks 1–4 mint the 4 new REQs (2 capabilities) and annotate the methods that
implement them. Task 5 is a cross-capability annotation reference for methods whose
contract already lives in `auth-system`. No code behaviour changes — only `@spec`
docblock tags (annotations + excludes) are added.

## Reverse-spec (new REQs)

- [x] task-1: schema-property-exploration#Requirement:SchemaService MUST discover undeclared properties by analysing a schema's stored objects — annotate `SchemaService::exploreSchemaProperties` (object-payload analysis, per-property usage counts/percentages, `@self` exclusion, missing-schema exception, discovery report shape).
- [x] task-2: schema-property-exploration#Requirement:SchemaService MUST apply confirmed exploration suggestions back onto a schema — annotate `SchemaService::updateSchemaFromExploration` (merge property updates onto existing properties, regenerate facets, persist via `SchemaMapper::update`, log+re-throw on failure).
- [x] task-3: file-risk-classification#Requirement:RiskLevelService MUST classify a file's PII risk from its detected entities — annotate `RiskLevelService::computeRiskLevel` (entity-type→tier map, highest-tier-wins, count-threshold escalation capped at `very_high`).
- [x] task-4: file-risk-classification#Requirement:RiskLevelService MUST persist and expose risk levels through Nextcloud files metadata — annotate `RiskLevelService::updateRiskLevel`, `getRiskLevel`, `initMetadataKey`, `getAllRiskLevels` (IFilesMetadata persistence, fail-soft read/write, repair-step registration, label map).

## Cross-capability annotation (no new REQs)

- [x] task-5: auth-system — `AuthorizationService` request-authorization surface (`authorizeJwt`, `authorizeBasic`, `authorizeOAuth`, `authorizeApiKey`, `validatePayload`, `corsAfterController`) maps to the existing `auth-system` capability requirements "The system MUST support multiple authentication methods with unified identity resolution" and "CORS policy MUST be enforced per Consumer and prevent CSRF", which already name every one of these methods in their scenarios (retroactive annotation).

## Annotated to existing capability tasks (no new REQs, documented in proposal.md)

- [x] task-6: `UserService` self-service methods (`updateUserProperties`, `getCustomNameFields`, `setCustomNameFields`, `deleteAvatar`, `setNotificationPreferences`, `listApiTokens`, `revokeApiToken`, `getDeactivationStatus`, `cancelDeactivation`) tagged at `retrofit-2026-05-24-b-svc-compute-profile-org/tasks.md#task-3` (`profile-actions`), matching their already-annotated siblings.
- [x] task-7: `ContactMatchingService` matchers (`matchByEmail`, `matchByName`, `matchByOrganization`, `matchContact`) tagged at `retrofit-2026-05-24-contacts-actions/tasks.md#task-3` (`contacts-actions`), matching the already-annotated `getRelatedObjectCounts` sibling.
- [x] task-8: `CalendarEventService` CalDAV link/CRUD (`createEvent`, `getEventsForObject`, `linkEvent`, `unlinkEvent`) tagged at `retrofit-2026-05-24-calendar-integration/tasks.md#task-1` (`calendar-integration` REQ-003).
- [x] task-9: `ManifestService::getEnrichedManifest` tagged at `manifest-user-context/tasks.md#task-1`, matching the file-level `@spec`.

## Excluded as facade plumbing (documented in proposal.md, NOT counted as REQs)

- [x] task-10: `@spec exclude` applied to the 80 facade-plumbing methods across `ApplicationService` (6), `ObjectServiceMapperAdapter` (7), `IndexService` (23), `ConfigurationService` (3), `ChatService::testChat` (1), `TmloService` (3), `RetentionService::validateNotImmutable` (1), `AuthorizationAuditService::logSchemaAuthorizationChange` (1), and the six ADR-019 Tier-2 integration link services (`Cospend`/`Form`/`Map`/`Photo`/`TimeTracker`/`Share`). Each exclude reason names the owning collaborator/capability. See proposal.md "Counts" for the full enumeration.

## Validation

- [x] `php -l` clean on all 21 touched files.
- [x] `openspec validate retrofit-2026-05-25-bw2-svc-flat-3 --strict` passes.

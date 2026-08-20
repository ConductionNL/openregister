# Tasks — reverse-spec service-facade bundle (chunk 1)

Each task documents the observed behavior of a cluster of service methods and maps to a
new requirement in the matching capability spec delta, or records an exclusion bucket for
boilerplate. Method `@spec` / `@spec exclude` annotations reference these task anchors or
the relevant capability spec.

## generic-integrations

- [x] task-1 Tier-2 dedicated-mapper link-service pattern for optional peer apps.
  Documents `AnalyticsLinkService` (`linkReport`, `createAndLinkReport`, `unlinkReport`,
  `getLinkedReports`, `getAvailableReports`), `CalendarLinkService` (`linkEvent`,
  `createAndLinkEvent`, `unlinkEvent`, `getLinkedEvents`, `getAvailableCalendars`,
  `getEventsForCalendar`), `DeckLinkService` (`linkCard`, `createAndLinkCard`,
  `unlinkCard`, `getLinkedCards`, `getAvailableBoards`, `getStacksForBoard`),
  `EmailLinkService` (`linkEmail`, `unlinkEmail`, `getLinkedEmails`,
  `getAvailableAccounts`, `getMailboxesForAccount`, `getMessagesForMailbox`,
  `isMailAvailable`), `FlowLinkService` (`linkOperation`, `unlinkOperation`,
  `getLinkedOperations`, `getAvailableOperations`, `isCurrentUserAdmin`), and
  `PollLinkService` (`linkPoll`, `createAndLinkPoll`, `unlinkPoll`, `getLinkedPolls`,
  `getAvailablePolls`).

## datetime-input-handling

- [x] task-2 Canonical datetime normalizer entry points
  (`DateTimeNormalizer::normalize`, `formatForDatabase`, `formatForIso8601`).

## webhook-payload-mapping

- [x] task-3 Mapping transformation engine semantics
  (`MappingService::executeMapping`).

## files-sidebar-tabs

- [x] task-4 Backend file reverse-lookup and document extraction status
  (`FileSidebarService::getObjectsForFile`, `getExtractionStatus`).

## zoeken-filteren

- [x] task-5 Search-trail analytics statistics surface
  (`SearchTrailService::getPopularSearchTerms`, `getRegisterSchemaStatistics`,
  `getSearchActivity`, `getSearchStatistics`, `getUserAgentStatistics`).

## Annotated against existing requirements (no new REQ)

- [x] task-6 `ConditionMatcher::objectMatchesConditions` annotated against
  `rbac-scopes` Conditional Match Evaluation.
- [x] task-7 `WorkflowEngineRegistry` CRUD + discovery + adapter-resolution
  (`createEngine`, `updateEngine`, `deleteEngine`, `discoverEngines`,
  `resolveAdapterById`) annotated against `workflow-engine-abstraction` Engine
  Registration and Discovery + credential-management requirements.
- [x] task-8 `McpDiscoveryService::getCapabilityIds` annotated against `mcp-discovery`
  Capability Coverage.
- [x] task-9 `SearchTrailService::createSearchTrail` annotated against `zoeken-filteren`
  Saved-searches-and-search-trails requirement.

## Excluded boilerplate (`@spec exclude`)

- [x] task-10 `ObjectService` (40 methods) — facade delegations, context
  getters/setters, deprecated throwing stubs.
- [x] task-11 `SettingsService` (5 methods) — pure utility helpers.
- [x] task-12 `MigrationService` (4 methods) — legacy facade, blob storage retired.
- [x] task-13 `MappingService` (4 methods) — cache wrappers and pure helpers.
- [x] task-14 `EmailService` (2 methods) — legacy link-table delete plumbing.
- [x] task-15 `VectorizationService` (2 methods) — one-line facade delegations.
- [x] task-16 `UploadService` (1 method) — source-dispatch helper.
- [x] task-17 `SearchTrailService::getSearchTrail` — thin find + name-enrich.

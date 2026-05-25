# Tasks

Annotation-only retrofit (Bucket 1 style) for the
`lib/Service/Integration/` cluster. Each task below points at an
EXISTING change/spec requirement; the matching method docblock carries a
`@spec ...` annotation. Boilerplate methods carry `@spec exclude
<reason>` instead. No new capabilities are minted and no spec deltas are
written, with one exception: a single new REQ
(the new `generic-integrations` "canonical pagination envelope" requirement) retroactively specifies the
`PaginatedResult` envelope-normalisation behavior, which is genuinely
uncovered in the merged specs. Every other behavior maps to a change
that already owns it (`pluggable-integration-registry`, the per-leaf
`integration-*` changes, or `retrofit-2026-05-24-activity-provider`).

All tasks are `[x]` because the code already exists.

## Annotated — pluggable-integration-registry (contract + registry + helpers)

- [x] task-1: pluggable-integration-registry#task-3 — `IntegrationRegistry::addProvider` (duplicate-id first-wins + external-source rejection, AD-13/AD-4), `withProviders` (test-seam replace), `list`, `listIds`, `get`, `getEnabled` (stage-1 enabled filter) (retroactive annotation)
- [x] task-2: pluggable-integration-registry#task-4 — `ExternalIntegrationRouter::call` (dispatch through OpenConnector + classify failure into openconnector-down / source-missing / upstream-down, AD-23) and `probe` (cheap admin-UI/OCS health check) (retroactive annotation)
- [x] task-3: NEW REQ generic-integrations "canonical pagination envelope" — `PaginatedResult::fromMixed` (permissive normalisation of flat-list / `{items|results,total}` into the canonical `{items,total,nextCursor}` envelope) + `toArray` (serialised mirror with `results` alias) (reverse-spec of existing behavior)
- [x] task-4: pluggable-integration-registry#task-10 — `PropertyReferenceTypeValidator::validate` / `validateAll` (schema `referenceType` marker must match a registered integration id, AD-18) (retroactive annotation)
- [x] task-5: pluggable-integration-registry#task-6 — `QueryTimeContract::buildHttpBody` (translate query-time mutation `NotImplementedException` into the HTTP 501 error envelope, AD-22) (retroactive annotation)

## Annotated — builtin providers (pluggable-integration-registry tasks 12-16)

- [x] task-6: pluggable-integration-registry#task-12 — `FilesProvider::list` (FileService magic-column folder enumeration via resolved ObjectEntity) (retroactive annotation)
- [x] task-7: pluggable-integration-registry#task-13 — `NotesProvider` `list` / `create` / `update` / `delete` (NoteService link-table CRUD delegation) (retroactive annotation)
- [x] task-8: pluggable-integration-registry#task-14 — `TasksProvider` `list` / `create` / `update` / `delete` (TaskService CalDAV link-table CRUD via composite `{calendarId}/{taskUri}` ids) (retroactive annotation)
- [x] task-9: pluggable-integration-registry#task-15 — `TagsProvider::list` (NC system-tag link-table read; `create` is a NotImplemented stub — see exclude task-29) (retroactive annotation)
- [x] task-10: pluggable-integration-registry#task-16 — `AuditTrailProvider::list` (AuditTrailMapper query-time read; read-only by design) (retroactive annotation)

## Annotated — leaf providers (per-leaf integration-* changes)

- [x] task-11: integration-xwiki — `XwikiProvider` `list` / `get` / `create` / `update` / `delete` / `authRequirements` (external, OpenConnector-routed XWiki CRUD + link-table enrichment) (retroactive annotation)
- [x] task-12: integration-openproject — `OpenProjectProvider` `list` / `get` / `create` / `update` / `delete` / `authRequirements` (external work-package CRUD) (retroactive annotation)
- [x] task-13: integration-deck — `DeckProvider` `list` / `create` / `delete` (Deck card link-table CRUD) (retroactive annotation)
- [x] task-14: integration-email — `EmailProvider` `list` / `create` / `delete` (Mail message link CRUD) (retroactive annotation)
- [x] task-15: integration-shares — `SharesProvider` `list` / `delete` (read+revoke-only: `IManager` folder-walk aggregation + revoke via `IManager::deleteShare`) (retroactive annotation)
- [x] task-16: integration-analytics — `AnalyticsProvider::list` (link-table read + legacy `[or:{uuid}]` marker fallback) (retroactive annotation)
- [x] task-17: integration-bookmarks — `BookmarksProvider::list` (query-time read) (retroactive annotation)
- [x] task-18: integration-collectives — `CollectivesProvider::list` (query-time read) (retroactive annotation)
- [x] task-19: integration-cospend — `CospendProvider::list` (query-time read) (retroactive annotation)
- [x] task-20: integration-flow — `FlowProvider::list` (query-time read) (retroactive annotation)
- [x] task-21: integration-forms — `FormsProvider::list` (query-time read) (retroactive annotation)
- [x] task-22: integration-maps — `MapsProvider::list` (query-time read) (retroactive annotation)
- [x] task-23: integration-photos — `PhotosProvider::list` (query-time read) (retroactive annotation)
- [x] task-24: integration-polls — `PollsProvider::list` (query-time read) (retroactive annotation)
- [x] task-25: integration-talk — `TalkProvider::list` (query-time conversation read) (retroactive annotation)
- [x] task-26: integration-time-tracker — `TimeProvider::list` (query-time read) (retroactive annotation)

## Excluded as boilerplate (`@spec exclude` on each method)

- [x] task-27: exclude bucket — `IntegrationProvider` interface method declarations (`authRequirements`, `create`, `delete`, `get`, `health`, `list`, `requiresPermission`, `update`) — pure contract shape, behavior lives in the implementing providers
- [x] task-28: exclude bucket — `AbstractIntegrationProvider` defaults (`authRequirements`, `requiresPermission` trivial-default returns; `get` / `create` / `update` / `delete` / `health` default `NotImplementedException`/static-descriptor stubs owned by the registry contract, not per-method behavior)
- [x] task-29: exclude bucket — NotImplemented write stubs + trivial predicates/getters: `FilesProvider::create` and `TagsProvider::create` (write paths deferred to the controller refactor — both throw `NotImplementedException`), `SharesProvider::isEnabled` (one-line `return true`), `SharesProvider` anonymous PSR-11 adapter `get` / `has` (`\OCP\Server::get` shim)
- [x] task-30: exclude bucket — leaf `health()` descriptors that only echo a static enabled/disabled status (`AnalyticsProvider`, `BookmarksProvider`, `CollectivesProvider`, `ContactsProvider`, `CospendProvider`, `DeckProvider`, `EmailProvider`, `FlowProvider`, `FormsProvider`, `MapsProvider`, `OpenProjectProvider`, `PhotosProvider`, `PollsProvider`, `SharesProvider`, `TalkProvider`, `TimeProvider`, `XwikiProvider`)
- [x] task-31: exclude bucket — shared helper with no per-method contract: `MarkerLookupTrait::findByMarker` (defensive LIKE-scan utility shared across leaf `list()` impls)

# Design: recently-opened-means-opened

## D1 · A lookup is a cause, not a missing row

Three ways to stop a lookup from counting as opened were on the table:

| Option | What the audit trail keeps | Verdict |
|---|---|---|
| Pass `_audit: false` at the site | nothing | Rejected. It removes rows the audit trail records today, and several of these reads (credentials, DSAR cases, guards) are exactly what an auditor wants to see. |
| A new audit action, such as `read.lookup` | the row, under another action | Rejected. Every reader that filters on `action = 'read'` (the audit UI, exports, reports) would silently lose these reads. |
| The existing `cause` column, with a new value `lookup` | the row, action `read`, cause `lookup` | Chosen. |

`cause` already answers "why was this row written", from a closed, server-derived vocabulary (REQ-RCN-001), and it is stamped in the one builder every audit row goes through (`AuditTrailMapper::buildAuditTrail()`). A lookup is one more answer to that question. The row, its action, its hash chain and its retention are unchanged.

`WriteCause::asLookup()` opens the `lookup` frame only when the acting cause is `person`. A read inside an import, a rule, a migration or a scheduled job already says why it happened; overwriting that with `lookup` would lose its run. The read history excludes those causes anyway.

The read history keeps rows whose cause is `person` or empty. Empty is a row written before causes existed: before this change every such `read` row counted, and dropping them would empty every history on upgrade.

The new filter is not in the index `or_audit_user_read_hist (user, action, object_uuid, created)` that #4514 adds. The database reads `cause` from the row for each candidate. For an interactive user that range is small; changing #4514's migration after it may already have run on an instance would leave two shapes of the same index, so the index stays as it is.

## D2 · The call sites

Every `ObjectService::find()` call in `lib/` was read. Only `GetObject::find()` writes the audit `read` row, and it is reached through `ObjectService::find()` and `GraphQLResolver::resolveSingle()`.

**Unchanged, the person opens the object** (the response is the object itself):

- `ObjectsController::show()`, the detail read
- `GraphQLResolver::resolveSingle()`, a single-object GraphQL query
- `TmloController::exportSingle()`, one object exported as MDTO
- `ObjectShareLinkController::show()`, a share link opened
- `CaseTokenService::resolve()`, a case token opened
- `ObjectServiceMapperAdapter::find()`, the mapper contract leaf apps use for their own reads; whether those are opens is the leaf app's call

**Unchanged, already `_audit: false`** (no row, so no history): `FlowNodeRunController::resolveAuthorizedSubject()`, `PartyService::find()`, `TaskSubjectAccessGuard::assertOne()`, `AccessLinkMintGuard::mayReadObject()`, `AccessLinkReader::subjectObject()`, `TimelineEntrySearchService::objectFor()`, `ReferenceService::resolve()`, `AppointmentAttendeeService::mayRead()`, `ExternalIntegrationRouter::findSource()`, `CaseAnchorReader::read()` and `::mayRead()`.

**Not an `ObjectService` read** (no audit row): `ContentReportController::create()` (MagicMapper), `PortalPartyResolver::resolveFromObject()` (object store interface).

**Lookup** (wrapped in `WriteCause::asLookup()`, still audited):

- Sub-resources and pre-flights of a page the person already opened: `ObjectsController::referenceOptions()`, `canDelete()`, `logs()`, `presenceObject()`, `downloadFiles()`, `referencedBy()`, `geoFeatures()`; `ObjectReadAccess::readable()` (relations tab); `ObjectFileAccess::readable()` and `::mayRead()`; `FilesController::ensureObjectAccess()`.
- Guards before an action: `RegistrySubscriptionController::resolveObjectAndGuardUpdate()`, `ObjectActionsController::invoke()`, `TransitionEngine::findVisibleSubject()` and `::applyProviderTransition()`, `HandoffService::listAvailability()` and `::execute()`, `CredentialController::ensureManageable()`, `CredentialBrokerService::loadAdmittedCredential()`, `OAuth2ConnectService::existingCredential()`, `OAuth2ConnectionRepository::findManageable()`, `OAuth2InstanceClient::pinnedClient()`, `OAuth2RefreshService::writeMetadata()`, `ObjectServiceMapperAdapter::updateFromArray()`, `TaskFormCompletion::save()`, `AppointmentAttendeeService::recordResponse()` and `::responsesFor()`, `TasksProvider::create()`.
- Resolving one object for another: `ReferenceResolver::resolveRelatedObject()`, `SourceRecordResolver::resolveReference()`, `NotificationTemplating::resolveRelationDisplayName()`, `AnnotationNotificationDispatcher::resolveRelationDeeplink()`, `ObjectPreviewFormatter::buildReference()`, `VocabularyController::resolveSchemeUuid()`, `VocabularyImportService::applyRelationFieldsToDraft()`, `FlowLocator::resolveSubject()`, `DeferredEntryObjectResolver::resolve()`, `ScheduleReconciler::resolveProductionManifest()`.
- Data-management operations: `SurvivorshipController::sources()` and `::override()`, `DuplicateController::fingerprintOf()`, `MasterRecomputeService::recompute()`, `MergeService` (all six reads), `SchemaMigrationService::rollback()`, `HarvestPipelineService::localChangedSinceSync()`, `TransferRecordService::loadTransferList()`, `CaseObjectAccessor::load()`, `DsarDpiaDetectionJob::flagGroup()`.
- Machine reads for another party: `FederationController::object()` (a remote instance fetching), `ObjectsTool::getObject()`, `::updateObject()`, `::deleteObject()`, `ObjectsToolProvider::getObject()`, `SchemaDerivedToolProvider::get()`, `McpResourcesService::readObjects()`. An agent reading fifty objects to answer one question did not open fifty objects.

## D3 · Cross-table searches honour the lens like one schema

`SearchQueryHandler` puts the history on `_ids` and `_recentViews` for every path. Three cross-table paths read them:

| Path | Before | After |
|---|---|---|
| UNION (`runUnionBatch`) | `_ids` ignored, so the whole register came back | `_ids` applied per arm as `(_uuid IN (…) OR _slug IN (…))`, quoted, like `applyIdFilters()` |
| UNION and sequential, ordering | score or uuid order | history order, unless `_order` is given |
| global `_ids` (`getGlobalSearchResult`) | storage order | history order, unless `_order` is given |

On every path each returned object carries `@self.viewedAt`.

When the lens orders the page, the cross-table search fetches the restricted set without `_offset` and `_limit`, sorts it by the history and then takes the page. The set is bounded by `_ids`, which is at most the 100 objects of the history, so fetching it whole is cheap; paging before sorting would give a page from the wrong place in the history. The single-table path keeps doing this in SQL.

The precedence is the single-table path's (`MagicSearchHandler::applyResultOrder()`): an explicit `_order` wins, then the history, then the default. A search term does not displace the history, on either path.

The ordering lives in one small class, `RecentLensOrder`, used by both cross-table paths, so the rule is written once.

`ObjectsController::crossTableSearch()` surfaces `@self.lenses.recent` like the single-schema list.

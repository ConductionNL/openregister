# Tasks

## Settings (settings-management)

- [x] task-1: settings-management — sliced typed per-domain get/update persisted as JSON in IAppConfig (ConfigurationSettingsHandler get/updateRbacSettingsOnly, get/updateOrganisationSettingsOnly, get/updateMultitenancySettingsOnly, get/updateLLMSettingsOnly, get/updateFileSettingsOnly, get/updateN8nSettingsOnly, updatePublishingOptions; FileSettingsHandler get/updateFileSettingsOnly) (retroactive annotation)
- [x] task-2: settings-management — environment / version introspection (ConfigurationSettingsHandler::getVersionInfoOnly) (retroactive annotation)
- [x] task-3: settings-management — multitenancy / organisation default reads and writes through the settings layer (ConfigurationSettingsHandler::isMultiTenancyEnabled, getTenantId, getDefaultOrganisationUuid, setDefaultOrganisationUuid) (retroactive annotation)
- [x] task-4: settings-management — search-backend + SOLR sliced config, bootstrap-safe search-backend read, dashboard stats, facet config, and index warmup (SolrSettingsHandler::getSolrSettingsOnly, getSearchBackendConfig, updateSearchBackendConfig, getSolrFacetConfiguration, updateSolrFacetConfiguration, getSolrDashboardStats, warmupSolrIndex) (retroactive annotation)

## Notification (notificatie-engine)

- [x] task-5: notificatie-engine — schema-declared notification-rule dispatch, annotation shape validation, and persistent-webhook install (AnnotationNotificationDispatcher::dispatch, NotificationAnnotationValidator::validate, NotificationsAnnotationInstaller::handle, NotificationsAnnotationInstaller::installSchema) (retroactive annotation)
- [x] task-6: notificatie-engine — per-recipient digest batching primitive (NotificationDigest::enqueue, flush, pendingCount, recipientCount, totalPending) (retroactive annotation)
- [x] task-7: notificatie-engine — per-user-per-notification read/unread tracking (NotificationReadState::markRead, markUnread, readCount) (retroactive annotation)
- [x] task-8: notificatie-engine — per-(rule,recipient) token-bucket rate limiting (RateLimiter::tryConsume) (retroactive annotation)
- [x] task-9: notificatie-engine — per-(rule,recipient) coalescing / grouping to reduce noise (NotificationCoalescer::shouldDispatch, inspect) (retroactive annotation)
- [x] task-10: notificatie-engine — VNG Notificaties API envelope mapping (VngNotificatiesEnvelope::buildEnvelope, mapAction) (retroactive annotation)
- [x] task-11: notificatie-engine — pluggable dynamic recipient resolution contract (RecipientResolverInterface::resolve) (retroactive annotation)

## Object lifecycle

- [x] task-12: object-lifecycle#REQ-011 — object locking state contract: lock presence and expiry-aware lock-info reads (LockHandler::isLocked, getLockInfo) (NEW requirement)
- [x] task-13: object-lifecycle#REQ-012 — object merge / deduplication: same-register/schema property, file, relation, and reference transfer with source soft-delete (MergeHandler::mergeObjects) (NEW requirement)

## Reference-existence-validation

- [x] task-14: reference-existence-validation — streaming bulk-upsert primitive engaging the request-scoped reference-existence cache, plus the cache reset for long-running CLI processes (SaveObject::saveObjectsStreaming, SaveObject::clearReferenceValidationCache) (retroactive annotation)

## Files-render-extension

- [x] task-15: files-render-extension — lightweight `@self.files` file-ID list attached to un-rendered list rows via a single batched lookup (RenderObject::attachLightweightFilesToRows) (retroactive annotation)

## Dropped / excluded (boilerplate)

- EXCLUDE: `BatchOperationStatus` — pure in-memory value object whose `start`, `complete`, `recordCreated`, `recordUpdated`, `recordUnchanged`, `recordFailed`, `recordReferenceCacheHit`, `recordReferenceCacheMiss`, `getProcessedCount`, `getDurationSeconds`, and `toArray` are trivial counter/accessor/serialization boilerplate. The class concept (batch outcome aggregation for the streaming upsert) is already anchored to `reference-existence-validation`; per-method annotations would be noise. Tagged `@spec exclude`.

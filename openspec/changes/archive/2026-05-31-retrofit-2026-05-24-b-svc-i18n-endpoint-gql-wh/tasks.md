# Tasks

## i18n-and-translation-pipeline (→ register-i18n)

- [x] task-1: register-i18n#REQ-TR-SIDECAR — TranslationProjectionService::project projects translatable JSONB into the sidecar (retroactive annotation)
- [x] task-2: register-i18n#REQ-TR-SIDECAR — TranslationProjectionService::purge drops all sidecar rows for a deleted object (retroactive annotation)
- [x] task-3: register-i18n#REQ-TR-WORKFLOW — TranslationStatusService::setStatus promotes a slot's workflow status (retroactive annotation)
- [x] task-4: register-i18n#REQ-TR-WORKFLOW — TranslationStatusService::completenessForObject computes per-language completeness (retroactive annotation)
- [x] task-5: register-i18n#REQ-TR-WORKFLOW — TranslationStatusService::search queries the sidecar by query/language/status/object (retroactive annotation)
- [x] task-6: register-i18n#REQ-TR-WORKFLOW — TranslationStatusService::findObjectsMissingLanguage returns objects lacking a language (retroactive annotation)
- [x] task-7: register-i18n#REQ-TR-MACHINE — BulkTranslationService::translateObject fills empty target-language slots via a provider (retroactive annotation)
- [x] task-8: register-i18n#REQ-TR-MACHINE — IdentityTranslationProvider::translate passthrough implementation (retroactive annotation)
- [x] task-9: register-i18n#REQ-TR-MACHINE — IdentityTranslationProvider::getIdentifier returns the provider slug (retroactive annotation)
- [x] task-10: register-i18n#REQ-TR-MACHINE — TranslationProviderInterface::translate strategy contract (retroactive annotation)
- [x] task-11: register-i18n#REQ-TR-MACHINE — TranslationProviderInterface::getIdentifier strategy contract (retroactive annotation)
- [x] task-12: register-i18n#REQ-TR-CSV — TranslationCsvCodec::flattenForCsv flattens language-keyed values to field_lang columns (retroactive annotation)
- [x] task-13: register-i18n#REQ-TR-CSV — TranslationCsvCodec::unflattenFromCsv reconstructs the nested shape from flat columns (retroactive annotation)

## endpoint-service-execution-pipeline (→ object-interactions)

- [x] task-14: object-interactions#REQ-EP-DISPATCH — EndpointService::executeEndpoint dispatches by target type (retroactive annotation)
- [x] task-15: object-interactions#REQ-EP-DISPATCH — EndpointService::testEndpoint executes an endpoint with test data and logs the result (retroactive annotation)
- [x] task-16: object-interactions#REQ-EP-DISPATCH — EndpointService::executeAgentEndpoint runs an AI agent endpoint (retroactive annotation)
- [x] task-17: object-interactions#REQ-EP-ACCESS — EndpointService::canExecuteEndpoint enforces group-based access (retroactive annotation)
- [x] task-18: object-interactions#REQ-EP-ACCESS — EndpointService::logEndpointCall persists an EndpointLog with TTL (retroactive annotation)

## graphql-resolver-extras (→ graphql-api)

- [x] task-19: graphql-api#REQ-GQL-EXTRAS — GraphQLResolver::resolveGroupBy dispatches aggregations through the resolver (retroactive annotation)
- [x] task-20: graphql-api#REQ-GQL-EXTRAS — GraphQLResolver::flushRelationBuffer batch-loads buffered relation UUIDs (retroactive annotation)
- [x] task-21: graphql-api#REQ-GQL-EXTRAS — GraphQLResolver::encodeCursor encodes opaque pagination cursors (retroactive annotation)

## webhook-cloudevent-formatter-extends (→ webhook-payload-mapping)

- [x] task-22: webhook-payload-mapping#REQ-WH-TRANSPORT — WebhookService::initializeHttpClient configures the Guzzle delivery client (retroactive annotation)
- [x] task-23: webhook-payload-mapping#REQ-WH-TRANSPORT — WebhookService::getNestedValue resolves dot-notation filter keys (retroactive annotation)
- [x] task-24: webhook-payload-mapping#REQ-WH-TRANSPORT — WebhookService::getShortEventName derives the short event name (retroactive annotation)
- [x] task-25: webhook-payload-mapping#REQ-WH-TRANSPORT — WebhookService::eventTypeToEventClass maps dot-notation event types to event classes (retroactive annotation)
- [x] task-26: webhook-payload-mapping#REQ-WH-TRANSPORT — CloudEventFormatter::getContentTypeHeader derives datacontenttype from the request (retroactive annotation)

## metrics-and-realtime-observability-extends (→ production-observability)

- [x] task-27: production-observability#REQ-OBS-AGG — MetricsService::getFilesProcessedPerDay aggregates per-day file processing counts (retroactive annotation)
- [x] task-28: production-observability#REQ-OBS-AGG — MetricsService::getEmbeddingStats reports embedding success rate and cost (retroactive annotation)
- [x] task-29: production-observability#REQ-OBS-AGG — MetricsService::getSearchLatencyStats reports per-type search latency (retroactive annotation)
- [x] task-30: production-observability#REQ-OBS-AGG — MetricsService::getStorageGrowth reports vector storage growth (retroactive annotation)
- [x] task-31: production-observability#REQ-OBS-AGG — MetricsService::getDashboardMetrics composes the dashboard metric bundle (retroactive annotation)
- [x] task-32: production-observability#REQ-OBS-AGG — MetricsService::calculateSuccessRate computes a success percentage (retroactive annotation)
- [x] task-33: production-observability#REQ-OBS-AGG — MetricsService::roundAverageMs coerces and rounds DB latency values (retroactive annotation)
- [x] task-34: production-observability#REQ-OBS-AGG — MetricsService::calculateAverageVectorsPerDay averages daily growth (retroactive annotation)
- [x] task-35: production-observability#REQ-OBS-REALTIME — RealtimeService::record emits a CloudEvent-shaped change record (retroactive annotation)

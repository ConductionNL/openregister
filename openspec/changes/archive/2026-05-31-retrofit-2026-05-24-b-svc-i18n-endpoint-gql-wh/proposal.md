# Retrofit — service bundle: i18n / endpoint / graphql / webhook / observability

Bundled reverse-spec of five service sub-clusters under the `service` parent cluster. All five EXTEND an existing capability with observed behavior that the current spec does not yet cover. Code already exists — this change retroactively specifies it.

## Why

The coverage scan flagged 52 implemented methods across 11 service files that have no spec annotation. Each maps onto an existing capability (`register-i18n`, `object-interactions`, `graphql-api`, `webhook-payload-mapping`, `production-observability`) but documents behavior the current spec text omits — the translation sidecar pipeline, the dynamic endpoint dispatcher, GraphQL aggregation/cursor/DataLoader helpers, webhook transport internals, and the metrics aggregation/realtime-emission helpers. Bringing these under the annotation convention (ADR-008) keeps the spec-to-code map complete and makes the behavior reviewable.

## Affected code units

### i18n-and-translation-pipeline → `register-i18n`
- lib/Service/TranslationProjectionService.php::project
- lib/Service/TranslationProjectionService.php::purge
- lib/Service/TranslationStatusService.php::setStatus
- lib/Service/TranslationStatusService.php::completenessForObject
- lib/Service/TranslationStatusService.php::search
- lib/Service/TranslationStatusService.php::findObjectsMissingLanguage
- lib/Service/BulkTranslationService.php::translateObject
- lib/Service/Translation/IdentityTranslationProvider.php::translate
- lib/Service/Translation/IdentityTranslationProvider.php::getIdentifier
- lib/Service/Translation/TranslationProviderInterface.php::translate
- lib/Service/Translation/TranslationProviderInterface.php::getIdentifier
- lib/Service/Translation/TranslationCsvCodec.php::flattenForCsv
- lib/Service/Translation/TranslationCsvCodec.php::unflattenFromCsv

### endpoint-service-execution-pipeline → `object-interactions`
- lib/Service/EndpointService.php::testEndpoint
- lib/Service/EndpointService.php::executeEndpoint
- lib/Service/EndpointService.php::canExecuteEndpoint
- lib/Service/EndpointService.php::executeAgentEndpoint
- lib/Service/EndpointService.php::logEndpointCall

### graphql-resolver-extras → `graphql-api`
- lib/Service/GraphQL/GraphQLResolver.php::resolveGroupBy
- lib/Service/GraphQL/GraphQLResolver.php::flushRelationBuffer
- lib/Service/GraphQL/GraphQLResolver.php::encodeCursor

### webhook-cloudevent-formatter-extends → `webhook-payload-mapping`
- lib/Service/WebhookService.php::initializeHttpClient
- lib/Service/WebhookService.php::getNestedValue
- lib/Service/WebhookService.php::getShortEventName
- lib/Service/WebhookService.php::eventTypeToEventClass
- lib/Service/Webhook/CloudEventFormatter.php::getContentTypeHeader

### metrics-and-realtime-observability-extends → `production-observability`
- lib/Service/MetricsService.php::getFilesProcessedPerDay
- lib/Service/MetricsService.php::getEmbeddingStats
- lib/Service/MetricsService.php::getSearchLatencyStats
- lib/Service/MetricsService.php::getStorageGrowth
- lib/Service/MetricsService.php::getDashboardMetrics
- lib/Service/MetricsService.php::calculateSuccessRate
- lib/Service/MetricsService.php::roundAverageMs
- lib/Service/MetricsService.php::calculateAverageVectorsPerDay
- lib/Service/RealtimeService.php::record

## Approach

- For each method: describe observed inputs, outputs, pre/postconditions, and failure modes from the source.
- Draft new `### Requirement:` entries (ADDED) onto the five target specs where behavior is genuinely uncovered; bias toward extend rather than new capabilities.
- Annotate each documented method with a `@spec` pointer to the matching task in `tasks.md`.
- DROP scanner false-positives: trivial placeholder dispatchers (`executeViewEndpoint`, `executeRegisterEndpoint`, `executeSchemaEndpoint`, `executeWebhookEndpoint`) that return a hardcoded placeholder response, and methods already annotated by prior retrofit changes (e.g. `WebhookService::buildPayload`, `generateSignature`, `interceptRequest`, the GraphQL resolve* CRUD methods, the CloudEventFormatter `formatAsCloudEvent` family).

Source: `/tmp/or-scan/bundle-svc-i18n-endpoint-gql-wh.json`. See [retrofit playbook](../../../.github/docs/claude/retrofit.md).

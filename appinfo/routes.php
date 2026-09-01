<?php

return [
    'resources' => [
        'Registers' => ['url' => 'api/registers'],
        'Schemas' => ['url' => 'api/schemas'],
        'Sources' => ['url' => 'api/sources'],
        'Configurations' => ['url' => 'api/configurations'],
        'Applications' => ['url' => 'api/applications'],
        'Agents' => ['url' => 'api/agents'],
        'Endpoints' => ['url' => 'api/endpoints'],
        'Mappings' => ['url' => 'api/mappings'],
        'Consumers' => ['url' => 'api/consumers'],
    ],
    'routes' => [
        // Federation (cross-instance OCM sharing) — token-scoped serving endpoints.
        // #[PublicPage]: the caller is a remote instance authenticated by the
        // bearer share token in the URL, not a local session.
        // First-time setup wizard (ADR-042) - the standard CnSetupWizard contract.
        ['name' => 'setup#status',    'url' => '/api/setup/status',            'verb' => 'GET'],
        ['name' => 'setup#runAction', 'url' => '/api/setup/action/{actionId}', 'verb' => 'POST', 'requirements' => ['actionId' => '[a-z0-9\\-]+']],
        ['name' => 'federation#objects', 'url' => '/api/federation/{shareToken}/objects',      'verb' => 'GET', 'requirements' => ['shareToken' => '[^/]+']],
        ['name' => 'federation#object',  'url' => '/api/federation/{shareToken}/objects/{id}', 'verb' => 'GET', 'requirements' => ['shareToken' => '[^/]+', 'id' => '[^/]+']],
        ['name' => 'federation#meta',    'url' => '/api/federation/{shareToken}/meta',         'verb' => 'GET', 'requirements' => ['shareToken' => '[^/]+']],
        // Federation write-through (read-write shares only, token-scoped).
        ['name' => 'federation#createObject', 'url' => '/api/federation/{shareToken}/objects',      'verb' => 'POST',   'requirements' => ['shareToken' => '[^/]+']],
        ['name' => 'federation#updateObject', 'url' => '/api/federation/{shareToken}/objects/{id}', 'verb' => 'PUT',    'requirements' => ['shareToken' => '[^/]+', 'id' => '[^/]+']],
        ['name' => 'federation#deleteObject', 'url' => '/api/federation/{shareToken}/objects/{id}', 'verb' => 'DELETE', 'requirements' => ['shareToken' => '[^/]+', 'id' => '[^/]+']],
        // Federation share management (authenticated, organisation-scoped).
        ['name' => 'federation#shares',      'url' => '/api/federation/shares',      'verb' => 'GET'],
        ['name' => 'federation#createShare', 'url' => '/api/federation/shares',      'verb' => 'POST'],
        ['name' => 'federation#revokeShare', 'url' => '/api/federation/shares/{id}', 'verb' => 'DELETE', 'requirements' => ['id' => '\d+']],

        // Credential broker (credential-broker-service) — owner-scoped credential
        // metadata CRUD + per-app signing-secret registration + the guarded broker
        // call. The token broker call (`/request`) reads the app id from the verified
        // X-Credential-Token header (never the body); the session broker call
        // (`/session-request`) authenticates via the NC session + CSRF requesttoken
        // and reads the app id from the body, with the owner guard still enforced
        // against the session user. All owner-scoped, static errors, no secret leak.
        ['name' => 'credential#index',         'url' => '/api/credentials',                       'verb' => 'GET'],
        ['name' => 'credential#providers',     'url' => '/api/credentials/providers',             'verb' => 'GET'],
        // Sharing (shared-credentials-and-flows). `shared-with-me` is a LITERAL
        // segment and is registered next to `providers`, before the `{id}` routes,
        // so a future `GET /api/credentials/{id}` cannot swallow it.
        ['name' => 'credential#sharedWithMe',  'url' => '/api/credentials/shared-with-me',        'verb' => 'GET'],
        ['name' => 'credential#shares',        'url' => '/api/credentials/{id}/shares',           'verb' => 'GET',    'requirements' => ['id' => '[^/]+']],
        ['name' => 'credential#updateShares',  'url' => '/api/credentials/{id}/shares',           'verb' => 'PUT',    'requirements' => ['id' => '[^/]+']],
        ['name' => 'credential#create',        'url' => '/api/credentials',                       'verb' => 'POST'],
        ['name' => 'credential#update',        'url' => '/api/credentials/{id}',                  'verb' => 'PUT',    'requirements' => ['id' => '[^/]+']],
        ['name' => 'credential#destroy',       'url' => '/api/credentials/{id}',                  'verb' => 'DELETE', 'requirements' => ['id' => '[^/]+']],
        ['name' => 'credential#registerApp',   'url' => '/api/credentials/apps/{appId}/register', 'verb' => 'POST',   'requirements' => ['appId' => '[a-z0-9_-]+']],
        ['name' => 'credential#brokerRequest', 'url' => '/api/credentials/{id}/request',           'verb' => 'POST',   'requirements' => ['id' => '[^/]+']],
        ['name' => 'credential#sessionBrokerRequest', 'url' => '/api/credentials/{id}/session-request', 'verb' => 'POST', 'requirements' => ['id' => '[^/]+']],

        // Web Push channel (openregister-web-push-engine).
        // VAPID public key (browser subscribe key) + current-user subscription CRUD
        // (owner-scoped, no IDOR) + per-originApp cobalt-hex notification icon/badge.
        ['name' => 'webPush#vapidPublicKey', 'url' => '/webpush/vapid-public-key', 'verb' => 'GET'],
        ['name' => 'webPush#subscribe',      'url' => '/webpush/subscription',     'verb' => 'POST'],
        ['name' => 'webPush#unsubscribe',    'url' => '/webpush/subscription',     'verb' => 'DELETE'],
        ['name' => 'webPush#hexIcon',  'url' => '/webpush/icon/{app}',  'verb' => 'GET', 'requirements' => ['app' => '[a-z0-9_-]+']],
        ['name' => 'webPush#hexBadge', 'url' => '/webpush/badge/{app}', 'verb' => 'GET', 'requirements' => ['app' => '[a-z0-9_-]+']],

        // Integration registry (read-only discovery API) —
        // pluggable-integration-registry task 4.3 / tasks.md#task-20.
        ['name' => 'integrations#index', 'url' => '/api/integrations', 'verb' => 'GET'],
        ['name' => 'integrations#show',  'url' => '/api/integrations/{id}', 'verb' => 'GET', 'requirements' => ['id' => '[^/]+']],

        // Object-scoped integration sub-resource dispatch —
        // pluggable-integration-registry task 4.2 / tasks.md#task-19.
        ['name' => 'objectIntegrations#index',   'url' => '/api/objects/{register}/{schema}/{id}/integrations/{integrationId}',            'verb' => 'GET',    'requirements' => ['register' => '[^/]+', 'schema' => '[^/]+', 'id' => '[^/]+', 'integrationId' => '[^/]+']],
        ['name' => 'objectIntegrations#show',    'url' => '/api/objects/{register}/{schema}/{id}/integrations/{integrationId}/{entityId}', 'verb' => 'GET',    'requirements' => ['register' => '[^/]+', 'schema' => '[^/]+', 'id' => '[^/]+', 'integrationId' => '[^/]+', 'entityId' => '[^/]+']],
        ['name' => 'objectIntegrations#create',  'url' => '/api/objects/{register}/{schema}/{id}/integrations/{integrationId}',            'verb' => 'POST',   'requirements' => ['register' => '[^/]+', 'schema' => '[^/]+', 'id' => '[^/]+', 'integrationId' => '[^/]+']],
        ['name' => 'objectIntegrations#update',  'url' => '/api/objects/{register}/{schema}/{id}/integrations/{integrationId}/{entityId}', 'verb' => 'PUT',    'requirements' => ['register' => '[^/]+', 'schema' => '[^/]+', 'id' => '[^/]+', 'integrationId' => '[^/]+', 'entityId' => '[^/]+']],
        ['name' => 'objectIntegrations#destroy', 'url' => '/api/objects/{register}/{schema}/{id}/integrations/{integrationId}/{entityId}', 'verb' => 'DELETE', 'requirements' => ['register' => '[^/]+', 'schema' => '[^/]+', 'id' => '[^/]+', 'integrationId' => '[^/]+', 'entityId' => '[^/]+']],

        // Per-object scope and grants. `_authorization` is not writable through an
        // ordinary object save — non-admin writes have it stripped and the write
        // path omits the column so a routine update cannot destroy it — so
        // changing an object's scope needs this owner-checked entry point.
        ['name' => 'objectSharing#scope',        'url' => '/api/objects/{register}/{schema}/{id}/scope',            'verb' => 'GET',    'requirements' => ['register' => '[^/]+', 'schema' => '[^/]+', 'id' => '[^/]+']],
        ['name' => 'objectSharing#setScope',     'url' => '/api/objects/{register}/{schema}/{id}/scope',            'verb' => 'PUT',    'requirements' => ['register' => '[^/]+', 'schema' => '[^/]+', 'id' => '[^/]+']],
        ['name' => 'objectSharing#shares',       'url' => '/api/objects/{register}/{schema}/{id}/shares',           'verb' => 'GET',    'requirements' => ['register' => '[^/]+', 'schema' => '[^/]+', 'id' => '[^/]+']],
        ['name' => 'objectSharing#createShare',  'url' => '/api/objects/{register}/{schema}/{id}/shares',           'verb' => 'POST',   'requirements' => ['register' => '[^/]+', 'schema' => '[^/]+', 'id' => '[^/]+']],
        ['name' => 'objectSharing#destroyShare', 'url' => '/api/objects/{register}/{schema}/{id}/shares/{shareId}', 'verb' => 'DELETE', 'requirements' => ['register' => '[^/]+', 'schema' => '[^/]+', 'id' => '[^/]+', 'shareId' => '[^/]+']],
        ['name' => 'objectSharing#createLink',   'url' => '/api/objects/{register}/{schema}/{id}/links',            'verb' => 'POST',   'requirements' => ['register' => '[^/]+', 'schema' => '[^/]+', 'id' => '[^/]+']],
        ['name' => 'objectSharing#inviteByEmail','url' => '/api/objects/{register}/{schema}/{id}/invitations',      'verb' => 'POST',   'requirements' => ['register' => '[^/]+', 'schema' => '[^/]+', 'id' => '[^/]+']],

        // PUBLIC. A share token is a bearer capability: nobody is logged in, so
        // there is no principal for RBAC to resolve and core's validation of the
        // token IS the authorization. Read-only, addresses exactly one object,
        // and deliberately offers no listing — see ObjectShareLinkController.
        ['name' => 'objectShareLink#show', 'url' => '/api/shared/{token}', 'verb' => 'GET', 'requirements' => ['token' => '[^/]+']],

        // PATCH routes for resources (partial updates).
        ['name' => 'registers#patch', 'url' => '/api/registers/{id}', 'verb' => 'PATCH', 'requirements' => ['id' => '[^/]+']],
        ['name' => 'schemas#patch', 'url' => '/api/schemas/{id}', 'verb' => 'PATCH', 'requirements' => ['id' => '[^/]+']],
        ['name' => 'sources#patch', 'url' => '/api/sources/{id}', 'verb' => 'PATCH', 'requirements' => ['id' => '[^/]+']],

        // Curated MDI glyph as an SVG image (used to render a schema's icon in unified search).
        ['name' => 'icon#mdi', 'url' => '/api/icon/mdi/{name}', 'verb' => 'GET', 'requirements' => ['name' => '[A-Za-z0-9-]+']],

        // Data sync / harvesting — manual trigger + status (data-sync-harvesting spec).
        ['name' => 'sources#syncNow',    'url' => '/api/sources/{id}/sync',        'verb' => 'POST', 'requirements' => ['id' => '[^/]+']],
        ['name' => 'sources#syncStatus', 'url' => '/api/sources/{id}/sync-status', 'verb' => 'GET',  'requirements' => ['id' => '[^/]+']],

        // Virtual registers over DBAL — connection test + introspection (dbal-virtual-registers spec).
        ['name' => 'sources#testConnection', 'url' => '/api/sources/{id}/test-connection', 'verb' => 'POST', 'requirements' => ['id' => '[^/]+']],
        ['name' => 'sources#introspect',     'url' => '/api/sources/{id}/introspect',      'verb' => 'POST', 'requirements' => ['id' => '[^/]+']],

        ['name' => 'configurations#patch', 'url' => '/api/configurations/{id}', 'verb' => 'PATCH', 'requirements' => ['id' => '[^/]+']],
        ['name' => 'applications#patch', 'url' => '/api/applications/{id}', 'verb' => 'PATCH', 'requirements' => ['id' => '[^/]+']],
        ['name' => 'agents#patch', 'url' => '/api/agents/{id}', 'verb' => 'PATCH', 'requirements' => ['id' => '[^/]+']],
        ['name' => 'endpoints#patch', 'url' => '/api/endpoints/{id}', 'verb' => 'PATCH', 'requirements' => ['id' => '[^/]+']],
        ['name' => 'mappings#patch', 'url' => '/api/mappings/{id}', 'verb' => 'PATCH', 'requirements' => ['id' => '[^/]+']],
        ['name' => 'consumers#patch', 'url' => '/api/consumers/{id}', 'verb' => 'PATCH', 'requirements' => ['id' => '[^/]+']],

        // Mappings - Custom routes.
        ['name' => 'mappings#test', 'url' => '/api/mappings/test', 'verb' => 'POST'],

        // Endpoints - Custom routes.
        ['name' => 'endpoints#test', 'url' => '/api/endpoints/{id}/test', 'verb' => 'POST', 'requirements' => ['id' => '\d+']],
        ['name' => 'endpoints#logs', 'url' => '/api/endpoints/{id}/logs', 'verb' => 'GET', 'requirements' => ['id' => '\d+']],
        ['name' => 'endpoints#logStats', 'url' => '/api/endpoints/{id}/logs/stats', 'verb' => 'GET', 'requirements' => ['id' => '\d+']],
        ['name' => 'endpoints#allLogs', 'url' => '/api/endpoints/logs', 'verb' => 'GET'],

        // Settings - Legacy endpoints (kept for compatibility).
        // Register descriptors — which app-declared registers landed, and a
        // forced re-import for the ones that did not. Admin-only, enforced in
        // the controller.
        ['name' => 'registerDescriptor#index', 'url' => '/api/register-descriptors', 'verb' => 'GET'],
        ['name' => 'registerDescriptor#import', 'url' => '/api/register-descriptors/{appId}/{slug}/import', 'verb' => 'POST'],
        ['name' => 'settings#index', 'url' => '/api/settings', 'verb' => 'GET'],
        ['name' => 'settings#update', 'url' => '/api/settings', 'verb' => 'PUT'],
        ['name' => 'settings#rebase', 'url' => '/api/settings/rebase', 'verb' => 'POST'],
        // Generic per-user preferences (used by shared nextcloud-vue widgets, e.g. CnSupportDialog).
        ['name' => 'preferences#getPreference', 'url' => '/api/preferences/{key}', 'verb' => 'GET'],
        ['name' => 'preferences#setPreference', 'url' => '/api/preferences/{key}', 'verb' => 'PUT'],
        ['name' => 'settings#stats', 'url' => '/api/settings/stats', 'verb' => 'GET'],

        // Migration - Move objects between blob storage and magic tables.
        ['name' => 'migration#status', 'url' => '/api/migration/status/{register}/{schema}', 'verb' => 'GET', 'requirements' => ['register' => '[^/]+', 'schema' => '[^/]+']],
        ['name' => 'migration#migrate', 'url' => '/api/migration/migrate', 'verb' => 'POST'],

        // Settings - Focused endpoints for better performance.
        ['name' => 'settings#getSearchBackend', 'url' => '/api/settings/search-backend', 'verb' => 'GET'],
        ['name' => 'settings#updateSearchBackend', 'url' => '/api/settings/search-backend', 'verb' => 'PUT'],
        ['name' => 'settings#updateSearchBackend', 'url' => '/api/settings/search-backend', 'verb' => 'PATCH'],
        // Magic Table Sync endpoints.
        ['name' => 'tables#sync', 'url' => '/api/tables/sync/{registerId}/{schemaId}', 'verb' => 'POST', 'requirements' => ['registerId' => '[^/]+', 'schemaId' => '[^/]+']],
        ['name' => 'tables#syncAll', 'url' => '/api/tables/sync', 'verb' => 'POST'],

        ['name' => 'Settings\ConfigurationSettings#getRbacSettings', 'url' => '/api/settings/rbac', 'verb' => 'GET'],
        ['name' => 'Settings\ConfigurationSettings#updateRbacSettings', 'url' => '/api/settings/rbac', 'verb' => 'PATCH'],
        ['name' => 'Settings\ConfigurationSettings#updateRbacSettings', 'url' => '/api/settings/rbac', 'verb' => 'PUT'],

        ['name' => 'Settings\ConfigurationSettings#getMultitenancySettings', 'url' => '/api/settings/multitenancy', 'verb' => 'GET'],
        ['name' => 'Settings\ConfigurationSettings#updateMultitenancySettings', 'url' => '/api/settings/multitenancy', 'verb' => 'PATCH'],
        ['name' => 'Settings\ConfigurationSettings#updateMultitenancySettings', 'url' => '/api/settings/multitenancy', 'verb' => 'PUT'],

        ['name' => 'Settings\ConfigurationSettings#getOrganisationSettings', 'url' => '/api/settings/organisation', 'verb' => 'GET'],
        ['name' => 'Settings\ConfigurationSettings#updateOrganisationSettings', 'url' => '/api/settings/organisation', 'verb' => 'PATCH'],
        ['name' => 'Settings\ConfigurationSettings#updateOrganisationSettings', 'url' => '/api/settings/organisation', 'verb' => 'PUT'],

        ['name' => 'Settings\LlmSettings#getLLMSettings', 'url' => '/api/settings/llm', 'verb' => 'GET'],
        ['name' => 'settings#getDatabaseInfo', 'url' => '/api/settings/database', 'verb' => 'GET'],
        ['name' => 'settings#refreshDatabaseInfo', 'url' => '/api/settings/database/refresh', 'verb' => 'POST'],
        ['name' => 'Settings\LlmSettings#updateLLMSettings', 'url' => '/api/settings/llm', 'verb' => 'POST'],
        ['name' => 'Settings\LlmSettings#patchLLMSettings', 'url' => '/api/settings/llm', 'verb' => 'PATCH'],
        ['name' => 'Settings\LlmSettings#updateLLMSettings', 'url' => '/api/settings/llm', 'verb' => 'PUT'],
        ['name' => 'Settings\LlmSettings#testEmbedding', 'url' => '/api/vectors/test-embedding', 'verb' => 'POST'],
        ['name' => 'Settings\LlmSettings#testChat', 'url' => '/api/llm/test-chat', 'verb' => 'POST'],
        ['name' => 'Settings\LlmSettings#getOllamaModels', 'url' => '/api/llm/ollama-models', 'verb' => 'GET'],
        ['name' => 'Settings\LlmSettings#checkEmbeddingModelMismatch', 'url' => '/api/vectors/check-model-mismatch', 'verb' => 'GET'],
        ['name' => 'Settings\LlmSettings#clearAllEmbeddings', 'url' => '/api/vectors/clear-all', 'verb' => 'DELETE'],
        ['name' => 'Settings\FileSettings#getFileSettings', 'url' => '/api/settings/files', 'verb' => 'GET'],
        ['name' => 'Settings\FileSettings#updateFileSettings', 'url' => '/api/settings/files', 'verb' => 'PATCH'],
        ['name' => 'Settings\FileSettings#updateFileSettings', 'url' => '/api/settings/files', 'verb' => 'PUT'],
        ['name' => 'Settings\FileSettings#getFileExtractionStats', 'url' => '/api/settings/files/stats', 'verb' => 'GET'],
        ['name' => 'Settings\FileSettings#testDolphinConnection', 'url' => '/api/settings/files/test-dolphin', 'verb' => 'POST'],
        ['name' => 'Settings\FileSettings#testPresidioConnection', 'url' => '/api/settings/files/test-presidio', 'verb' => 'POST'],
        ['name' => 'Settings\FileSettings#testOpenAnonymiserConnection', 'url' => '/api/settings/files/test-openanonymiser', 'verb' => 'POST'],

        // Anonymisation backend selection (admin-only).
        ['name' => 'anonymisationBackend#getBackendState', 'url' => '/api/admin/anonymisation/backend-state', 'verb' => 'GET'],
        ['name' => 'anonymisationBackend#testConnection', 'url' => '/api/admin/anonymisation/test-connection', 'verb' => 'POST'],

        ['name' => 'Settings\ConfigurationSettings#getObjectSettings', 'url' => '/api/settings/objects/vectorize', 'verb' => 'GET'],
        ['name' => 'Settings\ConfigurationSettings#getObjectSettings', 'url' => '/api/settings/objects', 'verb' => 'GET'],
        ['name' => 'Settings\ConfigurationSettings#updateObjectSettings', 'url' => '/api/settings/objects/vectorize', 'verb' => 'POST'],
        ['name' => 'Settings\ConfigurationSettings#patchObjectSettings', 'url' => '/api/settings/objects/vectorize', 'verb' => 'PATCH'],
        ['name' => 'Settings\ConfigurationSettings#updateObjectSettings', 'url' => '/api/settings/objects/vectorize', 'verb' => 'PUT'],

        // Object vectorization endpoints.
        ['name' => 'objects#vectorizeBatch', 'url' => '/api/objects/vectorize/batch', 'verb' => 'POST'],
        ['name' => 'objects#getObjectVectorizationCount', 'url' => '/api/objects/vectorize/count', 'verb' => 'GET'],
        ['name' => 'objects#getObjectVectorizationStats', 'url' => '/api/objects/vectorize/stats', 'verb' => 'GET'],

        // Object validation endpoint.
        ['name' => 'objects#validate', 'url' => '/api/objects/validate', 'verb' => 'POST'],

        // Batched object-count endpoint (one round-trip for many register/schema pairs).
        ['name' => 'objects#counts', 'url' => '/api/objects/counts', 'verb' => 'POST'],

        // Core file extraction endpoints (use fileExtraction controller to avoid conflict with files controller).
        // NOTE: Specific routes MUST come before parameterized routes like {id}
        ['name' => 'fileExtraction#index', 'url' => '/api/files', 'verb' => 'GET'],
        ['name' => 'fileExtraction#stats', 'url' => '/api/files/stats', 'verb' => 'GET'],
        ['name' => 'fileExtraction#fileTypes', 'url' => '/api/files/types', 'verb' => 'GET'],
        ['name' => 'fileExtraction#vectorizeBatch', 'url' => '/api/files/vectorize/batch', 'verb' => 'POST'],
        ['name' => 'fileExtraction#discover', 'url' => '/api/files/discover', 'verb' => 'POST'],
        ['name' => 'fileExtraction#extractAll', 'url' => '/api/files/extract', 'verb' => 'POST'],
        ['name' => 'fileExtraction#retryFailed', 'url' => '/api/files/retry-failed', 'verb' => 'POST'],
        ['name' => 'fileExtraction#cleanup', 'url' => '/api/files/cleanup', 'verb' => 'POST'],
        ['name' => 'fileExtraction#show', 'url' => '/api/files/{id}', 'verb' => 'GET'],
        ['name' => 'fileExtraction#extract', 'url' => '/api/files/{id}/extract', 'verb' => 'POST'],

        ['name' => 'Settings\ConfigurationSettings#getRetentionSettings', 'url' => '/api/settings/retention', 'verb' => 'GET'],

        // Settings — additional endpoints.
        ['name' => 'settings#load',                     'url' => '/api/settings/load',                            'verb' => 'GET'],
        ['name' => 'settings#semanticSearch',           'url' => '/api/settings/search/semantic',                 'verb' => 'GET'],
        ['name' => 'settings#hybridSearch',             'url' => '/api/settings/search/hybrid',                   'verb' => 'GET'],
        // Debug endpoints for type filtering issue.
        ['name' => 'settings#debugTypeFiltering', 'url' => '/api/debug/type-filtering', 'verb' => 'GET'],
        ['name' => 'Settings\ConfigurationSettings#updateRetentionSettings', 'url' => '/api/settings/retention', 'verb' => 'PATCH'],
        ['name' => 'Settings\ConfigurationSettings#updateRetentionSettings', 'url' => '/api/settings/retention', 'verb' => 'PUT'],

        ['name' => 'settings#getVersionInfo', 'url' => '/api/settings/version', 'verb' => 'GET'],

        // API Tokens for GitHub and GitLab.
        ['name' => 'Settings\ApiTokenSettings#getApiTokens', 'url' => '/api/settings/api-tokens', 'verb' => 'GET'],
        ['name' => 'Settings\ApiTokenSettings#saveApiTokens', 'url' => '/api/settings/api-tokens', 'verb' => 'POST'],
        ['name' => 'Settings\ApiTokenSettings#testGitHubToken', 'url' => '/api/settings/api-tokens/test/github', 'verb' => 'POST'],
        ['name' => 'Settings\ApiTokenSettings#testGitLabToken', 'url' => '/api/settings/api-tokens/test/gitlab', 'verb' => 'POST'],

        // n8n workflow integration.
        ['name' => 'Settings\N8nSettings#getN8nSettings', 'url' => '/api/settings/n8n', 'verb' => 'GET'],
        ['name' => 'Settings\N8nSettings#updateN8nSettings', 'url' => '/api/settings/n8n', 'verb' => 'POST'],
        ['name' => 'Settings\N8nSettings#updateN8nSettings', 'url' => '/api/settings/n8n', 'verb' => 'PATCH'],
        ['name' => 'Settings\N8nSettings#updateN8nSettings', 'url' => '/api/settings/n8n', 'verb' => 'PUT'],
        ['name' => 'Settings\N8nSettings#testN8nConnection', 'url' => '/api/settings/n8n/test', 'verb' => 'POST'],
        ['name' => 'Settings\N8nSettings#initializeN8n', 'url' => '/api/settings/n8n/initialize', 'verb' => 'POST'],
        ['name' => 'Settings\N8nSettings#getWorkflows', 'url' => '/api/settings/n8n/workflows', 'verb' => 'GET'],

        // Statistics endpoint.
        ['name' => 'settings#getStatistics', 'url' => '/api/settings/statistics', 'verb' => 'GET'],

        // Cache management.
        ['name' => 'Settings\CacheSettings#getCacheStats', 'url' => '/api/settings/cache', 'verb' => 'GET'],
        ['name' => 'Settings\CacheSettings#clearCache', 'url' => '/api/settings/cache', 'verb' => 'DELETE'],
        ['name' => 'Settings\CacheSettings#warmupNamesCache', 'url' => '/api/settings/cache/warmup-names', 'verb' => 'POST'],
        ['name' => 'Settings\CacheSettings#getWarmupInterval', 'url' => '/api/settings/cache/warmup-interval', 'verb' => 'GET'],
        ['name' => 'Settings\CacheSettings#setWarmupInterval', 'url' => '/api/settings/cache/warmup-interval', 'verb' => 'PUT'],
        ['name' => 'Settings\CacheSettings#clearAppStoreCache', 'url' => '/api/settings/cache/appstore', 'verb' => 'DELETE'],

        // Security management - Rate limiting and IP blocking.
        ['name' => 'Settings\SecuritySettings#clearIpRateLimits', 'url' => '/api/settings/security/unblock-ip', 'verb' => 'POST'],
        ['name' => 'Settings\SecuritySettings#clearUserRateLimits', 'url' => '/api/settings/security/unblock-user', 'verb' => 'POST'],
        ['name' => 'Settings\SecuritySettings#clearAllRateLimits', 'url' => '/api/settings/security/unblock', 'verb' => 'POST'],
        ['name' => 'Settings\ValidationSettings#validateAllObjects', 'url' => '/api/settings/validate-all-objects', 'verb' => 'POST'],
        ['name' => 'Settings\ValidationSettings#massValidateObjects', 'url' => '/api/settings/mass-validate', 'verb' => 'POST'],
        ['name' => 'Settings\ValidationSettings#predictMassValidationMemory', 'url' => '/api/settings/mass-validate/memory-prediction', 'verb' => 'POST'],
        // Manifest endpoint — returns host-app manifest enriched with runtime.user context.
        ['name' => 'manifest#index', 'url' => '/api/manifest/{appId}', 'verb' => 'GET', 'requirements' => ['appId' => '[^/]+']],
        // Heartbeat - Keep-alive endpoint for long-running operations.
        ['name' => 'heartbeat#heartbeat', 'url' => '/api/heartbeat', 'verb' => 'GET'],
        // Prometheus metrics endpoint — served by OpenRegister's own AppHost
        // declarative observability engine (ADR-040). OR dogfoods the engine:
        // the canonical /api/metrics URL is aliased at GenericMetricsController,
        // which reads the `observability.metrics` block of src/manifest.json.
        // URL + output contract are unchanged from the deleted MetricsController
        // (parity-verified); $appName resolves to "openregister".
        ['name' => 'AppHost\Controller\GenericMetrics#index', 'url' => '/api/metrics', 'verb' => 'GET'],
        // Health check endpoint — served by the AppHost engine from the
        // `observability.health` block. The engine adds #[PublicPage] (ADR-006
        // anon health, an intentional improvement over the login-gated bespoke
        // controller) and the standard {status, app, version, checks} shape.
        ['name' => 'AppHost\Controller\GenericHealth#index', 'url' => '/api/health', 'verb' => 'GET'],
        // URN resolution endpoints (RFC 8141 system-independent identifiers).
        ['name' => 'urn#resolve', 'url' => '/api/urn/resolve', 'verb' => 'GET'],
        ['name' => 'urn#lookup',  'url' => '/api/urn/lookup',  'verb' => 'GET'],
        ['name' => 'urn#bulk',    'url' => '/api/urn/bulk',    'verb' => 'POST'],
        // JSON-LD context document endpoints (json-ld-output). Dereferenceable
        // @context documents referenced by JSON-LD object serializations.
        ['name' => 'contexts#register', 'url' => '/api/contexts/{register}',          'verb' => 'GET'],
        ['name' => 'contexts#schema',   'url' => '/api/contexts/{register}/{schema}', 'verb' => 'GET'],
        // RBAC scope discovery endpoint — clients query effective (register,
        // schema, action) scopes for the authenticated user without probing
        // every endpoint individually.
        ['name' => 'scopes#index', 'url' => '/api/scopes', 'verb' => 'GET'],
        // AVG / GDPR Art 30 verwerkingsregister CRUD + accountability document.
        ['name' => 'verwerkingsactiviteiten#index',          'url' => '/api/avg/processing-activities',        'verb' => 'GET'],
        ['name' => 'verwerkingsactiviteiten#show',           'url' => '/api/avg/processing-activities/{id}',   'verb' => 'GET',    'requirements' => ['id' => '[^/]+']],
        ['name' => 'verwerkingsactiviteiten#create',         'url' => '/api/avg/processing-activities',        'verb' => 'POST'],
        ['name' => 'verwerkingsactiviteiten#update',         'url' => '/api/avg/processing-activities/{id}',   'verb' => 'PUT',    'requirements' => ['id' => '[^/]+']],
        ['name' => 'verwerkingsactiviteiten#destroy',        'url' => '/api/avg/processing-activities/{id}',   'verb' => 'DELETE', 'requirements' => ['id' => '[^/]+']],
        ['name' => 'verwerkingsactiviteiten#accountability', 'url' => '/api/avg/accountability',               'verb' => 'GET'],
        // AVG / GDPR data-subject rights endpoints (Phase 2b).
        ['name' => 'dsar#access',         'url' => '/api/avg/access',         'verb' => 'GET'],
        ['name' => 'dsar#portability',    'url' => '/api/avg/portability',    'verb' => 'GET'],
        ['name' => 'dsar#erasure',        'url' => '/api/avg/erasure',        'verb' => 'POST'],
        ['name' => 'dsar#rectification',  'url' => '/api/avg/rectification',  'verb' => 'POST'],
        ['name' => 'dsar#compliance',     'url' => '/api/avg/compliance',     'verb' => 'GET'],
        // Generic, RBAC + tenant scoped GDPR data-subject-rights endpoints
        // (consumable by leaf apps; NOT admin-only, distinct from dsar#*).
        ['name' => 'dataSubjectRequest#subjectData',  'url' => '/api/gdpr/subject-data',  'verb' => 'GET'],
        ['name' => 'dataSubjectRequest#accessExport', 'url' => '/api/gdpr/access-export', 'verb' => 'GET'],
        ['name' => 'dataSubjectRequest#rectify',      'url' => '/api/gdpr/rectify',       'verb' => 'POST'],
        ['name' => 'dataSubjectRequest#erase',        'url' => '/api/gdpr/erase',         'verb' => 'POST'],
        ['name' => 'dataSubjectRequest#restrict',     'url' => '/api/gdpr/restrict',      'verb' => 'POST'],
        ['name' => 'dataSubjectRequest#objection',    'url' => '/api/gdpr/object',        'verb' => 'POST'],
        // DSAR case-management engine (dsar-case-engine): stateful case workflow.
        // All @NoAdminRequired (never @PublicPage); @NoCSRFRequired only on the
        // one-time download (browser navigation). Case-level access control
        // (handler-scopes-own + officer-override, fail-closed) enforced in-body.
        ['name' => 'dsarCase#create',         'url' => '/api/gdpr/cases',                        'verb' => 'POST'],
        ['name' => 'dsarCase#transition',     'url' => '/api/gdpr/cases/{id}/transition',        'verb' => 'POST', 'requirements' => ['id' => '[^/]+']],
        ['name' => 'dsarCase#evidence',       'url' => '/api/gdpr/cases/{id}/evidence',          'verb' => 'POST', 'requirements' => ['id' => '[^/]+']],
        ['name' => 'dsarCase#redact',         'url' => '/api/gdpr/cases/{id}/redactions',        'verb' => 'POST', 'requirements' => ['id' => '[^/]+']],
        ['name' => 'dsarCase#generateBundle', 'url' => '/api/gdpr/cases/{id}/bundle',            'verb' => 'POST', 'requirements' => ['id' => '[^/]+']],
        ['name' => 'dsarCase#downloadBundle', 'url' => '/api/gdpr/cases/{id}/bundle/download',   'verb' => 'GET',  'requirements' => ['id' => '[^/]+']],
        ['name' => 'dsarCase#dossier',        'url' => '/api/gdpr/cases/{id}/dossier',           'verb' => 'GET',  'requirements' => ['id' => '[^/]+']],
        // DSAR integration seams (dsar-integration-seams): pack-selector-driven,
        // fail-closed identity-verify + regulator-escalate call-outs.
        ['name' => 'dsarCase#identityVerify', 'url' => '/api/gdpr/cases/{id}/verify-identity',   'verb' => 'POST', 'requirements' => ['id' => '[^/]+']],
        ['name' => 'dsarCase#escalate',       'url' => '/api/gdpr/cases/{id}/escalate',          'verb' => 'POST', 'requirements' => ['id' => '[^/]+']],
        // AVG / GDPR per-access processing log (verwerkingenlogging) — read-only,
        // admin-default + FG-delegated, append-only by surface (no write routes).
        ['name' => 'processingLog#index',      'url' => '/api/avg/verwerkingen',            'verb' => 'GET'],
        ['name' => 'processingLog#involvedParty', 'url' => '/api/avg/verwerkingen/betrokkene', 'verb' => 'GET'],
        // Translation sidecar — search, per-object slots + completeness, status updates.
        ['name' => 'translation#search',        'url' => '/api/translations/search',                                          'verb' => 'GET'],
        ['name' => 'translation#showByObject',  'url' => '/api/translations/object/{uuid}',                                   'verb' => 'GET'],
        ['name' => 'translation#setStatus',     'url' => '/api/translations/object/{uuid}/{property}/{language}/status',      'verb' => 'POST'],
        ['name' => 'translation#bulkTranslate', 'url' => '/api/translations/object/{uuid}/bulk-translate',                    'verb' => 'POST'],
        // Names - object name lookup. Both remaining routes require a session and
        // return 401 without one. The single-object route `/api/names/{id}` and the
        // `/stats` + `/warmup` routes were REMOVED (SEC-CTRL-2): all three were
        // #[PublicPage], and `{id}` resolved any object's name through
        // findAcrossAllSources(_rbac: false, _multitenancy: false). Manual warmup
        // still exists, admin-only, at POST /api/settings/cache/warmup-names.
        ['name' => 'names#index', 'url' => '/api/names', 'verb' => 'GET'],
        ['name' => 'names#create', 'url' => '/api/names', 'verb' => 'POST'],
        // Dashbaord.
        ['name' => 'dashboard#index', 'url' => '/api/dashboard', 'verb' => 'GET'],
        ['name' => 'dashboard#calculate', 'url' => '/api/dashboard/calculate/{registerId}', 'verb' => 'POST', 'requirements' => ['registerId' => '\d+']],
        // Dashboard Charts.
        ['name' => 'dashboard#getAuditTrailActionChart', 'url' => '/api/dashboard/charts/audit-trail-actions', 'verb' => 'GET'],
        ['name' => 'dashboard#getObjectsByRegisterChart', 'url' => '/api/dashboard/charts/objects-by-register', 'verb' => 'GET'],
        ['name' => 'dashboard#getObjectsBySchemaChart', 'url' => '/api/dashboard/charts/objects-by-schema', 'verb' => 'GET'],
        ['name' => 'dashboard#getObjectsBySizeChart', 'url' => '/api/dashboard/charts/objects-by-size', 'verb' => 'GET'],
        // Dashboard Statistics.
        ['name' => 'dashboard#getAuditTrailStatistics', 'url' => '/api/dashboard/statistics/audit-trail', 'verb' => 'GET'],
        ['name' => 'dashboard#getAuditTrailActionDistribution', 'url' => '/api/dashboard/statistics/audit-trail-distribution', 'verb' => 'GET'],
        ['name' => 'dashboard#getMostActiveObjects', 'url' => '/api/dashboard/statistics/most-active-objects', 'verb' => 'GET'],
        // Linked entities (mail sidebar, contacts sidebar, etc.).
        // Must be before objects/{register}/{schema} routes to avoid wildcard matching.
        ['name' => 'linked_entity#addObjectLink', 'url' => '/api/objects/{uuid}/_linked/{type}', 'verb' => 'POST', 'requirements' => ['uuid' => '[^/]+', 'type' => '[^/]+']],
        ['name' => 'linked_entity#removeObjectLink', 'url' => '/api/objects/{uuid}/_linked/{type}/{entityId}', 'verb' => 'DELETE', 'requirements' => ['uuid' => '[^/]+', 'type' => '[^/]+', 'entityId' => '.+']],
        ['name' => 'linked_entity#addRegisterLink', 'url' => '/api/registers/{uuid}/_linked/{type}', 'verb' => 'POST', 'requirements' => ['uuid' => '[^/]+', 'type' => '[^/]+']],
        ['name' => 'linked_entity#addSchemaLink', 'url' => '/api/schemas/{uuid}/_linked/{type}', 'verb' => 'POST', 'requirements' => ['uuid' => '[^/]+', 'type' => '[^/]+']],
        // Note: entityId uses .+ (not [^/]+) because mail entity refs are `{accountId}/{messageId}`
        // with a RAW slash — the sidebar deliberately does not URL-encode it (Apache rejects %2F in
        // paths unless AllowEncodedSlashes is on; see commit d8acb45f0). The legacy underscore-
        // prefixed variant below (linkedEntity#reverseLookup on /api/linked/_{type}/{entityId}) coexists
        // for backwards compatibility with older mail-sidebar clients; deduplicate in a future cleanup.
        ['name' => 'linked_entity#reverseLookup', 'url' => '/api/linked/{type}/{entityId}', 'verb' => 'GET', 'requirements' => ['type' => '[^/]+', 'entityId' => '.+']],

        // Objects.
        ['name' => 'objects#objects', 'url' => '/api/objects', 'verb' => 'GET'],
        // SEC-CTRL-10: the clearBlob route was removed — blob storage retired; the controller method was a no-op.
        // The objects import route was also removed — use the registers import endpoint instead.
        // Lifecycle transitions — MUST precede the wildcard {register}/{schema} routes
        // so /api/objects/{id}/transition isn't grabbed as register=id, schema=transition.
        ['name' => 'transition#transition', 'url' => '/api/objects/{id}/transition', 'verb' => 'POST', 'requirements' => ['id' => '[^/]+']],
        ['name' => 'transition#availableActions', 'url' => '/api/objects/{id}/available-actions', 'verb' => 'GET', 'requirements' => ['id' => '[^/]+']],

        // Aggregations — ad-hoc time-bucket primitive (must be ordered
        // BEFORE the {name} wildcard so /timeseries literal matches first).
        ['name' => 'aggregation#timeseries', 'url' => '/api/objects/aggregations/{register}/{schema}/timeseries', 'verb' => 'GET'],
        // Aggregations — ad-hoc single-value + categorical group-by primitives (literal before {name}).
        ['name' => 'aggregation#value', 'url' => '/api/objects/aggregations/{register}/{schema}/value', 'verb' => 'GET'],
        ['name' => 'aggregation#grouped', 'url' => '/api/objects/aggregations/{register}/{schema}/grouped', 'verb' => 'GET'],
        // Aggregations sugar endpoint — named annotation surface.
        ['name' => 'aggregation#aggregate', 'url' => '/api/objects/aggregations/{register}/{schema}/{name}', 'verb' => 'GET'],

        // MDM read-only surface — quality statistics + lowest-quality listing
        // (must be ordered BEFORE the bare {register}/{schema} listing so the
        // literal /stats segment matches first).
        ['name' => 'quality#stats', 'url' => '/api/objects/quality/{register}/{schema}/stats', 'verb' => 'GET'],
        ['name' => 'quality#index', 'url' => '/api/objects/quality/{register}/{schema}', 'verb' => 'GET'],
        // MDM read-only surface — duplicate-candidate listing.
        ['name' => 'duplicate#index', 'url' => '/api/objects/duplicates/{register}/{schema}', 'verb' => 'GET'],
        // MDM reversible merge surface (ADR-045 follow-on #B) — preview / execute / reverse.
        ['name' => 'merge#preview', 'url' => '/api/objects/merge/preview', 'verb' => 'POST'],
        ['name' => 'merge#execute', 'url' => '/api/objects/merge/execute', 'verb' => 'POST'],
        ['name' => 'merge#reverse', 'url' => '/api/objects/merge/{id}/reverse', 'verb' => 'POST', 'requirements' => ['id' => '[^/]+']],

        // MDM per-object attribute-override primitive (ADR-045 follow-on #E) —
        // sets/clears one attribute override on a master object and recomputes
        // its golden record.
        ['name' => 'survivorship#override', 'url' => '/api/objects/survivorship/{id}/override', 'verb' => 'POST', 'requirements' => ['id' => '[^/]+']],
        // Resolve a master's competing source records (embedded or reverse-FK)
        // for the conflict-resolution UI.
        ['name' => 'survivorship#sources', 'url' => '/api/objects/survivorship/{id}/sources', 'verb' => 'GET', 'requirements' => ['id' => '[^/]+']],

        // Contacts matching API — used by ContactsMenuProvider + mail-sidebar.
        ['name' => 'contacts#match', 'url' => '/api/contacts/match', 'verb' => 'GET'],

        // Mail sidebar — reverse lookup of OR objects linked to an email.
        // Search + bySender are app-global (no register/schema in path) and
        // stay on the legacy Tier-1 controller. The per-object link/unlink
        // surface is served by the Tier-2 `emailLinks` controller which adds
        // idempotent upsert + composite-key uniqueness; the picker step
        // routes (accounts/mailboxes/messages) live alongside.
        ['name' => 'emails#index',    'url' => '/api/objects/{register}/{schema}/{id}/emails/list',  'verb' => 'GET',    'requirements' => ['id' => '[^/]+']],
        ['name' => 'emails#create',   'url' => '/api/objects/{register}/{schema}/{id}/emails/send',  'verb' => 'POST',   'requirements' => ['id' => '[^/]+']],
        ['name' => 'emails#destroy',  'url' => '/api/objects/{register}/{schema}/{id}/emails/direct/{emailId}', 'verb' => 'DELETE', 'requirements' => ['id' => '[^/]+', 'emailId' => '[^/]+']],
        ['name' => 'emails#search',   'url' => '/api/emails/search',                                'verb' => 'GET'],
        ['name' => 'emails#bySender', 'url' => '/api/emails/by-sender',                             'verb' => 'GET'],
        ['name' => 'emailLinks#index',   'url' => '/api/objects/{register}/{schema}/{id}/emails',           'verb' => 'GET',    'requirements' => ['id' => '[^/]+']],
        ['name' => 'emailLinks#link',    'url' => '/api/objects/{register}/{schema}/{id}/emails',           'verb' => 'POST',   'requirements' => ['id' => '[^/]+']],
        ['name' => 'emailLinks#destroy', 'url' => '/api/objects/{register}/{schema}/{id}/emails/{linkId}',  'verb' => 'DELETE', 'requirements' => ['id' => '[^/]+', 'linkId' => '[0-9]+']],
        ['name' => 'emailLinks#accounts',  'url' => '/api/integrations/email/accounts',                                       'verb' => 'GET'],
        ['name' => 'emailLinks#mailboxes', 'url' => '/api/integrations/email/accounts/{accountId}/mailboxes',                 'verb' => 'GET',    'requirements' => ['accountId' => '[0-9]+']],
        ['name' => 'emailLinks#messages',  'url' => '/api/integrations/email/accounts/{accountId}/messages',                  'verb' => 'GET',    'requirements' => ['accountId' => '[0-9]+']],

        // Contacts — object↔NC contact links + reverse lookup. Match is app-global.
        // The explicit `/contacts/new` route is the Tier-2 create-only path
        // surfaced to the new `CnContactCreate` dialog; the bare POST still
        // accepts both link- and create-shaped payloads for back-compat.
        ['name' => 'contacts#index',     'url' => '/api/objects/{register}/{schema}/{id}/contacts',                 'verb' => 'GET',    'requirements' => ['id' => '[^/]+']],
        ['name' => 'contacts#createNew', 'url' => '/api/objects/{register}/{schema}/{id}/contacts/new',             'verb' => 'POST',   'requirements' => ['id' => '[^/]+']],
        ['name' => 'contacts#create',    'url' => '/api/objects/{register}/{schema}/{id}/contacts',                 'verb' => 'POST',   'requirements' => ['id' => '[^/]+']],
        ['name' => 'contacts#update',    'url' => '/api/objects/{register}/{schema}/{id}/contacts/{contactUid}',    'verb' => 'PUT',    'requirements' => ['id' => '[^/]+', 'contactUid' => '[^/]+']],
        ['name' => 'contacts#destroy',   'url' => '/api/objects/{register}/{schema}/{id}/contacts/{contactUid}',    'verb' => 'DELETE', 'requirements' => ['id' => '[^/]+', 'contactUid' => '[^/]+']],
        ['name' => 'contacts#objects',   'url' => '/api/contacts/{contactUid}/objects',                              'verb' => 'GET',    'requirements' => ['contactUid' => '[^/]+']],

        // Calendar events — object↔CalDAV event links via DAV principal.
        ['name' => 'calendarEvents#index',     'url' => '/api/objects/{register}/{schema}/{id}/events',                 'verb' => 'GET',    'requirements' => ['id' => '[^/]+']],
        ['name' => 'calendarEvents#create',    'url' => '/api/objects/{register}/{schema}/{id}/events',                 'verb' => 'POST',   'requirements' => ['id' => '[^/]+']],
        ['name' => 'calendarEvents#link',      'url' => '/api/objects/{register}/{schema}/{id}/events/link',            'verb' => 'POST',   'requirements' => ['id' => '[^/]+']],
        ['name' => 'calendarEvents#unlink',    'url' => '/api/objects/{register}/{schema}/{id}/events/{eventUid}/link', 'verb' => 'DELETE', 'requirements' => ['id' => '[^/]+', 'eventUid' => '[^/]+']],
        ['name' => 'calendarEvents#destroy',   'url' => '/api/objects/{register}/{schema}/{id}/events/{eventId}',       'verb' => 'DELETE', 'requirements' => ['id' => '[^/]+', 'eventId' => '[^/]+']],
        // Calendar integration — picker source endpoints (per-user CalDAV scope).
        ['name' => 'calendarEvents#listCalendars',      'url' => '/api/integrations/calendar/calendars',                              'verb' => 'GET'],
        ['name' => 'calendarEvents#listCalendarEvents', 'url' => '/api/integrations/calendar/calendars/{calendarUri}/events',         'verb' => 'GET',    'requirements' => ['calendarUri' => '[^/]+']],

        // Deck — Tier-2 link table + picker UX. Replaces the Tier-1
        // single-endpoint `deck#create` with explicit link/create
        // verbs so the picker can drive a multi-step modal.
        ['name' => 'deckLinks#index',     'url' => '/api/objects/{register}/{schema}/{id}/deck',              'verb' => 'GET',    'requirements' => ['id' => '[^/]+']],
        ['name' => 'deckLinks#link',      'url' => '/api/objects/{register}/{schema}/{id}/deck',              'verb' => 'POST',   'requirements' => ['id' => '[^/]+']],
        ['name' => 'deckLinks#createNew', 'url' => '/api/objects/{register}/{schema}/{id}/deck/new',          'verb' => 'POST',   'requirements' => ['id' => '[^/]+']],
        ['name' => 'deckLinks#destroy',   'url' => '/api/objects/{register}/{schema}/{id}/deck/{cardId}',     'verb' => 'DELETE', 'requirements' => ['id' => '[^/]+', 'cardId' => '[0-9]+']],
        ['name' => 'deckLinks#boards',    'url' => '/api/integrations/deck/boards',                           'verb' => 'GET'],
        ['name' => 'deckLinks#stacks',    'url' => '/api/integrations/deck/boards/{boardId}/stacks',          'verb' => 'GET',    'requirements' => ['boardId' => '[0-9]+']],
        // Schema-level sticky default board+stack (per-schema config, not object data).
        ['name' => 'deckLinks#getDefault', 'url' => '/api/integrations/deck/default/{schema}',                 'verb' => 'GET'],
        ['name' => 'deckLinks#setDefault', 'url' => '/api/integrations/deck/default/{schema}',                 'verb' => 'PUT'],
        // Tier-1 legacy endpoints (superseded by deckLinks; kept for back-compat).
        ['name' => 'deck#index',          'url' => '/api/objects/{register}/{schema}/{id}/deck/cards',         'verb' => 'GET',    'requirements' => ['id' => '[^/]+']],
        ['name' => 'deck#create',         'url' => '/api/objects/{register}/{schema}/{id}/deck/cards',         'verb' => 'POST',   'requirements' => ['id' => '[^/]+']],
        // Reverse lookup — keep on Tier-1 controller (not in Tier-2 scope).
        ['name' => 'deck#objects',        'url' => '/api/deck/boards/{boardId}/objects',                      'verb' => 'GET',    'requirements' => ['boardId' => '[^/]+']],

        // Talk — Tier-2 link table + picker UX. Specific routes (`/new`)
        // MUST precede the wildcard `{roomToken}` route so they aren't
        // grabbed as roomToken='new'. The picker source endpoint is
        // app-global (not per-object).
        ['name' => 'talkLinks#index',     'url' => '/api/objects/{register}/{schema}/{id}/talk',              'verb' => 'GET',    'requirements' => ['id' => '[^/]+']],
        ['name' => 'talkLinks#link',      'url' => '/api/objects/{register}/{schema}/{id}/talk',              'verb' => 'POST',   'requirements' => ['id' => '[^/]+']],
        ['name' => 'talkLinks#createNew', 'url' => '/api/objects/{register}/{schema}/{id}/talk/new',          'verb' => 'POST',   'requirements' => ['id' => '[^/]+']],
        ['name' => 'talkLinks#destroy',   'url' => '/api/objects/{register}/{schema}/{id}/talk/{roomToken}',  'verb' => 'DELETE', 'requirements' => ['id' => '[^/]+', 'roomToken' => '[A-Za-z0-9]+']],
        ['name' => 'talkLinks#rooms',     'url' => '/api/integrations/talk/rooms',                            'verb' => 'GET'],

        // Polls — Tier-2 link table + picker UX. Specific routes (`/new`)
        // MUST precede the wildcard `{pollId}` route so they aren't grabbed
        // as pollId='new'. Available-polls picker is app-global.
        ['name' => 'pollLinks#available', 'url' => '/api/integrations/polls/available',                       'verb' => 'GET'],
        ['name' => 'pollLinks#index',     'url' => '/api/objects/{register}/{schema}/{id}/polls',             'verb' => 'GET',    'requirements' => ['id' => '[^/]+']],
        ['name' => 'pollLinks#link',      'url' => '/api/objects/{register}/{schema}/{id}/polls',             'verb' => 'POST',   'requirements' => ['id' => '[^/]+']],
        ['name' => 'pollLinks#createNew', 'url' => '/api/objects/{register}/{schema}/{id}/polls/new',         'verb' => 'POST',   'requirements' => ['id' => '[^/]+']],
        ['name' => 'pollLinks#destroy',   'url' => '/api/objects/{register}/{schema}/{id}/polls/{pollId}',    'verb' => 'DELETE', 'requirements' => ['id' => '[^/]+', 'pollId' => '[0-9]+']],

        // Bookmarks — Tier-2 link table + picker UX. Specific routes
        // (`/new`) MUST precede the wildcard `{bookmarkId}` route so they
        // aren't grabbed as bookmarkId='new'. Available-bookmarks picker
        // is app-global.
        ['name' => 'bookmarkLinks#available', 'url' => '/api/integrations/bookmarks/available',                       'verb' => 'GET'],
        ['name' => 'bookmarkLinks#index',     'url' => '/api/objects/{register}/{schema}/{id}/bookmarks',             'verb' => 'GET',    'requirements' => ['id' => '[^/]+']],
        ['name' => 'bookmarkLinks#link',      'url' => '/api/objects/{register}/{schema}/{id}/bookmarks',             'verb' => 'POST',   'requirements' => ['id' => '[^/]+']],
        ['name' => 'bookmarkLinks#createNew', 'url' => '/api/objects/{register}/{schema}/{id}/bookmarks/new',         'verb' => 'POST',   'requirements' => ['id' => '[^/]+']],
        ['name' => 'bookmarkLinks#destroy',   'url' => '/api/objects/{register}/{schema}/{id}/bookmarks/{bookmarkId}', 'verb' => 'DELETE', 'requirements' => ['id' => '[^/]+', 'bookmarkId' => '[0-9]+']],

        // Shares — Tier-2: NO link table, NO cache. Every endpoint wraps
        // OCP\Share\IManager (NC core sharing is the single source of
        // truth). The shareable-files picker source is app-global but
        // object-scoped (it lists files inside the object's folder), so
        // the specific `/api/integrations/shares/files/...` route is
        // declared before the per-object wildcard `{shareId}` route.
        ['name' => 'shareLinks#files',   'url' => '/api/integrations/shares/files/{register}/{schema}/{id}', 'verb' => 'GET',    'requirements' => ['id' => '[^/]+']],
        ['name' => 'shareLinks#index',   'url' => '/api/objects/{register}/{schema}/{id}/shares',            'verb' => 'GET',    'requirements' => ['id' => '[^/]+']],
        ['name' => 'shareLinks#create',  'url' => '/api/objects/{register}/{schema}/{id}/shares',            'verb' => 'POST',   'requirements' => ['id' => '[^/]+']],
        ['name' => 'shareLinks#destroy', 'url' => '/api/objects/{register}/{schema}/{id}/shares/{shareId}',  'verb' => 'DELETE', 'requirements' => ['id' => '[^/]+', 'shareId' => '[^/]+']],

        // Flow (workflowengine) — Tier-2 link-table API. Admin-gated:
        // POST/DELETE return 403 for non-admins; GET is read-only for
        // everyone. NC Flow operations are configured globally in the
        // Workflow Settings UI; this surface only records "operation X
        // is pinned to OR object Y" so the sidebar tab can show it.
        // Visual flow builder — trigger event catalog (read-only, all users).
        ['name' => 'flow#eventCatalog', 'url' => '/api/flow/event-catalog', 'verb' => 'GET'],
        ['name' => 'flow#nodeCatalog',  'url' => '/api/flow/node-catalog',  'verb' => 'GET'],
        // The links one run-log entry earns, asked of the node that wrote it.
        // POST because the entry is the input and a log entry carries payloads
        // — a GET would put a run's data in a URL, and in every access log that
        // URL passes through.
        ['name' => 'flow#logActions', 'url' => '/api/flow/log-actions', 'verb' => 'POST'],
        // Preflight a flow document against the live node registry WITHOUT
        // saving it — the question a CI job or a deploy check needs to ask
        // about a document it is not writing. Must stay above `{flowId}` so
        // "validate" is never captured as a flow uuid.
        ['name' => 'flow#validate',     'url' => '/api/flow/validate',      'verb' => 'POST'],
        // What a flow is holding between runs — the read side of flow state,
        // so a dashboard can render slot occupancy (or#2216).
        ['name' => 'flow#state',        'url' => '/api/flow/{flowId}/state', 'verb' => 'GET', 'requirements' => ['flowId' => '[^/]+']],

        // Flow definitions — the one native store every app's builder reads and
        // writes (flow-engine-unification). PLURAL `/api/flows`, deliberately
        // distinct from the singular `/api/flow/...` catalog surface above, so
        // neither can ever capture the other's paths.
        //
        // `{id}/run` is declared BEFORE `{id}` so a POST to a run URL is never
        // matched as an update of a flow whose uuid happens to end in "/run".
        //
        // All six are `#[NoAdminRequired]`: flows are per-organisation, not
        // per-instance, so admin-gating them would make the feature unusable
        // for the tenants it exists for. The authorisation that matters is the
        // organisation scoping and per-flow guard inside FlowService.
        ['name' => 'flow#run',     'url' => '/api/flows/{id}/run', 'verb' => 'POST',   'requirements' => ['id' => '[^/]+']],

        // Lifecycle. Declared BEFORE the bare `{id}` routes for the same reason
        // `{id}/run` is: `id` matches `[^/]+`, so a uuid can never swallow a
        // trailing literal segment, but keeping the specific paths first means
        // a future looser requirement cannot silently start capturing them.
        //
        // The VERSION number is `\d+`, not `[^/]+`. Without that,
        // `/versions/publish` would match `version` with the literal string
        // "publish" and return a 404 for a route that exists.
        ['name' => 'flow#versions',  'url' => '/api/flows/{id}/versions',            'verb' => 'GET',  'requirements' => ['id' => '[^/]+']],
        ['name' => 'flow#version',   'url' => '/api/flows/{id}/versions/{version}',  'verb' => 'GET',  'requirements' => ['id' => '[^/]+', 'version' => '\d+']],
        ['name' => 'flow#publish',   'url' => '/api/flows/{id}/publish',             'verb' => 'POST', 'requirements' => ['id' => '[^/]+']],
        ['name' => 'flow#draft',     'url' => '/api/flows/{id}/draft',               'verb' => 'POST', 'requirements' => ['id' => '[^/]+']],
        ['name' => 'flow#deprecate', 'url' => '/api/flows/{id}/deprecate',           'verb' => 'POST', 'requirements' => ['id' => '[^/]+']],
        ['name' => 'flow#index',   'url' => '/api/flows',          'verb' => 'GET'],
        ['name' => 'flow#create',  'url' => '/api/flows',          'verb' => 'POST'],
        ['name' => 'flow#show',    'url' => '/api/flows/{id}',     'verb' => 'GET',    'requirements' => ['id' => '[^/]+']],
        ['name' => 'flow#update',  'url' => '/api/flows/{id}',     'verb' => 'PUT',    'requirements' => ['id' => '[^/]+']],
        ['name' => 'flow#destroy', 'url' => '/api/flows/{id}',     'verb' => 'DELETE', 'requirements' => ['id' => '[^/]+']],

        ['name' => 'flowLinks#available', 'url' => '/api/integrations/flow/operations',                       'verb' => 'GET'],
        ['name' => 'flowLinks#index',     'url' => '/api/objects/{register}/{schema}/{id}/flow',              'verb' => 'GET',    'requirements' => ['id' => '[^/]+']],
        ['name' => 'flowLinks#link',      'url' => '/api/objects/{register}/{schema}/{id}/flow',              'verb' => 'POST',   'requirements' => ['id' => '[^/]+']],
        ['name' => 'flowLinks#destroy',   'url' => '/api/objects/{register}/{schema}/{id}/flow/{operationId}', 'verb' => 'DELETE', 'requirements' => ['id' => '[^/]+', 'operationId' => '[0-9]+']],

        // Photos (NC Photos) — Tier-2 link-table API. User-scoped (no
        // admin gate). The specific `/photos/new` (create + link) route
        // MUST precede the wildcard `/photos/{albumId}` unlink route.
        ['name' => 'photoLinks#available',    'url' => '/api/integrations/photos/available',                   'verb' => 'GET'],
        ['name' => 'photoLinks#index',        'url' => '/api/objects/{register}/{schema}/{id}/photos',         'verb' => 'GET',    'requirements' => ['id' => '[^/]+']],
        ['name' => 'photoLinks#createAndLink','url' => '/api/objects/{register}/{schema}/{id}/photos/new',     'verb' => 'POST',   'requirements' => ['id' => '[^/]+']],
        ['name' => 'photoLinks#link',         'url' => '/api/objects/{register}/{schema}/{id}/photos',         'verb' => 'POST',   'requirements' => ['id' => '[^/]+']],
        ['name' => 'photoLinks#destroy',      'url' => '/api/objects/{register}/{schema}/{id}/photos/{albumId}', 'verb' => 'DELETE', 'requirements' => ['id' => '[^/]+', 'albumId' => '[0-9]+']],

        // Collectives (NC Knowledge) — Tier-2 link-table API. User-scoped
        // (no admin gate). The specific `/collectives/new` (create + link)
        // route MUST precede the wildcard `/collectives/{pageId}` unlink
        // route, and the app-global `available`/`list` routes precede the
        // object-scoped routes.
        ['name' => 'collectiveLinks#available',    'url' => '/api/integrations/collectives/available',                  'verb' => 'GET'],
        ['name' => 'collectiveLinks#collectives',  'url' => '/api/integrations/collectives/list',                       'verb' => 'GET'],
        ['name' => 'collectiveLinks#index',        'url' => '/api/objects/{register}/{schema}/{id}/collectives',        'verb' => 'GET',    'requirements' => ['id' => '[^/]+']],
        ['name' => 'collectiveLinks#createAndLink','url' => '/api/objects/{register}/{schema}/{id}/collectives/new',    'verb' => 'POST',   'requirements' => ['id' => '[^/]+']],
        ['name' => 'collectiveLinks#link',         'url' => '/api/objects/{register}/{schema}/{id}/collectives',        'verb' => 'POST',   'requirements' => ['id' => '[^/]+']],
        ['name' => 'collectiveLinks#destroy',      'url' => '/api/objects/{register}/{schema}/{id}/collectives/{pageId}', 'verb' => 'DELETE', 'requirements' => ['id' => '[^/]+', 'pageId' => '[0-9]+']],

        // xWiki (remote, OpenConnector-routed) — Tier-2 link-table API.
        // External: no NC app gate; the OpenConnector `xwiki` source carries
        // credentials. The specific `/xwiki/new` (create + link) route MUST
        // precede the wildcard `/xwiki/{pageRef}` unlink route, and the
        // app-global `available` route precedes the object-scoped routes.
        // pageRef is a url-encoded canonical page reference (`%2F` not `/`),
        // so the `[^/]+` requirement matches the whole segment.
        ['name' => 'xwikiLinks#available',    'url' => '/api/integrations/xwiki/available',                  'verb' => 'GET'],
        ['name' => 'xwikiLinks#search',       'url' => '/api/integrations/xwiki/search',                     'verb' => 'GET'],
        ['name' => 'xwikiLinks#index',        'url' => '/api/objects/{register}/{schema}/{id}/xwiki',        'verb' => 'GET',    'requirements' => ['id' => '[^/]+']],
        ['name' => 'xwikiLinks#createAndLink','url' => '/api/objects/{register}/{schema}/{id}/xwiki/new',    'verb' => 'POST',   'requirements' => ['id' => '[^/]+']],
        ['name' => 'xwikiLinks#link',         'url' => '/api/objects/{register}/{schema}/{id}/xwiki',        'verb' => 'POST',   'requirements' => ['id' => '[^/]+']],
        ['name' => 'xwikiLinks#destroy',      'url' => '/api/objects/{register}/{schema}/{id}/xwiki/{pageRef}', 'verb' => 'DELETE', 'requirements' => ['id' => '[^/]+', 'pageRef' => '[^/]+']],

        // KvK + OpenCorporates company lookup (external, OpenConnector-routed)
        // — read-only, object-independent company-lookup leaves. No NC app
        // gate; the OpenConnector `kvk` / `opencorporates` sources carry the
        // base URL + API key. Unconfigured/down → 503 with details.cause.
        // @spec openspec/changes/integration-kvk-opencorporates/specs/integration-company-lookup/spec.md.
        ['name' => 'companyLookup#kvkCompany',           'url' => '/api/integrations/kvk/company',            'verb' => 'GET'],
        ['name' => 'companyLookup#kvkSearch',            'url' => '/api/integrations/kvk/search',             'verb' => 'GET'],
        ['name' => 'companyLookup#openCorporatesSearch', 'url' => '/api/integrations/opencorporates/search',  'verb' => 'GET'],
        // BRP HaalCentraal person lookup (external, OpenConnector-routed) —
        // read-only, object-independent person-lookup leaf. No NC app gate; the
        // OpenConnector `brp-haalcentraal` source carries the base URL + OAuth2
        // client_credentials secret + PKIoverheid mutual-TLS client certificate
        // (both applied natively by CallService). Unconfigured/down → 503 with
        // details.cause. The BSN travels in the request body only, never logged.
        // @spec openspec/changes/integration-brp-haalcentraal/specs/integration-person-lookup/spec.md.
        ['name' => 'personLookup#brpPerson',             'url' => '/api/integrations/brp/person',             'verb' => 'GET'],
        // Outbound-messaging dispatch (external, OpenConnector-routed) —
        // side-effecting send leaf. No NC app gate; the OpenConnector
        // cmcom-sms / messagebird-sms / twilio-sms (SMS) and
        // whatsapp-cloud-api / whatsapp-bsp (WhatsApp) sources carry the base
        // URL + provider credential. The consuming app (pipelinq) composes the
        // vendor-shaped body + path and owns all orchestration (provider
        // selection, STOP opt-out, template-approval, 24h session, dedupe,
        // delivery-status); this leaf only POSTs the message. Unconfigured/down
        // → 503 with details.cause.
        // @spec openspec/changes/messaging-dispatch-leaf/specs/integration-message-dispatch/spec.md.
        ['name' => 'messageDispatch#smsSend',            'url' => '/api/integrations/sms/send',               'verb' => 'POST'],
        ['name' => 'messageDispatch#whatsappSend',       'url' => '/api/integrations/whatsapp/send',          'verb' => 'POST'],
        // Cospend (NC Costs) — Tier-2 link-table API. User-scoped (no
        // admin gate). The specific `/cospend/new` (create + link) route
        // MUST precede the wildcard `/cospend/{entryId}` unlink route, and
        // the app-global `available` route precedes the object-scoped
        // routes. The link POST handles BOTH project and bill rows
        // (discriminated by a `billId`/`entryType` in the body).
        ['name' => 'cospendLinks#available',    'url' => '/api/integrations/cospend/available',                  'verb' => 'GET'],
        ['name' => 'cospendLinks#index',        'url' => '/api/objects/{register}/{schema}/{id}/cospend',        'verb' => 'GET',    'requirements' => ['id' => '[^/]+']],
        ['name' => 'cospendLinks#createAndLink','url' => '/api/objects/{register}/{schema}/{id}/cospend/new',    'verb' => 'POST',   'requirements' => ['id' => '[^/]+']],
        ['name' => 'cospendLinks#link',         'url' => '/api/objects/{register}/{schema}/{id}/cospend',        'verb' => 'POST',   'requirements' => ['id' => '[^/]+']],
        ['name' => 'cospendLinks#destroy',      'url' => '/api/objects/{register}/{schema}/{id}/cospend/{entryId}', 'verb' => 'DELETE', 'requirements' => ['id' => '[^/]+', 'entryId' => '[0-9]+']],

        // OpenProject (external / OpenConnector-routed) — Tier-2 link-table
        // API. The picker source `available` is reached through the
        // OpenConnector `openproject` source. The specific
        // `/openproject/new` (create + link) route MUST precede the
        // wildcard `/openproject/{wpId}` unlink route, and the app-global
        // `available` route precedes the object-scoped routes.
        ['name' => 'openProjectLinks#available',    'url' => '/api/integrations/openproject/available',                  'verb' => 'GET'],
        ['name' => 'openProjectLinks#index',        'url' => '/api/objects/{register}/{schema}/{id}/openproject',        'verb' => 'GET',    'requirements' => ['id' => '[^/]+']],
        ['name' => 'openProjectLinks#createAndLink','url' => '/api/objects/{register}/{schema}/{id}/openproject/new',    'verb' => 'POST',   'requirements' => ['id' => '[^/]+']],
        ['name' => 'openProjectLinks#link',         'url' => '/api/objects/{register}/{schema}/{id}/openproject',        'verb' => 'POST',   'requirements' => ['id' => '[^/]+']],
        ['name' => 'openProjectLinks#destroy',      'url' => '/api/objects/{register}/{schema}/{id}/openproject/{wpId}', 'verb' => 'DELETE', 'requirements' => ['id' => '[^/]+', 'wpId' => '[0-9]+']],

        // Maps (NC Maps / Location) — Tier-2 link-table API. User-scoped
        // (no admin gate). The specific `/maps/new` (create + link) route
        // MUST precede the wildcard `/maps/{favoriteId}` unlink route.
        ['name' => 'mapLinks#available',    'url' => '/api/integrations/maps/available',                       'verb' => 'GET'],
        ['name' => 'mapLinks#index',        'url' => '/api/objects/{register}/{schema}/{id}/maps',             'verb' => 'GET',    'requirements' => ['id' => '[^/]+']],
        ['name' => 'mapLinks#createAndLink','url' => '/api/objects/{register}/{schema}/{id}/maps/new',         'verb' => 'POST',   'requirements' => ['id' => '[^/]+']],
        ['name' => 'mapLinks#link',         'url' => '/api/objects/{register}/{schema}/{id}/maps',             'verb' => 'POST',   'requirements' => ['id' => '[^/]+']],
        ['name' => 'mapLinks#destroy',      'url' => '/api/objects/{register}/{schema}/{id}/maps/{favoriteId}', 'verb' => 'DELETE', 'requirements' => ['id' => '[^/]+', 'favoriteId' => '[0-9]+']],

        // Time-tracker (NC TimeManager) — Tier-2 link-table API. User-scoped
        // (no admin gate). The leaf slug is `time-tracker` (hyphen); the NC
        // app id is `timemanager`. The specific `/time-tracker/new` (create +
        // link client) route MUST precede the wildcard
        // `/time-tracker/{entryId}` unlink route, and the app-global
        // `available` route precedes the object-scoped routes. entryId is a
        // TimeManager uuid (not numeric), so it matches `[^/]+`.
        ['name' => 'timeTrackerLinks#available',    'url' => '/api/integrations/time-tracker/available',                    'verb' => 'GET'],
        ['name' => 'timeTrackerLinks#index',        'url' => '/api/objects/{register}/{schema}/{id}/time-tracker',          'verb' => 'GET',    'requirements' => ['id' => '[^/]+']],
        ['name' => 'timeTrackerLinks#createAndLink','url' => '/api/objects/{register}/{schema}/{id}/time-tracker/new',      'verb' => 'POST',   'requirements' => ['id' => '[^/]+']],
        ['name' => 'timeTrackerLinks#link',         'url' => '/api/objects/{register}/{schema}/{id}/time-tracker',          'verb' => 'POST',   'requirements' => ['id' => '[^/]+']],
        ['name' => 'timeTrackerLinks#destroy',      'url' => '/api/objects/{register}/{schema}/{id}/time-tracker/{entryId}', 'verb' => 'DELETE', 'requirements' => ['id' => '[^/]+', 'entryId' => '[^/]+']],

        // Analytics (NC Analytics) — Tier-2 link-table API. User-scoped
        // (no admin gate). The specific `/analytics/new` (create + link)
        // route MUST precede the wildcard `/analytics/{reportId}` unlink
        // route, and the app-global `available` picker route MUST precede
        // the per-object wildcard routes.
        // @spec openspec/changes/integration-analytics/tasks.md.
        ['name' => 'analyticsLinks#available',    'url' => '/api/integrations/analytics/available',                  'verb' => 'GET'],
        ['name' => 'analyticsLinks#index',        'url' => '/api/objects/{register}/{schema}/{id}/analytics',        'verb' => 'GET',    'requirements' => ['id' => '[^/]+']],
        ['name' => 'analyticsLinks#createAndLink','url' => '/api/objects/{register}/{schema}/{id}/analytics/new',    'verb' => 'POST',   'requirements' => ['id' => '[^/]+']],
        ['name' => 'analyticsLinks#link',         'url' => '/api/objects/{register}/{schema}/{id}/analytics',        'verb' => 'POST',   'requirements' => ['id' => '[^/]+']],
        ['name' => 'analyticsLinks#destroy',      'url' => '/api/objects/{register}/{schema}/{id}/analytics/{reportId}', 'verb' => 'DELETE', 'requirements' => ['id' => '[^/]+', 'reportId' => '[0-9]+']],

        // Analytics page-level series — leaf-foundation render surface.
        // A leaf (procest SLA dashboard) registers a pre-computed series
        // (labels + datasets); the render layer fetches it as a chart
        // widget. RBAC-scoped inside AnalyticsSeriesService.
        // @spec openspec/changes/integration-leaf-foundation-shares-analytics/specs/integration-leaf-foundation/spec.md.
        ['name' => 'analyticsSeries#register', 'url' => '/api/integrations/analytics/series',              'verb' => 'POST'],
        ['name' => 'analyticsSeries#fetch',    'url' => '/api/integrations/analytics/series/{seriesKey}',  'verb' => 'GET',  'requirements' => ['seriesKey' => '[^/]+']],

        // Maps page-level overview — multi-object "cases on map" render
        // surface (procest issue #112). register declares a `map` page
        // widget; points queries the RBAC-scoped marker set for a
        // register/schema. RBAC enforced inside MapsOverviewService via the
        // canonical OR read path (_rbac:true for non-admins, fail-closed).
        // @spec openspec/changes/integration-maps-overview-page-surface/specs/integration-maps-overview/spec.md.
        ['name' => 'mapsOverview#register', 'url' => '/api/integrations/maps/overviews',                            'verb' => 'POST'],
        ['name' => 'mapsOverview#points',   'url' => '/api/integrations/maps/overviews/{register}/{schema}/points', 'verb' => 'GET', 'requirements' => ['register' => '[^/]+', 'schema' => '[^/]+']],

        // Public "track your case" token resolve — anonymous, RBAC-scoped
        // public-safe object view minted via the Shares integration
        // provider. Fails closed (404) on unknown/revoked/expired tokens.
        // @spec openspec/changes/integration-leaf-foundation-shares-analytics/specs/integration-leaf-foundation/spec.md.
        ['name' => 'caseToken#resolve', 'url' => '/api/public/case-tokens/{token}', 'verb' => 'GET', 'requirements' => ['token' => '[^/]+']],

        // Vocabulary (skos-concept-registers) — public read-only SKOS concept
        // resolution over the bundled `vocabulary` register. Query-param based
        // (uri/scheme values are full URIs, unsafe as path segments). 404
        // standard error shape on unknown uri/scheme/notation (SKOS-004).
        // @spec openspec/changes/skos-concept-registers/specs/skos-concept-registers/spec.md#skos-004
        ['name' => 'vocabulary#resolveByUri', 'url' => '/api/vocabulary/concept', 'verb' => 'GET'],
        ['name' => 'vocabulary#resolveByNotation', 'url' => '/api/vocabulary/concept/notation', 'verb' => 'GET'],
        ['name' => 'vocabulary#listConcepts', 'url' => '/api/vocabulary/concepts', 'verb' => 'GET'],

        // Activity — Tier-2 read-only API. NC Activity entries are
        // core-generated (no link/create/delete verbs); this surface
        // only filters + cursor-paginates the entries linked to an OR
        // object via the `[or:{uuid}]` marker in `activity.subject`
        // (wave-5.3 MarkerLookupTrait carve-out, preserved). The
        // app-global `types`/`actors` dropdown routes MUST precede the
        // per-object wildcard route so they aren't grabbed as register
        // slugs.
        // @spec openspec/changes/integration-activity/tasks.md.
        ['name' => 'activityLinks#types',  'url' => '/api/integrations/activity/types',                  'verb' => 'GET'],
        ['name' => 'activityLinks#actors', 'url' => '/api/integrations/activity/actors',                 'verb' => 'GET'],
        ['name' => 'activityLinks#index',  'url' => '/api/objects/{register}/{schema}/{id}/activity',    'verb' => 'GET', 'requirements' => ['id' => '[^/]+']],

        // Forms — Tier-2 link-table API. Specific routes (`/new`, `/submissions/{id}`)
        // MUST precede the wildcard `{formId}` route so they aren't grabbed as
        // formId='new' / formId='submissions'. Available-forms picker is app-global.
        [
            'name' => 'formLinks#available',
            'url'  => '/api/integrations/forms/available',
            'verb' => 'GET',
        ],
        [
            'name'         => 'formLinks#index',
            'url'          => '/api/objects/{register}/{schema}/{id}/forms',
            'verb'         => 'GET',
            'requirements' => ['id' => '[^/]+'],
        ],
        [
            'name'         => 'formLinks#create',
            'url'          => '/api/objects/{register}/{schema}/{id}/forms/new',
            'verb'         => 'POST',
            'requirements' => ['id' => '[^/]+'],
        ],
        [
            'name'         => 'formLinks#link',
            'url'          => '/api/objects/{register}/{schema}/{id}/forms',
            'verb'         => 'POST',
            'requirements' => ['id' => '[^/]+'],
        ],
        [
            'name'         => 'formLinks#destroySubmission',
            'url'          => '/api/objects/{register}/{schema}/{id}/forms/{formId}/submissions/{submissionId}',
            'verb'         => 'DELETE',
            'requirements' => [
                'id'           => '[^/]+',
                'formId'       => '[0-9]+',
                'submissionId' => '[0-9]+',
            ],
        ],
        [
            'name'         => 'formLinks#destroyForm',
            'url'          => '/api/objects/{register}/{schema}/{id}/forms/{formId}',
            'verb'         => 'DELETE',
            'requirements' => ['id' => '[^/]+', 'formId' => '[0-9]+'],
        ],

        // Unified relations endpoint — aggregates emails/contacts/calendar/deck for an object.
        ['name' => 'relations#index', 'url' => '/api/objects/{register}/{schema}/{id}/relations',          'verb' => 'GET',    'requirements' => ['id' => '[^/]+']],

        // Linked-entity-types — generic per-{type} link API (mail / event / contact / deck).
        ['name' => 'linkedEntity#addObjectLink',    'url' => '/api/objects/{uuid}/_{type}',           'verb' => 'POST',   'requirements' => ['uuid' => '[^/]+', 'type' => '[a-z]+']],
        ['name' => 'linkedEntity#removeObjectLink', 'url' => '/api/objects/{uuid}/_{type}/{entityId}','verb' => 'DELETE', 'requirements' => ['uuid' => '[^/]+', 'type' => '[a-z]+', 'entityId' => '.+']],
        ['name' => 'linkedEntity#addRegisterLink',  'url' => '/api/registers/{uuid}/_{type}',         'verb' => 'POST',   'requirements' => ['uuid' => '[^/]+', 'type' => '[a-z]+']],
        ['name' => 'linkedEntity#addSchemaLink',    'url' => '/api/schemas/{uuid}/_{type}',           'verb' => 'POST',   'requirements' => ['uuid' => '[^/]+', 'type' => '[a-z]+']],
        ['name' => 'linkedEntity#reverseLookup',    'url' => '/api/linked/_{type}/{entityId}',        'verb' => 'GET',    'requirements' => ['type' => '[a-z]+', 'entityId' => '.+']],

        // TMLO metadata export endpoints (declarative archival metadata per Dutch TMLO standard).
        ['name' => 'tmlo#summary',      'url' => '/api/tmlo/{register}/{schema}/summary',                'verb' => 'GET'],
        ['name' => 'tmlo#exportSingle', 'url' => '/api/tmlo/{register}/{schema}/{id}/export',            'verb' => 'GET',  'requirements' => ['id' => '[^/]+']],
        ['name' => 'tmlo#exportBatch',  'url' => '/api/tmlo/{register}/{schema}/export',                 'verb' => 'GET'],

        // FileSidebar — list OR objects connected to a Files entry + show extraction state.
        ['name' => 'fileSidebar#getObjectsForFile',    'url' => '/api/files/{fileId}/objects',           'verb' => 'GET',  'requirements' => ['fileId' => '[0-9]+']],
        ['name' => 'fileSidebar#getExtractionStatus',  'url' => '/api/files/{fileId}/extraction-status', 'verb' => 'GET',  'requirements' => ['fileId' => '[0-9]+']],

        // Action registry CRUD + utilities.
        ['name' => 'actions#index',            'url' => '/api/actions',                          'verb' => 'GET'],
        ['name' => 'actions#create',           'url' => '/api/actions',                          'verb' => 'POST'],
        ['name' => 'actions#show',             'url' => '/api/actions/{id}',                     'verb' => 'GET',    'requirements' => ['id' => '[0-9]+']],
        ['name' => 'actions#update',           'url' => '/api/actions/{id}',                     'verb' => 'PUT',    'requirements' => ['id' => '[0-9]+']],
        ['name' => 'actions#patch',            'url' => '/api/actions/{id}',                     'verb' => 'PATCH',  'requirements' => ['id' => '[0-9]+']],
        ['name' => 'actions#destroy',          'url' => '/api/actions/{id}',                     'verb' => 'DELETE', 'requirements' => ['id' => '[0-9]+']],
        ['name' => 'actions#test',             'url' => '/api/actions/{id}/test',                'verb' => 'POST',   'requirements' => ['id' => '[0-9]+']],
        ['name' => 'actions#logs',             'url' => '/api/actions/{id}/logs',                'verb' => 'GET',    'requirements' => ['id' => '[0-9]+']],
        ['name' => 'actions#migrateFromHooks', 'url' => '/api/actions/migrate-hooks/{schemaId}', 'verb' => 'POST',   'requirements' => ['schemaId' => '[0-9]+']],

        ['name' => 'objects#index', 'url' => '/api/objects/{register}/{schema}', 'verb' => 'GET'],

        ['name' => 'objects#geoSearch', 'url' => '/api/objects/{register}/{schema}/geo-search', 'verb' => 'POST'],
        ['name' => 'objects#geoJson', 'url' => '/api/geo/{register}/{schema}/geojson', 'verb' => 'GET'],
        ['name' => 'objects#wfs', 'url' => '/api/geo/{register}/{schema}/wfs', 'verb' => 'GET'],
        ['name' => 'objects#geocode', 'url' => '/api/geo/geocode', 'verb' => 'GET'],

        ['name' => 'objects#create', 'url' => '/api/objects/{register}/{schema}', 'verb' => 'POST'],
        ['name' => 'objects#export', 'url' => '/api/objects/{register}/{schema}/export', 'verb' => 'GET'],
        ['name' => 'objects#show', 'url' => '/api/objects/{register}/{schema}/{id}', 'verb' => 'GET', 'requirements' => ['id' => '[^/]+']],
        ['name' => 'objects#update', 'url' => '/api/objects/{register}/{schema}/{id}', 'verb' => 'PUT', 'requirements' => ['id' => '[^/]+']],
        ['name' => 'objects#patch', 'url' => '/api/objects/{register}/{schema}/{id}', 'verb' => 'PATCH', 'requirements' => ['id' => '[^/]+']],
        ['name' => 'objects#postPatch', 'url' => '/api/objects/{register}/{schema}/{id}', 'verb' => 'POST', 'requirements' => ['id' => '[^/]+']],
        ['name' => 'objects#destroy', 'url' => '/api/objects/{register}/{schema}/{id}', 'verb' => 'DELETE', 'requirements' => ['id' => '[^/]+']],
        ['name' => 'objects#canDelete', 'url' => '/api/objects/{register}/{schema}/{id}/can-delete', 'verb' => 'GET', 'requirements' => ['id' => '[^/]+']],
        ['name' => 'objects#merge', 'url' => '/api/objects/{register}/{schema}/{id}/merge', 'verb' => 'POST', 'requirements' => ['id' => '[^/]+']],
        ['name' => 'objects#migrate', 'url' => '/api/migrate', 'verb' => 'POST'],
        // Relations.
        ['name' => 'objects#contracts', 'url' => '/api/objects/{register}/{schema}/{id}/contracts', 'verb' => 'GET', 'requirements' => ['id' => '[^/]+']],
        ['name' => 'objects#uses',      'url' => '/api/objects/{register}/{schema}/{id}/uses',      'verb' => 'GET', 'requirements' => ['id' => '[^/]+']],
        ['name' => 'objects#used',      'url' => '/api/objects/{register}/{schema}/{id}/used',      'verb' => 'GET', 'requirements' => ['id' => '[^/]+']],
        ['name' => 'objects#logs',      'url' => '/api/objects/{register}/{schema}/{id}/logs',      'verb' => 'GET', 'requirements' => ['id' => '[^/]+']],
        // Locks.
        ['name' => 'objects#lock', 'url' => '/api/objects/{register}/{schema}/{id}/lock', 'verb' => 'POST', 'requirements' => ['id' => '[^/]+']],
        ['name' => 'objects#unlock', 'url' => '/api/objects/{register}/{schema}/{id}/unlock', 'verb' => 'POST', 'requirements' => ['id' => '[^/]+']],
        // Bulk Operations.
        ['name' => 'bulk#save', 'url' => '/api/bulk/{register}/{schema}/save', 'verb' => 'POST'],
        ['name' => 'bulk#delete', 'url' => '/api/bulk/{register}/{schema}/delete', 'verb' => 'POST'],
        ['name' => 'bulk#deleteSchema', 'url' => '/api/bulk/{register}/{schema}/delete-schema', 'verb' => 'POST'],
        ['name' => 'bulk#deleteSchemaObjects', 'url' => '/api/bulk/{register}/{schema}/delete-objects', 'verb' => 'POST'],
        ['name' => 'bulk#deleteRegister', 'url' => '/api/bulk/{register}/delete-register', 'verb' => 'POST'],
        ['name' => 'bulk#runSchemaValidation', 'url' => '/api/bulk/schema/{schema}/validate', 'verb' => 'POST'],
        // Audit Trails — specific routes MUST come before parameterized {id} routes.
        ['name' => 'auditTrail#objects', 'url' => '/api/objects/{register}/{schema}/{id}/audit-trails', 'verb' => 'GET', 'requirements' => ['id' => '[^/]+']],
        ['name' => 'auditTrail#index', 'url' => '/api/audit-trails', 'verb' => 'GET'],
        ['name' => 'auditTrail#statistics', 'url' => '/api/audit-trails/statistics', 'verb' => 'GET'],
        ['name' => 'auditTrail#export', 'url' => '/api/audit-trails/export', 'verb' => 'GET'],
        ['name' => 'auditTrail#verify', 'url' => '/api/audit-trails/verify', 'verb' => 'GET'],
        ['name' => 'auditTrail#integrity', 'url' => '/api/audit-trails/integrity', 'verb' => 'GET'],
        ['name' => 'auditTrail#processingActivities', 'url' => '/api/audit-trails/processing-activities', 'verb' => 'GET'],
        ['name' => 'auditTrail#subjectAuditTrail', 'url' => '/api/audit-trails/subject-lookup', 'verb' => 'GET'],
        ['name' => 'auditTrail#clearAll', 'url' => '/api/audit-trails/clear-all', 'verb' => 'DELETE'],
        ['name' => 'auditTrail#show', 'url' => '/api/audit-trails/{id}', 'verb' => 'GET', 'requirements' => ['id' => '[^/]+']],
        ['name' => 'auditTrail#update', 'url' => '/api/audit-trails/{id}', 'verb' => 'PUT', 'requirements' => ['id' => '[^/]+']],
        ['name' => 'auditTrail#destroy', 'url' => '/api/audit-trails/{id}', 'verb' => 'DELETE', 'requirements' => ['id' => '[^/]+']],
        ['name' => 'auditTrail#destroyMultiple', 'url' => '/api/audit-trails', 'verb' => 'DELETE'],
        // Audit Query (v2) — unified, cross-app query/export of audit-entry
        // objects (e.g. procest's aiAuditEntry, parafering's paraferingAuditEntry).
        // Distinct from Audit Trails above (OR's own object-mutation log);
        // this queries app-defined audit-entry OBJECTS via ObjectService.
        // /export MUST come before the (paramless) query route is irrelevant
        // here since both are static paths, but kept in the same specific-first
        // order as the audit-trails block above for consistency.
        ['name' => 'auditQuery#export', 'url' => '/api/v2/audit/export', 'verb' => 'GET'],
        ['name' => 'auditQuery#query', 'url' => '/api/v2/audit', 'verb' => 'GET'],
        // Notification History — read-only audit trail of every dispatch.
        ['name' => 'notificationHistory#index', 'url' => '/api/notification-history', 'verb' => 'GET'],
        // Notification Subscriptions — DEPRECATED per-user (register, schema) opt-in surface.
        // Superseded by override-only Notification Preferences below; kept during the deprecation window.
        ['name' => 'notificationSubscriptions#index',   'url' => '/api/notification-subscriptions', 'verb' => 'GET'],
        ['name' => 'notificationSubscriptions#create',  'url' => '/api/notification-subscriptions', 'verb' => 'POST'],
        ['name' => 'notificationSubscriptions#destroy', 'url' => '/api/notification-subscriptions', 'verb' => 'DELETE'],
        // Notification Preferences — override-only, per-(schema, notification) user preferences.
        ['name' => 'notificationPreferences#index',  'url' => '/api/notification-preferences', 'verb' => 'GET'],
        ['name' => 'notificationPreferences#update', 'url' => '/api/notification-preferences', 'verb' => 'PUT'],
        // Notification Delivery Window — override-only, per-user quiet-hours preference.
        ['name' => 'notificationDeliveryWindow#index',  'url' => '/api/notification-delivery-window', 'verb' => 'GET'],
        ['name' => 'notificationDeliveryWindow#update', 'url' => '/api/notification-delivery-window', 'verb' => 'PUT'],
        // Search Trails - specific routes first, then general ones.
        ['name' => 'searchTrail#index', 'url' => '/api/search-trails', 'verb' => 'GET'],
        ['name' => 'searchTrail#statistics', 'url' => '/api/search-trails/statistics', 'verb' => 'GET'],
        ['name' => 'searchTrail#popularTerms', 'url' => '/api/search-trails/popular-terms', 'verb' => 'GET'],
        ['name' => 'searchTrail#activity', 'url' => '/api/search-trails/activity', 'verb' => 'GET'],
        ['name' => 'searchTrail#registerSchemaStats', 'url' => '/api/search-trails/register-schema-stats', 'verb' => 'GET'],
        ['name' => 'searchTrail#userAgentStats', 'url' => '/api/search-trails/user-agent-stats', 'verb' => 'GET'],
        ['name' => 'searchTrail#export', 'url' => '/api/search-trails/export', 'verb' => 'GET'],
        ['name' => 'searchTrail#cleanup', 'url' => '/api/search-trails/cleanup', 'verb' => 'POST'],
        ['name' => 'searchTrail#destroyMultiple', 'url' => '/api/search-trails', 'verb' => 'DELETE'],
        ['name' => 'searchTrail#clearAll', 'url' => '/api/search-trails/clear-all', 'verb' => 'DELETE'],
        ['name' => 'searchTrail#show', 'url' => '/api/search-trails/{id}', 'verb' => 'GET', 'requirements' => ['id' => '[^/]+']],
        ['name' => 'searchTrail#destroy', 'url' => '/api/search-trails/{id}', 'verb' => 'DELETE', 'requirements' => ['id' => '[^/]+']],
        // Deleted Objects.
        ['name' => 'deleted#index', 'url' => '/api/deleted', 'verb' => 'GET'],
        ['name' => 'deleted#statistics', 'url' => '/api/deleted/statistics', 'verb' => 'GET'],
        ['name' => 'deleted#topDeleters', 'url' => '/api/deleted/top-deleters', 'verb' => 'GET'],
        ['name' => 'deleted#restore', 'url' => '/api/deleted/{id}/restore', 'verb' => 'POST', 'requirements' => ['id' => '[^/]+']],
        ['name' => 'deleted#restoreMultiple', 'url' => '/api/deleted/restore', 'verb' => 'POST'],
        ['name' => 'deleted#destroy', 'url' => '/api/deleted/{id}', 'verb' => 'DELETE', 'requirements' => ['id' => '[^/]+']],
        ['name' => 'deleted#destroyMultiple', 'url' => '/api/deleted', 'verb' => 'DELETE'],
        // Revert.
        ['name' => 'revert#revert', 'url' => '/api/objects/{register}/{schema}/{id}/revert', 'verb' => 'POST', 'requirements' => ['id' => '[^/]+']],

        // Files operations under objects.
		['name' => 'files#create', 'url' => '/api/objects/{register}/{schema}/{id}/files', 'verb' => 'POST', 'requirements' => ['id' => '[^/]+']],
		['name' => 'files#save', 'url' => '/api/objects/{register}/{schema}/{id}/files/save', 'verb' => 'POST', 'requirements' => ['id' => '[^/]+']],
		['name' => 'files#index', 'url' => '/api/objects/{register}/{schema}/{id}/files', 'verb' => 'GET', 'requirements' => ['id' => '[^/]+']],
        ['name' => 'files#show', 'url' => '/api/objects/{register}/{schema}/{id}/files/{fileId}', 'verb' => 'GET', 'requirements' => ['id' => '[^/]+', 'fileId' => '\d+']],
        ['name' => 'objects#downloadFiles', 'url' => '/api/objects/{register}/{schema}/{id}/files/download', 'verb' => 'GET', 'requirements' => ['id' => '[^/]+']],
		['name' => 'files#createMultipart', 'url' => '/api/objects/{register}/{schema}/{id}/filesMultipart', 'verb' => 'POST', 'requirements' => ['id' => '[^/]+']],
		['name' => 'files#update', 'url' => '/api/objects/{register}/{schema}/{id}/files/{fileId}', 'verb' => 'PUT', 'requirements' => ['id' => '[^/]+', 'fileId' => '\d+']],
		['name' => 'files#delete', 'url' => '/api/objects/{register}/{schema}/{id}/files/{fileId}', 'verb' => 'DELETE', 'requirements' => ['id' => '[^/]+', 'fileId' => '\d+']],
		// File-actions (rename / copy / move / versions / lock / batch / preview / labels).
		['name' => 'files#rename',         'url' => '/api/objects/{register}/{schema}/{id}/files/{fileId}/rename',                       'verb' => 'PUT',  'requirements' => ['id' => '[^/]+', 'fileId' => '\d+']],
		['name' => 'files#copy',           'url' => '/api/objects/{register}/{schema}/{id}/files/{fileId}/copy',                         'verb' => 'POST', 'requirements' => ['id' => '[^/]+', 'fileId' => '\d+']],
		['name' => 'files#move',           'url' => '/api/objects/{register}/{schema}/{id}/files/{fileId}/move',                         'verb' => 'POST', 'requirements' => ['id' => '[^/]+', 'fileId' => '\d+']],
		['name' => 'files#listVersions',   'url' => '/api/objects/{register}/{schema}/{id}/files/{fileId}/versions',                     'verb' => 'GET',  'requirements' => ['id' => '[^/]+', 'fileId' => '\d+']],
		['name' => 'files#restoreVersion', 'url' => '/api/objects/{register}/{schema}/{id}/files/{fileId}/versions/{versionId}/restore', 'verb' => 'POST', 'requirements' => ['id' => '[^/]+', 'fileId' => '\d+', 'versionId' => '[^/]+']],
		['name' => 'files#lock',           'url' => '/api/objects/{register}/{schema}/{id}/files/{fileId}/lock',                         'verb' => 'POST', 'requirements' => ['id' => '[^/]+', 'fileId' => '\d+']],
		['name' => 'files#unlock',         'url' => '/api/objects/{register}/{schema}/{id}/files/{fileId}/unlock',                       'verb' => 'POST', 'requirements' => ['id' => '[^/]+', 'fileId' => '\d+']],
		['name' => 'files#batch',          'url' => '/api/objects/{register}/{schema}/{id}/files/batch',                                 'verb' => 'POST', 'requirements' => ['id' => '[^/]+']],
		['name' => 'files#preview',        'url' => '/api/objects/{register}/{schema}/{id}/files/{fileId}/preview',                      'verb' => 'GET',  'requirements' => ['id' => '[^/]+', 'fileId' => '\d+']],
		['name' => 'files#updateLabels',   'url' => '/api/objects/{register}/{schema}/{id}/files/{fileId}/labels',                       'verb' => 'PUT',  'requirements' => ['id' => '[^/]+', 'fileId' => '\d+']],
		// Description and category had NO surface before this: only labels did,
		// which is why the gap was easy to miss. `file-actions` specifies all three.
		['name' => 'files#updateMetadata', 'url' => '/api/objects/{register}/{schema}/{id}/files/{fileId}/metadata', 'verb' => 'PUT',  'requirements' => ['id' => '[^/]+', 'fileId' => '\d+']],

        // Direct file access by ID (authenticated).
        ['name' => 'files#downloadById', 'url' => '/api/files/{fileId}/download', 'verb' => 'GET', 'requirements' => ['fileId' => '\d+']],

        // Tasks: user-scoped listing (all CalDAV VTODOs for current user).
        ['name' => 'tasks#allUserTasks', 'url' => '/api/tasks', 'verb' => 'GET'],

        // Tasks operations under objects (CalDAV VTODO wrapper).
        ['name' => 'tasks#index', 'url' => '/api/objects/{register}/{schema}/{id}/tasks', 'verb' => 'GET', 'requirements' => ['id' => '[^/]+']],
        ['name' => 'tasks#create', 'url' => '/api/objects/{register}/{schema}/{id}/tasks', 'verb' => 'POST', 'requirements' => ['id' => '[^/]+']],
        ['name' => 'tasks#update', 'url' => '/api/objects/{register}/{schema}/{id}/tasks/{taskId}', 'verb' => 'PUT', 'requirements' => ['id' => '[^/]+', 'taskId' => '[^/]+']],
        ['name' => 'tasks#destroy', 'url' => '/api/objects/{register}/{schema}/{id}/tasks/{taskId}', 'verb' => 'DELETE', 'requirements' => ['id' => '[^/]+', 'taskId' => '[^/]+']],

        // Notes operations under objects (Nextcloud Comments wrapper).
        ['name' => 'notes#index', 'url' => '/api/objects/{register}/{schema}/{id}/notes', 'verb' => 'GET', 'requirements' => ['id' => '[^/]+']],
        ['name' => 'notes#create', 'url' => '/api/objects/{register}/{schema}/{id}/notes', 'verb' => 'POST', 'requirements' => ['id' => '[^/]+']],
        ['name' => 'notes#update', 'url' => '/api/objects/{register}/{schema}/{id}/notes/{noteId}', 'verb' => 'PUT', 'requirements' => ['id' => '[^/]+', 'noteId' => '[^/]+']],
        ['name' => 'notes#destroy', 'url' => '/api/objects/{register}/{schema}/{id}/notes/{noteId}', 'verb' => 'DELETE', 'requirements' => ['id' => '[^/]+', 'noteId' => '[^/]+']],

        // Semantic-object handoff engine (ADR-051): availability + execute.
        // Both #[NoAdminRequired] with a per-object RBAC guard in the method
        // body (ADR-005/016/029); CSRF stays enabled on the POST.
        ['name' => 'handoff#availability', 'url' => '/api/objects/{register}/{schema}/{id}/handoffs', 'verb' => 'GET', 'requirements' => ['register' => '[^/]+', 'schema' => '[^/]+', 'id' => '[^/]+']],
        ['name' => 'handoff#execute', 'url' => '/api/objects/{register}/{schema}/{id}/handoffs/{handoffId}', 'verb' => 'POST', 'requirements' => ['register' => '[^/]+', 'schema' => '[^/]+', 'id' => '[^/]+', 'handoffId' => '[^/]+']],

        // Schemas.
        // Cross-app semantic reference discovery (ADR-048): resolve a canonical
        // semantic-type URI to the installed provider schema. Static path,
        // registered before the `{id}` schema routes so it is not shadowed.
        ['name' => 'schemas#resolveByImplements', 'url' => '/api/schemas/resolve-by-implements', 'verb' => 'GET'],
        ['name' => 'schemas#upload', 'url' => '/api/schemas/upload', 'verb' => 'POST'],
        ['name' => 'schemas#uploadUpdate', 'url' => '/api/schemas/{id}/upload', 'verb' => 'PUT', 'requirements' => ['id' => '[^/]+']],
        ['name' => 'schemas#download', 'url' => '/api/schemas/{id}/download', 'verb' => 'GET', 'requirements' => ['id' => '[^/]+']],
        ['name' => 'schemas#related', 'url' => '/api/schemas/{id}/related', 'verb' => 'GET', 'requirements' => ['id' => '[^/]+']],
        ['name' => 'schemas#stats', 'url' => '/api/schemas/{id}/stats', 'verb' => 'GET', 'requirements' => ['id' => '[^/]+']],
        ['name' => 'schemas#explore', 'url' => '/api/schemas/{id}/explore', 'verb' => 'GET', 'requirements' => ['id' => '[^/]+']],
        ['name' => 'schemas#updateFromExploration', 'url' => '/api/schemas/{id}/update-from-exploration', 'verb' => 'POST', 'requirements' => ['id' => '[^/]+']],
        // Schema versioning & object migration (schema-versioning-and-object-migration).
        ['name' => 'schemaMigration#changelog', 'url' => '/api/schemas/{id}/changelog', 'verb' => 'GET', 'requirements' => ['id' => '\d+']],
        ['name' => 'schemaMigration#revalidate', 'url' => '/api/schemas/{id}/revalidate', 'verb' => 'POST', 'requirements' => ['id' => '\d+']],
        ['name' => 'schemaMigration#runs', 'url' => '/api/schemas/{id}/runs', 'verb' => 'GET', 'requirements' => ['id' => '\d+']],
        ['name' => 'schemaMigration#run', 'url' => '/api/schemas/{id}/runs/{run}', 'verb' => 'GET', 'requirements' => ['id' => '\d+', 'run' => '\d+']],
        ['name' => 'schemaMigration#previewMigration', 'url' => '/api/schemas/{id}/migrations/preview', 'verb' => 'POST', 'requirements' => ['id' => '\d+']],
        ['name' => 'schemaMigration#migrate', 'url' => '/api/schemas/{id}/migrations', 'verb' => 'POST', 'requirements' => ['id' => '\d+']],
        ['name' => 'schemaMigration#rollback', 'url' => '/api/schemas/{id}/runs/{run}/rollback', 'verb' => 'POST', 'requirements' => ['id' => '\d+', 'run' => '\d+']],
        // Schema import from external standards (schema-import-standards). Admin-gated by NC framework default.
        ['name' => 'schemaImport#types', 'url' => '/api/schema-import/{dialect}/types', 'verb' => 'GET', 'requirements' => ['dialect' => '[^/]+']],
        ['name' => 'schemaImport#snapshot', 'url' => '/api/schema-import/{dialect}/snapshot', 'verb' => 'GET', 'requirements' => ['dialect' => '[^/]+']],
        ['name' => 'schemaImport#import', 'url' => '/api/schema-import/{dialect}', 'verb' => 'POST', 'requirements' => ['dialect' => '[^/]+']],
        ['name' => 'schemaImport#reimport', 'url' => '/api/schemas/{id}/reimport', 'verb' => 'POST', 'requirements' => ['id' => '\d+']],
        // Registers
        ['name' => 'registers#export', 'url' => '/api/registers/{id}/export', 'verb' => 'GET', 'requirements' => ['id' => '[^/]+']],
        ['name' => 'registers#import', 'url' => '/api/registers/{id}/import', 'verb' => 'POST', 'requirements' => ['id' => '[^/]+']],
        ['name' => 'registers#rollbackImport', 'url' => '/api/registers/import/rollback', 'verb' => 'POST'],
        [
            'name'         => 'registers#importTemplate',
            'url'          => '/api/registers/{id}/schemas/{schema}/import-template',
            'verb'         => 'GET',
            'requirements' => ['id' => '[^/]+', 'schema' => '[^/]+'],
        ],
        ['name' => 'registers#publishToGitHub', 'url' => '/api/registers/{id}/publish/github', 'verb' => 'POST', 'requirements' => ['id' => '[^/]+']],
        ['name' => 'registers#schemas', 'url' => '/api/registers/{id}/schemas', 'verb' => 'GET', 'requirements' => ['id' => '[^/]+']],
        ['name' => 'registers#stats', 'url' => '/api/registers/{id}/stats', 'verb' => 'GET', 'requirements' => ['id' => '[^/]+']],
        ['name' => 'oas#generate', 'url' => '/api/registers/{id}/oas', 'verb' => 'GET', 'requirements' => ['id' => '[^/]+']],
        ['name' => 'oas#generateAll', 'url' => '/api/registers/oas', 'verb' => 'GET'],
        // Configurations - CRUD (singular ConfigurationController — richer implementation than the resource-routed ConfigurationsController).
        ['name' => 'configuration#index',  'url' => '/api/configuration',         'verb' => 'GET'],
        ['name' => 'configuration#show',   'url' => '/api/configuration/{id}',    'verb' => 'GET',    'requirements' => ['id' => '\d+']],
        ['name' => 'configuration#create', 'url' => '/api/configuration',         'verb' => 'POST'],
        ['name' => 'configuration#update', 'url' => '/api/configuration/{id}',    'verb' => 'PUT',    'requirements' => ['id' => '\d+']],
        ['name' => 'configuration#destroy','url' => '/api/configuration/{id}',    'verb' => 'DELETE', 'requirements' => ['id' => '\d+']],
        // Configurations - Management.
        ['name' => 'configuration#versionStatus', 'url' => '/api/configurations/{id}/check-version', 'verb' => 'POST', 'requirements' => ['id' => '\d+']],
        ['name' => 'configuration#preview', 'url' => '/api/configurations/{id}/preview', 'verb' => 'GET', 'requirements' => ['id' => '\d+']],
        ['name' => 'configuration#import', 'url' => '/api/configurations/{id}/import', 'verb' => 'POST', 'requirements' => ['id' => '\d+']],
        ['name' => 'configuration#export', 'url' => '/api/configurations/{id}/export', 'verb' => 'GET', 'requirements' => ['id' => '\d+']],

        // Configuration discovery endpoints.
        ['name' => 'configuration#discover', 'url' => '/api/configurations/discover', 'verb' => 'GET'],
        ['name' => 'configuration#enrichDetails', 'url' => '/api/configurations/enrich', 'verb' => 'GET'],
        ['name' => 'configuration#getGitHubBranches', 'url' => '/api/configurations/github/branches', 'verb' => 'GET'],
        ['name' => 'configuration#getGitHubRepositories', 'url' => '/api/configurations/github/repositories', 'verb' => 'GET'],
        ['name' => 'configuration#getGitHubConfigurations', 'url' => '/api/configurations/github/files', 'verb' => 'GET'],
        ['name' => 'configuration#getGitLabBranches', 'url' => '/api/configurations/gitlab/branches', 'verb' => 'GET'],
        ['name' => 'configuration#getGitLabConfigurations', 'url' => '/api/configurations/gitlab/files', 'verb' => 'GET'],

        // Configuration import endpoints.
        ['name' => 'configurations#import', 'url' => '/api/configurations/import', 'verb' => 'POST'],
        ['name' => 'configuration#importFromGitHub', 'url' => '/api/configurations/import/github', 'verb' => 'POST'],
        ['name' => 'configuration#importFromGitLab', 'url' => '/api/configurations/import/gitlab', 'verb' => 'POST'],
        ['name' => 'configuration#importFromUrl', 'url' => '/api/configurations/import/url', 'verb' => 'POST'],

        // Configuration publish endpoints.
        ['name' => 'configuration#publishToGitHub', 'url' => '/api/configurations/{id}/publish/github', 'verb' => 'POST'],

        // User Settings - GitHub Integration.
        ['name' => 'userSettings#getGitHubTokenStatus', 'url' => '/api/user-settings/github/status', 'verb' => 'GET'],
        ['name' => 'userSettings#setGitHubToken', 'url' => '/api/user-settings/github/token', 'verb' => 'POST'],
        ['name' => 'userSettings#removeGitHubToken', 'url' => '/api/user-settings/github/token', 'verb' => 'DELETE'],
        // Applications.
        ['name' => 'applications#page', 'url' => '/applications', 'verb' => 'GET'],
        // SPA detail route — see ConductionNL/openregister#1962.
        ['name' => 'ui#applicationDetails', 'url' => '/applications/{id}', 'verb' => 'GET', 'requirements' => ['id' => '[^/]+']],
        // Agents. The SPA page moved to hermiq (or-chat-engine-decommission);
        // only the API surface remains, answered via the compat proxy.
        ['name' => 'agents#stats', 'url' => '/api/agents/stats', 'verb' => 'GET'],
        ['name' => 'agents#tools', 'url' => '/api/agents/tools', 'verb' => 'GET'],
        // Search.
        ['name' => 'search#search', 'url' => '/api/search', 'verb' => 'GET'],
        // Organisations - Multi-tenancy management.
        ['name' => 'organisation#index', 'url' => '/api/organisations', 'verb' => 'GET'],
        ['name' => 'organisation#create', 'url' => '/api/organisations', 'verb' => 'POST'],
        ['name' => 'organisation#search', 'url' => '/api/organisations/search', 'verb' => 'GET'],
        ['name' => 'organisation#stats', 'url' => '/api/organisations/stats', 'verb' => 'GET'],
        ['name' => 'organisation#stats', 'url' => '/api/organisations/statistics', 'verb' => 'GET'],
        ['name' => 'organisation#clearCache', 'url' => '/api/organisations/clear-cache', 'verb' => 'POST'],
        ['name' => 'organisation#getActive', 'url' => '/api/organisations/active', 'verb' => 'GET'],
        ['name' => 'organisation#show', 'url' => '/api/organisations/{uuid}', 'verb' => 'GET'],
        ['name' => 'organisation#update', 'url' => '/api/organisations/{uuid}', 'verb' => 'PUT'],
        ['name' => 'organisation#patch', 'url' => '/api/organisations/{uuid}', 'verb' => 'PATCH'],
        ['name' => 'organisation#setActive', 'url' => '/api/organisations/{uuid}/set-active', 'verb' => 'POST'],
        ['name' => 'organisation#join', 'url' => '/api/organisations/{uuid}/join', 'verb' => 'POST'],
        ['name' => 'organisation#leave', 'url' => '/api/organisations/{uuid}/leave', 'verb' => 'POST'],

        // Organisations - Tenant lifecycle management.
        ['name' => 'organisation#suspend', 'url' => '/api/organisations/{uuid}/suspend', 'verb' => 'PUT'],
        ['name' => 'organisation#activate', 'url' => '/api/organisations/{uuid}/activate', 'verb' => 'PUT'],
        ['name' => 'organisation#deprovision', 'url' => '/api/organisations/{uuid}/deprovision', 'verb' => 'PUT'],
        ['name' => 'organisation#usage', 'url' => '/api/organisations/{uuid}/usage', 'verb' => 'GET'],

        // Admin - Tenant isolation verification and metrics.
        ['name' => 'organisation#isolationVerify', 'url' => '/api/admin/isolation-verify', 'verb' => 'POST'],
        ['name' => 'organisation#isolationMetrics', 'url' => '/api/admin/isolation-metrics', 'verb' => 'GET'],
		// Tags.
		['name' => 'tags#getAllTags', 'url' => '/api/tags', 'verb' => 'GET'],
		['name' => 'tags#index',     'url' => '/api/objects/{register}/{schema}/{id}/tags',         'verb' => 'GET',    'requirements' => ['id' => '[^/]+']],
		['name' => 'tags#add',       'url' => '/api/objects/{register}/{schema}/{id}/tags',         'verb' => 'POST',   'requirements' => ['id' => '[^/]+']],
		['name' => 'tags#remove',    'url' => '/api/objects/{register}/{schema}/{id}/tags/{tag}',   'verb' => 'DELETE', 'requirements' => ['id' => '[^/]+', 'tag' => '[^/]+']],

		// Views - Saved search configurations.
		['name' => 'views#index', 'url' => '/api/views', 'verb' => 'GET'],
		['name' => 'views#show', 'url' => '/api/views/{id}', 'verb' => 'GET', 'requirements' => ['id' => '[^/]+']],
		['name' => 'views#create', 'url' => '/api/views', 'verb' => 'POST'],
		['name' => 'views#update', 'url' => '/api/views/{id}', 'verb' => 'PUT', 'requirements' => ['id' => '[^/]+']],
		['name' => 'views#patch', 'url' => '/api/views/{id}', 'verb' => 'PATCH', 'requirements' => ['id' => '[^/]+']],
		['name' => 'views#destroy', 'url' => '/api/views/{id}', 'verb' => 'DELETE', 'requirements' => ['id' => '[^/]+']],
		// Read-only presentation data — drag-to-move goes through the existing
		// guarded object PATCH/PUT (/api/objects/{register}/{schema}/{id}), never
		// a bespoke endpoint here (REQ-VIEW-KANBAN-03).
		['name' => 'views#kanban', 'url' => '/api/views/{id}/kanban', 'verb' => 'GET', 'requirements' => ['id' => '[^/]+']],
		['name' => 'views#calendar', 'url' => '/api/views/{id}/calendar', 'verb' => 'GET', 'requirements' => ['id' => '[^/]+']],

		// Chat - AI Assistant endpoints.
		['name' => 'chat#sendMessage', 'url' => '/api/chat/send', 'verb' => 'POST'],
		['name' => 'chat#getHistory', 'url' => '/api/chat/history', 'verb' => 'GET'],
		['name' => 'chat#clearHistory', 'url' => '/api/chat/history', 'verb' => 'DELETE'],
		['name' => 'chat#getChatStats', 'url' => '/api/chat/stats', 'verb' => 'GET'],
		['name' => 'chat#sendFeedback', 'url' => '/api/conversations/{conversationUuid}/messages/{messageId}/feedback', 'verb' => 'POST', 'requirements' => ['conversationUuid' => '[^/]+', 'messageId' => '\\d+']],

		// Chat - Health probe (PublicPage — no auth required).
		['name' => 'chatHealth#health', 'url' => '/api/chat/health', 'verb' => 'GET'],

		// Chat - SSE streaming endpoint (authenticated).
		['name' => 'chatStream#stream', 'url' => '/api/chat/stream', 'verb' => 'POST'],

		// Conversations - AI Conversation management.
		['name' => 'conversation#index', 'url' => '/api/conversations', 'verb' => 'GET'],
		['name' => 'conversation#show', 'url' => '/api/conversations/{uuid}', 'verb' => 'GET', 'requirements' => ['uuid' => '[^/]+']],
		['name' => 'conversation#messages', 'url' => '/api/conversations/{uuid}/messages', 'verb' => 'GET', 'requirements' => ['uuid' => '[^/]+']],
		['name' => 'conversation#create', 'url' => '/api/conversations', 'verb' => 'POST'],
		['name' => 'conversation#update', 'url' => '/api/conversations/{uuid}', 'verb' => 'PATCH', 'requirements' => ['uuid' => '[^/]+']],
		['name' => 'conversation#destroy', 'url' => '/api/conversations/{uuid}', 'verb' => 'DELETE', 'requirements' => ['uuid' => '[^/]+']],
		['name' => 'conversation#restore', 'url' => '/api/conversations/{uuid}/restore', 'verb' => 'POST', 'requirements' => ['uuid' => '[^/]+']],
		['name' => 'conversation#destroyPermanent', 'url' => '/api/conversations/{uuid}/permanent', 'verb' => 'DELETE', 'requirements' => ['uuid' => '[^/]+']],

		// File Text Management - Extract and manage text from files.
		['name' => 'fileText#getFileText', 'url' => '/api/files/{fileId}/text', 'verb' => 'GET', 'requirements' => ['fileId' => '\\d+']],
		['name' => 'fileText#extractFileText', 'url' => '/api/files/{fileId}/extract', 'verb' => 'POST', 'requirements' => ['fileId' => '\\d+']],
		['name' => 'fileText#bulkExtract', 'url' => '/api/files/extract/bulk', 'verb' => 'POST'],
		['name' => 'fileText#getStats', 'url' => '/api/files/extraction/stats', 'verb' => 'GET'],
		['name' => 'fileText#deleteFileText', 'url' => '/api/files/{fileId}/text', 'verb' => 'DELETE', 'requirements' => ['fileId' => '\\d+']],

		// File Chunking & Indexing - Process extracted files and index chunks in SOLR.

		// File Anonymization - Replace detected entities with placeholders.
		['name' => 'fileText#anonymizeFile', 'url' => '/api/files/{fileId}/anonymize', 'verb' => 'POST', 'requirements' => ['fileId' => '\\d+']],

		// Manual entity addition - operator-supplied value, chunk-aware string matching, persists catalogue + relations.
		['name' => 'fileText#addManualEntity', 'url' => '/api/files/{fileId}/manual-entities', 'verb' => 'POST', 'requirements' => ['fileId' => '\\d+']],

		// Entity Relations - Decision-metadata PATCH (bases + skipAnonymization). See `entity-relation-grondslagen`.
		['name' => 'entityRelations#update', 'url' => '/api/entity-relations/{id}', 'verb' => 'PATCH', 'requirements' => ['id' => '\\d+']],

		// GDPR Entities - Manage detected PII entities.
		['name' => 'gdprEntities#index', 'url' => '/api/entities', 'verb' => 'GET'],
		['name' => 'gdprEntities#show', 'url' => '/api/entities/{id}', 'verb' => 'GET', 'requirements' => ['id' => '\\d+']],
		['name' => 'gdprEntities#destroy', 'url' => '/api/entities/{id}', 'verb' => 'DELETE', 'requirements' => ['id' => '\\d+']],
		['name' => 'gdprEntities#getTypes', 'url' => '/api/entities/types', 'verb' => 'GET'],
		['name' => 'gdprEntities#getCategories', 'url' => '/api/entities/categories', 'verb' => 'GET'],
		['name' => 'gdprEntities#getStats', 'url' => '/api/entities/stats', 'verb' => 'GET'],

		// File Search - Semantic and hybrid search over file contents.
		['name' => 'fileSearch#semanticSearch', 'url' => '/api/search/files/semantic', 'verb' => 'POST'],
		['name' => 'fileSearch#hybridSearch', 'url' => '/api/search/files/hybrid', 'verb' => 'POST'],

		// Page routes.
		['name' => 'dashboard#page', 'url' => '/', 'verb' => 'GET'], // you cannot remove `dashboard#page` as the dashboard expects this.
		['name' => 'ui#registers', 'url' => '/registers', 'verb' => 'GET'],
		['name' => 'ui#registersDetails', 'url' => '/registers/{id}', 'verb' => 'GET', 'requirements' => ['id' => '[^/]+']],
		['name' => 'ui#schemas', 'url' => '/schemas', 'verb' => 'GET'],
		['name' => 'ui#schemasDetails', 'url' => '/schemas/{id}', 'verb' => 'GET', 'requirements' => ['id' => '[^/]+']],
		['name' => 'ui#sources', 'url' => '/sources', 'verb' => 'GET'],
		['name' => 'ui#organisation', 'url' => '/organisation', 'verb' => 'GET'],
		['name' => 'ui#objects', 'url' => '/objects', 'verb' => 'GET'],
		// Deep-link to a specific object — same SPA shell, Vue Router
		// parses the {register, schema, id} params and ObjectsIndex
		// fetches the object so its detail tabs (including the registry-
		// driven Integrations tab) render directly.
		//
		// Distinct action name (`ui#objectDetail`) so OC's `OC\Route\Router`
		// duplicate-route-name guard does not drop one of the two `/objects*`
		// declarations — same pattern as `ui#integrationsView` below. See
		// ConductionNL/openregister#1962.
		['name' => 'ui#objectDetail', 'url' => '/objects/{register}/{schema}/{id}', 'verb' => 'GET', 'requirements' => ['register' => '[^/]+', 'schema' => '[^/]+', 'id' => '[^/]+']],
		// Standalone integrations view (per-leaf screenshot harness target).
		// Bypasses ObjectDetails; Vue Router resolves to IntegrationsView.vue.
		// Has its own action `ui#integrationsView` so the duplicate-route-name
		// guard in OC's Router doesn't reject it.
		['name' => 'ui#integrationsView', 'url' => '/integrations/{register}/{schema}/{objectId}', 'verb' => 'GET', 'requirements' => ['register' => '[^/]+', 'schema' => '[^/]+', 'objectId' => '[^/]+']],
		['name' => 'ui#tables', 'url' => '/tables', 'verb' => 'GET'],
		['name' => 'ui#configurations', 'url' => '/configurations', 'verb' => 'GET'],
		['name' => 'ui#deleted', 'url' => '/deleted', 'verb' => 'GET'],
		['name' => 'ui#auditTrail', 'url' => '/audit-trails', 'verb' => 'GET'],
		['name' => 'ui#searchTrail', 'url' => '/search-trails', 'verb' => 'GET'],
		['name' => 'ui#webhooks', 'url' => '/webhooks', 'verb' => 'GET'],
		['name' => 'ui#webhooksLogs', 'url' => '/webhooks/logs', 'verb' => 'GET'],
		['name' => 'ui#endpoints', 'url' => '/endpoints', 'verb' => 'GET'],
		['name' => 'ui#endpointLogs', 'url' => '/endpoints/logs', 'verb' => 'GET'],
		['name' => 'ui#entities', 'url' => '/entities', 'verb' => 'GET'],
		['name' => 'ui#entitiesDetails', 'url' => '/entities/{id}', 'verb' => 'GET', 'requirements' => ['id' => '\d+']],
		['name' => 'ui#avg', 'url' => '/avg', 'verb' => 'GET'],
		['name' => 'ui#reports', 'url' => '/reports', 'verb' => 'GET'],
		['name' => 'ui#reportView', 'url' => '/reports/{id}', 'verb' => 'GET', 'requirements' => ['id' => '[^/]+']],
		// Rapportage on-demand render endpoints (Phase 2).
		['name' => 'reports#render',  'url' => '/api/reports/{id}/render',  'verb' => 'POST', 'requirements' => ['id' => '[^/]+']],
		['name' => 'reports#preview', 'url' => '/api/reports/{id}/preview', 'verb' => 'GET',  'requirements' => ['id' => '[^/]+']],
		['name' => 'ui#templates', 'url' => '/templates', 'verb' => 'GET'],
		['name' => 'ui#featuresRoadmap', 'url' => '/features-roadmap', 'verb' => 'GET'],
		// SPA my-account route — see ConductionNL/openregister#1962.
		['name' => 'ui#myAccount', 'url' => '/mijn-account', 'verb' => 'GET'],
		['name' => 'files#page', 'url' => '/files', 'verb' => 'GET'],

		// User - Profile management and authentication.
		['name' => 'user#me', 'url' => '/api/user/me', 'verb' => 'GET'],
		['name' => 'user#updateMe', 'url' => '/api/user/me', 'verb' => 'PUT'],
		['name' => 'user#login', 'url' => '/api/user/login', 'verb' => 'POST'],
		['name' => 'user#logout', 'url' => '/api/user/logout', 'verb' => 'POST'],

		// profile-actions — self-service endpoints for the current user (/api/user/me).
		['name' => 'user#changePassword',                  'url' => '/api/user/me/password',             'verb' => 'PUT'],
		['name' => 'user#uploadAvatar',                    'url' => '/api/user/me/avatar',               'verb' => 'POST'],
		['name' => 'user#deleteAvatar',                    'url' => '/api/user/me/avatar',               'verb' => 'DELETE'],
		['name' => 'user#exportData',                      'url' => '/api/user/me/export',               'verb' => 'GET'],
		['name' => 'user#getNotificationPreferences',      'url' => '/api/user/me/notifications',        'verb' => 'GET'],
		['name' => 'user#updateNotificationPreferences',   'url' => '/api/user/me/notifications',        'verb' => 'PUT'],
		['name' => 'user#getActivity',                     'url' => '/api/user/me/activity',             'verb' => 'GET'],
		['name' => 'user#listTokens',                      'url' => '/api/user/me/tokens',               'verb' => 'GET'],
		['name' => 'user#createToken',                     'url' => '/api/user/me/tokens',               'verb' => 'POST'],
		['name' => 'user#revokeToken',                     'url' => '/api/user/me/tokens/{id}',          'verb' => 'DELETE', 'requirements' => ['id' => '[^/]+']],
		['name' => 'user#requestDeactivation',             'url' => '/api/user/me/deactivate',           'verb' => 'POST'],
		['name' => 'user#getDeactivationStatus',           'url' => '/api/user/me/deactivation-status',  'verb' => 'GET'],
		['name' => 'user#cancelDeactivation',              'url' => '/api/user/me/deactivate',           'verb' => 'DELETE'],

		// Webhooks.
		['name' => 'webhooks#index', 'url' => '/api/webhooks', 'verb' => 'GET'],
		['name' => 'webhooks#show', 'url' => '/api/webhooks/{id}', 'verb' => 'GET', 'requirements' => ['id' => '\d+']],
		['name' => 'webhooks#create', 'url' => '/api/webhooks', 'verb' => 'POST'],
		['name' => 'webhooks#update', 'url' => '/api/webhooks/{id}', 'verb' => 'PUT', 'requirements' => ['id' => '\d+']],
		['name' => 'webhooks#destroy', 'url' => '/api/webhooks/{id}', 'verb' => 'DELETE', 'requirements' => ['id' => '\d+']],
		['name' => 'webhooks#test', 'url' => '/api/webhooks/{id}/test', 'verb' => 'POST', 'requirements' => ['id' => '\d+']],
		['name' => 'webhooks#events', 'url' => '/api/webhooks/events', 'verb' => 'GET'],
		['name' => 'webhooks#logs', 'url' => '/api/webhooks/{id}/logs', 'verb' => 'GET', 'requirements' => ['id' => '\d+']],
		['name' => 'webhooks#logStats', 'url' => '/api/webhooks/{id}/logs/stats', 'verb' => 'GET', 'requirements' => ['id' => '\d+']],
		['name' => 'webhooks#allLogs', 'url' => '/api/webhooks/logs', 'verb' => 'GET'],
		['name' => 'webhooks#retry', 'url' => '/api/webhooks/logs/{logId}/retry', 'verb' => 'POST', 'requirements' => ['logId' => '\d+']],

		// Scheduled reports (scheduled-report-jobs): owner-scoped recurring
		// ExportService exports, delivered to Files + notification. Admin may
		// list all via ?all=true. run-now queues ScheduledReportRunNowJob and
		// never runs the export inline in the request.
		['name' => 'scheduledReports#index', 'url' => '/api/scheduled-reports', 'verb' => 'GET'],
		['name' => 'scheduledReports#show', 'url' => '/api/scheduled-reports/{id}', 'verb' => 'GET', 'requirements' => ['id' => '\d+']],
		['name' => 'scheduledReports#create', 'url' => '/api/scheduled-reports', 'verb' => 'POST'],
		['name' => 'scheduledReports#update', 'url' => '/api/scheduled-reports/{id}', 'verb' => 'PUT', 'requirements' => ['id' => '\d+']],
		['name' => 'scheduledReports#destroy', 'url' => '/api/scheduled-reports/{id}', 'verb' => 'DELETE', 'requirements' => ['id' => '\d+']],
		['name' => 'scheduledReports#runNow', 'url' => '/api/scheduled-reports/{id}/run-now', 'verb' => 'POST', 'requirements' => ['id' => '\d+']],

		// Migration mapping packs (migration-mapping-packs): declarative
		// source-format-to-schema import mappings. Reads (index/show/export)
		// are available to any authenticated user so the import flow can
		// browse packs; create/update/destroy/import are admin-gated. The
		// `packId` request param on registers#import (below) resolves a
		// pack by its `packSlug` and runs each row through it before save.
		['name' => 'migrationPacks#index', 'url' => '/api/migration-packs', 'verb' => 'GET'],
		['name' => 'migrationPacks#create', 'url' => '/api/migration-packs', 'verb' => 'POST'],
		['name' => 'migrationPacks#import', 'url' => '/api/migration-packs/import', 'verb' => 'POST'],
		['name' => 'migrationPacks#show', 'url' => '/api/migration-packs/{id}', 'verb' => 'GET', 'requirements' => ['id' => '\d+']],
		['name' => 'migrationPacks#update', 'url' => '/api/migration-packs/{id}', 'verb' => 'PUT', 'requirements' => ['id' => '\d+']],
		['name' => 'migrationPacks#destroy', 'url' => '/api/migration-packs/{id}', 'verb' => 'DELETE', 'requirements' => ['id' => '\d+']],
		['name' => 'migrationPacks#export', 'url' => '/api/migration-packs/{id}/export', 'verb' => 'GET', 'requirements' => ['id' => '\d+']],

		// Workflow Engines - CRUD and health check.
		['name' => 'workflowEngine#available', 'url' => '/api/engines/available', 'verb' => 'GET'],
		['name' => 'workflowEngine#index', 'url' => '/api/engines', 'verb' => 'GET'],
		['name' => 'workflowEngine#create', 'url' => '/api/engines', 'verb' => 'POST'],
		['name' => 'workflowEngine#show', 'url' => '/api/engines/{id}', 'verb' => 'GET', 'requirements' => ['id' => '\d+']],
		['name' => 'workflowEngine#update', 'url' => '/api/engines/{id}', 'verb' => 'PUT', 'requirements' => ['id' => '\d+']],
		['name' => 'workflowEngine#destroy', 'url' => '/api/engines/{id}', 'verb' => 'DELETE', 'requirements' => ['id' => '\d+']],
		['name' => 'workflowEngine#health', 'url' => '/api/engines/{id}/health', 'verb' => 'POST', 'requirements' => ['id' => '\d+']],
		['name' => 'workflowEngine#testHook', 'url' => '/api/engines/{id}/test-hook', 'verb' => 'POST', 'requirements' => ['id' => '\d+']],

		// Workflow Execution History - read/admin-delete persisted hook executions.
		['name' => 'workflowExecution#index', 'url' => '/api/workflow-executions', 'verb' => 'GET'],
		['name' => 'workflowExecution#show', 'url' => '/api/workflow-executions/{id}', 'verb' => 'GET', 'requirements' => ['id' => '\d+']],
		['name' => 'workflowExecution#destroy', 'url' => '/api/workflow-executions/{id}', 'verb' => 'DELETE', 'requirements' => ['id' => '\d+']],

		// Scheduled Workflows - CRUD for TimedJob-driven workflow triggers.
		['name' => 'scheduledWorkflow#index', 'url' => '/api/scheduled-workflows', 'verb' => 'GET'],
		['name' => 'scheduledWorkflow#show', 'url' => '/api/scheduled-workflows/{id}', 'verb' => 'GET', 'requirements' => ['id' => '\d+']],
		['name' => 'scheduledWorkflow#create', 'url' => '/api/scheduled-workflows', 'verb' => 'POST'],
		['name' => 'scheduledWorkflow#update', 'url' => '/api/scheduled-workflows/{id}', 'verb' => 'PUT', 'requirements' => ['id' => '\d+']],
		['name' => 'scheduledWorkflow#destroy', 'url' => '/api/scheduled-workflows/{id}', 'verb' => 'DELETE', 'requirements' => ['id' => '\d+']],

		// Approval Chains - multi-step approval definitions and per-object progress.
		['name' => 'approval#index', 'url' => '/api/approval-chains', 'verb' => 'GET'],
		['name' => 'approval#show', 'url' => '/api/approval-chains/{id}', 'verb' => 'GET', 'requirements' => ['id' => '\d+']],
		['name' => 'approval#create', 'url' => '/api/approval-chains', 'verb' => 'POST'],
		['name' => 'approval#update', 'url' => '/api/approval-chains/{id}', 'verb' => 'PUT', 'requirements' => ['id' => '\d+']],
		['name' => 'approval#destroy', 'url' => '/api/approval-chains/{id}', 'verb' => 'DELETE', 'requirements' => ['id' => '\d+']],
		['name' => 'approval#objects', 'url' => '/api/approval-chains/{id}/objects', 'verb' => 'GET', 'requirements' => ['id' => '\d+']],
		['name' => 'approval#steps', 'url' => '/api/approval-steps', 'verb' => 'GET'],
		['name' => 'approval#approve', 'url' => '/api/approval-steps/{id}/approve', 'verb' => 'POST', 'requirements' => ['id' => '\d+']],
		['name' => 'approval#reject', 'url' => '/api/approval-steps/{id}/reject', 'verb' => 'POST', 'requirements' => ['id' => '\d+']],

		// MCP Discovery - Tiered API discovery for AI agents.
		// CORS preflight (OPTIONS) is handled automatically by the @CORS annotation.
		['name' => 'mcp#discover', 'url' => '/api/mcp/v1/discover', 'verb' => 'GET'],
		// The menu of rights that may be OFFERED to an agent. Reading it confers
		// nothing — whether a given agent HOLDS a right is resolved by Hermiq.
		['name' => 'mcp#grantableRights', 'url' => '/api/mcp/v1/grantable-rights', 'verb' => 'GET'],
		['name' => 'mcp#discoverCapability', 'url' => '/api/mcp/v1/discover/{capability}', 'verb' => 'GET', 'requirements' => ['capability' => '[a-z-]+']],

		// MCP Standard Protocol — JSON-RPC 2.0 Streamable HTTP endpoint.
		['name' => 'mcpServer#handle', 'url' => '/api/mcp', 'verb' => 'POST'],

		// GraphQL API.
		['name' => 'graphQL#execute', 'url' => '/api/graphql', 'verb' => 'POST'],
		['name' => 'graphQL#explorer', 'url' => '/api/graphql/explorer', 'verb' => 'GET'],

		// GraphQL Subscriptions (SSE).
		['name' => 'graphQLSubscription#subscribe', 'url' => '/api/graphql/subscribe', 'verb' => 'GET'],

		// Retention management: archival settings.
		['name' => 'Settings\ConfigurationSettings#getArchivalSettings', 'url' => '/api/settings/archival', 'verb' => 'GET'],
		['name' => 'Settings\ConfigurationSettings#updateArchivalSettings', 'url' => '/api/settings/archival', 'verb' => 'PUT'],
		['name' => 'Settings\ConfigurationSettings#updateArchivalSettings', 'url' => '/api/settings/archival', 'verb' => 'PATCH'],

		// Retention management: destruction list approval workflow.
		['name' => 'retention#approveDestructionList', 'url' => '/api/retention/destruction-lists/{id}/approve', 'verb' => 'POST', 'requirements' => ['id' => '[^/]+']],
		['name' => 'retention#rejectDestructionList', 'url' => '/api/retention/destruction-lists/{id}/reject', 'verb' => 'POST', 'requirements' => ['id' => '[^/]+']],

		// Retention management: legal holds.
		['name' => 'retention#placeLegalHold', 'url' => '/api/retention/legal-holds', 'verb' => 'POST'],
		['name' => 'retention#releaseLegalHold', 'url' => '/api/retention/legal-holds/{id}', 'verb' => 'DELETE', 'requirements' => ['id' => '[^/]+']],
		['name' => 'retention#placeBulkLegalHold', 'url' => '/api/retention/legal-holds/bulk', 'verb' => 'POST'],

		// Archival destruction workflow endpoints (spec-compliant /api/archival/ prefix).
		['name' => 'archival#listDestructionLists', 'url' => '/api/archival/destruction-lists', 'verb' => 'GET'],
		['name' => 'archival#getDestructionList', 'url' => '/api/archival/destruction-lists/{id}', 'verb' => 'GET', 'requirements' => ['id' => '[^/]+']],
		['name' => 'archival#approveDestructionList', 'url' => '/api/archival/destruction-lists/{id}/approve', 'verb' => 'POST', 'requirements' => ['id' => '[^/]+']],
		['name' => 'archival#rejectDestructionList', 'url' => '/api/archival/destruction-lists/{id}/reject', 'verb' => 'POST', 'requirements' => ['id' => '[^/]+']],
		['name' => 'archival#createLegalHold', 'url' => '/api/archival/legal-holds', 'verb' => 'POST'],
		['name' => 'archival#releaseLegalHold', 'url' => '/api/archival/legal-holds/{id}', 'verb' => 'DELETE', 'requirements' => ['id' => '[^/]+']],
		['name' => 'archival#listLegalHolds', 'url' => '/api/archival/legal-holds', 'verb' => 'GET'],
		['name' => 'archival#listCertificates', 'url' => '/api/archival/certificates', 'verb' => 'GET'],

		// e-Depot transfer settings.
		['name' => 'Settings\EdepotSettings#getEdepotSettings', 'url' => '/api/settings/edepot', 'verb' => 'GET'],
		['name' => 'Settings\EdepotSettings#updateEdepotSettings', 'url' => '/api/settings/edepot', 'verb' => 'PUT'],
		['name' => 'Settings\EdepotSettings#updateEdepotSettings', 'url' => '/api/settings/edepot', 'verb' => 'PATCH'],
		['name' => 'Settings\EdepotSettings#testEdepotConnection', 'url' => '/api/settings/edepot/test', 'verb' => 'POST'],

		// e-Depot transfer management.
		['name' => 'transfer#index', 'url' => '/api/transfers', 'verb' => 'GET'],
		['name' => 'transfer#show', 'url' => '/api/transfers/{id}', 'verb' => 'GET', 'requirements' => ['id' => '[^/]+']],
		['name' => 'transfer#create', 'url' => '/api/transfers', 'verb' => 'POST'],
		// The archivist's decision. A literal trailing segment, so `{id}` — which
		// matches [^/]+ — can never swallow them. Without these two the whole
		// e-Depot flow was unreachable: `transfer#create` refuses to dispatch
		// anything that is not `approved`, and nothing could set that status.
		['name' => 'transfer#approve', 'url' => '/api/transfers/{id}/approve', 'verb' => 'POST', 'requirements' => ['id' => '[^/]+']],
		['name' => 'transfer#reject',  'url' => '/api/transfers/{id}/reject',  'verb' => 'POST', 'requirements' => ['id' => '[^/]+']],

		// Features & Roadmap menu — GitHub issues proxy (add-features-roadmap-menu).
		// GET is a cached read (NoCSRFRequired set via controller attribute, pure read).
		// POST creates a GitHub issue on behalf of the user; CSRF MUST apply, so no
		// NoCSRFRequired attribute is declared in the controller for the create method.
		['name' => 'gitHubIssues#index', 'url' => '/api/github/issues', 'verb' => 'GET'],
		['name' => 'gitHubIssues#create', 'url' => '/api/github/issues', 'verb' => 'POST'],

		// Flow-run tooling (or-flow-tooling): history, inspection, retry.
		['name' => 'flowRun#index', 'url' => '/api/flow-runs', 'verb' => 'GET'],
		// Live runs for the caller's organisation (or-flow-active-runs) — the read
		// behind the shared "running flows" widget. MUST stay above the `{uuid}`
		// route: that pattern also matches the literal `active`, and Nextcloud
		// resolves routes in declaration order, so a later registration would be
		// answered by `show('active')` → 404 for every request.
		['name' => 'flowRun#active', 'url' => '/api/flow-runs/active', 'verb' => 'GET'],
		// Finished runs on ONE subject object (flow-runs-subject-scope): the case
		// page's run history. `subject` is REQUIRED (400 without it) and the read is
		// organisation-scoped like `active`. Same ordering rule: it MUST stay above
		// the `{uuid}` route or `show('completed')` answers it with a 404.
		['name' => 'flowRun#completedForSubject', 'url' => '/api/flow-runs/completed', 'verb' => 'GET'],
		['name' => 'flowRun#show', 'url' => '/api/flow-runs/{uuid}', 'verb' => 'GET', 'requirements' => ['uuid' => '[^/]+']],
		['name' => 'flowRun#objects', 'url' => '/api/flow-runs/{uuid}/objects', 'verb' => 'GET', 'requirements' => ['uuid' => '[^/]+']],
		['name' => 'flowRun#retry', 'url' => '/api/flow-runs/{uuid}/retry', 'verb' => 'POST', 'requirements' => ['uuid' => '[^/]+']],
		['name' => 'flowRun#resume', 'url' => '/api/flow-runs/{uuid}/resume', 'verb' => 'POST', 'requirements' => ['uuid' => '[^/]+']],
		// Interactive test run (or-flow-partial-run): run synchronously with optional startAt + pins + seed.
		['name' => 'flowRun#test', 'url' => '/api/flow-runs/test', 'verb' => 'POST'],
		// The fleet-generic task (flow-task-entity): the inbox and the
		// lifecycle verbs. Named for the `flow-tasks` CAPABILITY, not for a
		// flow requirement — a standalone task with run_uuid null is served
		// here identically. `/api/tasks` itself belongs to the older CalDAV
		// VTODO leaf (tasks#allUserTasks above), which is a different thing.
		// Every verb's real authorization is TaskAuthorizationService inside
		// the service; the route attribute is never the whole check.
		['name' => 'task#index', 'url' => '/api/flow-tasks', 'verb' => 'GET'],
		['name' => 'task#create', 'url' => '/api/flow-tasks', 'verb' => 'POST'],
		['name' => 'task#show', 'url' => '/api/flow-tasks/{uuid}', 'verb' => 'GET', 'requirements' => ['uuid' => '[^/]+']],
		['name' => 'task#audit', 'url' => '/api/flow-tasks/{uuid}/audit', 'verb' => 'GET', 'requirements' => ['uuid' => '[^/]+']],
		['name' => 'task#offer', 'url' => '/api/flow-tasks/{uuid}/offer', 'verb' => 'POST', 'requirements' => ['uuid' => '[^/]+']],
		['name' => 'task#claim', 'url' => '/api/flow-tasks/{uuid}/claim', 'verb' => 'POST', 'requirements' => ['uuid' => '[^/]+']],
		['name' => 'task#unclaim', 'url' => '/api/flow-tasks/{uuid}/unclaim', 'verb' => 'POST', 'requirements' => ['uuid' => '[^/]+']],
		['name' => 'task#assign', 'url' => '/api/flow-tasks/{uuid}/assign', 'verb' => 'POST', 'requirements' => ['uuid' => '[^/]+']],
		['name' => 'task#reassign', 'url' => '/api/flow-tasks/{uuid}/reassign', 'verb' => 'POST', 'requirements' => ['uuid' => '[^/]+']],
		['name' => 'task#delegate', 'url' => '/api/flow-tasks/{uuid}/delegate', 'verb' => 'POST', 'requirements' => ['uuid' => '[^/]+']],
		['name' => 'task#resolve', 'url' => '/api/flow-tasks/{uuid}/resolve', 'verb' => 'POST', 'requirements' => ['uuid' => '[^/]+']],
		['name' => 'task#complete', 'url' => '/api/flow-tasks/{uuid}/complete', 'verb' => 'POST', 'requirements' => ['uuid' => '[^/]+']],
		['name' => 'task#cancel', 'url' => '/api/flow-tasks/{uuid}/cancel', 'verb' => 'POST', 'requirements' => ['uuid' => '[^/]+']],
		['name' => 'task#checkItem', 'url' => '/api/flow-tasks/{uuid}/checklist/{itemId}', 'verb' => 'PATCH', 'requirements' => ['uuid' => '[^/]+', 'itemId' => '[^/]+']],
		// Delegation grants (or-delegation-grants): the consent surface. A grant
		// store with no way to answer is a store that only ever says no, so these
		// are what make every delegation refusal recoverable.
		//
		// `principal` is NEVER read from a body on any of these — it is the
		// session user. An endpoint that accepted it would let anyone raise a
		// request in somebody else's name, and the prompt a real person then saw
		// would read as that other party asking.
		['name' => 'delegation#index', 'url' => '/api/delegations', 'verb' => 'GET'],
		['name' => 'delegation#request', 'url' => '/api/delegations', 'verb' => 'POST'],
		['name' => 'delegation#answer', 'url' => '/api/delegations/{uuid}/answer', 'verb' => 'POST', 'requirements' => ['uuid' => '[^/]+']],
		['name' => 'delegation#revoke', 'url' => '/api/delegations/{uuid}/revoke', 'verb' => 'POST', 'requirements' => ['uuid' => '[^/]+']],
		// Federated configuration sharing (federated-config-sharing): declare types, bundle a selection, install/publish/discover a bundle.
		['name' => 'federatedConfig#types', 'url' => '/api/federated-config/types', 'verb' => 'GET'],
		['name' => 'federatedConfig#bundle', 'url' => '/api/federated-config/bundle', 'verb' => 'POST'],
		['name' => 'federatedConfig#install', 'url' => '/api/federated-config/install', 'verb' => 'POST'],
		['name' => 'federatedConfig#publish', 'url' => '/api/federated-config/publish', 'verb' => 'POST'],
		['name' => 'federatedConfig#discover', 'url' => '/api/federated-config/discover', 'verb' => 'GET'],
		['name' => 'federatedConfig#fetch', 'url' => '/api/federated-config/fetch', 'verb' => 'GET'],
		['name' => 'federatedConfig#publicKey', 'url' => '/api/federated-config/public-key', 'verb' => 'GET'],
		['name' => 'federatedConfig#trust', 'url' => '/api/federated-config/trust', 'verb' => 'GET'],
		['name' => 'federatedConfig#setTrust', 'url' => '/api/federated-config/trust', 'verb' => 'PUT'],

		// SPA catch-all — MUST stay last so every explicit route above keeps
		// priority over the /{path} fallback. Without it only `/` served the
		// shell, so any deep link (/registers, /schemas, a detail route) never
		// reached the SPA at all — the #133 regression that forced this app back
		// onto hash routing. Spelled inline rather than via
		// \OCA\OpenRegister\AppHost\Routes::standard() because this file also
		// declares a `resources` block the builder does not carry, and because
		// this IS openregister — guarding a call to its own class would be odd.
		// ⚠️ `(?!api/)` is load-bearing. Nextcloud's RouteParser processes the
		// `routes` array BEFORE the `resources` array
		// (RouteParser::parseDefaultRoutes), and Symfony matches in insertion
		// order — so even as the LAST entry here this route still registers
		// ahead of all nine `api/...` resource routes below. Without the
		// lookahead `.+` (which matches slashes) would swallow
		// GET /api/registers, /api/schemas and the rest, answering the SPA
		// shell instead of JSON. The SPA never needs an `api/` path.
		['name' => 'dashboard#catchAll', 'url' => '/{path}', 'verb' => 'GET',
			'requirements' => ['path' => '(?!api/).+'], 'defaults' => ['path' => '']],
    ],
];

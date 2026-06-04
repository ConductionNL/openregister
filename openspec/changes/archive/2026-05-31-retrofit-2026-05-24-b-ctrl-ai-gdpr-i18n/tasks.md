# Tasks

Retroactive reverse-spec annotation. Each task corresponds to one NEW requirement drafted in the matching `specs/<capability>/spec.md` delta, and the methods listed are annotated with `@spec ...#task-N`.

## llm-chat-admin → chat-ai

- [x] task-1: chat-ai#REQ-006 — `LlmSettingsController::getLLMSettings`, `updateLLMSettings`, `patchLLMSettings` (read + full/partial update of LLM provider settings) (reverse-spec annotation)
- [x] task-2: chat-ai#REQ-007 — `LlmSettingsController::testEmbedding`, `testChat`, `getOllamaModels` (provider connection / model-discovery testing against supplied-but-unsaved config) (reverse-spec annotation)
- [x] task-3: chat-ai#REQ-008 — `LlmSettingsController::checkEmbeddingModelMismatch`, `clearAllEmbeddings` (embedding-store maintenance) (reverse-spec annotation)

## verwerkingsregister-rest-api → verwerkingsregister-api

- [x] task-4: verwerkingsregister-api#REQ-004 — `VerwerkingsactiviteitenController::index`, `show`, `create`, `update`, `destroy` (CRUD over the dedicated verwerkingsactiviteiten catalog with admin-gated writes + soft-archive) (reverse-spec annotation)
- [x] task-5: verwerkingsregister-api#REQ-005 — `VerwerkingsactiviteitenController::verantwoording` (Art 30 §4 accountability report aggregating audit-trail counts per activity) (reverse-spec annotation)

## gdpr-dsar-api → avg-verwerkingsregister

- [x] task-6: avg-verwerkingsregister#REQ-016 — `DsarController::inzage`, `portabiliteit`, `vergetelheid`, `rectificatie`, `compliance` (admin-gated DSAR rights HTTP surface, Art 15/16/17/20 + compliance check) (reverse-spec annotation)
- [x] task-7: avg-verwerkingsregister#REQ-017 — `GdprEntitiesController::index`, `show`, `getTypes`, `getCategories`, `getStats`, `destroy` (detected-PII entity registry: list/filter/stats/delete) (reverse-spec annotation)

## translation-i18n-api → register-i18n

- [x] task-8: register-i18n#REQ-017 — `TranslationController::search`, `showByObject`, `setStatus`, `bulkTranslate` (translations sidecar HTTP surface: search, per-object slots + completeness, status promotion, bulk machine-translate) (reverse-spec annotation)

## user-self-service-api → auth-system

- [x] task-9: auth-system#REQ-021 — `UserController::login`, `logout` (public self-service login/logout with rate-limit, memory guard, security headers) (reverse-spec annotation)
- [x] task-10: auth-system#REQ-022 — `UserController::me`, `updateMe`, `changePassword`, `uploadAvatar`, `deleteAvatar` (authenticated profile + credential + avatar self-management) (reverse-spec annotation)
- [x] task-11: auth-system#REQ-023 — `UserController::exportData`, `getActivity`, `getNotificationPreferences`, `updateNotificationPreferences`, `listTokens`, `createToken`, `revokeToken`, `requestDeactivation`, `getDeactivationStatus`, `cancelDeactivation` (personal-data export, activity, notification prefs, API tokens, deactivation lifecycle) (reverse-spec annotation)
- [x] task-12: auth-system#REQ-024 — `UserSettingsController::getGitHubTokenStatus`, `setGitHubToken`, `removeGitHubToken` (per-user GitHub PAT management with validation, token never echoed) (reverse-spec annotation)

# Retrofit — Controller Bundle: AI / GDPR / i18n (5 sub-clusters)

Reverse-spec of a bundle of six controllers whose HTTP surfaces the coverage scanner could not map to any existing REQ. Each sub-cluster `--extend`s its closest canonical capability with NEW requirements drafted strictly from observed controller behaviour. Scanner false-positives (private helpers, error-envelope boilerplate, memory-limit math) were dropped.

## Approach

Six controllers, five capability homes, one ghost change, one PR:

| Sub-cluster | Controller(s) | Extends | New REQs |
|---|---|---|---|
| llm-chat-admin | `Settings/LlmSettingsController` | `chat-ai` | REQ-006..008 |
| verwerkingsregister-rest-api | `VerwerkingsactiviteitenController` | `verwerkingsregister-api` | REQ-4..5 |
| gdpr-dsar-api | `DsarController`, `GdprEntitiesController` | `avg-verwerkingsregister` | 2 REQs |
| translation-i18n-api | `TranslationController` | `register-i18n` | 1 REQ |
| user-self-service-api | `UserController`, `UserSettingsController` | `auth-system` | 4 REQs |

`chat-ai` was empty for runtime chat REQs only on the message/conversation side; its REQ-001..005 (already drafted in a sibling pass) cover the runtime ChatController. This change adds the admin-configuration surface (REQ-006..008). `verwerkingsregister-api` previously specced only the audit-trail-derived `/api/audit-trails/verwerkingsregister` read views; `VerwerkingsactiviteitenController` is a *distinct* dedicated-table CRUD surface (`oc_openregister_verwerkingsactiviteiten`) plus the Art 30 §4 `verantwoording` report, so it extends rather than overlaps. `avg-verwerkingsregister` carries 15 aspirational REQs describing the full future workflow; the two GDPR controllers are the thin *shipped* HTTP slice, drafted here against observed behaviour only.

12 NEW REQs total.

## Affected code units (by sub-cluster)

- **llm-chat-admin** → `chat-ai`
  - `lib/Controller/Settings/LlmSettingsController.php` — getLLMSettings, updateLLMSettings, patchLLMSettings, testEmbedding, testChat, getOllamaModels, checkEmbeddingModelMismatch, clearAllEmbeddings
- **verwerkingsregister-rest-api** → `verwerkingsregister-api`
  - `lib/Controller/VerwerkingsactiviteitenController.php` — index, show, create, update, destroy, verantwoording (+ private hydrateFromPayload, resolveOne, aggregateAuditCounts, isAdmin, forbidden)
- **gdpr-dsar-api** → `avg-verwerkingsregister`
  - `lib/Controller/DsarController.php` — inzage, portabiliteit, vergetelheid, rectificatie, compliance
  - `lib/Controller/GdprEntitiesController.php` — index, show, getTypes, getCategories, getStats, destroy
- **translation-i18n-api** → `register-i18n`
  - `lib/Controller/TranslationController.php` — search, showByObject, setStatus, bulkTranslate (+ private loadObject, resolveSchema)
- **user-self-service-api** → `auth-system`
  - `lib/Controller/UserController.php` — me, updateMe, login, logout, changePassword, uploadAvatar, deleteAvatar, exportData, getNotificationPreferences, updateNotificationPreferences, getActivity, listTokens, createToken, revokeToken, requestDeactivation, getDeactivationStatus, cancelDeactivation
  - `lib/Controller/UserSettingsController.php` — getGitHubTokenStatus, setGitHubToken, removeGitHubToken

## Dropped as scanner false-positives

- Private envelope/helper methods with no externally observable contract of their own: `errorResponse`, `logError`, `convertToBytes` (UserController); `getTokenValidationMessage` (UserSettingsController); `forbidden`, `missingSubject`, `isAdmin` (Dsar/Verwerkingsactiviteiten — folded into the parent REQ's authz scenarios). `hydrateFromPayload`, `resolveOne`, `aggregateAuditCounts`, `loadObject`, `resolveSchema` are described inline within the public-endpoint REQs they serve.

## Security observations (surfaced, not specced)

See `## Notes` in each spec delta. Key items: GDPR/DSAR read endpoints (`GdprEntitiesController`, `VerwerkingsactiviteitenController::index/show/verantwoording`) are `@NoAdminRequired` and lack any owner / organisation / multi-tenancy scoping — any authenticated user can enumerate detected-PII values and the full processing register across tenants.

Source: openspec coverage scan, controller bundle `ctrl-ai-gdpr-i18n`, 2026-05-24.

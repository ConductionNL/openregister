# Tasks

## New capability — frontend-ui-shell

- [x] task-1: frontend-ui-shell#REQ-001 — Navigation/modal/dialog/sidebar coordination (`src/store/modules/navigation.js`, `useNavigationStore`) (retroactive annotation)
- [x] task-2: frontend-ui-shell#REQ-002 — Admin settings panel cross-section loading + toast state (`src/store/settings.js`, `useSettingsStore`) (retroactive annotation)

## Cross-capability annotations

- [x] task-3: chat-ai#REQ-004 — Agent CRUD store (`src/store/modules/agent.js`, `useAgentStore`) (retroactive annotation)
- [x] task-4: chat-ai#REQ-002 — Conversation + message-history store (`src/store/modules/conversation.ts`, `useConversationStore`) (retroactive annotation)
- [x] task-5: avg-verwerkingsregister — AVG Art 30 register + data-subject-rights store (`src/store/modules/avg.js`, `useAvgStore`, `RECHTSGROND_VOCABULARY`, `STATUS_VOCABULARY`) (retroactive annotation)
- [x] task-6: rapportage-bi-export — Reports/dashboards widget store + widget cache key (`src/store/modules/reports.js`, `useReportsStore`, `widgetCacheKey`, `DEFAULT_REPORTS_REGISTER`, `DEFAULT_DASHBOARD_SCHEMA`) (retroactive annotation)
- [x] task-7: rapportage-bi-export — Built-in dashboard store + watcher setup (`src/store/modules/dashboard.js`, `useDashboardStore`, `setupDashboardStoreWatchers`) (retroactive annotation)
- [x] task-8: register-i18n — Translations store + RTL detection (`src/store/modules/translations.js`, `useTranslationsStore`, `RTL_LANGUAGES`, `TRANSLATION_STATUSES`, `isRtlLanguage`) (retroactive annotation)
- [x] task-9: object-lifecycle#REQ-001 — Object store adapter (`src/store/modules/object.js`, `useObjectStore`, `getCurrentType`, `openregisterObjectPlugin`) (retroactive annotation)
- [x] task-10: object-lifecycle — Soft-delete / restore store (`src/store/modules/deleted.js`, `useDeletedStore`) (retroactive annotation)
- [x] task-11: data-sync-harvesting#REQ-001 — Source store (`src/store/modules/source.js`, `useSourceStore`) (retroactive annotation)
- [x] task-12: auth-system — Application access-control store (`src/store/modules/application.js`, `useApplicationStore`) (retroactive annotation)
- [x] task-13: data-import-export — Configuration bundle store (`src/store/modules/configuration.js`, `useConfigurationStore`) (retroactive annotation)
- [x] task-14: tenant-lifecycle — Organisation/multi-tenancy store (`src/store/modules/organisation.js`, `useOrganisationStore`) (retroactive annotation)
- [x] task-15: zoeken-filteren — Saved views store (`src/store/modules/views.js`, `useViewsStore`) (retroactive annotation)

## Coverage gaps

- [ ] follow-up: Custom endpoint registry (`src/store/modules/endpoints.ts`, `useEndpointStore`) — no backend-domain capability spec exists; dropped from this retrofit pending an `endpoint-registry` (or similar) spec

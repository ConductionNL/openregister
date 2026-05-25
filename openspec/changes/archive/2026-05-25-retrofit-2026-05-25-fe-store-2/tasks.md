# Tasks — Retrofit frontend store (chunk 2)

Retroactive annotation of behaviour that already exists in `src/store/`. The code
already implements each REQ; the only work captured here is the `@spec` pointer.

- [x] task-1: frontend-store-client-state#REQ-001 — Long register imports are kept alive by a client-side heartbeat (retroactive annotation of `src/store/modules/register.js::startImportHeartbeat` and its returned `stop` closure)
- [x] task-2: frontend-store-client-state#REQ-002 — A saved view's configuration is applied onto the live search store (retroactive annotation of `src/store/modules/views.js::applyView`)
- [x] task-3: frontend-store-client-state#REQ-003 — The live search-store state is captured into a saveable view configuration (retroactive annotation of `src/store/modules/views.js::createViewFromSearchState`)

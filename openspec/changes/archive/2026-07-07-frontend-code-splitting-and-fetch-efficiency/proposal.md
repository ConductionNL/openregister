---
kind: fix
depends_on: []
adr: openspec/architecture/adr-009-performance-invariants.md
---

## Why

Frontend performance audit of the Vue 2.7 app found a large eager bundle and
several N+1 fetch / re-render patterns.

1. **Zero route-level code splitting (HIGH).** `src/registry.js:21-50` statically
   imports all ~35 views; `grep "() => import(" src` returns 0 hits. The built
   `openregister-vendor.js` is 9.2MB and `openregister-main.js` 1.4MB — every page
   load parses/evaluates unrelated views (ApexCharts reports, CodeMirror editors,
   Chat, AVG, Roadmap) whether or not they're visited.
2. **N+1 user resolution (HIGH).** `src/modals/agent/EditAgent.vue:644-668` fetches
   all user ids then `Promise.all` a per-user fetch — 1 + N OCS requests on modal
   open.
3. **N+1 webhook health stats (MED).** `src/store/modules/quality.js:568-590`
   `Promise.all` per-webhook `/logs/stats`.
4. **N+1 register schemas (MED).** `src/views/register/RegisterDetail.vue:606-615`
   fetch per schema id, no store-cache check.
5. **Sources full unpaginated fetch + client slice (MED).**
   `src/store/modules/source.js:40-58` no `_limit`/`_page`; `SourcesIndex.vue:282-286`
   slices client-side; and a full-list refetch after every single mutation
   (`source.js:108,158`).
6. **Redundant `JSON.parse(JSON.stringify())` on already-parsed JSON (MED).**
   `src/store/modules/auditTrail.js:170`, `src/store/modules/searchTrail.js:272`.
7. **Broken `v-for :key` (MED).** `src/views/object/ObjectsList.vue:50`
   `:key="\`${object}${i}\`"` stringifies every row to `"[object Object]N"` —
   degenerates to index keying, defeating DOM reuse and leaking component state on
   filter/sort.
8. **Deep watcher on a whole object needing only its id (LOW-MED).**
   `src/views/register/RegisterDetail.vue:394-408` `deep: true` on `register`.
9. **500ms polling while Mail sidebar mounted (LOW).**
   `src/mail-sidebar/composables/useMailObserver.js:143` `setInterval(..., 500)`.
10. **bootstrap-vue CJS named imports don't tree-shake (LOW).** ~10 files import
    from `'bootstrap-vue'` (CJS main) pulling the full component set on top of
    nc-vue's equivalents.

## What Changes

- Convert `registry.js` view imports to async components (`() => import(...)`) so
  webpack's existing `splitChunks` splits per-route vendor code instead of
  front-loading everything.
- Replace per-item fetch loops with bulk/batch endpoints or store-cache checks
  (users, webhook stats, register schemas).
- Add server pagination to Sources and patch local state on single mutations
  instead of full refetch.
- Drop the redundant `JSON.parse(JSON.stringify())` wrappers.
- Fix the `v-for :key` to a stable id; watch `register.id` not the deep object;
  replace the 500ms poll with a `history.pushState` wrap / router hook; deep-import
  or replace the bootstrap-vue components.

## Impact

- Affected: `src/registry.js`, `webpack.config.js` (verify splitChunks),
  `src/modals/agent/EditAgent.vue`, `src/store/modules/{quality,source,auditTrail,searchTrail}.js`,
  `src/views/register/RegisterDetail.vue`, `src/views/source/SourcesIndex.vue`,
  `src/views/object/ObjectsList.vue`, `src/mail-sidebar/composables/useMailObserver.js`,
  the ~10 bootstrap-vue importers; some need a batch API endpoint (schemas, webhook
  stats) on the backend.
- Behavioural: faster first paint (smaller initial bundle), fewer requests; no UX
  change. The batch endpoints are additive.
- Risk: code-splitting can expose implicit load-order assumptions — smoke-test each
  route loads its async chunk; verify no shared-singleton timing breaks.

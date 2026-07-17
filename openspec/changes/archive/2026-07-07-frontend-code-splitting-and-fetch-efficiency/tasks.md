## 1. Route-level code splitting

- [ ] 1.1 Convert the ~35 static view imports in `src/registry.js:21-50` to async components `() => import('./views/.../X.vue')`.
- [ ] 1.2 Confirm `webpack.config.js` splitChunks produces per-route chunks; measure the new initial `vendor.js`/`main.js` sizes (target: heavy libs — ApexCharts, CodeMirror — no longer in the initial chunk).

## 2. Kill N+1 fetches

- [ ] 2.1 `EditAgent.vue:644-668`: replace per-user fetch with a bulk user-search/autocomplete (nc-vue user-select or sharees endpoint), or lazy-resolve only rendered users.
- [ ] 2.2 `quality.js:568-590`: add/consume a batch webhook-stats endpoint instead of per-webhook `/logs/stats`.
- [ ] 2.3 `RegisterDetail.vue:606-615`: bulk `GET /schemas?ids=` or check `schemaStore` cache before fetching.

## 3. Sources pagination + local patching

- [ ] 3.1 `source.js:40-58`: pass `_limit`/`_page`; paginate server-side (mirror `DeletedIndex.vue`). Remove the client `slice` in `SourcesIndex.vue:282-286`.
- [ ] 3.2 `source.js:108,158`: patch/splice local `sourceList` on delete/save instead of full `refreshSourceList()`.

## 4. Micro-fixes

- [ ] 4.1 Drop `JSON.parse(JSON.stringify(...))` in `auditTrail.js:170` and `searchTrail.js:272`.
- [ ] 4.2 `ObjectsList.vue:50`: `:key="object.id ?? i"`.
- [ ] 4.3 `RegisterDetail.vue:394-408`: watch `register.id` (or a `registerId` computed) instead of `deep: true`.
- [ ] 4.4 `useMailObserver.js:143`: replace the 500ms `setInterval` with a `history.pushState` wrap / router `afterEach` hook.
- [ ] 4.5 The ~10 bootstrap-vue importers: deep-import the ESM component path or replace with nc-vue pagination/tabs; drop the dep if fully replaced.

## 5. Verification

- [ ] 5.1 Bundle-size check: initial chunk shrinks; heavy libs load only on their route.
- [ ] 5.2 Network check: opening Agent edit / register / webhook-health issues O(1) requests, not O(N).
- [ ] 5.3 Sources list fetches one page; single mutations don't refetch the whole list.
- [ ] 5.4 `npm run lint` + build pass; each route loads its async chunk (smoke test).

## Acceptance criteria

- Views are route-split; the initial bundle no longer contains every view.
- No N+1 fetch loops on modal/detail open.
- Sources are server-paginated; single mutations patch local state.
- The v-for key, deep watcher, polling, and bootstrap-vue import issues are fixed.

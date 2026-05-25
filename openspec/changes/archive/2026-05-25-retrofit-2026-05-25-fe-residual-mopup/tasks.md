# Tasks — Retrofit fe-residual-mopup (2026-05-25)

Final retrofit annotation pass over the residual `fe-residual` batch (24 methods). Every
task below is `[x]` because the code already exists — annotating retroactively. All
methods are residual UI/store plumbing carried as `@spec exclude <reason>`; there is no
spec delta. No new implementation work and no code-logic changes (comment-only diff).

## Excluded (24 methods)

- [x] task-1: `src/components/EntitiesSidebar.vue` filter-state watchers `category`,
      `search`, `type` — `@spec exclude computed filter-state binding` (retroactive)
- [x] task-2: `src/components/FilesSidebar.vue` filter-state watchers `riskLevel`,
      `search`, `status` — `@spec exclude computed filter-state binding` (retroactive)
- [x] task-3: `src/components/WebhooksSidebar.vue` filter-state watchers `enabled`,
      `search` — `@spec exclude computed filter-state binding` (retroactive)
- [x] task-4: `src/components/cards/RegisterSchemaCard.vue::showEditRegisterDialog`
      watcher — `@spec exclude UI handler/computed dialog-open trigger` (retroactive)
- [x] task-5: `src/components/i18n/BulkTranslateDialog.vue::open` watcher —
      `@spec exclude UI handler/computed dialog-open trigger` (retroactive)
- [x] task-6: `src/modals/file/UploadFiles.vue::handler` (labelOptions watcher) —
      `@spec exclude UI handler/computed reactive label-options sync` (retroactive)
- [x] task-7: `src/modals/schema/EditSchemaProperty.vue::inversedByOptions` computed —
      `@spec exclude UI display helper` (pre-tagged in concurrent wave)
- [x] task-8: `src/services/appInstallService.js::constructor` (RequestError) —
      `@spec exclude DI constructor` (retroactive)
- [x] task-9: store `refreshXList` passthroughs — `agent.js`, `application.js`,
      `configuration.js`, `organisation.js`, `register.js`, `schema.js`, `source.js` —
      `@spec exclude store list-refresh passthrough` (pre-tagged in concurrent store waves)
- [x] task-10: `src/views/deleted/DeletedIndex.vue::filteredItems` watcher —
      `@spec exclude list-view watcher re-emitting counts` (retroactive)
- [x] task-11: `src/views/object/ObjectsIndex.vue::loadFromRoute` —
      `@spec exclude UI plumbing deep-link prime` (pre-tagged in concurrent wave)
- [x] task-12: `src/views/settings/sections/SolrConfiguration.vue` `solrEnabled` `get`/`set`
      — `@spec exclude computed v-model accessor` (retroactive)

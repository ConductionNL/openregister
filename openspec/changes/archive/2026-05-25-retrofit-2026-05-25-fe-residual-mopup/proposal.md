# Retrofit — frontend coverage: residual mop-up (2026-05-25)

Final mop-up over the last 24 uncovered openregister frontend methods. These are
residual boilerplate added by concurrent work after the main frontend retrofit waves
(`fe-sidebars`, `fe-components`, `fe-store-*`, `fe-misc`, `fe-views-*`) had already
landed. They are all UI/store plumbing: computed filter-state bindings, dialog-open
handlers, a DI constructor, store list-refresh passthroughs, view plumbing, and
computed v-model accessors.

All 24 methods end tagged with an `@spec exclude <reason>` annotation (reason required
per ADR-003). No spec delta — every method is excluded, so no `--strict` validation is
needed. No code-logic changes (comment-only diff).

## Counts

- **24 methods excluded** — residual UI/store plumbing.
- **0 methods spec'd** — none carry a standalone behavioural contract.

## Excluded (24 methods) — reasons recorded per method

- **Sidebar computed filter-state watchers** — `EntitiesSidebar.vue`
  (`category`, `search`, `type`), `FilesSidebar.vue` (`riskLevel`, `search`, `status`),
  `WebhooksSidebar.vue` (`enabled`, `search`): watchers mirroring the two-way-bound
  filter props into local state. `@spec exclude computed filter-state binding`.
- **Dialog/handler/computed UI glue** — `RegisterSchemaCard.vue::showEditRegisterDialog`
  (watcher loading schema options on open), `BulkTranslateDialog.vue::open` (watcher
  resetting form on open), `UploadFiles.vue::handler` (label-options reactive sync),
  `EditSchemaProperty.vue::inversedByOptions` (computed option builder — pre-tagged).
  `@spec exclude UI handler/computed ...`.
- **DI constructor** — `appInstallService.js::constructor` (RequestError field-copy
  constructor). `@spec exclude DI constructor`.
- **Store list-refresh passthroughs** — `agent.js`, `application.js`, `configuration.js`,
  `organisation.js`, `register.js`, `schema.js`, `source.js` `refreshXList`: thin GET
  list API passthroughs (pre-tagged in the concurrent store waves).
  `@spec exclude store list-refresh passthrough`.
- **View plumbing** — `DeletedIndex.vue::filteredItems` (watcher re-emitting counts),
  `ObjectsIndex.vue::loadFromRoute` (deep-link prime; pre-tagged).
  `@spec exclude ... view plumbing`.
- **Computed v-model accessors** — `SolrConfiguration.vue` `solrEnabled` `get`/`set`
  (auto-save toggle adapter). `@spec exclude computed v-model accessor`.

Source: `/tmp/or-scan/fw-residual.txt` (24 `path::method` refs).

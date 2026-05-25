# Tasks

All tasks are marked `[x]` because the code already exists. This is a retrofit — tasks
describe retroactive annotation, not new implementation work. No REQs were minted; every
method in the batch is UI plumbing tagged `@spec exclude <reason>`.

## Annotation

- [x] task-1: Annotate `src/views/account/sections/*.vue` plumbing methods (AccountSection, PasswordSection, TokensSection) with `@spec exclude <reason>` — account self-service contract owned by `account-self-service`; these are formatters, lifecycle hooks, clipboard/token CRUD glue.
- [x] task-2: Annotate `src/views/configuration/ConfigurationsIndex.vue` plumbing methods with `@spec exclude <reason>` — admin list-view pagination/selection/modal-dispatch/status-formatting glue; list contract owned by `admin-list-views`.
- [x] task-3: Annotate `src/views/integration/IntegrationsView.vue` plumbing methods with `@spec exclude <reason>` — route-param accessors and registry setup for the screenshot harness; integration contract owned by ADR-019 / `generic-integrations`.
- [x] task-4: Annotate `src/views/object/ObjectsIndex.vue` plumbing methods with `@spec exclude <reason>` — deep-link prime + add-object dispatch + route watcher; object contract owned by `object-lifecycle`.
- [x] task-5: Annotate `src/views/organisation/OrganisationsIndex.vue` plumbing methods with `@spec exclude <reason>` — tenant list view selection/pagination/permission-display/switch glue; tenant contract owned by `tenant-lifecycle`.
- [x] task-6: Annotate `src/views/register/RegisterDetail.vue` plumbing methods with `@spec exclude <reason>` — register dashboard chart-option computeds, schema/stat loaders, save/edit dispatch; dashboard contract owned by `built-in-dashboards`.
- [x] task-7: Annotate `src/views/settings/sections/ApiTokenConfiguration.vue` plumbing methods with `@spec exclude <reason>` — Git token admin-settings load/save/test/clear/update glue.
- [x] task-8: Annotate `src/views/settings/sections/FileConfiguration.vue` plumbing methods with `@spec exclude <reason>` — text-extraction admin-settings load/save/test/discover/extract/format glue.
- [x] task-9: Annotate `src/views/settings/sections/MultitenancyConfiguration.vue` plumbing methods with `@spec exclude <reason>` — multitenancy admin-settings store passthroughs and save/rebase-dialog glue.
- [x] task-10: Annotate `src/views/settings/sections/N8nConfiguration.vue` plumbing methods with `@spec exclude <reason>` — n8n admin-settings load/save/test/init/workflow glue.
- [x] task-11: Annotate `src/views/settings/sections/SolrConfiguration.vue` plumbing methods with `@spec exclude <reason>` — Solr admin-settings store passthroughs, dialog open/close, field inspect/create/fix, warmup/reindex, stats loaders, formatters, loading-tip animation; search contract owned by `zoeken-filteren` / `faceting-configuration`.
- [x] task-12: Annotate `src/views/source/SourcesIndex.vue` plumbing methods with `@spec exclude <reason>` — admin list-view pagination/selection/empty-state glue; list contract owned by `admin-list-views`.
- [x] task-13: Annotate `src/views/templates/TemplatesIndex.vue` plumbing methods with `@spec exclude <reason>` — admin list-view pagination/load/view/format glue.
- [x] task-14: Annotate `src/views/webhooks/WebhooksIndex.vue` plumbing methods with `@spec exclude <reason>` — webhook list-view CRUD/test/logs/format glue; payload contract owned by `webhook-payload-mapping`.

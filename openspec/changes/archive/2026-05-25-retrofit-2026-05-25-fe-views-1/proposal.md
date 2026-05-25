# Retrofit — frontend coverage, views (chunk 1)

## Why

The `/opsx-coverage-scan` retrofit pass over `openregister` left 223 named methods
across ten `src/views/**/*.vue` files without an `@spec` annotation. Per ADR-003 every
public method must end with a `@spec` tag (pointing at the change that caused it) or an
explicit `@spec exclude <reason>` tag. This ghost change brings those 223 methods under
the convention.

This is a documentation-only retrofit. No code behavior changes; only JSDoc `@spec`
tags are added.

## What the batch contains

Batch file: `/tmp/or-scan/fw-fe-views-1.json` — 223 methods across:

| File | Surface |
|------|---------|
| `src/views/account/sections/AccountSection.vue` | account self-service (deactivation) |
| `src/views/account/sections/PasswordSection.vue` | account self-service (password) |
| `src/views/account/sections/TokensSection.vue` | account self-service (API tokens) |
| `src/views/configuration/ConfigurationsIndex.vue` | admin list view (configurations) |
| `src/views/integration/IntegrationsView.vue` | integration-registry screenshot harness |
| `src/views/object/ObjectsIndex.vue` | object index / deep-link prime |
| `src/views/organisation/OrganisationsIndex.vue` | organisation (tenant) list view |
| `src/views/register/RegisterDetail.vue` | register detail dashboard |
| `src/views/settings/sections/ApiTokenConfiguration.vue` | admin settings (Git tokens) |
| `src/views/settings/sections/FileConfiguration.vue` | admin settings (text extraction) |
| `src/views/settings/sections/MultitenancyConfiguration.vue` | admin settings (multitenancy) |
| `src/views/settings/sections/N8nConfiguration.vue` | admin settings (n8n) |
| `src/views/settings/sections/SolrConfiguration.vue` | admin settings (Solr) |
| `src/views/source/SourcesIndex.vue` | admin list view (sources) |
| `src/views/templates/TemplatesIndex.vue` | admin list view (templates) |
| `src/views/webhooks/WebhooksIndex.vue` | admin list view (webhooks) |

## Triage outcome

After reading every file, all 223 methods are **list/detail/settings UI plumbing**:
pagination handlers, row-selection state, computed display getters, store passthroughs,
modal/dialog dispatch, formatters, lifecycle hooks, watcher handlers, route-param
accessors, and admin-settings load/save/test/clear handlers.

The genuinely spec-worthy user-facing view contracts in these files
(`toggleSelectAll`, `toggleSidebar`, `requestDeactivation`, `changePassword`,
`loadTokens`) were already minted into the `admin-list-views` and
`account-self-service` capabilities by the earlier `retrofit-2026-05-24-2b-views`
change. The methods in this batch are the surrounding plumbing those capabilities do
not (and should not) describe. Behavior whose contract lives elsewhere — object
lifecycle (`object-lifecycle`), tenant lifecycle (`tenant-lifecycle`), register
dashboards (`built-in-dashboards`), search/faceting (`zoeken-filteren` /
`faceting-configuration`), webhook payloads (`webhook-payload-mapping`), the
integration registry (ADR-019 / `generic-integrations`) — is excluded here with a
reason pointing at the owning concern; annotation against those capabilities is their
own retrofit runs' job, not this UI-plumbing pass.

**Result: 0 reverse-spec REQs minted. All 223 methods tagged `@spec exclude <reason>`.**

## Counts

- Methods in batch: **223**
- Spec'd (reverse-spec REQs): **0**
- Excluded (`@spec exclude <reason>`): **223**
- New REQs: **0**

## Approach

No spec delta (no REQs minted). Each of the 223 methods gets a JSDoc `@spec exclude
<reason>` tag describing why it is not a spec-bearing contract (UI plumbing, store
passthrough, formatter, lifecycle/watcher glue, or behavior owned by another
capability).

## Out of scope

- Reshaping or "fixing" observed behavior — pure annotation.
- Annotating the cross-capability behavior (object/tenant/dashboard/search/webhook/
  integration) surfaced through these views — owned by those capabilities' own retrofit
  runs.

Source: `openspec/coverage-report.md`. See
[retrofit playbook](../../../../.github/docs/claude/retrofit.md).

/*
 * SPDX-FileCopyrightText: 2026 Open Register Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * MANIFEST PAGE COMPONENT -> ROUTE BINDINGS.
 *
 * Single source of truth mapping each manifest page's `component` (the Vue
 * page host under `src/views/**`, as registered in `src/registry.js`) to the
 * hash route `src/manifest.json` mounts it on. Specs navigate by importing the
 * binding rather than by repeating a bare path string.
 *
 * WHY THIS FILE EXISTS
 * --------------------
 * A route string in a spec says WHERE the browser went; it does not say WHICH
 * component rendered. That gap was not cosmetic — it was measurable. Hydra
 * gate-26 (visual-coverage) asks whether each page component is referenced by
 * an executable e2e test, and it deliberately blanks comments first, because a
 * comment naming a component is a claim, not a test (.github#358).
 *
 * Measured on this tree on 2026-08-16 against gate package 18fe6f9:
 *
 *   27 page components flagged as having no visual/e2e proof
 *   20 of those 27 were in fact DRIVEN by a real spec, through its route,
 *      and named nowhere the gate could read — 6 named only inside a `//`
 *      comment, 14 not named at all.
 *
 * So the gate was right that the reference was missing and wrong about the
 * coverage being missing. This file closes that distance the honest way: the
 * binding is load-bearing (change a route in the manifest and the spec that
 * imports the const must change with it), so the component name in an
 * executable line is a fact about what the spec drives, not an annotation
 * added to satisfy a checker.
 *
 * ⚠️ KEEP THE CONST NAME EQUAL TO THE COMPONENT NAME. The name is the whole
 * point — `SchemasIndex` here IS `src/views/schema/SchemasIndex.vue`. Renaming
 * the component without renaming the const silently removes that component's
 * only machine-readable coverage reference.
 *
 * ⚠️ THE ROUTER RUNS IN HASH MODE (`src/main.js`). These are hash routes: a
 * path-form deep link is rewritten by the hash router and renders the
 * DASHBOARD instead of the target page. Callers must compose them as
 * `…/apps/openregister/#${route}` — which every `gotoPage`/`gotoApp`/`go`
 * helper in this suite already does.
 */

/** `src/views/schema/SchemasIndex.vue` — schema list. */
export const SchemasIndex = '/schemas'

/** `src/views/source/SourcesIndex.vue` — source list. */
export const SourcesIndex = '/sources'

/** `src/views/templates/TemplatesIndex.vue` — template list. */
export const TemplatesIndex = '/templates'

/** `src/views/application/ApplicationsIndex.vue` — application list. */
export const ApplicationsIndex = '/applications'

/** `src/views/object/ObjectsIndex.vue` — object list (also serves object detail). */
export const ObjectsIndex = '/objects'

/** `src/views/organisation/OrganisationsIndex.vue` — organisation list. */
export const OrganisationsIndex = '/organisation'

/** `src/views/configuration/ConfigurationsIndex.vue` — configuration list. */
export const ConfigurationsIndex = '/configurations'

/** `src/views/webhooks/WebhooksIndex.vue` — webhook list. */
export const WebhooksIndex = '/webhooks'

/** `src/views/webhooks/WebhookLogsIndex.vue` — webhook delivery log. */
export const WebhookLogsIndex = '/webhooks/logs'

/** `src/views/Endpoint/EndpointsIndex.vue` — endpoint list. */
export const EndpointsIndex = '/endpoints'

/** `src/views/logs/SearchTrailIndex.vue` — search-trail log. */
export const SearchTrailIndex = '/search-trails'

/** `src/views/logs/AuditTrailIndex.vue` — audit-trail log. */
export const AuditTrailIndex = '/audit-trails'

/** `src/views/files/FilesIndex.vue` — files surface. */
export const FilesIndex = '/files'

/** `src/views/avg/AvgIndex.vue` — AVG / GDPR surface. */
export const AvgIndex = '/avg'

/** `src/views/reports/ReportsIndex.vue` — report/dashboard list. */
export const ReportsIndex = '/reports'

/** `src/views/account/MyAccount.vue` — the signed-in user's own account page. */
export const MyAccount = '/mijn-account'

/** `src/views/roadmap/FeaturesRoadmapIndex.vue` — features roadmap. */
export const FeaturesRoadmapIndex = '/features-roadmap'

/** `src/views/flows/FlowsIndex.vue` — flow list. */
export const FlowsIndex = '/flows'

/** `src/views/deleted/DeletedIndex.vue` — soft-deleted object list. */
export const DeletedIndex = '/deleted'

// ─────────────────────────────────────────────────────────────────────────────
// Parameterised routes.
//
// PascalCase on a function is deliberate here and is the file's own rule
// applied consistently: the identifier IS the component name, and a detail
// page's route needs an id before it is a route. Lower-casing these would
// break the one invariant this module exists to hold.
// ─────────────────────────────────────────────────────────────────────────────

// `src/views/entities/EntityDetail.vue` (`/entities/{id}`) is DELIBERATELY not
// exported. Nothing can drive it hermetically: `openregister_entities` rows are
// detected PII and the routed surface is read-only — `appinfo/routes.php`
// registers `gdprEntities#index|show|destroy|getTypes|getCategories|getStats`
// and NO create. Exporting a route no spec imports would satisfy gate-26 with a
// declaration nobody reads, which is the same failure as the comment the gate's
// comment-masking exists to defeat. The gap is recorded as a reason-bearing
// `@visual exclude` in the component itself instead.

/** `src/views/schema/SchemaDetails.vue` — single schema, by id. */
export const SchemaDetails = (id: string | number): string => `/schemas/${id}`

/** `src/views/application/ApplicationDetails.vue` — single application, by id. */
export const ApplicationDetails = (id: string | number): string =>
	`/applications/${id}`

/** `src/views/flows/FlowDetailPage.vue` — single flow, by id. */
export const FlowDetailPage = (id: string | number): string => `/flows/${id}`

/** `src/views/reports/ReportView.vue` — single report/dashboard, by id. */
export const ReportView = (id: string | number): string => `/reports/${id}`

/**
 * `src/views/integration/IntegrationsView.vue` — the ISOLATED integrations
 * host, which mounts one provider tab on its own rather than inside the
 * ObjectDetails sidebar. `tests/e2e/leaf-screenshots.spec.ts` drives it.
 */
export const IntegrationsView = (
	register: string | number,
	schema: string | number,
	objectId: string,
): string => `/integrations/${register}/${schema}/${objectId}`

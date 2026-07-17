/**
 * OpenRegister v2 component registry (ADR-036).
 *
 * Kind-tagged map passed as the `registry` prop to CnAppRoot. CnPageRenderer
 * resolves each manifest page's `component` string against entries whose
 * `kind === "page"` (with precedence over the deprecated `customComponents`
 * prop, which OpenRegister no longer ships).
 *
 * OpenRegister is the data-platform foundation: its registers, schemas,
 * sources, applications, endpoints and entities are native foundation entities
 * (served by dedicated controllers/stores), NOT register-stored objects, so the
 * library's built-in `index`/`detail` renderers (which resolve via
 * useObjectStore against a register+schema slug) cannot drive them. Every OR
 * page therefore stays a bespoke view referenced here by name — a pure shell
 * dispatch with no page-rendering behaviour change.
 *
 * PERFORMANCE (frontend-code-splitting-and-fetch-efficiency): each view is
 * registered as a Vue async component (`() => import(...)`) rather than a static
 * import, so webpack emits a per-view chunk and the initial bundle no longer
 * front-loads the parser/eval cost of every view (charts, editors, chat, AVG,
 * roadmap, …). A view's chunk is fetched only when its route is first visited.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

/**
 * Wrap a Vue component (or async-component loader) into the v2 registry shape
 * required by CnAppRoot's `registry` prop (`kind: "page"` is the discriminator
 * CnPageRenderer keys page dispatch off — `kind: "widget"`/`"modal"`/
 * `"form-field"`/`"cell-renderer"` entries with the same name are NOT used for
 * page dispatch).
 *
 * @param {(object|Function)} component Vue component options, or an async
 *   component loader `() => import(...)`.
 *
 * @return {object} A `{ kind: "page", component }` registry entry.
 */
function page(component) {
	return { kind: 'page', component }
}

export default {
	Dashboard: page(() => import('./views/dashboard/DashboardIndex.vue')),
	RegistersIndex: page(() => import('./views/register/RegistersIndex.vue')),
	RegisterDetail: page(() => import('./views/register/RegisterDetail.vue')),
	SchemasIndex: page(() => import('./views/schema/SchemasIndex.vue')),
	SchemaDetails: page(() => import('./views/schema/SchemaDetails.vue')),
	SourcesIndex: page(() => import('./views/source/SourcesIndex.vue')),
	OrganisationsIndex: page(() => import('./views/organisation/OrganisationsIndex.vue')),
	ApplicationsIndex: page(() => import('./views/application/ApplicationsIndex.vue')),
	ApplicationDetails: page(() => import('./views/application/ApplicationDetails.vue')),
	ObjectsIndex: page(() => import('./views/object/ObjectsIndex.vue')),
	SearchIndex: page(() => import('./views/search/SearchIndex.vue')),
	FilesIndex: page(() => import('./views/files/FilesIndex.vue')),
	ConfigurationsIndex: page(() => import('./views/configuration/ConfigurationsIndex.vue')),
	DeletedIndex: page(() => import('./views/deleted/DeletedIndex.vue')),
	AuditTrailIndex: page(() => import('./views/logs/AuditTrailIndex.vue')),
	SearchTrailIndex: page(() => import('./views/logs/SearchTrailIndex.vue')),
	WebhooksIndex: page(() => import('./views/webhooks/WebhooksIndex.vue')),
	WebhookLogsIndex: page(() => import('./views/webhooks/WebhookLogsIndex.vue')),
	EndpointsIndex: page(() => import('./views/Endpoint/EndpointsIndex.vue')),
	EntitiesIndex: page(() => import('./views/entities/EntitiesIndex.vue')),
	EntityDetail: page(() => import('./views/entities/EntityDetail.vue')),
	TemplatesIndex: page(() => import('./views/templates/TemplatesIndex.vue')),
	MyAccount: page(() => import('./views/account/MyAccount.vue')),
	AvgIndex: page(() => import('./views/avg/AvgIndex.vue')),
	ReportsIndex: page(() => import('./views/reports/ReportsIndex.vue')),
	ReportView: page(() => import('./views/reports/ReportView.vue')),
	FeaturesRoadmapIndex: page(() => import('./views/roadmap/FeaturesRoadmapIndex.vue')),
	IntegrationsView: page(() => import('./views/integration/IntegrationsView.vue')),
	QualityIndex: page(() => import('./views/quality/QualityIndex.vue')),
	DuplicatesIndex: page(() => import('./views/quality/DuplicatesIndex.vue')),
	MasterEntitiesIndex: page(() => import('./views/quality/MasterEntitiesIndex.vue')),
	QueueHealthIndex: page(() => import('./views/quality/QueueHealthIndex.vue')),
	MergeOperationsIndex: page(() => import('./views/quality/MergeOperationsIndex.vue')),
}

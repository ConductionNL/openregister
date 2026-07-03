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
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

import Dashboard from './views/dashboard/DashboardIndex.vue'
import RegistersIndex from './views/register/RegistersIndex.vue'
import RegisterDetail from './views/register/RegisterDetail.vue'
import SchemasIndex from './views/schema/SchemasIndex.vue'
import SchemaDetails from './views/schema/SchemaDetails.vue'
import SourcesIndex from './views/source/SourcesIndex.vue'
import OrganisationsIndex from './views/organisation/OrganisationsIndex.vue'
import ApplicationsIndex from './views/application/ApplicationsIndex.vue'
import ApplicationDetails from './views/application/ApplicationDetails.vue'
import ObjectsIndex from './views/object/ObjectsIndex.vue'
import SearchIndex from './views/search/SearchIndex.vue'
import ChatIndex from './views/chat/ChatIndex.vue'
import FilesIndex from './views/files/FilesIndex.vue'
import AgentsIndex from './views/agents/AgentsIndex.vue'
import ConfigurationsIndex from './views/configuration/ConfigurationsIndex.vue'
import DeletedIndex from './views/deleted/DeletedIndex.vue'
import AuditTrailIndex from './views/logs/AuditTrailIndex.vue'
import SearchTrailIndex from './views/logs/SearchTrailIndex.vue'
import WebhooksIndex from './views/webhooks/WebhooksIndex.vue'
import WebhookLogsIndex from './views/webhooks/WebhookLogsIndex.vue'
import EndpointsIndex from './views/Endpoint/EndpointsIndex.vue'
import EntitiesIndex from './views/entities/EntitiesIndex.vue'
import EntityDetail from './views/entities/EntityDetail.vue'
import TemplatesIndex from './views/templates/TemplatesIndex.vue'
import MyAccount from './views/account/MyAccount.vue'
import AvgIndex from './views/avg/AvgIndex.vue'
import ReportsIndex from './views/reports/ReportsIndex.vue'
import ReportView from './views/reports/ReportView.vue'
import FeaturesRoadmapIndex from './views/roadmap/FeaturesRoadmapIndex.vue'
import IntegrationsView from './views/integration/IntegrationsView.vue'
import QualityIndex from './views/quality/QualityIndex.vue'
import DuplicatesIndex from './views/quality/DuplicatesIndex.vue'
import MasterEntitiesIndex from './views/quality/MasterEntitiesIndex.vue'
import QueueHealthIndex from './views/quality/QueueHealthIndex.vue'

/**
 * Wrap a Vue component into the v2 registry shape required by CnAppRoot's
 * `registry` prop (`kind: "page"` is the discriminator CnPageRenderer keys
 * page dispatch off — `kind: "widget"`/`"modal"`/`"form-field"`/
 * `"cell-renderer"` entries with the same name are NOT used for page
 * dispatch).
 *
 * @param {object} component Vue component options.
 *
 * @return {object} A `{ kind: "page", component }` registry entry.
 */
function page(component) {
	return { kind: 'page', component }
}

export default {
	Dashboard: page(Dashboard),
	RegistersIndex: page(RegistersIndex),
	RegisterDetail: page(RegisterDetail),
	SchemasIndex: page(SchemasIndex),
	SchemaDetails: page(SchemaDetails),
	SourcesIndex: page(SourcesIndex),
	OrganisationsIndex: page(OrganisationsIndex),
	ApplicationsIndex: page(ApplicationsIndex),
	ApplicationDetails: page(ApplicationDetails),
	ObjectsIndex: page(ObjectsIndex),
	SearchIndex: page(SearchIndex),
	ChatIndex: page(ChatIndex),
	FilesIndex: page(FilesIndex),
	AgentsIndex: page(AgentsIndex),
	ConfigurationsIndex: page(ConfigurationsIndex),
	DeletedIndex: page(DeletedIndex),
	AuditTrailIndex: page(AuditTrailIndex),
	SearchTrailIndex: page(SearchTrailIndex),
	WebhooksIndex: page(WebhooksIndex),
	WebhookLogsIndex: page(WebhookLogsIndex),
	EndpointsIndex: page(EndpointsIndex),
	EntitiesIndex: page(EntitiesIndex),
	EntityDetail: page(EntityDetail),
	TemplatesIndex: page(TemplatesIndex),
	MyAccount: page(MyAccount),
	AvgIndex: page(AvgIndex),
	ReportsIndex: page(ReportsIndex),
	ReportView: page(ReportView),
	FeaturesRoadmapIndex: page(FeaturesRoadmapIndex),
	IntegrationsView: page(IntegrationsView),
	QualityIndex: page(QualityIndex),
	DuplicatesIndex: page(DuplicatesIndex),
	MasterEntitiesIndex: page(MasterEntitiesIndex),
	QueueHealthIndex: page(QueueHealthIndex),
}

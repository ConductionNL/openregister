/**
 * OpenRegister custom-component registry.
 *
 * Flat `{ ComponentName: Component }` map (decidesk convention) passed as the
 * `customComponents` prop to CnAppRoot. CnPageRenderer resolves each manifest
 * page's `component` string against this map for `type:"custom"` pages.
 *
 * OpenRegister is the data-platform foundation: its registers, schemas,
 * sources, applications, endpoints and entities are native foundation entities
 * (served by dedicated controllers/stores), NOT register-stored objects, so the
 * library's built-in `index`/`detail` renderers (which resolve via
 * useObjectStore against a register+schema slug) cannot drive them. Every OR
 * page therefore stays a bespoke view referenced here by name — a pure shell
 * swap with no page-rendering behaviour change.
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

export default {
	Dashboard,
	RegistersIndex,
	RegisterDetail,
	SchemasIndex,
	SchemaDetails,
	SourcesIndex,
	OrganisationsIndex,
	ApplicationsIndex,
	ApplicationDetails,
	ObjectsIndex,
	SearchIndex,
	ChatIndex,
	FilesIndex,
	AgentsIndex,
	ConfigurationsIndex,
	DeletedIndex,
	AuditTrailIndex,
	SearchTrailIndex,
	WebhooksIndex,
	WebhookLogsIndex,
	EndpointsIndex,
	EntitiesIndex,
	EntityDetail,
	TemplatesIndex,
	MyAccount,
	AvgIndex,
	ReportsIndex,
	ReportView,
	FeaturesRoadmapIndex,
	IntegrationsView,
}

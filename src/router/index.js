import Vue from 'vue'
// eslint-disable-next-line n/no-unpublished-import
import Router from 'vue-router'

import Dashboard from '../views/dashboard/DashboardIndex.vue'
import RegistersIndex from '../views/register/RegistersIndex.vue'
import RegisterDetail from '../views/register/RegisterDetail.vue'
import SchemasIndex from '../views/schema/SchemasIndex.vue'
import SchemaDetails from '../views/schema/SchemaDetails.vue'
import SourcesIndex from '../views/source/SourcesIndex.vue'
import OrganisationsIndex from '../views/organisation/OrganisationsIndex.vue'
import ApplicationsIndex from '../views/application/ApplicationsIndex.vue'
import ApplicationDetails from '../views/application/ApplicationDetails.vue'
import ObjectsIndex from '../views/object/ObjectsIndex.vue'
import SearchIndex from '../views/search/SearchIndex.vue'
import ChatIndex from '../views/chat/ChatIndex.vue'
import FilesIndex from '../views/files/FilesIndex.vue'
import AgentsIndex from '../views/agents/AgentsIndex.vue'
import ConfigurationsIndex from '../views/configuration/ConfigurationsIndex.vue'
import DeletedIndex from '../views/deleted/DeletedIndex.vue'
import AuditTrailIndex from '../views/logs/AuditTrailIndex.vue'
import SearchTrailIndex from '../views/logs/SearchTrailIndex.vue'
import WebhooksIndex from '../views/webhooks/WebhooksIndex.vue'
import WebhookLogsIndex from '../views/webhooks/WebhookLogsIndex.vue'
import EndpointsIndex from '../views/Endpoint/EndpointsIndex.vue'
import EntitiesIndex from '../views/entities/EntitiesIndex.vue'
import EntityDetail from '../views/entities/EntityDetail.vue'
import TemplatesIndex from '../views/templates/TemplatesIndex.vue'
import MyAccount from '../views/account/MyAccount.vue'
import AvgIndex from '../views/avg/AvgIndex.vue'
import ReportsIndex from '../views/reports/ReportsIndex.vue'
import ReportView from '../views/reports/ReportView.vue'
import FeaturesRoadmapIndex from '../views/roadmap/FeaturesRoadmapIndex.vue'
import IntegrationsView from '../views/integration/IntegrationsView.vue'

Vue.use(Router)

// Map top-level paths to existing navigationStore keys for backward-compatibility
export const routeKeyByPath = {
	'/': 'dashboard',
	'/registers': 'registers',
	'/schemas': 'schemas',
	'/sources': 'sources',
	'/organisation': 'organisations',
	'/applications': 'applications',
	'/objects': 'objects',
	'/tables': 'tableSearch',
	'/chat': 'chat',
	'/files': 'files',
	'/agents': 'agents',
	'/configurations': 'configurations',
	'/deleted': 'deleted',
	'/audit-trails': 'auditTrails',
	'/search-trails': 'searchTrails',
	'/endpoints': 'endpoints',
	'/avg': 'avg',
	'/reports': 'reports',
	'/mijn-account': 'myAccount',
}

const router = new Router({
	mode: 'history',
	base: '/index.php/apps/openregister/',
	routes: [
		{ path: '/', component: Dashboard },
		{ path: '/registers', component: RegistersIndex },
		{ path: '/registers/:id', component: RegisterDetail },
		{ path: '/schemas', component: SchemasIndex },
		{ path: '/schemas/:id', component: SchemaDetails },
		{ path: '/sources', component: SourcesIndex },
		{ path: '/organisation', component: OrganisationsIndex },
		{ path: '/applications', name: 'applications', component: ApplicationsIndex },
		{ path: '/applications/:id', name: 'applicationDetails', component: ApplicationDetails },
		{ path: '/objects', component: ObjectsIndex },
		// Deep-link to a specific object. ObjectsIndex watches the
		// {register, schema, id} params and primes objectStore.objectItem
		// so the detail view (and its registry-driven Integrations tab)
		// renders without needing a click-through. This is what the
		// per-leaf screenshot harness targets.
		{ path: '/objects/:register/:schema/:id', name: 'objectDetail', component: ObjectsIndex },
		{ path: '/tables', component: SearchIndex },
		{ path: '/chat', component: ChatIndex },
		{ path: '/files', component: FilesIndex },
		{ path: '/agents', component: AgentsIndex },
		{ path: '/configurations', component: ConfigurationsIndex },
		{ path: '/deleted', component: DeletedIndex },
		{ path: '/audit-trails', component: AuditTrailIndex },
		{ path: '/search-trails', component: SearchTrailIndex },
		{ path: '/webhooks', component: WebhooksIndex },
		{ path: '/webhooks/logs', component: WebhookLogsIndex },
		{ path: '/endpoints', component: EndpointsIndex },
		{ path: '/entities', component: EntitiesIndex },
		{ path: '/entities/:id', name: 'entityDetails', component: EntityDetail },
		{ path: '/templates', component: TemplatesIndex },
		{ path: '/mijn-account', name: 'myAccount', component: MyAccount },
		{ path: '/avg', name: 'avg', component: AvgIndex },
		{ path: '/reports', name: 'reports', component: ReportsIndex },
		{ path: '/reports/:id', name: 'reportView', component: ReportView },
		{ path: '/features-roadmap', name: 'features-roadmap', component: FeaturesRoadmapIndex },
		// Standalone integration registry surface (used by the per-leaf
		// screenshot harness). Bypasses ObjectDetails so the legacy
		// sub-resource plugins don't race the render.
		{ path: '/integrations/:register/:schema/:objectId', name: 'integrationsView', component: IntegrationsView },
		{ path: '*', redirect: '/' },
	],
})

export default router

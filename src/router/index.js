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
		{ path: '/tables', component: SearchIndex },
		{ path: '/chat', component: ChatIndex },
		{ path: '/files', component: FilesIndex },
		{ path: '/agents', component: AgentsIndex },
		{ path: '/configurations', component: ConfigurationsIndex },
		{ path: '/deleted', component: DeletedIndex },
		{ path: '/audit-trails', component: AuditTrailIndex },
		{ path: '/search-trails', component: SearchTrailIndex },
		{ path: '*', redirect: '/' },
	],
})

export default router

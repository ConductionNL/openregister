<template>
	<DashboardSideBar v-if="$route.path === '/'" />
	<SearchSideBar v-else-if="$route.path.startsWith('/tables')" />
	<RegisterSideBar v-else-if="/^\/registers\/.+/.test($route.path)" />
	<RegistersSideBar v-else-if="$route.path.startsWith('/registers')" />
	<DeletedSideBar v-else-if="$route.path.startsWith('/deleted')" />
	<EntitiesSideBar v-else-if="$route.path.startsWith('/entities')" />
	<AuditTrailSideBar v-else-if="$route.path.startsWith('/audit-trails')" />
	<SearchTrailSideBar v-else-if="$route.path.startsWith('/search-trails')" />
	<!--
		The flow canvas's controls are NOT listed here. They are declared on the
		manifest page as `sidebarComponent: FlowDetailSidebar`, and #3103 made
		CnAppRoot hand that component to this app through the #sidebar slot
		prop, so App.vue renders it directly.

		A hardcoded <FlowDetailSidebar> used to sit here as a workaround from
		when the manifest key rendered nothing. Once #3103 made the manifest
		route work, BOTH rendered, and the e2e caught it exactly as it should:

		  strict mode violation: locator('.cn-flow-sidebar') resolved to 2 elements

		The manifest is the single source of truth for a page's sidebar. Adding
		a route here for a page that declares `sidebarComponent` will duplicate
		it again.
	-->
</template>

<script>
import DashboardSideBar from './dashboard/DashboardSideBar.vue'
import DeletedSideBar from './deleted/DeletedSideBar.vue'
import EntitiesSideBar from './entities/EntitiesSideBar.vue'
import AuditTrailSideBar from './logs/AuditTrailSideBar.vue'
import SearchTrailSideBar from './logs/SearchTrailSideBar.vue'
import RegisterSideBar from './register/RegisterSideBar.vue'
import RegistersSideBar from './register/RegistersSideBar.vue'
import SearchSideBar from './search/SearchSideBar.vue'

export default {
	name: 'SideBars',
	components: {
		SearchSideBar,
		DashboardSideBar,
		RegisterSideBar,
		RegistersSideBar,
		DeletedSideBar,
		EntitiesSideBar,
		AuditTrailSideBar,
		SearchTrailSideBar,
	},
}
</script>

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
import { defineAsyncComponent } from 'vue'
import DashboardSideBar from './dashboard/DashboardSideBar.vue'
import RegisterSideBar from './register/RegisterSideBar.vue'
import RegistersSideBar from './register/RegistersSideBar.vue'

// The sidebars of the dashboard and the register pages stay in the main
// chunk: they show with those pages' first render, and loaded as a chunk
// they appeared, and sent their reads, a task later (Registers measured
// ~90 ms slower). The others show on less used routes, so each loads as its
// own chunk when its route first shows it; imported statically, they and
// their styles (inserted when the module runs) were part of the code every
// OpenRegister page evaluates before it renders.
const DeletedSideBar = defineAsyncComponent(() => import('./deleted/DeletedSideBar.vue'))
const EntitiesSideBar = defineAsyncComponent(() => import('./entities/EntitiesSideBar.vue'))
const AuditTrailSideBar = defineAsyncComponent(() => import('./logs/AuditTrailSideBar.vue'))
const SearchTrailSideBar = defineAsyncComponent(() => import('./logs/SearchTrailSideBar.vue'))
const SearchSideBar = defineAsyncComponent(() => import('./search/SearchSideBar.vue'))

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

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
		The flow canvas's controls — step palette, Save, Run, run history.

		These are declared on the manifest page as `sidebarComponent:
		FlowDetailSidebar`, and CnAppRoot does resolve that key. It could never
		render here, though: CnAppRoot only falls back to it as the DEFAULT
		content of its #sidebar slot, and this app fills that slot itself, so
		consumer content wins by Vue's ordinary slot mechanic. The manifest key
		was live config that rendered nothing.

		The symptom was a flow page with no way to save, run, or add a step —
		while its own empty state said "Add a step from the sidebar".
	-->
	<FlowDetailSidebar v-else-if="/^\/flows\/.+/.test($route.path)" />
</template>

<script>
import FlowDetailSidebar from '../views/flows/FlowDetailSidebar.vue'
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
		FlowDetailSidebar,
	},
}
</script>

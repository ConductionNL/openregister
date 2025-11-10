<template>
	<div>
		<DashboardSideBar v-if="$route.path === '/'" />
		<SearchSideBar v-else-if="$route.path.startsWith('/tables')" />
		<RegistersSideBar v-else-if="$route.path.startsWith('/registers')" />
		<RegisterSideBar v-else-if="/^\/registers\/.+/.test($route.path)" />
		<ChatSideBar v-else-if="$route.path.startsWith('/chat')" />
		<DeletedSideBar v-else-if="$route.path.startsWith('/deleted')" />
		<AuditTrailSideBar v-else-if="$route.path.startsWith('/audit-trails')" />
		<SearchTrailSideBar v-else-if="$route.path.startsWith('/search-trails')" />
	</div>
</template>

<script>
import SearchSideBar from './search/SearchSideBar.vue'
import DashboardSideBar from './dashboard/DashboardSideBar.vue'
import RegisterSideBar from './register/RegisterSideBar.vue'
import RegistersSideBar from './register/RegistersSideBar.vue'
import ChatSideBar from './chat/ChatSideBar.vue'
import DeletedSideBar from './deleted/DeletedSideBar.vue'
import AuditTrailSideBar from './logs/AuditTrailSideBar.vue'
import SearchTrailSideBar from './logs/SearchTrailSideBar.vue'

export default {
	name: 'SideBars',
	components: {
		SearchSideBar,
		DashboardSideBar,
		RegisterSideBar,
		RegistersSideBar,
		ChatSideBar,
		DeletedSideBar,
		AuditTrailSideBar,
		SearchTrailSideBar,
	},
}
</script>

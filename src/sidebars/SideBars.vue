<template>
	<DashboardSideBar v-if="$route.path === '/'" />
	<SearchSideBar v-else-if="$route.path.startsWith('/tables')" />
	<RegisterSideBar v-else-if="/^\/registers\/.+/.test($route.path)" />
	<RegistersSideBar v-else-if="$route.path.startsWith('/registers')" />
	<ChatSideBar v-else-if="$route.path.startsWith('/chat')" />
	<DeletedSideBar v-else-if="$route.path.startsWith('/deleted')" />
<<<<<<< HEAD
=======
	<EntitiesSideBar v-else-if="$route.path.startsWith('/entities')" />
>>>>>>> origin/development
	<AuditTrailSideBar v-else-if="$route.path.startsWith('/audit-trails')" />
	<SearchTrailSideBar v-else-if="$route.path.startsWith('/search-trails')" />
</template>

<script>
import SearchSideBar from './search/SearchSideBar.vue'
import DashboardSideBar from './dashboard/DashboardSideBar.vue'
import RegisterSideBar from './register/RegisterSideBar.vue'
import RegistersSideBar from './register/RegistersSideBar.vue'
import ChatSideBar from './chat/ChatSideBar.vue'
import DeletedSideBar from './deleted/DeletedSideBar.vue'
import EntitiesSideBar from './entities/EntitiesSideBar.vue'
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
		EntitiesSideBar,
		AuditTrailSideBar,
		SearchTrailSideBar,
	},
}
</script>

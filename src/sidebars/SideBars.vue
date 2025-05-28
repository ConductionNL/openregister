<script setup>
import { navigationStore } from '../store/store.js'
</script>

<template>
	<div>
		<SearchSideBar v-if="navigationStore.selected === 'search'" />
		<DashboardSideBar v-if="navigationStore.selected === 'dashboard'" />
		<RegisterSideBar v-if="navigationStore.selected === 'register-detail'" />
		<RegistersSideBar v-if="navigationStore.selected === 'registers'" />
		<DeletedSideBar v-if="navigationStore.selected === 'deleted'" />
		<LogsSideBar v-if="navigationStore.selected === 'logs'" />
	</div>
</template>

<script>
import SearchSideBar from './search/SearchSideBar.vue'
import DashboardSideBar from './dashboard/DashboardSideBar.vue'
import RegisterSideBar from './register/RegisterSideBar.vue'
import RegistersSideBar from './register/RegistersSideBar.vue'
import DeletedSideBar from './deleted/DeletedSideBar.vue'
import LogsSideBar from './logs/LogsSideBar.vue'

export default {
	name: 'SideBars',
	components: {
		SearchSideBar,
		DashboardSideBar,
		RegisterSideBar,
		RegistersSideBar,
		DeletedSideBar,
		LogsSideBar,
	},
}
</script>

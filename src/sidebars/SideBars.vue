<script setup>
import { navigationStore } from '../store/store.js'
</script>

<template>
	<div>
		<SearchSideBar v-if="navigationStore.selected === 'search'" />
		<DashboardSideBar v-if="navigationStore.selected === 'dashboard' || navigationStore.selected === 'registers'" />
		<RegisterSideBar v-if="navigationStore.selected === 'register-detail'" />
	</div>
</template>

<script>
import SearchSideBar from './search/SearchSideBar.vue'
import DashboardSideBar from './dashboard/DashboardSideBar.vue'
import RegisterSideBar from './register/RegisterSideBar.vue'

export default {
	name: 'SideBars',
	components: {
		SearchSideBar,
		DashboardSideBar,
		RegisterSideBar,
	},
}
</script>

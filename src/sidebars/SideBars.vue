<script setup>
import { navigationStore } from '../store/store.js'
</script>

<template>
	<div>
		<SearchSideBar v-if="navigationStore.selected === 'search'" />
		<DashboardSideBar v-if="navigationStore.selected === 'dashboard'" />
	</div>
</template>

<script>
import SearchSideBar from './search/SearchSideBar.vue'
import DashboardSideBar from './dashboard/DashboardSideBar.vue'

export default {
	name: 'SideBars',
	components: {
		SearchSideBar,
		DashboardSideBar,
	},
}
</script>

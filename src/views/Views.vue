<script setup>
import { navigationStore } from '../store/store.js'
</script>

<template>
	<!-- Placeholder div for all of the views-->
	<NcAppContent>
		<template #default>
			<Dashboard v-if="navigationStore.selected === 'dashboard'" />
			<RegistersList v-if="navigationStore.selected === 'registers'" />
		</template>
	</NcAppContent>
</template>

<script>
import { NcAppContent } from '@nextcloud/vue'
import Dashboard from './dashboard/DashboardIndex.vue'
import RegistersList from './register/RegistersList.vue'

export default {
	name: 'Views',
	components: {
		Dashboard,
		NcAppContent,
		RegistersList,
	},
}
</script>

<script setup>
import { navigationStore } from '../store/store.js'
</script>

<template>
	<!-- Placeholder div for all of the views-->
	<NcAppContent>
		<template #default>
			<Dashboard v-if="navigationStore.selected === 'dashboard'" />
		</template>
	</NcAppContent>
</template>

<script>
import { NcAppContent } from '@nextcloud/vue'
import Dashboard from './dashboard/DashboardIndex.vue'

export default {
	name: 'Views',
	components: {
		Dashboard,
		NcAppContent,
	},
}
</script>

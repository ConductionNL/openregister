<script setup>
import { navigationStore } from '../store/store.js'
</script>

<template>
	<!-- Placeholder div for all of the views-->
	<NcAppContent>
		<template #default>
			<Dashboard v-if="navigationStore.selected === 'dashboard'" />
			<RegistersIndex v-if="navigationStore.selected === 'registers'" />
			<SourcesIndex v-if="navigationStore.selected === 'sources'" />
			<SchemasIndex v-if="navigationStore.selected === 'schemas'" />
			<ObjectsIndex v-if="navigationStore.selected === 'objects'" />
		</template>
	</NcAppContent>
</template>

<script>
import { NcAppContent } from '@nextcloud/vue'
import Dashboard from './dashboard/DashboardIndex.vue'
import RegistersIndex from './register/RegistersIndex.vue'
import SourcesIndex from './source/SourcesIndex.vue'
import SchemasIndex from './schema/SchemasIndex.vue'
import ObjectsIndex from './object/ObjectsIndex.vue'
export default {
	name: 'Views',
	components: {
		Dashboard,
		NcAppContent,
		RegistersIndex,
		SourcesIndex,
		SchemasIndex,
		ObjectsIndex,
	},
}
</script>

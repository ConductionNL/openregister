<script setup>
import { navigationStore } from '../store/store.js'
</script>

<template>
	<!-- Placeholder div for all of the views-->
	<NcAppContent>
		<template #default>
			<Dashboard v-if="navigationStore.selected === 'dashboard'" />
			<RegistersIndex v-if="navigationStore.selected === 'registers'" />
			<RegisterDetail v-if="navigationStore.selected === 'register-detail'" />
			<SourcesIndex v-if="navigationStore.selected === 'sources'" />
			<SchemasIndex v-if="navigationStore.selected === 'schemas'" />
			<SchemaDetails v-if="navigationStore.selected === 'schemaDetails'" />
			<ObjectsIndex v-if="navigationStore.selected === 'objects'" />
			<SearchIndex v-if="navigationStore.selected === 'tableSearch'" />
			<ConfigurationsIndex v-if="navigationStore.selected === 'configurations'" />
			<DeletedIndex v-if="navigationStore.selected === 'deleted'" />
			<AuditTrailIndex v-if="navigationStore.selected === 'auditTrails'" />
			<SearchTrailIndex v-if="navigationStore.selected === 'searchTrails'" />
		</template>
	</NcAppContent>
</template>

<script>
import { NcAppContent } from '@nextcloud/vue'
import Dashboard from './dashboard/DashboardIndex.vue'
import RegistersIndex from './register/RegistersIndex.vue'
import SourcesIndex from './source/SourcesIndex.vue'
import SchemasIndex from './schema/SchemasIndex.vue'
import SchemaDetails from './schema/SchemaDetails.vue'
import ObjectsIndex from './object/ObjectsIndex.vue'
import SearchIndex from './search/SearchIndex.vue'
import ConfigurationsIndex from './configuration/ConfigurationsIndex.vue'
import RegisterDetail from './register/RegisterDetail.vue'
import DeletedIndex from './deleted/DeletedIndex.vue'
import AuditTrailIndex from './logs/AuditTrailIndex.vue'
import SearchTrailIndex from './logs/SearchTrailIndex.vue'

export default {
	name: 'Views',
	components: {
		Dashboard,
		NcAppContent,
		RegistersIndex,
		SourcesIndex,
		SchemasIndex,
		SchemaDetails,
		ObjectsIndex,
		SearchIndex,
		ConfigurationsIndex,
		RegisterDetail,
		DeletedIndex,
		AuditTrailIndex,
		SearchTrailIndex,
	},
}
</script>

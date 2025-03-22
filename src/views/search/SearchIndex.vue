<script setup>
import { objectStore } from '../../store/store.js'
import { computed } from 'vue'

const pageTitle = computed(() => {
	if (!objectStore.activeRegister) {
		return 'No register selected'
	}
	
	const registerName = objectStore.activeRegister.label || objectStore.activeRegister.title
	
	if (!objectStore.activeSchema) {
		return `${registerName} / No schema selected`
	}
	
	const schemaName = objectStore.activeSchema.label || objectStore.activeSchema.title
	return `${registerName} / ${schemaName}`
})

const showNoRegisterWarning = computed(() => !objectStore.activeRegister)
const showNoSchemaWarning = computed(() => objectStore.activeRegister && !objectStore.activeSchema)
const showNoObjectsMessage = computed(() => {
	return objectStore.activeRegister 
		&& objectStore.activeSchema 
		&& !objectStore.loading 
		&& !objectStore.objectList?.results?.length
})
</script>

<template>
	<NcAppContent>
		<span class="pageHeaderContainer">
			<h2 class="pageHeader">
				{{ pageTitle }}
			</h2>
		</span>

		<!-- Warning when no register is selected -->
		<NcNoteCard v-if="showNoRegisterWarning" type="warning">
			<p>Please select a register in the sidebar to view objects</p>
		</NcNoteCard>

		<!-- Warning when no schema is selected -->
		<NcNoteCard v-if="showNoSchemaWarning" type="warning">
			<p>Please select a schema in the sidebar to view objects</p>
		</NcNoteCard>

		<!-- Message when no objects found -->
		<NcNoteCard v-if="showNoObjectsMessage" type="info">
			<p>There are no objects that match this filter</p>
		</NcNoteCard>

		<NcLoadingIcon v-if="objectStore.loading && !objectStore.objectList?.results?.length"
			:size="64"
			class="loadingIcon"
			appearance="dark"
			name="Objects loading" />

		<SearchList v-if="objectStore.objectList?.results?.length" />
	</NcAppContent>
</template>

<script>
import { NcAppContent, NcNoteCard, NcLoadingIcon } from '@nextcloud/vue'
import SearchList from './SearchList.vue'

export default {
	name: 'SearchIndex',
	components: {
		NcAppContent,
		NcNoteCard,
		NcLoadingIcon,
		SearchList,
	},
}
</script>

<style scoped>
.pageHeaderContainer {
    display: flex;
    align-items: center;
    justify-content: space-between;
}
.pageHeaderContainer > .loadingIcon {
    margin-inline-end: 1rem;
}
</style>

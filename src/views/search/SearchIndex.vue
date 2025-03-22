<script setup>
import { objectStore, registerStore, schemaStore } from '../../store/store.js'
import { computed } from 'vue'
import { NcButton } from '@nextcloud/vue'
import Delete from 'vue-material-design-icons/Delete.vue'

const pageTitle = computed(() => {
	if (!registerStore.registerItem) {
		return 'No register selected'
	}
	
	const registerName = (registerStore.registerItem.label || registerStore.registerItem.title).charAt(0).toUpperCase() + 
		(registerStore.registerItem.label || registerStore.registerItem.title).slice(1)
	const objectCount = objectStore.objectList?.results?.length || 0
	const objectTotal = objectStore.objectList?.total || 0
	
	if (!schemaStore.schemaItem) {
		return `${registerName} / No schema selected`
	}
	
	const schemaName = (schemaStore.schemaItem.label || schemaStore.schemaItem.title).charAt(0).toUpperCase() + 
		(schemaStore.schemaItem.label || schemaStore.schemaItem.title).slice(1)
	return `${registerName} / ${schemaName} (${objectCount} of ${objectTotal})`
})

const showNoRegisterWarning = computed(() => !registerStore.registerItem)
const showNoSchemaWarning = computed(() => registerStore.registerItem && !schemaStore.schemaItem)
const showNoObjectsMessage = computed(() => {
	return registerStore.registerItem 
		&& schemaStore.schemaItem 
		&& !objectStore.loading 
		&& !objectStore.objectList?.results?.length
})
</script>

<template>
	<NcAppContent>
		<span class="pageHeaderContainer">
			<h1 class="pageHeader">
				{{ pageTitle }}
			</h1>
			<NcButton 
				:disabled="!objectStore.selectedObjects.length" 
				type="error" 
				@click="() => massDeleteObjectModal = true">
				<template #icon>
					<Delete :size="20" />
				</template>
				Delete {{ objectStore.selectedObjects.length }} 
				{{ objectStore.selectedObjects.length > 1 ? 'objects' : 'object' }}
			</NcButton>
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

		<NcLoadingIcon v-if="objectStore.loading"
			:size="64"
			class="loadingIcon"
			appearance="dark"
			name="Objects loading" />

		<SearchList v-if="!objectStore.loading && objectStore.objectList?.results?.length && registerStore.registerItem && schemaStore.schemaItem" />
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
		NcButton,
		Delete,
	},
}
</script>

<style scoped>
.pageHeaderContainer {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 0;
}

.pageHeader {
    font-family: system-ui, -apple-system, "Segoe UI", Roboto, Oxygen-Sans, Cantarell, Ubuntu, "Helvetica Neue", "Noto Sans", "Liberation Sans", Arial, sans-serif, "Apple Color Emoji", "Segoe UI Emoji", "Segoe UI Symbol", "Noto Color Emoji";
	font-size: 30px;
	font-weight: 600;
    margin-left: 50px;
    text-transform: capitalize;
}

/* Add styles for the delete button container */
:deep(.button-vue) {
    margin-top: 15px;
    margin-right: 15px;
    padding-right: 15px;
}

/* Add styles for note cards */
:deep(.notecard) {
    margin-left: 15px;
    margin-right: 15px;
}

.loadingIcon {
    margin-inline-end: 1rem;
}
</style>

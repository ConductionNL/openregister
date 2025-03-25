<script setup>
import { objectStore, registerStore, schemaStore, navigationStore } from '../../store/store.js'
import { computed } from 'vue'
import { NcButton } from '@nextcloud/vue'

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

const openAddObjectModal = () => {
	objectStore.setObjectItem(null) // Clear any existing object
	navigationStore.setModal('editObject')
}
</script>

<template>
	<NcAppContent>
		<span class="pageHeaderContainer">
			<h1 class="pageHeader">
				{{ pageTitle }}
			</h1>

			<NcActions 
			:force-name="true" 
			:inline="1"
			:primary="true" 
			:menu-name="`Bulk action for ${objectStore.selectedObjects?.length || 0} objects`">
				<NcActionButton 
					@click="openAddObjectModal" 
					:disabled="!registerStore.registerItem || !schemaStore.schemaItem"
					:title="!registerStore.registerItem ? 'Please select a register to add an object' : (!schemaStore.schemaItem ? 'Please select a schema to add an object' : '')">
					<template #icon>
						<Pencil :size="20" />
					</template>
					Add
				</NcActionButton>
				<NcActionButton>
					<template #icon>
						<Upload :size="20" />
					</template>
					Upload
				</NcActionButton>
				<NcActionButton>
					<template #icon>
						<Download :size="20" />
					</template>
					Download
				</NcActionButton>
				<NcActionButton @click="() => massDeleteObjectModal = true">
					<template #icon>
						<Delete :size="20" />
					</template>
					Delete
				</NcActionButton>
			</NcActions>
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
import { NcAppContent, NcNoteCard, NcLoadingIcon, NcActions, NcActionButton } from '@nextcloud/vue'
import SearchList from './SearchList.vue'
import LightningBolt from 'vue-material-design-icons/LightningBolt.vue'
import Delete from 'vue-material-design-icons/Delete.vue'
import ArrowRight from 'vue-material-design-icons/ArrowRight.vue'
import Download from 'vue-material-design-icons/Download.vue'
import Pencil from 'vue-material-design-icons/Pencil.vue'
import Upload from 'vue-material-design-icons/Upload.vue'

export default {
	name: 'SearchIndex',
	components: {
		NcAppContent,
		NcNoteCard,
		NcLoadingIcon,
		SearchList,
		NcButton,
		Delete,
		LightningBolt,
		ArrowRight,
		Download,
		Pencil,
		Upload,
		
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

<script setup>
import { schemaStore, navigationStore, searchStore } from '../../store/store.js'
</script>

<template>
	<NcAppContentList>
		<ul>
			<div class="searchListHeader">
				<NcTextField
					:value.sync="searchStore.search"
					:show-trailing-button="searchStore.search !== ''"
					label="Search"
					class="searchField"
					trailing-button-icon="close"
					@trailing-button-click="schemaStore.refreshSchemaList()">
					<Magnify :size="20" />
				</NcTextField>
				<NcActions>
					<NcActionButton @click="schemaStore.refreshSchemaList()">
						<template #icon>
							<Refresh :size="20" />
						</template>
						Refresh
					</NcActionButton>
					<NcActionButton @click="schemaStore.setSchemaItem(null); navigationStore.setModal('uploadSchema')">
						<template #icon>
							<Upload :size="20" />
						</template>
						Upload
					</NcActionButton>
					<NcActionButton @click="schemaStore.setSchemaItem(null); navigationStore.setModal('editSchema')">
						<template #icon>
							<Plus :size="20" />
						</template>
						Add Schema
					</NcActionButton>
				</NcActions>
			</div>
			<div v-if="schemaStore.schemaList && schemaStore.schemaList.length > 0">
				<NcListItem v-for="(schema, i) in schemaStore.schemaList"
					:key="`${schema}${i}`"
					:name="schema.title"
					:active="schemaStore.schemaItem?.id === schema?.id"
					:force-display-actions="true"
					@click="schemaStore.setSchemaItem(schema)">
					<template #icon>
						<FileTreeOutline :class="schemaStore.schemaItem?.id === schema.id && 'selectedSchemaIcon'"
							disable-menu
							:size="44" />
					</template>
					<template #subname>
						{{ schema?.description }}
					</template>
					<template #actions>
						<NcActionButton @click="schemaStore.setSchemaItem(schema); navigationStore.setModal('editSchema')">
							<template #icon>
								<Pencil />
							</template>
							Edit
						</NcActionButton>
						<NcActionButton @click="schemaStore.setSchemaItem(schema); schemaStore.setSchemaPropertyKey(null); navigationStore.setModal('editSchemaProperty')">
							<template #icon>
								<PlusCircleOutline />
							</template>
							Add Property
						</NcActionButton>
						<NcActionButton @click="schemaStore.downloadSchema(new Schema(schema))">
							<template #icon>
								<Download />
							</template>
							Download
						</NcActionButton>
						<NcActionButton @click="schemaStore.setSchemaItem(schema); navigationStore.setDialog('deleteSchema')">
							<template #icon>
								<TrashCanOutline />
							</template>
							Delete
						</NcActionButton>
					</template>
				</NcListItem>
			</div>
		</ul>

		<NcLoadingIcon v-if="!schemaStore.schemaList"
			class="loadingIcon"
			:size="64"
			appearance="dark"
			name="Loading schemas" />

		<div v-if="schemaStore.schemaList.length === 0">
			No schemas have been defined yet.
		</div>
	</NcAppContentList>
</template>

<script>
import { NcListItem, NcActionButton, NcAppContentList, NcTextField, NcLoadingIcon, NcActions } from '@nextcloud/vue'
import { Schema } from '../../entities/index.js'
import Magnify from 'vue-material-design-icons/Magnify.vue'
import FileTreeOutline from 'vue-material-design-icons/FileTreeOutline.vue'
import Refresh from 'vue-material-design-icons/Refresh.vue'
import Plus from 'vue-material-design-icons/Plus.vue'
import Pencil from 'vue-material-design-icons/Pencil.vue'
import TrashCanOutline from 'vue-material-design-icons/TrashCanOutline.vue'
import PlusCircleOutline from 'vue-material-design-icons/PlusCircleOutline.vue'
import Upload from 'vue-material-design-icons/Upload.vue'
import Download from 'vue-material-design-icons/Download.vue'

export default {
	name: 'SchemasList',
	components: {
		NcListItem,
		NcActions,
		NcActionButton,
		NcAppContentList,
		NcTextField,
		NcLoadingIcon,
		Magnify,
		FileTreeOutline,
		Refresh,
		Plus,
		Pencil,
		TrashCanOutline,
		PlusCircleOutline,
		Upload,
	},
	mounted() {
		schemaStore.refreshSchemaList()
	},
}
</script>

<style>
/* Styles remain the same */
</style>

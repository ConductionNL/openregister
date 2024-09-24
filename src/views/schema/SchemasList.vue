<script setup>
import { schemaStore, navigationStore, searchStore } from '../../store/store.js'
</script>

<template>
	<NcAppContentList>
		<ul>
			<div class="listHeader">
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
						Ververs
					</NcActionButton>
					<NcActionButton @click="schemaStore.setSchemaItem({}); navigationStore.setModal('editSchema')">
						<template #icon>
							<Plus :size="20" />
						</template>
						Schema toevoegen
					</NcActionButton>
				</NcActions>
			</div>
			<div v-if="schemaStore.schemaList && schemaStore.schemaList.length > 0">
				<NcListItem v-for="(schema, i) in schemaStore.schemaList"
					:key="`${schema}${i}`"
					:name="schema.name"
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
							Bewerken
						</NcActionButton>
						<NcActionButton @click="schemaStore.setSchemaItem(schema); navigationStore.setDialog('deleteSchema')">
							<template #icon>
								<TrashCanOutline />
							</template>
							Verwijderen
						</NcActionButton>
					</template>
				</NcListItem>
			</div>
		</ul>

		<NcLoadingIcon v-if="!schemaStore.schemaList"
			class="loadingIcon"
			:size="64"
			appearance="dark"
			name="Schema's aan het laden" />

		<div v-if="schemaStore.schemaList.length === 0">
			Er zijn nog geen schema's gedefinieerd.
		</div>
	</NcAppContentList>
</template>

<script>
import { NcListItem, NcActionButton, NcAppContentList, NcTextField, NcLoadingIcon, NcActions } from '@nextcloud/vue'
import Magnify from 'vue-material-design-icons/Magnify.vue'
import FileTreeOutline from 'vue-material-design-icons/FileTreeOutline.vue'
import Refresh from 'vue-material-design-icons/Refresh.vue'
import Plus from 'vue-material-design-icons/Plus.vue'
import Pencil from 'vue-material-design-icons/Pencil.vue'
import TrashCanOutline from 'vue-material-design-icons/TrashCanOutline.vue'

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
	},
	mounted() {
		schemaStore.refreshSchemaList()
	},
}
</script>

<style>
/* Styles remain the same */
</style>

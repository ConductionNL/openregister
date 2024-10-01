<script setup>
import { objectStore, navigationStore, searchStore } from '../../store/store.js'
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
					@trailing-button-click="objectStore.refreshObjectList()">
					<Magnify :size="20" />
				</NcTextField>
				<NcActions>
					<NcActionButton @click="objectStore.refreshObjectList()">
						<template #icon>
							<Refresh :size="20" />
						</template>
						Refresh
					</NcActionButton>
					<NcActionButton @click="objectStore.setObjectItem(null); navigationStore.setModal('editObject')">
						<template #icon>
							<Plus :size="20" />
						</template>
						Add Object
					</NcActionButton>
				</NcActions>
			</div>
			<div v-if="objectStore.objectList && objectStore.objectList.length > 0">
				<NcListItem v-for="(object, i) in objectStore.objectList"
					:key="`${object}${i}`"
					:name="object.uuid"
					:active="objectStore.objectItem?.id === object?.id"
					:force-display-actions="true"
					@click="objectStore.setObjectItem(object)">
					<template #icon>
						<DatabaseOutline :class="objectStore.objectItem?.id === object.id && 'selectedObjectIcon'"
							disable-menu
							:size="44" />
					</template>
					<template #subname>
						{{ JSON.stringify(object?.object) }}
					</template>
					<template #actions>
						<NcActionButton @click="objectStore.setObjectItem(object); navigationStore.setModal('editObject')">
							<template #icon>
								<Pencil />
							</template>
							Edit
						</NcActionButton>
						<NcActionButton @click="objectStore.setObjectItem(object); navigationStore.setDialog('deleteObject')">
							<template #icon>
								<TrashCanOutline />
							</template>
							Delete
						</NcActionButton>
					</template>
				</NcListItem>
			</div>
		</ul>

		<NcLoadingIcon v-if="!objectStore.objectList"
			class="loadingIcon"
			:size="64"
			appearance="dark"
			name="Loading Objects" />

		<div v-if="objectStore.objectList.length === 0">
			No objects have been defined yet.
		</div>
	</NcAppContentList>
</template>

<script>
import { NcListItem, NcActionButton, NcAppContentList, NcTextField, NcLoadingIcon, NcActions } from '@nextcloud/vue'
import Magnify from 'vue-material-design-icons/Magnify.vue'
import DatabaseOutline from 'vue-material-design-icons/DatabaseOutline.vue'
import Refresh from 'vue-material-design-icons/Refresh.vue'
import Plus from 'vue-material-design-icons/Plus.vue'
import Pencil from 'vue-material-design-icons/Pencil.vue'
import TrashCanOutline from 'vue-material-design-icons/TrashCanOutline.vue'

export default {
	name: 'ObjectsList',
	components: {
		NcListItem,
		NcActions,
		NcActionButton,
		NcAppContentList,
		NcTextField,
		NcLoadingIcon,
		Magnify,
		DatabaseOutline,
		Refresh,
		Plus,
		Pencil,
		TrashCanOutline,
	},
	mounted() {
		objectStore.refreshObjectList()
	},
}
</script>

<style>
/* Styles remain the same */
</style>

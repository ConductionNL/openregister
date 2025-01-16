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
					<NcActionButton @click="objectStore.setObjectItem(null); navigationStore.setModal('uploadObject')">
						<template #icon>
							<Upload :size="20" />
						</template>
						Upload
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
					:name="object.id?.toString()"
					:active="objectStore.objectItem?.id === object?.id"
					:force-display-actions="true"
					@click="objectStore.setObjectItem(object)">
					<template #icon>
						<CubeOutline :class="objectStore.objectItem?.id === object.id && 'selectedObjectIcon'"
							disable-menu
							:size="44" />
					</template>
					<template #subname>
						{{ object.uuid }}
					</template>
					<template #actions>
						<NcActionButton @click="objectStore.setObjectItem(object); navigationStore.setModal('editObject')">
							<template #icon>
								<Pencil />
							</template>
							Edit
						</NcActionButton>
						<NcActionButton v-if="!object.locked"
							@click="objectStore.setObjectItem(object); navigationStore.setModal('lockObject')">
							<template #icon>
								<LockOutline />
							</template>
							Lock
						</NcActionButton>
						<NcActionButton v-if="object.locked"
							@click="objectStore.unlockObject(objectStore.objectItem.id)">
							<template #icon>
								<LockOpenOutline />
							</template>
							Unlock
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
import CubeOutline from 'vue-material-design-icons/CubeOutline.vue'
import Refresh from 'vue-material-design-icons/Refresh.vue'
import Plus from 'vue-material-design-icons/Plus.vue'
import Pencil from 'vue-material-design-icons/Pencil.vue'
import TrashCanOutline from 'vue-material-design-icons/TrashCanOutline.vue'
import Upload from 'vue-material-design-icons/Upload.vue'
import LockOutline from 'vue-material-design-icons/LockOutline.vue'
import LockOpenOutline from 'vue-material-design-icons/LockOpenOutline.vue'
import { showSuccess, showError } from '@nextcloud/dialogs'

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
		CubeOutline,
		Refresh,
		Plus,
		Pencil,
		TrashCanOutline,
		LockOutline,
		LockOpenOutline,
	},
	mounted() {
		objectStore.refreshObjectList()
	}
}
</script>

<style>
/* Styles remain the same */
</style>

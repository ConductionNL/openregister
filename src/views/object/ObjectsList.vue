<script setup>
import { objectStore, navigationStore } from '../../store/store.js'
</script>

<template>
	<NcAppContentList>
		<ul>
			<div class="listHeader">
				<div class="searchListHeader">
					<NcTextField
						:value.sync="search"
						:show-trailing-button="search !== ''"
						label="Search"
						class="searchField"
						trailing-button-icon="close"
						@trailing-button-click="search = ''">
						<Magnify :size="20" />
					</NcTextField>
					<NcActions>
						<NcActionButton close-after-click @click="objectStore.refreshObjectList({ search: search, page: 1 })">
							<template #icon>
								<Refresh :size="20" />
							</template>
							Refresh
						</NcActionButton>
						<NcActionButton close-after-click @click="objectStore.setObjectItem(null); navigationStore.setModal('uploadObject')">
							<template #icon>
								<Upload :size="20" />
							</template>
							Upload
						</NcActionButton>
						<NcActionButton close-after-click @click="objectStore.setObjectItem(null); navigationStore.setModal('editObject')">
							<template #icon>
								<Plus :size="20" />
							</template>
							Add Object
						</NcActionButton>
					</NcActions>
				</div>
				<div v-if="objectStore.objectList.results?.length > 0 && objectStore.objectList.total > limit">
					<span>Page {{ currentPage }} of {{ objectStore.objectList.pages }}</span>
					<BPagination v-model="currentPage"
						class="listPagination"
						:total-rows="objectStore.objectList.total"
						:per-page="limit" />
				</div>
			</div>
			<div v-if="objectStore.objectList.results && objectStore.objectList.results.length > 0 && !loading">
				<NcListItem v-for="(object, i) in objectStore.objectList.results"
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
						<NcActionButton close-after-click @click="objectStore.setObjectItem(object); navigationStore.setModal('editObject')">
							<template #icon>
								<Pencil />
							</template>
							Edit
						</NcActionButton>
						<NcActionButton v-if="!object.locked"
							close-after-click
							@click="objectStore.setObjectItem(object); navigationStore.setModal('lockObject')">
							<template #icon>
								<LockOutline />
							</template>
							Lock
						</NcActionButton>
						<NcActionButton v-if="object.locked"
							close-after-click
							@click="objectStore.unlockObject(objectStore.objectItem.id)">
							<template #icon>
								<LockOpenOutline />
							</template>
							Unlock
						</NcActionButton>
						<NcActionButton close-after-click @click="objectStore.setObjectItem(object); navigationStore.setDialog('deleteObject')">
							<template #icon>
								<TrashCanOutline />
							</template>
							Delete
						</NcActionButton>
					</template>
				</NcListItem>
			</div>
		</ul>

		<NcLoadingIcon v-if="loading"
			class="loadingIcon"
			:size="64"
			appearance="dark"
			name="Loading Objects" />

		<div v-if="objectStore.objectList.results?.length === 0">
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
import { BPagination } from 'bootstrap-vue'

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
	data() {
		return {
			limit: 200,
			currentPage: 1,
			loading: false,
			search: '',
			searchTimeout: null,

		}
	},
	watch: {
		currentPage(newVal) {
			this.loading = true
			objectStore.refreshObjectList({ limit: this.limit, page: newVal, search: this.search }).finally(() => {
				this.loading = false
			})
		},
		search(newVal) {
			clearTimeout(this.searchTimeout)
			this.searchTimeout = setTimeout(() => {
				this.loading = true
				objectStore.refreshObjectList({ limit: this.limit, page: this.currentPage, search: newVal }).finally(() => {
					this.loading = false
				})
			}, 700)
		},
	},
	mounted() {
		this.loading = true
		objectStore.refreshObjectList({ limit: this.limit, page: this.currentPage, search: this.search }).finally(() => {
			this.loading = false
		})
	},
}
</script>

<style>
/* Styles remain the same */
</style>

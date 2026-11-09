<script setup>
import { translate as t } from '@nextcloud/l10n'
import {
	navigationStore,
	objectStore,
	registerStore,
	schemaStore,
} from '../../store/store.js'
</script>

<template>
	<NcAppContentList>
		<div class="listHeader">
			<div class="searchListHeader">
				<NcTextField
					v-model="search"
					:showTrailingButton="search !== ''"
					:label="t('openregister', 'Search')"
					class="searchField"
					trailingButtonIcon="close"
					@trailingButtonClick="search = ''">
					<Magnify :size="20" />
				</NcTextField>
				<NcActions>
					<NcActionButton
						closeAfterClick
						@click="
							objectStore.refreshObjectList({
								search: search,
								page: 1,
							})
						">
						<template #icon>
							<Refresh :size="20" />
						</template>
						Refresh
					</NcActionButton>
					<NcActionButton
						closeAfterClick
						@click="
							() => {
								objectStore.setObjectItem(null)
								navigationStore.setModal('uploadObject')
							}
						">
						<template #icon>
							<Upload :size="20" />
						</template>
						Upload
					</NcActionButton>
					<NcActionButton closeAfterClick @click="addObject">
						<template #icon>
							<Plus :size="20" />
						</template>
						Add Object
					</NcActionButton>
				</NcActions>
			</div>
			<div
				v-if="
					objectStore.getCollection(objectStore.currentType).length > 0
					&& objectStore.getPagination(objectStore.currentType).total
						> limit
				">
				<span
					>Page {{ currentPage }} of
					{{
						objectStore.getPagination(objectStore.currentType).pages
					}}</span
				>
				<CnPagination
					class="listPagination"
					:currentPage="currentPage"
					:totalPages="
						objectStore.getPagination(objectStore.currentType).pages
					"
					:totalItems="
						objectStore.getPagination(objectStore.currentType).total
					"
					:currentPageSize="limit"
					:minItemsToShow="10"
					@pageChanged="currentPage = $event"
					@pageSizeChanged="onPageSizeChanged" />
			</div>
		</div>
		<ul
			v-if="
				objectStore.getCollection(objectStore.currentType)
				&& objectStore.getCollection(objectStore.currentType).length > 0
				&& !loading
			">
			<NcListItem
				v-for="(object, i) in objectStore.getCollection(
					objectStore.currentType,
				)"
				:key="`${object}${i}`"
				:name="object.id?.toString()"
				:active="objectStore.objectItem?.id === object?.id"
				:forceDisplayActions="true"
				@click="objectStore.setObjectItem(object)">
				<template #icon>
					<CubeOutline
						:class="
							objectStore.objectItem?.id === object.id
							&& 'selectedObjectIcon'
						"
						disableMenu
						:size="44" />
				</template>
				<template #subname>
					{{ object.uuid }}
				</template>
				<template #actions>
					<NcActionButton
						closeAfterClick
						@click="
							() => {
								objectStore.setObjectItem(object)
								navigationStore.setModal('viewObject')
							}
						">
						<template #icon>
							<Pencil />
						</template>
						Edit
					</NcActionButton>
					<NcActionButton
						v-if="!object.locked"
						closeAfterClick
						@click="
							() => {
								objectStore.setObjectItem(object)
								navigationStore.setModal('lockObject')
							}
						">
						<template #icon>
							<LockOutline />
						</template>
						Lock
					</NcActionButton>
					<NcActionButton
						v-if="object.locked"
						closeAfterClick
						@click="objectStore.unlockObject(objectStore.objectItem.id)">
						<template #icon>
							<LockOpenOutline />
						</template>
						Unlock
					</NcActionButton>
					<NcActionButton
						closeAfterClick
						@click="
							() => {
								objectStore.setObjectItem(object)
								navigationStore.setDialog('deleteObject')
							}
						">
						<template #icon>
							<TrashCanOutline />
						</template>
						Delete
					</NcActionButton>
				</template>
			</NcListItem>
		</ul>

		<NcLoadingIcon
			v-if="loading"
			class="loadingIcon"
			:size="64"
			appearance="dark"
			name="Loading Objects" />

		<div v-if="objectStore.getCollection(objectStore.currentType)?.length === 0">
			No objects have been defined yet.
		</div>
	</NcAppContentList>
</template>

<script>
import { CnPagination } from '@conduction/nextcloud-vue'
import {
	NcActionButton,
	NcActions,
	NcAppContentList,
	NcListItem,
	NcLoadingIcon,
	NcTextField,
} from '@nextcloud/vue'
import CubeOutline from 'vue-material-design-icons/CubeOutline.vue'
import LockOpenOutline from 'vue-material-design-icons/LockOpenOutline.vue'
import LockOutline from 'vue-material-design-icons/LockOutline.vue'
import Magnify from 'vue-material-design-icons/Magnify.vue'
import Pencil from 'vue-material-design-icons/Pencil.vue'
import Plus from 'vue-material-design-icons/Plus.vue'
import Refresh from 'vue-material-design-icons/Refresh.vue'
import TrashCanOutline from 'vue-material-design-icons/TrashCanOutline.vue'
import Upload from 'vue-material-design-icons/Upload.vue'
import { liveCollectionMethods } from '../../composables/liveCollectionSubscription.js'

export default {
	name: 'ObjectsList',
	components: {
		NcListItem,
		NcActions,
		NcActionButton,
		NcAppContentList,
		NcTextField,
		NcLoadingIcon,
		CnPagination,
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
			// Live-updates handle for the or-collection-{register}-{schema}
			// subscription of the currently scoped register+schema
			// (adopt-live-updates-ui). Managed by syncLiveSubscription().
			// livePendingType marks an in-flight subscribe so a concurrent
			// same-scope call doesn't double-subscribe; liveEpoch invalidates
			// in-flight resolutions after a release (scope change / destroy).
			liveHandle: null,
			liveType: '',
			livePendingType: '',
			liveEpoch: 0,
		}
	},

	computed: {
		/**
		 * @spec exclude Derived client-state — mirrors the store's currentType so the watcher below can re-scope the live subscription.
		 */
		liveCollectionType() {
			return objectStore.currentType
		},
	},

	watch: {
		/**
		 * Re-scope the live collection subscription when the user switches
		 * register/schema.
		 *
		 * @spec openspec/specs/realtime-updates/spec.md
		 */
		liveCollectionType() {
			this.syncLiveSubscription()
		},

		/**
		 * @param {number} newVal The new page number
		 * @spec exclude list-view watcher; reloads the object list on page change (object-lifecycle contract)
		 */
		currentPage(newVal) {
			this.reloadList(newVal, this.search)
		},

		/**
		 * @param {string} newVal The new search term
		 * @spec exclude list-view watcher; debounced reload of the object list on search change (object-lifecycle contract)
		 */
		search(newVal) {
			clearTimeout(this.searchTimeout)
			this.searchTimeout = setTimeout(() => {
				this.reloadList(this.currentPage, newVal)
			}, 700)
		},
	},

	/**
	 * @spec exclude list-view lifecycle; conditionally loads the object list on mount when register+schema are scoped (object-lifecycle contract)
	 */
	mounted() {
		// Skip the refresh when neither a register nor a schema is in
		// scope — the deep-link route /objects/:register/:schema/:id
		// mounts this list with empty stores, and refreshObjectList()
		// throws 'Register and schema are required.' which crashes the
		// surrounding Vue render. Once the user picks a register/schema
		// in the side bars the existing watchers re-trigger the call.
		const registerSet = !!objectStore?.filters?.register
		const schemaSet = !!objectStore?.filters?.schema
		this.syncLiveSubscription()
		if (!registerSet || !schemaSet) {
			this.loading = false
			return
		}
		this.reloadList(this.currentPage, this.search)
	},

	/**
	 * Lifecycle hook: release the live collection subscription on unmount.
	 *
	 * @spec openspec/specs/realtime-updates/spec.md
	 * @return {void}
	 */
	beforeUnmount() {
		this.releaseLiveSubscription()
	},

	methods: {
		...liveCollectionMethods({
			tag: '[ObjectsList]',
			currentType: () => objectStore.currentType,
		}),

		/**
		 * Refetch the object list with the current limit, showing the loader
		 * while it runs.
		 *
		 * @spec exclude list-view plumbing; reloads the object list (object-lifecycle contract)
		 * @param {number} page The page to load
		 * @param {string} search The search term
		 * @return {void}
		 */
		reloadList(page, search) {
			this.loading = true
			objectStore
				.refreshObjectList({
					limit: this.limit,
					page,
					search,
				})
				.finally(() => {
					this.loading = false
				})
		},

		/**
		 * Apply a new page size from the paginator.
		 *
		 * Matches the onPageSizeChanged of the other paginated views: the page
		 * resets to 1 and the list refetches with the new limit. Here the
		 * refetch normally rides the `currentPage` watcher, so it is only
		 * issued directly when the page is already 1 and that watcher would
		 * not fire — issuing it unconditionally would double-fetch.
		 *
		 * @param {number} pageSize - The new page size
		 * @spec exclude list-view pagination page-size-change handler
		 * @return {void}
		 */
		onPageSizeChanged(pageSize) {
			this.limit = pageSize
			if (this.currentPage !== 1) {
				this.currentPage = 1
				return
			}
			this.reloadList(1, this.search)
		},

		/**
		 * @spec exclude list-view action; opens the add-object modal with register/schema context (object-lifecycle contract)
		 */
		addObject() {
			// Clear any existing object and open the add object modal
			objectStore.setObjectItem(null)
			// Ensure register and schema are set for new object creation
			if (registerStore.registerItem) {
				registerStore.setRegisterItem(registerStore.registerItem)
			}
			if (schemaStore.schemaItem) {
				schemaStore.setSchemaItem(schemaStore.schemaItem)
			}
			navigationStore.setModal('viewObject')
		},
	},
}
</script>

<style>
/* Styles remain the same */
</style>

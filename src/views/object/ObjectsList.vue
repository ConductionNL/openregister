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
			this.loading = true
			objectStore
				.refreshObjectList({
					limit: this.limit,
					page: newVal,
					search: this.search,
				})
				.finally(() => {
					this.loading = false
				})
		},

		/**
		 * @param {string} newVal The new search term
		 * @spec exclude list-view watcher; debounced reload of the object list on search change (object-lifecycle contract)
		 */
		search(newVal) {
			clearTimeout(this.searchTimeout)
			this.searchTimeout = setTimeout(() => {
				this.loading = true
				objectStore
					.refreshObjectList({
						limit: this.limit,
						page: this.currentPage,
						search: newVal,
					})
					.finally(() => {
						this.loading = false
					})
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
		this.loading = true
		objectStore
			.refreshObjectList({
				limit: this.limit,
				page: this.currentPage,
				search: this.search,
			})
			.finally(() => {
				this.loading = false
			})
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
		/**
		 * Subscribe to live updates for the currently scoped register+schema
		 * collection (adopt-live-updates-ui). Events are refetch hints only:
		 * the liveUpdatesPlugin re-runs fetchCollection with the last-used
		 * params, so the rendered collection state refreshes reactively.
		 * Idempotent per type; releases the previous subscription when the
		 * scope changes. Uses notify_push when available, visibility-gated
		 * polling otherwise.
		 *
		 * @spec openspec/specs/realtime-updates/spec.md
		 * @return {Promise<void>}
		 */
		async syncLiveSubscription() {
			const type = objectStore.currentType
			if (typeof objectStore.subscribe !== 'function') {
				return
			}
			if (!type) {
				this.releaseLiveSubscription()
				return
			}
			if (this.liveHandle && this.liveType === type) {
				return
			}
			if (this.livePendingType === type) {
				// A subscribe for this exact scope is already in flight —
				// re-subscribing here would leak the first handle.
				return
			}
			this.releaseLiveSubscription()
			try {
				// Subscription is independent of refreshObjectList timing:
				// register the type ourselves when it is not known yet.
				if (!objectStore.objectTypes.includes(type)) {
					const registerId = registerStore.registerItem?.id
					const schemaId = schemaStore.schemaItem?.id
					if (!registerId || !schemaId) {
						return
					}
					objectStore.registerObjectType(type, schemaId, registerId)
				}
				const epoch = this.liveEpoch
				this.livePendingType = type
				this.liveType = type
				const handle = await objectStore.subscribe(type)
				if (this.livePendingType === type) {
					this.livePendingType = ''
				}
				if (this.liveEpoch !== epoch) {
					// released while awaiting (scope change / destroy) — drop
					// the now-stale subscription instead of leaking it.
					objectStore.unsubscribe(handle)
					return
				}
				this.liveHandle = handle
			} catch (e) {
				if (this.livePendingType === type) {
					this.livePendingType = ''
				}
				this.liveHandle = null
				this.liveType = ''
				console.warn(
					'[ObjectsList] live subscription failed:',
					e?.message ?? e,
				)
			}
		},

		/**
		 * Release the current live collection subscription, if any, and
		 * invalidate any in-flight subscribe (its resolution unsubscribes
		 * itself via the epoch check).
		 *
		 * @spec openspec/specs/realtime-updates/spec.md
		 * @return {void}
		 */
		releaseLiveSubscription() {
			this.liveEpoch += 1
			this.livePendingType = ''
			if (this.liveHandle && typeof objectStore.unsubscribe === 'function') {
				objectStore.unsubscribe(this.liveHandle)
			}
			this.liveHandle = null
			this.liveType = ''
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
			this.loading = true
			objectStore
				.refreshObjectList({
					limit: this.limit,
					page: 1,
					search: this.search,
				})
				.finally(() => {
					this.loading = false
				})
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

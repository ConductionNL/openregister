/**
 * Object store using @conduction/nextcloud-vue with an adapter for the existing app API.
 * Delegates to the package's createObjectStore; maps register/schema context to type slug
 * and exposes getCollection, objectItem, refreshObjectList, etc. via the package store API.
 */

import { getActivePinia } from 'pinia'
import {
	createObjectStore,
	filesPlugin,
	auditTrailsPlugin,
	relationsPlugin,
	registerMappingPlugin,
	lifecyclePlugin,
	searchPlugin,
	selectionPlugin,
	liveUpdatesPlugin,
} from '@conduction/nextcloud-vue'
import { useRegisterStore } from './register.js'
import { useSchemaStore } from './schema.js'

/**
 * Derive current type slug from register and schema stores.
 * @param {import('pinia').Pinia} pinia - The pinia instance
 * @return {string} - The current type slug
 */
function getCurrentType(pinia) {
	if (!pinia) return ''
	const registerStore = useRegisterStore(pinia)
	const schemaStore = useSchemaStore(pinia)
	const registerId = registerStore.registerItem?.id ?? ''
	const schemaId = schemaStore.schemaItem?.id ?? ''
	return `${registerId}-${schemaId}`.replace(/^-|-$/g, '') || ''
}

function openregisterObjectPlugin() {
	return {
		name: 'openregisterObject',

		state: () => ({
			objectItem: false,
			filters: {},
			auditTrailItem: false,
		}),

		getters: {
			/**
			 * @spec exclude Derived client-state getter — composes a type slug from the register/schema stores. No backend contract.
			 */
			currentType() {
				return getCurrentType(getActivePinia())
			},

			/**
			 * @spec exclude Derived client-state getter — proxies the active schema from the schema store. No backend contract.
			 */
			activeSchema() {
				const pinia = getActivePinia()
				if (!pinia) return null
				return useSchemaStore(pinia).schemaItem
			},
		},

		actions: {
			/**
			 * Soft-delete several objects of the current register/schema in one action.
			 *
			 * Called by MassDeleteObject.vue and SearchIndex.vue. Those call sites
			 * existed while this method did NOT, so `objectStore.massDeleteObject`
			 * was undefined and the bulk-delete confirm button threw a TypeError
			 * before any request was made — the modal simply never progressed.
			 *
			 * Delegates to the package store's `deleteObjects(type, ids)`, whose
			 * `{ successfulIds, failedIds }` return shape is exactly what the
			 * callers already destructure.
			 *
			 * @param {Array<string>} ids The object ids to delete.
			 *
			 * @return {Promise<{successfulIds: Array<string>, failedIds: Array<string>}>} Per-id outcome.
			 *
			 * @spec exclude Adapter delegating to the @conduction/nextcloud-vue package object store (deleteObjects); the delete contract is owned by the shared library, not this app.
			 */
			async massDeleteObject(ids = []) {
				const type = getCurrentType(getActivePinia())
				if (!type) {
					throw new Error('Register and schema are required.')
				}

				// Normalise whatever the call sites hand over. They are not
				// consistent: some map `selectedObjects` to `obj.id`, some pass the
				// objects themselves, and an OpenRegister object carries its id both
				// top-level and under `@self.id`. Anything falsy MUST be dropped —
				// the package store's `_buildUrl(type, id)` appends `/${id}` only
				// `if (id)`, so an undefined id silently produces a DELETE against
				// the COLLECTION url (`/api/objects/27/116`) instead of one object.
				// That is not a no-op: it is a 405 per selected row.
				const resolved = (Array.isArray(ids) ? ids : [ids])
					.map((entry) => {
						if (entry === null || entry === undefined) return null
						if (typeof entry === 'string' || typeof entry === 'number') return String(entry)
						return entry.id ?? entry['@self']?.id ?? null
					})
					.filter((id) => id !== null && id !== '')

				if (resolved.length === 0) {
					throw new Error('No deletable object ids could be resolved from the selection.')
				}

				return await this.deleteObjects(type, resolved)
			},

			/**
			 * Ensure the current register/schema type is registered in the package store, then fetch collection.
			 * @param {object} [options] Fetch options: register, schema, limit, page, search
			 *
			 * @spec exclude Adapter delegating to the @conduction/nextcloud-vue package object store (fetchCollection); the data-fetch contract is owned by the shared library, not this app.
			 */
			async refreshObjectList(options = {}) {
				const pinia = getActivePinia()
				if (!pinia) return { response: null, data: {} }
				const registerStore = useRegisterStore(pinia)
				const schemaStore = useSchemaStore(pinia)
				const registerId = options.register ?? registerStore.registerItem?.id
				const schemaId = options.schema ?? schemaStore.schemaItem?.id
				if (!registerId || !schemaId) {
					throw new Error('Register and schema are required.')
				}
				const type = `${registerId}-${schemaId}`
				if (!this.objectTypes.includes(type)) {
					this.registerObjectType(type, schemaId, registerId)
				}
				const params = { ...this.filters }
				if (options.limit != null) params._limit = options.limit
				if (options.page != null) params._page = options.page
				if (options.search != null) params._search = options.search
				const results = await this.fetchCollection(type, params)
				const pag = this.getPagination(type)
				return {
					response: {},
					data: { results, total: pag.total, page: pag.page, pages: pag.pages, limit: pag.limit, offset: (pag.page - 1) * pag.limit },
				}
			},

			setObjectItem(item, _skipRefresh = false) {
				this.objectItem = item || false
			},

			setFilters(filters) {
				this.filters = { ...this.filters, ...filters }
			},

			setAuditTrailItem(item) {
				this.auditTrailItem = item || false
			},

			// Stub: DashboardSideBar.vue + SearchSideBar.vue call this on
			// mount + schema change. The implementation that originally
			// lived alongside them was refactored out, but the call sites
			// remained — and on routes where those side-bars mount, the
			// missing method throws a TypeError mid-bootstrap, which kills
			// every subsequent mounted() hook in the SPA (including App.vue).
			// A no-op stub lets the SPA finish mounting; the original
			// behaviour (per-column filter init) is now handled inline by
			// the filter components themselves.
			/**
			 * @spec exclude Intentional no-op compatibility stub — keeps stale call sites safe; behaviour moved into the filter components.
			 */
			initializeColumnFilters() {
				// Intentionally empty.
			},

			// Stub: paired with initializeColumnFilters above. Called from
			// the same code paths in DashboardSideBar.vue when a schema
			// becomes available. The new schema-aware property store
			// handles this elsewhere; a no-op keeps the call site safe.
			/**
			 * @param {object} _schema Unused — kept for call-site compatibility
			 * @spec exclude Intentional no-op compatibility stub — keeps stale call sites safe; behaviour moved into the schema-aware property store.
			 */
			initializeProperties(_schema) {
				// Intentionally empty.
			},
		},
	}
}

/** Package object store — use for type 'search' on the search page; exported for SearchIndex/SearchSideBar. */
export const useObjectStore = createObjectStore('openregister-objects', {
	plugins: [
		filesPlugin(),
		auditTrailsPlugin(),
		relationsPlugin(),
		registerMappingPlugin(),
		lifecyclePlugin(),
		searchPlugin(),
		selectionPlugin(),
		// Live updates (adopt-live-updates-ui): exposes subscribe(type, id?) /
		// unsubscribe(handle) backed by @nextcloud/notify_push with polling
		// fallback. Inert until the first subscribe() call — the object list
		// (ObjectsList.vue) subscribes to the or-collection event for the
		// current register+schema, the detail view (ObjectDetails.vue) to the
		// or-object event for the open object. Events are refetch hints only:
		// the plugin re-runs fetchCollection/fetchObject through this store.
		liveUpdatesPlugin(),
		openregisterObjectPlugin(),
	],
})

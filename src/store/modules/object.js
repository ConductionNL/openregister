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
			currentType() {
				return getCurrentType(getActivePinia())
			},

			activeSchema() {
				const pinia = getActivePinia()
				if (!pinia) return null
				return useSchemaStore(pinia).schemaItem
			},
		},

		actions: {
			/**
			 * Ensure the current register/schema type is registered in the package store, then fetch collection.
			 * @param options
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
			initializeColumnFilters() {
				// Intentionally empty.
			},

			// Stub: paired with initializeColumnFilters above. Called from
			// the same code paths in DashboardSideBar.vue when a schema
			// becomes available. The new schema-aware property store
			// handles this elsewhere; a no-op keeps the call site safe.
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
		openregisterObjectPlugin(),
	],
})

/**
 * Object store using @conduction/nextcloud-vue with an adapter for the existing app API.
 * Delegates to the package's createObjectStore; maps register/schema context to type slug
 * Delegates to the package's createObjectStore; maps register/schema context to type slug
 * and exposes getCollection, objectItem, refreshObjectList, etc. via the package store API.
 */

import { getActivePinia, defineStore } from 'pinia'
import {
	createObjectStore,
	filesPlugin,
	auditTrailsPlugin,
	relationsPlugin,
	registerMappingPlugin,
	lifecyclePlugin,
	buildHeaders,
} from '@conduction/nextcloud-vue'
import { useRegisterStore } from './register.js'
import { useSchemaStore } from './schema.js'

/**
 * Get the base URL for API requests, automatically prepending /index.php if the current URL uses it.
 * @return {string} - The base URL with optional /index.php prefix
 */
function getBaseUrl() {
	const defaultBaseUrl = '/apps/openregister/api/objects'
	if (typeof window !== 'undefined' && window.location.pathname.includes('/index.php')) {
		return `/index.php${defaultBaseUrl}`
	}
	return defaultBaseUrl
}

/**
 * Get the register API URL with /index.php prefix when the app is served via index.php.
 * @param {string|number} registerId - Register ID
 * @return {string} - Full URL path for the register endpoint
 */
function getRegisterApiUrl(registerId) {
	const path = '/apps/openregister/api/registers/' + String(registerId)
	if (typeof window !== 'undefined' && window.location.pathname.includes('/index.php')) {
		return '/index.php' + path
	}
	return path
}

/**
 * Get the schema API URL with /index.php prefix when the app is served via index.php.
 * @param {string|number} schemaId - Schema ID
 * @return {string} - Full URL path for the schema endpoint
 */
function getSchemaApiUrl(schemaId) {
	const path = '/apps/openregister/api/schemas/' + String(schemaId)
	if (typeof window !== 'undefined' && window.location.pathname.includes('/index.php')) {
		return '/index.php' + path
	}
	return path
}

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

const SEARCH_TYPE = 'search'

function openregisterObjectPlugin() {
	return {
		name: 'openregisterObject',

		state: () => ({
			objectItem: false,
			filters: {},
			selectedObjects: [],
			auditTrailItem: false,
			columnFilters: {},
			metadata: {},
			properties: {},
			/** Search page: params used for fetchCollection('search', …). Updated by SearchSideBar and SearchIndex. */
			searchParams: {
				registerId: null,
				schemaId: null,
				search: '',
				filters: {},
				source: 'auto',
				sortKey: null,
				sortOrder: 'asc',
				page: 1,
				limit: 20,
			},
			/** Search page: column keys to show (null = all). Set by CnIndexSidebar @columns-change. */
			searchVisibleColumns: null,
		}),

		getters: {
			currentType() {
				return getCurrentType(getActivePinia())
			},

			pagination() {
				const pinia = getActivePinia()
				if (!pinia) return { total: 0, page: 1, pages: 1, limit: 20, offset: 0 }
				const pkg = usePackageObjectStore(pinia)
				const pag = pkg.getPagination(getCurrentType(pinia))
				return {
					...pag,
					offset: (pag.page - 1) * pag.limit,
				}
			},

			loading() {
				const pinia = getActivePinia()
				if (!pinia) return false
				return usePackageObjectStore(pinia).isLoading(getCurrentType(pinia))
			},

			auditTrails() {
				const pinia = getActivePinia()
				if (!pinia) return { results: [], total: 0, page: 1, pages: 1, limit: 20, offset: 0 }
				const pkg = usePackageObjectStore(pinia)
				return pkg.getAuditTrails ?? { results: [], total: 0, page: 1, pages: 1, limit: 20, offset: 0 }
			},

			files() {
				const pinia = getActivePinia()
				if (!pinia) return { results: [], total: 0, page: 1, pages: 1, limit: 20, offset: 0 }
				const pkg = usePackageObjectStore(pinia)
				return pkg.getFiles ?? { results: [], total: 0, page: 1, pages: 1, limit: 20, offset: 0 }
			},

			enabledColumns() {
				return []
			},

			isAllSelected() {
				const pinia = getActivePinia()
				const list = pinia ? usePackageObjectStore(pinia).getCollection(getCurrentType(pinia)) : []
				if (!list.length) return false
				return list.every((r) => this.selectedObjects.includes(r.id ?? r['@self']?.id))
			},

			activeSchema() {
				const pinia = getActivePinia()
				if (!pinia) return null
				return useSchemaStore(pinia).schemaItem
			},

			relations() {
				const pinia = getActivePinia()
				if (!pinia) return { results: [], total: 0, page: 1, pages: 1, limit: 20, offset: 0 }
				const pkg = usePackageObjectStore(pinia)
				return pkg.getContracts ?? { results: [], total: 0, page: 1, pages: 1, limit: 20, offset: 0 }
			},

			/** Search page: collection for type 'search' from package store. */
			searchCollection() {
				const pinia = getActivePinia()
				if (!pinia) return []
				return usePackageObjectStore(pinia).getCollection(SEARCH_TYPE)
			},

			/** Search page: pagination for type 'search'. */
			searchPagination() {
				const pinia = getActivePinia()
				if (!pinia) return { total: 0, page: 1, pages: 1, limit: 20 }
				return usePackageObjectStore(pinia).getPagination(SEARCH_TYPE)
			},

			/** Search page: loading for type 'search'. */
			searchLoading() {
				const pinia = getActivePinia()
				if (!pinia) return false
				return usePackageObjectStore(pinia).isLoading(SEARCH_TYPE)
			},

			/** Search page: schema for type 'search'. */
			searchSchema() {
				const pinia = getActivePinia()
				if (!pinia) return null
				return usePackageObjectStore(pinia).getSchema(SEARCH_TYPE)
			},

			/** Search page: register for type 'search'. */
			searchRegister() {
				const pinia = getActivePinia()
				if (!pinia) return null
				return usePackageObjectStore(pinia).getRegister(SEARCH_TYPE)
			},

			/** Search page: facets for type 'search' (CnIndexSidebar format). */
			searchFacets() {
				const pinia = getActivePinia()
				if (!pinia) return {}
				return usePackageObjectStore(pinia).getFacets(SEARCH_TYPE)
			},
		},

		actions: {
		/**
		 * Update search params (partial). Used by SearchSideBar and SearchIndex.
		 * @param updates
		 */
			setSearchParams(updates) {
				Object.assign(this.searchParams, updates)
			},

			/**
			 * Set visible columns for search table (null = all).
			 * @param columns
			 */
			setSearchVisibleColumns(columns) {
				this.searchVisibleColumns = columns
			},

			/** Clear search params and package store state for type 'search' when register/schema are deselected. */
			clearSearchCollection() {
				this.setSearchParams({ registerId: null, schemaId: null, filters: {} })
				const pinia = getActivePinia()
				if (!pinia) return
				const pkg = usePackageObjectStore(pinia)
				pkg.collections = { ...pkg.collections, [SEARCH_TYPE]: [] }
				pkg.pagination = { ...pkg.pagination, [SEARCH_TYPE]: { total: 0, page: 1, pages: 1, limit: 20 } }
				pkg.loading = { ...pkg.loading, [SEARCH_TYPE]: false }
				pkg.facets = { ...pkg.facets, [SEARCH_TYPE]: {} }
				pkg.schemas = { ...pkg.schemas, [SEARCH_TYPE]: null }
				pkg.registers = { ...pkg.registers, [SEARCH_TYPE]: null }
			},

			/**
			 * Register type 'search' with current searchParams and fetch schema + collection.
			 * Call when register/schema/search/filters/sort/page change. No-op if registerId or schemaId missing.
			 * @param {object} [extraParams] - Params merged into the request (e.g. { _facets: 'extend', _limit: 0 } for facet discovery).
			 */
			async refetchSearchCollection(extraParams = {}) {
				const pinia = getActivePinia()
				if (!pinia) return
				const { registerId, schemaId, search, filters, source, sortKey, sortOrder, page, limit } = this.searchParams
				if (!registerId || !schemaId) return
				const pkg = usePackageObjectStore(pinia)
				pkg.registerObjectType(SEARCH_TYPE, String(schemaId), String(registerId))
				// Fetch register with correct base URL (index.php) instead of package's hardcoded path
				if (!pkg.registers[SEARCH_TYPE]) {
					try {
						const res = await fetch(getRegisterApiUrl(registerId), { method: 'GET', headers: buildHeaders() })
						if (res.ok) {
							const register = await res.json()
							pkg.registers = { ...pkg.registers, [SEARCH_TYPE]: register }
						}
					} catch {
					// leave register null; collection can still load
					}
				}
				// Fetch schema with correct base URL (index.php) instead of package's hardcoded path
				if (!pkg.schemas[SEARCH_TYPE]) {
					try {
						const res = await fetch(getSchemaApiUrl(schemaId), { method: 'GET', headers: buildHeaders() })
						if (res.ok) {
							const schema = await res.json()
							pkg.schemas = { ...pkg.schemas, [SEARCH_TYPE]: schema }
						}
					} catch {
					// leave schema null; collection can still load
					}
				}
				const params = {
					_limit: limit || 20,
					_page: page || 1,
					...(search ? { _search: search } : {}),
					...(sortKey ? { _order: { [sortKey]: sortOrder || 'asc' } } : {}),
					...(source && source !== 'auto' ? { _source: source } : {}),
					...filters,
					...extraParams,
				}
				await pkg.fetchCollection(SEARCH_TYPE, params)
			},

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
					throw new Error('Register and schema are required')
				}
				const type = `${registerId}-${schemaId}`
				const pkg = usePackageObjectStore(pinia)
				if (!pkg.objectTypes.includes(type)) {
					pkg.registerObjectType(type, schemaId, registerId)
				}
				const params = { ...this.filters }
				if (options.limit != null) params._limit = options.limit
				if (options.page != null) params._page = options.page
				if (options.search != null) params._search = options.search
				const results = await pkg.fetchCollection(type, params)
				const pag = pkg.getPagination(type)
				// When modals (e.g. mass delete/copy) refresh this type and it matches search page context, keep search type in sync
				if (this.searchParams?.registerId === registerId && this.searchParams?.schemaId === schemaId) {
					pkg.collections = { ...pkg.collections, [SEARCH_TYPE]: results }
					pkg.pagination = { ...pkg.pagination, [SEARCH_TYPE]: { ...pag } }
				}
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

			setSelectedObjects(ids) {
				this.selectedObjects = Array.isArray(ids) ? ids : []
			},

			toggleSelectAllObjects() {
				const pinia = getActivePinia()
				const list = pinia ? usePackageObjectStore(pinia).getCollection(getCurrentType(pinia)) : []
				const ids = list.map((r) => r.id ?? r['@self']?.id).filter(Boolean)
				if (this.isAllSelected) {
					this.selectedObjects = []
				} else {
					this.selectedObjects = [...ids]
				}
			},

			initializeColumnFilters() {},
			initializeProperties(_schema) {},

			setAuditTrailItem(item) {
				this.auditTrailItem = item || false
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
		openregisterObjectPlugin(),
	],
	baseUrl: getBaseUrl(),
})

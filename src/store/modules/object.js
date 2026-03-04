/**
 * Object store using @conduction/nextcloud-vue with an adapter for the existing app API.
 * Delegates to the package's createObjectStore; maps register/schema context to type slug
 * and exposes objectList, objectItem, refreshObjectList, etc. for backward compatibility.
 */

import { getActivePinia, defineStore } from 'pinia'
import {
	createObjectStore,
	filesPlugin,
	auditTrailsPlugin,
	relationsPlugin,
	registerMappingPlugin,
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

/** Package object store — use for type 'search' on the search page; exported for SearchIndex/SearchSideBar. */
export const usePackageObjectStore = createObjectStore('openregister-objects', {
	plugins: [
		filesPlugin(),
		auditTrailsPlugin(),
		relationsPlugin(),
		registerMappingPlugin(),
	],
	baseUrl: getBaseUrl(),
})

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

export const useObjectStore = defineStore('object', {
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

		objectList() {
			const pinia = getActivePinia()
			if (!pinia) return { results: [], total: 0, page: 1, pages: 1, limit: 20, offset: 0 }
			const pkg = usePackageObjectStore(pinia)
			const type = getCurrentType(pinia)
			const results = pkg.getCollection(type)
			const pag = pkg.getPagination(type)
			return {
				results,
				total: pag.total,
				page: pag.page,
				pages: pag.pages,
				limit: pag.limit,
				offset: (pag.page - 1) * pag.limit,
			}
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
			const list = this.objectList?.results ?? []
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

		async getObject({ register, schema, objectId }) {
			if (!register || !schema || !objectId) throw new Error('Register, schema and objectId are required')
			const pinia = getActivePinia()
			if (!pinia) return null
			const type = `${register}-${schema}`
			const pkg = usePackageObjectStore(pinia)
			if (!pkg.objectTypes.includes(type)) {
				pkg.registerObjectType(type, schema, register)
			}
			const data = await pkg.fetchObject(type, objectId)
			if (data) this.objectItem = data
			return data
		},

		async saveObject(objectItem, { register, schema }) {
			if (!objectItem || !register || !schema) throw new Error('Object item, register and schema are required')
			const pinia = getActivePinia()
			if (!pinia) return null
			const type = `${register}-${schema}`
			const pkg = usePackageObjectStore(pinia)
			if (!pkg.objectTypes.includes(type)) {
				pkg.registerObjectType(type, schema, register)
			}
			const payload = objectItem?.id ? objectItem : { ...objectItem, id: objectItem['@self']?.id }
			const data = await pkg.saveObject(type, payload)
			if (data) this.objectItem = data
			await this.refreshObjectList({ register, schema })
			return { response: {}, data }
		},

		async deleteObject(objectId, options = {}) {
			if (!objectId) throw new Error('No object id to delete')
			const pinia = getActivePinia()
			if (!pinia) return
			const registerStore = useRegisterStore(pinia)
			const schemaStore = useSchemaStore(pinia)
			const registerId = options.register ?? registerStore.registerItem?.id
			const schemaId = options.schema ?? schemaStore.schemaItem?.id
			if (!registerId || !schemaId) throw new Error('Register and schema are required')
			const type = `${registerId}-${schemaId}`
			const pkg = usePackageObjectStore(pinia)
			if (!pkg.objectTypes.includes(type)) {
				pkg.registerObjectType(type, schemaId, registerId)
			}
			await pkg.deleteObject(type, String(objectId))
			await this.refreshObjectList({ register: registerId, schema: schemaId })
		},

		async fetchSchema(type) {
			const pinia = getActivePinia()
			if (!pinia) return null
			return usePackageObjectStore(pinia).fetchSchema(type)
		},

		getSchema(type) {
			const pinia = getActivePinia()
			if (!pinia) return null
			return usePackageObjectStore(pinia).getSchema(type ?? this.currentType)
		},

		setPagination(_page, _limit = 20) {
			// Pagination is managed by the package store per type; call refreshObjectList({ page, limit }) to apply
		},

		setObjectList(_list) {
			// No-op: list comes from package store
		},

		toggleSelectAllObjects() {
			const list = this.objectList?.results ?? []
			const ids = list.map((r) => r.id ?? r['@self']?.id).filter(Boolean)
			if (this.isAllSelected) {
				this.selectedObjects = []
			} else {
				this.selectedObjects = [...ids]
			}
		},

		setSelectAllObjects() {
			this.toggleSelectAllObjects()
		},

		initializeColumnFilters() {},
		initializeProperties(_schema) {},

		setAuditTrailItem(item) {
			this.auditTrailItem = item || false
		},

		updateColumnFilter(_id, _enabled) {},

		async getFiles(objectRef, options = {}) {
			const pinia = getActivePinia()
			if (!pinia) return
			const objectId = typeof objectRef === 'object' ? objectRef?.id ?? objectRef?.['@self']?.id : objectRef
			const type = getCurrentType(pinia)
			const pkg = usePackageObjectStore(pinia)
			if (pkg.fetchFiles) await pkg.fetchFiles(type, objectId, options)
		},

		async getAuditTrails(objectRef, options = {}) {
			const pinia = getActivePinia()
			if (!pinia) return
			const objectId = typeof objectRef === 'object' ? objectRef?.id ?? objectRef?.['@self']?.id : objectRef
			const type = getCurrentType(pinia)
			const pkg = usePackageObjectStore(pinia)
			if (pkg.fetchAuditTrails) await pkg.fetchAuditTrails(type, objectId, options)
		},

		async getRelations(_objectId) {
			// Relations are fetched per object via package fetchContracts/fetchUses/fetchUsed if needed
		},

		async mergeObjects(params) {
			const { register, schema, sourceObjectId, target, object: objectData, fileAction = 'transfer', relationAction = 'transfer', referenceAction = 'transfer' } = params
			if (!register || !schema || !sourceObjectId || !target || !objectData) throw new Error('Missing required parameters for object merge')
			const res = await fetch(`/index.php/apps/openregister/api/objects/${register}/${schema}/${sourceObjectId}/merge`, {
				method: 'POST',
				headers: { 'Content-Type': 'application/json' },
				body: JSON.stringify({ target, object: objectData, fileAction, relationAction, referenceAction }),
			})
			if (!res.ok) throw new Error((await res.json()).error || res.statusText)
			await this.refreshObjectList({ register, schema })
			return { response: res, data: await res.json() }
		},

		async massDeleteObject(objectIds, options = {}) {
			if (!objectIds?.length) throw new Error('No object ids to delete')
			for (const id of objectIds) {
				await this.deleteObject(id, options)
			}
			return { successfulIds: objectIds, failedIds: [] }
		},

		async unlockObject(_object) {
			const obj = typeof _object === 'object' ? _object : this.objectItem
			if (!obj?.id && !obj?.['@self']?.id) return
			const id = obj?.id ?? obj?.['@self']?.id
			const register = obj?.['@self']?.register ?? obj?.register
			const schema = obj?.['@self']?.schema ?? obj?.schema
			if (!register || !schema) return
			const res = await fetch(`/index.php/apps/openregister/api/objects/${register}/${schema}/${id}/unlock`, { method: 'POST' })
			if (!res.ok) throw new Error(res.statusText)
			const data = await res.json()
			this.objectItem = data
			await this.refreshObjectList({ register, schema })
			return { response: res, data }
		},

		async publishObject({ register, schema, objectId, publishedDate = null }) {
			const date = publishedDate || new Date().toISOString()
			const res = await fetch(`/index.php/apps/openregister/api/objects/${register}/${schema}/${objectId}/publish`, {
				method: 'POST',
				headers: { 'Content-Type': 'application/json' },
				body: JSON.stringify({ date }),
			})
			if (!res.ok) throw new Error(res.statusText)
			await this.refreshObjectList({ register, schema })
			return { response: res, data: await res.json() }
		},

		async depublishObject({ register, schema, objectId, depublishedDate = null }) {
			const date = depublishedDate || new Date().toISOString()
			const res = await fetch(`/index.php/apps/openregister/api/objects/${register}/${schema}/${objectId}/depublish`, {
				method: 'POST',
				headers: { 'Content-Type': 'application/json' },
				body: JSON.stringify({ date }),
			})
			if (!res.ok) throw new Error(res.statusText)
			await this.refreshObjectList({ register, schema })
			return { response: res, data: await res.json() }
		},
	},
})

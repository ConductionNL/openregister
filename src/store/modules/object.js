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

const usePackageObjectStore = createObjectStore('openregister-objects', {
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

export const useObjectStore = defineStore('object', {
	state: () => ({
		objectItem: false,
		filters: {},
		selectedObjects: [],
		auditTrailItem: false,
		columnFilters: {},
		metadata: {},
		properties: {},
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

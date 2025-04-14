/* eslint-disable no-undef */

import { defineStore } from 'pinia'
import { AuditTrail, ObjectEntity } from '../../entities/index.js'
import { useRegisterStore } from '../../store/modules/register.js'
import { useSchemaStore } from '../../store/modules/schema.js'

export const useObjectStore = defineStore('object', {
	state: () => ({
		objectItem: false,
		objectList: [],
		auditTrailItem: false,
		fileItem: false,
		auditTrails: {
			results: [],
			total: 0,
			page: 1,
			pages: 0,
			limit: 20,
			offset: 0,
		},
		contracts: {
			results: [],
			total: 0,
			page: 1,
			pages: 0,
			limit: 20,
			offset: 0,
		},
		uses: {
			results: [],
			total: 0,
			page: 1,
			pages: 0,
			limit: 20,
			offset: 0,
		},
		used: {
			results: [],
			total: 0,
			page: 1,
			pages: 0,
			limit: 20,
			offset: 0,
		},
		files: {
			results: [],
			total: 0,
			page: 1,
			pages: 0,
			limit: 20,
			offset: 0,
		},
		filters: {},
		pagination: {
			total: 0,
			page: 1,
			pages: 0,
			limit: 20,
			offset: 0,
		},
		selectedObjects: [],
		metadata: {
			objectId: {
				label: 'ID',
				key: 'id',
				description: 'Unique identifier of the object',
				enabled: true, // Enabled by default
			},
			uri: {
				label: 'URI',
				key: 'uri',
				description: 'Uniform resource identifier',
				enabled: false,
			},
			version: {
				label: 'Version',
				key: 'version',
				description: 'Version number of the object',
				enabled: false,
			},
			register: {
				label: 'Register',
				key: 'register',
				description: 'Register the object belongs to',
				enabled: false,
			},
			schema: {
				label: 'Schema',
				key: 'schema',
				description: 'Schema the object follows',
				enabled: false,
			},
			files: {
				label: 'Files',
				key: 'files',
				description: 'Attached files count',
				enabled: true, // Enabled by default
			},
			relations: {
				label: 'Relations',
				key: 'relations',
				description: 'Related objects count',
				enabled: false,
			},
			locked: {
				label: 'Locked',
				key: 'locked',
				description: 'Lock status of the object',
				enabled: false,
			},
			owner: {
				label: 'Owner',
				key: 'owner',
				description: 'Owner of the object',
				enabled: false,
			},
			application: {
				label: 'Application',
				key: 'application',
				description: 'Application that created the object',
				enabled: false,
			},
			folder: {
				label: 'Folder',
				key: 'folder',
				description: 'Storage folder location',
				enabled: false,
			},
			created: {
				label: 'Created',
				key: 'created',
				description: 'Creation date and time',
				enabled: true, // Enabled by default
			},
			updated: {
				label: 'Updated',
				key: 'updated',
				description: 'Last update date and time',
				enabled: true, // Enabled by default
			},
		},
		properties: {}, // Will be populated based on schema
		columnFilters: {}, // Will contain both metadata and property filters
		loading: false,
	}),
	actions: {
		// Helper method to build endpoint path
		_buildObjectPath({ register, schema, objectId = '' }) {
			return `/index.php/apps/openregister/api/objects/${register}/${schema}${objectId ? '/' + objectId : ''}`
		},
		async setObjectItem(objectItem) {
			this.objectItem = objectItem && new ObjectEntity(objectItem)
			console.info('Active object item set to ' + objectItem?.['@self']?.id)

			// If we have a valid object item, fetch related data
			if (objectItem?.['@self']?.id) {
				try {
					const objectRef = {
						id: objectItem['@self'].id,
						register: objectItem['@self'].register,
						schema: objectItem['@self'].schema,
					}
					// Use store actions to fetch related data
					await Promise.all([
						this.getAuditTrails(objectRef),
						this.getContracts(objectRef),
						this.getUses(objectRef),
						this.getUsed(objectRef),
						this.getFiles(objectRef),
					])

					console.info('Successfully fetched all related data for object', objectItem['@self'].id)
				} catch (error) {
					console.error('Error fetching related data:', error)
					// Clear data in case of error
					this.clearRelatedData()
				}
			} else {
				// Clear related data when no object is selected
				this.clearRelatedData()
			}
		},
		setObjectList(objectList) {
			this.objectList = {
				...objectList,
				results: objectList.results.map(
					(objectItem) => new ObjectEntity(objectItem),
				),
			}

			console.info('Object list set to ' + objectList.length + ' items')
		},
		setAuditTrailItem(auditTrailItem) {
			this.auditTrailItem = auditTrailItem && new AuditTrail(auditTrailItem)
		},
		setAuditTrails(auditTrails) {
			this.auditTrails = auditTrails
			this.auditTrails.results = auditTrails.results
				? auditTrails.results.map(
					(auditTrail) => new AuditTrail(auditTrail),
				)
				: []
			console.info('Audit trails set to', this.auditTrails.results.length, 'items')
		},
		setContracts(contracts) {
			this.contracts = contracts
			this.contracts.results = contracts.results
				? contracts.results.map(
					(contract) => new ObjectEntity(contract),
				)
				: []
			console.info('Contracts set to', this.contracts.results.length, 'items')
		},
		setUses(uses) {
			this.uses = uses
			this.uses.results = uses.results
				? uses.results.map(
					(use) => new ObjectEntity(use),
				)
				: []
			console.info('Uses set to', this.uses.results.length, 'items')
		},
		setUsed(used) {
			this.used = used
			this.used.results = used.results
				? used.results.map(
					(usedBy) => new ObjectEntity(usedBy),
				)
				: []
			console.info('Used by set to', this.used.results.length, 'items')
		},
		setFiles(files) {
			this.files = files
			this.files.results = files.results || []
			console.info('Files set to', this.files.results.length, 'items')
		},
		/**
		 * Set pagination details
		 *
		 * @param {number} page Default page is 1
		 * @param {number} [limit] Default limit is 14
		 * @return {void}
		 */
		setPagination(page, limit = 14) {
			this.pagination = { page, limit }
			console.info('Pagination set to', { page, limit })
		},
		/**
		 * Set query filters for object list
		 *
		 * @param {object} filters Filters to set
		 * @return {void}
		 */
		setFilters(filters) {
			this.filters = { ...this.filters, ...filters }
			console.info('Query filters set to', this.filters)
		},
		async refreshObjectList(options = {}) {
			const registerStore = useRegisterStore()
			const schemaStore = useSchemaStore()

			const register = options.register || registerStore.registerItem?.id
			const schema = options.schema || schemaStore.schemaItem?.id

			if (!register || !schema) {
				throw new Error('Register and schema are required')
			}

			let endpoint = this._buildObjectPath({
				register,
				schema,
			})

			const params = []

			// Handle filters as an object
			Object.entries(this.filters).forEach(([key, value]) => {
				if (value !== undefined && value !== '') {
					params.push(`${key}=${encodeURIComponent(value)}`)
				}
			})

			if (options.limit || this.pagination.limit) {
				params.push('_limit=' + (options.limit || this.pagination.limit))
			}
			if (options.page || this.pagination.page) {
				params.push('_page=' + (options.page || this.pagination.page))
			}

			if (params.length > 0) {
				endpoint += '?' + params.join('&')
			}

			try {
				const response = await fetch(endpoint)
				const data = await response.json()
				this.setObjectList(data)
				return { response, data }
			} catch (err) {
				console.error(err)
				throw err
			}
		},
		async getObject({ register, schema, objectId }) {
			if (!register || !schema || !objectId) {
				throw new Error('Register, schema and objectId are required')
			}

			const endpoint = this._buildObjectPath({ register, schema, objectId })

			try {
				const response = await fetch(endpoint)
				const data = await response.json()
				this.setObjectItem(data)
				this.getAuditTrails({ register, schema, objectId })
				this.getRelations({ register, schema, objectId })
				return data
			} catch (err) {
				console.error(err)
				throw err
			}
		},
		async saveObject(objectItem, { register, schema }) {
			if (!objectItem || !register || !schema) {
				throw new Error('Object item, register and schema are required')
			}

			const isNewObject = !objectItem['@self'].id
			const endpoint = this._buildObjectPath({
				register,
				schema,
				objectId: isNewObject ? '' : objectItem['@self'].id,
			})

			objectItem['@self'].updated = new Date().toISOString()

			try {
				const response = await fetch(endpoint, {
					method: isNewObject ? 'POST' : 'PUT',
					headers: { 'Content-Type': 'application/json' },
					body: JSON.stringify(objectItem),
				})

				const data = new ObjectEntity(await response.json())
				this.setObjectItem(data)
				await this.refreshObjectList({ register, schema })
				return { response, data }
			} catch (error) {
				console.error('Error saving object:', error)
				throw error
			}
		},
		// mass delete objects
		async massDeleteObject(objectIds) {
			if (!objectIds.length) {
				throw new Error('No object ids to delete')
			}

			console.info('Deleting objects...')

			const result = {
				successfulIds: [],
				failedIds: [],
			}

			await Promise.all(objectIds.map(async (objectId) => {
				const endpoint = `/index.php/apps/openregister/api/objects/${register}/${schema}${objectId ? '/' + objectId : ''}`

				try {
					const response = await fetch(endpoint, {
						method: 'DELETE',
					})

					if (response.ok) {
						result.successfulIds.push(objectId)
					} else {
						result.failedIds.push(objectId)
					}
				} catch (error) {
					console.error('Error deleting object:', error)
					result.failedIds.push(objectId)
				}
			}))

			this.refreshObjectList()

			return result
		},
		// AUDIT TRAILS
		async getAuditTrails(object, options = {}) {
			if (!object?.id) {
				throw new Error('No object id to get audit trails for')
			}

			try {
				let endpoint = this._buildObjectPath({
					register: object.register,
					schema: object.schema,
					objectId: object.id + '/audit-trails',
				})

				const params = []
				if (options.search && options.search !== '') {
					params.push('_search=' + options.search)
				}
				if (options.limit && options.limit !== '') {
					params.push('_limit=' + options.limit)
				}
				if (options.page && options.page !== '') {
					params.push('_page=' + options.page)
				}

				if (params.length > 0) {
					endpoint += '?' + params.join('&')
				}

				const response = await fetch(endpoint)
				const data = await response.json()
				this.setAuditTrails(data)
				return { response, data }
			} catch (error) {
				console.error('Error getting audit trails:', error)
				this.setAuditTrails({
					results: [],
					total: 0,
					page: 1,
					pages: 1,
					limit: 20,
					offset: 0,
				})
				throw error
			}
		},
		// RELATIONS
		async getRelations(id, options = {}) {
			if (!id) {
				throw new Error('No object id to get relations for')
			}

			let endpoint = `/index.php/apps/openregister/api/objects/${register}/${schema}/${objectId}/relations`
			const params = []

			if (options.search && options.search !== '') {
				params.push('_search=' + options.search)
			}
			if (options.limit && options.limit !== '') {
				params.push('_limit=' + options.limit)
			}
			if (options.page && options.page !== '') {
				params.push('_page=' + options.page)
			}

			if (params.length > 0) {
				endpoint += '?' + params.join('&')
			}

			const response = await fetch(endpoint, {
				method: 'GET',
			})

			const responseData = await response.json()
			const data = responseData.map((relation) => new ObjectEntity(relation))

			this.setRelations(data)

			return { response, data }
		},
		// FILES
		/**
		 * Get files for an object
		 *
		 * @param {object} object Object containing id, register, and schema
		 * @param {object} options Pagination options
		 * @return {Promise} Promise that resolves with the object's files
		 */
		async getFiles(object, options = {}) {
			if (!object?.id) {
				throw new Error('No object id to get files for')
			}

			try {
				let endpoint = this._buildObjectPath({
					register: object.register,
					schema: object.schema,
					objectId: object.id + '/files',
				})

				const params = []
				if (options.search && options.search !== '') {
					params.push('_search=' + options.search)
				}
				if (options.limit && options.limit !== '') {
					params.push('_limit=' + options.limit)
				}
				if (options.page && options.page !== '') {
					params.push('_page=' + options.page)
				}

				if (params.length > 0) {
					endpoint += '?' + params.join('&')
				}

				const response = await fetch(endpoint)
				if (!response.ok) {
					throw new Error(`HTTP error! status: ${response.status}`)
				}

				const data = await response.json()
				this.setFiles(data || {
					results: [],
					total: 0,
					page: 1,
					pages: 1,
					limit: 20,
					offset: 0,
				})

				return { response, data }
			} catch (error) {
				console.error('Error getting files:', error)
				this.setFiles({
					results: [],
					total: 0,
					page: 1,
					pages: 1,
					limit: 20,
					offset: 0,
				})
				throw error
			}
		},
		// mappings
		async getMappings() {
			const endpoint = '/index.php/apps/openregister/api/objects/mappings'

			const response = await fetch(endpoint, {
				method: 'GET',
			})

			const data = (await response.json()).results

			return { response, data }
		},
		/**
		 * Lock an object
		 *
		 * @param {number} id Object ID
		 * @param {string|null} process Optional process identifier
		 * @param {number|null} duration Lock duration in seconds
		 * @return {Promise} Promise that resolves when the object is locked
		 */
		async lockObject(id, process = null, duration = null) {
			const endpoint = `/index.php/apps/openregister/api/objects/${register}/${schema}/${objectId}/lock`

			try {
				const response = await fetch(endpoint, {
					method: 'POST',
					headers: {
						'Content-Type': 'application/json',
					},
					body: JSON.stringify({
						process,
						duration,
					}),
				})

				if (!response.ok) {
					throw new Error(`HTTP error! status: ${response.status}`)
				}

				const data = await response.json()
				this.setObjectItem(data)
				this.refreshObjectList()

				return { response, data }
			} catch (error) {
				console.error('Error locking object:', error)
				throw new Error(`Failed to lock object: ${error.message}`)
			}
		},
		/**
		 * Unlock an object
		 *
		 * @param {number} id Object ID
		 * @return {Promise} Promise that resolves when the object is unlocked
		 */
		async unlockObject(id) {
			const endpoint = `/index.php/apps/openregister/api/objects/${register}/${schema}/${objectId}/${id}/unlock`

			try {
				const response = await fetch(endpoint, {
					method: 'POST',
				})

				if (!response.ok) {
					throw new Error(`HTTP error! status: ${response.status}`)
				}

				const data = await response.json()
				this.setObjectItem(data)
				this.refreshObjectList()

				return { response, data }
			} catch (error) {
				console.error('Error unlocking object:', error)
				throw new Error(`Failed to unlock object: ${error.message}`)
			}
		},
		/**
		 * Revert an object to a previous state
		 *
		 * @param {number} id Object ID
		 * @param {object} options Revert options
		 * @param {string} [options.datetime] ISO datetime string
		 * @param {string} [options.auditTrailId] Audit trail ID
		 * @param {string} [options.version] Semantic version
		 * @param {boolean} [options.overwriteVersion] Whether to overwrite version
		 * @return {Promise} Promise that resolves when the object is reverted
		 */
		async revertObject(id, options) {
			const endpoint = `/index.php/apps/openregister/api/objects/${register}/${schema}/${objectId}/${id}/revert`

			try {
				const response = await fetch(endpoint, {
					method: 'POST',
					headers: {
						'Content-Type': 'application/json',
					},
					body: JSON.stringify(options),
				})

				if (!response.ok) {
					throw new Error(`HTTP error! status: ${response.status}`)
				}

				const data = await response.json()
				this.setObjectItem(data)
				this.refreshObjectList()

				return { response, data }
			} catch (error) {
				console.error('Error reverting object:', error)
				throw new Error(`Failed to revert object: ${error.message}`)
			}
		},
		setSelectedObjects(objects) {
			this.selectedObjects = objects
		},
		toggleSelectAllObjects() {
			if (this.isAllSelected) {
				// Clear selection
				this.selectedObjects = []
			} else {
				// Select all current objects
				this.selectedObjects = this.objectList.results.map(result => result['@self'].id)
			}
		},
		updateColumnFilter(id, enabled) {
			console.info('Updating column filter:', id, enabled)
			console.info('Current columnFilters:', this.columnFilters)

			if (id.startsWith('meta_')) {
				const metaId = id.replace('meta_', '')
				if (this.metadata[metaId]) {
					this.metadata[metaId].enabled = enabled
					this.columnFilters[id] = enabled
					console.info('Updated metadata filter:', metaId, enabled)
				}
			} else if (id.startsWith('prop_')) {
				const propId = id.replace('prop_', '')
				if (this.properties[propId]) {
					this.properties[propId].enabled = enabled
					this.columnFilters[id] = enabled
					console.info('Updated property filter:', propId, enabled)
				}
			}

			console.info('Updated columnFilters:', this.columnFilters)
			// Force a refresh of the table
			this.objectList = { ...this.objectList }
		},
		// Initialize properties based on schema
		initializeProperties(schema) {
			if (!schema?.properties) {
				return
			}

			console.info('Initializing properties from schema:', schema.properties)

			// Reset properties
			this.properties = {}

			// Create property entries similar to metadata structure
			Object.entries(schema.properties).forEach(([propertyName, property]) => {
				this.properties[propertyName] = {
					// Capitalize first letter and replace underscores/hyphens with spaces
					label: propertyName
						.split(/[-_]/)
						.map(word => word.charAt(0).toUpperCase() + word.slice(1).toLowerCase())
						.join(' '),
					key: propertyName,
					description: property.description || '',
					enabled: false,
					type: property.type,
				}
			})

			console.info('Properties initialized:', this.properties)

			// Reinitialize column filters to include new properties
			this.initializeColumnFilters()
		},
		// Update to handle both metadata and properties
		initializeColumnFilters() {
			this.columnFilters = {
				...Object.entries(this.metadata).reduce((acc, [id, meta]) => {
					acc[`meta_${id}`] = meta.enabled
					return acc
				}, {}),
				...Object.entries(this.properties).reduce((acc, [id, prop]) => {
					acc[`prop_${id}`] = prop.enabled
					return acc
				}, {}),
			}
			console.info('Initialized column filters:', this.columnFilters)
		},
		clearRelatedData() {
			const emptyPaginatedData = {
				results: [],
				total: 0,
				page: 1,
				pages: 1,
				limit: 20,
				offset: 0,
			}

			// Clear all related data with proper pagination structure
			this.auditTrails = { ...emptyPaginatedData }
			this.contracts = { ...emptyPaginatedData }
			this.uses = { ...emptyPaginatedData }
			this.used = { ...emptyPaginatedData }
			this.files = { ...emptyPaginatedData }

			// Clear individual items
			this.auditTrailItem = false
			this.fileItem = false

			console.info('All related data cleared')
		},
		async getContracts(object, options = {}) {
			if (!object?.id) {
				throw new Error('No object id to get contracts for')
			}

			try {
				let endpoint = this._buildObjectPath({
					register: object.register,
					schema: object.schema,
					objectId: object.id + '/contracts',
				})

				const params = []
				if (options.search && options.search !== '') {
					params.push('_search=' + options.search)
				}
				if (options.limit && options.limit !== '') {
					params.push('_limit=' + options.limit)
				}
				if (options.page && options.page !== '') {
					params.push('_page=' + options.page)
				}

				if (params.length > 0) {
					endpoint += '?' + params.join('&')
				}

				const response = await fetch(endpoint)
				const data = await response.json()
				this.setContracts(data)
				return { response, data }
			} catch (error) {
				console.error('Error getting contracts:', error)
				this.setContracts({
					results: [],
					total: 0,
					page: 1,
					pages: 1,
					limit: 20,
					offset: 0,
				})
				throw error
			}
		},
		async getUses(object, options = {}) {
			if (!object?.id) {
				throw new Error('No object id to get uses for')
			}

			try {
				let endpoint = this._buildObjectPath({
					register: object.register,
					schema: object.schema,
					objectId: object.id + '/uses',
				})

				const params = []
				if (options.search && options.search !== '') {
					params.push('_search=' + options.search)
				}
				if (options.limit && options.limit !== '') {
					params.push('_limit=' + options.limit)
				}
				if (options.page && options.page !== '') {
					params.push('_page=' + options.page)
				}

				if (params.length > 0) {
					endpoint += '?' + params.join('&')
				}

				const response = await fetch(endpoint)
				const data = await response.json()
				this.setUses(data)
				return { response, data }
			} catch (error) {
				console.error('Error getting uses:', error)
				this.setUses({
					results: [],
					total: 0,
					page: 1,
					pages: 1,
					limit: 20,
					offset: 0,
				})
				throw error
			}
		},
		async getUsed(object, options = {}) {
			if (!object?.id) {
				throw new Error('No object id to get used by for')
			}

			try {
				let endpoint = this._buildObjectPath({
					register: object.register,
					schema: object.schema,
					objectId: object.id + '/used',
				})

				const params = []
				if (options.search && options.search !== '') {
					params.push('_search=' + options.search)
				}
				if (options.limit && options.limit !== '') {
					params.push('_limit=' + options.limit)
				}
				if (options.page && options.page !== '') {
					params.push('_page=' + options.page)
				}

				if (params.length > 0) {
					endpoint += '?' + params.join('&')
				}

				const response = await fetch(endpoint)
				const data = await response.json()
				this.setUsed(data)
				return { response, data }
			} catch (error) {
				console.error('Error getting used by:', error)
				this.setUsed({
					results: [],
					total: 0,
					page: 1,
					pages: 1,
					limit: 20,
					offset: 0,
				})
				throw error
			}
		},
	},
	getters: {
		isAllSelected() {
			if (!this.objectList?.results?.length) {
				return false
			}
			const currentIds = this.objectList.results.map(result => result['@self'].id)
			return currentIds.every(id => this.selectedObjects.includes(id))
		},
		// Add getter for enabled metadata columns
		enabledMetadata() {
			return Object.entries(this.metadata)
				.filter(([id]) => this.columnFilters[`meta_${id}`])
				.map(([id, meta]) => ({
					id: `meta_${id}`,
					...meta,
				}))
		},
		// Add getter for enabled property columns
		enabledProperties() {
			return Object.entries(this.properties)
				.filter(([id]) => this.columnFilters[`prop_${id}`])
				.map(([id, prop]) => ({
					id: `prop_${id}`,
					...prop,
				}))
		},
		// Separate getter for ID/UUID metadata
		enabledIdentifierMetadata() {
			return Object.entries(this.metadata)
				.filter(([id]) =>
					(id === 'objectId' || id === 'uuid')
					&& this.columnFilters[`meta_${id}`],
				)
				.map(([id, meta]) => ({
					id: `meta_${id}`,
					...meta,
				}))
		},
		// Separate getter for other metadata
		enabledOtherMetadata() {
			return Object.entries(this.metadata)
				.filter(([id]) =>
					id !== 'objectId'
					&& id !== 'uuid'
					&& this.columnFilters[`meta_${id}`],
				)
				.map(([id, meta]) => ({
					id: `meta_${id}`,
					...meta,
				}))
		},
		// Combined enabled columns in the desired order
		enabledColumns() {
			return [
				...this.enabledIdentifierMetadata, // ID/UUID first
				...this.enabledProperties, // Then properties
				...this.enabledOtherMetadata, // Then other metadata
			]
		},
	},
})

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
			name: {
				label: 'Name',
				key: 'name',
				description: 'Display name of the object',
				enabled: true, // Enabled by default
			},
			description: {
				label: 'Description',
				key: 'description',
				description: 'Description of the object',
				enabled: false,
			},
			objectId: {
				label: 'ID',
				key: 'id',
				description: 'Unique identifier of the object',
				enabled: false, // Changed from true to false
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
			locked: {
				label: 'Locked',
				key: 'locked',
				description: 'Lock status of the object',
				enabled: false,
			},
			organization: {
				label: 'Organization',
				key: 'organization',
				description: 'Organization that created the object',
				enabled: false,
			},
			validation: {
				label: 'Validation',
				key: 'validation',
				description: 'Validation status of the object',
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
			geo: {
				label: 'Geo',
				key: 'geo',
				description: 'Geographical location of the object',
				enabled: false,
			},
			retention: {
				label: 'Retention',
				key: 'retention',
				description: 'Retention status of the object',
				enabled: false,
			},
			size: {
				label: 'Size',
				key: 'size',
				description: 'Size of the object',
				enabled: false,
			},
			published: {
				label: 'Published',
				key: 'published',
				description: 'Published status of the object',
				enabled: false,
			},
			depublished: {
				label: 'Depublished',
				key: 'depublished',
				description: 'Depublished status of the object',
				enabled: false,
			},
			deleted: {
				label: 'Deleted',
				key: 'deleted',
				description: 'Deleted status of the object',
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
		// Facet-related state
		facets: {},
		facetableFields: {},
		activeFacets: {},
		facetsLoading: false,
		// Add new filters state for selected filter values
		activeFilters: {},
	}),
	actions: {
		// Helper method to build endpoint path
		/**
		 * Build the API endpoint path for objects
		 * @param {object} params - Path parameters
		 * @param {string} params.register - Register ID
		 * @param {string} params.schema - Schema ID
		 * @param {string} [params.objectId] - Optional object ID
		 * @return {string} Complete API endpoint path
		 */
		_buildObjectPath({ register, schema, objectId = '' }) {
			return `/index.php/apps/openregister/api/objects/${register}/${schema}${objectId ? '/' + objectId : ''}`
		},
		/**
		 * Set the active object item, optionally skipping backend refresh to avoid infinite loops.
		 * @param {object} objectItem - The object item to set
		 * @param {boolean} skipRefresh - If true, do not fetch from backend (prevents recursion)
		 */
		async setObjectItem(objectItem, skipRefresh = false) {

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

					// define register, schema, and objectId before using them
					const register = objectItem['@self'].register
					const schema = objectItem['@self'].schema
					const objectId = objectItem['@self'].id

					// Fore a reload for view logging
					if (!skipRefresh) {
						await this.getObject({ register, schema, objectId })

						console.info('Successfully fetched latest object data for object', objectItem['@self'].id)
					}

				} catch (error) {
					console.error('Error fetching related data:', error)
					// Clear data in case of error
					this.clearRelatedData()
				}
			} else if (objectItem === false) {
				// Clear related data when object item is explicitly set to null
				this.clearRelatedData()
			}
		},
		/**
		 * Set the object list with proper entity mapping
		 * @param {object} objectList - Object list data
		 * @return {void}
		 */
		setObjectList(objectList) {
			this.objectList = {
				...objectList,
				results: objectList.results.map(
					(objectItem) => new ObjectEntity(objectItem),
				),
			}

			// Update pagination information from the response
			this.pagination = {
				total: objectList.total || 0,
				page: objectList.page || 1,
				pages: objectList.pages || 1,
				limit: objectList.limit || 20,
				offset: objectList.offset || 0,
			}

			console.info('Object list set to ' + objectList.results.length + ' items')
			console.info('Pagination updated:', this.pagination)
		},
		/**
		 * Set the audit trail item
		 * @param {object|null} auditTrailItem - Audit trail item data
		 * @return {void}
		 */
		setAuditTrailItem(auditTrailItem) {
			this.auditTrailItem = auditTrailItem && new AuditTrail(auditTrailItem)
		},
		/**
		 * Set the audit trails list
		 * @param {object} auditTrails - Audit trails data
		 * @return {void}
		 */
		setAuditTrails(auditTrails) {
			this.auditTrails = auditTrails
			this.auditTrails.results = auditTrails.results
				? auditTrails.results.map(
					(auditTrail) => new AuditTrail(auditTrail),
				)
				: []
			console.info('Audit trails set to', this.auditTrails.results.length, 'items')
		},
		/**
		 * Set the contracts list
		 * @param {object} contracts - Contracts data
		 * @return {void}
		 */
		setContracts(contracts) {
			this.contracts = contracts
			this.contracts.results = contracts.results
				? contracts.results.map(
					(contract) => new ObjectEntity(contract),
				)
				: []
			console.info('Contracts set to', this.contracts.results.length, 'items')
		},
		/**
		 * Set the uses list
		 * @param {object} uses - Uses data
		 * @return {void}
		 */
		setUses(uses) {
			this.uses = uses
			this.uses.results = uses.results
				? uses.results.map(
					(use) => new ObjectEntity(use),
				)
				: []
			console.info('Uses set to', this.uses.results.length, 'items')
		},
		/**
		 * Set the used by list
		 * @param {object} used - Used by data
		 * @return {void}
		 */
		setUsed(used) {
			this.used = used
			this.used.results = used.results
				? used.results.map(
					(usedBy) => new ObjectEntity(usedBy),
				)
				: []
			console.info('Used by set to', this.used.results.length, 'items')
		},
		/**
		 * Set the files list
		 * @param {object} files - Files data
		 * @return {void}
		 */
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
		/**
		 * Refresh the object list with current filters and facets
		 * @param {object} [options] - Optional parameters
		 * @param {string} [options.register] - Register ID
		 * @param {string} [options.schema] - Schema ID
		 * @param {number} [options.limit] - Page limit
		 * @param {number} [options.page] - Page number
		 * @param {boolean} [options.includeFacets] - Whether to include facets
		 * @return {Promise<object>} Promise that resolves with response and data
		 */
		async refreshObjectList(options = {}) {
			const registerStore = useRegisterStore()
			const schemaStore = useSchemaStore()

			const register = options.register || registerStore.registerItem?.id
			const schema = options.schema || schemaStore.schemaItem?.id

			if (!register || !schema) {
				throw new Error('Register and schema are required')
			}

			this.loading = true

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

			// Handle active filters (from facet selections)
			Object.entries(this.activeFilters).forEach(([fieldName, values]) => {
				if (values && Array.isArray(values) && values.length > 0) {
					values.forEach(value => {
						if (fieldName.startsWith('@self.')) {
							// Handle metadata filters with potential operators
							// Check if the field has operators like [>=], [<=], etc.
							const operatorMatch = fieldName.match(/@self\.([^[]+)(\[.+\])/)
							if (operatorMatch) {
								// Field with operator: @self.created[>=] becomes @self[created][>=]
								const metadataField = operatorMatch[1]
								const operator = operatorMatch[2]

								// CRITICAL FIX: Convert operators to PHP-friendly names
								// PHP's $_GET parser can't handle operators like >= in array keys
								let phpOperator = operator
								if (operator === '[>=]') phpOperator = '[gte]'
								else if (operator === '[<=]') phpOperator = '[lte]'
								else if (operator === '[>]') phpOperator = '[gt]'
								else if (operator === '[<]') phpOperator = '[lt]'
								else if (operator === '[!=]') phpOperator = '[ne]'
								else if (operator === '[=]') phpOperator = '[eq]'

								// For metadata operators, only encode the value if it's not a date/time string
								// Date/time strings (ISO format) contain only safe URL characters
								const encodedValue = /^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\.\d{3})?Z?$/.test(value) ? value : encodeURIComponent(value)
								params.push(`@self[${metadataField}]${phpOperator}=${encodedValue}`)
								// eslint-disable-next-line no-console
								console.log(`Added operator filter: @self[${metadataField}]${phpOperator}=${encodedValue} (original: ${operator})`)
							} else {
								// Regular metadata filter
								const metadataField = fieldName.replace('@self.', '')
								params.push(`@self[${metadataField}][]=${encodeURIComponent(value)}`)
								// eslint-disable-next-line no-console
								console.log(`Added regular metadata filter: @self[${metadataField}][]=${value}`)
							}
						} else {
							// Handle object field filters
							params.push(`${fieldName}[]=${encodeURIComponent(value)}`)
							// eslint-disable-next-line no-console
							console.log(`Added object filter: ${fieldName}[]=${value}`)
						}
					})
				}
			})

			if (options.limit || this.pagination.limit) {
				params.push('_limit=' + (options.limit || this.pagination.limit))
			}
			if (options.page || this.pagination.page) {
				params.push('_page=' + (options.page || this.pagination.page))
			}

			// Include facets and facetable fields if requested
			// CRITICAL FIX: Don't send _facetable=true when using database source
			// Database source doesn't support faceting and will cause errors
			const isUsingDatabaseSource = this.filters._source === 'database'
			
			if (options.includeFacets !== false && !isUsingDatabaseSource) { // Default to true, but skip for database source
				// Always request facetable fields discovery to show available options
				params.push('_facetable=true')
				
				// Only send specific facet configuration if user has explicitly configured facets
				const facetConfiguration = this.buildFacetConfiguration()
				if (facetConfiguration?._facets) {
					// User has explicitly configured facets - add facet params
					this.addFacetParamsToUrl(params, facetConfiguration)
				}
				// If no facet configuration, still send _facetable=true for discovery
				// but don't send any specific facet parameters
			}

			if (params.length > 0) {
				endpoint += '?' + params.join('&')
			}

			// eslint-disable-next-line no-console
			console.log('refreshObjectList - Final endpoint:', endpoint)
			// eslint-disable-next-line no-console
			console.log('refreshObjectList - Params array:', params)

			try {
				const response = await fetch(endpoint)
				const data = await response.json()

				// Set the object list
				this.setObjectList(data)

				// Set facets if included in response
				if (data.facets) {
					this.setFacets(data.facets)
				}

				// Set facetable fields if included in response
				if (data.facetable) {
					this.setFacetableFields(data.facetable)
				}

				return { response, data }
			} catch (err) {
				console.error(err)
				this.setFacets({})
				this.setFacetableFields({})
				throw err
			} finally {
				this.loading = false
			}
		},
		/**
		 * Get a single object by ID
		 * @param {object} params - Object parameters
		 * @param {string} params.register - Register ID
		 * @param {string} params.schema - Schema ID
		 * @param {string} params.objectId - Object ID
		 * @return {Promise<object>} Promise that resolves with object data
		 */
		async getObject({ register, schema, objectId }) {
			if (!register || !schema || !objectId) {
				throw new Error('Register, schema and objectId are required')
			}

			const endpoint = this._buildObjectPath({ register, schema, objectId })

			try {
				const response = await fetch(endpoint)
				const data = await response.json()
				this.setObjectItem(data, true) // Prevent recursion by skipping refresh
				return data
			} catch (err) {
				console.error(err)
				throw err
			}
		},
		/**
		 * Save an object (create or update)
		 * @param {object} objectItem - Object data to save
		 * @param {object} params - Save parameters
		 * @param {string} params.register - Register ID
		 * @param {string} params.schema - Schema ID
		 * @return {Promise<object>} Promise that resolves with response and data
		 */
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
		/**
		 * Delete a single object
		 *
		 * @param {string|number} objectId The ID of the object to delete
		 * @param {object} options Optional parameters
		 * @return {Promise} Promise that resolves when the object is deleted
		 */
		async deleteObject(objectId, options = {}) {
			if (!objectId) throw new Error('No object id to delete')

			// Resolve register / schema the same way refreshObjectList does
			const registerStore = useRegisterStore()
			const schemaStore = useSchemaStore()
			const register = options.register || registerStore.registerItem?.id
			const schema = options.schema || schemaStore.schemaItem?.id
			if (!register || !schema) throw new Error('Register and schema are required')

			const endpoint = this._buildObjectPath({ register, schema, objectId })

			try {
				const response = await fetch(endpoint, { method: 'DELETE' })
				if (!response.ok) {
					throw new Error(`Failed to delete object: ${response.statusText}`)
				}
				await this.refreshObjectList({ register, schema })
				return { response }
			} catch (error) {
				console.error('Error deleting object:', error)
				throw error
			}
		},
		// mass delete objects
		/**
		 * Delete multiple objects
		 * @param {Array<string|number>} objectIds - Array of object IDs to delete
		 * @param {object} [options] - Optional parameters
		 * @param {string} [options.register] - Register ID
		 * @param {string} [options.schema] - Schema ID
		 * @return {Promise<object>} Promise that resolves with deletion results
		 */
		async massDeleteObject(objectIds, options = {}) {
			if (!objectIds?.length) throw new Error('No object ids to delete')

			// Resolve register / schema the same way refreshObjectList does
			const registerStore = useRegisterStore()
			const schemaStore = useSchemaStore()
			const register = options.register || registerStore.registerItem?.id
			const schema = options.schema || schemaStore.schemaItem?.id
			if (!register || !schema) throw new Error('Register and schema are required')

			console.info('Deleting objects…')
			const result = { successfulIds: [], failedIds: [] }

			await Promise.all(objectIds.map(async (objectId) => {
				const endpoint = this._buildObjectPath({ register, schema, objectId })
				try {
					const response = await fetch(endpoint, { method: 'DELETE' })
					;(response.ok ? result.successfulIds : result.failedIds).push(objectId)
				} catch (err) {
					console.error('Error deleting object:', err)
					result.failedIds.push(objectId)
				}
			}))

			await this.refreshObjectList({ register, schema })
			return result
		},
		// AUDIT TRAILS
		/**
		 * Get audit trails for an object
		 * @param {object} object - Object containing id, register, and schema
		 * @param {object} [options] - Optional parameters
		 * @param {string} [options.search] - Search term
		 * @param {number} [options.limit] - Page limit
		 * @param {number} [options.page] - Page number
		 * @return {Promise<object>} Promise that resolves with audit trails data
		 */
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
		/**
		 * Get object mappings
		 * @return {Promise<object>} Promise that resolves with mappings data
		 */
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
		 * @param {object} object Object containing id, register, and schema
		 * @param {string|null} process Optional process identifier
		 * @param {number|null} duration Lock duration in seconds
		 * @return {Promise} Promise that resolves when the object is locked
		 */
		async lockObject(object, process = null, duration = null) {
			if (!object?.id) throw new Error('No object id to lock')

			const endpoint = this._buildObjectPath({
				register: object.register,
				schema: object.schema,
				objectId: `${object.id}/lock`,
			})

			const response = await fetch(endpoint, {
				method: 'POST',
				headers: { 'Content-Type': 'application/json' },
				body: JSON.stringify({ process, duration }),
			})
			if (!response.ok) throw new Error(`HTTP error! status: ${response.status}`)

			const data = await response.json()
			this.setObjectItem(data)
			await this.refreshObjectList()
			return { response, data }
		},

		async unlockObject(object) {
			if (!object?.id) throw new Error('No object id to unlock')

			const endpoint = this._buildObjectPath({
				register: object.register,
				schema: object.schema,
				objectId: `${object.id}/unlock`,
			})

			const response = await fetch(endpoint, { method: 'POST' })
			if (!response.ok) throw new Error(`HTTP error! status: ${response.status}`)

			const data = await response.json()
			this.setObjectItem(data)
			await this.refreshObjectList()
			return { response, data }
		},
		/**
		 * Revert an object to a previous state
		 *
		 * @param {object} object Object containing id, register, and schema
		 * @param {object} options Revert options
		 * @param {string} [options.datetime] ISO datetime string
		 * @param {string} [options.auditTrailId] Audit trail ID
		 * @param {string} [options.version] Semantic version
		 * @param {boolean} [options.overwriteVersion] Whether to overwrite version
		 * @return {Promise} Promise that resolves when the object is reverted
		 */
		async revertObject(object, options) {
			if (!object?.id) throw new Error('No object id to revert')

			const endpoint = this._buildObjectPath({
				register: object.register,
				schema: object.schema,
				objectId: `${object.id}/revert`,
			})

			const response = await fetch(endpoint, {
				method: 'POST',
				headers: { 'Content-Type': 'application/json' },
				body: JSON.stringify(options),
			})
			if (!response.ok) throw new Error(`HTTP error! status: ${response.status}`)

			const data = await response.json()
			this.setObjectItem(data)
			await this.refreshObjectList()
			return { response, data }
		},
		/**
		 * Set selected objects
		 * @param {Array<string|number>} objects - Array of object IDs to select
		 * @return {void}
		 */
		setSelectedObjects(objects) {
			this.selectedObjects = objects
		},
		/**
		 * Set select all objects (legacy method)
		 * @return {void}
		 */
		setSelectAllObjects() {
			// Legacy method for compatibility - use toggleSelectAllObjects instead
			this.toggleSelectAllObjects()
		},
		/**
		 * Toggle select all objects
		 * @return {void}
		 */
		toggleSelectAllObjects() {
			if (this.isAllSelected) {
				// Clear selection
				this.selectedObjects = []
			} else {
				// Select all current objects
				this.selectedObjects = this.objectList.results.map(result => result['@self'].id)
			}
		},
		/**
		 * Update column filter
		 * @param {string} id - Filter ID
		 * @param {boolean} enabled - Whether the filter is enabled
		 * @return {void}
		 */
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
		/**
		 * Initialize properties based on schema
		 * @param {object} schema - Schema object with properties
		 * @return {void}
		 */
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
		/**
		 * Initialize column filters for both metadata and properties
		 * @return {void}
		 */
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
		/**
		 * Set property enabled state
		 * @param {string} propertyId - Property ID
		 * @param {boolean} enabled - Whether the property is enabled
		 * @return {void}
		 */
		setPropertyEnabled(propertyId, enabled) {
			if (this.properties[propertyId]) {
				this.properties[propertyId].enabled = enabled
				this.columnFilters[`prop_${propertyId}`] = enabled
				console.info('Property enabled state updated:', propertyId, enabled)
			}
		},
		/**
		 * Set metadata enabled state
		 * @param {string} metadataId - Metadata ID
		 * @param {boolean} enabled - Whether the metadata is enabled
		 * @return {void}
		 */
		setMetadataEnabled(metadataId, enabled) {
			if (this.metadata[metadataId]) {
				this.metadata[metadataId].enabled = enabled
				this.columnFilters[`meta_${metadataId}`] = enabled
				console.info('Metadata enabled state updated:', metadataId, enabled)
			}
		},
		/**
		 * Clear all related data
		 * @return {void}
		 */
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
		/**
		 * Get contracts for an object
		 * @param {object} object - Object containing id, register, and schema
		 * @param {object} [options] - Optional parameters
		 * @param {string} [options.search] - Search term
		 * @param {number} [options.limit] - Page limit
		 * @param {number} [options.page] - Page number
		 * @return {Promise<object>} Promise that resolves with contracts data
		 */
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
		/**
		 * Get uses for an object
		 * @param {object} object - Object containing id, register, and schema
		 * @param {object} [options] - Optional parameters
		 * @param {string} [options.search] - Search term
		 * @param {number} [options.limit] - Page limit
		 * @param {number} [options.page] - Page number
		 * @return {Promise<object>} Promise that resolves with uses data
		 */
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
		/**
		 * Get used by for an object
		 * @param {object} object - Object containing id, register, and schema
		 * @param {object} [options] - Optional parameters
		 * @param {string} [options.search] - Search term
		 * @param {number} [options.limit] - Page limit
		 * @param {number} [options.page] - Page number
		 * @return {Promise<object>} Promise that resolves with used by data
		 */
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
		/**
		 * Upload files to an object using the multipart endpoint
		 * @param {object} params - Upload parameters
		 * @param {string|number} params.register - Register ID
		 * @param {string|number} params.schema - Schema ID
		 * @param {string|number} params.objectId - Object ID
		 * @param {File[]} params.files - Array of File objects
		 * @param {string[]} [params.labels] - Optional labels/tags
		 * @param {boolean} [params.share] - Optional share flag
		 * @return {Promise} API response
		 */
		async uploadFiles({ register, schema, objectId, files, labels = [], share = false }) {
			if (!register || !schema || !objectId || !files?.length) {
				throw new Error('Missing required parameters for file upload')
			}

			// Use the /filesMultipart endpoint for proper backend handling
			const endpoint = `/index.php/apps/openregister/api/objects/${register}/${schema}/${objectId}/filesMultipart`
			const formData = new FormData()

			// Append files
			files.forEach((file, idx) => {
				formData.append('files', file)
			})
			// Append labels/tags if present
			if (labels && labels.length) {
				formData.append('tags', labels.join(','))
			}
			// Append share flag
			formData.append('share', share ? 'true' : 'false')

			try {
				const response = await fetch(endpoint, {
					method: 'POST',
					body: formData,
				})
				if (!response.ok) {
					throw new Error(`Failed to upload files: ${response.statusText}`)
				}
				return await response.json()
			} catch (error) {
				console.error('Error uploading files:', error)
				throw error
			}
		},
		/**
		 * Fetch all tags from the backend
		 * @return {Promise<{response: Response, data: Array}>} List of tags
		 */
		async getTags() {
			try {
				const response = await fetch('/index.php/apps/openregister/api/tags')
				if (!response.ok) {
					throw new Error('Failed to fetch tags')
				}
				const data = await response.json()
				return { response, data }
			} catch (error) {
				console.error('Error fetching tags:', error)
				throw error
			}
		},
		/**
		 * Publish an object with optional date
		 * If no published date is set and user wants to publish: set to now
		 * If a depublished date has been set and user wants to publish: remove the depublished date
		 * @param {object} params - Publish parameters
		 * @param {string|number} params.register - Register ID
		 * @param {string|number} params.schema - Schema ID
		 * @param {string|number} params.objectId - Object ID
		 * @param {string|null} params.publishedDate - Optional published date (ISO string), defaults to now
		 * @return {Promise} API response
		 */
		async publishObject({ register, schema, objectId, publishedDate = null }) {
			if (!register || !schema || !objectId) {
				throw new Error('Missing required parameters for object publish')
			}

			// Default to current time if no date provided
			const finalPublishedDate = publishedDate || new Date().toISOString()

			const endpoint = `/index.php/apps/openregister/api/objects/${register}/${schema}/${objectId}/publish`

			try {
				const body = { date: finalPublishedDate }
				const response = await fetch(endpoint, {
					method: 'POST',
					headers: {
						'Content-Type': 'application/json',
					},
					body: JSON.stringify(body),
				})
				if (!response.ok) {
					throw new Error(`Failed to publish object: ${response.statusText}`)
				}
				const data = await response.json()

				// Update the current object item if it matches
				if (this.objectItem && this.objectItem['@self'].id === objectId) {
					this.objectItem['@self'].published = data.published || finalPublishedDate
					// Remove depublished date when publishing
					this.objectItem['@self'].depublished = null
				}

				// Refresh object list to update the display
				await this.refreshObjectList()

				return { response, data }
			} catch (error) {
				console.error('Error publishing object:', error)
				throw error
			}
		},
		/**
		 * Depublish an object with optional date
		 * If no depublished date has been set and user wants to depublish: set to now
		 * When depublishing, the published date is NOT removed, only depublished date is set
		 * @param {object} params - Depublish parameters
		 * @param {string|number} params.register - Register ID
		 * @param {string|number} params.schema - Schema ID
		 * @param {string|number} params.objectId - Object ID
		 * @param {string|null} params.depublishedDate - Optional depublished date (ISO string), defaults to now
		 * @return {Promise} API response
		 */
		async depublishObject({ register, schema, objectId, depublishedDate = null }) {
			if (!register || !schema || !objectId) {
				throw new Error('Missing required parameters for object depublish')
			}

			// Default to current time if no date provided
			const finalDepublishedDate = depublishedDate || new Date().toISOString()

			const endpoint = `/index.php/apps/openregister/api/objects/${register}/${schema}/${objectId}/depublish`

			try {
				const body = { date: finalDepublishedDate }
				const response = await fetch(endpoint, {
					method: 'POST',
					headers: {
						'Content-Type': 'application/json',
					},
					body: JSON.stringify(body),
				})
				if (!response.ok) {
					throw new Error(`Failed to depublish object: ${response.statusText}`)
				}
				const data = await response.json()

				// Update the current object item if it matches
				if (this.objectItem && this.objectItem['@self'].id === objectId) {
					this.objectItem['@self'].depublished = data.depublished || finalDepublishedDate
					// Do NOT modify the published date when depublishing
				}

				// Refresh object list to update the display
				await this.refreshObjectList()

				return { response, data }
			} catch (error) {
				console.error('Error depublishing object:', error)
				throw error
			}
		},
		/**
		 * Publish a file for an object
		 * @param {object} params - Publish parameters
		 * @param {string|number} params.register - Register ID
		 * @param {string|number} params.schema - Schema ID
		 * @param {string|number} params.objectId - Object ID
		 * @param {string|number} params.fileId - ID of the file to publish
		 * @return {Promise} API response
		 */
		async publishFile({ register, schema, objectId, fileId }) {
			if (!register || !schema || !objectId || !fileId) {
				throw new Error('Missing required parameters for file publish')
			}

			const endpoint = `/index.php/apps/openregister/api/objects/${register}/${schema}/${objectId}/files/${fileId}/publish`

			try {
				const response = await fetch(endpoint, {
					method: 'POST',
				})
				if (!response.ok) {
					throw new Error(`Failed to publish file: ${response.statusText}`)
				}
				const data = await response.json()

				// Refresh files list after publishing
				await this.getFiles(this.objectItem)

				return { response, data }
			} catch (error) {
				console.error('Error publishing file:', error)
				throw error
			}
		},
		/**
		 * Unpublish a file for an object
		 * @param {object} params - Unpublish parameters
		 * @param {string|number} params.register - Register ID
		 * @param {string|number} params.schema - Schema ID
		 * @param {string|number} params.objectId - Object ID
		 * @param {string|number} params.fileId - ID of the file to unpublish
		 * @return {Promise} API response
		 */
		async unpublishFile({ register, schema, objectId, fileId }) {
			if (!register || !schema || !objectId || !fileId) {
				throw new Error('Missing required parameters for file unpublish')
			}

			const endpoint = `/index.php/apps/openregister/api/objects/${register}/${schema}/${objectId}/files/${fileId}/depublish`

			try {
				const response = await fetch(endpoint, {
					method: 'POST',
				})
				if (!response.ok) {
					throw new Error(`Failed to unpublish file: ${response.statusText}`)
				}
				const data = await response.json()

				// Refresh files list after unpublishing
				await this.getFiles(this.objectItem)

				return { response, data }
			} catch (error) {
				console.error('Error unpublishing file:', error)
				throw error
			}
		},
		/**
		 * Delete a file from an object
		 * @param {object} params - Delete parameters
		 * @param {string|number} params.register - Register ID
		 * @param {string|number} params.schema - Schema ID
		 * @param {string|number} params.objectId - Object ID
		 * @param {string|number} params.fileId - ID of the file to delete
		 * @return {Promise} API response
		 */
		async deleteFile({ register, schema, objectId, fileId }) {
			if (!register || !schema || !objectId || !fileId) {
				throw new Error('Missing required parameters for file delete')
			}

			const endpoint = `/index.php/apps/openregister/api/objects/${register}/${schema}/${objectId}/files/${fileId}`

			try {
				const response = await fetch(endpoint, {
					method: 'DELETE',
				})
				if (!response.ok) {
					throw new Error(`Failed to delete file: ${response.statusText}`)
				}
				const data = await response.json()

				// Refresh files list after deletion
				await this.getFiles(this.objectItem)

				return { response, data }
			} catch (error) {
				console.error('Error deleting file:', error)
				throw error
			}
		},
		/**
		 * Merge two objects within the same register and schema
		 * @param {object} params - Merge parameters
		 * @param {string|number} params.register - Register ID
		 * @param {string|number} params.schema - Schema ID
		 * @param {string|number} params.sourceObjectId - Source object ID (object to merge from)
		 * @param {string|number} params.target - Target object ID (object to merge into)
		 * @param {object} params.object - Merged object data (without id)
		 * @param {string} params.fileAction - File action: 'transfer' or 'delete'
		 * @param {string} params.relationAction - Relation action: 'transfer' or 'drop'
		 * @return {Promise} API response with merge result
		 */
		async mergeObjects({ register, schema, sourceObjectId, target, object, fileAction = 'transfer', relationAction = 'transfer' }) {
			if (!register || !schema || !sourceObjectId || !target || !object) {
				throw new Error('Missing required parameters for object merge')
			}

			const endpoint = `/index.php/apps/openregister/api/objects/${register}/${schema}/${sourceObjectId}/merge`

			try {
				const response = await fetch(endpoint, {
					method: 'POST',
					headers: {
						'Content-Type': 'application/json',
					},
					body: JSON.stringify({
						target,
						object,
						fileAction,
						relationAction,
					}),
				})

				if (!response.ok) {
					const errorData = await response.json()
					throw new Error(errorData.error || `Failed to merge objects: ${response.statusText}`)
				}

				const data = await response.json()

				// Refresh object list after merge
				await this.refreshObjectList({ register, schema })

				return { response, data }
			} catch (error) {
				console.error('Error merging objects:', error)
				throw error
			}
		},
		// Facet-related methods
		/**
		 * Set facets data
		 * @param {object} facets - Facets data
		 * @return {void}
		 */
		setFacets(facets) {
			this.facets = facets
		},
		/**
		 * Set facetable fields data
		 * @param {object} facetableFields - Facetable fields data
		 * @return {void}
		 */
		setFacetableFields(facetableFields) {
			this.facetableFields = facetableFields
		},
		/**
		 * Set active facets
		 * @param {object} activeFacets - Active facets configuration
		 * @return {void}
		 */
		setActiveFacets(activeFacets) {
			this.activeFacets = activeFacets
		},
		/**
		 * Set facets loading state
		 * @param {boolean} loading - Loading state
		 * @return {void}
		 */
		setFacetsLoading(loading) {
			this.facetsLoading = loading
		},
		/**
		 * Get facetable fields for the current register and schema
		 * This discovers what fields can be used for faceting
		 * @param {object} [options] - Optional parameters
		 * @param {string} [options.register] - Register ID
		 * @param {string} [options.schema] - Schema ID
		 * @return {Promise<object>} Promise that resolves with facetable fields data
		 */
		async getFacetableFields(options = {}) {
			const registerStore = useRegisterStore()
			const schemaStore = useSchemaStore()

			const register = options.register || registerStore.registerItem?.id
			const schema = options.schema || schemaStore.schemaItem?.id

			if (!register || !schema) {
				console.warn('Register and schema are required for facetable fields discovery')
				return
			}

			// CRITICAL FIX: Don't attempt to get facetable fields when using database source
			// Database source doesn't support faceting and will cause errors
			const isUsingDatabaseSource = this.filters._source === 'database'
			if (isUsingDatabaseSource) {
				console.warn('Facetable fields discovery skipped for database source')
				this.setFacetableFields({})
				return {}
			}

			this.setFacetsLoading(true)

			try {
				let endpoint = this._buildObjectPath({ register, schema })

				// Add facetable discovery parameter and limit to 0 for faster response
				const params = ['_facetable=true', '_limit=0']

				// Apply current filters for context-aware discovery
				Object.entries(this.filters).forEach(([key, value]) => {
					if (value !== undefined && value !== '') {
						params.push(`${key}=${encodeURIComponent(value)}`)
					}
				})

				endpoint += '?' + params.join('&')

				const response = await fetch(endpoint)
				const data = await response.json()

				if (data.facetable) {
					this.setFacetableFields(data.facetable)
				}

				return data.facetable
			} catch (error) {
				console.error('Error getting facetable fields:', error)
				this.setFacetableFields({})
				throw error
			} finally {
				this.setFacetsLoading(false)
			}
		},
		/**
		 * Get facets for the current search/filter context
		 * This returns actual facet counts based on the current query
		 * @param {object|null} [facetConfig] - Facet configuration object
		 * @param {object} [options] - Optional parameters
		 * @param {string} [options.register] - Register ID
		 * @param {string} [options.schema] - Schema ID
		 * @return {Promise<object>} Promise that resolves with facets data
		 */
		async getFacets(facetConfig = null, options = {}) {
			const registerStore = useRegisterStore()
			const schemaStore = useSchemaStore()

			const register = options.register || registerStore.registerItem?.id
			const schema = options.schema || schemaStore.schemaItem?.id

			if (!register || !schema) {
				console.warn('Register and schema are required for facets')
				return
			}

			// CRITICAL FIX: Don't attempt to get facets when using database source
			// Database source doesn't support faceting and will cause errors
			const isUsingDatabaseSource = this.filters._source === 'database'
			if (isUsingDatabaseSource) {
				console.warn('Facets discovery skipped for database source')
				this.setFacets({})
				return {}
			}

			this.setFacetsLoading(true)

			try {
				let endpoint = this._buildObjectPath({ register, schema })
				const params = []

				// Build facet configuration from active facets or provided config
				const facetConfiguration = facetConfig || this.buildFacetConfiguration()

				if (facetConfiguration && Object.keys(facetConfiguration).length > 0) {
					// Add facet configuration as URL parameters
					this.addFacetParamsToUrl(params, facetConfiguration)
				}

				// Apply current filters for context
				Object.entries(this.filters).forEach(([key, value]) => {
					if (value !== undefined && value !== '') {
						params.push(`${key}=${encodeURIComponent(value)}`)
					}
				})

				// Handle active filters (from facet selections)
				Object.entries(this.activeFilters).forEach(([fieldName, values]) => {
					if (values && Array.isArray(values) && values.length > 0) {
						values.forEach(value => {
							if (fieldName.startsWith('@self.')) {
								// Handle metadata filters
								const metadataField = fieldName.replace('@self.', '')
								params.push(`@self[${metadataField}][]=${encodeURIComponent(value)}`)
							} else {
								// Handle object field filters
								params.push(`${fieldName}[]=${encodeURIComponent(value)}`)
							}
						})
					}
				})

				// Limit to 0 to only get facets, not objects
				params.push('_limit=0')

				if (params.length > 0) {
					endpoint += '?' + params.join('&')
				}

				const response = await fetch(endpoint)
				const data = await response.json()

				if (data.facets) {
					this.setFacets(data.facets)
				}

				return data.facets
			} catch (error) {
				console.error('Error getting facets:', error)
				this.setFacets({})
				throw error
			} finally {
				this.setFacetsLoading(false)
			}
		},
		/**
		 * Build facet configuration from currently active facets
		 * @return {object} Facet configuration object
		 */
		buildFacetConfiguration() {
			// eslint-disable-next-line no-console
			console.log('buildFacetConfiguration - activeFacets:', this.activeFacets)

			const config = {}

			// Only build from explicitly active facets - no default configuration
			// Check for _facets property specifically since that's where our data is stored
			if (this.activeFacets._facets && Object.keys(this.activeFacets._facets).length > 0) {
				// eslint-disable-next-line no-console
				console.log('Using active facets:', this.activeFacets._facets)
				config._facets = this.activeFacets._facets
			} else {
				// eslint-disable-next-line no-console
				console.log('No active facets configured - returning empty configuration')
				// Return empty configuration when no facets are explicitly selected
				// This prevents sending default facets that the user didn't request
				return null
			}

			// eslint-disable-next-line no-console
			console.log('Final facet configuration:', config)
			return config
		},
		/**
		 * Add facet parameters to URL params array
		 * @param {Array} params - URL params array
		 * @param {object} facetConfig - Facet configuration object
		 * @return {void}
		 */
		addFacetParamsToUrl(params, facetConfig) {
			// eslint-disable-next-line no-console
			console.log('addFacetParamsToUrl - facetConfig:', facetConfig)

			if (facetConfig._facets) {
				// Handle @self metadata facets
				if (facetConfig._facets['@self']) {
					// eslint-disable-next-line no-console
					console.log('Processing @self facets:', facetConfig._facets['@self'])

					Object.entries(facetConfig._facets['@self']).forEach(([field, config]) => {
						// eslint-disable-next-line no-console
						console.log(`Processing field: ${field}, config:`, config)

						params.push(`_facets[@self][${field}][type]=${config.type}`)
						if (config.interval) {
							params.push(`_facets[@self][${field}][interval]=${config.interval}`)
						}
						if (config.terms && Array.isArray(config.terms)) {
							config.terms.forEach((term, index) => {
								params.push(`_facets[@self][${field}][terms][${index}]=${encodeURIComponent(term)}`)
							})
						}
						if (config.ranges) {
							// eslint-disable-next-line no-console
							console.log(`Adding range parameters for ${field}:`, config.ranges)
							config.ranges.forEach((range, index) => {
								if (range.from) {
									const param = `_facets[@self][${field}][ranges][${index}][from]=${range.from}`
									// eslint-disable-next-line no-console
									console.log('Adding FROM param:', param)
									params.push(param)
								}
								if (range.to) {
									const param = `_facets[@self][${field}][ranges][${index}][to]=${range.to}`
									// eslint-disable-next-line no-console
									console.log('Adding TO param:', param)
									params.push(param)
								}
								if (range.key) params.push(`_facets[@self][${field}][ranges][${index}][key]=${range.key}`)
							})
						}
					})
				}

				// Handle object field facets
				Object.entries(facetConfig._facets).forEach(([field, config]) => {
					if (field !== '@self') {
						params.push(`_facets[${field}][type]=${config.type}`)
						if (config.interval) {
							params.push(`_facets[${field}][interval]=${config.interval}`)
						}
						if (config.terms && Array.isArray(config.terms)) {
							config.terms.forEach((term, index) => {
								params.push(`_facets[${field}][terms][${index}]=${encodeURIComponent(term)}`)
							})
						}
						if (config.ranges) {
							config.ranges.forEach((range, index) => {
								if (range.from) params.push(`_facets[${field}][ranges][${index}][from]=${range.from}`)
								if (range.to) params.push(`_facets[${field}][ranges][${index}][to]=${range.to}`)
								if (range.key) params.push(`_facets[${field}][ranges][${index}][key]=${range.key}`)
							})
						}
					}
				})
			}
		},
		/**
		 * Update active facets and refresh data
		 * @param {string} field - Field name
		 * @param {string} facetType - Facet type
		 * @param {boolean} [enabled] - Whether to enable or disable the facet
		 * @return {Promise<void>} Promise that resolves when the facet is updated
		 */
		async updateActiveFacet(field, facetType, enabled = true) {
			if (enabled) {
				if (!this.activeFacets._facets) {
					this.activeFacets._facets = {}
				}

				if (field.startsWith('@self.')) {
					if (!this.activeFacets._facets['@self']) {
						this.activeFacets._facets['@self'] = {}
					}
					const fieldName = field.replace('@self.', '')
					this.activeFacets._facets['@self'][fieldName] = { type: facetType }
				} else {
					this.activeFacets._facets[field] = { type: facetType }
				}
			} else {
				// Remove facet
				if (field.startsWith('@self.')) {
					const fieldName = field.replace('@self.', '')
					if (this.activeFacets._facets?.['@self']) {
						delete this.activeFacets._facets['@self'][fieldName]
					}
				} else if (this.activeFacets._facets) {
					delete this.activeFacets._facets[field]
				}
			}

			// Get updated facets
			await this.getFacets()
		},
		// Add methods to manage active filters
		/**
		 * Set active filters
		 * @param {object} filters - Filters object
		 * @return {void}
		 */
		setActiveFilters(filters) {
			this.activeFilters = filters
		},
		/**
		 * Update filter for a field
		 * @param {string} fieldName - Field name
		 * @param {Array|string} values - Filter values
		 * @return {void}
		 */
		updateFilter(fieldName, values) {
			if (!values || (Array.isArray(values) && values.length === 0)) {
				// Remove filter if no values
				if (this.activeFilters[fieldName]) {
					delete this.activeFilters[fieldName]
				}
			} else {
				// Set filter values
				this.activeFilters[fieldName] = Array.isArray(values) ? values : [values]
			}
		},
		/**
		 * Clear all active filters
		 * @return {void}
		 */
		clearAllFilters() {
			this.activeFilters = {}
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
		// Facet-related getters
		availableMetadataFacets() {
			return this.facetableFields?.['@self'] || {}
		},
		availableObjectFieldFacets() {
			return this.facetableFields?.object_fields || {}
		},
		allAvailableFacets() {
			return {
				...this.availableMetadataFacets,
				...this.availableObjectFieldFacets,
			}
		},
		currentFacets() {
			return this.facets || {}
		},
		hasFacets() {
			return Object.keys(this.currentFacets).length > 0
		},
		hasFacetableFields() {
			return Object.keys(this.allAvailableFacets).length > 0
		},
		// Active filters getters
		hasActiveFilters() {
			return Object.keys(this.activeFilters).length > 0
		},
		activeFilterCount() {
			return Object.values(this.activeFilters).reduce((total, values) => total + (Array.isArray(values) ? values.length : 0), 0)
		},
	},
})

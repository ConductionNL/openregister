/* eslint-disable no-console */
import { createCrudStore } from '@conduction/nextcloud-vue'
import { Organisation } from '../../entities/index.js'

export const useOrganisationStore = createCrudStore('organisation', {
	endpoint: 'organisations',
	entity: Organisation,
	cleanFields: ['id', 'uuid', 'created', 'updated', 'users', 'userCount', 'groupCount', 'usage', 'owner', 'isDefault'],
	features: { viewMode: true },
	// Custom response parser — the organisations endpoint returns userStats format
	parseListResponse(json) {
		this.setUserStats(json)
		return json.results || []
	},
	extend: {
		state: () => ({
			activeOrganisation: null,
			userStats: { total: 0, active: null, list: [] },
			nextcloudGroups: [],
		}),
		getters: {
			activeOrganisationGetter: (state) => state.activeOrganisation,
			getUserOrganisations: (state) => state.userStats.list,
		},
		actions: {
			setActiveOrganisation(activeOrganisation) {
				this.activeOrganisation = activeOrganisation && new Organisation(activeOrganisation)
				console.log('Active organisation set to ' + (activeOrganisation?.name || 'null'))
			},
			setUserStats(stats) {
				this.userStats = {
					total: stats.total || 0,
					active: stats.active ? new Organisation(stats.active) : null,
					list: (stats.results || []).map(org => new Organisation(org)),
				}
				console.log('User organisation stats set:', this.userStats)
			},
			// Override cleanForSave — handle boolean coercion and slug cleanup
			cleanForSave(organisationItem) {
				const cleaned = { ...organisationItem }
				for (const field of this._options.cleanFields) {
					delete cleaned[field]
				}
				if (cleaned.slug === '' || cleaned.slug === null) {
					delete cleaned.slug
				}
				if (cleaned.active !== undefined) {
					cleaned.active = cleaned.active === '' ? true : Boolean(cleaned.active)
				}
				return cleaned
			},
			async getActiveOrganisation() {
				const endpoint = this._options.baseApiUrl + '/active'
				try {
					const response = await fetch(endpoint, { method: 'GET' })
					const data = await response.json()
					if (data.activeOrganisation) {
						this.setActiveOrganisation(data.activeOrganisation)
					}
					return data.activeOrganisation
				} catch (err) {
					console.error(err)
					throw err
				}
			},
			// Override getOne — unwraps data.organisation
			async getOne(uuid, options = { setItem: false }) {
				const endpoint = `${this._options.baseApiUrl}/${uuid}`
				try {
					const response = await fetch(endpoint, { method: 'GET' })
					const data = await response.json()
					if (data.organisation) {
						if (options.setItem) this.setItem(data.organisation)
						return data.organisation
					}
					return data
				} catch (err) {
					console.error(err)
					throw err
				}
			},
			async setActiveOrganisationById(uuid) {
				const endpoint = `${this._options.baseApiUrl}/${uuid}/set-active`
				try {
					const response = await fetch(endpoint, { method: 'POST' })
					if (!response.ok) throw new Error(`HTTP error! status: ${response.status}`)
					const data = await response.json()
					if (data.activeOrganisation) {
						this.setActiveOrganisation(data.activeOrganisation)
					}
					await this.refreshList()
					return { response, data }
				} catch (error) {
					console.error('Error setting active organisation:', error)
					throw new Error(`Failed to set active organisation: ${error.message}`)
				}
			},
			async joinOrganisation(uuid, userId = null) {
				const endpoint = `${this._options.baseApiUrl}/${uuid}/join`
				try {
					const requestBody = userId ? { userId } : {}
					const response = await fetch(endpoint, {
						method: 'POST',
						headers: { 'Content-Type': 'application/json' },
						body: JSON.stringify(requestBody),
					})
					if (!response.ok) throw new Error(`HTTP error! status: ${response.status}`)
					const data = await response.json()
					await this.refreshList()
					return { response, data }
				} catch (error) {
					console.error('Error joining organisation:', error)
					throw new Error(`Failed to join organisation: ${error.message}`)
				}
			},
			async leaveOrganisation(uuid) {
				const endpoint = `${this._options.baseApiUrl}/${uuid}/leave`
				try {
					const response = await fetch(endpoint, { method: 'POST' })
					if (!response.ok) throw new Error(`HTTP error! status: ${response.status}`)
					const data = await response.json()
					await this.refreshList()
					await this.getActiveOrganisation()
					return { response, data }
				} catch (error) {
					console.error('Error leaving organisation:', error)
					throw new Error(`Failed to leave organisation: ${error.message}`)
				}
			},
			// Override save — organisation uses separate create/update with different response handling
			async save(organisationItem) {
				const isNew = !organisationItem.uuid && !organisationItem.id
				if (isNew) {
					return await this.createOrganisation(organisationItem)
				} else {
					return await this.updateOrganisation(organisationItem)
				}
			},
			async createOrganisation(organisationData) {
				console.log('Creating organisation...', organisationData)
				try {
					const response = await fetch(this._options.baseApiUrl, {
						method: 'POST',
						headers: { 'Content-Type': 'application/json' },
						body: JSON.stringify(organisationData),
					})
					if (!response.ok) {
						const errorData = await response.json()
						throw new Error(errorData.message || `HTTP error! status: ${response.status}`)
					}
					const data = await response.json()
					const savedOrganisation = data.organisation || data
					this.setItem(savedOrganisation)
					await this.refreshList()
					console.log('Organisation created successfully:', savedOrganisation)
					return { response, data: savedOrganisation }
				} catch (error) {
					console.error('Error creating organisation:', error)
					throw new Error(`Failed to create organisation: ${error.message}`)
				}
			},
			async updateOrganisation(organisationData) {
				console.log('Updating organisation...', organisationData)
				if (!organisationData.id && !organisationData.uuid) {
					throw new Error('Organisation UUID is required for updates')
				}
				const organisationId = organisationData.uuid || organisationData.id
				const endpoint = `${this._options.baseApiUrl}/${organisationId}`
				const cleanedData = this.cleanForSave(organisationData)
				try {
					const response = await fetch(endpoint, {
						method: 'PUT',
						headers: { 'Content-Type': 'application/json' },
						body: JSON.stringify(cleanedData),
					})
					if (!response.ok) {
						const errorData = await response.json()
						throw new Error(errorData.message || `HTTP error! status: ${response.status}`)
					}
					const data = await response.json()
					const savedOrganisation = data.organisation || data
					const index = this.list.findIndex(org =>
						org.uuid === organisationId || org.id === organisationId,
					)
					if (index !== -1) {
						this.list[index] = new Organisation(savedOrganisation)
					}
					if (this.item && (this.item.uuid === organisationId || this.item.id === organisationId)) {
						this.setItem(savedOrganisation)
					}
					await this.refreshList()
					console.log('Organisation updated successfully:', savedOrganisation)
					return { response, data: savedOrganisation }
				} catch (error) {
					console.error('Error updating organisation:', error)
					throw new Error(`Failed to update organisation: ${error.message}`)
				}
			},
			async searchOrganisations(query = '', limit = 50, offset = 0) {
				const params = new URLSearchParams()
				if (query.trim()) params.append('query', query.trim())
				params.append('_limit', limit)
				params.append('_offset', offset)
				const endpoint = `${this._options.baseApiUrl}/search?${params.toString()}`
				try {
					const response = await fetch(endpoint, { method: 'GET' })
					if (!response.ok) throw new Error(`HTTP error! status: ${response.status}`)
					const data = await response.json()
					return data.organisations || []
				} catch (error) {
					console.error('Error searching organisations:', error)
					throw new Error(`Failed to search organisations: ${error.message}`)
				}
			},
			async clearCache() {
				const endpoint = this._options.baseApiUrl + '/clear-cache'
				try {
					const response = await fetch(endpoint, { method: 'POST' })
					const data = await response.json()
					console.log('Organisation cache cleared:', data.message)
					await this.refreshList()
					return { response, data }
				} catch (error) {
					console.error('Error clearing cache:', error)
					throw new Error(`Failed to clear cache: ${error.message}`)
				}
			},
			async loadNextcloudGroups() {
				try {
					const response = await fetch('/ocs/v1.php/cloud/groups?format=json', {
						headers: { 'OCS-APIRequest': 'true' },
					})
					if (response.ok) {
						const data = await response.json()
						if (data.ocs?.data?.groups) {
							this.nextcloudGroups = data.ocs.data.groups.map(groupId => ({
								id: groupId,
								name: groupId,
								userCount: 0,
							}))
							console.log('Loaded', this.nextcloudGroups.length, 'Nextcloud groups into organisation store')
						}
					} else {
						console.warn('Failed to load Nextcloud groups:', response.statusText)
					}
				} catch (error) {
					console.error('Error loading Nextcloud groups:', error)
				}
			},
		},
	},
})

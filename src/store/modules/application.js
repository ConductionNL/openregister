/* eslint-disable no-console */
import { createCrudStore } from '@conduction/nextcloud-vue'
import { Application } from '../../entities/index.js'

export const useApplicationStore = createCrudStore('application', {
	endpoint: 'applications',
	entity: Application,
	cleanFields: ['id', 'uuid', 'created', 'updated', 'usage', 'owner'],
	features: { loading: true, viewMode: true },
	extend: {
		state: () => ({
			nextcloudGroups: [],
		}),
		actions: {
			/**
			 * Override cleanForSave to handle boolean coercion for active field.
			 * @param {object} item Raw item data
			 * @return {object} Cleaned copy safe for POST/PUT
			 */
			cleanForSave(item) {
				const cleaned = { ...item }
				for (const field of this._options.cleanFields) {
					delete cleaned[field]
				}
				if (cleaned.active !== undefined) {
					cleaned.active = cleaned.active === '' ? true : Boolean(cleaned.active)
				}
				return cleaned
			},
			/**
			 * Load and cache Nextcloud groups for application access control
			 * @return {Promise<void>}
			 */
			async loadNextcloudGroups() {
				try {
					const response = await fetch('/ocs/v1.php/cloud/groups?format=json', {
						headers: {
							'OCS-APIRequest': 'true',
						},
					})
					if (response.ok) {
						const data = await response.json()
						if (data.ocs?.data?.groups) {
							this.nextcloudGroups = data.ocs.data.groups.map(groupId => ({
								id: groupId,
								name: groupId,
								userCount: 0,
							}))
							console.log('Loaded', this.nextcloudGroups.length, 'Nextcloud groups into application store')
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

/* eslint-disable no-console */
import { defineStore } from 'pinia'
import { Source } from '../../entities/index.js'

export const useSourceStore = defineStore(
	'source', {
		state: () => ({
			sourceItem: false,
			sourceList: [],
		}),
		actions: {
			setSourceItem(sourceItem) {
				this.sourceItem = sourceItem && new Source(sourceItem)
				console.log('Active source item set to ' + sourceItem)
			},
			setSourceList(sourceList) {
				this.sourceList = sourceList.map(
					(sourceItem) => new Source(sourceItem),
				)
				console.log('Source list set to ' + sourceList.length + ' items')
			},
			/* istanbul ignore next */ // ignore this for Jest until moved into a service
			async refreshSourceList(search = null) {
				// @todo this might belong in a service?
				let endpoint = '/index.php/apps/openregister/api/sources'
				if (search !== null && search !== '') {
					endpoint = endpoint + '?_search=' + search
				}
				return fetch(endpoint, {
					method: 'GET',
				})
					.then(
						(response) => {
							response.json().then(
								(data) => {
									this.setSourceList(data.results)
								},
							)
						},
					)
					.catch(
						(err) => {
							console.error(err)
						},
					)
			},
			// New function to get a single source
			async getSource(id) {
				const endpoint = `/index.php/apps/openregister/api/sources/${id}`
				try {
					const response = await fetch(endpoint, {
						method: 'GET',
					})
					const data = await response.json()
					this.setSourceItem(data)
					return data
				} catch (err) {
					console.error(err)
					throw err
				}
			},
			// Delete a source
			deleteSource() {
				if (!this.sourceItem || !this.sourceItem.id) {
					throw new Error('No source item to delete')
				}

				console.log('Deleting source...')

				const endpoint = `/index.php/apps/openregister/api/sources/${this.sourceItem.id}`

				return fetch(endpoint, {
					method: 'DELETE',
				})
					.then((response) => {
						this.refreshSourceList()
					})
					.catch((err) => {
						console.error('Error deleting source:', err)
						throw err
					})
			},
			// Create or save a source from store
			saveSource() {
				if (!this.sourceItem) {
					throw new Error('No source item to save')
				}

				console.log('Saving source...')

				const isNewSource = !this.sourceItem.id
				const endpoint = isNewSource
					? '/index.php/apps/openregister/api/sources'
					: `/index.php/apps/openregister/api/sources/${this.sourceItem.id}`
				const method = isNewSource ? 'POST' : 'PUT'

				// Create a copy of the source item and remove empty properties
				const sourceToSave = { ...this.sourceItem }
				Object.keys(sourceToSave).forEach(key => {
					if (sourceToSave[key] === '' || (Array.isArray(sourceToSave[key]) && sourceToSave[key].length === 0)) {
						delete sourceToSave[key]
					}
				})

				return fetch(
					endpoint,
					{
						method,
						headers: {
							'Content-Type': 'application/json',
						},
						body: JSON.stringify(sourceToSave),
					},
				)
					.then((response) => response.json())
					.then((data) => {
						this.setSourceItem(data)
						console.log('Source saved')
						// Refresh the source list
						return this.refreshSourceList()
					})
					.catch((err) => {
						console.error('Error saving source:', err)
						throw err
					})
			},
		},
	},
)

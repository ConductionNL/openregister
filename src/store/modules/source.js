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
					.then(response => response.json())
					.then(data => {
						this.setSourceList(data.results)
						return this.sourceList // Return the updated source list
					})
					.catch(err => {
						console.error(err)
						throw err // Re-throw the error to be caught by the caller
					})
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
			async deleteSource(sourceItem) {
				if (!sourceItem.id) {
					throw new Error('No source item to delete')
				}

				console.log('Deleting source...')

				const endpoint = `/index.php/apps/openregister/api/sources/${sourceItem.id}`

				const response = await fetch(endpoint, {
					method: 'DELETE',
				})

				if (!response.ok) {
					throw new Error(`HTTP error! status: ${response.status}`)
				}

				const responseData = await response.json()

				if (!responseData || typeof responseData !== 'object') {
					throw new Error('Invalid response data')
				}

				this.refreshSourceList()

				return { response, data: responseData }

			},
			// Create or save a source from store
			async saveSource(sourceItem) {
				if (!sourceItem) {
					throw new Error('No source item to save')
				}

				console.log('Saving source...')

				const isNewSource = !sourceItem.id
				const endpoint = isNewSource
					? '/index.php/apps/openregister/api/sources'
					: `/index.php/apps/openregister/api/sources/${sourceItem.id}`
				const method = isNewSource ? 'POST' : 'PUT'

				const response = await fetch(
					endpoint,
					{
						method,
						headers: {
							'Content-Type': 'application/json',
						},
						body: JSON.stringify(sourceItem),
					},
				)

				if (!response.ok) {
					throw new Error(`HTTP error! status: ${response.status}`)
				}

				const responseData = await response.json()

				if (!responseData || typeof responseData !== 'object') {
					throw new Error('Invalid response data')
				}

				const data = new Source(responseData)

				this.setSourceItem(data)
				await this.refreshSourceList()

				return { response, data }
			},
		},
	},
)

import { defineStore } from 'pinia'
import { Source } from '../../entities/index.js'

export const useSourceStore = defineStore('source', {
	state: () => ({
		sourceItem: false,
		sourceList: [],
	}),
	actions: {
		/**
		 * Set the active source item.
		 *
		 * @param {object|null} sourceItem - The source item to set
		 * @spec exclude store setter (wraps Source entity construction)
		 */
		setSourceItem(sourceItem) {
			this.sourceItem = sourceItem && new Source(sourceItem)
		},
		/**
		 * Set the source list.
		 *
		 * @param {Array} sourceList - Array of source objects
		 * @spec exclude store setter (maps to Source entities)
		 */
		setSourceList(sourceList) {
			this.sourceList = sourceList.map((sourceItem) => new Source(sourceItem))
		},
		/**
		 * Refresh the source list from the API
		 *
		 * @param {string|null} search - Optional search term
		 * @param {boolean} _soft - If true, don't show loading state (default: false)
		 * @return {Promise} Promise with source list
		 * @spec exclude API passthrough to GET /api/sources (list)
		 */
		/* istanbul ignore next */ // ignore this for Jest until moved into a service
		async refreshSourceList(search = null, _soft = false) {
			// @todo this might belong in a service?
			let endpoint = '/index.php/apps/openregister/api/sources'
			if (search !== null && search !== '') {
				endpoint = endpoint + '?_search=' + search
			}
			return fetch(endpoint, {
				method: 'GET',
			})
				.then((response) => response.json())
				.then((data) => {
					this.setSourceList(data.results)
					return this.sourceList // Return the updated source list
				})
				.catch((err) => {
					console.error(err)
					throw err // Re-throw the error to be caught by the caller
				})
		},
		/**
		 * Get a single source by id.
		 *
		 * @param {number|string} id - Source id
		 * @return {Promise} Promise with source data
		 * @spec exclude API passthrough to GET /api/sources/{id}
		 */
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
		/**
		 * Delete a source.
		 *
		 * @param {object} sourceItem - The source to delete
		 * @return {Promise} Promise with response and data
		 * @spec exclude API passthrough to DELETE /api/sources/{id}
		 */
		async deleteSource(sourceItem) {
			if (!sourceItem.id) {
				throw new Error('No source item to delete')
			}

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
			this.setSourceItem(null)

			return { response, data: responseData }
		},
		/**
		 * Create or save a source from store.
		 *
		 * @param {object} sourceItem - The source to save
		 * @return {Promise} Promise with response and data
		 * @spec exclude API passthrough to POST/PUT /api/sources
		 */
		async saveSource(sourceItem) {
			if (!sourceItem) {
				throw new Error('No source item to save')
			}

			const isNewSource = !sourceItem.id
			const endpoint = isNewSource
				? '/index.php/apps/openregister/api/sources'
				: `/index.php/apps/openregister/api/sources/${sourceItem.id}`
			const method = isNewSource ? 'POST' : 'PUT'

			sourceItem.updated = new Date().toISOString()

			const response = await fetch(endpoint, {
				method,
				headers: {
					'Content-Type': 'application/json',
				},
				body: JSON.stringify(sourceItem),
			})

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
})

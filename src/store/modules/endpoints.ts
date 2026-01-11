import { ref } from 'vue'
import { defineStore } from 'pinia'

const apiEndpoint = '/index.php/apps/openregister/api/endpoints'

export const useEndpointStore = defineStore('endpoint', () => {
	// state.
	const endpointItem = ref(null)
	const endpointList = ref([])
	const viewMode = ref('cards')

	// ################################
	// ||    Setters and Getters     ||
	// ################################

	/**
	 * Set the active endpoint item.
	 * @param item - The endpoint item to set
	 */
	const setEndpointItem = (item) => {
		endpointItem.value = item
		console.info('Active endpoint item set to ' + (item ? item.id : 'null'))
	}

	/**
	 * Get the active endpoint item.
	 *
	 * @description
	 * Returns the currently active endpoint item. Note that the return value is non-reactive.
	 *
	 * For reactive usage, either:
	 * 1. Reference the `endpointItem` state directly:
	 * ```js
	 * const endpointItem = useEndpointStore().endpointItem // reactive state
	 * ```
	 * 2. Or wrap in a `computed` property:
	 * ```js
	 * const endpointItem = computed(() => useEndpointStore().getEndpointItem())
	 * ```
	 *
	 * @return {object | null} The active endpoint item
	 */
	const getEndpointItem = () => endpointItem.value

	/**
	 * Set the active endpoint list.
	 * @param list - The endpoint list to set
	 */
	const setEndpointList = (list) => {
		endpointList.value = list
		console.info('Endpoint list set to ' + list.length + ' items')
	}

	/**
	 * Get the active endpoint list.
	 *
	 * @description
	 * Returns the currently active endpoint list. Note that the return value is non-reactive.
	 *
	 * For reactive usage, either:
	 * 1. Reference the `endpointList` state directly:
	 * ```js
	 * const endpointList = useEndpointStore().endpointList // reactive state
	 * ```
	 * 2. Or wrap in a `computed` property:
	 * ```js
	 * const endpointList = computed(() => useEndpointStore().getEndpointList())
	 * ```
	 *
	 * @return {Array} The active endpoint list
	 */
	const getEndpointList = () => endpointList.value

	// ################################
	// ||          Actions           ||
	// ################################

	/**
	 * Fetch the list of endpoints from the API
	 */
	const refreshEndpointList = () => {
		console.info('Refreshing endpoint list')

		fetch(apiEndpoint, {
			method: 'GET',
		})
			.then((response) => {
				response.json().then((data) => {
					setEndpointList(data.results)
				})
			})
			.catch((err) => {
				console.error('Error fetching endpoint list:', err)
			})
	}

	/**
	 * Create a new endpoint on the API
	 * @param item - The endpoint item to create
	 */
	const createEndpoint = (item) => {
		console.info('Creating endpoint:', item)

		return fetch(apiEndpoint, {
			method: 'POST',
			headers: {
				'Content-Type': 'application/json',
			},
			body: JSON.stringify(item),
		})
			.then((response) => {
				if (!response.ok) {
					throw new Error('Failed to create endpoint')
				}
				return response.json()
			})
			.then((data) => {
				console.info('Endpoint created successfully:', data)
				setEndpointItem(data)
				refreshEndpointList()
				return data
			})
			.catch((err) => {
				console.error('Error creating endpoint:', err)
				throw err
			})
	}

	/**
	 * Update an existing endpoint on the API
	 * @param item - The endpoint item to update
	 */
	const updateEndpoint = (item) => {
		if (!item.id) {
			throw new Error('Endpoint ID is required for update')
		}

		console.info('Updating endpoint:', item)

		return fetch(`${apiEndpoint}/${item.id}`, {
			method: 'PUT',
			headers: {
				'Content-Type': 'application/json',
			},
			body: JSON.stringify(item),
		})
			.then((response) => {
				if (!response.ok) {
					throw new Error('Failed to update endpoint')
				}
				return response.json()
			})
			.then((data) => {
				console.info('Endpoint updated successfully:', data)
				setEndpointItem(data)
				refreshEndpointList()
				return data
			})
			.catch((err) => {
				console.error('Error updating endpoint:', err)
				throw err
			})
	}

	/**
	 * Delete an endpoint from the API
	 * @param item - The endpoint item to delete
	 */
	const deleteEndpoint = (item) => {
		if (!item.id) {
			throw new Error('Endpoint ID is required for deletion')
		}

		console.info('Deleting endpoint:', item)

		return fetch(`${apiEndpoint}/${item.id}`, {
			method: 'DELETE',
		})
			.then((response) => {
				if (!response.ok) {
					throw new Error('Failed to delete endpoint')
				}
				console.info('Endpoint deleted successfully')
				// Clear the active item if it was the deleted one.
				if (endpointItem.value && endpointItem.value.id === item.id) {
					setEndpointItem(null)
				}
				refreshEndpointList()
			})
			.catch((err) => {
				console.error('Error deleting endpoint:', err)
				throw err
			})
	}

	/**
	 * Test an endpoint by executing it
	 * @param item - The endpoint item to test
	 * @param testData - Optional test data to send
	 */
	const testEndpoint = (item, testData = {}) => {
		if (!item.id) {
			throw new Error('Endpoint ID is required for testing')
		}

		console.info('Testing endpoint:', item)

		return fetch(`${apiEndpoint}/${item.id}/test`, {
			method: 'POST',
			headers: {
				'Content-Type': 'application/json',
			},
			body: JSON.stringify({ data: testData }),
		})
			.then((response) => {
				return response.json().then((data) => {
					if (!response.ok) {
						throw new Error(data.error || data.message || 'Failed to test endpoint')
					}
					return data
				})
			})
			.then((data) => {
				console.info('Endpoint tested successfully:', data)
				return data
			})
			.catch((err) => {
				console.error('Error testing endpoint:', err)
				throw err
			})
	}

	return {
		// State.
		endpointItem,
		endpointList,
		viewMode,
		// Getters/Setters.
		setEndpointItem,
		getEndpointItem,
		setEndpointList,
		getEndpointList,
		// Actions.
		refreshEndpointList,
		createEndpoint,
		updateEndpoint,
		deleteEndpoint,
		testEndpoint,
	}
})


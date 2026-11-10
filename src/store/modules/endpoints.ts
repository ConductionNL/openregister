import { defineStore } from 'pinia'
import { ref } from 'vue'

const apiEndpoint = '/index.php/apps/openregister/api/endpoints'

type TEndpointItem = Record<string, unknown>

/**
 * Per save method: the URL it sends to, and the words its errors use.
 */
const SAVES = {
	POST: { url: () => apiEndpoint, verb: 'create', doing: 'creating' },
	PUT: {
		url: (item: TEndpointItem) => `${apiEndpoint}/${item.id}`,
		verb: 'update',
		doing: 'updating',
	},
}

export const useEndpointStore = defineStore('endpoint', () => {
	// state.
	const endpointItem = ref<TEndpointItem | null>(null)
	const endpointList = ref<TEndpointItem[]>([])
	const viewMode = ref('cards')

	// ################################
	// ||    Setters and Getters     ||
	// ################################

	/**
	 * Set the active endpoint item.
	 *
	 * @param item - The endpoint item to set
	 */
	const setEndpointItem = (item: TEndpointItem | null) => {
		endpointItem.value = item
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
	 * @return The active endpoint item
	 */
	const getEndpointItem = () => endpointItem.value

	/**
	 * Set the active endpoint list.
	 *
	 * @param list - The endpoint list to set
	 */
	const setEndpointList = (list: TEndpointItem[]) => {
		endpointList.value = list
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
	 * @return The active endpoint list
	 */
	const getEndpointList = () => endpointList.value

	// ################################
	// ||          Actions           ||
	// ################################

	/**
	 * Fetch the list of endpoints from the API
	 */
	const refreshEndpointList = () => {
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
	 * Send an endpoint to the API, then make the answer the active item and
	 * refresh the list.
	 *
	 * @param method - POST to create, PUT to update
	 * @param item - The endpoint item to send
	 * @return The saved endpoint
	 * @spec exclude Endpoint store save request shared by create and update: no standalone behavioural contract.
	 */
	const saveEndpoint = (method: keyof typeof SAVES, item: TEndpointItem) => {
		const save = SAVES[method]
		return fetch(save.url(item), {
			method,
			headers: {
				'Content-Type': 'application/json',
			},
			body: JSON.stringify(item),
		})
			.then((response) => {
				if (!response.ok) {
					throw new Error(`Failed to ${save.verb} endpoint`)
				}
				return response.json()
			})
			.then((data) => {
				setEndpointItem(data)
				refreshEndpointList()
				return data
			})
			.catch((err) => {
				console.error(`Error ${save.doing} endpoint:`, err)
				throw err
			})
	}

	/**
	 * Create a new endpoint on the API
	 *
	 * @param item - The endpoint item to create
	 * @spec exclude Endpoint store create action: no standalone behavioural contract.
	 */
	const createEndpoint = (item: TEndpointItem) => saveEndpoint('POST', item)

	/**
	 * Update an existing endpoint on the API
	 *
	 * @param item - The endpoint item to update
	 * @spec exclude Endpoint store update action: no standalone behavioural contract.
	 */
	const updateEndpoint = (item: TEndpointItem) => {
		if (!item.id) {
			throw new Error('Endpoint ID is required for update')
		}

		return saveEndpoint('PUT', item)
	}

	/**
	 * Delete an endpoint from the API
	 *
	 * @param item - The endpoint item to delete
	 */
	const deleteEndpoint = (item: TEndpointItem) => {
		if (!item.id) {
			throw new Error('Endpoint ID is required for deletion')
		}

		return fetch(`${apiEndpoint}/${item.id}`, {
			method: 'DELETE',
		})
			.then((response) => {
				if (!response.ok) {
					throw new Error('Failed to delete endpoint')
				}
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
	 *
	 * @param item - The endpoint item to test
	 * @param testData - Optional test data to send
	 */
	const testEndpoint = (item: TEndpointItem, testData = {}) => {
		if (!item.id) {
			throw new Error('Endpoint ID is required for testing')
		}

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
						throw new Error(
							data.error || data.message || 'Failed to test endpoint',
						)
					}
					return data
				})
			})
			.then((data) => {
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

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

/**
 * The endpoint store's state: the active item, the list and the view mode,
 * with their setters and getters.
 *
 * @return The state refs, setters and getters
 */
function useEndpointState() {
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

	return {
		endpointItem,
		endpointList,
		viewMode,
		setEndpointItem,
		getEndpointItem,
		setEndpointList,
		getEndpointList,
	}
}

type TEndpointState = ReturnType<typeof useEndpointState>

/**
 * The endpoint store's reads: the list refresh and the test run.
 *
 * @param state - The endpoint state
 * @return The read actions
 */
function useEndpointReads(state: TEndpointState) {
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
					state.setEndpointList(data.results)
				})
			})
			.catch((err) => {
				console.error('Error fetching endpoint list:', err)
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

	return { refreshEndpointList, testEndpoint }
}

/**
 * The endpoint store's writes: create, update and delete, each refreshing
 * the list afterwards.
 *
 * @param state - The endpoint state
 * @param refreshEndpointList - The list refresh
 * @return The write actions
 */
function useEndpointWrites(
	state: TEndpointState,
	refreshEndpointList: () => void,
) {
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
				state.setEndpointItem(data)
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
				if (state.endpointItem.value && state.endpointItem.value.id === item.id) {
					state.setEndpointItem(null)
				}
				refreshEndpointList()
			})
			.catch((err) => {
				console.error('Error deleting endpoint:', err)
				throw err
			})
	}

	return { createEndpoint, updateEndpoint, deleteEndpoint }
}

export const useEndpointStore = defineStore('endpoint', () => {
	const state = useEndpointState()
	const { refreshEndpointList, testEndpoint } = useEndpointReads(state)
	return {
		// State, getters and setters.
		...state,
		// Actions.
		refreshEndpointList,
		...useEndpointWrites(state, refreshEndpointList),
		testEndpoint,
	}
})

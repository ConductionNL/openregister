/* eslint-disable no-console */
import type { Ref } from 'vue'

import { defineStore } from 'pinia'
import { ref } from 'vue'

/**
 * The legacy search: one query string, its raw results and its error.
 *
 * @return The legacy state and actions
 */
function useLegacySearch() {
	/*
		I would rather not have this legacy code here.
        But other pages require this code as to not break and spam errors.
        So I will leave this here until I can refactor all the pages that use this.
	*/

	// LEGACY!!!
	const search = ref<any>('')
	const searchResults = ref<any>('')
	const searchError = ref<any>('')

	/**
	 *
	 * @param _search
	 */
	function setSearch(_search: string) {
		search.value = _search
	}
	/**
	 *
	 * @param _searchResults
	 */
	function setSearchResults(_searchResults: string) {
		searchResults.value = _searchResults
	}
	/* istanbul ignore next */ // ignore this for Jest until moved into a service
	/**
	 *
	 */
	function getSearchResults() {
		fetch('/index.php/apps/openregister/api/search?_search=' + search.value, {
			method: 'GET',
		})
			.then((response) => {
				response.json().then((data) => {
					if (data?.code === 403 && data?.message) {
						searchError.value = data.message
					} else {
						searchError.value = '' // Clear any previous errors
					}
					searchResults.value = data
				})
			})
			.catch((err) => {
				searchError.value = err.message || 'An error occurred'
				console.error(err.message ?? err)
			})
	}
	/**
	 *
	 */
	function clearSearch() {
		search.value = ''
		searchError.value = ''
	}
	// END OF LEGACY CODE

	return {
		search,
		searchResults,
		searchError,
		setSearch,
		setSearchResults,
		getSearchResults,
		clearSearch,
	}
}

/**
 * What the search page searches in: the register, the schema and the page.
 *
 * @return The selection refs
 */
function objectSearchSelection() {
	return {
		searchObjectsDataRegister: ref<{ label: string; id: string } | null>(null),
		searchObjectsDataSchema: ref<{ label: string; id: string } | null>(null),
		searchObjectsDataPagination: ref<number>(1),
		searchObjectsDataPaginationLimit: ref<number>(14),
	}
}

/**
 * The outcome of the search page's latest object search.
 *
 * @return The outcome refs
 */
function objectSearchState() {
	return {
		searchObjectsSuccess: ref(false),
		searchObjectsLoading: ref(false),
		searchObjectsResult: ref<Record<string, any>[]>([]),
		searchObjectsError: ref(''),
	}
}

/**
 * The refs an object search hands its caller, updated as the answer comes in.
 */
type TObjectSearchOutcome = {
	success: Ref<boolean>
	loading: Ref<boolean>
	result: Ref<Record<string, any>[]>
	error: Ref<string>
}

/**
 * The outcome refs under the names an object search returns them by.
 *
 * @param state - The object search outcome refs
 * @return The refs a caller watches
 */
function searchOutcome(state: ReturnType<typeof objectSearchState>): TObjectSearchOutcome {
	return {
		success: state.searchObjectsSuccess,
		loading: state.searchObjectsLoading,
		result: state.searchObjectsResult,
		error: state.searchObjectsError,
	}
}

/**
 * The search page's object search, with what it searches in and its outcome.
 *
 * @return The object search state and actions
 */
function useObjectSearch() {
	const selection = objectSearchSelection()
	const state = objectSearchState()
	const oldSearchQuery = ref<Record<string, any>>({})

	/**
	 * The objects URL for the selected register and schema, with the query.
	 *
	 * @param searchQuery - Key-value pairs of search parameters
	 * @return The URL to fetch
	 * @spec exclude Search page request builder: no standalone behavioural contract.
	 */
	function objectsEndpoint(searchQuery: Record<string, string>): string {
		const searchQueryString = new URLSearchParams(searchQuery).toString()
		const queryPart = searchQueryString ? `?${searchQueryString}` : ''
		const register = selection.searchObjectsDataRegister.value?.id || ''
		const schema = selection.searchObjectsDataSchema.value?.id || ''
		return `/index.php/apps/openregister/api/objects/${register}/${schema}${queryPart}`
	}

	/**
	 * Search for objects in the database.
	 * This function returns refs immediately while updating them asynchronously as the search results come in.
	 *
	 * @param searchQuery - Key-value pairs of search parameters
	 * @return Object containing refs that will be updated with search results
	 * @spec exclude Search page object fetch that only fills the search refs: no standalone behavioural contract.
	 */
	function searchObjects(
		searchQuery: Record<string, string> = {},
	): TObjectSearchOutcome {
		oldSearchQuery.value = searchQuery

		console.group('search objects')

		console.group('Fetching search results with params:')
		console.groupEnd()

		state.searchObjectsLoading.value = true

		fetch(objectsEndpoint(searchQuery), { method: 'GET' })
			.then(async (response) => {
				// Clear any previous errors
				state.searchObjectsError.value = ''

				const data = await response.json()

				if (!response.ok && response.statusText) {
					state.searchObjectsError.value = response.statusText
					console.error(state.searchObjectsError.value)
					return
				}

				state.searchObjectsSuccess.value = true
				state.searchObjectsResult.value = data
			})
			.catch((error) => {
				console.error('Error fetching search results:', error)
				state.searchObjectsSuccess.value = false
				state.searchObjectsError.value = error
			})
			.finally(() => {
				state.searchObjectsLoading.value = false
				console.groupEnd()
			})

		return searchOutcome(state)
	}

	/**
	 *
	 */
	function reDoSearch() {
		return searchObjects({
			...oldSearchQuery.value,
		})
	}

	/**
	 *
	 */
	function clearObjectSearchResults() {
		state.searchObjectsSuccess.value = false
		state.searchObjectsLoading.value = false
		state.searchObjectsResult.value = []
		state.searchObjectsError.value = ''
	}

	return {
		...state,
		...selection,
		searchObjects,
		reDoSearch,
		clearObjectSearchResults,
	}
}

export const useSearchStore = defineStore('search', () => ({
	...useLegacySearch(),
	...useObjectSearch(),
}))

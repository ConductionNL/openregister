/* eslint-disable no-console */
import type { Ref } from 'vue'

import { defineStore } from 'pinia'
import { ref } from 'vue'

export const useSearchStore = defineStore('search', () => {
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

	// new, used by search page
	// search data
	const searchObjectsDataRegister = ref<{ label: string; id: string } | null>(null)
	const searchObjectsDataSchema = ref<{ label: string; id: string } | null>(null)
	const searchObjectsDataPagination = ref<number>(1)
	const searchObjectsDataPaginationLimit = ref<number>(14)

	// search objects
	const searchObjectsSuccess = ref(false)
	const searchObjectsLoading = ref(false)
	const searchObjectsResult = ref<Record<string, any>[]>([])
	const searchObjectsError = ref('')

	const oldSearchQuery = ref<Record<string, any>>({})

	/**
	 * Search for objects in the database.
	 * This function returns refs immediately while updating them asynchronously as the search results come in.
	 *
	 * @param searchQuery - Key-value pairs of search parameters
	 * @return Object containing refs that will be updated with search results
	 */
	function searchObjects(searchQuery: Record<string, string> = {}): {
		success: Ref<boolean>
		loading: Ref<boolean>
		result: Ref<Record<string, any>[]>
		error: Ref<string>
	} {
		const searchQueryString = new URLSearchParams(searchQuery).toString()
		const queryPart = searchQueryString ? `?${searchQueryString}` : ''

		oldSearchQuery.value = searchQuery

		console.group('search objects')

		console.group('Fetching search results with params:')
		console.groupEnd()

		searchObjectsLoading.value = true

		const register = searchObjectsDataRegister.value?.id || ''
		const schema = searchObjectsDataSchema.value?.id || ''
		const endpoint = `/index.php/apps/openregister/api/objects/${register}/${schema}${queryPart}`

		fetch(endpoint, { method: 'GET' })
			.then(async (response) => {
				// Clear any previous errors
				searchObjectsError.value = ''

				const data = await response.json()

				if (!response.ok && response.statusText) {
					searchObjectsError.value = response.statusText
					console.error(searchObjectsError.value)
					return
				}

				searchObjectsSuccess.value = true
				searchObjectsResult.value = data
			})
			.catch((error) => {
				console.error('Error fetching search results:', error)
				searchObjectsSuccess.value = false
				searchObjectsError.value = error
			})
			.finally(() => {
				searchObjectsLoading.value = false
				console.groupEnd()
			})

		return {
			success: searchObjectsSuccess,
			loading: searchObjectsLoading,
			result: searchObjectsResult,
			error: searchObjectsError,
		}
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
		searchObjectsSuccess.value = false
		searchObjectsLoading.value = false
		searchObjectsResult.value = []
		searchObjectsError.value = ''
	}

	return {
		// LEGACY
		// state
		search,
		searchResults,
		searchError,

		// functions
		setSearch,
		setSearchResults,
		getSearchResults,
		clearSearch,

		// NEW
		// state
		searchObjectsSuccess,
		searchObjectsLoading,
		searchObjectsResult,
		searchObjectsError,

		searchObjectsDataRegister,
		searchObjectsDataSchema,
		searchObjectsDataPagination,
		searchObjectsDataPaginationLimit,

		// functions
		searchObjects,
		reDoSearch,
		clearObjectSearchResults,
	}
})

/* eslint-disable no-console */
import { Ref, ref } from 'vue'
import { defineStore } from 'pinia'

export const useSearchStore = defineStore('search', () => {
	/*
		I would rather not have this legacy code here.
        But other pages require this code as to not break and spam errors.
        So I will leave this here until I can refactor all the pages that use this.
	*/

	// LEGACY!!!
	const search = ref('')
	const searchResults = ref('')
	const searchError = ref('')

	function setSearch(search: string) {
		this.search = search
		console.info('Active search set to ' + search)
	}
	function setSearchResults(searchResults: string) {
		this.searchResults = searchResults
		console.info('Active search set to ' + searchResults)
	}
	/* istanbul ignore next */ // ignore this for Jest until moved into a service
	function getSearchResults() {
		fetch(
			'/index.php/apps/openregister/api/search?_search=' + this.search,
			{
				method: 'GET',
			},
		)
			.then(
				(response) => {
					response.json().then(
						(data) => {
							if (data?.code === 403 && data?.message) {
								this.searchError = data.message
								console.info(this.searchError)
							} else {
								this.searchError = '' // Clear any previous errors
							}
							this.searchResults = data
						},
					)
				},
			)
			.catch(
				(err) => {
					this.searchError = err.message || 'An error occurred'
					console.error(err.message ?? err)
				},
			)
	}
	function clearSearch() {
		search.value = ''
		searchError.value = ''
	}
	// END OF LEGACY CODE

	// new, used by search page
	const searchObjectsSuccess = ref(false)
	const searchObjectsLoading = ref(false)
	const searchObjectsResult = ref<Record<string, any>[]>([])
	const searchObjectsError = ref('')

	/**
	 * Search for objects in the database.
	 * This function returns refs immediately while updating them asynchronously as the search results come in.
	 *
	 * @param {Record<string, string>} searchQuery - Key-value pairs of search parameters
	 * @return {object} Object containing refs that will be updated with search results
	 */
	function searchObjects(searchQuery: Record<string, string> = {}): {success: Ref<boolean>, loading: Ref<boolean>, result: Ref<Record<string, any>[]>, error: Ref<string>} {
		const searchQueryString = new URLSearchParams(searchQuery).toString()
		const queryPart = searchQueryString ? `?${searchQueryString}` : ''

		console.group('search objects')

		console.info('clearing old result')
		searchObjectsResult.value = []

		console.group('Fetching search results with params:')
		Object.entries(searchQuery).forEach(([key, value]) => {
			console.info(`${key}: ${value}`)
		})
		console.groupEnd()

		searchObjectsLoading.value = true

		fetch(`/index.php/apps/openregister/api/objects${queryPart}`, { method: 'GET' })
			.then(async response => {
				console.info('Search results fetched')

				// Clear any previous errors
				searchObjectsError.value = ''

				const data = (await response.json()).results

				console.info(`${data.length} objects found`)

				if (!response.ok && response.statusText) {
					searchObjectsError.value = response.statusText
					console.error(searchObjectsError.value)
					return
				}

				searchObjectsSuccess.value = true
				searchObjectsResult.value = data
			})
			.catch(error => {
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

	function clearObjectSearch() {
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

		// functions
		searchObjects,
		clearObjectSearch,
	}
})

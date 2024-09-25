/* eslint-disable no-console */
import { defineStore } from 'pinia'

export const useSearchStore = defineStore(
	'search', {
		state: () => ({
			search: '',
			searchResults: '',
			searchError: '',
		}),
		actions: {
			setSearch(search) {
				this.search = search
				console.log('Active search set to ' + search)
			},
			setSearchResults(searchResults) {
				this.searchResults = searchResults
				console.log('Active search set to ' + searchResults)
			},
			/* istanbul ignore next */ // ignore this for Jest until moved into a service
			getSearchResults() {
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
										console.log(this.searchError)
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
			},
			clearSearch() {
				this.search = ''
				this.searchError = ''
			},
		},
	},
)

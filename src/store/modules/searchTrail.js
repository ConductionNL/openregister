/**
 * @spec openspec/specs/zoeken-filteren/spec.md#requirement-saved-searches-and-search-trails
 */
import { defineStore } from 'pinia'
import {
	copiedResults,
	getRequest,
	listPageQuery,
	listPagination,
	readOrFallback,
	storeRequest,
} from '../../services/listPage.js'
import { statisticsGetter } from './trailStatistics.js'

const apiUrl = '/index.php/apps/openregister/api'

/**
 * The statistics with every count at 0.
 *
 * @return {object} Every count at 0.
 * @spec exclude Client-side empty state of the statistics; no backend contract.
 */
function emptyStatistics() {
	return {
		total: 0,
		totalResults: 0,
		averageResultsPerSearch: 0,
		averageExecutionTime: 0,
		successRate: 0,
		uniqueSearchTerms: 0,
		uniqueUsers: 0,
		uniqueOrganizations: 0,
		queryComplexity: emptyQueryComplexity(),
	}
}

/**
 * The query complexity counts, all 0.
 *
 * @return {{simple: number, medium: number, complex: number}} The counts.
 * @spec exclude Client-side empty state of the statistics; no backend contract.
 */
function emptyQueryComplexity() {
	return { simple: 0, medium: 0, complex: 0 }
}

/**
 * A reader of one count of the statistics answer; a missing count is 0.
 *
 * @param {string} field The answer field.
 * @return {function(object): number} The reader.
 * @spec openspec/specs/zoeken-filteren/spec.md#requirement-saved-searches-and-search-trails
 */
function count(field) {
	return (stats) => stats[field] || 0
}

// The store's statistics keys, in order, with how each is read from the answer.
const STATISTIC_FIELDS = [
	['total', count('total_searches')],
	['totalResults', count('total_results')],
	['averageResultsPerSearch', count('avg_results_per_search')],
	['averageExecutionTime', count('avg_response_time')],
	['successRate', (stats) => (stats.success_rate ? stats.success_rate / 100 : 0)],
	['uniqueSearchTerms', count('unique_search_terms')],
	['uniqueUsers', count('unique_users')],
	['uniqueOrganizations', count('unique_organizations')],
	['avgSearchesPerSession', count('avg_searches_per_session')],
	['avgObjectViewsPerSession', count('avg_object_views_per_session')],
	[
		'queryComplexity',
		(stats) => stats.query_complexity || { simple: 0, medium: 0, complex: 0 },
	],
]

/**
 * Store for managing search trail logs
 * Provides functionality for retrieving, filtering, and managing search trail entries
 */
/**
 * The search trail store's statistics, popular terms, activity and usage
 * figures, before anything loads.
 *
 * @return {object} The state.
 * @spec openspec/specs/zoeken-filteren/spec.md#requirement-saved-searches-and-search-trails
 */
function searchTrailInsightsState() {
	return {
		statistics: emptyStatistics(),
		popularTerms: [],
		activity: { hourly: [], daily: [], weekly: [], monthly: [] },
		registerSchemaStats: [],
		userAgentStats: [],
	}
}

export const useSearchTrailStore = defineStore('searchTrail', {
	state: () => ({
		// Loading states
		searchTrailLoading: false,
		statisticsLoading: false,
		popularTermsLoading: false,
		activityLoading: false,

		// Data
		searchTrailList: [],
		searchTrailItem: null,
		searchTrailPagination: listPagination({}, 50),
		...searchTrailInsightsState(),

		// Filters
		searchTrailFilters: {},
		searchTrailSearch: '',
	}),

	actions: {
		...statisticsGetter(emptyStatistics),
		/**
		 * Set search trail list
		 *
		 * @param {Array} searchTrailList - The search trail list to set
		 *
		 * @spec openspec/specs/zoeken-filteren/spec.md#requirement-saved-searches-and-search-trails
		 */
		setSearchTrailList(searchTrailList) {
			// Ensure we have a clean array without reactive references
			this.searchTrailList = Array.isArray(searchTrailList)
				? [...searchTrailList]
				: []
		},

		/**
		 * Set search trail item
		 *
		 * @param {object} searchTrailItem - The search trail item to set
		 *
		 * @spec openspec/specs/zoeken-filteren/spec.md#requirement-saved-searches-and-search-trails
		 */
		setSearchTrailItem(searchTrailItem) {
			this.searchTrailItem = searchTrailItem
		},

		/**
		 * Set search trail pagination
		 *
		 * @param {object} pagination - The pagination object
		 *
		 * @spec openspec/specs/zoeken-filteren/spec.md#requirement-saved-searches-and-search-trails
		 */
		setSearchTrailPagination(pagination) {
			this.searchTrailPagination = {
				...this.searchTrailPagination,
				...pagination,
			}
		},

		/**
		 * Set statistics
		 *
		 * @param {object} stats - The statistics object
		 *
		 * @spec openspec/specs/zoeken-filteren/spec.md#requirement-saved-searches-and-search-trails
		 */
		setStatistics(stats) {
			this.statistics = Object.fromEntries(
				STATISTIC_FIELDS.map(([key, read]) => [key, read(stats)]),
			)
		},

		/**
		 * Set popular terms
		 *
		 * @param {object} response - The popular terms response object
		 *
		 * @spec openspec/specs/zoeken-filteren/spec.md#requirement-saved-searches-and-search-trails
		 */
		setPopularTerms(response) {
			// Handle response structure from API
			const terms = response?.results || response?.terms || response
			this.popularTerms = Array.isArray(terms) ? [...terms] : []
		},

		/**
		 * Set activity data
		 *
		 * @param {object} activityResponse - The activity data response
		 *
		 * @spec openspec/specs/zoeken-filteren/spec.md#requirement-saved-searches-and-search-trails
		 */
		setActivity(activityResponse) {
			// Handle response structure from API
			Object.entries(activityResponse).forEach(([period, response]) => {
				// Extract activity data from the response
				// The response can be structured as {activity: [array]} or directly as an array
				const activityData = response?.activity || response
				if (Array.isArray(activityData)) {
					// Map the activity data to match expected format
					this.activity[period] = activityData.map((item) => ({
						period: item.period,
						searches: item.count || item.searches || 0,
						avgResults: item.avg_results || 0,
						avgResponseTime: item.avg_response_time || 0,
					}))
				} else {
					this.activity[period] = []
				}
			})
		},

		/**
		 * Set register schema statistics
		 *
		 * @param {object} response - The register schema statistics response
		 *
		 * @spec openspec/specs/zoeken-filteren/spec.md#requirement-saved-searches-and-search-trails
		 */
		setRegisterSchemaStats(response) {
			// Handle response structure from API
			const stats = response?.results || response?.statistics || response
			this.registerSchemaStats = Array.isArray(stats) ? [...stats] : []
		},

		/**
		 * Set user agent statistics
		 *
		 * @param {object} response - The user agent statistics response
		 *
		 * @spec openspec/specs/zoeken-filteren/spec.md#requirement-saved-searches-and-search-trails
		 */
		setUserAgentStats(response) {
			// Handle response structure from API
			const stats = response?.results || response?.user_agents || response
			this.userAgentStats = Array.isArray(stats) ? [...stats] : []
		},

		/**
		 * Set search trail filters
		 *
		 * @param {object} filters - The filters to set
		 *
		 * @spec openspec/specs/zoeken-filteren/spec.md#requirement-saved-searches-and-search-trails
		 */
		setSearchTrailFilters(filters) {
			this.searchTrailFilters = filters
		},

		/**
		 * Set search trail search
		 *
		 * @param {string} search - The search term
		 *
		 * @spec openspec/specs/zoeken-filteren/spec.md#requirement-saved-searches-and-search-trails
		 */
		setSearchTrailSearch(search) {
			this.searchTrailSearch = search
		},

		/**
		 * Fetch search trails with optional filtering and pagination
		 *
		 * @param {object} options - Options for fetching
		 * @return {Promise<object>} The fetched data
		 *
		 * @spec openspec/specs/zoeken-filteren/spec.md#requirement-saved-searches-and-search-trails
		 */
		async fetchSearchTrails(options = {}) {
			const query = listPageQuery(options, {
				search: this.searchTrailSearch,
				filters: this.searchTrailFilters,
			})
			return storeRequest(this, {
				flag: 'searchTrailLoading',
				url: `${apiUrl}/search-trails?${query}`,
				request: getRequest('Failed to fetch search trails'),
				log: (error) =>
					console.error('Error fetching search trails:', error),
				keep: (data) => {
					this.setSearchTrailList(copiedResults(data))
					this.setSearchTrailPagination(listPagination(data, 50))
				},
			})
		},

		/**
		 * Fetch search trail statistics
		 *
		 * @param {object} options - Optional { from, to } ISO date filters
		 * @return {Promise<object>} The statistics data
		 *
		 * @spec openspec/specs/zoeken-filteren/spec.md#requirement-saved-searches-and-search-trails
		 */
		async fetchStatistics(options = {}) {
			const query = new URLSearchParams()
			if (options.from) query.append('from', options.from)
			if (options.to) query.append('to', options.to)
			const suffix = query.toString() ? `?${query.toString()}` : ''
			return storeRequest(this, {
				flag: 'statisticsLoading',
				url: `${apiUrl}/search-trails/statistics${suffix}`,
				request: { method: 'GET', failure: 'Failed to fetch statistics' },
				log: (error) =>
					console.error('Error fetching search trail statistics:', error),
				keep: (data) => this.setStatistics(data),
			})
		},

		/**
		 * Fetch popular search terms
		 *
		 * @param {number} limit - Number of terms to fetch
		 * @return {Promise<Array>} The popular terms data
		 *
		 * @spec openspec/specs/zoeken-filteren/spec.md#requirement-saved-searches-and-search-trails
		 */
		async fetchPopularTerms(limit = 10) {
			return storeRequest(this, {
				flag: 'popularTermsLoading',
				url: `${apiUrl}/search-trails/popular-terms?limit=${limit}`,
				request: { method: 'GET', failure: 'Failed to fetch popular terms' },
				log: (error) =>
					console.error('Error fetching popular terms:', error),
				keep: (data) => this.setPopularTerms(data),
			})
		},

		/**
		 * Fetch search activity data
		 *
		 * @param {string} period - The period to fetch (hourly, daily, weekly, monthly)
		 * @return {Promise<Array>} The activity data
		 *
		 * @spec openspec/specs/zoeken-filteren/spec.md#requirement-saved-searches-and-search-trails
		 */
		async fetchActivity(period = 'daily') {
			return storeRequest(this, {
				flag: 'activityLoading',
				url: `${apiUrl}/search-trails/activity?interval=${period}`,
				request: { method: 'GET', failure: 'Failed to fetch activity data' },
				log: (error) =>
					console.error('Error fetching activity data:', error),
				keep: (data) => this.setActivity({ [period]: data }),
			})
		},

		/**
		 * Fetch register schema statistics
		 *
		 * @return {Promise<Array>} The register schema statistics
		 *
		 * @spec openspec/specs/zoeken-filteren/spec.md#requirement-saved-searches-and-search-trails
		 */
		async fetchRegisterSchemaStats() {
			return storeRequest(this, {
				url: `${apiUrl}/search-trails/register-schema-stats`,
				request: {
					method: 'GET',
					failure: 'Failed to fetch register schema statistics',
				},
				log: (error) =>
					console.error(
						'Error fetching register schema statistics:',
						error,
					),
				keep: (data) => this.setRegisterSchemaStats(data),
			})
		},

		/**
		 * Fetch user agent statistics
		 *
		 * @return {Promise<Array>} The user agent statistics
		 *
		 * @spec openspec/specs/zoeken-filteren/spec.md#requirement-saved-searches-and-search-trails
		 */
		async fetchUserAgentStats() {
			return storeRequest(this, {
				url: `${apiUrl}/search-trails/user-agent-stats`,
				request: {
					method: 'GET',
					failure: 'Failed to fetch user agent statistics',
				},
				log: (error) =>
					console.error('Error fetching user agent statistics:', error),
				keep: (data) => this.setUserAgentStats(data),
			})
		},

		/**
		 * Delete search trail logs older than specified days
		 *
		 * @param {number} days - Number of days to keep
		 * @return {Promise<object>} The response data
		 *
		 * @spec openspec/specs/zoeken-filteren/spec.md#requirement-saved-searches-and-search-trails
		 */
		async cleanupSearchTrails(days = 30) {
			return storeRequest(this, {
				url: `${apiUrl}/search-trails/cleanup`,
				request: {
					method: 'POST',
					body: { days },
					failure: 'Failed to cleanup search trails',
				},
				log: (error) =>
					console.error('Error cleaning up search trails:', error),
			})
		},

		/**
		 * Refresh search trail list with current filters
		 *
		 * @return {Promise} The refresh promise
		 *
		 * @spec openspec/specs/zoeken-filteren/spec.md#requirement-saved-searches-and-search-trails
		 */
		async refreshSearchTrailList() {
			return this.fetchSearchTrails({
				limit: this.searchTrailPagination.limit,
				page: this.searchTrailPagination.page,
			})
		},

		/**
		 * Get popular search terms
		 *
		 * @param {number} limit - Number of terms to get
		 * @return {Promise<Array>} The popular terms
		 *
		 * @spec openspec/specs/zoeken-filteren/spec.md#requirement-saved-searches-and-search-trails
		 */
		async getPopularTerms(limit = 10) {
			return readOrFallback(
				async () => {
					await this.fetchPopularTerms(limit)
					return this.popularTerms
				},
				{
					log: (error) =>
						console.error('Error getting popular terms:', error),
					fallback: () => [],
				},
			)
		},

		/**
		 * Get search activity data
		 *
		 * @param {string} period - The period to get data for
		 * @return {Promise<Array>} The activity data
		 *
		 * @spec openspec/specs/zoeken-filteren/spec.md#requirement-saved-searches-and-search-trails
		 */
		async getActivity(period = 'daily') {
			return readOrFallback(
				async () => {
					await this.fetchActivity(period)
					return this.activity[period] || []
				},
				{
					log: (error) =>
						console.error('Error getting activity data:', error),
					fallback: () => [],
				},
			)
		},

		/**
		 * Get register schema usage statistics
		 *
		 * @return {Promise<Array>} The register schema statistics
		 *
		 * @spec openspec/specs/zoeken-filteren/spec.md#requirement-saved-searches-and-search-trails
		 */
		async getRegisterSchemaStats() {
			return readOrFallback(
				async () => {
					await this.fetchRegisterSchemaStats()
					return this.registerSchemaStats
				},
				{
					log: (error) =>
						console.error(
							'Error getting register schema statistics:',
							error,
						),
					fallback: () => [],
				},
			)
		},

		/**
		 * Get user agent statistics
		 *
		 * @return {Promise<Array>} The user agent statistics
		 *
		 * @spec openspec/specs/zoeken-filteren/spec.md#requirement-saved-searches-and-search-trails
		 */
		async getUserAgentStats() {
			return readOrFallback(
				async () => {
					await this.fetchUserAgentStats()
					return this.userAgentStats
				},
				{
					log: (error) =>
						console.error('Error getting user agent statistics:', error),
					fallback: () => [],
				},
			)
		},

		/**
		 * Clear all search trail store data
		 *
		 * @spec openspec/specs/zoeken-filteren/spec.md#requirement-saved-searches-and-search-trails
		 */
		clearSearchTrailStore() {
			this.searchTrailList = []
			this.searchTrailItem = null
			this.searchTrailPagination = listPagination({}, 50)
			Object.assign(this, searchTrailInsightsState())
			this.searchTrailFilters = {}
			this.searchTrailSearch = ''
		},
	},
})

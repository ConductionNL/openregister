/**
 * @spec openspec/changes/retrofit-2026-04-23-annotate-openregister/tasks.md#task-89
 */
import { defineStore } from 'pinia'

const apiUrl = '/index.php/apps/openregister/api'

/**
 * Store for managing search trail logs
 * Provides functionality for retrieving, filtering, and managing search trail entries
 */
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

		// Pagination
		searchTrailPagination: {
			total: 0,
			page: 1,
			pages: 1,
			limit: 50,
			offset: 0,
		},

		// Statistics
		statistics: {
			total: 0,
			totalResults: 0,
			averageResultsPerSearch: 0,
			averageExecutionTime: 0,
			successRate: 0,
			uniqueSearchTerms: 0,
			uniqueUsers: 0,
			uniqueOrganizations: 0,
			queryComplexity: {
				simple: 0,
				medium: 0,
				complex: 0,
			},
		},

		// Popular terms
		popularTerms: [],

		// Activity data
		activity: {
			hourly: [],
			daily: [],
			weekly: [],
			monthly: [],
		},

		// Register schema statistics
		registerSchemaStats: [],

		// User agent statistics
		userAgentStats: [],

		// Filters
		searchTrailFilters: {},
		searchTrailSearch: '',
	}),

	actions: {
		/**
		 * Set search trail list
		 * @param {Array} searchTrailList - The search trail list to set
		 *
		 * @spec openspec/changes/retrofit-2026-04-23-annotate-openregister/tasks.md#task-89
		 */
		setSearchTrailList(searchTrailList) {
			// Ensure we have a clean array without reactive references
			this.searchTrailList = Array.isArray(searchTrailList) ? [...searchTrailList] : []
		},

		/**
		 * Set search trail item
		 * @param {object} searchTrailItem - The search trail item to set
		 *
		 * @spec openspec/changes/retrofit-2026-04-23-annotate-openregister/tasks.md#task-89
		 */
		setSearchTrailItem(searchTrailItem) {
			this.searchTrailItem = searchTrailItem
		},

		/**
		 * Set search trail pagination
		 * @param {object} pagination - The pagination object
		 *
		 * @spec openspec/changes/retrofit-2026-04-23-annotate-openregister/tasks.md#task-89
		 */
		setSearchTrailPagination(pagination) {
			this.searchTrailPagination = {
				...this.searchTrailPagination,
				...pagination,
			}
		},

		/**
		 * Set statistics
		 * @param {object} stats - The statistics object
		 *
		 * @spec openspec/changes/retrofit-2026-04-23-annotate-openregister/tasks.md#task-89
		 */
		setStatistics(stats) {
			this.statistics = {
				total: stats.total_searches || 0,
				totalResults: stats.total_results || 0,
				averageResultsPerSearch: stats.avg_results_per_search || 0,
				averageExecutionTime: stats.avg_response_time || 0,
				successRate: stats.success_rate ? (stats.success_rate / 100) : 0,
				uniqueSearchTerms: stats.unique_search_terms || 0,
				uniqueUsers: stats.unique_users || 0,
				uniqueOrganizations: stats.unique_organizations || 0,
				avgSearchesPerSession: stats.avg_searches_per_session || 0,
				avgObjectViewsPerSession: stats.avg_object_views_per_session || 0,
				queryComplexity: stats.query_complexity || {
					simple: 0,
					medium: 0,
					complex: 0,
				},
			}
		},

		/**
		 * Set popular terms
		 * @param {object} response - The popular terms response object
		 *
		 * @spec openspec/changes/retrofit-2026-04-23-annotate-openregister/tasks.md#task-89
		 */
		setPopularTerms(response) {
			// Handle response structure from API
			const terms = response?.results || response?.terms || response
			this.popularTerms = Array.isArray(terms) ? [...terms] : []
		},

		/**
		 * Set activity data
		 * @param {object} activityResponse - The activity data response
		 *
		 * @spec openspec/changes/retrofit-2026-04-23-annotate-openregister/tasks.md#task-89
		 */
		setActivity(activityResponse) {
			// Handle response structure from API
			Object.entries(activityResponse).forEach(([period, response]) => {
				// Extract activity data from the response
				// The response can be structured as {activity: [array]} or directly as an array
				const activityData = response?.activity || response
				if (Array.isArray(activityData)) {
					// Map the activity data to match expected format
					this.activity[period] = activityData.map(item => ({
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
		 * @param {object} response - The register schema statistics response
		 *
		 * @spec openspec/changes/retrofit-2026-04-23-annotate-openregister/tasks.md#task-89
		 */
		setRegisterSchemaStats(response) {
			// Handle response structure from API
			const stats = response?.results || response?.statistics || response
			this.registerSchemaStats = Array.isArray(stats) ? [...stats] : []
		},

		/**
		 * Set user agent statistics
		 * @param {object} response - The user agent statistics response
		 *
		 * @spec openspec/changes/retrofit-2026-04-23-annotate-openregister/tasks.md#task-89
		 */
		setUserAgentStats(response) {
			// Handle response structure from API
			const stats = response?.results || response?.user_agents || response
			this.userAgentStats = Array.isArray(stats) ? [...stats] : []
		},

		/**
		 * Set search trail filters
		 * @param {object} filters - The filters to set
		 *
		 * @spec openspec/changes/retrofit-2026-04-23-annotate-openregister/tasks.md#task-89
		 */
		setSearchTrailFilters(filters) {
			this.searchTrailFilters = filters
		},

		/**
		 * Set search trail search
		 * @param {string} search - The search term
		 *
		 * @spec openspec/changes/retrofit-2026-04-23-annotate-openregister/tasks.md#task-89
		 */
		setSearchTrailSearch(search) {
			this.searchTrailSearch = search
		},

		/**
		 * Fetch search trails with optional filtering and pagination
		 * @param {object} options - Options for fetching
		 * @return {Promise<object>} The fetched data
		 *
		 * @spec openspec/changes/retrofit-2026-04-23-annotate-openregister/tasks.md#task-89
		 */
		async fetchSearchTrails(options = {}) {
			this.searchTrailLoading = true

			try {
				// Build query parameters
				const params = new URLSearchParams()

				// Add pagination
				if (options.limit) params.append('limit', options.limit)
				if (options.offset) params.append('offset', options.offset)
				if (options.page) params.append('page', options.page)

				// Add search
				if (options.search || this.searchTrailSearch) {
					params.append('search', options.search || this.searchTrailSearch)
				}

				// Add filters
				const filters = { ...this.searchTrailFilters, ...options.filters }
				Object.entries(filters).forEach(([key, value]) => {
					if (value !== null && value !== undefined && value !== '') {
						params.append(key, value)
					}
				})

				// Add sort
				if (options.sort) {
					Object.entries(options.sort).forEach(([field, direction]) => {
						params.append('sort', field)
						params.append('order', direction)
					})
				}

				const url = `${apiUrl}/search-trails?${params.toString()}`

				const response = await fetch(url, {
					method: 'GET',
					headers: {
						'Content-Type': 'application/json',
						requesttoken: OC.requestToken,
					},
				})

				const data = await response.json()

				if (!response.ok) {
					throw new Error(data.error || 'Failed to fetch search trails')
				}

				// Update store state - ensure we pass clean data
				this.setSearchTrailList(data.results ? JSON.parse(JSON.stringify(data.results)) : [])
				this.setSearchTrailPagination({
					total: data.total || 0,
					page: data.page || 1,
					pages: data.pages || 1,
					limit: data.limit || 50,
					offset: data.offset || 0,
				})

				return data
			} catch (error) {
				console.error('Error fetching search trails:', error)
				throw error
			} finally {
				this.searchTrailLoading = false
			}
		},

		/**
		 * Fetch search trail statistics
		 * @param {object} options - Optional { from, to } ISO date filters
		 * @return {Promise<object>} The statistics data
		 *
		 * @spec openspec/changes/retrofit-2026-04-23-annotate-openregister/tasks.md#task-89
		 */
		async fetchStatistics(options = {}) {
			this.statisticsLoading = true

			try {
				const query = new URLSearchParams()
				if (options.from) query.append('from', options.from)
				if (options.to) query.append('to', options.to)
				const suffix = query.toString() ? `?${query.toString()}` : ''
				const response = await fetch(`${apiUrl}/search-trails/statistics${suffix}`, {
					method: 'GET',
					headers: {
						'Content-Type': 'application/json',
						requesttoken: OC.requestToken,
					},
				})

				const data = await response.json()

				if (!response.ok) {
					throw new Error(data.error || 'Failed to fetch statistics')
				}

				this.setStatistics(data)
				return data
			} catch (error) {
				console.error('Error fetching search trail statistics:', error)
				throw error
			} finally {
				this.statisticsLoading = false
			}
		},

		/**
		 * Fetch popular search terms
		 * @param {number} limit - Number of terms to fetch
		 * @return {Promise<Array>} The popular terms data
		 *
		 * @spec openspec/changes/retrofit-2026-04-23-annotate-openregister/tasks.md#task-89
		 */
		async fetchPopularTerms(limit = 10) {
			this.popularTermsLoading = true

			try {
				const response = await fetch(`${apiUrl}/search-trails/popular-terms?limit=${limit}`, {
					method: 'GET',
					headers: {
						'Content-Type': 'application/json',
						requesttoken: OC.requestToken,
					},
				})

				const data = await response.json()

				if (!response.ok) {
					throw new Error(data.error || 'Failed to fetch popular terms')
				}

				this.setPopularTerms(data)
				return data
			} catch (error) {
				console.error('Error fetching popular terms:', error)
				throw error
			} finally {
				this.popularTermsLoading = false
			}
		},

		/**
		 * Fetch search activity data
		 * @param {string} period - The period to fetch (hourly, daily, weekly, monthly)
		 * @return {Promise<Array>} The activity data
		 *
		 * @spec openspec/changes/retrofit-2026-04-23-annotate-openregister/tasks.md#task-89
		 */
		async fetchActivity(period = 'daily') {
			this.activityLoading = true

			try {
				const response = await fetch(`${apiUrl}/search-trails/activity?interval=${period}`, {
					method: 'GET',
					headers: {
						'Content-Type': 'application/json',
						requesttoken: OC.requestToken,
					},
				})

				const data = await response.json()

				if (!response.ok) {
					throw new Error(data.error || 'Failed to fetch activity data')
				}

				this.setActivity({ [period]: data })
				return data
			} catch (error) {
				console.error('Error fetching activity data:', error)
				throw error
			} finally {
				this.activityLoading = false
			}
		},

		/**
		 * Fetch register schema statistics
		 * @return {Promise<Array>} The register schema statistics
		 *
		 * @spec openspec/changes/retrofit-2026-04-23-annotate-openregister/tasks.md#task-89
		 */
		async fetchRegisterSchemaStats() {
			try {
				const response = await fetch(`${apiUrl}/search-trails/register-schema-stats`, {
					method: 'GET',
					headers: {
						'Content-Type': 'application/json',
						requesttoken: OC.requestToken,
					},
				})

				const data = await response.json()

				if (!response.ok) {
					throw new Error(data.error || 'Failed to fetch register schema statistics')
				}

				this.setRegisterSchemaStats(data)
				return data
			} catch (error) {
				console.error('Error fetching register schema statistics:', error)
				throw error
			}
		},

		/**
		 * Fetch user agent statistics
		 * @return {Promise<Array>} The user agent statistics
		 *
		 * @spec openspec/changes/retrofit-2026-04-23-annotate-openregister/tasks.md#task-89
		 */
		async fetchUserAgentStats() {
			try {
				const response = await fetch(`${apiUrl}/search-trails/user-agent-stats`, {
					method: 'GET',
					headers: {
						'Content-Type': 'application/json',
						requesttoken: OC.requestToken,
					},
				})

				const data = await response.json()

				if (!response.ok) {
					throw new Error(data.error || 'Failed to fetch user agent statistics')
				}

				this.setUserAgentStats(data)
				return data
			} catch (error) {
				console.error('Error fetching user agent statistics:', error)
				throw error
			}
		},

		/**
		 * Delete search trail logs older than specified days
		 * @param {number} days - Number of days to keep
		 * @return {Promise<object>} The response data
		 *
		 * @spec openspec/changes/retrofit-2026-04-23-annotate-openregister/tasks.md#task-89
		 */
		async cleanupSearchTrails(days = 30) {
			try {
				const response = await fetch(`${apiUrl}/search-trails/cleanup`, {
					method: 'POST',
					headers: {
						'Content-Type': 'application/json',
						requesttoken: OC.requestToken,
					},
					body: JSON.stringify({ days }),
				})

				const data = await response.json()

				if (!response.ok) {
					throw new Error(data.error || 'Failed to cleanup search trails')
				}

				return data
			} catch (error) {
				console.error('Error cleaning up search trails:', error)
				throw error
			}
		},

		/**
		 * Refresh search trail list with current filters
		 * @return {Promise} The refresh promise
		 *
		 * @spec openspec/changes/retrofit-2026-04-23-annotate-openregister/tasks.md#task-89
		 */
		async refreshSearchTrailList() {
			return this.fetchSearchTrails({
				limit: this.searchTrailPagination.limit,
				page: this.searchTrailPagination.page,
			})
		},

		/**
		 * Get search trail statistics
		 * @return {Promise<object>} The statistics
		 *
		 * @spec openspec/changes/retrofit-2026-04-23-annotate-openregister/tasks.md#task-89
		 */
		async getStatistics() {
			try {
				await this.fetchStatistics()
				return this.statistics
			} catch (error) {
				console.error('Error getting statistics:', error)
				return {
					total: 0,
					totalResults: 0,
					averageResultsPerSearch: 0,
					averageExecutionTime: 0,
					successRate: 0,
					uniqueSearchTerms: 0,
					uniqueUsers: 0,
					uniqueOrganizations: 0,
					queryComplexity: {
						simple: 0,
						medium: 0,
						complex: 0,
					},
				}
			}
		},

		/**
		 * Get popular search terms
		 * @param {number} limit - Number of terms to get
		 * @return {Promise<Array>} The popular terms
		 *
		 * @spec openspec/changes/retrofit-2026-04-23-annotate-openregister/tasks.md#task-89
		 */
		async getPopularTerms(limit = 10) {
			try {
				await this.fetchPopularTerms(limit)
				return this.popularTerms
			} catch (error) {
				console.error('Error getting popular terms:', error)
				return []
			}
		},

		/**
		 * Get search activity data
		 * @param {string} period - The period to get data for
		 * @return {Promise<Array>} The activity data
		 *
		 * @spec openspec/changes/retrofit-2026-04-23-annotate-openregister/tasks.md#task-89
		 */
		async getActivity(period = 'daily') {
			try {
				await this.fetchActivity(period)
				return this.activity[period] || []
			} catch (error) {
				console.error('Error getting activity data:', error)
				return []
			}
		},

		/**
		 * Get register schema usage statistics
		 * @return {Promise<Array>} The register schema statistics
		 *
		 * @spec openspec/changes/retrofit-2026-04-23-annotate-openregister/tasks.md#task-89
		 */
		async getRegisterSchemaStats() {
			try {
				await this.fetchRegisterSchemaStats()
				return this.registerSchemaStats
			} catch (error) {
				console.error('Error getting register schema statistics:', error)
				return []
			}
		},

		/**
		 * Get user agent statistics
		 * @return {Promise<Array>} The user agent statistics
		 *
		 * @spec openspec/changes/retrofit-2026-04-23-annotate-openregister/tasks.md#task-89
		 */
		async getUserAgentStats() {
			try {
				await this.fetchUserAgentStats()
				return this.userAgentStats
			} catch (error) {
				console.error('Error getting user agent statistics:', error)
				return []
			}
		},

		/**
		 * Clear all search trail store data
		 *
		 * @spec openspec/changes/retrofit-2026-04-23-annotate-openregister/tasks.md#task-89
		 */
		clearSearchTrailStore() {
			this.searchTrailList = []
			this.searchTrailItem = null
			this.searchTrailPagination = {
				total: 0,
				page: 1,
				pages: 1,
				limit: 50,
				offset: 0,
			}
			this.statistics = {
				total: 0,
				totalResults: 0,
				averageResultsPerSearch: 0,
				averageExecutionTime: 0,
				successRate: 0,
				uniqueSearchTerms: 0,
				uniqueUsers: 0,
				uniqueOrganizations: 0,
				queryComplexity: {
					simple: 0,
					medium: 0,
					complex: 0,
				},
			}
			this.popularTerms = []
			this.activity = {
				hourly: [],
				daily: [],
				weekly: [],
				monthly: [],
			}
			this.registerSchemaStats = []
			this.userAgentStats = []
			this.searchTrailFilters = {}
			this.searchTrailSearch = ''
		},
	},
})

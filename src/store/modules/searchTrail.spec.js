/* eslint-disable no-console */
import { setActivePinia, createPinia } from 'pinia'
import { useSearchTrailStore } from './searchTrail.js'

// Mock fetch globally
global.fetch = jest.fn()

describe('SearchTrail Store', () => {
	let store

	beforeEach(() => {
		setActivePinia(createPinia())
		store = useSearchTrailStore()
		jest.clearAllMocks()
	})

	describe('Initial State', () => {
		it('should have correct initial state', () => {
			expect(store.searchTrailItem).toBe(null)
			expect(store.searchTrailList).toEqual([])
			expect(store.searchTrailFilters).toEqual({})
			expect(store.searchTrailPagination).toEqual({
				total: 0,
				page: 1,
				pages: 1,
				limit: 50,
				offset: 0,
			})
			expect(store.searchTrailLoading).toBe(false)
			expect(store.statistics).toEqual({
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
			})
			expect(store.popularTerms).toEqual([])
			expect(store.activity).toEqual({
				hourly: [],
				daily: [],
				weekly: [],
				monthly: [],
			})
			expect(store.registerSchemaStats).toEqual([])
			expect(store.userAgentStats).toEqual([])
		})
	})

	describe('Actions', () => {
		describe('setSearchTrailItem', () => {
			it('should set search trail item correctly', () => {
				const consoleSpy = jest.spyOn(console, 'info').mockImplementation(() => {})
				const searchTrailData = {
					id: 1,
					searchTerm: 'test search',
					parameters: { limit: 10 },
					resultCount: 5,
					executionTime: 150,
					success: true,
				}
				store.setSearchTrailItem(searchTrailData)
				expect(store.searchTrailItem).toEqual(searchTrailData)
				expect(consoleSpy).toHaveBeenCalledWith('Search trail item set to:', searchTrailData)
				consoleSpy.mockRestore()
			})

			it('should handle null search trail item', () => {
				const consoleSpy = jest.spyOn(console, 'info').mockImplementation(() => {})
				store.setSearchTrailItem(null)
				expect(store.searchTrailItem).toBe(null)
				expect(consoleSpy).toHaveBeenCalledWith('Search trail item set to:', null)
				consoleSpy.mockRestore()
			})
		})

		describe('setSearchTrailList', () => {
			it('should set search trail list correctly', () => {
				const consoleSpy = jest.spyOn(console, 'info').mockImplementation(() => {})
				const searchTrails = [
					{
						id: 1,
						searchTerm: 'test search 1',
						parameters: { limit: 10 },
						resultCount: 5,
						executionTime: 150,
						success: true,
					},
					{
						id: 2,
						searchTerm: 'test search 2',
						parameters: { limit: 20 },
						resultCount: 15,
						executionTime: 200,
						success: true,
					},
				]
				store.setSearchTrailList(searchTrails)
				expect(store.searchTrailList).toHaveLength(2)
				expect(store.searchTrailList[0]).toEqual(searchTrails[0])
				expect(consoleSpy).toHaveBeenCalledWith('Search trail list set to:', 2, 'items')
				consoleSpy.mockRestore()
			})

			it('should handle empty search trail list', () => {
				const consoleSpy = jest.spyOn(console, 'info').mockImplementation(() => {})
				store.setSearchTrailList([])
				expect(store.searchTrailList).toEqual([])
				expect(consoleSpy).toHaveBeenCalledWith('Search trail list set to:', 0, 'items')
				consoleSpy.mockRestore()
			})
		})

		describe('setSearchTrailPagination', () => {
			it('should set pagination correctly', () => {
				const consoleSpy = jest.spyOn(console, 'info').mockImplementation(() => {})
				const pagination = {
					total: 100,
					page: 2,
					pages: 5,
					limit: 20,
					offset: 20,
				}
				store.setSearchTrailPagination(pagination)
				expect(store.searchTrailPagination).toEqual(pagination)
				expect(consoleSpy).toHaveBeenCalledWith('Search trail pagination set to:', store.searchTrailPagination)
				consoleSpy.mockRestore()
			})
		})

		describe('setStatistics', () => {
			it('should map snake_case API response to camelCase state', () => {
				const consoleSpy = jest.spyOn(console, 'info').mockImplementation(() => {})
				const apiStats = {
					total_searches: 1000,
					total_results: 15000,
					avg_results_per_search: 15,
					avg_response_time: 180,
					success_rate: 95,
					unique_search_terms: 250,
					unique_users: 50,
					unique_organizations: 10,
					query_complexity: {
						simple: 600,
						medium: 300,
						complex: 100,
					},
				}
				store.setStatistics(apiStats)
				expect(store.statistics.total).toBe(1000)
				expect(store.statistics.totalResults).toBe(15000)
				expect(store.statistics.averageResultsPerSearch).toBe(15)
				expect(store.statistics.averageExecutionTime).toBe(180)
				expect(store.statistics.successRate).toBe(0.95)
				expect(store.statistics.uniqueSearchTerms).toBe(250)
				expect(store.statistics.uniqueUsers).toBe(50)
				expect(store.statistics.uniqueOrganizations).toBe(10)
				expect(store.statistics.queryComplexity).toEqual({
					simple: 600,
					medium: 300,
					complex: 100,
				})
				consoleSpy.mockRestore()
			})
		})

		describe('setPopularTerms', () => {
			it('should handle array response', () => {
				const consoleSpy = jest.spyOn(console, 'info').mockImplementation(() => {})
				const terms = [
					{ term: 'user', count: 100 },
					{ term: 'order', count: 80 },
					{ term: 'product', count: 60 },
				]
				store.setPopularTerms(terms)
				expect(store.popularTerms).toEqual(terms)
				expect(consoleSpy).toHaveBeenCalledWith('Popular terms set to:', 3, 'items')
				consoleSpy.mockRestore()
			})

			it('should handle { results: [] } response', () => {
				const consoleSpy = jest.spyOn(console, 'info').mockImplementation(() => {})
				store.setPopularTerms({ results: [{ term: 'test', count: 5 }] })
				expect(store.popularTerms).toHaveLength(1)
				consoleSpy.mockRestore()
			})
		})

		describe('setSearchTrailFilters', () => {
			it('should set filters correctly', () => {
				const consoleSpy = jest.spyOn(console, 'info').mockImplementation(() => {})
				const filters = { searchTerm: 'test', success: true }
				store.setSearchTrailFilters(filters)
				expect(store.searchTrailFilters).toEqual(filters)
				expect(consoleSpy).toHaveBeenCalledWith('Search trail filters set to:', filters)
				consoleSpy.mockRestore()
			})
		})

		describe('fetchSearchTrails', () => {
			it('should fetch search trails successfully', async () => {
				const mockResponse = {
					results: [
						{
							id: 1,
							searchTerm: 'test search',
							parameters: { limit: 10 },
							resultCount: 5,
							executionTime: 150,
							success: true,
						},
					],
					total: 1,
					page: 1,
					pages: 1,
					limit: 50,
					offset: 0,
				}

				fetch.mockResolvedValueOnce({
					ok: true,
					json: async () => mockResponse,
				})

				const result = await store.fetchSearchTrails()

				expect(fetch).toHaveBeenCalledWith(
					'/index.php/apps/openregister/api/search-trails?',
					{
						method: 'GET',
						headers: {
							'Content-Type': 'application/json',
							requesttoken: OC.requestToken,
						},
					},
				)
				expect(store.searchTrailList).toHaveLength(1)
				expect(store.searchTrailPagination.total).toBe(1)
				expect(result).toEqual(mockResponse)
				expect(store.searchTrailLoading).toBe(false)
			})

			it('should handle API errors', async () => {
				fetch.mockResolvedValueOnce({
					ok: false,
					json: async () => ({ error: 'Server error' }),
				})

				await expect(store.fetchSearchTrails()).rejects.toThrow('Server error')
				expect(store.searchTrailLoading).toBe(false)
			})

			it('should build query parameters correctly', async () => {
				const mockResponse = {
					results: [],
					total: 0,
					page: 1,
					pages: 1,
					limit: 50,
					offset: 0,
				}

				fetch.mockResolvedValueOnce({
					ok: true,
					json: async () => mockResponse,
				})

				const options = {
					limit: 20,
					page: 2,
					search: 'test search',
					filters: { success: true },
					sort: { created: 'DESC' },
				}

				await store.fetchSearchTrails(options)

				expect(fetch).toHaveBeenCalledWith(
					'/index.php/apps/openregister/api/search-trails?limit=20&page=2&search=test+search&success=true&sort=created&order=DESC',
					{
						method: 'GET',
						headers: {
							'Content-Type': 'application/json',
							requesttoken: OC.requestToken,
						},
					},
				)
			})
		})

		describe('fetchStatistics', () => {
			it('should fetch statistics successfully', async () => {
				const mockApiStats = {
					total_searches: 1000,
					total_results: 15000,
					avg_results_per_search: 15,
					avg_response_time: 180,
					success_rate: 95,
					unique_search_terms: 250,
					unique_users: 50,
					unique_organizations: 10,
					query_complexity: {
						simple: 600,
						medium: 300,
						complex: 100,
					},
				}

				fetch.mockResolvedValueOnce({
					ok: true,
					json: async () => mockApiStats,
				})

				const result = await store.fetchStatistics()

				expect(fetch).toHaveBeenCalledWith(
					'/index.php/apps/openregister/api/search-trails/statistics',
					{
						method: 'GET',
						headers: {
							'Content-Type': 'application/json',
							requesttoken: OC.requestToken,
						},
					},
				)
				expect(store.statistics.total).toBe(1000)
				expect(store.statistics.successRate).toBe(0.95)
				expect(result).toEqual(mockApiStats)
				expect(store.statisticsLoading).toBe(false)
			})

			it('should handle statistics API errors', async () => {
				fetch.mockResolvedValueOnce({
					ok: false,
					json: async () => ({ error: 'Statistics error' }),
				})

				await expect(store.fetchStatistics()).rejects.toThrow('Statistics error')
				expect(store.statisticsLoading).toBe(false)
			})
		})

		describe('fetchPopularTerms', () => {
			it('should fetch popular terms successfully', async () => {
				const mockTerms = [
					{ term: 'user', count: 100 },
					{ term: 'order', count: 80 },
					{ term: 'product', count: 60 },
				]

				fetch.mockResolvedValueOnce({
					ok: true,
					json: async () => mockTerms,
				})

				const result = await store.fetchPopularTerms(10)

				expect(fetch).toHaveBeenCalledWith(
					'/index.php/apps/openregister/api/search-trails/popular-terms?limit=10',
					{
						method: 'GET',
						headers: {
							'Content-Type': 'application/json',
							requesttoken: OC.requestToken,
						},
					},
				)
				expect(store.popularTerms).toEqual(mockTerms)
				expect(result).toEqual(mockTerms)
				expect(store.popularTermsLoading).toBe(false)
			})
		})

		describe('fetchActivity', () => {
			it('should fetch activity data successfully', async () => {
				const mockActivity = {
					activity: [
						{ period: '2023-01-01', count: 50, avg_results: 15, avg_response_time: 100 },
						{ period: '2023-01-02', count: 75, avg_results: 20, avg_response_time: 120 },
					],
				}

				fetch.mockResolvedValueOnce({
					ok: true,
					json: async () => mockActivity,
				})

				const result = await store.fetchActivity('daily')

				expect(fetch).toHaveBeenCalledWith(
					'/index.php/apps/openregister/api/search-trails/activity?interval=daily',
					{
						method: 'GET',
						headers: {
							'Content-Type': 'application/json',
							requesttoken: OC.requestToken,
						},
					},
				)
				expect(store.activity.daily).toHaveLength(2)
				expect(store.activity.daily[0].period).toBe('2023-01-01')
				expect(store.activity.daily[0].searches).toBe(50)
				expect(result).toEqual(mockActivity)
				expect(store.activityLoading).toBe(false)
			})
		})

		describe('cleanupSearchTrails', () => {
			it('should cleanup search trails successfully', async () => {
				const mockResponse = {
					success: true,
					message: 'Cleanup completed',
					deletedCount: 100,
				}

				fetch.mockResolvedValueOnce({
					ok: true,
					json: async () => mockResponse,
				})

				const result = await store.cleanupSearchTrails(30)

				expect(fetch).toHaveBeenCalledWith(
					'/index.php/apps/openregister/api/search-trails/cleanup',
					{
						method: 'POST',
						headers: {
							'Content-Type': 'application/json',
							requesttoken: OC.requestToken,
						},
						body: JSON.stringify({ days: 30 }),
					},
				)
				expect(result).toEqual(mockResponse)
			})
		})

		describe('refreshSearchTrailList', () => {
			it('should refresh search trail list with current pagination', async () => {
				const mockResponse = {
					results: [],
					total: 0,
					page: 1,
					pages: 1,
					limit: 50,
					offset: 0,
				}

				fetch.mockResolvedValueOnce({
					ok: true,
					json: async () => mockResponse,
				})

				const result = await store.refreshSearchTrailList()

				expect(fetch).toHaveBeenCalledWith(
					'/index.php/apps/openregister/api/search-trails?limit=50&page=1',
					{
						method: 'GET',
						headers: {
							'Content-Type': 'application/json',
							requesttoken: OC.requestToken,
						},
					},
				)
				expect(result).toEqual(mockResponse)
			})
		})

		describe('getStatistics', () => {
			it('should get statistics with error handling', async () => {
				fetch.mockResolvedValueOnce({
					ok: false,
					json: async () => ({ error: 'Server error' }),
				})

				const result = await store.getStatistics()

				expect(result).toEqual({
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
				})
			})
		})

		describe('clearSearchTrailStore', () => {
			it('should clear all store data', () => {
				const consoleSpy = jest.spyOn(console, 'info').mockImplementation(() => {})
				// Set some data first
				store.searchTrailList = [{ id: 1 }]
				store.searchTrailItem = { id: 1 }
				store.searchTrailFilters = { test: true }
				store.statistics = { total: 100 }

				store.clearSearchTrailStore()

				expect(store.searchTrailList).toEqual([])
				expect(store.searchTrailItem).toBe(null)
				expect(store.searchTrailFilters).toEqual({})
				expect(store.statistics).toEqual({
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
				})
				expect(store.registerSchemaStats).toEqual([])
				expect(store.userAgentStats).toEqual([])
				expect(consoleSpy).toHaveBeenCalledWith('Search trail store cleared')
				consoleSpy.mockRestore()
			})
		})
	})
})

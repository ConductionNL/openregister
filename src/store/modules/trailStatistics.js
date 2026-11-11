import { statisticsOrEmpty, storeRequest } from '../../services/listPage.js'

const apiUrl = '/index.php/apps/openregister/api'

/**
 * The statistics actions the audit-trail and deleted-objects stores share:
 * merging statistics into the store, and reading them from the resource's
 * `statistics` endpoint.
 *
 * @param {string} resource The API resource, `audit-trails` or `deleted`.
 * @return {object} The `setStatistics` and `fetchStatistics` actions.
 * @spec openspec/specs/audit-trail-immutable/spec.md
 */
export function mergedStatisticsActions(resource) {
	return {
		/**
		 * Merge statistics into the ones the store holds.
		 *
		 * @param {object} stats - The statistics object
		 * @spec exclude store setter (local statistics state)
		 */
		setStatistics(stats) {
			this.statistics = {
				...this.statistics,
				...stats,
			}
		},

		/**
		 * Fetch the resource's statistics.
		 *
		 * @return {Promise<object>} The statistics data
		 * @spec openspec/specs/audit-trail-immutable/spec.md
		 */
		async fetchStatistics() {
			return storeRequest(this, {
				flag: 'statisticsLoading',
				url: `${apiUrl}/${resource}/statistics`,
				request: { method: 'GET', failure: 'Failed to fetch statistics' },
				log: (error) => console.error('Error fetching statistics:', error),
				keep: (data) => this.setStatistics(data),
			})
		},
	}
}

/**
 * The `getStatistics` action the audit-trail and search-trail stores share:
 * the fetched statistics, or empty ones when they fail to load.
 *
 * @param {function(): object} empty The empty statistics' factory.
 * @return {object} The `getStatistics` action.
 * @spec openspec/specs/audit-trail-immutable/spec.md
 */
export function statisticsGetter(empty) {
	return {
		/**
		 * Get statistics (fetch if not already loaded).
		 *
		 * @return {Promise<object>} The statistics
		 * @spec openspec/specs/audit-trail-immutable/spec.md
		 */
		async getStatistics() {
			return statisticsOrEmpty(this, {
				empty,
				log: (error) => console.error('Error getting statistics:', error),
			})
		},
	}
}

/**
 * What the audit trail and search trail sidebars share: the filter and
 * statistics components they render, and the user and date range filter
 * state with the user options taken from the loaded trail list.
 *
 * @spec exclude Client-side filter state shared by the trail sidebars.
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
import CountListItem from '../components/shared/CountListItem.vue'
import SidebarStatCard from '../components/shared/SidebarStatCard.vue'
import StatisticsSidebarTab from '../components/shared/StatisticsSidebarTab.vue'
import TrailUserDateFields from '../sidebars/logs/TrailUserDateFields.vue'
import ScopeFiltersTab from '../sidebars/ScopeFiltersTab.vue'
import SidebarFilterSelect from '../sidebars/SidebarFilterSelect.vue'
import { trailUserOptions } from '../services/trailFilters.js'

/**
 * A mixin for a trail sidebar.
 *
 * @param {Function} trailList Returns the trail list the users are taken from
 * @return {object} The mixin
 */
export function trailSidebar(trailList) {
	return {
		components: {
			CountListItem,
			ScopeFiltersTab,
			SidebarFilterSelect,
			SidebarStatCard,
			StatisticsSidebarTab,
			TrailUserDateFields,
		},

		data() {
			return {
				selectedUsers: [],
				dateFrom: null,
				dateTo: null,
			}
		},

		computed: {
			/**
			 * The users that occur in the loaded trail list, as picker options.
			 *
			 * @spec openspec/changes/retrofit-2026-05-25-fe-sidebars/tasks.md#task-11
			 * @spec openspec/specs/audit-trail-immutable/spec.md#requirement-the-audit-trail-must-use-cryptographic-hash-chaining
			 * @return {Array<object>} The options
			 */
			userOptions() {
				return trailUserOptions(trailList())
			},
		},
	}
}

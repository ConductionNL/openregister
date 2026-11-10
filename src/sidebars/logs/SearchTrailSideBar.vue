<script setup>
import { translatePlural as n, translate as t } from '@nextcloud/l10n'
import {
	navigationStore,
	registerStore,
	schemaStore,
	searchTrailStore,
} from '../../store/store.js'
</script>

<template>
	<NcAppSidebar
		ref="sidebar"
		v-model="activeTab"
		:name="t('openregister', 'Search Trail Management')"
		:subtitle="t('openregister', 'Filter and analyze search trail entries')"
		:subname="t('openregister', 'View search analytics and manage search logs')"
		:open="navigationStore.sidebarState.searchTrail"
		@update:open="(e) => navigationStore.setSidebarState('searchTrail', e)">
		<ScopeFiltersTab
			:title="t('openregister', 'Filter Search Trails')"
			:hint="
				t(
					'openregister',
					'Use filters to narrow down search trail entries by register, schema, success status, user, date range, search terms, or performance metrics.',
				)
			"
			clearable
			@registerChange="handleRegisterChange"
			@schemaChange="handleSchemaChange"
			@clear="clearFilters">
			<SidebarFilterSelect
				id="successSelect"
				v-model="selectedSuccessStatus"
				:options="successOptions"
				:placeholder="t('openregister', 'All searches')"
				:label="t('openregister', 'Success Status')"
				@update:modelValue="applyFilters">
				<template #option="{ label, value }">
					<div class="statusOption" :class="value">
						{{ label }}
					</div>
				</template>
			</SidebarFilterSelect>
			<TrailUserDateFields
				v-model:users="selectedUsers"
				v-model:dateFrom="dateFrom"
				v-model:dateTo="dateTo"
				:userOptions="userOptions"
				@change="applyFilters" />
			<div class="filterGroup">
				<label for="searchTermFilter">{{
					t('openregister', 'Search Term')
				}}</label>
				<NcTextField
					id="searchTermFilter"
					v-model="searchTermFilter"
					:label="t('openregister', 'Filter by search term')"
					:placeholder="t('openregister', 'Enter search term')"
					@update:modelValue="handleSearchTermFilterChange" />
			</div>
			<div class="filterGroup">
				<label for="executionTimeFilter">{{
					t('openregister', 'Execution Time Range')
				}}</label>
				<div class="rangeFilter">
					<NcTextField
						id="executionTimeFrom"
						v-model="executionTimeFrom"
						:label="t('openregister', 'Min execution time (ms)')"
						:placeholder="t('openregister', 'Min ms')"
						type="number"
						@update:modelValue="handleExecutionTimeChange" />
					<NcTextField
						id="executionTimeTo"
						v-model="executionTimeTo"
						:label="t('openregister', 'Max execution time (ms)')"
						:placeholder="t('openregister', 'Max ms')"
						type="number"
						@update:modelValue="handleExecutionTimeChange" />
				</div>
			</div>
			<div class="filterGroup">
				<label for="resultCountFilter">{{
					t('openregister', 'Result Count Range')
				}}</label>
				<div class="rangeFilter">
					<NcTextField
						id="resultCountFrom"
						v-model="resultCountFrom"
						:label="t('openregister', 'Min result count')"
						:placeholder="t('openregister', 'Min results')"
						type="number"
						@update:modelValue="handleResultCountChange" />
					<NcTextField
						id="resultCountTo"
						v-model="resultCountTo"
						:label="t('openregister', 'Max result count')"
						:placeholder="t('openregister', 'Max results')"
						type="number"
						@update:modelValue="handleResultCountChange" />
				</div>
			</div>
		</ScopeFiltersTab>

		<StatisticsSidebarTab>
			<!-- Statistics Section -->
			<div class="statsSection">
				<h3>{{ t('openregister', 'Search Trail Statistics') }}</h3>
				<div class="statGrid">
					<SidebarStatCard
						:value="totalSearchTrails"
						:label="t('openregister', 'Total Searches')" />
					<SidebarStatCard
						:value="totalResults"
						:label="t('openregister', 'Total Results')" />
					<SidebarStatCard
						:value="averageResultsPerSearch"
						:label="t('openregister', 'Avg Results/Search')" />
					<SidebarStatCard
						:value="`${averageExecutionTime}ms`"
						:label="t('openregister', 'Avg Execution Time')" />
					<SidebarStatCard
						:value="`${(successRate * 100).toFixed(1)}%`"
						:label="t('openregister', 'Success Rate')" />
					<SidebarStatCard
						:value="uniqueSearchTerms"
						:label="t('openregister', 'Unique Search Terms')" />
					<SidebarStatCard
						:value="avgSearchesPerSession"
						:label="t('openregister', 'Avg Searches/Session')" />
					<SidebarStatCard
						:value="avgObjectViewsPerSession"
						:label="t('openregister', 'Avg Object Views/Session')" />
				</div>
			</div>

			<!-- Query Complexity Distribution -->
			<div class="complexitySection">
				<h4>{{ t('openregister', 'Query Complexity Distribution') }}</h4>
				<div class="complexityBars">
					<div
						v-for="level in complexityLevels"
						:key="level.key"
						class="complexityBar">
						<div class="complexityLabel">
							<span
								:title="level.title"
								class="complexity-label-with-tooltip">
								{{ level.label }}
							</span>
							<span>{{ queryComplexity[level.key] }}</span>
						</div>
						<div class="complexityProgress">
							<div
								class="complexityProgressBar"
								:class="level.key"
								:style="{
									width: getComplexityPercentage(level.key) + '%',
								}" />
						</div>
					</div>
				</div>
			</div>

			<!-- Popular Search Terms -->
			<div class="popularTermsSection">
				<h4>{{ t('openregister', 'Popular Search Terms') }}</h4>
				<div class="popularTermsList">
					<CountListItem
						v-for="(term, index) in popularTerms"
						:key="index"
						:name="term.term"
						:subname="searchesLabel(term.count)">
						<template #icon>
							<MagnifyPlus :size="32" />
						</template>
					</CountListItem>
				</div>
			</div>

			<!-- Register/Schema Usage -->
			<div class="registerSchemaSection">
				<h4>{{ t('openregister', 'Register/Schema Usage') }}</h4>
				<div class="registerSchemaList">
					<CountListItem
						v-for="(stat, index) in registerSchemaStats"
						:key="index"
						:name="getRegisterSchemaName(stat)"
						:subname="searchesLabel(stat.count)">
						<template #icon>
							<DatabaseOutline :size="32" />
						</template>
					</CountListItem>
				</div>
			</div>
		</StatisticsSidebarTab>

		<NcAppSidebarTab
			id="analytics-tab"
			:name="t('openregister', 'Analytics')"
			:order="3">
			<template #icon>
				<TrendingUp :size="20" />
			</template>

			<!-- Analytics Section -->
			<div class="analyticsSection">
				<h3>{{ t('openregister', 'Search Analytics') }}</h3>

				<!-- Activity Period Selector -->
				<div class="filterGroup">
					<label for="activityPeriodSelect">{{
						t('openregister', 'Activity Period')
					}}</label>
					<NcSelect
						id="activityPeriodSelect"
						v-model="selectedActivityPeriod"
						inputLabel="Selected Activity Period"
						:options="activityPeriodOptions"
						:placeholder="t('openregister', 'Select period')"
						@update:modelValue="loadActivityData">
						<template #option="{ label }">
							{{ label }}
						</template>
					</NcSelect>
				</div>

				<!-- Activity Chart/Data -->
				<div class="activityData">
					<h4>{{ t('openregister', 'Search Activity') }}</h4>
					<div
						v-if="searchTrailStore.activityLoading"
						class="loadingSpinner">
						<NcLoadingIcon :size="32" />
					</div>
					<div
						v-else-if="currentActivityData.length > 0"
						class="activityList">
						<div
							v-for="(activity, index) in currentActivityData"
							:key="index"
							class="activityItem">
							<span class="activityPeriod">{{
								formatActivityPeriod(activity.period)
							}}</span>
							<span class="activityCount"
								>{{ activity.searches }} searches</span
							>
						</div>
					</div>
					<div v-else class="noActivityData">
						{{ t('openregister', 'No activity data available') }}
					</div>
				</div>

				<!-- User Agent Statistics -->
				<div class="userAgentSection">
					<h4>{{ t('openregister', 'User Agent Statistics') }}</h4>
					<div class="userAgentList">
						<CountListItem
							v-for="(agent, index) in userAgentStats"
							:key="index"
							:name="getBrowserName(agent)"
							:subname="searchesLabel(agent.count)">
							<template #icon>
								<Monitor :size="32" />
							</template>
						</CountListItem>
					</div>
				</div>
			</div>
		</NcAppSidebarTab>
	</NcAppSidebar>
</template>

<script>
import {
	NcAppSidebar,
	NcAppSidebarTab,
	NcLoadingIcon,
	NcSelect,
	NcTextField,
} from '@nextcloud/vue'
import DatabaseOutline from 'vue-material-design-icons/DatabaseOutline.vue'
import MagnifyPlus from 'vue-material-design-icons/MagnifyPlus.vue'
import Monitor from 'vue-material-design-icons/Monitor.vue'
import TrendingUp from 'vue-material-design-icons/TrendingUp.vue'
import { trailSidebar } from '../../composables/trailSidebar.js'
import eventBus from '../../eventBus.js'
import {
	addDateRange,
	addStrings,
	csvValues,
	routeDate,
} from '../../services/routeQuery.js'
import { applyRouteScope } from '../../services/routeScope.js'
import {
	addJoinedValues,
	addPresent,
	addScopeIds,
} from '../../services/trailFilters.js'
import { routeBoundFilters, selectedScope } from '../routeBoundFilters.js'

/**
 * Leave a statistic as the API reports it.
 *
 * @param {number} value The statistic
 * @return {number} The same value
 */
const asIs = (value) => value

// Shown statistics: field, statistics key, and how to show the value.
const SEARCH_STATISTICS = [
	['totalSearchTrails', 'total', asIs],
	['totalResults', 'totalResults', asIs],
	['averageResultsPerSearch', 'averageResultsPerSearch', Math.round],
	['averageExecutionTime', 'averageExecutionTime', Math.round],
	['successRate', 'successRate', asIs],
	['uniqueSearchTerms', 'uniqueSearchTerms', asIs],
	['uniqueUsers', 'uniqueUsers', asIs],
	['uniqueOrganizations', 'uniqueOrganizations', asIs],
	['avgSearchesPerSession', 'avgSearchesPerSession', asIs],
	['avgObjectViewsPerSession', 'avgObjectViewsPerSession', asIs],
]

/**
 * The search trail statistics to show, 0 where one is missing.
 *
 * @param {object} stats The statistics from the store
 * @return {object} The shown statistics
 */
function searchStatisticsOf(stats) {
	const shown = Object.fromEntries(
		SEARCH_STATISTICS.map(([field, key, show]) => [
			field,
			show(stats[key] || 0),
		]),
	)
	shown.queryComplexity = stats.queryComplexity || {
		simple: 0,
		medium: 0,
		complex: 0,
	}
	return shown
}

const ACTIVITY_PERIOD_FORMATS = {
	hourly: (date) => date.toLocaleString(),
	daily: (date) => date.toLocaleDateString(),
	weekly: (date) => `Week of ${date.toLocaleDateString()}`,
	monthly: (date) =>
		date.toLocaleDateString(undefined, { year: 'numeric', month: 'long' }),
}

/**
 * Add the picked success status and users to a route query.
 *
 * @param {object} query The query being built
 * @param {object} picked The sidebar's selectedSuccessStatus and selectedUsers
 */
function addSelections(query, picked) {
	if (picked.selectedSuccessStatus && picked.selectedSuccessStatus.value)
		query.success = String(picked.selectedSuccessStatus.value)
	if (Array.isArray(picked.selectedUsers) && picked.selectedUsers.length > 0)
		query.user = picked.selectedUsers.map((u) => u.value || u).join(',')
}

const ROUTE_FIELDS = {
	searchTerm: 'searchTermFilter',
	executionTimeFrom: 'executionTimeFrom',
	executionTimeTo: 'executionTimeTo',
	resultCountFrom: 'resultCountFrom',
	resultCountTo: 'resultCountTo',
}

const RANGE_FILTERS = Object.keys(ROUTE_FIELDS)

export default {
	name: 'SearchTrailSideBar',
	components: {
		NcAppSidebar,
		NcAppSidebarTab,
		NcSelect,
		NcTextField,
		NcLoadingIcon,
		TrendingUp,
		MagnifyPlus,
		DatabaseOutline,
		Monitor,
	},

	mixins: [
		routeBoundFilters('/search-trails'),
		trailSidebar(() => searchTrailStore.searchTrailList),
	],

	data() {
		return {
			successOptions: [
				{
					label: t('openregister', 'Successful'),
					value: 'true',
				},
				{
					label: t('openregister', 'Failed'),
					value: 'false',
				},
			],

			activityPeriodOptions: [
				{
					label: t('openregister', 'Hourly'),
					value: 'hourly',
				},
				{
					label: t('openregister', 'Daily'),
					value: 'daily',
				},
				{
					label: t('openregister', 'Weekly'),
					value: 'weekly',
				},
				{
					label: t('openregister', 'Monthly'),
					value: 'monthly',
				},
			],

			activeTab: 'filters-tab',
			selectedSuccessStatus: null,
			selectedActivityPeriod: { value: 'daily', label: 'Daily' },
			searchTermFilter: '',
			executionTimeFrom: '',
			executionTimeTo: '',
			resultCountFrom: '',
			resultCountTo: '',
			filteredCount: 0,

			// Statistics
			totalSearchTrails: 0,
			totalResults: 0,
			averageResultsPerSearch: 0,
			averageExecutionTime: 0,
			successRate: 0,
			uniqueSearchTerms: 0,
			uniqueUsers: 0,
			uniqueOrganizations: 0,
			avgSearchesPerSession: 0,
			avgObjectViewsPerSession: 0,
			queryComplexity: {
				simple: 0,
				medium: 0,
				complex: 0,
			},

			popularTerms: [],
			registerSchemaStats: [],
			userAgentStats: [],
			currentActivityData: [],

			filterTimeout: null,
		}
	},

	computed: {
		// A <script setup> binding is not on `this`, and a watch path reads `this`.
		searchTrailStore: () => searchTrailStore,

		/**
		 * The query complexity levels the distribution shows, in order.
		 *
		 * @spec exclude Presentational labels of the complexity bars.
		 * @return {Array<{key: string, label: string, title: string}>} The levels
		 */
		complexityLevels() {
			return [
				{
					key: 'simple',
					label: t('openregister', 'Simple'),
					title: t(
						'openregister',
						'Simple queries: basic text searches with minimal parameters (e.g., single search term, no advanced filters)',
					),
				},
				{
					key: 'medium',
					label: t('openregister', 'Medium'),
					title: t(
						'openregister',
						'Medium queries: searches with some filtering or multiple parameters (e.g., date ranges, specific registers/schemas)',
					),
				},
				{
					key: 'complex',
					label: t('openregister', 'Complex'),
					title: t(
						'openregister',
						'Complex queries: advanced searches with multiple filters, operators, and complex parameter combinations',
					),
				},
			]
		},
	},

	watch: {
		'searchTrailStore.searchTrailList': function () {
			this.updateFilteredCount()
		},
	},

	/**
	 * Load every search-trail analytics dataset on mount and seed state from the route.
	 *
	 * @spec openspec/specs/zoeken-filteren/spec.md#requirement-search-trail-analytics-dashboard
	 * @return {void}
	 */
	mounted() {
		// Load required data
		this.refreshEmptyScopeLists()

		// Load initial search trail data
		this.loadSearchTrailData()
		this.loadStatistics()
		this.loadPopularTerms()
		this.loadRegisterSchemaStats()
		this.loadUserAgentStats()
		this.loadActivityData()

		// Listen for filtered count updates
		this.listenFilteredCount('search-trail-filtered-count')

		// Watch store changes and update count
		this.updateFilteredCount()

		// Initialize from current URL query params
		this.applyQueryParamsFromRoute()
	},

	beforeUnmount() {
		eventBus.off('search-trail-filtered-count')
	},

	methods: {
		/**
		 * "{count} searches", translated, for a row of a top list.
		 *
		 * @param {number} count How often the row occurs
		 * @spec exclude Presentational count label.
		 * @return {string} The label
		 */
		searchesLabel(count) {
			return t('openregister', '{count} searches', { count })
		},

		/**
		 * Load search trail data and update filtered count
		 *
		 * @spec openspec/specs/zoeken-filteren/spec.md#requirement-search-trail-analytics-dashboard
		 * @return {Promise<void>}
		 */
		async loadSearchTrailData() {
			try {
				await searchTrailStore.refreshSearchTrailList()
				this.updateFilteredCount()
			} catch {
				// Handle error silently
			}
		},

		/**
		 * Clear all filters
		 *
		 * @spec openspec/changes/retrofit-2026-05-25-fe-sidebars/tasks.md#task-6
		 * @return {void}
		 */
		clearAllFilters() {
			// Clear component state
			this.selectedSuccessStatus = null
			this.selectedUsers = []
			this.dateFrom = null
			this.dateTo = null
			this.searchTermFilter = ''
			this.executionTimeFrom = ''
			this.executionTimeTo = ''
			this.resultCountFrom = ''
			this.resultCountTo = ''

			// Clear global stores
			registerStore.setRegisterItem(null)
			schemaStore.setSchemaItem(null)

			// Clear store filters
			searchTrailStore.setSearchTrailFilters({})

			// Refresh without applying filters
			searchTrailStore.refreshSearchTrailList()

			// Reflect cleared filters in URL
			this.updateRouteQueryFromState()
		},


		/**
		 * Handle search term filter change with debouncing
		 *
		 * @spec openspec/changes/retrofit-2026-05-25-fe-sidebars/tasks.md#task-6
		 * @param {string} value - The filter value
		 * @return {void}
		 */
		handleSearchTermFilterChange(value) {
			this.searchTermFilter = value
			this.debouncedApplyFilters()
		},

		/**
		 * Handle execution time filter change with debouncing
		 *
		 * @spec openspec/changes/retrofit-2026-05-25-fe-sidebars/tasks.md#task-6
		 * @return {void}
		 */
		handleExecutionTimeChange() {
			this.debouncedApplyFilters()
		},

		/**
		 * Handle result count filter change with debouncing
		 *
		 * @spec openspec/changes/retrofit-2026-05-25-fe-sidebars/tasks.md#task-6
		 * @return {void}
		 */
		handleResultCountChange() {
			this.debouncedApplyFilters()
		},

		/**
		 * Apply filters and emit to parent components
		 *
		 * @spec openspec/changes/retrofit-2026-05-25-fe-sidebars/tasks.md#task-6
		 * @return {void}
		 */
		applyFilters() {
			const filters = {}
			if (this.selectedSuccessStatus) {
				filters.success = this.selectedSuccessStatus.value
			}
			addScopeIds(filters, selectedScope())
			addJoinedValues(filters, ['user', this.selectedUsers])
			addPresent(filters, [
				['dateFrom', this.dateFrom],
				['dateTo', this.dateTo],
				['searchTerm', this.searchTermFilter],
				['executionTimeFrom', this.executionTimeFrom],
				['executionTimeTo', this.executionTimeTo],
				['resultCountFrom', this.resultCountFrom],
				['resultCountTo', this.resultCountTo],
			])

			// Set filters in store and refresh data
			searchTrailStore.setSearchTrailFilters(filters)
			searchTrailStore.refreshSearchTrailList()

			// Also emit for legacy compatibility
			eventBus.emit('search-trail-filters-changed', filters)

			// Reflect filters in URL
			this.updateRouteQueryFromState()
		},


		/**
		 * Update filtered count from store
		 *
		 * @spec openspec/specs/zoeken-filteren/spec.md#requirement-search-trail-analytics-dashboard
		 * @return {void}
		 */
		updateFilteredCount() {
			this.filteredCount = searchTrailStore.searchTrailList.length
		},

		/**
		 * Load statistics
		 *
		 * @spec openspec/specs/zoeken-filteren/spec.md#requirement-search-trail-analytics-dashboard
		 * @return {Promise<void>}
		 */
		async loadStatistics() {
			try {
				const stats = await searchTrailStore.getStatistics()
				Object.assign(this, searchStatisticsOf(stats))
			} catch (error) {
				console.error('Error loading statistics:', error)
				// Set default values on error
				Object.assign(this, searchStatisticsOf({}))
			}
		},

		/**
		 * Load popular search terms
		 *
		 * @spec openspec/specs/zoeken-filteren/spec.md#requirement-search-trail-analytics-dashboard
		 * @return {Promise<void>}
		 */
		async loadPopularTerms() {
			try {
				const terms = await searchTrailStore.getPopularTerms(10)
				this.popularTerms = terms || []
			} catch (error) {
				console.error('Error loading popular terms:', error)
				this.popularTerms = []
			}
		},

		/**
		 * Load register schema statistics
		 *
		 * @spec openspec/specs/zoeken-filteren/spec.md#requirement-search-trail-analytics-dashboard
		 * @return {Promise<void>}
		 */
		async loadRegisterSchemaStats() {
			try {
				const stats = await searchTrailStore.getRegisterSchemaStats()
				this.registerSchemaStats = stats || []
			} catch (error) {
				console.error('Error loading register schema stats:', error)
				this.registerSchemaStats = []
			}
		},

		/**
		 * Load user agent statistics
		 *
		 * @spec openspec/specs/zoeken-filteren/spec.md#requirement-search-trail-analytics-dashboard
		 * @return {Promise<void>}
		 */
		async loadUserAgentStats() {
			try {
				const stats = await searchTrailStore.getUserAgentStats()
				this.userAgentStats = stats || []
			} catch (error) {
				console.error('Error loading user agent stats:', error)
				this.userAgentStats = []
			}
		},

		/**
		 * Load activity data for selected period
		 *
		 * @spec openspec/specs/zoeken-filteren/spec.md#requirement-search-trail-analytics-dashboard
		 * @return {Promise<void>}
		 */
		async loadActivityData() {
			try {
				const period = this.selectedActivityPeriod.value
				const data = await searchTrailStore.getActivity(period)
				this.currentActivityData = data || []
			} catch (error) {
				console.error('Error loading activity data:', error)
				this.currentActivityData = []
			}
			// Reflect activity period in URL
			this.updateRouteQueryFromState()
		},

		/**
		 * Get complexity percentage for progress bar
		 *
		 * @spec openspec/specs/zoeken-filteren/spec.md#requirement-search-trail-analytics-dashboard
		 * @param {string} type - The complexity type
		 * @return {number} The percentage
		 */
		getComplexityPercentage(type) {
			const total =
				this.queryComplexity.simple
				+ this.queryComplexity.medium
				+ this.queryComplexity.complex
			if (total === 0) return 0
			return Math.round((this.queryComplexity[type] / total) * 100)
		},

		/**
		 * Format activity period for display
		 *
		 * @spec openspec/specs/zoeken-filteren/spec.md#requirement-search-trail-analytics-dashboard
		 * @param {string} period - The period string
		 * @return {string} Formatted period
		 */
		formatActivityPeriod(period) {
			const periodType = this.selectedActivityPeriod.value
			if (!Object.hasOwn(ACTIVITY_PERIOD_FORMATS, periodType)) {
				return period
			}
			return ACTIVITY_PERIOD_FORMATS[periodType](new Date(period))
		},

		/**
		 * Get register/schema name for display
		 *
		 * @spec openspec/specs/zoeken-filteren/spec.md#requirement-search-trail-analytics-dashboard
		 * @param {object} stat - The register/schema stat object
		 * @return {string} The display name
		 */
		getRegisterSchemaName(stat) {
			const register = registerStore.registerList.find(
				(r) => r.id === stat.register,
			)
			const schema = schemaStore.schemaList.find((s) => s.id === stat.schema)

			const registerName = register?.title || `Register ${stat.register}`
			const schemaName = schema?.title || `Schema ${stat.schema}`

			return `${registerName} / ${schemaName}`
		},

		/**
		 * Get browser name for display
		 *
		 * @spec openspec/specs/zoeken-filteren/spec.md#requirement-search-trail-analytics-dashboard
		 * @param {object} agent - The user agent stat object
		 * @return {string} The browser name
		 */
		getBrowserName(agent) {
			if (agent.browser_info?.browser) {
				const browser = agent.browser_info.browser
				const version = agent.browser_info.version
				return version ? `${browser} ${version}` : browser
			}
			return agent.user_agent || 'Unknown Browser'
		},

		/**
		 * Build URL query from current component/store state.
		 *
		 * @spec openspec/changes/retrofit-2026-05-25-fe-sidebars/tasks.md#task-6
		 * @return {object} Query object derived from sidebar filter state
		 */
		buildQueryFromState() {
			const query = {}
			if (registerStore.registerItem)
				query.register = String(registerStore.registerItem.id)
			if (schemaStore.schemaItem)
				query.schema = String(schemaStore.schemaItem.id)
			addSelections(query, this)
			addDateRange(query, this)
			if (this.searchTermFilter) query.searchTerm = this.searchTermFilter
			addStrings(query, [
				['executionTimeFrom', this.executionTimeFrom],
				['executionTimeTo', this.executionTimeTo],
				['resultCountFrom', this.resultCountFrom],
				['resultCountTo', this.resultCountTo],
			])
			return query
		},

		/**
		 * Read the URL query and apply it to component/store state.
		 *
		 * @spec openspec/changes/retrofit-2026-05-25-fe-sidebars/tasks.md#task-6
		 * @return {void}
		 */
		applyQueryParamsFromRoute() {
			if (this.$route.path !== '/search-trails') return
			const q = this.$route.query || {}
			this.applySuccessFromRoute(q.success)
			if (typeof q.user === 'string') {
				this.selectedUsers = csvValues(q.user).map((u) => ({
					label: u,
					value: u,
				}))
			}
			// JS dates are awful, so we first check if its a valid date and then create the date. (q.dateFrom is a ISO string)
			this.dateFrom = routeDate(q.dateFrom)
			this.dateTo = routeDate(q.dateTo)
			this.applyRangeFilters(q)
			// Apply store filters once selection ready
			applyRouteScope(q, () => {
				this.applyFilters()
				this.loadActivityData()
			})
		},

		/**
		 * Pick the success status a route names, when it names one.
		 *
		 * @param {string|undefined} success The status from the route
		 * @spec openspec/changes/retrofit-2026-05-25-fe-sidebars/tasks.md#task-11
		 */
		applySuccessFromRoute(success) {
			if (typeof success === 'undefined') return
			const val = String(success)
			this.selectedSuccessStatus =
				this.successOptions.find((o) => String(o.value) === val) || null
		},

		/**
		 * Take the search term and the range filters from the route.
		 *
		 * @param {object} q The route query
		 * @spec openspec/changes/retrofit-2026-05-25-fe-sidebars/tasks.md#task-11
		 */
		applyRangeFilters(q) {
			for (const key of RANGE_FILTERS) {
				this[ROUTE_FIELDS[key]] = q[key] || ''
			}
		},
	},
}
</script>

<style scoped lang="scss">
@use '../../styles/sidebars.scss' as *;

@include divided-sections(filterSection, statsSection, analyticsSection);

@include filter-group-rules;

.rangeFilter {
	display: flex;
	gap: 8px;
}

.rangeFilter input {
	flex: 1;
}

@include action-group;

.statusOption.true {
	color: var(--color-success);
}

.statusOption.false {
	color: var(--color-error);
}

@include filter-hint-rules;

.statGrid {
	display: grid;
	grid-template-columns: 1fr 1fr;
	gap: 12px;
	margin-bottom: 16px;
}

.statCard {
	background: var(--color-background-hover);
	border-radius: var(--border-radius);
	padding: 12px;
	text-align: center;
}

// Smaller than SidebarStatCard's own number and label, to fit two columns.
.statGrid :deep(.statCard .statNumber) {
	font-size: 1.5rem;
}

.statGrid :deep(.statCard .statLabel) {
	font-size: 0.8rem;
}

.complexitySection,
.popularTermsSection,
.registerSchemaSection,
.userAgentSection {
	margin-top: 20px;
}

.complexitySection h4,
.popularTermsSection h4,
.registerSchemaSection h4,
.userAgentSection h4 {
	margin: 0 0 12px 0;
	font-size: 1rem;
	font-weight: 500;
	color: var(--color-main-text);
}

.complexityBars {
	display: flex;
	flex-direction: column;
	gap: 8px;
}

.complexityBar {
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.complexityLabel {
	display: flex;
	justify-content: space-between;
	align-items: center;
	font-size: 0.9rem;
}

.complexityProgress {
	background: var(--color-background-darker);
	border-radius: 4px;
	height: 8px;
	overflow: hidden;
}

.complexityProgressBar {
	height: 100%;
	transition: width 0.3s ease;
}

.complexityProgressBar.simple {
	background: var(--color-success);
}

.complexityProgressBar.medium {
	background: var(--color-warning);
}

.complexityProgressBar.complex {
	background: var(--color-error);
}

.complexity-label-with-tooltip {
	cursor: help;
	text-decoration: underline;
	text-decoration-style: dotted;
	text-underline-offset: 2px;
}

.complexity-label-with-tooltip:hover {
	text-decoration-style: solid;
}

.activityData {
	margin-top: 16px;
}

.loadingSpinner {
	display: flex;
	justify-content: center;
	align-items: center;
	height: 60px;
}

.activityList {
	display: flex;
	flex-direction: column;
	gap: 8px;
}

.activityItem {
	display: flex;
	justify-content: space-between;
	align-items: center;
	padding: 8px;
	background: var(--color-background-hover);
	border-radius: var(--border-radius);
	font-size: 0.9rem;
}

.activityPeriod {
	font-weight: 500;
}

.activityCount {
	color: var(--color-text-maxcontrast);
}

.noActivityData {
	text-align: center;
	color: var(--color-text-maxcontrast);
	padding: 20px;
}

.popularTermsList,
.registerSchemaList,
.userAgentList {
	max-height: 200px;
	overflow-y: auto;
}

/* Add some spacing between select inputs */
:deep(.v-select) {
	margin-bottom: 8px;
}

@media (prefers-reduced-motion: reduce) {
	.complexityProgressBar {
		transition: none;
	}
}
</style>

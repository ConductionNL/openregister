<script setup>
import { translate as t, translatePlural as n } from '@nextcloud/l10n'
import { searchTrailStore, navigationStore, registerStore, schemaStore } from '../../store/store.js'
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
		<NcAppSidebarTab id="filters-tab" :name="t('openregister', 'Filters')" :order="1">
			<template #icon>
				<FilterOutline :size="20" />
			</template>

			<!-- Filter Section -->
			<div class="filterSection">
				<h3>{{ t('openregister', 'Filter Search Trails') }}</h3>
				<div class="filterGroup">
					<label for="registerSelect">{{ t('openregister', 'Register') }}</label>
					<NcSelect
						id="registerSelect"
						v-bind="registerOptions"
						:model-value="selectedRegisterValue"
						:placeholder="t('openregister', 'All registers')"
						:input-label="t('openregister', 'Register')"
						:clearable="true"
						@update:model-value="handleRegisterChange" />
				</div>
				<div class="filterGroup">
					<label for="schemaSelect">{{ t('openregister', 'Schema') }}</label>
					<NcSelect
						id="schemaSelect"
						v-bind="schemaOptions"
						:model-value="selectedSchemaValue"
						:placeholder="t('openregister', 'All schemas')"
						:input-label="t('openregister', 'Schema')"
						:disabled="!registerStore.item"
						:clearable="true"
						@update:model-value="handleSchemaChange" />
				</div>
				<div class="filterGroup">
					<label for="successSelect">{{ t('openregister', 'Success Status') }}</label>
					<NcSelect
						id="successSelect"
						v-model="selectedSuccessStatus"
						:options="successOptions"
						:placeholder="t('openregister', 'All searches')"
						:input-label="t('openregister', 'Success Status')"
						:clearable="true"
						@input="applyFilters">
						<template #option="{ label, value }">
							<div class="statusOption" :class="value">
								{{ label }}
							</div>
						</template>
					</NcSelect>
				</div>
				<div class="filterGroup">
					<label for="userSelect">{{ t('openregister', 'Users') }}</label>
					<NcSelect
						id="userSelect"
						v-model="selectedUsers"
						:options="userOptions"
						:placeholder="t('openregister', 'All users')"
						:input-label="t('openregister', 'Users')"
						:multiple="true"
						:clearable="true"
						@input="applyFilters">
						<template #option="{ label }">
							{{ label }}
						</template>
					</NcSelect>
				</div>
				<div class="filterGroup">
					<label>{{ t('openregister', 'Date Range') }}</label>
					<NcDateTimePickerNative
						v-model="dateFrom"
						:label="t('openregister', 'From date')"
						type="datetime-local"
						@input="applyFilters" />
					<NcDateTimePickerNative
						v-model="dateTo"
						:label="t('openregister', 'To date')"
						type="datetime-local"
						@input="applyFilters" />
				</div>
				<div class="filterGroup">
					<label for="searchTermFilter">{{ t('openregister', 'Search Term') }}</label>
					<NcTextField
						id="searchTermFilter"
						v-model="searchTermFilter"
						:label="t('openregister', 'Filter by search term')"
						:placeholder="t('openregister', 'Enter search term')"
						@update:value="handleSearchTermFilterChange" />
				</div>
				<div class="filterGroup">
					<label for="executionTimeFilter">{{ t('openregister', 'Execution Time Range') }}</label>
					<div class="rangeFilter">
						<NcTextField
							id="executionTimeFrom"
							v-model="executionTimeFrom"
							:label="t('openregister', 'Min execution time (ms)')"
							:placeholder="t('openregister', 'Min ms')"
							type="number"
							@update:value="handleExecutionTimeChange" />
						<NcTextField
							id="executionTimeTo"
							v-model="executionTimeTo"
							:label="t('openregister', 'Max execution time (ms)')"
							:placeholder="t('openregister', 'Max ms')"
							type="number"
							@update:value="handleExecutionTimeChange" />
					</div>
				</div>
				<div class="filterGroup">
					<label for="resultCountFilter">{{ t('openregister', 'Result Count Range') }}</label>
					<div class="rangeFilter">
						<NcTextField
							id="resultCountFrom"
							v-model="resultCountFrom"
							:label="t('openregister', 'Min result count')"
							:placeholder="t('openregister', 'Min results')"
							type="number"
							@update:value="handleResultCountChange" />
						<NcTextField
							id="resultCountTo"
							v-model="resultCountTo"
							:label="t('openregister', 'Max result count')"
							:placeholder="t('openregister', 'Max results')"
							type="number"
							@update:value="handleResultCountChange" />
					</div>
				</div>
			</div>

			<div class="actionGroup">
				<NcButton @click="clearFilters">
					<template #icon>
						<FilterOffOutline :size="20" />
					</template>
					{{ t('openregister', 'Clear Filters') }}
				</NcButton>
			</div>

			<NcNoteCard type="info" class="filter-hint">
				{{ t('openregister', 'Use filters to narrow down search trail entries by register, schema, success status, user, date range, search terms, or performance metrics.') }}
			</NcNoteCard>
		</NcAppSidebarTab>

		<NcAppSidebarTab id="stats-tab" :name="t('openregister', 'Statistics')" :order="2">
			<template #icon>
				<ChartLine :size="20" />
			</template>

			<CnStatsPanel :sections="searchStatsSections">
				<!-- Custom icon for popular terms -->
				<template #item-icon-popularTerms>
					<MagnifyPlus :size="32" />
				</template>
			</CnStatsPanel>
		</NcAppSidebarTab>

		<NcAppSidebarTab id="analytics-tab" :name="t('openregister', 'Analytics')" :order="3">
			<template #icon>
				<TrendingUp :size="20" />
			</template>

			<CnStatsPanel :sections="analyticsSections">
				<template #header>
					<div class="filterGroup">
						<label for="activityPeriodSelect">{{ t('openregister', 'Activity Period') }}</label>
						<NcSelect
							id="activityPeriodSelect"
							v-model="selectedActivityPeriod"
							:options="activityPeriodOptions"
							:placeholder="t('openregister', 'Select period')"
							@input="loadActivityData">
							<template #option="{ label }">
								{{ label }}
							</template>
						</NcSelect>
					</div>
				</template>

				<!-- Custom icon for user agent stats -->
				<template #item-icon-userAgentStats>
					<Monitor :size="32" />
				</template>
			</CnStatsPanel>
		</NcAppSidebarTab>
	</NcAppSidebar>
</template>

<script>
import {
	NcAppSidebar,
	NcAppSidebarTab,
	NcSelect,
	NcNoteCard,
	NcButton,
	NcDateTimePickerNative,
	NcTextField,
} from '@nextcloud/vue'
import { CnStatsPanel } from '@conduction/nextcloud-vue'
import FilterOutline from 'vue-material-design-icons/FilterOutline.vue'
import ChartLine from 'vue-material-design-icons/ChartLine.vue'
import TrendingUp from 'vue-material-design-icons/TrendingUp.vue'
import MagnifyPlus from 'vue-material-design-icons/MagnifyPlus.vue'
import DatabaseOutline from 'vue-material-design-icons/DatabaseOutline.vue'
import Monitor from 'vue-material-design-icons/Monitor.vue'
import FilterOffOutline from 'vue-material-design-icons/FilterOffOutline.vue'
import Counter from 'vue-material-design-icons/Counter.vue'
import TimerOutline from 'vue-material-design-icons/TimerOutline.vue'
import CheckCircleOutline from 'vue-material-design-icons/CheckCircleOutline.vue'
import TagOutline from 'vue-material-design-icons/TagOutline.vue'
import AccountGroupOutline from 'vue-material-design-icons/AccountGroupOutline.vue'
import EyeOutline from 'vue-material-design-icons/EyeOutline.vue'

export default {
	name: 'SearchTrailSideBar',
	components: {
		NcAppSidebar,
		NcAppSidebarTab,
		NcSelect,
		NcNoteCard,
		NcButton,
		NcDateTimePickerNative,
		NcTextField,
		CnStatsPanel,
		FilterOutline,
		ChartLine,
		TrendingUp,
		MagnifyPlus,
		Monitor,
		FilterOffOutline,
	},
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
			selectedUsers: [],
			selectedActivityPeriod: { value: 'daily', label: 'Daily' },
			dateFrom: null,
			dateTo: null,
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
		registerOptions() {
			return {
				options: registerStore.list.map(register => ({
					value: register.id,
					label: register.title,
					title: register.title,
					register,
				})),
				reduce: option => option.register,
				label: 'title',
				getOptionLabel: option => {
					return option.title || (option.register && option.register.title) || option.label || ''
				},
			}
		},
		schemaOptions() {
			if (!registerStore.item) return { options: [] }

			return {
				options: schemaStore.list
					.filter(schema => registerStore.item.schemas.some(registerSchema => registerSchema.id === schema.id))
					.map(schema => ({
						value: schema.id,
						label: schema.title,
						title: schema.title,
						schema,
					})),
				reduce: option => option.schema,
				label: 'title',
				getOptionLabel: option => {
					return option.title || (option.schema && option.schema.title) || option.label || ''
				},
			}
		},
		selectedRegisterValue() {
			if (!registerStore.item) return null
			const register = registerStore.item
			return {
				value: register.id,
				label: register.title,
				title: register.title,
				register,
			}
		},
		selectedSchemaValue() {
			if (!schemaStore.item) return null
			const schema = schemaStore.item
			return {
				value: schema.id,
				label: schema.title,
				title: schema.title,
				schema,
			}
		},
		userOptions() {
			if (!searchTrailStore.searchTrailList || !searchTrailStore.searchTrailList.length) {
				return []
			}
			const users = [...new Set(searchTrailStore.searchTrailList.map(trail => trail.userName || trail.user).filter(Boolean))]
			return users.map(user => ({
				label: user,
				value: user,
			}))
		},
		searchStatsSections() {
			return [
				{
					type: 'stats',
					id: 'total',
					title: t('openregister', 'Search Trail Statistics'),
					layout: 'stack',
					items: [{
						title: t('openregister', 'Total Searches'),
						count: this.totalSearchTrails,
						countLabel: t('openregister', 'searches'),
						variant: 'primary',
						icon: MagnifyPlus,
					}],
				},
				{
					type: 'stats',
					id: 'metrics',
					layout: 'grid',
					columns: 2,
					items: [
						{
							title: t('openregister', 'Total Results'),
							count: this.totalResults,
							countLabel: t('openregister', 'results'),
							icon: Counter,
						},
						{
							title: t('openregister', 'Avg Results/Search'),
							count: this.averageResultsPerSearch,
							countLabel: t('openregister', 'results'),
							icon: ChartLine,
						},
						{
							title: t('openregister', 'Avg Execution Time'),
							count: this.averageExecutionTime,
							countLabel: 'ms',
							icon: TimerOutline,
						},
						{
							title: t('openregister', 'Success Rate'),
							count: parseFloat((this.successRate * 100).toFixed(1)),
							countLabel: '%',
							variant: 'success',
							icon: CheckCircleOutline,
						},
						{
							title: t('openregister', 'Unique Search Terms'),
							count: this.uniqueSearchTerms,
							countLabel: t('openregister', 'terms'),
							icon: TagOutline,
						},
						{
							title: t('openregister', 'Avg Searches/Session'),
							count: this.avgSearchesPerSession,
							countLabel: t('openregister', 'searches'),
							icon: AccountGroupOutline,
						},
						{
							title: t('openregister', 'Avg Object Views/Session'),
							count: this.avgObjectViewsPerSession,
							countLabel: t('openregister', 'views'),
							icon: EyeOutline,
						},
					],
				},
				{
					type: 'progress',
					id: 'queryComplexity',
					title: t('openregister', 'Query Complexity Distribution'),
					items: [
						{
							key: 'simple',
							label: t('openregister', 'Simple'),
							count: this.queryComplexity.simple,
							variant: 'success',
							tooltip: t('openregister', 'Simple queries: Basic text searches with minimal parameters (e.g., single search term, no advanced filters)'),
						},
						{
							key: 'medium',
							label: t('openregister', 'Medium'),
							count: this.queryComplexity.medium,
							variant: 'warning',
							tooltip: t('openregister', 'Medium queries: Searches with some filtering or multiple parameters (e.g., date ranges, specific registers/schemas)'),
						},
						{
							key: 'complex',
							label: t('openregister', 'Complex'),
							count: this.queryComplexity.complex,
							variant: 'error',
							tooltip: t('openregister', 'Complex queries: Advanced searches with multiple filters, operators, and complex parameter combinations'),
						},
					],
				},
				{
					type: 'list',
					id: 'popularTerms',
					title: t('openregister', 'Popular Search Terms'),
					items: this.popularTerms.map(term => ({
						key: term.term,
						name: term.term,
						subname: t('openregister', '{count} searches', { count: term.count }),
					})),
				},
				{
					type: 'list',
					id: 'registerSchemaUsage',
					title: t('openregister', 'Register/Schema Usage'),
					items: this.registerSchemaStats.map(stat => ({
						key: `${stat.register}-${stat.schema}`,
						name: this.getRegisterSchemaName(stat),
						subname: t('openregister', '{count} searches', { count: stat.count }),
						icon: DatabaseOutline,
					})),
				},
			]
		},
		analyticsSections() {
			return [
				{
					type: 'list',
					id: 'activityData',
					title: t('openregister', 'Search Activity'),
					loading: searchTrailStore.activityLoading,
					emptyLabel: t('openregister', 'No activity data available'),
					items: this.currentActivityData.map(activity => ({
						key: activity.period,
						name: this.formatActivityPeriod(activity.period),
						subname: t('openregister', '{count} searches', { count: activity.searches }),
					})),
				},
				{
					type: 'list',
					id: 'userAgentStats',
					title: t('openregister', 'User Agent Statistics'),
					items: this.userAgentStats.map(agent => ({
						key: agent.user_agent || 'unknown',
						name: this.getBrowserName(agent),
						subname: t('openregister', '{count} searches', { count: agent.count }),
					})),
				},
			]
		},
	},
	watch: {
		'$route.query': {
			handler() {
				if (this.$route.path !== '/search-trails') return
				this.applyQueryParamsFromRoute()
			},
			deep: true,
		},
		'searchTrailStore.searchTrailList'() {
			this.updateFilteredCount()
		},
		'registerStore.item'() {
			this.applyFilters()
		},
		'schemaStore.item'() {
			this.applyFilters()
		},
	},
	mounted() {
		if (!registerStore.list.length) {
			registerStore.refreshList()
		}

		if (!schemaStore.list.length) {
			schemaStore.refreshList()
		}

		this.loadSearchTrailData()
		this.loadStatistics()
		this.loadPopularTerms()
		this.loadRegisterSchemaStats()
		this.loadUserAgentStats()
		this.loadActivityData()

		this.$root.$on('search-trail-filtered-count', (count) => {
			this.filteredCount = count
		})

		this.updateFilteredCount()
		this.applyQueryParamsFromRoute()
	},
	beforeDestroy() {
		this.$root.$off('search-trail-filtered-count')
	},
	methods: {
		/**
		 * Load search trail data and update filtered count
		 * @return {Promise<void>}
		 */
		async loadSearchTrailData() {
			try {
				await searchTrailStore.refreshSearchTrailList()
				this.updateFilteredCount()
			} catch (error) {
				// Handle error silently
			}
		},
		/**
		 * Clear all filters
		 * @return {void}
		 */
		clearAllFilters() {
			this.selectedSuccessStatus = null
			this.selectedUsers = []
			this.dateFrom = null
			this.dateTo = null
			this.searchTermFilter = ''
			this.executionTimeFrom = ''
			this.executionTimeTo = ''
			this.resultCountFrom = ''
			this.resultCountTo = ''

			registerStore.setItem(null)
			schemaStore.setItem(null)

			searchTrailStore.setSearchTrailFilters({})
			searchTrailStore.refreshSearchTrailList()

			this.updateRouteQueryFromState()
		},
		/**
		 * Clear filters (alias for clearAllFilters for template compatibility)
		 * @return {void}
		 */
		clearFilters() {
			this.clearAllFilters()
		},
		/**
		 * Handle search term filter change with debouncing
		 * @param {string} value - The filter value
		 * @return {void}
		 */
		handleSearchTermFilterChange(value) {
			this.searchTermFilter = value
			this.debouncedApplyFilters()
		},
		/**
		 * Handle execution time filter change with debouncing
		 * @return {void}
		 */
		handleExecutionTimeChange() {
			this.debouncedApplyFilters()
		},
		/**
		 * Handle result count filter change with debouncing
		 * @return {void}
		 */
		handleResultCountChange() {
			this.debouncedApplyFilters()
		},
		/**
		 * Apply filters and emit to parent components
		 * @return {void}
		 */
		applyFilters() {
			const filters = {}

			if (this.selectedSuccessStatus) {
				filters.success = this.selectedSuccessStatus.value
			}

			if (registerStore.item) {
				filters.register = registerStore.item.id.toString()
			}

			if (schemaStore.item) {
				filters.schema = schemaStore.item.id.toString()
			}

			if (Array.isArray(this.selectedUsers) && this.selectedUsers.length > 0) {
				const users = this.selectedUsers.slice()
				if (users.length > 0) {
					filters.user = users.map(u => u.value).join(',')
				}
			}

			if (this.dateFrom) {
				filters.dateFrom = this.dateFrom
			}
			if (this.dateTo) {
				filters.dateTo = this.dateTo
			}

			if (this.searchTermFilter) {
				filters.searchTerm = this.searchTermFilter
			}

			if (this.executionTimeFrom) {
				filters.executionTimeFrom = this.executionTimeFrom
			}
			if (this.executionTimeTo) {
				filters.executionTimeTo = this.executionTimeTo
			}

			if (this.resultCountFrom) {
				filters.resultCountFrom = this.resultCountFrom
			}
			if (this.resultCountTo) {
				filters.resultCountTo = this.resultCountTo
			}

			searchTrailStore.setSearchTrailFilters(filters)
			searchTrailStore.refreshSearchTrailList()

			this.$root.$emit('search-trail-filters-changed', filters)

			this.updateRouteQueryFromState()
		},
		/**
		 * Debounced version of applyFilters for text input
		 * @return {void}
		 */
		debouncedApplyFilters() {
			clearTimeout(this.filterTimeout)
			this.filterTimeout = setTimeout(() => {
				this.applyFilters()
			}, 500)
		},
		/**
		 * Update filtered count from store
		 * @return {void}
		 */
		updateFilteredCount() {
			this.filteredCount = searchTrailStore.searchTrailList.length
		},
		/**
		 * Load statistics
		 * @return {Promise<void>}
		 */
		async loadStatistics() {
			try {
				const stats = await searchTrailStore.getStatistics()
				this.totalSearchTrails = stats.total || 0
				this.totalResults = stats.totalResults || 0
				this.averageResultsPerSearch = Math.round(stats.averageResultsPerSearch || 0)
				this.averageExecutionTime = Math.round(stats.averageExecutionTime || 0)
				this.successRate = stats.successRate || 0
				this.uniqueSearchTerms = stats.uniqueSearchTerms || 0
				this.uniqueUsers = stats.uniqueUsers || 0
				this.uniqueOrganizations = stats.uniqueOrganizations || 0
				this.avgSearchesPerSession = stats.avgSearchesPerSession || 0
				this.avgObjectViewsPerSession = stats.avgObjectViewsPerSession || 0
				this.queryComplexity = stats.queryComplexity || { simple: 0, medium: 0, complex: 0 }
			} catch (error) {
				console.error('Error loading statistics:', error)
				this.totalSearchTrails = 0
				this.totalResults = 0
				this.averageResultsPerSearch = 0
				this.averageExecutionTime = 0
				this.successRate = 0
				this.uniqueSearchTerms = 0
				this.uniqueUsers = 0
				this.uniqueOrganizations = 0
				this.avgSearchesPerSession = 0
				this.avgObjectViewsPerSession = 0
				this.queryComplexity = { simple: 0, medium: 0, complex: 0 }
			}
		},
		/**
		 * Load popular search terms
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
			this.updateRouteQueryFromState()
		},
		/**
		 * Format activity period for display
		 * @param {string} period - The period string
		 * @return {string} Formatted period
		 */
		formatActivityPeriod(period) {
			const periodType = this.selectedActivityPeriod.value

			switch (periodType) {
			case 'hourly':
				return new Date(period).toLocaleString()
			case 'daily':
				return new Date(period).toLocaleDateString()
			case 'weekly':
				return `Week of ${new Date(period).toLocaleDateString()}`
			case 'monthly':
				return new Date(period).toLocaleDateString(undefined, { year: 'numeric', month: 'long' })
			default:
				return period
			}
		},
		/**
		 * Handle register change
		 * @param {object} register - Selected register
		 * @return {void}
		 */
		handleRegisterChange(register) {
			registerStore.setItem(register)
			schemaStore.setItem(null)
			this.applyFilters()
		},
		/**
		 * Handle schema change
		 * @param {object} schema - Selected schema
		 * @return {void}
		 */
		handleSchemaChange(schema) {
			schemaStore.setItem(schema)
			this.applyFilters()
		},
		/**
		 * Get register/schema name for display
		 * @param {object} stat - The register/schema stat object
		 * @return {string} The display name
		 */
		getRegisterSchemaName(stat) {
			const register = registerStore.list.find(r => r.id === stat.register)
			const schema = schemaStore.list.find(s => s.id === stat.schema)

			const registerName = register?.title || `Register ${stat.register}`
			const schemaName = schema?.title || `Schema ${stat.schema}`

			return `${registerName} / ${schemaName}`
		},
		/**
		 * Get browser name for display
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
		buildQueryFromState() {
			const query = {}
			if (registerStore.item) query.register = String(registerStore.item.id)
			if (schemaStore.item) query.schema = String(schemaStore.item.id)
			if (this.selectedSuccessStatus && this.selectedSuccessStatus.value) query.success = String(this.selectedSuccessStatus.value)
			if (Array.isArray(this.selectedUsers) && this.selectedUsers.length > 0) query.user = this.selectedUsers.map(u => u.value || u).join(',')
			// JS dates are awful, so we first check if its a valid date and then get the ISO string.
			if (this.dateFrom) query.dateFrom = new Date(this.dateFrom).getDate() ? new Date(this.dateFrom).toISOString() : null
			if (this.dateTo) query.dateTo = new Date(this.dateTo).getDate() ? new Date(this.dateTo).toISOString() : null
			if (this.searchTermFilter) query.searchTerm = this.searchTermFilter
			if (this.executionTimeFrom) query.executionTimeFrom = String(this.executionTimeFrom)
			if (this.executionTimeTo) query.executionTimeTo = String(this.executionTimeTo)
			if (this.resultCountFrom) query.resultCountFrom = String(this.resultCountFrom)
			if (this.resultCountTo) query.resultCountTo = String(this.resultCountTo)
			return query
		},
		queriesEqual(a, b) {
			const ka = Object.keys(a).sort()
			const kb = Object.keys(b || {}).sort()
			if (ka.length !== kb.length) return false
			for (let i = 0; i < ka.length; i++) {
				const k = ka[i]
				if (k !== kb[i]) return false
				if (String(a[k]) !== String(b[k])) return false
			}
			return true
		},
		updateRouteQueryFromState() {
			if (this.$route.path !== '/search-trails') return
			const nextQuery = this.buildQueryFromState()
			if (this.queriesEqual(nextQuery, this.$route.query)) return
			this.$router.replace({ path: this.$route.path, query: nextQuery })
		},
		applyQueryParamsFromRoute() {
			if (this.$route.path !== '/search-trails') return
			const q = this.$route.query || {}
			if (typeof q.success !== 'undefined') {
				const val = String(q.success)
				const opt = this.successOptions.find(o => String(o.value) === val)
				this.selectedSuccessStatus = opt || null
			}
			if (typeof q.user === 'string') {
				const users = q.user.split(',').map(s => s.trim()).filter(Boolean)
				this.selectedUsers = users.map(u => ({ label: u, value: u }))
			}
			// JS dates are awful, so we first check if its a valid date and then create the date. (q.dateFrom is a ISO string)
			this.dateFrom = q.dateFrom && new Date(q.dateFrom).getDate() ? new Date(q.dateFrom) : null
			this.dateTo = q.dateTo && new Date(q.dateTo).getDate() ? new Date(q.dateTo) : null
			this.searchTermFilter = q.searchTerm || ''
			this.executionTimeFrom = q.executionTimeFrom || ''
			this.executionTimeTo = q.executionTimeTo || ''
			this.resultCountFrom = q.resultCountFrom || ''
			this.resultCountTo = q.resultCountTo || ''
			const applyRegister = () => {
				if (!q.register) return true
				if (!registerStore.list.length) return false
				const reg = registerStore.list.find(r => String(r.id) === String(q.register))
				if (reg) registerStore.setItem(reg)
				return true
			}
			const applySchema = () => {
				if (!q.schema) return true
				if (!schemaStore.list.length) return false
				const sch = schemaStore.list.find(s => String(s.id) === String(q.schema))
				if (sch) schemaStore.setItem(sch)
				return true
			}
			const tryApply = (attempt = 0) => {
				const rOk = applyRegister()
				const sOk = applySchema()
				if (rOk && sOk) {
					this.applyFilters()
					this.loadActivityData()
					return
				}
				if (attempt < 10) setTimeout(() => tryApply(attempt + 1), 200)
			}
			tryApply()
		},
	},
}
</script>

<style scoped>
.filterSection {
	padding: 12px 0;
	border-bottom: 1px solid var(--color-border);
}

.filterSection:last-child {
	border-bottom: none;
}

.filterSection h3 {
	color: var(--color-text-maxcontrast);
	font-size: 14px;
	font-weight: bold;
	padding: 0 16px;
	margin: 0 0 12px 0;
}

.filterGroup {
	display: flex;
	flex-direction: column;
	gap: 8px;
	padding: 0 16px;
	margin-bottom: 16px;
}

.filterGroup label {
	font-size: 0.9em;
	color: var(--color-text-maxcontrast);
}

.rangeFilter {
	display: flex;
	gap: 8px;
}

.rangeFilter input {
	flex: 1;
}

.actionGroup {
	padding: 0 16px;
	margin-bottom: 12px;
}

.statusOption.true {
	color: var(--color-success);
}

.statusOption.false {
	color: var(--color-error);
}

.filter-hint {
	margin: 8px 16px;
}

/* Add some spacing between select inputs */
:deep(.v-select) {
	margin-bottom: 8px;
}
</style>

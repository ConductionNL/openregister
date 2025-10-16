<script setup>
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
						:disabled="!registerStore.registerItem"
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
						<template #option="{ option }">
							<div class="statusOption" :class="option.value">
								{{ option.label }}
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
						<template #option="{ option }">
							{{ option.label }}
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

			<!-- Statistics Section -->
			<div class="statsSection">
				<h3>{{ t('openregister', 'Search Trail Statistics') }}</h3>
				<div class="statGrid">
					<div class="statCard">
						<div class="statNumber">
							{{ totalSearchTrails }}
						</div>
						<div class="statLabel">
							{{ t('openregister', 'Total Searches') }}
						</div>
					</div>
					<div class="statCard">
						<div class="statNumber">
							{{ totalResults }}
						</div>
						<div class="statLabel">
							{{ t('openregister', 'Total Results') }}
						</div>
					</div>
					<div class="statCard">
						<div class="statNumber">
							{{ averageResultsPerSearch }}
						</div>
						<div class="statLabel">
							{{ t('openregister', 'Avg Results/Search') }}
						</div>
					</div>
					<div class="statCard">
						<div class="statNumber">
							{{ averageExecutionTime }}ms
						</div>
						<div class="statLabel">
							{{ t('openregister', 'Avg Execution Time') }}
						</div>
					</div>
					<div class="statCard">
						<div class="statNumber">
							{{ (successRate * 100).toFixed(1) }}%
						</div>
						<div class="statLabel">
							{{ t('openregister', 'Success Rate') }}
						</div>
					</div>
					<div class="statCard">
						<div class="statNumber">
							{{ uniqueSearchTerms }}
						</div>
						<div class="statLabel">
							{{ t('openregister', 'Unique Search Terms') }}
						</div>
					</div>
					<div class="statCard">
						<div class="statNumber">
							{{ avgSearchesPerSession }}
						</div>
						<div class="statLabel">
							{{ t('openregister', 'Avg Searches/Session') }}
						</div>
					</div>
					<div class="statCard">
						<div class="statNumber">
							{{ avgObjectViewsPerSession }}
						</div>
						<div class="statLabel">
							{{ t('openregister', 'Avg Object Views/Session') }}
						</div>
					</div>
				</div>
			</div>

			<!-- Query Complexity Distribution -->
			<div class="complexitySection">
				<h4>{{ t('openregister', 'Query Complexity Distribution') }}</h4>
				<div class="complexityBars">
					<div class="complexityBar">
						<div class="complexityLabel">
							<span
								:title="t('openregister', 'Simple queries: Basic text searches with minimal parameters (e.g., single search term, no advanced filters)')"
								class="complexity-label-with-tooltip">
								{{ t('openregister', 'Simple') }}
							</span>
							<span>{{ queryComplexity.simple }}</span>
						</div>
						<div class="complexityProgress">
							<div class="complexityProgressBar simple" :style="{ width: getComplexityPercentage('simple') + '%' }" />
						</div>
					</div>
					<div class="complexityBar">
						<div class="complexityLabel">
							<span
								:title="t('openregister', 'Medium queries: Searches with some filtering or multiple parameters (e.g., date ranges, specific registers/schemas)')"
								class="complexity-label-with-tooltip">
								{{ t('openregister', 'Medium') }}
							</span>
							<span>{{ queryComplexity.medium }}</span>
						</div>
						<div class="complexityProgress">
							<div class="complexityProgressBar medium" :style="{ width: getComplexityPercentage('medium') + '%' }" />
						</div>
					</div>
					<div class="complexityBar">
						<div class="complexityLabel">
							<span
								:title="t('openregister', 'Complex queries: Advanced searches with multiple filters, operators, and complex parameter combinations')"
								class="complexity-label-with-tooltip">
								{{ t('openregister', 'Complex') }}
							</span>
							<span>{{ queryComplexity.complex }}</span>
						</div>
						<div class="complexityProgress">
							<div class="complexityProgressBar complex" :style="{ width: getComplexityPercentage('complex') + '%' }" />
						</div>
					</div>
				</div>
			</div>

			<!-- Popular Search Terms -->
			<div class="popularTermsSection">
				<h4>{{ t('openregister', 'Popular Search Terms') }}</h4>
				<div class="popularTermsList">
					<NcListItem v-for="(term, index) in popularTerms"
						:key="index"
						:name="term.term"
						:bold="false">
						<template #icon>
							<MagnifyPlus :size="32" />
						</template>
						<template #subname>
							{{ t('openregister', '{count} searches', { count: term.count }) }}
						</template>
					</NcListItem>
				</div>
			</div>

			<!-- Register/Schema Usage -->
			<div class="registerSchemaSection">
				<h4>{{ t('openregister', 'Register/Schema Usage') }}</h4>
				<div class="registerSchemaList">
					<NcListItem v-for="(stat, index) in registerSchemaStats"
						:key="index"
						:name="getRegisterSchemaName(stat)"
						:bold="false">
						<template #icon>
							<DatabaseOutline :size="32" />
						</template>
						<template #subname>
							{{ t('openregister', '{count} searches', { count: stat.count }) }}
						</template>
					</NcListItem>
				</div>
			</div>
		</NcAppSidebarTab>

		<NcAppSidebarTab id="analytics-tab" :name="t('openregister', 'Analytics')" :order="3">
			<template #icon>
				<TrendingUp :size="20" />
			</template>

			<!-- Analytics Section -->
			<div class="analyticsSection">
				<h3>{{ t('openregister', 'Search Analytics') }}</h3>

				<!-- Activity Period Selector -->
				<div class="filterGroup">
					<label for="activityPeriodSelect">{{ t('openregister', 'Activity Period') }}</label>
					<NcSelect
						id="activityPeriodSelect"
						v-model="selectedActivityPeriod"
						:options="activityPeriodOptions"
						:placeholder="t('openregister', 'Select period')"
						@input="loadActivityData">
						<template #option="{ option }">
							{{ option.label }}
						</template>
					</NcSelect>
				</div>

				<!-- Activity Chart/Data -->
				<div class="activityData">
					<h4>{{ t('openregister', 'Search Activity') }}</h4>
					<div v-if="searchTrailStore.activityLoading" class="loadingSpinner">
						<NcLoadingIcon :size="32" />
					</div>
					<div v-else-if="currentActivityData.length > 0" class="activityList">
						<div v-for="(activity, index) in currentActivityData"
							:key="index"
							class="activityItem">
							<span class="activityPeriod">{{ formatActivityPeriod(activity.period) }}</span>
							<span class="activityCount">{{ activity.searches }} searches</span>
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
						<NcListItem v-for="(agent, index) in userAgentStats"
							:key="index"
							:name="getBrowserName(agent)"
							:bold="false">
							<template #icon>
								<Monitor :size="32" />
							</template>
							<template #subname>
								{{ t('openregister', '{count} searches', { count: agent.count }) }}
							</template>
						</NcListItem>
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
	NcSelect,
	NcNoteCard,
	NcButton,
	NcListItem,
	NcDateTimePickerNative,
	NcTextField,
	NcLoadingIcon,
} from '@nextcloud/vue'
import FilterOutline from 'vue-material-design-icons/FilterOutline.vue'
import ChartLine from 'vue-material-design-icons/ChartLine.vue'
import TrendingUp from 'vue-material-design-icons/TrendingUp.vue'
import MagnifyPlus from 'vue-material-design-icons/MagnifyPlus.vue'
import DatabaseOutline from 'vue-material-design-icons/DatabaseOutline.vue'
import Monitor from 'vue-material-design-icons/Monitor.vue'
import FilterOffOutline from 'vue-material-design-icons/FilterOffOutline.vue'

export default {
	name: 'SearchTrailSideBar',
	components: {
		NcAppSidebar,
		NcAppSidebarTab,
		NcSelect,
		NcNoteCard,
		NcButton,
		NcListItem,
		NcDateTimePickerNative,
		NcTextField,
		NcLoadingIcon,
		FilterOutline,
		ChartLine,
		TrendingUp,
		MagnifyPlus,
		DatabaseOutline,
		Monitor,
		FilterOffOutline,
	},
	data() {
		return {
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
		successOptions() {
			return [
				{
					label: this.t('openregister', 'Successful'),
					value: 'true',
				},
				{
					label: this.t('openregister', 'Failed'),
					value: 'false',
				},
			]
		},
		activityPeriodOptions() {
			return [
				{
					label: this.t('openregister', 'Hourly'),
					value: 'hourly',
				},
				{
					label: this.t('openregister', 'Daily'),
					value: 'daily',
				},
				{
					label: this.t('openregister', 'Weekly'),
					value: 'weekly',
				},
				{
					label: this.t('openregister', 'Monthly'),
					value: 'monthly',
				},
			]
		},
		registerOptions() {
			return {
				options: registerStore.registerList.map(register => ({
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
			if (!registerStore.registerItem) return { options: [] }

			return {
				options: schemaStore.schemaList
					.filter(schema => registerStore.registerItem.schemas.includes(schema.id))
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
			if (!registerStore.registerItem) return null
			const register = registerStore.registerItem
			return {
				value: register.id,
				label: register.title,
				title: register.title,
				register,
			}
		},
		selectedSchemaValue() {
			if (!schemaStore.schemaItem) return null
			const schema = schemaStore.schemaItem
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
			// Get unique users from search trail list
			const users = [...new Set(searchTrailStore.searchTrailList.map(trail => trail.userName || trail.user).filter(Boolean))]
			return users.map(user => ({
				label: user,
				value: user,
			}))
		},
	},
	watch: {
		// Keep component/store in sync with URL query params (single source of truth)
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
		// Watch for changes in the global stores
		'registerStore.registerItem'() {
			this.applyFilters()
		},
		'schemaStore.schemaItem'() {
			this.applyFilters()
		},
	},
	mounted() {
		// Load required data
		if (!registerStore.registerList.length) {
			registerStore.refreshRegisterList()
		}

		if (!schemaStore.schemaList.length) {
			schemaStore.refreshSchemaList()
		}

		// Load initial search trail data
		this.loadSearchTrailData()
		this.loadStatistics()
		this.loadPopularTerms()
		this.loadRegisterSchemaStats()
		this.loadUserAgentStats()
		this.loadActivityData()

		// Listen for filtered count updates
		this.$root.$on('search-trail-filtered-count', (count) => {
			this.filteredCount = count
		})

		// Watch store changes and update count
		this.updateFilteredCount()

		// Initialize from current URL query params
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

			// Build success filter
			if (this.selectedSuccessStatus) {
				filters.success = this.selectedSuccessStatus.value
			}

			// Build register filter
			if (registerStore.registerItem) {
				filters.register = registerStore.registerItem.id.toString()
			}

			// Build schema filter
			if (schemaStore.schemaItem) {
				filters.schema = schemaStore.schemaItem.id.toString()
			}

			// Build user filter
			if (Array.isArray(this.selectedUsers) && this.selectedUsers.length > 0) {
				const users = this.selectedUsers.slice()
				if (users.length > 0) {
					filters.user = users.map(u => u.value).join(',')
				}
			}

			// Date filters
			if (this.dateFrom) {
				filters.dateFrom = this.dateFrom
			}
			if (this.dateTo) {
				filters.dateTo = this.dateTo
			}

			// Search term filter
			if (this.searchTermFilter) {
				filters.searchTerm = this.searchTermFilter
			}

			// Execution time filters
			if (this.executionTimeFrom) {
				filters.executionTimeFrom = this.executionTimeFrom
			}
			if (this.executionTimeTo) {
				filters.executionTimeTo = this.executionTimeTo
			}

			// Result count filters
			if (this.resultCountFrom) {
				filters.resultCountFrom = this.resultCountFrom
			}
			if (this.resultCountTo) {
				filters.resultCountTo = this.resultCountTo
			}

			// Set filters in store and refresh data
			searchTrailStore.setSearchTrailFilters(filters)
			searchTrailStore.refreshSearchTrailList()

			// Also emit for legacy compatibility
			this.$root.$emit('search-trail-filters-changed', filters)

			// Reflect filters in URL
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
				// Set default values on error
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
			// Reflect activity period in URL
			this.updateRouteQueryFromState()
		},
		/**
		 * Get complexity percentage for progress bar
		 * @param {string} type - The complexity type
		 * @return {number} The percentage
		 */
		getComplexityPercentage(type) {
			const total = this.queryComplexity.simple + this.queryComplexity.medium + this.queryComplexity.complex
			if (total === 0) return 0
			return Math.round((this.queryComplexity[type] / total) * 100)
		},
		/**
		 * Format activity period for display
		 * @param {string} period - The period string
		 * @return {string} Formatted period
		 */
		formatActivityPeriod(period) {
			// Format based on the selected period type
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
			registerStore.setRegisterItem(register)
			schemaStore.setSchemaItem(null) // Clear schema when register changes
			this.applyFilters()
		},
		/**
		 * Handle schema change
		 * @param {object} schema - Selected schema
		 * @return {void}
		 */
		handleSchemaChange(schema) {
			schemaStore.setSchemaItem(schema)
			this.applyFilters()
		},
		/**
		 * Get register/schema name for display
		 * @param {object} stat - The register/schema stat object
		 * @return {string} The display name
		 */
		getRegisterSchemaName(stat) {
			const register = registerStore.registerList.find(r => r.id === stat.register)
			const schema = schemaStore.schemaList.find(s => s.id === stat.schema)

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
		// Build URL query from current component/store state
		buildQueryFromState() {
			const query = {}
			// Filters
			if (registerStore.registerItem) query.register = String(registerStore.registerItem.id)
			if (schemaStore.schemaItem) query.schema = String(schemaStore.schemaItem.id)
			if (this.selectedSuccessStatus && this.selectedSuccessStatus.value) query.success = String(this.selectedSuccessStatus.value)
			if (Array.isArray(this.selectedUsers) && this.selectedUsers.length > 0) query.user = this.selectedUsers.map(u => u.value || u).join(',')
			if (this.dateFrom) query.dateFrom = this.dateFrom
			if (this.dateTo) query.dateTo = this.dateTo
			if (this.searchTermFilter) query.searchTerm = this.searchTermFilter
			if (this.executionTimeFrom) query.executionTimeFrom = String(this.executionTimeFrom)
			if (this.executionTimeTo) query.executionTimeTo = String(this.executionTimeTo)
			if (this.resultCountFrom) query.resultCountFrom = String(this.resultCountFrom)
			if (this.resultCountTo) query.resultCountTo = String(this.resultCountTo)
			if (this.selectedActivityPeriod && this.selectedActivityPeriod.value) query.activityPeriod = this.selectedActivityPeriod.value
			return query
		},
		// Shallow compare queries
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
		// Write current state into URL
		updateRouteQueryFromState() {
			if (this.$route.path !== '/search-trails') return
			const nextQuery = this.buildQueryFromState()
			if (this.queriesEqual(nextQuery, this.$route.query)) return
			this.$router.replace({ path: this.$route.path, query: nextQuery })
		},
		// Read URL query and apply to component/store
		applyQueryParamsFromRoute() {
			if (this.$route.path !== '/search-trails') return
			const q = this.$route.query || {}
			// Success status
			if (typeof q.success !== 'undefined') {
				const val = String(q.success)
				const opt = this.successOptions.find(o => String(o.value) === val)
				this.selectedSuccessStatus = opt || null
			}
			// Users
			if (typeof q.user === 'string') {
				const users = q.user.split(',').map(s => s.trim()).filter(Boolean)
				this.selectedUsers = users.map(u => ({ label: u, value: u }))
			}
			// Dates and fields
			this.dateFrom = q.dateFrom || null
			this.dateTo = q.dateTo || null
			this.searchTermFilter = q.searchTerm || ''
			this.executionTimeFrom = q.executionTimeFrom || ''
			this.executionTimeTo = q.executionTimeTo || ''
			this.resultCountFrom = q.resultCountFrom || ''
			this.resultCountTo = q.resultCountTo || ''
			// Activity period
			if (typeof q.activityPeriod === 'string') {
				const ap = this.activityPeriodOptions.find(o => o.value === q.activityPeriod)
				if (ap) this.selectedActivityPeriod = ap
			}
			// Registers & schemas depend on lists
			const applyRegister = () => {
				if (!q.register) return true
				if (!registerStore.registerList.length) return false
				const reg = registerStore.registerList.find(r => String(r.id) === String(q.register))
				if (reg) registerStore.setRegisterItem(reg)
				return true
			}
			const applySchema = () => {
				if (!q.schema) return true
				if (!schemaStore.schemaList.length) return false
				const sch = schemaStore.schemaList.find(s => String(s.id) === String(q.schema))
				if (sch) schemaStore.setSchemaItem(sch)
				return true
			}
			const tryApply = (attempt = 0) => {
				const rOk = applyRegister()
				const sOk = applySchema()
				// Apply store filters once selection ready
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
.filterSection,
.statsSection,
.analyticsSection {
	padding: 12px 0;
	border-bottom: 1px solid var(--color-border);
}

.filterSection:last-child,
.statsSection:last-child,
.analyticsSection:last-child {
	border-bottom: none;
}

.filterSection h3,
.statsSection h3,
.analyticsSection h3 {
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

.statsSection {
	padding: 16px;
}

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

.statNumber {
	font-size: 1.5rem;
	font-weight: bold;
	color: var(--color-primary);
	margin-bottom: 4px;
}

.statLabel {
	font-size: 0.8rem;
	color: var(--color-text-maxcontrast);
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
</style>

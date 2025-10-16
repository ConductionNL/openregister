<script setup>
import { navigationStore, objectStore, registerStore, schemaStore } from '../../store/store.js'
</script>

<template>
	<NcAppSidebar
		ref="sidebar"
		name="Search Objects"
		subtitle="Select register, schema and search"
		subname="Within the federative network"
		:open="navigationStore.sidebarState.search"
		@update:open="(e) => navigationStore.setSidebarState('search', e)">
		<!-- Filter Section -->
		<div class="filterSection">
			<h3>{{ t('openregister', 'Filter Objects') }}</h3>
			<div class="filterGroup">
				<label for="registerSelect">{{ t('openregister', 'Register') }}</label>
				<NcSelect v-bind="registerOptions"
					id="registerSelect"
					:model-value="selectedRegisterValue"
					:loading="registerLoading"
					:disabled="registerLoading"
					:input-label="t('openregister', 'Register')"
					placeholder="Select a register"
					@update:model-value="handleRegisterChange" />
			</div>
			<div class="filterGroup">
				<label for="schemaSelect">{{ t('openregister', 'Schema') }}</label>
				<NcSelect v-bind="schemaOptions"
					id="schemaSelect"
					:model-value="selectedSchemaValue"
					:loading="schemaLoading"
					:disabled="!registerStore.registerItem || schemaLoading"
					:input-label="t('openregister', 'Schema')"
					placeholder="Select a schema"
					@update:model-value="handleSchemaChange" />
			</div>
			<div class="filterGroup">
				<label for="sourceSelect">{{ t('openregister', 'Data Source') }}</label>
				<NcSelect
					id="sourceSelect"
					:model-value="selectedSourceValue"
					:options="sourceOptions"
					:input-label="t('openregister', 'Data Source')"
					placeholder="Select data source"
					@update:model-value="handleSourceChange" />
			</div>
		</div>

		<!-- Search Section -->
		<div class="section">
			<h3 class="sectionTitle">
				{{ t('openregister', 'Search') }}
			</h3>
			<div class="search-input-container">
				<NcTextField
					v-model="searchQuery"
					:placeholder="searchPlaceholder"
					:disabled="searchLoading"
					@keyup.enter="performSearch" />
				<NcButton
					type="primary"
					:disabled="!canSearch || searchLoading"
					@click="performSearch">
					<template #icon>
						<NcLoadingIcon v-if="searchLoading" :size="20" />
						<Magnify v-else :size="20" />
					</template>
					{{ t('openregister', 'Search') }}
				</NcButton>
			</div>
			<div v-if="searchTerms.length > 0" class="search-terms">
				<span class="search-terms-label">{{ t('openregister', 'Search terms:') }}</span>
				<div class="search-chips">
					<div
						v-for="(term, index) in searchTerms"
						:key="index"
						class="search-chip">
						<span class="chip-text">{{ term }}</span>
						<button class="chip-remove" @click="removeSearchTerm(index)">
							<Close :size="16" />
						</button>
					</div>
				</div>
			</div>
			<div v-if="lastSearchStats" class="search-stats">
				{{ t('openregister', 'Found {total} objects in {time}ms', lastSearchStats) }}
			</div>
		</div>

		<!-- Unified Faceting Section -->
		<div class="section">
			<h3 class="sectionTitle">
				{{ t('openregister', 'Advanced Filters') }}
			</h3>

			<!-- Stage 1: Facet Discovery -->
			<div v-if="!facetableFields && canSearch && !isDatabaseSource" class="facets-discovery-container">
				<NcButton
					type="secondary"
					:disabled="facetsLoading"
					@click="discoverFacets">
					<template #icon>
						<NcLoadingIcon v-if="facetsLoading" :size="20" />
						<FilterIcon v-else :size="20" />
					</template>
					{{ t('openregister', 'Load Advanced Filters') }}
				</NcButton>
				<p class="facets-description">
					{{ t('openregister', 'Load advanced filters with live data from your search index') }}
				</p>
			</div>

			<!-- Database Source Notice -->
			<div v-if="isDatabaseSource && canSearch" class="database-source-notice">
				<p class="database-notice-text">
					{{ t('openregister', 'Advanced filters are not available when using database source. Switch to Auto or SOLR Index for filtering options.') }}
				</p>
			</div>

			<!-- Loading -->
			<div v-if="facetsLoading && !facetableFields" class="loading-container">
				<NcLoadingIcon :size="20" />
				<span>{{ t('openregister', 'Loading advanced filters...') }}</span>
			</div>

			<!-- Available Facets (Stage 1 Complete) -->
			<div v-else-if="facetableFields && !isDatabaseSource" class="available-facets-container">
				<h4 class="available-facets-title">
					{{ t('openregister', 'Available Filters') }}
				</h4>

				<!-- Metadata Facets -->
				<div v-if="facetableFields['@self']" class="facet-category">
					<h5 class="facet-category-title">
						{{ t('openregister', 'Metadata Filters') }}
					</h5>
					<div class="facet-checkboxes">
						<div v-for="(field, fieldName) in facetableFields['@self']" :key="`@self.${fieldName}`" class="facet-checkbox">
							<input
								:id="`facet-@self-${fieldName}`"
								v-model="enabledFacets[`@self.${fieldName}`]"
								type="checkbox"
								@change="toggleFacet(`@self.${fieldName}`, field)">
							<label :for="`facet-@self-${fieldName}`" class="facet-checkbox-label">
								{{ field.description || fieldName }}
								<span class="facet-types">({{ field.facet_types.join(', ') }})</span>
							</label>
						</div>
					</div>
				</div>

				<!-- Object Field Facets -->
				<div v-if="facetableFields.object_fields" class="facet-category">
					<h5 class="facet-category-title">
						{{ t('openregister', 'Content Filters') }}
					</h5>
					<div class="facet-checkboxes">
						<div v-for="(field, fieldName) in facetableFields.object_fields" :key="fieldName" class="facet-checkbox">
							<input
								:id="`facet-${fieldName}`"
								v-model="enabledFacets[fieldName]"
								type="checkbox"
								@change="toggleFacet(fieldName, field)">
							<label :for="`facet-${fieldName}`" class="facet-checkbox-label">
								{{ field.title || field.description || fieldName }}
								<span class="facet-types">({{ field.facet_types.join(', ') }})</span>
							</label>
						</div>
					</div>
				</div>

				<!-- Info about loaded facets -->
				<div v-if="facetData && Object.keys(facetData).length > 0" class="facets-loaded-info">
					<p class="facets-loaded-description">
						{{ t('openregister', 'Filter data loaded automatically. Use the filters below to refine your search.') }}
					</p>
				</div>
			</div>

			<!-- Stage 2 Loading -->
			<div v-if="facetDataLoading" class="loading-container">
				<NcLoadingIcon :size="20" />
				<span>{{ t('openregister', 'Loading filter data...') }}</span>
			</div>

			<!-- Stage 2: Facet Data (Active Filters) -->
			<div v-else-if="facetData && Object.keys(facetData).length > 0 && !isDatabaseSource" class="active-facets-container">
				<h4 class="active-facets-title">
					{{ t('openregister', 'Active Filters') }}
				</h4>

				<!-- Metadata facets (@self) -->
				<div v-for="(facet, field) in facetData?.['@self'] || {}" :key="`@self.${field}`" class="facet-group">
					<label class="facet-label">{{ getFacetLabel(field, facet, true) }}</label>
					<NcSelect
						:model-value="facetFilters[`@self.${field}`] || []"
						:options="getFacetOptions(facet)"
						:multiple="true"
						:placeholder="t('openregister', 'Select options...')"
						:input-label="getFacetLabel(field, facet, true)"
						@update:model-value="(value) => updateFacetFilter(`@self.${field}`, value)" />
				</div>

				<!-- Object field facets -->
				<div v-for="(facet, field) in facetData?.object_fields || {}" :key="field" class="facet-group">
					<label class="facet-label">{{ getFacetLabel(field, facet, false) }}</label>
					<NcSelect
						:model-value="facetFilters[field] || []"
						:options="getFacetOptions(facet)"
						:multiple="true"
						:placeholder="t('openregister', 'Select options...')"
						:input-label="getFacetLabel(field, facet, false)"
						@update:model-value="(value) => updateFacetFilter(field, value)" />
				</div>

				<!-- Reset Facets Button -->
				<div class="facets-reset-container">
					<NcButton
						type="secondary"
						@click="resetFacets">
						{{ t('openregister', 'Reset Filters') }}
					</NcButton>
				</div>
			</div>
		</div>

		<div class="section">
			<NcNoteCard type="info" class="search-hint">
				{{ t('openregister', 'Type search terms and press Enter or click Add to add them. Click Search to find objects.') }}
			</NcNoteCard>
		</div>
	</NcAppSidebar>
</template>

<script>
import { NcAppSidebar, NcSelect, NcNoteCard, NcTextField, NcButton, NcLoadingIcon } from '@nextcloud/vue'
import Magnify from 'vue-material-design-icons/Magnify.vue'
import Close from 'vue-material-design-icons/Close.vue'
import FilterIcon from 'vue-material-design-icons/Filter.vue'
import { translate as t } from '@nextcloud/l10n'

export default {
	name: 'SearchSideBar',
	components: {
		NcAppSidebar,
		NcSelect,
		NcNoteCard,
		NcTextField,
		NcButton,
		NcLoadingIcon,
		Magnify,
		Close,
		FilterIcon,
	},
	data() {
		return {
			registerLoading: false,
			schemaLoading: false,
			searchQuery: '',
			searchTerms: [],
			searchLoading: false,
			lastSearchStats: null,
			// Unified Faceting System
			facetableFields: null, // Stage 1: Available facetable fields
			facetData: null, // Stage 2: Actual facet data with counts
			facetFilters: {}, // Applied facet filters
			enabledFacets: {}, // Which facets user has enabled
			facetsLoading: false, // Stage 1 loading
			facetDataLoading: false, // Stage 2 loading
			selectedSource: 'auto', // 'auto', 'database', 'index'
		}
	},
	computed: {
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

		canSearch() {
			// Add null checks to prevent undefined errors
			// Always allow search if register and schema are selected
			return registerStore.registerItem && schemaStore.schemaItem
		},
		searchPlaceholder() {
			return this.searchTerms.length > 0 ? 'Add more search terms...' : 'Type to search...'
		},
		sourceOptions() {
			return [
				{
					value: 'auto',
					label: '🤖 Auto (Intelligent)',
					description: 'Automatically chooses the best data source',
				},
				{
					value: 'index',
					label: '🔍 SOLR Index',
					description: 'Fast search with advanced features, field weighting, and faceting',
				},
				{
					value: 'database',
					label: '💾 Database',
					description: 'Direct database queries (slower but always available)',
				},
			]
		},
		selectedSourceValue() {
			const source = this.sourceOptions.find(option => option.value === this.selectedSource)
			return source || this.sourceOptions[0]
		},
		isDatabaseSource() {
			return this.selectedSource === 'database'
		},
		hasEnabledFacets() {
			return Object.values(this.enabledFacets).some(enabled => enabled)
		},
	},
	watch: {
		// React to query param changes as single source of truth (only on /tables)
		'$route.query': {
			handler() {
				if (this.$route.path !== '/tables') return
				this.applyQueryParamsFromRoute()
			},
			deep: true,
		},
		// Watch for schema changes to initialize properties
		// Use immediate: true equivalent in mounted
		// This watcher will update properties when schema changes
		'$root.schemaStore.schemaItem': {
			handler(newSchema) {
				if (newSchema) {
					objectStore.initializeProperties(newSchema)
				} else {
					objectStore.properties = {}
					objectStore.initializeColumnFilters()
				}
			},
			deep: true,
		},
	},
	mounted() {
		objectStore.initializeColumnFilters()
		this.registerLoading = true
		this.schemaLoading = true

		// Only load lists if they're empty
		if (!registerStore.registerList.length) {
			registerStore.refreshRegisterList()
				.finally(() => (this.registerLoading = false))
		} else {
			this.registerLoading = false
		}

		if (!schemaStore.schemaList.length) {
			schemaStore.refreshSchemaList()
				.finally(() => (this.schemaLoading = false))
		} else {
			this.schemaLoading = false
		}

		// Load objects if register and schema are already selected
		if (registerStore.registerItem && schemaStore.schemaItem) {
			// Set loading state for initial load
			objectStore.loading = true
			objectStore.refreshObjectList({
				register: registerStore.registerItem.id,
				schema: schemaStore.schemaItem.id,
				includeFacets: false, // Don't include facets by default on initial load
			})
				.finally(() => {
					objectStore.loading = false
				})
		}

		// Initialize from query params after lists potentially load
		this.applyQueryParamsFromRoute()
	},
	methods: {
		t,
		// Build query params from current sidebar state
		buildQueryFromState() {
			const query = {}
			if (registerStore.registerItem && registerStore.registerItem.id) {
				query.register = String(registerStore.registerItem.id)
			}
			if (schemaStore.schemaItem && schemaStore.schemaItem.id) {
				query.schema = String(schemaStore.schemaItem.id)
			}
			if (this.searchTerms && this.searchTerms.length > 0) {
				query.q = this.searchTerms.join(',')
			}
			if (this.selectedSource && this.selectedSource !== 'auto') {
				query.source = this.selectedSource
			}
			return query
		},
		// Compare two query objects for equality (shallow, keys/values as strings)
		queriesEqual(a, b) {
			const ka = Object.keys(a).sort()
			const kb = Object.keys(b).sort()
			if (ka.length !== kb.length) return false
			for (let i = 0; i < ka.length; i++) {
				if (ka[i] !== kb[i]) return false
				if (String(a[ka[i]]) !== String(b[kb[i]])) return false
			}
			return true
		},
		// Write current state to URL query (only on /tables)
		updateRouteQueryFromState() {
			if (this.$route.path !== '/tables') return
			const nextQuery = this.buildQueryFromState()
			if (this.queriesEqual(nextQuery, this.$route.query || {})) return
			this.$router.replace({
				path: this.$route.path,
				query: nextQuery,
			})
		},
		// Apply URL query params into component/store state
		applyQueryParamsFromRoute() {
			if (this.$route.path !== '/tables') return
			const { register, schema, q, source } = this.$route.query || {}
			// Source
			if (source) {
				this.selectedSource = String(source)
			}
			// Search terms
			if (typeof q === 'string') {
				const terms = q.split(',').map(s => s.trim()).filter(Boolean)
				this.searchTerms = Array.from(new Set(terms))
			}
			// Registers and schemas depend on lists being loaded
			const applyRegister = () => {
				if (!register) return true
				if (!registerStore.registerList.length) return false
				const reg = registerStore.registerList.find(r => String(r.id) === String(register))
				if (reg) registerStore.setRegisterItem(reg)
				return true
			}
			const applySchema = () => {
				if (!schema) return true
				if (!schemaStore.schemaList.length) return false
				const sch = schemaStore.schemaList.find(s => String(s.id) === String(schema))
				if (sch) schemaStore.setSchemaItem(sch)
				return true
			}
			// Try apply now, or retry shortly if lists not yet loaded
			const tryApply = (attempt = 0) => {
				const regOk = applyRegister()
				const schOk = applySchema()
				if (regOk && schOk) {
					// If both selected, perform search
					if (registerStore.registerItem && schemaStore.schemaItem) {
						this.performSearchWithFacets()
					}
					return
				}
				if (attempt < 10) {
					setTimeout(() => tryApply(attempt + 1), 200)
				}
			}
			tryApply()
		},
		handleRegisterChange(option) {
			// Set loading state when register changes
			objectStore.loading = true

			try {
				registerStore.setRegisterItem(option)
				schemaStore.setSchemaItem(null)

				// Clear object list when register changes
				objectStore.setObjectList({
					results: [],
					total: 0,
					page: 1,
					pages: 0,
					limit: 20,
					offset: 0,
				})

				// Clear all facet data
				this.resetFacets()
				this.facetableFields = null
				// Reflect change in URL
				this.updateRouteQueryFromState()

			} finally {
				// Clear loading state after register change is complete
				objectStore.loading = false
			}
		},
		async handleSchemaChange(option) {
			// Set loading state when schema changes
			objectStore.loading = true

			try {
				schemaStore.setSchemaItem(option)
				if (option) {
					objectStore.initializeProperties(option)
					// Just load the object list without facets - user can enable facets later if needed
					await objectStore.refreshObjectList({
						register: registerStore.registerItem.id,
						schema: option.id,
						includeFacets: false, // Don't include facets by default
					})
				} else {
					// Clear object list when schema is cleared
					objectStore.setObjectList({
						results: [],
						total: 0,
						page: 1,
						pages: 0,
						limit: 20,
						offset: 0,
					})

					// Clear all facet data
					this.resetFacets()
					this.facetableFields = null
				}
			} finally {
				// Clear loading state after schema change is complete
				objectStore.loading = false
			}
			// Reflect change in URL
			this.updateRouteQueryFromState()
		},
		handleSourceChange(option) {
			this.selectedSource = option.value

			// If we have register and schema selected, refresh the search with new source
			if (this.canSearch) {
				this.performSearchWithFacets()
			}
			// Reflect change in URL
			this.updateRouteQueryFromState()
		},
		handleSearchInput() {
			// Parse search terms from input (support comma and space separation)
			const inputTerms = this.searchQuery
				.split(/[,\s]+/)
				.map(term => term.trim())
				.filter(term => term.length > 0)

			// Find terms that are new (not already in searchTerms)
			const newTerms = inputTerms.filter(term => !this.searchTerms.includes(term))

			// Add only new terms to existing ones
			if (newTerms.length > 0) {
				this.searchTerms = [...this.searchTerms, ...newTerms]
			}
		},
		addSearchTerms() {
			// This method adds terms from the input to the existing search terms
			this.handleSearchInput()
			// Clear the input after adding terms
			this.searchQuery = ''
			// Reflect change in URL
			this.updateRouteQueryFromState()
		},
		async removeSearchTerm(index) {
			this.searchTerms.splice(index, 1)
			this.searchQuery = this.searchTerms.join(', ')

			// Automatically apply filters after removing a term
			// This will either search with remaining terms or show all results if no terms left
			if (this.canSearch) {
				// applyFacetFilters now manages objectStore.loading state
				await this.applyFacetFilters()
			}
			// Reflect change in URL
			this.updateRouteQueryFromState()
		},
		async performSearch() {
			if (!this.canSearch) return

			// Add any terms from the input to the search terms
			this.handleSearchInput()
			// Clear the input after adding terms
			this.searchQuery = ''

			// Start performance timing
			const startTime = performance.now()

			try {
				this.searchLoading = true
				objectStore.loading = true
				this.lastSearchStats = null

				// Apply all filters (search terms + facet filters) and perform search with facets
				await this.performSearchWithFacets()

				// Calculate performance statistics
				const endTime = performance.now()
				const executionTime = Math.round(endTime - startTime)

				this.lastSearchStats = {
					total: objectStore.pagination.total || 0,
					time: executionTime,
				}

			} catch (error) {
				// Search failed - error is handled by setting lastSearchStats to defaults
				this.lastSearchStats = {
					total: 0,
					time: 0,
				}
			} finally {
				this.searchLoading = false
				objectStore.loading = false
			}
			// Reflect search terms in URL
			this.updateRouteQueryFromState()
		},

		// Complete Faceting: Load everything using _facets=extend
		async discoverFacets() {
			if (!registerStore.registerItem || !schemaStore.schemaItem) return

			try {
				this.facetsLoading = true
				this.facetableFields = null
				this.facetData = null

				// Use _facets=extend to get complete faceting data in one call
				await objectStore.refreshObjectList({
					register: registerStore.registerItem.id,
					schema: schemaStore.schemaItem.id,
					_facets: 'extend',
					_limit: 0, // We only want facet data, not objects
				})

				// Extract facetable fields (for UI structure)
				this.facetableFields = objectStore.facets?.facetable || {}

				// Extract extended facet data (with counts and options)
				this.facetData = objectStore.facets?.extended || {}

				// Auto-enable all facets since we already have the data
				this.enabledFacets = {}
				Object.keys(this.facetableFields['@self'] || {}).forEach(field => {
					this.enabledFacets[`@self.${field}`] = true
				})
				Object.keys(this.facetableFields.object_fields || {}).forEach(field => {
					this.enabledFacets[field] = true
				})

				this.logger?.debug('Loaded complete faceting data', {
					metadataFields: Object.keys(this.facetableFields['@self'] || {}).length,
					objectFields: Object.keys(this.facetableFields.object_fields || {}).length,
					facetDataLoaded: Object.keys(this.facetData).length > 0,
				})

			} catch (error) {
				console.error('Error loading complete faceting data:', error)
				this.facetableFields = null
				this.facetData = null
			} finally {
				this.facetsLoading = false
			}
		},

		// Toggle individual facet on/off
		toggleFacet(fieldName, fieldInfo) {
			// When toggling facet, clear any existing data for that field
			if (this.enabledFacets[fieldName]) {
				// Facet was enabled, now being disabled
				delete this.facetFilters[fieldName]
			}

			// The v-model will handle the enabledFacets update
		},

		// Build facet configuration from enabled facets
		buildFacetConfiguration() {
			const config = {}

			// Process enabled facets
			Object.entries(this.enabledFacets).forEach(([fieldName, enabled]) => {
				if (!enabled) return

				if (fieldName.startsWith('@self.')) {
					// Metadata facet
					const field = fieldName.replace('@self.', '')
					if (!config['@self']) config['@self'] = {}

					const fieldInfo = this.facetableFields?.['@self']?.[field]
					const facetType = fieldInfo?.facet_types?.[0] || 'terms'

					config['@self'][field] = { type: facetType }
					if (facetType === 'date_histogram') {
						config['@self'][field].interval = 'month'
					}
				} else {
					// Object field facet
					const fieldInfo = this.facetableFields?.object_fields?.[fieldName]
					const facetType = fieldInfo?.facet_types?.[0] || 'terms'

					config[fieldName] = { type: facetType }
					if (facetType === 'date_histogram') {
						config[fieldName].interval = 'month'
					}
				}
			})

			return { _facets: config }
		},

		// Reset all facets
		resetFacets() {
			this.enabledFacets = {}
			this.facetFilters = {}
			this.facetData = null
			this.facetDataLoading = false
		},

		async performSearchWithFacets() {
			// Perform search with facet configuration to get both results and facet data
			if (!registerStore.registerItem || !schemaStore.schemaItem) return

			try {
				this.searchLoading = true

				// Apply current filters and search terms to objectStore
				this.applyFiltersToObjectStore()

				// Always include facets discovery when searching to show available options
				// This allows users to see what faceting options are available
				await objectStore.refreshObjectList({
					register: registerStore.registerItem.id,
					schema: schemaStore.schemaItem.id,
					includeFacets: true, // Always include facets discovery for search results
				})

				// Get the facet data from the objectStore
				// The API response has facets nested under facets.facets
				this.facetData = objectStore.facets?.facets || {}

			} catch (error) {
				// Error performing search with facets - set to null to handle gracefully
				this.facetData = null
			} finally {
				this.searchLoading = false
			}
		},

		getFacetLabel(field, facet, isMetadata) {
			// Get human-readable label for facet
			if (isMetadata) {
				const fieldInfo = this.facetableFields?.['@self']?.[field]
				return fieldInfo?.description || this.capitalizeFieldName(field)
			} else {
				const fieldInfo = this.facetableFields?.object_fields?.[field]
				return fieldInfo?.title || fieldInfo?.description || this.capitalizeFieldName(field)
			}
		},

		getFacetOptions(facet) {
			// Handle different facet data structures from _facets=extend
			if (!facet || !facet.data) return []

			const facetData = facet.data

			if (facetData.type === 'terms') {
				// Terms facet: simple buckets with value and count
				return (facetData.buckets || []).map(bucket => ({
					value: bucket.value,
					label: `${bucket.label || bucket.value} (${bucket.count || 0})`,
				}))
			} else if (facetData.type === 'range') {
				// Range facet: numeric ranges
				return (facetData.buckets || []).map(bucket => ({
					value: `${bucket.from}-${bucket.to}`,
					label: `${bucket.label || `${bucket.from} - ${bucket.to}`} (${bucket.count || 0})`,
				}))
			} else if (facetData.type === 'date_histogram') {
				// Date histogram facet: multiple time brackets
				const options = []

				// Add yearly options
				if (facetData.brackets?.yearly?.buckets) {
					facetData.brackets.yearly.buckets.forEach(bucket => {
						options.push({
							value: `year:${bucket.date}`,
							label: `${bucket.label} (${bucket.count || 0})`,
						})
					})
				}

				// Add monthly options
				if (facetData.brackets?.monthly?.buckets) {
					facetData.brackets.monthly.buckets.forEach(bucket => {
						options.push({
							value: `month:${bucket.date}`,
							label: `${bucket.label} (${bucket.count || 0})`,
						})
					})
				}

				// Add daily options (limit to recent entries to avoid clutter)
				if (facetData.brackets?.daily?.buckets) {
					facetData.brackets.daily.buckets.slice(0, 30).forEach(bucket => {
						options.push({
							value: `day:${bucket.date}`,
							label: `${bucket.label} (${bucket.count || 0})`,
						})
					})
				}

				return options
			}

			// Fallback for unknown facet types
			return []
		},

		capitalizeFieldName(fieldName) {
			// Convert field names like 'tooiCategorieNaam' to 'Tooi Categorie Naam'
			return fieldName
				.replace(/([a-z])([A-Z])/g, '$1 $2') // Split camelCase
				.replace(/^./, str => str.toUpperCase()) // Capitalize first letter
		},

		updateFacetFilter(field, selectedValues) {
			// Update facet filter and refresh search
			this.facetFilters = {
				...this.facetFilters,
				[field]: selectedValues,
			}

			// Apply facet filters to search
			this.applyFacetFilters()
		},

		applyFiltersToObjectStore() {
			// Convert facet filters to object store activeFilters format
			const activeFilters = {}

			// Add facet filters
			Object.entries(this.facetFilters).forEach(([field, values]) => {
				if (values && values.length > 0) {
					const filterValues = values.map(option => option.value || option)
					activeFilters[field] = filterValues
				}
			})

			// Add search terms and source selection to regular filters
			const filters = {}
			if (this.searchTerms.length > 0) {
				filters._search = this.searchTerms.join(' ')
			}

			// Add source selection (only if not 'auto')
			if (this.selectedSource && this.selectedSource !== 'auto') {
				filters._source = this.selectedSource
			}

			// Apply filters to object store using the existing activeFilters system
			objectStore.setActiveFilters(activeFilters)
			objectStore.setFilters(filters)
		},

		async applyFacetFilters() {
			// Apply facet filters and refresh search with facets
			// Note: performSearchWithFacets manages its own searchLoading state
			// but we also need to manage objectStore.loading for the main view
			objectStore.loading = true
			try {
				await this.performSearchWithFacets()
			} finally {
				objectStore.loading = false
			}
		},
	},
}
</script>

<style lang="scss" scoped>
.section {
	padding: 12px 16px;
	border-bottom: 1px solid var(--color-border);
}

.section:last-child {
	border-bottom: none;
}

.sectionTitle {
	color: var(--color-text-maxcontrast);
	font-size: 14px;
	font-weight: bold;
	margin: 0 0 12px 0;
}

.filterSection {
	display: flex;
	flex-direction: column;
	gap: 16px;
	padding: 0 16px 20px 16px;
	border-bottom: 1px solid var(--color-border);

	h3 {
		margin: 0;
		font-size: 1.1em;
		color: var(--color-main-text);
	}
}

.filterGroup {
	display: flex;
	flex-direction: column;
	gap: 8px;

	label {
		font-size: 0.9em;
		color: var(--color-text-maxcontrast);
	}
}

.search-input-container {
	display: flex;
	gap: 8px;
	margin-bottom: 10px;
}

.search-input-container .nc-text-field {
	flex: 1;
}

.search-terms {
	margin-bottom: 10px;
}

.search-terms-label {
	display: block;
	margin-bottom: 5px;
	font-size: 14px;
	color: var(--color-text-maxcontrast);
}

.search-chips {
	display: flex;
	flex-wrap: wrap;
	gap: 4px;
}

.search-chip {
	display: inline-flex;
	align-items: center;
	background: var(--color-primary-element-light);
	color: var(--color-primary-element-text);
	border-radius: 16px;
	padding: 4px 8px;
	font-size: 12px;
	max-width: 200px;
}

.chip-text {
	margin-right: 4px;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.chip-remove {
	background: none;
	border: none;
	cursor: pointer;
	display: flex;
	align-items: center;
	justify-content: center;
	color: inherit;
	opacity: 0.7;
	transition: opacity 0.2s;
}

.chip-remove:hover {
	opacity: 1;
}

.search-stats {
	font-size: 12px;
	color: var(--color-text-maxcontrast);
	margin-bottom: 10px;
}

.search-hint {
	margin: 0;
}

.loading-container {
	display: flex;
	align-items: center;
	gap: 8px;
	color: var(--color-text-maxcontrast);
}

.facets-container {
	display: flex;
	flex-direction: column;
	gap: 16px;
}

.facet-group {
	display: flex;
	flex-direction: column;
	gap: 8px;
}

.facet-label {
	font-size: 0.9em;
	font-weight: 500;
	color: var(--color-text-maxcontrast);
}

.no-facets-message {
	padding: 16px;
	text-align: center;
	color: var(--color-text-maxcontrast);
	font-style: italic;
}

.facets-enable-container {
	display: flex;
	flex-direction: column;
	gap: 8px;
	align-items: center;
	text-align: center;
}

.facets-enable-description {
	font-size: 12px;
	color: var(--color-text-maxcontrast);
	margin: 0;
	line-height: 1.4;
}

.database-source-notice {
	padding: 12px;
	background-color: var(--color-warning-light);
	border-radius: 6px;
	text-align: center;
}

.database-notice-text {
	font-size: 12px;
	color: var(--color-warning-dark);
	margin: 0;
	line-height: 1.4;
}

/* Unified Faceting System Styles */
.facets-discovery-container,
.facets-load-container,
.facets-reset-container {
	display: flex;
	flex-direction: column;
	gap: 8px;
	align-items: center;
	text-align: center;
	padding: 12px;
}

.facets-description,
.facets-load-description {
	font-size: 12px;
	color: var(--color-text-maxcontrast);
	margin: 0;
	line-height: 1.4;
}

.available-facets-container,
.active-facets-container {
	display: flex;
	flex-direction: column;
	gap: 16px;
}

.available-facets-title,
.active-facets-title {
	font-size: 14px;
	font-weight: 600;
	color: var(--color-main-text);
	margin: 0 0 8px 0;
}

.facet-category {
	display: flex;
	flex-direction: column;
	gap: 8px;
}

.facet-category-title {
	font-size: 13px;
	font-weight: 500;
	color: var(--color-text-maxcontrast);
	margin: 0;
}

.facet-checkboxes {
	display: flex;
	flex-direction: column;
	gap: 6px;
}

.facet-checkbox {
	display: flex;
	align-items: flex-start;
	gap: 8px;
}

.facet-checkbox input[type="checkbox"] {
	margin-top: 2px;
	flex-shrink: 0;
}

.facet-checkbox-label {
	font-size: 12px;
	color: var(--color-main-text);
	line-height: 1.4;
	cursor: pointer;
	flex: 1;
}

.facet-types {
	font-size: 11px;
	color: var(--color-text-maxcontrast);
	font-style: italic;
}

.facets-reset-container {
	border-top: 1px solid var(--color-border);
	padding-top: 12px;
	margin-top: 8px;
}
</style>

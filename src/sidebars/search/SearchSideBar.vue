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

		<!-- Faceted Search Section -->
		<div class="section">
			<h3 class="sectionTitle">
				{{ t('openregister', 'Advanced Filters') }}
			</h3>
			
			<!-- Enable Facets Button -->
			<div v-if="!facetableFields && canSearch" class="facets-enable-container">
				<NcButton
					type="secondary"
					:disabled="facetsLoading"
					@click="enableFacets">
					<template #icon>
						<NcLoadingIcon v-if="facetsLoading" :size="20" />
						<FilterIcon v-else :size="20" />
					</template>
					{{ t('openregister', 'Enable Advanced Filters') }}
				</NcButton>
				<p class="facets-enable-description">
					{{ t('openregister', 'Discover available filter options for this schema') }}
				</p>
			</div>

			<div v-if="facetsLoading" class="loading-container">
				<NcLoadingIcon :size="20" />
				<span>{{ t('openregister', 'Loading filters...') }}</span>
			</div>
			<div v-else-if="facetData && Object.keys(facetData).length > 0" class="facets-container">
				<!-- Show message if no facet data available -->
				<div v-if="!facetData || Object.keys(facetData).length === 0" class="no-facets-message">
					<p>{{ t('openregister', 'No facet filters available for this schema.') }}</p>
				</div>

				<!-- Metadata facets (@self) -->
				<div v-for="(facet, field) in facetData?.['@self'] || {}" :key="`@self.${field}`" class="facet-group">
					<label class="facet-label">{{ getFacetLabel(field, facet, true) }}</label>
					<NcSelect
						:model-value="facetFilters[`@self.${field}`] || []"
						:options="(facet?.buckets || []).map(bucket => ({
							value: bucket.key,
							label: (bucket.label || bucket.key) + ' (' + (bucket.results || bucket.doc_count || 0) + ')'
						}))"
						:multiple="true"
						:placeholder="t('openregister', 'Select options...')"
						:input-label="getFacetLabel(field, facet, true)"
						@update:model-value="(value) => updateFacetFilter(`@self.${field}`, value)" />
				</div>

				<!-- Object field facets -->
				<div v-for="(facet, field) in Object.fromEntries(Object.entries(facetData || {}).filter(([key]) => key !== '@self'))" :key="field" class="facet-group">
					<label class="facet-label">{{ getFacetLabel(field, facet, false) }}</label>
					<NcSelect
						:model-value="facetFilters[field] || []"
						:options="(facet?.buckets || []).map(bucket => ({
							value: bucket.key,
							label: (bucket.label || bucket.key) + ' (' + (bucket.results || bucket.doc_count || 0) + ')'
						}))"
						:multiple="true"
						:placeholder="t('openregister', 'Select options...')"
						:input-label="getFacetLabel(field, facet, false)"
						@update:model-value="(value) => updateFacetFilter(field, value)" />
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
			facetableFields: null,
			facetData: null,
			facetFilters: {},
			facetsLoading: false,
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
					description: 'Automatically chooses the best data source'
				},
				{
					value: 'index',
					label: '🔍 SOLR Index',
					description: 'Fast search with advanced features, field weighting, and faceting'
				},
				{
					value: 'database',
					label: '💾 Database',
					description: 'Direct database queries (slower but always available)'
				}
			]
		},
		selectedSourceValue() {
			const source = this.sourceOptions.find(option => option.value === this.selectedSource)
			return source || this.sourceOptions[0]
		},
	},
	watch: {
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
	},
	methods: {
		t,
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

				// Clear facet data
				this.facetData = null
				this.facetFilters = {}

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

					// Clear facet data
					this.facetData = null
					this.facetFilters = {}
				}
			} finally {
				// Clear loading state after schema change is complete
				objectStore.loading = false
			}
		},
		handleSourceChange(option) {
			this.selectedSource = option.value
			
			// If we have register and schema selected, refresh the search with new source
			if (this.canSearch) {
				this.performSearchWithFacets()
			}
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
		},

		async enableFacets() {
			// Enable facets by loading facetable fields and then enabling default facets
			if (!registerStore.registerItem || !schemaStore.schemaItem) return

			try {
				this.facetsLoading = true

				// First: Load facetable fields to discover what facets are available
				await this.loadFacetableFields()

				// Second: Enable some default facets to show the user what's available
				if (this.facetableFields) {
					// Enable default @self facets
					const defaultFacets = {
						'@self.register': [],
						'@self.schema': [],
						'@self.created': [],
					}

					// Only enable facets that are actually available
					Object.keys(defaultFacets).forEach(facetKey => {
						const field = facetKey.replace('@self.', '')
						if (this.facetableFields?.['@self']?.[field]) {
							this.facetFilters[facetKey] = []
						}
					})

					// Refresh search to get facet data
					await this.performSearchWithFacets()
				}

			} catch (error) {
				console.error('Error enabling facets:', error)
			} finally {
				this.facetsLoading = false
			}
		},

		async loadFacetableFields() {
			// Load facetable fields to discover what facets are available
			if (!registerStore.registerItem || !schemaStore.schemaItem) return

			try {
				// Use objectStore.getFacetableFields to discover available facetable fields
				const facetableFields = await objectStore.getFacetableFields({
					register: registerStore.registerItem.id,
					schema: schemaStore.schemaItem.id,
				})

				this.facetableFields = facetableFields || {}

			} catch (error) {
				// Error loading facetable fields - set to null to handle gracefully
				this.facetableFields = null
			}
		},

		async performSearchWithFacets() {
			// Perform search with facet configuration to get both results and facet data
			if (!registerStore.registerItem || !schemaStore.schemaItem) return

			try {
				this.searchLoading = true

				// Apply current filters and search terms to objectStore
				this.applyFiltersToObjectStore()

				// Check if we have any facet filters configured
				const hasFacetFilters = Object.keys(this.facetFilters).some(key => 
					this.facetFilters[key] && this.facetFilters[key].length > 0
				)

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
</style>

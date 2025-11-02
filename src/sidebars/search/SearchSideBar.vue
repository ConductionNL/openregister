<script setup>
import { navigationStore, objectStore, registerStore, schemaStore, viewsStore } from '../../store/store.js'
</script>

<template>
	<NcAppSidebar
		ref="sidebar"
		v-model="activeTab"
		name="Search Objects"
		subtitle="Select registers, schemas and search"
		subname="Within the federative network"
		:open="navigationStore.sidebarState.search"
		@update:open="(e) => navigationStore.setSidebarState('search', e)">
		<!-- Search Tab -->
		<NcAppSidebarTab
			id="search-tab"
			:name="t('openregister', 'Search')"
			:order="1">
			<template #icon>
				<Magnify :size="20" />
			</template>

		<!-- Save View Action -->
		<div class="saveViewSection">
			<!-- Show button when form is not visible -->
			<NcButton
				v-if="!showSaveForm"
				type="primary"
				:disabled="!canSaveView"
				@click="showSaveForm = true">
				<template #icon>
					<ContentSaveOutline :size="20" />
				</template>
				{{ t('openregister', 'Save current search as view') }}
			</NcButton>

			<!-- Show inline form when button is clicked -->
			<div v-else class="saveViewForm">
				<NcTextField
					v-model="viewName"
					:placeholder="t('openregister', 'Enter view name...')"
					:label="t('openregister', 'View Name')"
					@keyup.enter="saveView">
					<template #icon>
						<ContentSaveOutline :size="20" />
					</template>
				</NcTextField>
				<div class="saveViewFormActions">
					<NcButton
						type="primary"
						:disabled="!viewName.trim()"
						@click="saveView">
						{{ t('openregister', 'Save') }}
					</NcButton>
					<NcButton
						type="secondary"
						@click="cancelSaveView">
						{{ t('openregister', 'Cancel') }}
					</NcButton>
				</div>
			</div>

			<p v-if="!canSaveView && !showSaveForm" class="saveViewHint">
				{{ t('openregister', 'Select registers and schemas to save a view') }}
			</p>
		</div>

			<!-- Filter Section -->
			<div class="filterSection">
			<h3>{{ t('openregister', 'Filter Objects') }}</h3>
			<div class="filterGroup">
				<label for="registerSelect">{{ t('openregister', 'Registers') }}</label>
				<NcSelect v-bind="registerOptions"
					id="registerSelect"
					:model-value="selectedRegisters"
					:loading="registerLoading"
					:disabled="registerLoading"
					:input-label="t('openregister', 'Registers')"
					:multiple="true"
					:close-on-select="false"
					placeholder="Select one or more registers"
					@update:model-value="handleRegisterChange" />
			</div>
			<div class="filterGroup">
				<label for="schemaSelect">{{ t('openregister', 'Schemas') }}</label>
				<NcSelect v-bind="schemaOptions"
					id="schemaSelect"
					:model-value="selectedSchemas"
					:loading="schemaLoading"
					:disabled="selectedRegisters.length === 0 || schemaLoading"
					:input-label="t('openregister', 'Schemas')"
					:multiple="true"
					:close-on-select="false"
					placeholder="Select one or more schemas"
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
	</NcAppSidebarTab>

	<!-- Views Tab -->
	<NcAppSidebarTab
		id="views-tab"
		:name="t('openregister', 'Views')"
		:order="2">
		<template #icon>
			<ViewDashboardOutline :size="20" />
		</template>

		<div class="viewsSection">
			<h3>{{ t('openregister', 'Saved Views') }}</h3>
			<p class="viewsDescription">
				{{ t('openregister', 'Manage your saved search configurations') }}
			</p>

			<!-- Search Views -->
			<div class="viewsSearchContainer">
				<NcTextField
					v-model="viewSearchQuery"
					:placeholder="t('openregister', 'Search views...')"
					:label="t('openregister', 'Search Views')">
					<template #icon>
						<Magnify :size="20" />
					</template>
				</NcTextField>
			</div>

			<!-- Active View Badge -->
			<div v-if="viewsStore.activeView" class="activeViewBadge">
				<strong>{{ t('openregister', 'Active:') }}</strong> {{ viewsStore.activeView.name }}
			</div>

			<!-- Views Table -->
			<div v-if="viewsStore.isLoading" class="viewsLoading">
				<NcLoadingIcon :size="32" />
				<p>{{ t('openregister', 'Loading views...') }}</p>
			</div>

			<div v-else-if="filteredViews.length === 0" class="noViews">
				<NcNoteCard type="info">
					{{ viewSearchQuery ? t('openregister', 'No views match your search') : t('openregister', 'No saved views yet. Create one in the Search tab!') }}
				</NcNoteCard>
			</div>

			<div v-else class="viewsTable">
				<div
					v-for="view in filteredViews"
					:key="view.id || view.uuid"
					class="viewRow"
					:class="{ 'viewRow--active': isActiveView(view) }">
					<div class="viewRowHeader">
						<div class="viewRowTitle">
							<strong>{{ view.name }}</strong>
							<span v-if="view.isDefault" class="viewBadge viewBadge--default">
								{{ t('openregister', 'Default') }}
							</span>
							<span v-if="view.isPublic" class="viewBadge viewBadge--public">
								{{ t('openregister', 'Public') }}
							</span>
						</div>
						<div class="viewRowActions">
							<!-- Star/Favorite button -->
							<NcButton
								:type="isFavorited(view) ? 'primary' : 'secondary'"
								:aria-label="isFavorited(view) ? t('openregister', 'Remove from favorites') : t('openregister', 'Add to favorites')"
								@click="toggleFavorite(view)">
								<template #icon>
									<Star v-if="isFavorited(view)" :size="20" />
									<StarOutline v-else :size="20" />
								</template>
							</NcButton>
							
							<!-- Load View (Magnify) -->
							<NcButton
								type="secondary"
								:aria-label="t('openregister', 'Load view')"
								@click="loadView(view)">
								<template #icon>
									<Magnify :size="20" />
								</template>
							</NcButton>
							
							<!-- Edit View (Pencil) -->
							<NcButton
								type="secondary"
								:aria-label="t('openregister', 'Edit view')"
								@click="openEditDialog(view)">
								<template #icon>
									<Pencil :size="20" />
								</template>
							</NcButton>
							
							<!-- Delete View -->
							<NcButton
								type="error"
								:aria-label="t('openregister', 'Delete view')"
								@click="confirmDeleteView(view)">
								<template #icon>
									<Delete :size="20" />
								</template>
							</NcButton>
						</div>
					</div>
					<p v-if="view.description" class="viewRowDescription">
						{{ view.description }}
					</p>
					<div class="viewRowMeta">
						<span class="viewMetaItem">
							<strong>{{ t('openregister', 'Registers:') }}</strong> {{ (view.query || view.configuration)?.registers?.length || 0 }}
						</span>
						<span class="viewMetaItem">
							<strong>{{ t('openregister', 'Schemas:') }}</strong> {{ (view.query || view.configuration)?.schemas?.length || 0 }}
						</span>
						<span v-if="(view.query || view.configuration)?.searchTerms?.length" class="viewMetaItem">
							<strong>{{ t('openregister', 'Search terms:') }}</strong> {{ (view.query || view.configuration).searchTerms.length }}
						</span>
					</div>
				</div>
			</div>
		</div>
	</NcAppSidebarTab>

	<!-- Columns Tab -->
	<NcAppSidebarTab
		id="columns-tab"
		:name="t('openregister', 'Columns')"
		:order="3">
		<template #icon>
			<FormatColumns :size="20" />
		</template>

		<div class="columnsSection">
			<h3>{{ t('openregister', 'Column Visibility') }}</h3>
			<p class="columnsDescription">
				{{ t('openregister', 'Select which columns to display in the table') }}
			</p>

			<!-- Schema Properties Sections -->
			<div v-if="selectedSchemasWithProperties.length > 0">
				<div v-for="schemaData in selectedSchemasWithProperties" :key="`schema_${schemaData.id}`" class="columnGroup collapsible">
					<div class="columnGroupHeader" @click="toggleSchemaGroup(schemaData.id)">
						<ChevronDown v-if="expandedSchemas[schemaData.id]" :size="20" />
						<ChevronRight v-else :size="20" />
						<h4>{{ schemaData.title }}</h4>
					</div>
					<div v-if="expandedSchemas[schemaData.id]" class="columnGroupContent">
						<NcCheckboxRadioSwitch
							v-for="(property, propertyName) in schemaData.properties"
							:key="`schema_${schemaData.id}_prop_${propertyName}`"
							:checked="objectStore.columnFilters[`schema_${schemaData.id}_prop_${propertyName}`]"
							@update:checked="(status) => objectStore.updateColumnFilter(`schema_${schemaData.id}_prop_${propertyName}`, status)">
							{{ property.title || property.label || propertyName }}
						</NcCheckboxRadioSwitch>
					</div>
				</div>
			</div>

			<NcNoteCard v-else type="info">
				{{ t('openregister', 'No properties available. Select a schema to view properties.') }}
			</NcNoteCard>

			<!-- Metadata Section (Collapsible) -->
			<div class="columnGroup collapsible">
				<div class="columnGroupHeader" @click="metadataExpanded = !metadataExpanded">
					<ChevronDown v-if="metadataExpanded" :size="20" />
					<ChevronRight v-else :size="20" />
					<h4>{{ t('openregister', 'Metadata') }}</h4>
				</div>
				<div v-if="metadataExpanded" class="columnGroupContent">
					<NcCheckboxRadioSwitch
						v-for="meta in metadataColumns"
						:key="`meta_${meta.id}`"
						:checked="objectStore.columnFilters[`meta_${meta.id}`]"
						@update:checked="(status) => objectStore.updateColumnFilter(`meta_${meta.id}`, status)">
						{{ meta.label }}
					</NcCheckboxRadioSwitch>
				</div>
			</div>
		</div>
	</NcAppSidebarTab>
	
	<!-- Edit View Dialog (as a modal overlay) -->
	<div v-if="showEditDialog" class="editViewModal">
		<div class="editViewModalContent">
			<h3>{{ t('openregister', 'Edit View') }}</h3>
			
			<NcTextField
				:value.sync="editViewName"
				:label="t('openregister', 'View Name')"
				:placeholder="t('openregister', 'Enter view name...')"
				class="editViewField" />
			
			<NcTextField
				:value.sync="editViewDescription"
				:label="t('openregister', 'Description')"
				:placeholder="t('openregister', 'Enter description (optional)...')"
				class="editViewField" />
			
			<div class="editViewActions">
				<NcButton
					type="primary"
					:disabled="!editViewName.trim()"
					@click="updateView()">
					{{ t('openregister', 'Save') }}
				</NcButton>
				<NcButton
					type="secondary"
					@click="cancelEditView()">
					{{ t('openregister', 'Cancel') }}
				</NcButton>
			</div>
		</div>
	</div>
	</NcAppSidebar>
</template>

<script>
import { NcAppSidebar, NcAppSidebarTab, NcSelect, NcNoteCard, NcTextField, NcButton, NcLoadingIcon, NcCheckboxRadioSwitch } from '@nextcloud/vue'
import Magnify from 'vue-material-design-icons/Magnify.vue'
import Close from 'vue-material-design-icons/Close.vue'
import FilterIcon from 'vue-material-design-icons/Filter.vue'
import ContentSaveOutline from 'vue-material-design-icons/ContentSaveOutline.vue'
import Delete from 'vue-material-design-icons/Delete.vue'
import ViewDashboardOutline from 'vue-material-design-icons/ViewDashboardOutline.vue'
import FolderOpenOutline from 'vue-material-design-icons/FolderOpenOutline.vue'
import Star from 'vue-material-design-icons/Star.vue'
import StarOutline from 'vue-material-design-icons/StarOutline.vue'
import Pencil from 'vue-material-design-icons/Pencil.vue'
import FormatColumns from 'vue-material-design-icons/FormatColumns.vue'
import ChevronDown from 'vue-material-design-icons/ChevronDown.vue'
import ChevronRight from 'vue-material-design-icons/ChevronRight.vue'
import { translate as t } from '@nextcloud/l10n'

export default {
	name: 'SearchSideBar',
	components: {
		NcAppSidebar,
		NcAppSidebarTab,
		NcSelect,
		NcNoteCard,
		NcTextField,
		NcButton,
		NcLoadingIcon,
		NcCheckboxRadioSwitch,
		Magnify,
		Close,
		FilterIcon,
		ContentSaveOutline,
		Delete,
		ViewDashboardOutline,
		FolderOpenOutline,
		Star,
		StarOutline,
		Pencil,
		FormatColumns,
		ChevronDown,
		ChevronRight,
	},
	data() {
		return {
			activeTab: 'search-tab', // Active tab tracker
			registerLoading: false,
			schemaLoading: false,
			// Multi-select state
			selectedRegisters: [], // Array of register IDs
			selectedSchemas: [], // Array of schema IDs
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
		// View management
		viewSearchQuery: '', // Search query for filtering views
		showSaveForm: false, // Show inline save form
		viewName: '',
		viewDescription: '',
		viewIsPublic: false,
		viewIsDefault: false,
		// Edit view dialog
		showEditDialog: false,
		editingView: null,
		editViewName: '',
		editViewDescription: '',
		// Column visibility collapsible state
		expandedSchemas: {}, // Track which schema groups are expanded
		metadataExpanded: true, // Metadata section expanded by default
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
				reduce: option => option.value, // Return only the ID for multi-select
				label: 'title',
				getOptionLabel: option => {
					return option.title || option.label || ''
				},
			}
		},
		schemaOptions() {
			// Get all schemas from selected registers
			if (this.selectedRegisters.length === 0) return { options: [] }

			const schemaIds = new Set()
			this.selectedRegisters.forEach(registerId => {
				const register = registerStore.registerList.find(r => r.id === registerId)
				if (register && register.schemas) {
					register.schemas.forEach(schemaId => schemaIds.add(schemaId))
				}
			})

			return {
				options: schemaStore.schemaList
					.filter(schema => schemaIds.has(schema.id))
					.map(schema => ({
						value: schema.id,
						label: schema.title,
						title: schema.title,
						schema,
					})),
				reduce: option => option.value, // Return only the ID for multi-select
				label: 'title',
				getOptionLabel: option => {
					return option.title || option.label || ''
				},
			}
		},
		oldSchemaOptions() {
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
		viewOptions() {
			return viewsStore.getAllViews.map(view => ({
				value: view.id || view.uuid,
				label: view.name,
				description: view.description,
				isDefault: view.isDefault,
				isPublic: view.isPublic,
			}))
		},
		selectedViewValue() {
			if (!viewsStore.activeView) return null
			const view = viewsStore.activeView
			return {
				value: view.id || view.uuid,
				label: view.name,
			}
		},

		filteredViews() {
			// Filter views based on search query
			let views = viewsStore.getAllViews
			if (this.viewSearchQuery) {
				const query = this.viewSearchQuery.toLowerCase()
				views = views.filter(view => {
					return view.name.toLowerCase().includes(query) ||
						(view.description && view.description.toLowerCase().includes(query))
				})
			}
			
			// Sort: favorited views first, then by name
			return views.sort((a, b) => {
				const aFavorited = this.isFavorited(a)
				const bFavorited = this.isFavorited(b)
				
				if (aFavorited && !bFavorited) return -1
				if (!aFavorited && bFavorited) return 1
				
				// Both favorited or both not favorited - sort by name
				return (a.name || '').localeCompare(b.name || '')
			})
		},

		canSearch() {
			// Allow search if at least one register and one schema are selected
			return this.selectedRegisters.length > 0 && this.selectedSchemas.length > 0
		},
		canSaveView() {
			// Can save view if we have search configuration
			return this.canSearch
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
		metadataColumns() {
			return Object.entries(objectStore.metadata).map(([id, meta]) => ({
				id,
				...meta,
			}))
		},
		/**
		 * Get properties for all selected schemas
		 *
		 * Returns an array of objects containing schema info and properties
		 *
		 * @return {Array} Array of schema data with properties
		 */
		selectedSchemasWithProperties() {
			if (!this.selectedSchemas || this.selectedSchemas.length === 0) {
				return []
			}

			return this.selectedSchemas
				.map(schemaId => {
					const schema = schemaStore.schemaList.find(s => s.id === schemaId)
					if (!schema || !schema.properties) {
						return null
					}

					return {
						id: schema.id,
						title: schema.title || schema.name || `Schema ${schema.id}`,
						properties: schema.properties,
					}
				})
				.filter(Boolean) // Remove null entries
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
		// Watch for selected schemas changes to auto-expand new schemas
		selectedSchemas: {
			handler(newSchemas, oldSchemas) {
				// Auto-expand newly selected schemas
				if (newSchemas && newSchemas.length > 0) {
					const newExpanded = { ...this.expandedSchemas }
					newSchemas.forEach(schemaId => {
						// Only auto-expand if not already in the list
						if (!oldSchemas || !oldSchemas.includes(schemaId)) {
							newExpanded[schemaId] = true
						}
					})
					this.expandedSchemas = newExpanded
				}
			},
			deep: true,
		},
	},
	mounted() {
		objectStore.initializeColumnFilters()
		this.registerLoading = true
		this.schemaLoading = true

		// Load views
		viewsStore.fetchViews().catch(error => {
			console.error('Error loading views:', error)
		})

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

		// Load default view if available
		const defaultView = viewsStore.getDefaultView
		if (defaultView) {
			this.applyViewConfiguration(defaultView)
		}

		// Initialize from query params after lists potentially load
		this.applyQueryParamsFromRoute()
	},
	methods: {
		t,
		// Build query params from current sidebar state
		buildQueryFromState() {
			const query = {}
			if (this.selectedRegisters.length > 0) {
				query.register = this.selectedRegisters.join(',')
			}
			if (this.selectedSchemas.length > 0) {
				query.schema = this.selectedSchemas.join(',')
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
			const applyRegisters = () => {
				if (!register) return true
				if (!registerStore.registerList.length) return false
				const registerIds = String(register).split(',').map(id => parseInt(id, 10)).filter(Boolean)
				this.selectedRegisters = registerIds.filter(id => registerStore.registerList.some(r => r.id === id))
				return true
			}
			const applySchemas = () => {
				if (!schema) return true
				if (!schemaStore.schemaList.length) return false
				const schemaIds = String(schema).split(',').map(id => parseInt(id, 10)).filter(Boolean)
				this.selectedSchemas = schemaIds.filter(id => schemaStore.schemaList.some(s => s.id === id))
				return true
			}
			// Try apply now, or retry shortly if lists not yet loaded
			const tryApply = (attempt = 0) => {
				const regOk = applyRegisters()
				const schOk = applySchemas()
				if (regOk && schOk) {
					// If both selected, perform search
					if (this.selectedRegisters.length > 0 && this.selectedSchemas.length > 0) {
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
		handleRegisterChange(options) {
			// Handle multi-select - options is an array of values
			console.log('Register change - raw options:', options)
			
			// NcSelect with reduce returns the reduced values directly
			// For multi-select, it's an array of the reduced values (IDs)
			if (!options || options.length === 0) {
				this.selectedRegisters = []
			} else if (Array.isArray(options)) {
				// Options should already be an array of IDs thanks to reduce
				this.selectedRegisters = options
			} else {
				// Fallback for single value
				this.selectedRegisters = [options]
			}

			console.log('Selected registers after processing:', this.selectedRegisters)

			// Clear schemas that are no longer valid for selected registers
			const validSchemaIds = new Set()
			this.selectedRegisters.forEach(registerId => {
				const register = registerStore.registerList.find(r => r.id === registerId)
				if (register && register.schemas) {
					register.schemas.forEach(schemaId => validSchemaIds.add(schemaId))
				}
			})
			this.selectedSchemas = this.selectedSchemas.filter(id => validSchemaIds.has(id))

			// Clear object list when registers change
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
		},
		async handleSchemaChange(options) {
			// Handle multi-select - options is an array of values
			console.log('Schema change - raw options:', options)
			
			// NcSelect with reduce returns the reduced values directly
			// For multi-select, it's an array of the reduced values (IDs)
			if (!options || options.length === 0) {
				this.selectedSchemas = []
			} else if (Array.isArray(options)) {
				// Options should already be an array of IDs thanks to reduce
				this.selectedSchemas = options
			} else {
				// Fallback for single value
				this.selectedSchemas = [options]
			}

			console.log('Selected schemas after processing:', this.selectedSchemas)
			console.log('Can search?', this.canSearch, 'Registers:', this.selectedRegisters.length, 'Schemas:', this.selectedSchemas.length)

			// Clear object list when schemas change
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

			// If we have selections, perform search
			if (this.canSearch) {
				await this.performSearch()
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
			if (!this.canSearch) return

			try {
				this.searchLoading = true

				// Apply current filters and search terms to objectStore
				this.applyFiltersToObjectStore()

				// Build search parameters for multi-register/schema search
				const searchParams = {
					includeFacets: true, // Always include facets discovery for search results
				}

				// Add register and schema arrays as query parameters
				if (this.selectedRegisters.length > 0) {
					searchParams.register = this.selectedRegisters
				}
				if (this.selectedSchemas.length > 0) {
					searchParams.schema = this.selectedSchemas
				}

				// Add source parameter
				if (this.selectedSource && this.selectedSource !== 'auto') {
					searchParams._source = this.selectedSource
				}

				// Perform search using generic objects endpoint
				await objectStore.refreshObjectList(searchParams)

				// Get the facet data from the objectStore
				// The API response has facets nested under facets.facets
				this.facetData = objectStore.facets?.facets || {}

			} catch (error) {
				// Error performing search with facets - set to null to handle gracefully
				this.facetData = null
				console.error('Error performing search with facets:', error)
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

		/**
		 * Toggle schema group expanded/collapsed state
		 *
		 * @param {number} schemaId - The schema ID
		 * @return {void}
		 */
		toggleSchemaGroup(schemaId) {
			// Toggle the expanded state for this schema
			this.expandedSchemas = {
				...this.expandedSchemas,
				[schemaId]: !this.expandedSchemas[schemaId],
			}
		},

		// View Management Methods
		async handleViewChange(option) {
			if (!option) {
				viewsStore.clearActiveView()
				return
			}

			try {
				// Fetch the full view details
				const view = await viewsStore.fetchView(option.value)

				// Apply the view configuration to current search state
				this.applyViewConfiguration(view)
			} catch (error) {
				console.error('Error loading view:', error)
			}
		},

		applyViewConfiguration(view) {
			if (!view) return

			// Support both 'query' (new) and 'configuration' (old) for backwards compatibility
			const config = view.query || view.configuration
			if (!config) return

			// Apply registers (define data scope)
			if (config.registers && Array.isArray(config.registers)) {
				this.selectedRegisters = config.registers
			}

			// Apply schemas (define object types)
			if (config.schemas && Array.isArray(config.schemas)) {
				this.selectedSchemas = config.schemas
			}

			// Apply source (database/index/auto)
			if (config.source) {
				this.selectedSource = config.source
			}

			// Apply search terms (default filters)
			if (config.searchTerms && Array.isArray(config.searchTerms)) {
				this.searchTerms = config.searchTerms
			}

			// Apply facet filters (default filters)
			if (config.facetFilters) {
				this.facetFilters = config.facetFilters
			}

			// Apply enabled facets
			if (config.enabledFacets) {
				this.enabledFacets = config.enabledFacets
			}

			// Set as active view
			viewsStore.setActiveView(view)

			// Perform search with new configuration
			if (this.canSearch) {
				this.performSearchWithFacets()
			}
		},

		async saveView() {
			if (!this.viewName.trim()) return

			try {
				// Only save query parameters (not UI state like pagination, sorting, columns)
				const viewData = {
					name: this.viewName.trim(),
					description: this.viewDescription || '',
					isPublic: this.viewIsPublic,
					isDefault: this.viewIsDefault,
					configuration: {
						// Query parameters only
						registers: this.selectedRegisters,
						schemas: this.selectedSchemas,
						source: this.selectedSource,
						searchTerms: this.searchTerms,
						facetFilters: this.facetFilters,
						enabledFacets: this.enabledFacets,
					},
				}

				await viewsStore.createView(viewData)

				// Clear form data and hide form
				this.viewName = ''
				this.viewDescription = ''
				this.viewIsPublic = false
				this.viewIsDefault = false
				this.showSaveForm = false

				// Show success message
				OC.Notification.showTemporary(this.t('openregister', 'View saved successfully!'))
			} catch (error) {
				console.error('Error saving view:', error)
				OC.Notification.showTemporary(this.t('openregister', 'Failed to save view: {error}', { error: error.message }))
			}
		},

		cancelSaveView() {
			// Reset form and hide it
			this.viewName = ''
			this.viewDescription = ''
			this.showSaveForm = false
		},

		async deleteCurrentView() {
			if (!viewsStore.activeView) return

			const confirmed = confirm(`Are you sure you want to delete the view "${viewsStore.activeView.name}"?`)
			if (!confirmed) return

			try {
				await viewsStore.deleteView(viewsStore.activeView.id || viewsStore.activeView.uuid)
				alert('View deleted successfully!')
			} catch (error) {
				console.error('Error deleting view:', error)
				alert('Failed to delete view: ' + error.message)
			}
		},

		async loadView(view) {
			try {
				// Fetch the full view details
				const fullView = await viewsStore.fetchView(view.id || view.uuid)
				// Apply the view configuration to current search state
				this.applyViewConfiguration(fullView)
				// Switch to search tab to see the applied configuration
				this.activeTab = 'search-tab'
			} catch (error) {
				console.error('Error loading view:', error)
				alert('Failed to load view: ' + error.message)
			}
		},

		async confirmDeleteView(view) {
			const confirmed = confirm(`Are you sure you want to delete the view "${view.name}"?`)
			if (!confirmed) return

			try {
				await viewsStore.deleteView(view.id || view.uuid)
				alert('View deleted successfully!')
			} catch (error) {
				console.error('Error deleting view:', error)
				alert('Failed to delete view: ' + error.message)
			}
		},

		isActiveView(view) {
			if (!viewsStore.activeView) return false
			const activeId = viewsStore.activeView.id || viewsStore.activeView.uuid
			const viewId = view.id || view.uuid
			return activeId === viewId
		},

		isFavorited(view) {
			// Check if current user has favorited this view
			const currentUser = OC.getCurrentUser()?.uid
			if (!currentUser || !view || !view.favoredBy) return false
			return view.favoredBy.includes(currentUser)
		},

		async toggleFavorite(view) {
			try {
				const currentUser = OC.getCurrentUser()?.uid
				if (!currentUser) {
					OC.Notification.showTemporary(this.t('openregister', 'You must be logged in to favorite views'))
					return
				}

				const isFavorited = this.isFavorited(view)
				const favor = !isFavorited

				// Call API to toggle favorite
				await fetch(`/apps/openregister/api/views/${view.id || view.uuid}/favorite`, {
					method: 'POST',
					headers: {
						'Content-Type': 'application/json',
						requesttoken: OC.requestToken,
				},
					body: JSON.stringify({ favor }),
				})

				// Refresh views list to get updated favoredBy
				await viewsStore.fetchViews()

				OC.Notification.showTemporary(
					favor
						? this.t('openregister', 'Added to favorites')
						: this.t('openregister', 'Removed from favorites')
				)
			} catch (error) {
				console.error('Error toggling favorite:', error)
				OC.Notification.showTemporary(this.t('openregister', 'Failed to update favorite status'))
			}
		},

		openEditDialog(view) {
			this.editingView = view
			this.editViewName = view.name
			this.editViewDescription = view.description || ''
			this.showEditDialog = true
		},

		async updateView() {
			if (!this.editingView || !this.editViewName.trim()) return

			try {
				const viewData = {
					name: this.editViewName.trim(),
					description: this.editViewDescription || '',
					isPublic: this.editingView.isPublic,
					isDefault: this.editingView.isDefault,
					query: this.editingView.query || this.editingView.configuration,
				}

				await viewsStore.updateView(this.editingView.id || this.editingView.uuid, viewData)

				// Clear form and hide dialog
				this.showEditDialog = false
				this.editingView = null
				this.editViewName = ''
				this.editViewDescription = ''

				// Show success message
				OC.Notification.showTemporary(this.t('openregister', 'View updated successfully!'))
			} catch (error) {
				console.error('Error updating view:', error)
				OC.Notification.showTemporary(this.t('openregister', 'Failed to update view: {error}', { error: error.message }))
			}
		},

		cancelEditView() {
			this.showEditDialog = false
			this.editingView = null
			this.editViewName = ''
			this.editViewDescription = ''
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
			} else {
				// Ensure previously set _search is cleared when no terms remain
				filters._search = ''
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
	padding: 16px;
}

.sectionTitle {
	color: var(--color-text-maxcontrast);
	font-size: 14px;
	font-weight: bold;
	margin: 0 0 12px 0;
}

.saveViewSection {
	padding: 16px;
	border-bottom: 1px solid var(--color-border);
	display: flex;
	flex-direction: column;
	gap: 12px;

	> button {
		width: 100%;
	}
}

.saveViewForm {
	display: flex;
	flex-direction: column;
	gap: 12px;
	width: 100%;
}

.saveViewFormActions {
	display: flex;
	gap: 8px;

	button {
		flex: 1;
	}
}

.saveViewHint {
	font-size: 0.85em;
	color: var(--color-text-maxcontrast);
	margin: 0;
	text-align: center;
}

.viewsSection {
	display: flex;
	flex-direction: column;
	gap: 16px;
	padding: 16px;

	h3 {
		margin: 0 0 8px 0;
		font-size: 1.1em;
		color: var(--color-main-text);
	}
}

.viewsDescription {
	color: var(--color-text-maxcontrast);
	font-size: 0.9em;
	margin: 0;
}

.viewsSearchContainer {
	margin-top: 8px;
}

.activeViewBadge {
	background: var(--color-primary-element-light);
	color: var(--color-primary-element-text);
	padding: 8px 12px;
	border-radius: var(--border-radius);
	font-size: 0.9em;
	text-align: center;
}

.viewsLoading {
	display: flex;
	flex-direction: column;
	align-items: center;
	justify-content: center;
	padding: 32px;
	gap: 16px;

	p {
		color: var(--color-text-maxcontrast);
		margin: 0;
	}
}

.noViews {
	padding: 16px 0;
}

.viewsTable {
	display: flex;
	flex-direction: column;
	gap: 8px;
	margin-top: 8px;
}

.viewRow {
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	padding: 12px;
	display: flex;
	flex-direction: column;
	gap: 8px;
	transition: background-color 0.2s ease;

	&:hover {
		background-color: var(--color-background-hover);
	}

	&--active {
		border-color: var(--color-primary-element);
		background-color: var(--color-primary-element-light);
	}
}

.viewRowHeader {
	display: flex;
	justify-content: space-between;
	align-items: flex-start;
	gap: 8px;
}

.viewRowTitle {
	flex: 1;
	display: flex;
	align-items: center;
	gap: 8px;
	flex-wrap: wrap;

	strong {
		font-size: 1em;
		color: var(--color-main-text);
	}
}

.viewBadge {
	font-size: 0.75em;
	padding: 2px 6px;
	border-radius: 3px;
	font-weight: 600;
	text-transform: uppercase;

	&--default {
		background-color: var(--color-success);
		color: white;
	}

	&--public {
		background-color: var(--color-primary-element);
		color: white;
	}
}

.viewRowActions {
	display: flex;
	gap: 4px;
}

.viewRowDescription {
	font-size: 0.9em;
	color: var(--color-text-maxcontrast);
	margin: 0;
	font-style: italic;
}

.viewRowMeta {
	display: flex;
	flex-wrap: wrap;
	gap: 12px;
	font-size: 0.85em;
	color: var(--color-text-maxcontrast);
}

.viewMetaItem {
	strong {
		font-weight: 600;
	}
}

.filterSection {
	display: flex;
	flex-direction: column;
	gap: 16px;
	padding: 16px;

	h3 {
		margin: 0 0 12px 0;
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

/* Edit View Modal */
.editViewModal {
	position: fixed;
	top: 0;
	left: 0;
	width: 100%;
	height: 100%;
	background-color: rgba(0, 0, 0, 0.5);
	display: flex;
	justify-content: center;
	align-items: center;
	z-index: 10000;
}

.editViewModalContent {
	background-color: var(--color-main-background);
	border-radius: var(--border-radius-large);
	padding: 24px;
	max-width: 500px;
	width: 90%;
	box-shadow: 0 2px 10px rgba(0, 0, 0, 0.2);
}

.editViewModalContent h3 {
	margin-top: 0;
	margin-bottom: 16px;
}

.editViewField {
	margin-bottom: 16px;
}

.editViewActions {
	display: flex;
	gap: 8px;
	justify-content: flex-end;
	margin-top: 24px;
}

/* Columns Tab */
.columnsSection {
	padding: 16px;
}

.columnsSection h3 {
	margin-top: 0;
	margin-bottom: 8px;
	font-size: 18px;
	font-weight: 600;
}

.columnsDescription {
	color: var(--color-text-maxcontrast);
	margin-bottom: 16px;
	font-size: 14px;
}

.columnGroup {
	margin-bottom: 24px;
}

.columnGroup h4 {
	margin-top: 0;
	margin-bottom: 12px;
	font-size: 14px;
	font-weight: 600;
	color: var(--color-text-light);
	border-bottom: 1px solid var(--color-border);
	padding-bottom: 8px;
}

.columnGroup :deep(.checkbox-radio-switch) {
	margin-bottom: 8px;
}

.columnGroup :deep(.checkbox-radio-switch__content) {
	padding: 4px 0;
}

/* Collapsible Column Groups */
.columnGroup.collapsible {
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	padding: 0;
	margin-bottom: 12px;
}

.columnGroupHeader {
	display: flex;
	align-items: center;
	gap: 8px;
	padding: 12px;
	cursor: pointer;
	user-select: none;
	transition: background-color 0.2s ease;
}

.columnGroupHeader:hover {
	background-color: var(--color-background-hover);
}

.columnGroupHeader h4 {
	margin: 0;
	padding: 0;
	border: none;
	flex: 1;
	color: var(--color-main-text);
}

.columnGroupContent {
	padding: 12px;
	border-top: 1px solid var(--color-border);
	display: flex;
	flex-direction: column;
	gap: 8px;
}
</style>

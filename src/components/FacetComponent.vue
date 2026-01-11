<template>
	<div class="facet-component">
		<div v-if="objectStore.facetsLoading" class="facet-loading">
			<NcLoadingIcon :size="20" />
			<span>Loading facets...</span>
		</div>

		<div v-else-if="objectStore.hasFacetableFields" class="facet-content">
			<!-- Current Active Filters Section - Always at the top -->
			<div class="current-filters-section">
				<h3 class="current-filters-title">
					{{ t('openregister', 'Current Filters') }}
				</h3>

				<div v-if="hasActiveFilters" class="active-filters">
					<!-- Display active facets -->
					<div v-for="(facetData, facetField) in objectStore.currentFacets" :key="`active-${facetField}`" class="active-filter-group">
						<div class="active-filter-header">
							<span class="active-filter-name">{{ getActiveFacetDisplayName(facetField) }}</span>
							<NcButton
								type="tertiary"
								size="small"
								:aria-label="t('openregister', 'Remove filter')"
								@click="removeFilter(facetField)">
								<template #icon>
									<Close :size="16" />
								</template>
							</NcButton>
						</div>

						<!-- Show facet values for this filter -->
						<div v-if="facetData.buckets && facetData.buckets.length > 0" class="active-filter-values">
							<div v-for="bucket in facetData.buckets.slice(0, 5)" :key="bucket.key" class="active-filter-value">
								{{ bucket.key }}
								<span class="value-count">({{ bucket.results }})</span>
							</div>
							<div v-if="facetData.buckets.length > 5" class="filter-more">
								+{{ facetData.buckets.length - 5 }} more
							</div>
						</div>
					</div>

					<!-- Clear all filters button -->
					<div class="clear-filters">
						<NcButton
							type="tertiary-no-background"
							size="small"
							@click="clearAllFilters">
							{{ t('openregister', 'Clear all filters') }}
						</NcButton>
					</div>
				</div>

				<div v-else class="no-active-filters">
					<span class="no-filters-text">{{ t('openregister', 'No active filters') }}</span>
				</div>
			</div>

			<!-- Available Filters Section -->
			<div class="available-filters-section">
				<h3 class="facet-title">
					{{ t('openregister', 'Available Filters') }}
				</h3>

				<!-- Metadata Facets -->
				<div v-if="Object.keys(objectStore.availableMetadataFacets).length > 0" class="facet-section">
					<h4 class="facet-section-title">
						{{ t('openregister', 'Metadata Filters') }}
					</h4>

					<!-- Date Range Pickers for metadata date fields -->
					<div class="facet-date-ranges">
						<div
							v-for="(field, fieldName) in metadataDateFields"
							:key="`date-${fieldName}`"
							class="facet-date-item">
							<label
								class="facet-date-label"
								:title="field.description">
								{{ capitalizeFieldName(fieldName) }}
								<span v-if="field.date_range" class="date-range-info">
									({{ formatDateRange(field.date_range) }})
								</span>
							</label>
							<div class="date-range-inputs">
								<NcDateTimePickerNative
									:model-value="getDateRangeValue(fieldName, 'from')"
									:placeholder="t('openregister', 'From date')"
									type="date"
									@update:model-value="updateDateRange(fieldName, 'from', $event)" />
								<span class="date-separator">{{ t('openregister', 'to') }}</span>
								<NcDateTimePickerNative
									:model-value="getDateRangeValue(fieldName, 'to')"
									:placeholder="t('openregister', 'To date')"
									type="date"
									@update:model-value="updateDateRange(fieldName, 'to', $event)" />
								<NcButton
									v-if="hasDateRange(fieldName)"
									type="tertiary"
									size="small"
									:aria-label="t('openregister', 'Clear date range')"
									@click="clearDateRange(fieldName)">
									<template #icon>
										<Close :size="16" />
									</template>
								</NcButton>
							</div>
						</div>
					</div>

					<!-- Terms-based dropdowns for metadata fields (excluding id and uuid) -->
					<div class="facet-dropdowns">
						<div
							v-for="(field, fieldName) in termsMetadataFields"
							:key="`meta-dropdown-${fieldName}`"
							class="facet-dropdown-item">
							<label
								class="facet-dropdown-label"
								:title="field.description">
								{{ capitalizeFieldName(fieldName) }}
								<span v-if="field.appearance_rate" class="field-coverage">
									({{ field.appearance_rate }}/{{ objectStore.objectList?.total || 0 }} objects)
								</span>
							</label>
							<NcSelect
								:model-value="getSelectedMetadataDropdownValues(fieldName)"
								:options="getMetadataDropdownOptions(fieldName)"
								:placeholder="t('openregister', 'Select {fieldName} values', { fieldName: capitalizeFieldName(fieldName) })"
								:input-label="capitalizeFieldName(fieldName)"
								:multiple="true"
								:close-on-select="false"
								:searchable="true"
								:loading="objectStore.facetsLoading"
								@update:model-value="updateMetadataDropdownSelection(fieldName, $event)">
								<template #option="{ option }">
									<div v-if="option" class="dropdown-option">
										<span class="option-label">{{ option.label || option.value || '' }}</span>
										<span v-if="option.count" class="option-count">({{ option.count }})</span>
									</div>
								</template>
								<template #selected-option="{ option }">
									<span v-if="option" class="selected-option">{{ option.label || option.value || '' }}</span>
								</template>
							</NcSelect>
						</div>
					</div>

					<!-- Non-date metadata facets -->
					<div v-if="Object.keys(nonDateMetadataFields).length > 0" class="facet-list">
						<div
							v-for="(field, fieldName) in nonDateMetadataFields"
							:key="`meta-${fieldName}`"
							class="facet-item">
							<NcCheckboxRadioSwitch
								:checked="isActiveFacet(`@self.${fieldName}`)"
								@update:checked="(status) => toggleFacet(`@self.${fieldName}`, field.facet_types[0], status)">
								<span :title="field.description">{{ capitalizeFieldName(fieldName) }}</span>
							</NcCheckboxRadioSwitch>
							<small class="facet-info">
								{{ field.type }}
								<span v-if="field.appearance_rate">({{ field.appearance_rate }} objects)</span>
							</small>
						</div>
					</div>
				</div>

				<!-- Object Field Facets -->
				<div v-if="Object.keys(objectStore.availableObjectFieldFacets).length > 0" class="facet-section">
					<h4 class="facet-section-title">
						{{ t('openregister', 'Property Filters') }}
					</h4>

					<!-- Terms-based dropdowns for object fields (excluding id) -->
					<div class="facet-dropdowns">
						<div
							v-for="(field, fieldName) in termsFacetableFields"
							:key="`dropdown-${fieldName}`"
							class="facet-dropdown-item">
							<label
								class="facet-dropdown-label"
								:title="field.description">
								{{ capitalizeFieldName(fieldName) }}
								<span v-if="field.appearance_rate" class="field-coverage">
									({{ field.appearance_rate }}/{{ objectStore.objectList?.total || 0 }} objects)
								</span>
							</label>
							<NcSelect
								:model-value="getSelectedDropdownValues(fieldName)"
								:options="getDropdownOptions(fieldName)"
								:placeholder="t('openregister', 'Select {fieldName} values', { fieldName: capitalizeFieldName(fieldName) })"
								:input-label="capitalizeFieldName(fieldName)"
								:multiple="true"
								:close-on-select="false"
								:searchable="true"
								:loading="objectStore.facetsLoading"
								@update:model-value="updateDropdownSelection(fieldName, $event)">
								<template #option="{ option }">
									<div v-if="option" class="dropdown-option">
										<span class="option-label">{{ option.label || option.value || '' }}</span>
										<span v-if="option.count" class="option-count">({{ option.count }})</span>
									</div>
								</template>
								<template #selected-option="{ option }">
									<span v-if="option" class="selected-option">{{ option.label || option.value || '' }}</span>
								</template>
							</NcSelect>
						</div>
					</div>

					<!-- Checkbox-based facets for non-terms fields (excluding id) -->
					<div v-if="Object.keys(nonTermsObjectFieldFacets).length > 0" class="facet-list">
						<div
							v-for="(field, fieldName) in nonTermsObjectFieldFacets"
							:key="`checkbox-${fieldName}`"
							class="facet-item">
							<NcCheckboxRadioSwitch
								:checked="isActiveFacet(fieldName)"
								@update:checked="(status) => toggleFacet(fieldName, field.facet_types[0], status)">
								<span :title="field.description">{{ capitalizeFieldName(fieldName) }}</span>
							</NcCheckboxRadioSwitch>
							<small class="facet-info">
								{{ field.type }}
								<span v-if="field.appearance_rate">({{ field.appearance_rate }} objects)</span>
								<span v-if="field.sample_values && field.sample_values.length > 0">
									- Sample: {{ field.sample_values.slice(0, 3).join(', ') }}
								</span>
							</small>
						</div>
					</div>
				</div>
			</div>
		</div>

		<div v-else class="facet-empty">
			<p>{{ t('openregister', 'No facetable fields available. Select a register and schema to see available filters.') }}</p>
		</div>
	</div>
</template>

<script>
import { NcCheckboxRadioSwitch, NcLoadingIcon, NcButton, NcSelect, NcDateTimePickerNative } from '@nextcloud/vue'
import Close from 'vue-material-design-icons/Close.vue'
import { objectStore } from '../store/store.js'

export default {
	name: 'FacetComponent',
	components: {
		NcCheckboxRadioSwitch,
		NcLoadingIcon,
		NcButton,
		NcSelect,
		NcDateTimePickerNative,
		Close,
	},
	data() {
		return {
			objectStore,
		}
	},
	computed: {
		hasActiveFilters() {
			return this.objectStore.hasFacets && Object.keys(this.objectStore.currentFacets).length > 0
		},
		/**
		 * Get object fields that support terms faceting (excluding id)
		 * These will be shown as dropdowns
		 */
		termsFacetableFields() {
			const fields = {}
			Object.entries(this.objectStore.availableObjectFieldFacets).forEach(([fieldName, field]) => {
				// Exclude id field and only include fields that support terms faceting
				if (fieldName !== 'id' && field.facet_types && field.facet_types.includes('terms')) {
					fields[fieldName] = field
				}
			})
			return fields
		},
		/**
		 * Get object fields that don't support terms faceting (excluding id field)
		 * These will be shown as checkboxes
		 */
		nonTermsObjectFieldFacets() {
			const fields = {}
			Object.entries(this.objectStore.availableObjectFieldFacets).forEach(([fieldName, field]) => {
				// Exclude id field, only include fields that don't support terms faceting
				if (fieldName !== 'id' && (!field.facet_types || !field.facet_types.includes('terms'))) {
					fields[fieldName] = field
				}
			})
			return fields
		},
		/**
		 * Get metadata fields that support date range faceting
		 * These will be shown as date range pickers
		 */
		metadataDateFields() {
			// eslint-disable-next-line no-console
			console.log('metadataDateFields computed - availableMetadataFacets:', this.objectStore.availableMetadataFacets)

			const fields = {}
			Object.entries(this.objectStore.availableMetadataFacets).forEach(([fieldName, field]) => {
				// eslint-disable-next-line no-console
				console.log('Checking field:', fieldName, 'with data:', field)

				// Include fields that are date type and support range faceting
				if (field.type === 'date' && field.facet_types && field.facet_types.includes('range')) {
					// eslint-disable-next-line no-console
					console.log('Adding date field:', fieldName)
					fields[fieldName] = field
				}
			})

			// eslint-disable-next-line no-console
			console.log('Final metadataDateFields:', fields)
			return fields
		},
		/**
		 * Get metadata fields that are not date fields (excluding id and uuid)
		 * These will be shown as checkboxes
		 */
		nonDateMetadataFields() {
			const fields = {}
			Object.entries(this.objectStore.availableMetadataFacets).forEach(([fieldName, field]) => {
				// Exclude date fields, id, uuid, and terms-facetable fields
				if (field.type !== 'date'
					&& fieldName !== 'id'
					&& fieldName !== 'uuid'
					&& (!field.facet_types || !field.facet_types.includes('terms'))) {
					fields[fieldName] = field
				}
			})
			return fields
		},
		/**
		 * Get metadata fields that support terms faceting (excluding id and uuid)
		 * These will be shown as dropdowns
		 */
		termsMetadataFields() {
			const fields = {}
			Object.entries(this.objectStore.availableMetadataFacets).forEach(([fieldName, field]) => {
				// Include fields that support terms faceting (excluding id and uuid)
				if (fieldName !== 'id'
					&& fieldName !== 'uuid'
					&& field.facet_types
					&& field.facet_types.includes('terms')) {
					fields[fieldName] = field
				}
			})
			return fields
		},
	},
	methods: {
		/**
		 * Check if a facet is currently active
		 * @param {string} fieldName - Name of the field to check
		 * @return {boolean} True if facet is active
		 */
		isActiveFacet(fieldName) {
			if (fieldName.startsWith('@self.')) {
				const field = fieldName.replace('@self.', '')
				return Boolean(this.objectStore.activeFacets._facets?.['@self']?.[field])
			} else {
				return Boolean(this.objectStore.activeFacets._facets?.[fieldName])
			}
		},
		/**
		 * Toggle a facet's active state
		 * @param {string} fieldName - Name of the field to toggle
		 * @param {string} facetType - Type of facet
		 * @param {boolean} enabled - Whether to enable or disable the facet
		 */
		async toggleFacet(fieldName, facetType, enabled) {
			await this.objectStore.updateActiveFacet(fieldName, facetType, enabled)
			// Trigger search after toggling facet
			await this.objectStore.refreshObjectList()
		},
		/**
		 * Get display name for a facet field
		 * @param {string} facetField - Name of the facet field
		 * @return {string} Display name for the facet
		 */
		getFacetDisplayName(facetField) {
			if (facetField === '@self') {
				return 'Metadata'
			}

			// Try to get a friendly name from facetable fields
			const metadataField = this.objectStore.availableMetadataFacets[facetField]
			if (metadataField) {
				return metadataField.description || facetField
			}

			const objectField = this.objectStore.availableObjectFieldFacets[facetField]
			if (objectField) {
				return objectField.description || facetField
			}

			return facetField
		},
		/**
		 * Get display name for an active facet field
		 * @param {string} facetField - Name of the facet field
		 * @return {string} Display name for the active facet
		 */
		getActiveFacetDisplayName(facetField) {
			// Handle nested facet fields (e.g., '@self' contains multiple sub-facets)
			if (facetField === '@self') {
				return 'Metadata'
			}

			// For individual fields, use the same logic as getFacetDisplayName
			return this.getFacetDisplayName(facetField)
		},
		/**
		 * Remove a specific filter
		 * @param {string} facetField - Name of the facet field to remove
		 */
		async removeFilter(facetField) {
			// Remove a specific active filter
			if (facetField === '@self') {
				// Remove all metadata facets
				const metadataFields = Object.keys(this.objectStore.activeFacets._facets?.['@self'] || {})
				for (const field of metadataFields) {
					await this.objectStore.updateActiveFacet(`@self.${field}`, null, false)
				}
			} else {
				// Remove individual field facet
				await this.objectStore.updateActiveFacet(facetField, null, false)
			}
			// Trigger search after removing filter
			await this.objectStore.refreshObjectList()
		},
		/**
		 * Clear all active filters
		 */
		async clearAllFilters() {
			// Clear all active facets and filters
			this.objectStore.setActiveFacets({})
			this.objectStore.clearAllFilters()
			await this.objectStore.refreshObjectList()
		},
		/**
		 * Get dropdown options for a specific field
		 * Uses facet results if available, otherwise uses sample values
		 * @param {string} fieldName - Name of the field
		 * @return {Array} Array of dropdown options
		 */
		getDropdownOptions(fieldName) {
			const options = []

			// First, try to get values from current facet results
			const facetData = this.objectStore.currentFacets[fieldName]
			if (facetData && facetData.buckets) {
				facetData.buckets.forEach(bucket => {
					if (bucket && bucket.key !== undefined) {
						options.push({
							value: bucket.key,
							label: bucket.label || bucket.key,
							count: bucket.results,
						})
					}
				})
			} else {
				// Fallback to sample values from facetable fields
				const fieldInfo = this.objectStore.availableObjectFieldFacets[fieldName]
				if (fieldInfo && fieldInfo.sample_values) {
					fieldInfo.sample_values.forEach(sampleValue => {
						if (sampleValue !== null && sampleValue !== undefined) {
							// Handle both string and object values
							let value, label, count
							if (typeof sampleValue === 'object' && sampleValue !== null && sampleValue.value !== undefined) {
								value = sampleValue.value
								label = sampleValue.label || sampleValue.value
								count = sampleValue.count
							} else {
								value = String(sampleValue)
								label = String(sampleValue)
								count = null
							}

							// Avoid duplicates and ensure value is valid
							if (value !== null && value !== undefined && !options.find(opt => opt.value === value)) {
								options.push({ value, label, count })
							}
						}
					})
				}
			}

			// Filter out any invalid options and sort
			return options
				.filter(option => option && option.value !== null && option.value !== undefined)
				.sort((a, b) => {
					if (a.count && b.count) {
						return b.count - a.count
					}
					return (a.label || '').localeCompare(b.label || '')
				})
		},
		/**
		 * Get currently selected values for a dropdown
		 * @param {string} fieldName - Name of the field
		 * @return {Array} Array of selected values
		 */
		getSelectedDropdownValues(fieldName) {
			// Get selected values from active filters
			const filterValues = this.objectStore.activeFilters[fieldName]
			if (!filterValues || !Array.isArray(filterValues)) {
				return []
			}

			// Convert filter values back to dropdown options
			const options = this.getDropdownOptions(fieldName)
			const selectedValues = []

			filterValues.forEach(value => {
				const option = options.find(opt => opt.value === value)
				if (option) {
					selectedValues.push(option)
				} else {
					// Create option for values not in current options
					selectedValues.push({
						value,
						label: value,
						count: null,
					})
				}
			})

			return selectedValues
		},
		/**
		 * Update dropdown selection for a field
		 * @param {string} fieldName - Name of the field
		 * @param {Array} selectedOptions - Array of selected options
		 */
		async updateDropdownSelection(fieldName, selectedOptions) {
			// eslint-disable-next-line no-console
			console.log('updateDropdownSelection called:', { fieldName, selectedOptions })

			try {
				// Extract values from selected options
				const selectedValues = selectedOptions && selectedOptions.length > 0
					? selectedOptions.map(option => option.value).filter(value => value !== null && value !== undefined)
					: []

				// eslint-disable-next-line no-console
				console.log('Selected values:', selectedValues)

				// Update the filter (not facet configuration)
				this.objectStore.updateFilter(fieldName, selectedValues)

				// eslint-disable-next-line no-console
				console.log('Updated filters:', this.objectStore.activeFilters)

				// Refresh the object list to apply the filter
				await this.objectStore.refreshObjectList()
			} catch (error) {
				// eslint-disable-next-line no-console
				console.error('Error in updateDropdownSelection:', error)
			}
		},
		/**
		 * Update a field facet with specific configuration
		 * @param {string} fieldName - Name of the field
		 * @param {object} facetConfig - Facet configuration object
		 */
		async updateFieldFacet(fieldName, facetConfig) {
			// eslint-disable-next-line no-console
			console.log('updateFieldFacet called:', { fieldName, facetConfig })

			// Get current active facets
			const currentFacets = { ...this.objectStore.activeFacets }
			// eslint-disable-next-line no-console
			console.log('Current facets before update:', currentFacets)

			// Ensure _facets structure exists
			if (!currentFacets._facets) {
				currentFacets._facets = {}
			}

			// Set the field facet configuration
			currentFacets._facets[fieldName] = facetConfig
			// eslint-disable-next-line no-console
			console.log('Current facets after update:', currentFacets)

			// Update store and refresh
			this.objectStore.setActiveFacets(currentFacets)
			// eslint-disable-next-line no-console
			console.log('About to refresh object list...')
			await this.objectStore.refreshObjectList()
			// eslint-disable-next-line no-console
			console.log('Object list refreshed')
		},
		/**
		 * Capitalize field names for display
		 * @param {string} fieldName - Name of the field to capitalize
		 * @return {string} Capitalized field name
		 */
		capitalizeFieldName(fieldName) {
			// Handle common field names and provide proper capitalization
			const specialCases = {
				uuid: 'UUID',
				id: 'ID',
				uri: 'URI',
				url: 'URL',
				api: 'API',
				xml: 'XML',
				json: 'JSON',
				html: 'HTML',
				css: 'CSS',
				js: 'JS',
			}

			// Check for special cases first
			if (specialCases[fieldName.toLowerCase()]) {
				return specialCases[fieldName.toLowerCase()]
			}

			// Standard capitalization - capitalize first letter of each word
			return fieldName
				.split(/[\s_-]+/)
				.map(word => word.charAt(0).toUpperCase() + word.slice(1).toLowerCase())
				.join(' ')
		},
		/**
		 * Format date range for display
		 * @param {object} dateRange - Date range object with min and max dates
		 * @return {string} Formatted date range string
		 */
		formatDateRange(dateRange) {
			if (!dateRange || !dateRange.min || !dateRange.max) {
				return ''
			}

			const formatDate = (dateStr) => {
				try {
					return new Date(dateStr).toLocaleDateString()
				} catch {
					return dateStr
				}
			}

			return `${formatDate(dateRange.min)} - ${formatDate(dateRange.max)}`
		},
		/**
		 * Get date range value for a specific field and bound (from/to)
		 * @param {string} fieldName - Name of the field
		 * @param {string} bound - Bound type ('from' or 'to')
		 * @return {string|null} Date value or null
		 */
		getDateRangeValue(fieldName, bound) {
			// eslint-disable-next-line no-console
			console.log('getDateRangeValue called:', { fieldName, bound })

			const activeFacetData = this.objectStore.activeFacets._facets?.['@self']?.[fieldName]
			// eslint-disable-next-line no-console
			console.log('Active facet data for', fieldName, ':', activeFacetData)

			if (!activeFacetData || activeFacetData.type !== 'range' || !activeFacetData.ranges) {
				// eslint-disable-next-line no-console
				console.log('No valid range data found, returning null')
				return null
			}

			// Find the range that matches our bound
			const range = activeFacetData.ranges.find(r => r.from || r.to)
			if (!range) {
				// eslint-disable-next-line no-console
				console.log('No range found, returning null')
				return null
			}

			const value = bound === 'from' ? range.from : range.to
			// eslint-disable-next-line no-console
			console.log('Returning value:', value)
			return value
		},
		/**
		 * Update date range for a field
		 * @param {string} fieldName - Name of the field
		 * @param {string} bound - Bound type ('from' or 'to')
		 * @param {string} value - Date value
		 */
		async updateDateRange(fieldName, bound, value) {
			// eslint-disable-next-line no-console
			console.log('updateDateRange called:', { fieldName, bound, value })

			// Get current facet configuration
			const currentFacets = { ...this.objectStore.activeFacets }
			// eslint-disable-next-line no-console
			console.log('Current facets before update:', currentFacets)

			// Ensure structure exists
			if (!currentFacets._facets) {
				currentFacets._facets = {}
			}
			if (!currentFacets._facets['@self']) {
				currentFacets._facets['@self'] = {}
			}

			// Get or create the range facet
			let rangeFacet = currentFacets._facets['@self'][fieldName]
			if (!rangeFacet || rangeFacet.type !== 'range') {
				rangeFacet = {
					type: 'range',
					ranges: [{}],
				}
			}

			// Update the range
			if (!rangeFacet.ranges || rangeFacet.ranges.length === 0) {
				rangeFacet.ranges = [{}]
			}

			if (bound === 'from') {
				rangeFacet.ranges[0].from = value
			} else {
				rangeFacet.ranges[0].to = value
			}

			// Set the facet configuration
			currentFacets._facets['@self'][fieldName] = rangeFacet
			// eslint-disable-next-line no-console
			console.log('Updated facets:', currentFacets)

			try {
				// Update store facets
				this.objectStore.setActiveFacets(currentFacets)

				// IMPORTANT: Also update activeFilters with proper operator-based filters
				this.updateActiveFiltersFromDateRange(fieldName, rangeFacet)

				// eslint-disable-next-line no-console
				console.log('About to refresh object list...')
				await this.objectStore.refreshObjectList()
				// eslint-disable-next-line no-console
				console.log('Object list refreshed successfully')
			} catch (error) {
				// eslint-disable-next-line no-console
				console.error('Error in updateDateRange:', error)
			}
		},

		/**
		 * Update activeFilters based on date range facet
		 * Converts range facets to proper filter parameters like @self[created][>=]=2025-06-24T00:00:00+00:00
		 * @param {string} fieldName - Name of the field
		 * @param {object} rangeFacet - Range facet configuration object
		 */
		updateActiveFiltersFromDateRange(fieldName, rangeFacet) {
			// eslint-disable-next-line no-console
			console.log('updateActiveFiltersFromDateRange called:', { fieldName, rangeFacet })

			// Get current active filters
			const currentFilters = { ...this.objectStore.activeFilters }

			// Remove existing date range filters for this field
			const filterPrefix = `@self.${fieldName}`
			Object.keys(currentFilters).forEach(key => {
				if (key.startsWith(filterPrefix + '[')) {
					delete currentFilters[key]
				}
			})

			// Add new range filters if we have valid ranges
			if (rangeFacet && rangeFacet.ranges && rangeFacet.ranges.length > 0) {
				rangeFacet.ranges.forEach(range => {
					if (range.from) {
						// Convert to database format (Y-m-d H:i:s)
						const fromDate = new Date(range.from).toISOString().replace('T', ' ').replace(/\.000Z$/, '')
						currentFilters[`@self.${fieldName}[>=]`] = [fromDate]
						// eslint-disable-next-line no-console
						console.log(`Added filter: @self.${fieldName}[>=] = ${fromDate}`)
					}
					if (range.to) {
						// Convert to database format and set to end of day for the filter
						const toDate = new Date(range.to)
						toDate.setHours(23, 59, 59, 999) // End of day
						const toDateISO = toDate.toISOString().replace('T', ' ').replace(/\.000Z$/, '')
						currentFilters[`@self.${fieldName}[<=]`] = [toDateISO]
						// eslint-disable-next-line no-console
						console.log(`Added filter: @self.${fieldName}[<=] = ${toDateISO}`)
					}
				})
			}

			// Update the store
			this.objectStore.setActiveFilters(currentFilters)

			// eslint-disable-next-line no-console
			console.log('updateActiveFiltersFromDateRange - Updated activeFilters:', currentFilters)
		},
		/**
		 * Check if a field has an active date range
		 * @param {string} fieldName - Name of the field to check
		 * @return {boolean} True if field has an active date range
		 */
		hasDateRange(fieldName) {
			const activeFacetData = this.objectStore.activeFacets._facets?.['@self']?.[fieldName]
			return activeFacetData
				   && activeFacetData.type === 'range'
				   && activeFacetData.ranges
				   && activeFacetData.ranges.length > 0
				   && (activeFacetData.ranges[0].from || activeFacetData.ranges[0].to)
		},
		/**
		 * Clear date range for a field
		 * @param {string} fieldName - Name of the field to clear date range for
		 */
		async clearDateRange(fieldName) {
			// Remove the date range facet
			const currentFacets = { ...this.objectStore.activeFacets }

			if (currentFacets._facets?.['@self']?.[fieldName]) {
				delete currentFacets._facets['@self'][fieldName]
			}

			// Also clear the corresponding activeFilters
			const currentFilters = { ...this.objectStore.activeFilters }
			const filterPrefix = `@self.${fieldName}`
			Object.keys(currentFilters).forEach(key => {
				if (key.startsWith(filterPrefix + '[')) {
					delete currentFilters[key]
				}
			})

			// Update store and refresh
			this.objectStore.setActiveFacets(currentFacets)
			this.objectStore.setActiveFilters(currentFilters)
			await this.objectStore.refreshObjectList()
		},
		/**
		 * Get dropdown options for a metadata field
		 * Uses facet results if available, otherwise uses sample values
		 * @param {string} fieldName - Name of the metadata field
		 * @return {Array} Array of dropdown options
		 */
		getMetadataDropdownOptions(fieldName) {
			const options = []

			// eslint-disable-next-line no-console
			console.log('getMetadataDropdownOptions for:', fieldName)
			// eslint-disable-next-line no-console
			console.log('Available facets:', this.objectStore.currentFacets)

			// First, try to get values from current facet results
			const facetData = this.objectStore.currentFacets?.['@self']?.[fieldName]
			// eslint-disable-next-line no-console
			console.log('Facet data for', fieldName, ':', facetData)

			if (facetData && facetData.buckets) {
				facetData.buckets.forEach(bucket => {
					if (bucket && bucket.key !== undefined) {
						options.push({
							value: bucket.key,
							label: bucket.label || bucket.key,
							count: bucket.results,
						})
					}
				})
				// eslint-disable-next-line no-console
				console.log('Options from facet data:', options)
			} else {
				// Fallback to sample values from facetable fields
				const fieldInfo = this.objectStore.availableMetadataFacets[fieldName]
				// eslint-disable-next-line no-console
				console.log('Field info for', fieldName, ':', fieldInfo)

				if (fieldInfo && fieldInfo.sample_values) {
					fieldInfo.sample_values.forEach(sampleValue => {
						if (sampleValue !== null && sampleValue !== undefined) {
							// Handle both string and object values
							let value, label, count
							if (typeof sampleValue === 'object' && sampleValue !== null && sampleValue.value !== undefined) {
								value = sampleValue.value
								label = sampleValue.label || sampleValue.value
								count = sampleValue.count
							} else {
								value = String(sampleValue)
								label = String(sampleValue)
								count = null
							}

							// Avoid duplicates and ensure value is valid
							if (value !== null && value !== undefined && !options.find(opt => opt.value === value)) {
								options.push({ value, label, count })
							}
						}
					})
					// eslint-disable-next-line no-console
					console.log('Options from sample values:', options)
				}
			}

			// Filter out any invalid options and sort
			const finalOptions = options
				.filter(option => option && option.value !== null && option.value !== undefined)
				.sort((a, b) => {
					if (a.count && b.count) {
						return b.count - a.count
					}
					return (a.label || '').localeCompare(b.label || '')
				})

			// eslint-disable-next-line no-console
			console.log('Final options for', fieldName, ':', finalOptions)
			return finalOptions
		},
		/**
		 * Get currently selected values for a metadata dropdown
		 * @param {string} fieldName - Name of the metadata field
		 * @return {Array} Array of selected dropdown options
		 */
		getSelectedMetadataDropdownValues(fieldName) {
			// Metadata filters use @self. prefix
			const metadataKey = `@self.${fieldName}`
			const filterValues = this.objectStore.activeFilters[metadataKey]
			if (!filterValues || !Array.isArray(filterValues)) {
				return []
			}

			// Convert filter values back to dropdown options
			const options = this.getMetadataDropdownOptions(fieldName)
			const selectedValues = []

			filterValues.forEach(value => {
				const option = options.find(opt => opt.value === value)
				if (option) {
					selectedValues.push(option)
				} else {
					// Create option for values not in current options
					selectedValues.push({
						value,
						label: value,
						count: null,
					})
				}
			})

			return selectedValues
		},
		/**
		 * Update metadata dropdown selection for a field
		 * @param {string} fieldName - Name of the metadata field
		 * @param {Array} selectedOptions - Array of selected options
		 */
		async updateMetadataDropdownSelection(fieldName, selectedOptions) {
			// eslint-disable-next-line no-console
			console.log('updateMetadataDropdownSelection called:', { fieldName, selectedOptions })

			try {
				// Extract values from selected options
				const selectedValues = selectedOptions && selectedOptions.length > 0
					? selectedOptions.map(option => option.value).filter(value => value !== null && value !== undefined)
					: []

				// eslint-disable-next-line no-console
				console.log('Selected metadata values:', selectedValues)

				// Update the filter with @self. prefix for metadata fields
				const metadataKey = `@self.${fieldName}`
				this.objectStore.updateFilter(metadataKey, selectedValues)

				// eslint-disable-next-line no-console
				console.log('Updated filters:', this.objectStore.activeFilters)

				// Refresh the object list to apply the filter
				await this.objectStore.refreshObjectList()
			} catch (error) {
				// eslint-disable-next-line no-console
				console.error('Error in updateMetadataDropdownSelection:', error)
			}
		},
	},
}
</script>

<style scoped>
.facet-container {
	padding: 16px;
}

.facet-loading {
	text-align: center;
	padding: 20px;
}

.facet-section {
	margin-bottom: 24px;
	padding-bottom: 16px;
	border-bottom: 1px solid var(--color-border);
}

.facet-section:last-child {
	border-bottom: none;
	margin-bottom: 0;
}

.facet-section-title {
	font-size: 14px;
	font-weight: 600;
	color: var(--color-main-text);
	margin: 0 0 12px 0;
}

.facet-list {
	display: flex;
	flex-direction: column;
	gap: 8px;
}

.facet-item {
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.facet-info {
	color: var(--color-text-maxcontrast);
	font-size: 12px;
	margin-left: 24px;
}

.facet-dropdowns {
	display: flex;
	flex-direction: column;
	gap: 16px;
	margin-bottom: 16px;
}

.facet-dropdown-item {
	display: flex;
	flex-direction: column;
	gap: 8px;
}

.facet-dropdown-label {
	font-size: 14px;
	font-weight: 500;
	color: var(--color-main-text);
	cursor: help;
}

.field-coverage {
	font-size: 12px;
	font-weight: normal;
	color: var(--color-text-maxcontrast);
}

.dropdown-option {
	display: flex;
	justify-content: space-between;
	align-items: center;
	width: 100%;
}

.option-label {
	flex: 1;
	text-align: left;
}

.option-count {
	font-size: 12px;
	color: var(--color-text-maxcontrast);
	background: var(--color-background-dark);
	padding: 2px 6px;
	border-radius: 12px;
	min-width: 20px;
	text-align: center;
}

.selected-option {
	font-weight: 500;
}

.date-range-container {
	display: flex;
	flex-direction: column;
	gap: 12px;
	margin-bottom: 16px;
}

.date-range-item {
	display: flex;
	flex-direction: column;
	gap: 8px;
}

.date-range-label {
	font-size: 14px;
	font-weight: 500;
	color: var(--color-main-text);
	cursor: help;
}

.date-range-inputs {
	display: flex;
	flex-direction: column;
	gap: 8px;
}

.date-range-row {
	display: flex;
	align-items: center;
	gap: 8px;
}

.date-range-row label {
	font-size: 12px;
	color: var(--color-text-maxcontrast);
	min-width: 40px;
}

.date-range-context {
	display: flex;
	align-items: center;
	justify-content: space-between;
	font-size: 12px;
	color: var(--color-text-maxcontrast);
	margin-top: 4px;
}

.facet-empty {
	text-align: center;
	color: var(--color-text-maxcontrast);
	padding: 20px;
	font-style: italic;
}
</style>

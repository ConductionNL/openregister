<script setup>
import { schemaStore, navigationStore } from '../../store/store.js'
import SchemaStatsBlock from '../../components/SchemaStatsBlock.vue'
</script>

<template>
	<NcDialog :name="'Analyze Schema Properties'"
		size="large"
		:can-close="true"
		@update:open="handleDialogClose">
		<NcNoteCard v-if="success" type="success">
			<p>Schema successfully updated with {{ selectedProperties.length }} properties</p>
		</NcNoteCard>

		<NcNoteCard v-if="error" type="error">
			<p>{{ error }}</p>
		</NcNoteCard>

		<!-- Info Card - Visible only before/during analysis -->
		<div v-if="!explorationData && !success" class="info-section">
			<NcNoteCard type="info">
				<h4>{{ t('openregister', 'This analysis may take some time') }}</h4>
				<p>{{ t('openregister', "We'll scan all objects belonging to this schema to discover new properties and analyze existing properties for potential enhancements. The process involves examining each object's data structure, identifying properties not defined in the current schema, and finding opportunities to improve existing property definitions with better constraints, formats, and validation rules.") }}</p>
			</NcNoteCard>
		</div>

		<div v-if="!success">
			<!-- Gray Well Container -->
			<div class="well-container">
				<NcProgressBar v-if="loading" :indeterminate="true" />
				<div v-if="loading" class="loading-analysis">
					<div class="object-count-section">
						<h4>{{ t('openregister', 'Objects being analyzed') }}</h4>
						<div class="object-count-centered">
							<span class="count-value">{{ objectCount }}</span>
							<span class="count-label">{{ t('openregister', 'objects') }}</span>
						</div>
					</div>
				</div>
				<div v-else-if="!explorationData" class="no-analysis">
					<div class="analysis-info">
						<SchemaStatsBlock
							:object-count="objectCount"
							:object-stats="objectStats"
							:loading="false"
							:title="t('openregister', 'Objects to be analyzed')" />

						<div class="steps-section">
							<h4>{{ t('openregister', 'Analysis steps:') }}</h4>
							<ol class="steps-list">
								<li>{{ t('openregister', 'Retrieve all objects for this schema') }}</li>
								<li>{{ t('openregister', 'Extract properties from each object') }}</li>
								<li>{{ t('openregister', 'Detect data types and patterns') }}</li>
								<li>{{ t('openregister', 'Identify properties not in the schema') }}</li>
								<li>{{ t('openregister', 'Analyze existing properties for improvement opportunities') }}</li>
								<li>{{ t('openregister', 'Compare current schema with real object data') }}</li>
								<li>{{ t('openregister', 'Generate recommendations and confidence scores') }}</li>
							</ol>
						</div>
					</div>
				</div>

				<!-- Analysis Results (inside the well) -->
				<div v-else-if="explorationData" class="analysis-summary">
					<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 16px; margin-bottom: 20px;">
						<div style="background: white; border: 2px solid #ddd; border-radius: 8px; padding: 20px; text-align: center; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">
							<div style="font-size: 2rem; font-weight: bold; color: #0066cc; margin-bottom: 8px;">
								{{ explorationData.total_objects }}
							</div>
							<div style="font-size: 0.9rem; color: #666; text-transform: uppercase; font-weight: 600;">
								{{ t('openregister', 'Objects Analyzed') }}
							</div>
						</div>
						<div style="background: white; border: 2px solid #ddd; border-radius: 8px; padding: 20px; text-align: center; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">
							<div style="font-size: 2rem; font-weight: bold; color: #0066cc; margin-bottom: 8px;">
								{{ explorationData.analysis_summary?.new_properties_count || Object.keys(explorationData.discovered_properties || {}).length }}
							</div>
							<div style="font-size: 0.9rem; color: #666; text-transform: uppercase; font-weight: 600;">
								{{ t('openregister', 'New Properties') }}
							</div>
						</div>
						<div style="background: white; border: 2px solid #ddd; border-radius: 8px; padding: 20px; text-align: center; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">
							<div style="font-size: 2rem; font-weight: bold; color: #0066cc; margin-bottom: 8px;">
								{{ explorationData.analysis_summary?.existing_properties_improvements || 0 }}
							</div>
							<div style="font-size: 0.9rem; color: #666; text-transform: uppercase; font-weight: 600;">
								{{ t('openregister', 'Existing Improvements') }}
							</div>
						</div>
						<div style="background: white; border: 2px solid #ddd; border-radius: 8px; padding: 20px; text-align: center; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">
							<div style="font-size: 2rem; font-weight: bold; color: #0066cc; margin-bottom: 8px;">
								{{ selectedProperties.length }}
							</div>
							<div style="font-size: 0.9rem; color: #666; text-transform: uppercase; font-weight: 600;">
								{{ t('openregister', 'Selected') }}
							</div>
						</div>
					</div>
					<div style="text-align: center; padding: 12px; background: #e9ecef; border-radius: 6px; border: 1px solid #ddd; color: #495057; font-size: 0.9rem;">
						<strong>{{ t('openregister', 'Analysis completed:') }}</strong> {{ new Date(explorationData.analysis_date).toLocaleString() }}
					</div>
				</div>
			</div>

			<!-- Analysis Controls -->
			<div class="analysis-controls">
				<NcButton
					v-if="!explorationData && !loading"
					type="primary"
					:disabled="!schemaStore.schemaItem"
					@click="startAnalysis">
					<template #icon>
						<DatabaseSearch :size="20" />
					</template>
					Analyze Objects
				</NcButton>

				<NcButton
					v-else-if="!loading"
					type="secondary"
					@click="startAnalysis">
					<template #icon>
						<Refresh :size="20" />
					</template>
					Re-analyze
				</NcButton>
			</div>

			<!-- Discovered Properties -->
			<div v-if="explorationData && !loading" class="discovered-properties">
				<h3>{{ t('openregister', 'Discovered Properties') }}</h3>

				<!-- Filter Controls -->
				<div class="property-filters">
					<div class="filter-section">
						<label class="filter-label">{{ t('openregister', 'Filter Properties') }}</label>
						<NcTextField
							v-model="propertyFilter"
							:placeholder="t('openregister', 'Search property names...')" />
					</div>

					<div class="filter-section">
						<label class="filter-label">{{ t('openregister', 'Confidence Level') }}</label>
						<NcSelect
							v-model="confidenceFilter"
							:options="confidenceFilterOptions" />
					</div>

					<div class="filter-section">
						<label class="filter-label">{{ t('openregister', 'Property Type') }}</label>
						<NcSelect
							v-model="typeFilter"
							:options="typeFilterOptions" />
					</div>
				</div>

				<!-- Properties List -->
				<div class="properties-list">
					<div v-if="filteredSuggestions.length === 0" class="no-results">
						<p>{{ t('openregister', 'No properties match your filters.') }}</p>
					</div>

					<div v-for="(suggestion, index) in paginatedSuggestions"
						:key="`suggestion-${index}-${suggestion.property_name}`"
						class="property-card"
						:class="{ selected: isPropertySelected(suggestion.property_name) }">
						<!-- Property Header -->
						<div class="property-header">
							<div class="property-info">
								<h4>{{ suggestion.property_name }}</h4>
								<div class="property-meta">
									<span class="confidence-badge" :class="'confidence-' + suggestion.confidence">
										{{ suggestion.confidence.toUpperCase() }}
									</span>
									<span class="usage-percentage">
										{{ t('openregister', '{percentage}% of objects', { percentage: suggestion.usage_percentage }) }}
									</span>
									<!-- New Property Status -->
									<span v-if="!suggestion.improvement_status || suggestion.improvement_status !== 'existing'" class="new-property-status">
										{{ t('openregister', 'New Property') }}
									</span>
									<!-- Existing Property Improvement Status -->
									<span v-if="suggestion.improvement_status === 'existing'" class="improvement-status">
										{{ t('openregister', 'Improved Property') }}
									</span>
									<!-- Issues Badge -->
									<span v-if="suggestion.issues && suggestion.issues.length > 0" class="issues-badge">
										{{ t('openregister', '{count} issues', { count: suggestion.issues.length }) }}
									</span>
								</div>
							</div>

							<div class="property-actions">
								<NcCheckboxRadioSwitch
									:checked="isPropertySelected(suggestion.property_name)"
									@update:checked="togglePropertySelection(suggestion.property_name)" />
							</div>
						</div>

						<!-- Property Details -->
						<div class="property-details">
							<div class="detail-item">
								<span class="detail-label">{{ t('openregister', 'Recommended Type:') }}</span>
								<span class="detail-value">{{ suggestion.recommended_type }}</span>
							</div>

							<div v-if="suggestion.detected_format" class="detail-item">
								<span class="detail-label">{{ t('openregister', 'Detected Format:') }}</span>
								<span class="detail-value format-badge">{{ suggestion.detected_format }}</span>
							</div>

							<div v-if="suggestion.type_variations && suggestion.type_variations.length > 1" class="detail-item">
								<span class="detail-label">{{ t('openregister', 'Type Variations:') }}</span>
								<span class="detail-value">{{ suggestion.type_variations.join(', ') }}</span>
							</div>

							<div v-if="suggestion.numeric_range" class="detail-item">
								<span class="detail-label">{{ t('openregister', 'Numeric Range:') }}</span>
								<span class="detail-value">{{ suggestion.numeric_range.min }} - {{ suggestion.numeric_range.max }} ({{ suggestion.numeric_range.type }})</span>
							</div>

							<div v-if="suggestion.min_length !== null && suggestion.max_length !== null" class="detail-item">
								<span class="detail-label">{{ t('openregister', 'Length Range:') }}</span>
								<span class="detail-value">{{ suggestion.min_length }} - {{ suggestion.max_length }} characters</span>
							</div>
							<div v-else-if="suggestion.max_length > 0" class="detail-item">
								<span class="detail-label">{{ t('openregister', 'Max Length:') }}</span>
								<span class="detail-value">{{ suggestion.max_length }}</span>
							</div>

							<div v-if="suggestion.string_patterns && suggestion.string_patterns.length > 0" class="detail-item">
								<span class="detail-label">{{ t('openregister', 'Patterns:') }}</span>
								<span class="detail-value">
									<span v-for="(pattern, patternIndex) in suggestion.string_patterns" :key="`pattern-${patternIndex}`" class="pattern-tag">
										{{ pattern }}
									</span>
								</span>
							</div>

							<div class="detail-item">
								<span class="detail-label">{{ t('openregister', 'Description:') }}</span>
								<span class="detail-value">{{ suggestion.description }}</span>
							</div>
							<!-- Current vs Recommended Type for existing properties -->
							<div v-if="suggestion.improvement_status === 'existing' && suggestion.current_type && suggestion.current_type !== suggestion.recommended_type" class="detail-item">
								<span class="detail-label">{{ t('openregister', 'Current Type:') }}</span>
								<span class="detail-value type-warning">{{ suggestion.current_type }}</span>
							</div>
						</div>

						<!-- Improvement Details (for existing properties) -->
						<div v-if="suggestion.improvement_status === 'existing' && suggestion.issues && suggestion.issues.length > 0" class="improvement-details">
							<h5>{{ t('openregister', 'Detected Issues:') }}</h5>
							<div class="issues-list">
								<div v-for="(issue, issueIndex) in getIssueDetails(suggestion.issues)" :key="`issue-${issueIndex}`" class="issue-item">
									<div class="issue-badge" :class="'issue-' + issue.type">
										{{ getIssueLabel(issue.type) }}
									</div>
									<div class="issue-description">
										{{ issue.description }}
									</div>
								</div>
							</div>

							<h5>{{ t('openregister', 'Recommendations:') }}</h5>
							<div class="suggestions-list">
								<div v-for="(suggestion_item, suggestionIndex) in suggestion.suggestions" :key="`suggestion-${suggestionIndex}`" class="suggestion-item">
									<div class="suggestion-field">
										<strong>{{ suggestion_item.field }}:</strong>
									</div>
									<div class="suggestion-change">
										<span class="current">{{ suggestion_item.current }}</span> → <span class="recommended">{{ suggestion_item.recommended }}</span>
									</div>
									<div class="suggestion-desc">
										{{ suggestion_item.description }}
									</div>
								</div>
							</div>
						</div>

						<!-- Examples -->
						<div v-if="suggestion.examples && suggestion.examples.length > 0" class="property-examples">
							<div class="examples-header">
								<h5>{{ t('openregister', 'Sample Values:') }}</h5>
							</div>
							<div class="example-values">
								<span v-for="(example, exampleIndex) in suggestion.examples" :key="`example-${exampleIndex}`" class="example-tag">
									{{ formatExample(example) }}
								</span>
							</div>
						</div>

						<!-- Type-specific Configuration -->
						<div v-if="isPropertySelected(suggestion.property_name)" class="property-config">
							<h5>{{ t('openregister', 'Property Configuration:') }}</h5>

							<div class="config-fields">
								<NcTextField
									v-model="selectedPropertiesConfig[suggestion.property_name].title"
									:label="t('openregister', 'Property Title')"
									:placeholder="suggestion.property_name" />

								<NcTextField
									v-model="selectedPropertiesConfig[suggestion.property_name].technicalDescription"
									:label="t('openregister', 'Technical Description')"
									:placeholder="t('openregister', 'Technical description for developers and administrators')" />

								<NcSelect
									v-if="suggestion.recommended_type !== suggestion.type"
									v-model="selectedPropertiesConfig[suggestion.property_name].type"
									:options="typeOptions"
									:input-label="t('openregister', 'Property Type')"
									:label-outside="true" />

								<NcSelect
									v-if="suggestion.detected_format"
									v-model="selectedPropertiesConfig[suggestion.property_name].format"
									:options="formatOptions(suggestion)"
									:input-label="t('openregister', 'Format')"
									:label-outside="true" />

								<!-- String constraints -->
								<div v-if="selectedPropertiesConfig[suggestion.property_name].type === 'string'" class="constraints-section">
									<h5>{{ t('openregister', 'String Constraints') }}</h5>
									<NcTextField
										v-model="selectedPropertiesConfig[suggestion.property_name].maxLength"
										:label="t('openregister', 'Max Length')"
										type="number"
										:placeholder="suggestion.max_length ? suggestion.max_length.toString() : ''" />

									<NcTextField
										v-model="selectedPropertiesConfig[suggestion.property_name].minLength"
										:label="t('openregister', 'Min Length')"
										type="number"
										:placeholder="suggestion.min_length ? suggestion.min_length.toString() : ''" />

									<NcTextField
										v-model="selectedPropertiesConfig[suggestion.property_name].pattern"
										:label="t('openregister', 'Pattern (regex)')"
										placeholder="^[A-Za-z]+$" />
								</div>

								<!-- Number constraints -->
								<div v-if="['number', 'integer'].includes(selectedPropertiesConfig[suggestion.property_name].type)" class="constraints-section">
									<h5>{{ t('openregister', 'Number Constraints') }}</h5>
									<NcTextField
										v-model="selectedPropertiesConfig[suggestion.property_name].minimum"
										:label="t('openregister', 'Minimum')"
										type="number" />

									<NcTextField
										v-model="selectedPropertiesConfig[suggestion.property_name].maximum"
										:label="t('openregister', 'Maximum')"
										type="number" />

									<NcTextField
										v-model="selectedPropertiesConfig[suggestion.property_name].multipleOf"
										:label="t('openregister', 'Multiple Of')"
										type="number" />

									<NcCheckboxRadioSwitch
										v-model="selectedPropertiesConfig[suggestion.property_name].exclusiveMin">
										{{ t('openregister', 'Exclusive Minimum') }}
									</NcCheckboxRadioSwitch>

									<NcCheckboxRadioSwitch
										v-model="selectedPropertiesConfig[suggestion.property_name].exclusiveMax">
										{{ t('openregister', 'Exclusive Maximum') }}
									</NcCheckboxRadioSwitch>
								</div>

								<!-- Property Behaviors - Compact Two Column Layout -->
								<div class="behaviors-section">
									<h5>{{ t('openregister', 'Property Behaviors') }}</h5>
									<div class="behaviors-grid">
										<!-- Left Column -->
										<div class="behavior-column">
											<div class="behavior-item">
												<NcCheckboxRadioSwitch v-model="selectedPropertiesConfig[suggestion.property_name].required">
													{{ t('openregister', 'Required field') }}
												</NcCheckboxRadioSwitch>
											</div>
											<div class="behavior-item">
												<NcCheckboxRadioSwitch v-model="selectedPropertiesConfig[suggestion.property_name].immutable">
													{{ t('openregister', 'Immutable') }}
												</NcCheckboxRadioSwitch>
											</div>
											<div class="behavior-item">
												<NcCheckboxRadioSwitch v-model="selectedPropertiesConfig[suggestion.property_name].deprecated">
													{{ t('openregister', 'Deprecated') }}
												</NcCheckboxRadioSwitch>
											</div>
											<div class="behavior-item">
												<NcCheckboxRadioSwitch v-model="selectedPropertiesConfig[suggestion.property_name].visible">
													{{ t('openregister', 'Visible to users') }}
												</NcCheckboxRadioSwitch>
											</div>
										</div>

										<!-- Right Column -->
										<div class="behavior-column">
											<div class="behavior-item">
												<NcCheckboxRadioSwitch v-model="selectedPropertiesConfig[suggestion.property_name].hideOnCollection">
													{{ t('openregister', 'Hide in list view') }}
												</NcCheckboxRadioSwitch>
											</div>
											<div class="behavior-item">
												<NcCheckboxRadioSwitch v-model="selectedPropertiesConfig[suggestion.property_name].hideOnForm">
													{{ t('openregister', 'Hide in forms') }}
												</NcCheckboxRadioSwitch>
											</div>
											<div class="behavior-item">
												<NcCheckboxRadioSwitch v-model="selectedPropertiesConfig[suggestion.property_name].facetable">
													{{ t('openregister', 'Enable faceting') }}
												</NcCheckboxRadioSwitch>
											</div>
										</div>
									</div>
								</div>

								<!-- Additional property configuration fields -->
								<NcTextField
									v-model="selectedPropertiesConfig[suggestion.property_name].displayTitle"
									:label="t('openregister', 'Title')"
									:placeholder="suggestion.property_name" />

								<NcTextArea
									v-model="selectedPropertiesConfig[suggestion.property_name].userDescription"
									:label="t('openregister', 'User Description')"
									:placeholder="t('openregister', 'User-friendly description shown in forms and help text')" />

								<NcTextField
									v-model="selectedPropertiesConfig[suggestion.property_name].example"
									:label="t('openregister', 'Example Value')"
									placeholder="Example value for this property" />

								<NcTextField
									v-model="selectedPropertiesConfig[suggestion.property_name].order"
									:label="t('openregister', 'Order')"
									type="number"
									placeholder="0" />

								<div v-if="suggestion.type_variations && suggestion.type_variations.length > 1" class="config-warning">
									<NcNoteCard type="warning">
										<p>{{ t('openregister', "Warning: This property has inconsistent types: {types}. Consider if all objects should have the same type.", { types: suggestion.type_variations.join(', ') }) }}</p>
									</NcNoteCard>
								</div>
							</div>
						</div>
					</div>
				</div>

				<!-- Pagination -->
				<div v-if="filteredSuggestions.length > itemsPerPage" class="pagination-controls">
					<PaginationComponent
						:current-page="currentPage"
						:total-pages="totalPages"
						:total-items="filteredSuggestions.length"
						:current-page-size="itemsPerPage"
						:min-items-to-show="5"
						@page-changed="onPageChanged"
						@page-size-changed="onPageSizeChanged" />
				</div>

				<!-- Selection Summary and Actions -->
				<div v-if="selectedProperties.length > 0" class="selection-summary">
					<NcNoteCard type="info">
						<p>
							{{ t('openregister', 'You have selected {count} properties to add to the schema.', { count: selectedProperties.length }) }}
						</p>
					</NcNoteCard>

					<div class="summary-actions">
						<NcButton type="secondary" @click="clearSelection">
							{{ t('openregister', 'Clear Selection') }}
						</NcButton>
						<NcButton type="secondary" @click="selectAll">
							{{ t('openregister', 'Select All') }}
						</NcButton>
						<NcButton type="primary" :disabled="loading || selectedProperties.length === 0" @click="updateSchema">
							<template #icon>
								<Check :size="20" />
							</template>
							{{ t('openregister', 'Apply Changes') }}
						</NcButton>
					</div>
				</div>

				<!-- Analyze Objects Button (show when no analysis has been done) -->
				<div v-if="!explorationData && !loading" class="modal-footer">
					<NcButton
						:disabled="analysisStarted"
						type="primary"
						@click="startAnalysis">
						<template #icon>
							<NcLoadingIcon v-if="analysisStarted" :size="16" />
							<DatabaseSearch v-else :size="16" />
						</template>
						{{ analysisStarted ? t('openregister', 'Analyzing...') : t('openregister', 'Analyze Objects') }}
					</NcButton>
				</div>

				<!-- Close Button (show when results are available) -->
				<div v-else-if="explorationData && explorationData.suggestions" class="modal-footer">
					<NcButton type="secondary" @click="closeDialog">
						{{ t('openregister', 'Close') }}
					</NcButton>
				</div>
			</div>
		</div>
	</NcDialog>
</template>

<script>
import { NcDialog, NcNoteCard, NcButton, NcProgressBar, NcTextField, NcTextArea, NcSelect, NcCheckboxRadioSwitch, NcLoadingIcon } from '@nextcloud/vue'
import DatabaseSearch from 'vue-material-design-icons/DatabaseSearch.vue'
import Refresh from 'vue-material-design-icons/Refresh.vue'
import Check from 'vue-material-design-icons/Check.vue'
import PaginationComponent from '../../components/PaginationComponent.vue'

export default {
	name: 'ExploreSchema',
	components: {
		NcDialog,
		NcNoteCard,
		NcButton,
		NcProgressBar,
		NcTextField,
		NcTextArea,
		NcSelect,
		NcCheckboxRadioSwitch,
		NcLoadingIcon,
		DatabaseSearch,
		Refresh,
		Check,
		PaginationComponent,
		SchemaStatsBlock,
	},
	data() {
		return {
			loading: false,
			error: null,
			success: false,
			explorationData: null,
			selectedProperties: [],
			selectedPropertiesConfig: {},
			propertyFilter: '',
			confidenceFilter: 'all',
			typeFilter: 'all',
			showOnlySelected: false,
			currentPage: 1,
			itemsPerPage: 10,
			analysisStarted: false,
			objectCount: 0,
			objectStats: null,
		}
	},
	computed: {
		typeOptions() {
			return [
				{ label: 'String', value: 'string', key: 'string' },
				{ label: 'Integer', value: 'integer', key: 'integer' },
				{ label: 'Number', value: 'number', key: 'number' },
				{ label: 'Boolean', value: 'boolean', key: 'boolean' },
				{ label: 'Array', value: 'array', key: 'array' },
				{ label: 'Object', value: 'object', key: 'object' },
			]
		},
		formatOptions() {
			return (suggestion) => {
				const commonFormats = [
					{ label: 'None', value: '', key: 'none' },
					{ label: 'Date (YYYY-MM-DD)', value: 'date', key: 'date' },
					{ label: 'Date Time (ISO 8601)', value: 'date-time', key: 'date-time' },
					{ label: 'Time (HH:MM:SS)', value: 'time', key: 'time' },
					{ label: 'Email', value: 'email', key: 'email' },
					{ label: 'URL', value: 'url', key: 'url' },
					{ label: 'UUID', value: 'uuid', key: 'uuid' },
					{ label: 'Hostname', value: 'hostname', key: 'hostname' },
					{ label: 'Color (Hex)', value: 'color', key: 'color' },
				]

				const detectedFormat = suggestion.detected_format
				const hasDetectedFormat = detectedFormat && !commonFormats.find(f => f.value === detectedFormat)

				// Add detected format if not already in common formats
				if (hasDetectedFormat) {
					commonFormats.push({
						label: detectedFormat.charAt(0).toUpperCase() + detectedFormat.slice(1),
						value: detectedFormat,
						key: detectedFormat,
					})
				}

				return commonFormats
			}
		},
		confidenceFilterOptions() {
			return [
				{ label: this.t('openregister', 'All Confidence Levels'), value: 'all', key: 'all' },
				{ label: this.t('openregister', 'High Confidence'), value: 'high', key: 'high' },
				{ label: this.t('openregister', 'Medium Confidence'), value: 'medium', key: 'medium' },
				{ label: this.t('openregister', 'Low Confidence'), value: 'low', key: 'low' },
			]
		},
		typeFilterOptions() {
			return [
				{ label: this.t('openregister', 'All'), value: 'all', key: 'all' },
				{ label: this.t('openregister', 'New Properties'), value: 'new', key: 'new' },
				{ label: this.t('openregister', 'Existing Improvements'), value: 'existing', key: 'existing' },
			]
		},
		filteredSuggestions() {
			if (!this.explorationData?.suggestions) {
				return []
			}

			let filtered = this.explorationData.suggestions

			// Filter by search term
			if (this.propertyFilter) {
				const filterLower = this.propertyFilter.toLowerCase()
				filtered = filtered.filter(suggestion =>
					suggestion.property_name.toLowerCase().includes(filterLower),
				)
			}

			 // Filter by confidence period
			if (this.confidenceFilter !== 'all') {
				filtered = filtered.filter(suggestion =>
					suggestion.confidence === this.confidenceFilter,
				)
			}

			// Filter by property type (new vs existing improvements)
			if (this.typeFilter !== 'all') {
				filtered = filtered.filter(suggestion => {
					if (this.typeFilter === 'new') {
						// Show only new properties (not existing improvements)
						return suggestion.improvement_status !== 'existing'
					} else if (this.typeFilter === 'existing') {
						// Show only existing property improvements
						return suggestion.improvement_status === 'existing'
					}
					return true
				})
			}

			 // Filter by selection status
			if (this.showOnlySelected) {
				filtered = filtered.filter(suggestion =>
					this.selectedProperties.includes(suggestion.property_name),
				)
			}

			return filtered
		},
		paginatedSuggestions() {
			const start = (this.currentPage - 1) * this.itemsPerPage
			const end = start + this.itemsPerPage
			return this.filteredSuggestions.slice(start, end)
		},
		totalPages() {
			return Math.ceil(this.filteredSuggestions.length / this.itemsPerPage)
		},
	},
	mounted() {
		// Initialize if we don't have schema item
		if (!schemaStore.schemaItem) {
			this.error = this.t('openregister', 'No schema selected for exploration')
		} else {
			// Count objects for this schema
			this.countObjects()
		}
	},
	methods: {
		t,
		async handleDialogClose() {
			navigationStore.setDialog(false)
			this.resetDialog()
		},
		resetDialog() {
			this.loading = false
			this.error = null
			this.success = false
			this.explorationData = null
			this.selectedProperties = []
			this.selectedPropertiesConfig = {}
			this.propertyFilter = ''
			this.confidenceFilter = 'all'
			this.typeFilter = 'all'
			this.showOnlySelected = false
			this.currentPage = 1
			this.analysisStarted = false
			this.objectCount = 0
			this.objectStats = null
		},
		async countObjects() {
			try {
				if (schemaStore.schemaItem?.id) {
					// Use the upgraded stats endpoint to get detailed object counts
					const stats = await schemaStore.getSchemaStats(schemaStore.schemaItem.id)
					this.objectStats = stats.objects
					this.objectCount = stats.objects?.total || 0
					console.info('Loaded detailed schema stats for exploration:', stats)
				}
			} catch (error) {
				console.warn('Could not fetch object count:', error)
				this.objectCount = 0
				this.objectStats = null
			}
		},
		async startAnalysis() {
			this.analysisStarted = true
			this.loading = true
			this.error = null

			try {
				const endpoint = `/index.php/apps/openregister/api/schemas/${schemaStore.schemaItem.id}/explore`

				const response = await fetch(endpoint, {
					method: 'GET',
					headers: {
						'Content-Type': 'application/json',
					},
				})

				if (!response.ok) {
					throw new Error(`HTTP error! status: ${response.status}`)
				}

				const data = await response.json()

				if (data.error) {
					throw new Error(data.error)
				}

				this.explorationData = data

				// Initialize configuration for suggestions
				data.suggestions?.forEach(suggestion => {
					if (!this.selectedPropertiesConfig[suggestion.property_name]) {
						this.selectedPropertiesConfig[suggestion.property_name] = {
							type: suggestion.recommended_type,
							title: suggestion.property_name,
							displayTitle: suggestion.property_name,
							format: suggestion.detected_format || '',
							maxLength: suggestion.max_length > 0 ? suggestion.max_length : null,
							minLength: suggestion.min_length !== null && suggestion.min_length < Number.MAX_SAFE_INTEGER ? suggestion.min_length : null,
							description: suggestion.description || '',
							example: '',
							order: 0,
							required: false, // Default to not required

							immutable: false, // Default to not immutable
							deprecated: false, // Default to not deprecated
							visible: true, // Default to visible
							hideOnCollection: false, // Default to show in collection view
							hideOnForm: false, // Default to show in form view
							facetable: false, // Default to not facetable
							// Constraint fields
							pattern: '',
							minimum: null,
							maximum: null,
							multipleOf: null,
							exclusiveMin: false,
							exclusiveMax: false,
						}
					}
				})

			} catch (error) {
				console.error('Schema exploration failed:', error)
				this.error = error.message || this.t('openregister', 'Failed to analyze schema properties')
			} finally {
				this.loading = false
				this.analysisStarted = false
			}
		},
		togglePropertySelection(propertyName) {
			if (this.selectedProperties.includes(propertyName)) {
				this.selectedProperties = this.selectedProperties.filter(name => name !== propertyName)
				delete this.selectedPropertiesConfig[propertyName]
			} else {
				this.selectedProperties.push(propertyName)
				if (!this.selectedPropertiesConfig[propertyName]) {
					const suggestion = this.explorationData.suggestions.find(s => s.property_name === propertyName)
					this.selectedPropertiesConfig[propertyName] = {
						selected: true,
						type: suggestion?.recommended_type || 'string',
						title: propertyName,
						description: suggestion?.description || '',
						format: suggestion?.detected_format || '',
						required: false,
						immutable: false,
						deprecated: false,
						visible: true,
						facetable: false,
						hideOnCollection: false,
						hideOnForm: false,
						displayTitle: propertyName,
						userDescription: '',
						example: '',
						order: 100,
						technicalDescription: '',
						// Constraint fields
						pattern: '',
						minimum: null,
						maximum: null,
						multipleOf: null,
						exclusiveMin: false,
						exclusiveMax: false,
						maxLength: suggestion?.max_length || null,
						minLength: suggestion?.min_length || null,
					}
				}
			}
		},
		isPropertySelected(propertyName) {
			return this.selectedProperties.includes(propertyName)
		},
		clearSelection() {
			this.selectedProperties = []
			this.selectedPropertiesConfig = {}
		},
		selectAll() {
			this.explorationData?.suggestions?.forEach(suggestion => {
				if (!this.selectedProperties.includes(suggestion.property_name)) {
					this.selectedProperties.push(suggestion.property_name)
					if (!this.selectedPropertiesConfig[suggestion.property_name]) {
						this.selectedPropertiesConfig[suggestion.property_name] = {
							type: suggestion.recommended_type || 'string',
							title: suggestion.property_name,
							description: suggestion.description || '',
							required: false,
							facetable: false,
						}
					}
				}
			})
		},
		async updateSchema() {
			this.loading = true
			this.error = null

			try {
				// Build property updates for selected properties
				const propertyUpdates = {}

				this.selectedProperties.forEach(propertyName => {
					const config = this.selectedPropertiesConfig[propertyName]
					propertyUpdates[propertyName] = {
						type: config.type,
						title: config.title,
						description: config.description,
						required: config.required,
						facetable: config.facetable,
					}
				})

				const endpoint = `/index.php/apps/openregister/api/schemas/${schemaStore.schemaItem.id}/update-from-exploration`

				const response = await fetch(endpoint, {
					method: 'POST',
					headers: {
						'Content-Type': 'application/json',
					},
					body: JSON.stringify({
						properties: propertyUpdates,
					}),
				})

				if (!response.ok) {
					throw new Error(`HTTP error! status: ${response.status}`)
				}

				const data = await response.json()

				if (data.error) {
					throw new Error(data.error)
				}

				this.success = true

				// Update schema in store
				if (data.schema) {
					schemaStore.setSchemaItem(data.schema)
				}

				// Refresh schema list to reflect changes
				schemaStore.refreshSchemaList()

				// Auto-close modal after success
				setTimeout(() => {
					this.handleDialogClose()
				}, 2000)

			} catch (error) {
				console.error('Schema update failed:', error)
				this.error = error.message || this.t('openregister', 'Failed to update schema properties')
			} finally {
				this.loading = false
			}
		},
		formatExample(value) {
			if (value === null || value === undefined) {
				return 'null'
			}

			if (typeof value === 'object') {
				return '{object}'
			}

			if (typeof value === 'string' && value.length > 20) {
				return value.substring(0, 20) + '...'
			}

			return String(value)
		},
		onPageChanged(page) {
			this.currentPage = page

			// Scroll to top when page changes
			const container = this.$el?.querySelector('.properties-list')
			if (container) {
				container.scrollTop = 0
			}
		},
		onPageSizeChanged(pageSize) {
			this.itemsPerPage = pageSize
			this.currentPage = 1
		},
		closeDialog() {
			navigationStore.setDialog(false)
			this.resetDialog()
		},
		getIssueDetails(issues) {
			// Convert issue type strings to more detailed objects
			return issues.map(issueType => {
				return {
					type: this.getIssueType(issueType),
					description: this.getIssueDescription(issueType),
				}
			})
		},
		getIssueType(issueType) {
			// Map issue types to UI-friendly categories
			const typeMap = {
				type_mismatch: 'type',
				missing_max_length: 'constraint',
				max_length_too_small: 'constraint',
				missing_format: 'format',
				missing_pattern: 'pattern',
				missing_minimum: 'constraint',
				minimum_too_high: 'constraint',
				missing_maximum: 'constraint',
				maximum_too_low: 'constraint',
				inconsistent_required: 'behavior',
				missing_enum: 'enum',
			}
			return typeMap[issueType] || 'general'
		},
		getIssueLabel(issueType) {
			// Get UI-friendly labels for issue types
			const labelMap = {
				type: this.t('openregister', 'Type Issue'),
				constraint: this.t('openregister', 'Constraint Issue'),
				format: this.t('openregister', 'Format Issue'),
				pattern: this.t('openregister', 'Pattern Issue'),
				behavior: this.t('openregister', 'Behavior Issue'),
				enum: this.t('openregister', 'Enum Issue'),
				general: this.t('openregister', 'General Issue'),
			}
			return labelMap[issueType] || this.t('openregister', 'Issue')
		},
		getIssueDescription(issueType) {
			// Get descriptions for different issue types
			const descriptionMap = {
				type_mismatch: this.t('openregister', 'Data type does not match observed values'),
				missing_max_length: this.t('openregister', 'Maximum length constraint is missing'),
				max_length_too_small: this.t('openregister', 'Maximum length is too restrictive'),
				missing_format: this.t('openregister', 'Format constraint is missing'),
				missing_pattern: this.t('openregister', 'Pattern constraint is missing'),
				missing_minimum: this.t('openregister', 'Minimum value constraint is missing'),
				minimum_too_high: this.t('openregister', 'Minimum value is too restrictive'),
				missing_maximum: this.t('openregister', 'Maximum value constraint is missing'),
				maximum_too_low: this.t('openregister', 'Maximum value is too restrictive'),
				inconsistent_required: this.t('openregister', 'Required status is inconsistent'),
				missing_enum: this.t('openregister', 'Enum constraint is missing'),
			}
			return descriptionMap[issueType] || this.t('openregister', 'Property can be improved')
		},
	},
}
</script>

<style scoped lang="scss">
.exploitation-header {
	margin-bottom: 2rem;

	h2 {
		display: flex;
		align-items: center;
		gap: 0.5rem;
		margin: 0 0 0.5rem 0;
		color: var(--color-main-text);
		font-size: 1.5rem;
		font-weight: 600;
	}

	p {
		margin: 0;
		color: var(--color-text-lighter);
		font-size: 0.95rem;
		line-height: 1.5;
	}
}

.analysis-status {
	margin-bottom: 1.5rem;

	.no-analysis {
		text-align: center;
		padding: 2rem;
		background: var(--color-background-hover);
		border-radius: var(--border-radius);
		color: var(--color-text-lighter);
	}

	.analysis-summary {
		background: var(--color-background-hover);
		border-radius: var(--border-radius);
		padding: 1.5rem;

	.stats-container {
		display: grid;
		grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
		gap: 1rem;
		margin-bottom: 1.5rem;
	}

	.stat-box {
		background: white;
		border: 2px solid #e1e5e9;
		border-radius: 8px;
		padding: 1rem;
		text-align: center;
		box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
		transition: all 0.3s ease;
	}

	.stat-box:hover {
		border-color: #0066cc;
		box-shadow: 0 4px 8px rgba(0, 102, 204, 0.2);
		transform: translateY(-2px);
	}

	.stat-number {
		font-size: 2rem;
		font-weight: bold;
		color: #0066cc;
		margin-bottom: 0.5rem;
		display: block;
	}

	.stat-title {
		font-size: 0.9rem;
		color: #666;
		text-transform: uppercase;
		letter-spacing: 0.5px;
		font-weight: 600;
	}

	.analysis-timestamp {
		text-align: center;
		padding: 0.75rem;
		background: #f8f9fa;
		border-radius: 6px;
		border: 1px solid #e1e5e9;
		color: #495057;
		font-size: 0.9rem;
	}
	}
}

.analysis-controls {
	margin-bottom: 2rem;
	text-align: center;
}

.discovered-properties {
	h3 {
		margin: 0 0 1rem 0;
		color: var(--color-main-text);
		font-size: 1.25rem;
		font-weight: 600;
	}
}

.property-filters {
	display: flex;
	gap: 1rem;
	align-items: end;
	margin-bottom: 1.5rem;
	padding-bottom: 1rem;
	border-bottom: 1px solid var(--color-border);
}

.filter-section {
	display: flex;
	flex-direction: column;
	flex: 1;
	min-width: 200px;
	gap: 0.5rem;
}

.filter-label {
	color: var(--color-main-text);
	font-weight: 600;
	font-size: 0.9rem;
	margin-bottom: 0.25rem;
}

.property-filters .nc-text-field,
.property-filters .nc-select {
	flex: 1;
}

/* Improve readability of filter components */
.property-filters ::placeholder {
	color: var(--color-text-maxcontrast) !important;
	opacity: 0.8;
}

.property-filters .nc-select .nc-select__input-wrapper {
	color: var(--color-main-text) !important;
}

.property-filters .nc-select .nc-select__label {
	color: var(--color-main-text) !important;
	font-weight: 500;
}

.properties-list {
	margin-bottom: 2rem;
}

.property-card {
	background: var(--color-main-background);
	border: 2px solid var(--color-border);
	border-radius: var(--border-radius);
	padding: 1.5rem;
	margin-bottom: 1rem;
	transition: all 0.2s ease;

	&:hover {
		border-color: var(--color-primary-element);
		box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
	}

	&.selected {
		border-color: var(--color-primary-element);
		background: var(--color-primary-light);
	}
}

.property-header {
	display: flex;
	justify-content: space-between;
	align-items: flex-start;
	margin-bottom: 1rem;

	.property-info {
		h4 {
			margin: 0 0 0.5rem 0;
			color: var(--color-main-text);
			font-size: 1.1rem;
			font-weight: 600;
		}
	}

	.property-meta {
		display: flex;
		gap: 0.5rem;
		align-items: center;

		.confidence-badge {
			font-size: 0.7rem;
			font-weight: 600;
			text-transform: uppercase;
			letter-spacing: 0.5px;
			padding: 0.25rem 0.5rem;
			border-radius: var(--border-radius-pill);

			&.confidence-high {
				background: var(--color-success);
				color: white;
			}

			&.confidence-medium {
				background: var(--color-warning);
				color: white;
			}

			&.confidence-low {
				background: var(--color-error);
				color: white;
			}
		}

		.usage-percentage {
			font-size: 0.8rem;
			color: var(--color-text-lighter);
		}
	}
}

.property-details {
	display: grid;
	grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
	gap: 1rem;
	margin-bottom: 1rem;

	.detail-item {
		.detail-label {
			font-size: 0.8rem;
			color: var(--color-text-lighter);
			text-transform: uppercase;
			letter-spacing: 0.5px;
			display: block;
			margin-bottom: 0.25rem;
		}

		.detail-value {
			color: var(--color-main-text);
			font-weight: 500;
		}
	}
}

.property-examples {
	margin-bottom: 1rem;

	.examples-header h5 {
		margin: 0 0 0.5rem 0;
		font-size: 0.9rem;
		color: var(--color-text-lighter);
	}

	.example-values {
		display: flex;
		flex-wrap: wrap;
		gap: 0.5rem;
	}

	.example-tag {
		background: var(--color-background-hover);
		color: var(--color-main-text);
		padding: 0.25rem 0.5rem;
		border-radius: var(--border-radius-pill);
		font-size: 0.8rem;
		font-family: var(--font-family-monospace);
	}
}

.format-badge {
	background: var(--color-primary-element-light);
	color: var(--color-primary-element);
	padding: 0.25rem 0.5rem;
	border-radius: var(--border-radius-pill);
	font-size: 0.8rem;
	font-weight: 600;
	text-transform: uppercase;
	letter-spacing: 0.5px;
}

.pattern-tag {
	display: inline-block;
	background: var(--color-background-hover);
	color: var(--color-text-lighter);
	padding: 0.125rem 0.375rem;
	border-radius: var(--border-radius-pill);
	font-size: 0.75rem;
	margin-right: 0.25rem;
	margin-bottom: 0.125rem;
	font-family: var(--font-family-monospace);
}

.config-warning {
	margin-top: 1rem;
}

.title-icon {
	margin-right: 0.5rem;
	vertical-align: middle;
}

.analysis-info {
	max-width: 800px;
	margin: 0 auto;
	padding: 1rem;
}

.info-section {
	margin-bottom: 2rem;
}

.object-count-section {
	background: var(--color-background-hover);
	border-radius: var(--border-radius);
	padding: 1rem;
	margin-bottom: 2rem;
}

.object-count-section h4 {
	margin-top: 0;
	margin-bottom: 1rem;
	color: var(--color-text);
}

.object-count {
	display: flex;
	align-items: baseline;
	gap: 0.5rem;
	font-size: 1.2rem;
}

.object-count-centered {
	display: flex;
	align-items: baseline;
	justify-content: center;
	gap: 0.5rem;
	font-size: 1.2rem;
}

.count-value {
	font-size: 2rem;
	font-weight: bold;
	color: var(--color-primary-element);
}

.count-label {
	color: var(--color-text-lighter);
}

.loading-count {
	display: flex;
	align-items: center;
	justify-content: center;
	gap: 0.5rem;
	color: var(--color-text-lighter);
}

.object-breakdown {
	margin-top: 1rem;
	padding: 1rem;
	background: var(--color-background-hover);
	border-radius: var(--border-radius);
	border: 1px solid var(--color-border);
}

.breakdown-item {
	display: flex;
	justify-content: space-between;
	align-items: center;
	margin-bottom: 0.5rem;
}

.breakdown-item:last-child {
	margin-bottom: 0;
}

.breakdown-label {
	font-weight: 500;
	color: var(--color-text);
}

.breakdown-value {
	font-weight: 600;
	padding: 0.25rem 0.5rem;
	border-radius: var(--border-radius);
	background: var(--color-background-hover);
}

.breakdown-value.invalid {
	color: var(--color-warning);
	background: var(--color-warning-light);
}

.breakdown-value.deleted {
	color: var(--color-error);
	background: var(--color-error-light);
}

.breakdown-value.published {
	color: var(--color-success);
	background: var(--color-success-light);
}

.steps-section {
	margin-bottom: 2rem;
}

.steps-section h4 {
	margin-bottom: 1rem;
	color: var(--color-text);
}

.steps-list {
	margin: 0;
	padding-left: 1.5rem;
	color: var(--color-text);
}

.steps-list li {
	margin-bottom: 0.5rem;
	line-height: 1.5;
}

.start-analysis {
	text-align: center;
	margin-top: 2rem;
	margin-bottom: 2rem;
}

.modal-footer {
	padding: 1.5rem 2rem;
	border-top: 1px solid var(--color-border);
	text-align: center;
	background: var(--color-background-alt);
}

.constraints-section {
	margin-top: 1rem;
	padding-top: 1rem;
	border-top: 1px solid var(--color-border);
}

.constraints-section h5 {
	margin: 0 0 0.5rem 0;
	color: var(--color-text);
	font-weight: 600;
	font-size: 0.9rem;
}

.well-container {
	background: var(--color-background-hover);
	border-radius: var(--border-radius);
	padding: 1.5rem;
	margin-bottom: 1.5rem;
}

.behaviors-section {
	margin-top: 1.5rem;
	margin-bottom: 1rem;
}

.behaviors-grid {
	background: var(--color-background);
	border-radius: var(--border-radius);
	padding: 1rem;
	border: 1px solid var(--color-border);
	display: grid;
	grid-template-columns: 1fr 1fr;
	gap: 1rem;
}

.behavior-column {
	display: flex;
	flex-direction: column;
	gap: 0.75rem;
}

.behavior-item {
	display: flex;
	align-items: center;
}

.behavior-item .nc-checkbox-radio-switch {
	font-size: 0.9rem;
}

.property-config {
	background: var(--color-background-hover);
	border-radius: var(--border-radius);
	padding: 1rem;
	margin-top: 1rem;

	h5 {
		margin: 0 0 1rem 0;
		color: var(--color-main-text);
		font-size: 1rem;
		font-weight: 600;
	}

	.config-fields {
		display: grid;
		gap: 1rem;
	}
}

.no-results {
	text-align: center;
	padding: 3rem;
	color: var(--color-text-lighter);

	p {
		margin: 0;
		font-size: 1.1rem;
	}
}

.selection-summary {
	margin-top: 2rem;
	padding-top: 2rem;
	border-top: 2px solid var(--color-border);

	.summary-actions {
		display: flex;
		gap: 1rem;
		justify-content: center;
		margin-top: 1rem;
	}
}

.pagination-controls {
	margin-top: 2rem;
	padding-top: 1rem;
	border-top: 1px solid var(--color-border);
}

/* Responsive design */
@media (max-width: 768px) {
	.property-filters {
		flex-direction: column;
		align-items: stretch;

		.nc-text-field,
		.nc-select {
			min-width: auto;
		}
	}

	.summary-stats .stat-item {
		margin-bottom: 0.5rem;
	}

	.selection-summary .summary-actions {
		flex-direction: column;
		align-items: stretch;
	}
}

/* Improvement and Issue Styles */
.improvement-status {
	background: var(--color-primary-element);
	color: var(--color-primary-element-text);
	padding: 0.25rem 0.5rem;
	border-radius: var(--border-radius-small);
	font-size: 0.7rem;
	font-weight: 600;
	text-transform: uppercase;
	letter-spacing: 0.5px;
}

.new-property-status {
	background: var(--color-success);
	color: white !important;
	padding: 0.25rem 0.5rem;
	border-radius: var(--border-radius-small);
	font-size: 0.7rem;
	font-weight: 600;
	text-transform: uppercase;
	letter-spacing: 0.5px;
}

.issues-badge {
	background: var(--color-warning);
	color: white !important;
	padding: 0.25rem 0.5rem;
	border-radius: var(--border-radius-small);
	font-size: 0.7rem;
	font-weight: 600;
	text-transform: uppercase;
	letter-spacing: 0.5px;
}

.type-warning {
	color: var(--color-warning);
	font-weight: 600;
}

.improvement-details {
	margin-top: 1rem;
	padding-top: 1rem;
	border-top: 1px solid var(--color-border);

	h5 {
		margin: 0 0 0.75rem 0;
		color: var(--color-text);
		font-size: 0.9rem;
		font-weight: 600;
	}
}

.issues-list {
	margin-bottom: 1.5rem;
}

.issue-item {
	display: flex;
	align-items: flex-start;
	gap: 0.75rem;
	margin-bottom: 0.5rem;

	&:last-child {
		margin-bottom: 0;
	}
}

.issue-badge {
	padding: 0.25rem 0.5rem;
	border-radius: var(--border-radius-small);
	font-size: 0.75rem;
	font-weight: 600;
	text-transform: uppercase;
	letter-spacing: 0.5px;
	flex-shrink: 0;

	&.issue-type {
		background: var(--color-error);
		color: var(--color-error-text);
	}

	&.issue-constraint {
		background: var(--color-warning);
		color: var(--color-warning-text);
	}

	&.issue-format {
		background: var(--color-info);
		color: var(--color-info-text);
	}

	&.issue-pattern {
		background: var(--color-success);
		color: var(--color-success-text);
	}

	&.issue-behavior {
		background: var(--color-text-lighter);
		color: var(--color-main-text);
	}

	&.issue-enum {
		background: var(--color-primary-element);
		color: var(--color-primary-element-text);
	}
}

.issue-description {
	color: var(--color-text);
	font-size: 0.85rem;
	line-height: 1.3;
}

.suggestions-list {
	display: flex;
	flex-direction: column;
	gap: 1rem;
}

.suggestion-item {
	padding: 0.75rem;
	background: var(--color-background-hover);
	border-radius: var(--border-radius);
	border-left: 3px solid var(--color-primary-element);
}

.suggestion-field {
	font-size: 0.85rem;
	color: var(--color-text);
	margin-bottom: 0.25rem;
}

.suggestion-change {
	font-size: 0.85rem;
	margin-bottom: 0.5rem;

	.current {
		color: var(--color-error);
		font-weight: 600;
	}

	.recommended {
		color: var(--color-success);
		font-weight: 600;
	}
}

.suggestion-desc {
	font-size: 0.8rem;
	color: var(--color-text-lighter);
	line-height: 1.3;
}
</style>

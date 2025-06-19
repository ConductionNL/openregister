<script setup>
import { navigationStore, objectStore, registerStore, schemaStore } from '../../store/store.js'
</script>

<template>
	<NcAppSidebar
		ref="sidebar"
		v-model="activeTab"
		name="Object selection"
		subtitle="Select register and schema"
		subname="Within the federative network"
		:open="navigationStore.sidebarState.search"
		@update:open="(e) => navigationStore.setSidebarState('search', e)">
		<NcAppSidebarTab id="filters-tab" name="Filters" :order="1">
			<template #icon>
				<FilterOutline :size="20" />
			</template>

			<!-- Filter Section -->
			<div class="filterSection">
				<h3>{{ t('openregister', 'Filter Objects') }}</h3>
				<div class="filterGroup">
					<label for="registerSelect">{{ t('openregister', 'Register') }}</label>
					<NcSelect v-bind="registerOptions"
						id="registerSelect"
						:model-value="selectedRegisterValue"
						input-label="Register"
						:loading="registerLoading"
						:disabled="registerLoading"
						placeholder="Select a register"
						@update:model-value="handleRegisterChange" />
				</div>
				<div class="filterGroup">
					<label for="schemaSelect">{{ t('openregister', 'Schema') }}</label>
					<NcSelect v-bind="schemaOptions"
						id="schemaSelect"
						:model-value="selectedSchemaValue"
						input-label="Schema"
						:loading="schemaLoading"
						:disabled="!registerStore.registerItem || schemaLoading"
						placeholder="Select a schema"
						@update:model-value="handleSchemaChange" />
				</div>
				<div class="filterGroup">
					<NcTextField
						v-model="searchQuery"
						label="Search objects"
						type="search"
						:disabled="!registerStore.registerItem || !schemaStore.schemaItem"
						placeholder="Type to search..."
						class="search-input"
						@update:modelValue="handleSearch" />
				</div>
			</div>

			<NcNoteCard type="info" class="column-hint">
				You can customize visible columns in the Columns tab
			</NcNoteCard>
		</NcAppSidebarTab>

		<NcAppSidebarTab id="facets-tab" name="Facets" :order="2">
			<template #icon>
				<FilterVariant :size="20" />
			</template>

			<!-- Facets Section -->
			<div class="section">
				<h3 class="section-title">
					Active Filters
				</h3>
				<div v-if="Object.keys(objectStore.currentActiveFacets).length === 0" class="empty-state">
					No active filters
				</div>
				<div v-else class="active-facets">
					<div v-for="(values, field) in objectStore.currentActiveFacets" :key="field" class="active-facet-group">
						<div class="facet-field-label">
							{{ getFacetFieldLabel(field) }}
						</div>
						<div class="facet-values">
							<NcButton
								v-for="value in values"
								:key="`${field}-${value}`"
								type="tertiary"
								class="facet-chip"
								@click="removeFacetValue(field, value)">
								{{ value }}
								<template #icon>
									<Close :size="16" />
								</template>
							</NcButton>
						</div>
					</div>
					<NcButton
						type="secondary"
						class="clear-all-facets"
						@click="clearAllFacets">
						<template #icon>
							<FilterRemove :size="20" />
						</template>
						Clear All Filters
					</NcButton>
				</div>
			</div>

			<!-- Available Facets -->
			<div class="section">
				<h3 class="section-title">
					Available Filters
				</h3>
				<NcNoteCard v-if="!schemaStore.schemaItem" type="info">
					No schema selected. Please select a schema to view available filters.
				</NcNoteCard>
				<NcNoteCard v-else-if="objectStore.availableFacetFields.length === 0" type="info">
					No facets available. Facets will appear after loading data.
				</NcNoteCard>
				<div v-else class="facet-groups">
					<div v-for="facetField in objectStore.availableFacetFields" :key="facetField.key" class="facet-group">
						<div class="facet-header">
							<h4 class="facet-title">
								{{ facetField.label }}
							</h4>
							<span class="facet-type">{{ facetField.type }}</span>
						</div>
						<div class="facet-values">
							<div v-for="facetValue in getFacetValues(facetField)" :key="facetValue.value" class="facet-value">
								<NcCheckboxRadioSwitch
									:checked="isFacetActive(facetField.key, facetValue.value)"
									@update:checked="toggleFacet(facetField.key, facetValue.value, $event)">
									<span class="facet-value-label">{{ facetValue.value }}</span>
									<span class="facet-count">({{ facetValue.count }})</span>
								</NcCheckboxRadioSwitch>
							</div>
						</div>
					</div>
				</div>
			</div>
		</NcAppSidebarTab>

		<NcAppSidebarTab id="columns-tab" name="Columns" :order="3">
			<template #icon>
				<FormatColumns :size="20" />
			</template>

			<!-- Custom Columns Section -->
			<div class="section">
				<h3 class="section-title">
					Properties
				</h3>
				<NcNoteCard v-if="!schemaStore.schemaItem" type="info">
					No schema selected. Please select a schema to view properties.
				</NcNoteCard>
				<NcNoteCard v-else-if="!Object.keys(objectStore.properties || {}).length" type="warning">
					Selected schema has no properties. Please add properties to the schema.
				</NcNoteCard>
				<div v-else class="column-switches">
					<NcCheckboxRadioSwitch
						v-for="(property, propertyName) in objectStore.properties"
						:key="`prop_${propertyName}`"
						:checked="objectStore.columnFilters[`prop_${propertyName}`]"
						:title="property.description"
						@update:checked="(status) => objectStore.updateColumnFilter(`prop_${propertyName}`, status)">
						{{ property.label }}
					</NcCheckboxRadioSwitch>
				</div>
			</div>

			<!-- Default Columns Section -->
			<div class="section">
				<h3 class="section-title">
					Metadata
				</h3>
				<NcNoteCard v-if="!schemaStore.schemaItem" type="info">
					No schema selected. Please select a schema to view metadata columns.
				</NcNoteCard>
				<div v-if="schemaStore.schemaItem" class="column-switches">
					<NcCheckboxRadioSwitch
						v-for="meta in metadataColumns"
						:key="`meta_${meta.id}`"
						:checked="objectStore.columnFilters[`meta_${meta.id}`]"
						:title="meta.description"
						@update:checked="(status) => objectStore.updateColumnFilter(`meta_${meta.id}`, status)">
						{{ meta.label }}
					</NcCheckboxRadioSwitch>
				</div>
			</div>
		</NcAppSidebarTab>

		<NcAppSidebarTab id="developer-tab" name="Developer" :order="4">
			<template #icon>
				<CodeTags :size="20" />
			</template>

			<!-- API Call Information -->
			<div class="section">
				<h3 class="section-title">
					Last API Call
				</h3>
				<NcNoteCard v-if="!objectStore.apiCallForDeveloper" type="info">
					No API calls made yet. Perform a search to see the API call details.
				</NcNoteCard>
				<div v-else class="api-call-details">
					<div class="api-call-info">
						<div class="api-call-meta">
							<span class="api-method">{{ objectStore.apiCallForDeveloper.method }}</span>
							<span class="api-duration">{{ objectStore.apiCallForDeveloper.duration }}ms</span>
							<span class="api-timestamp">{{ formatTimestamp(objectStore.apiCallForDeveloper.timestamp) }}</span>
						</div>
						<div class="api-url">
							<code>{{ objectStore.apiCallForDeveloper.url }}</code>
						</div>
					</div>

					<!-- Parameters -->
					<div class="api-section">
						<h4>Parameters</h4>
						<pre class="code-block">{{ JSON.stringify(objectStore.apiCallForDeveloper.params, null, 2) }}</pre>
					</div>

					<!-- cURL Example -->
					<div class="api-section">
						<h4>cURL Command</h4>
						<div class="code-block-container">
							<pre class="code-block">{{ generateCurlCommand() }}</pre>
							<NcButton
								type="tertiary"
								class="copy-button"
								@click="copyToClipboard(generateCurlCommand())">
								<template #icon>
									<ContentCopy :size="16" />
								</template>
							</NcButton>
						</div>
					</div>

					<!-- JavaScript Example -->
					<div class="api-section">
						<h4>JavaScript Example</h4>
						<div class="code-block-container">
							<pre class="code-block">{{ generateJsExample() }}</pre>
							<NcButton
								type="tertiary"
								class="copy-button"
								@click="copyToClipboard(generateJsExample())">
								<template #icon>
									<ContentCopy :size="16" />
								</template>
							</NcButton>
						</div>
					</div>

					<!-- Response Preview -->
					<div class="api-section">
						<h4>Response Structure</h4>
						<pre class="code-block">{{ JSON.stringify(getResponsePreview(), null, 2) }}</pre>
					</div>
				</div>
			</div>
		</NcAppSidebarTab>

		<template #secondary-actions>
			<NcActionButton close-after-click
				:disabled="!registerStore.registerItem || !schemaStore.schemaItem"
				@click="openAddObjectModal">
				<template #icon>
					<Plus :size="20" />
				</template>
				Add
			</NcActionButton>
			<NcActionButton close-after-click
				:disabled="!registerStore.registerItem || !schemaStore.schemaItem"
				@click="refreshObjects">
				<template #icon>
					<Refresh :size="20" />
				</template>
				Refresh
			</NcActionButton>
			<NcActionButton close-after-click :disabled="!objectStore.selectedObjects?.length" @click="() => navigationStore.setDialog('massDeleteObject')">
				<template #icon>
					<Delete :size="20" />
				</template>
				Delete {{ objectStore.selectedObjects?.length }} {{ objectStore.selectedObjects?.length > 1 ? 'objects' : 'object' }}
			</NcActionButton>
			<NcActionButton close-after-click
				:disabled="!registerStore.registerItem"
				@click="openEditRegisterModal">
				<template #icon>
					<Pencil :size="20" />
				</template>
				Edit Register
			</NcActionButton>
			<NcActionButton close-after-click
				:disabled="!schemaStore.schemaItem"
				@click="openEditSchemaModal">
				<template #icon>
					<Pencil :size="20" />
				</template>
				Edit Schema
			</NcActionButton>
			<NcActionButton close-after-click disabled>
				<template #icon>
					<Upload :size="20" />
				</template>
				Upload
			</NcActionButton>
			<NcActionButton close-after-click disabled>
				<template #icon>
					<Download :size="20" />
				</template>
				Download
			</NcActionButton>
			<NcActionButton close-after-click disabled>
				<template #icon>
					<FileMoveOutline :size="20" />
				</template>
				Move
			</NcActionButton>
		</template>
	</NcAppSidebar>
</template>

<script>
import { NcAppSidebar, NcAppSidebarTab, NcSelect, NcNoteCard, NcCheckboxRadioSwitch, NcTextField, NcActionButton, NcButton } from '@nextcloud/vue'
import FilterOutline from 'vue-material-design-icons/FilterOutline.vue'
import FilterVariant from 'vue-material-design-icons/FilterVariant.vue'
import Close from 'vue-material-design-icons/Close.vue'
import FilterRemove from 'vue-material-design-icons/FilterRemove.vue'
import FormatColumns from 'vue-material-design-icons/FormatColumns.vue'
import CodeTags from 'vue-material-design-icons/CodeTags.vue'
import ContentCopy from 'vue-material-design-icons/ContentCopy.vue'
import Plus from 'vue-material-design-icons/Plus.vue'
import Pencil from 'vue-material-design-icons/Pencil.vue'
import Upload from 'vue-material-design-icons/Upload.vue'
import Download from 'vue-material-design-icons/Download.vue'
import FileMoveOutline from 'vue-material-design-icons/FileMoveOutline.vue'
import Delete from 'vue-material-design-icons/Delete.vue'
import Refresh from 'vue-material-design-icons/Refresh.vue'

export default {
	name: 'SearchSideBar',
	components: {
		NcAppSidebar,
		NcAppSidebarTab,
		NcSelect,
		NcNoteCard,
		NcCheckboxRadioSwitch,
		NcTextField,
		NcActionButton,
		NcButton,
		FilterOutline,
		FormatColumns,
		FilterVariant,
		Close,
		FilterRemove,
		CodeTags,
		ContentCopy,
		Plus,
		Pencil,
		Upload,
		Download,
		FileMoveOutline,
		Delete,
		Refresh,
	},
	data() {
		return {
			registerLoading: false,
			schemaLoading: false,
			ignoreNextPageWatch: false,
			searchQuery: '',
			activeTab: 'filters-tab',
			searchTimeout: null,
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
		metadataColumns() {
			return Object.entries(objectStore.metadata).map(([id, meta]) => ({
				id,
				...meta,
			}))
		},
	},
	watch: {
		searchQuery(value) {
			if (this.searchTimeout) {
				clearTimeout(this.searchTimeout)
			}
			this.searchTimeout = setTimeout(() => {
				objectStore.setFilters({
					_search: value || '',
				})
				if (registerStore.registerItem && schemaStore.schemaItem) {
					objectStore.refreshObjectList({
						register: registerStore.registerItem.id,
						schema: schemaStore.schemaItem.id,
					})
				}
			}, 1000)
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
			objectStore.refreshObjectList()
		}
	},
	methods: {
		handleRegisterChange(option) {
			registerStore.setRegisterItem(option)
			schemaStore.setSchemaItem(null)
		},
		async handleSchemaChange(option) {
			schemaStore.setSchemaItem(option)
			if (option) {
				objectStore.initializeProperties(option)
				objectStore.refreshObjectList()
			}
		},
		handleSearch() {
			if (registerStore.registerItem && schemaStore.schemaItem) {
				objectStore.refreshObjectList({
					register: registerStore.registerItem.id,
					schema: schemaStore.schemaItem.id,
					search: this.searchQuery,
				})
			}
		},
		openAddObjectModal() {
			objectStore.setObjectItem(null) // Clear any existing object
			navigationStore.setModal('editObject')
		},
		openEditRegisterModal() {
			navigationStore.setModal('editRegister')
		},
		openEditSchemaModal() {
			navigationStore.setModal('editSchema')
		},

		async refreshObjects() {
			await objectStore.refreshObjectList()
		},

		// Faceting methods
		getFacetFieldLabel(field) {
			if (field.startsWith('@self.')) {
				const metaKey = field.replace('@self.', '')
				const metaField = objectStore.metadata[metaKey]
				return metaField?.label || field
			} else {
				const property = objectStore.properties[field]
				return property?.label || field
			}
		},

		getFacetValues(facetField) {
			if (Array.isArray(facetField.values)) {
				return facetField.values
			} else if (typeof facetField.values === 'object') {
				return Object.entries(facetField.values).map(([value, count]) => ({
					value,
					count,
				}))
			}
			return []
		},

		isFacetActive(field, value) {
			return objectStore.activeFacets[field]?.includes(value) || false
		},

		toggleFacet(field, value, active) {
			objectStore.setActiveFacet(field, value, active)
			objectStore.refreshObjectList()
		},

		removeFacetValue(field, value) {
			objectStore.setActiveFacet(field, value, false)
			objectStore.refreshObjectList()
		},

		clearAllFacets() {
			objectStore.clearActiveFacets()
			objectStore.refreshObjectList()
		},

		// Developer tools methods
		formatTimestamp(timestamp) {
			if (!timestamp) return ''
			return new Date(timestamp).toLocaleString()
		},

		generateCurlCommand() {
			const apiCall = objectStore.apiCallForDeveloper
			if (!apiCall) return ''

			let command = `curl -X ${apiCall.method} '${apiCall.url}'`

			if (apiCall.headers) {
				Object.entries(apiCall.headers).forEach(([key, value]) => {
					command += ` \\\n  -H '${key}: ${value}'`
				})
			}

			return command
		},

		generateJsExample() {
			const apiCall = objectStore.apiCallForDeveloper
			if (!apiCall) return ''

			return `// Fetch API example
const response = await fetch('${apiCall.url}', {
  method: '${apiCall.method}',
  headers: ${JSON.stringify(apiCall.headers, null, 4)}
});

const data = await response.json();
console.log(data);`
		},

		getResponsePreview() {
			const apiCall = objectStore.apiCallForDeveloper
			if (!apiCall?.response) return {}

			// Return a simplified version of the response structure
			const response = apiCall.response
			return {
				results: Array.isArray(response.results) ? `Array(${response.results.length})` : response.results,
				total: response.total,
				page: response.page,
				pages: response.pages,
				limit: response.limit,
				facets: response.facets ? 'Object with facet data' : undefined,
				facetable: response.facetable ? 'Object with facetable fields' : undefined,
			}
		},

		async copyToClipboard(text) {
			try {
				await navigator.clipboard.writeText(text)
				// Could add a toast notification here
			} catch (err) {
				console.error('Failed to copy to clipboard:', err)
			}
		},
	},
}
</script>

<style scoped>
.section {
	padding: 12px 0;
	border-bottom: 1px solid var(--color-border);
}

.section:last-child {
	border-bottom: none;
}

.section-title {
	color: var(--color-text-maxcontrast);
	font-size: 14px;
	font-weight: bold;
	padding: 0 16px;
	margin: 0 0 12px 0;
}

.column-switches {
	padding: 0 16px;
}

.column-switches :deep(.checkbox-radio-switch) {
	margin: 8px 0;
}

.search-input {
	margin: 12px 16px;
}

.empty-state {
	color: var(--color-text-maxcontrast);
	text-align: center;
	padding: 12px;
	font-style: italic;
}

/* Add some spacing between select inputs */
:deep(.v-select) {
	margin: 0 16px 12px 16px;
}

/* Style for the last select to maintain consistent spacing */
:deep(.v-select:last-of-type) {
	margin-bottom: 0;
}

/* Empty content styling */
:deep(.empty-content) {
	margin: 20px 0;
}

:deep(.empty-content__icon) {
	width: 32px;
	height: 32px;
}

.column-hint {
	margin: 8px 16px;
}

.inline-button {
	display: inline;
	padding: 0;
	margin: 0;
	text-decoration: underline;
	height: auto;
	min-height: auto;
	color: var(--color-primary);
}

.inline-button:hover {
	text-decoration: none;
}

.filterSection {
	display: flex;
	flex-direction: column;
	gap: 16px;
	padding-bottom: 20px;
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

/* Faceting styles */
.active-facets {
	padding: 0 16px;
}

.active-facet-group {
	margin-bottom: 12px;
}

.facet-field-label {
	font-weight: bold;
	font-size: 0.9em;
	color: var(--color-text-maxcontrast);
	margin-bottom: 4px;
}

.facet-values {
	display: flex;
	flex-wrap: wrap;
	gap: 4px;
}

.facet-chip {
	font-size: 0.8em;
	padding: 2px 8px;
	border-radius: 12px;
	background-color: var(--color-primary-element);
	color: var(--color-primary-text);
}

.clear-all-facets {
	margin-top: 8px;
}

.facet-groups {
	padding: 0 16px;
}

.facet-group {
	margin-bottom: 16px;
	border-bottom: 1px solid var(--color-border-light);
	padding-bottom: 12px;
}

.facet-group:last-child {
	border-bottom: none;
}

.facet-header {
	display: flex;
	justify-content: space-between;
	align-items: center;
	margin-bottom: 8px;
}

.facet-title {
	font-size: 0.9em;
	font-weight: bold;
	margin: 0;
	color: var(--color-main-text);
}

.facet-type {
	font-size: 0.7em;
	color: var(--color-text-maxcontrast);
	background-color: var(--color-background-dark);
	padding: 2px 6px;
	border-radius: 8px;
}

.facet-value {
	margin-bottom: 4px;
}

.facet-value-label {
	margin-right: 4px;
}

.facet-count {
	color: var(--color-text-maxcontrast);
	font-size: 0.8em;
}

/* Developer tab styles */
.api-call-details {
	padding: 0 16px;
}

.api-call-info {
	margin-bottom: 16px;
	padding: 12px;
	background-color: var(--color-background-dark);
	border-radius: 8px;
}

.api-call-meta {
	display: flex;
	gap: 12px;
	margin-bottom: 8px;
	flex-wrap: wrap;
}

.api-method {
	font-weight: bold;
	color: var(--color-success);
}

.api-duration {
	color: var(--color-text-maxcontrast);
}

.api-timestamp {
	color: var(--color-text-maxcontrast);
	font-size: 0.8em;
}

.api-url {
	font-family: monospace;
	font-size: 0.8em;
	word-break: break-all;
}

.api-section {
	margin-bottom: 16px;
}

.api-section h4 {
	margin: 0 0 8px 0;
	font-size: 0.9em;
	color: var(--color-main-text);
}

.code-block-container {
	position: relative;
}

.code-block {
	background-color: var(--color-background-dark);
	border: 1px solid var(--color-border);
	border-radius: 4px;
	padding: 12px;
	font-family: monospace;
	font-size: 0.8em;
	overflow-x: auto;
	white-space: pre-wrap;
	word-break: break-all;
	margin: 0;
}

.copy-button {
	position: absolute;
	top: 8px;
	right: 8px;
	opacity: 0.7;
}

.copy-button:hover {
	opacity: 1;
}
</style>

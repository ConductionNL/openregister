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

		<NcAppSidebarTab id="columns-tab" name="Columns" :order="2">
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
import { NcAppSidebar, NcAppSidebarTab, NcSelect, NcNoteCard, NcCheckboxRadioSwitch, NcTextField, NcActionButton } from '@nextcloud/vue'
import FilterOutline from 'vue-material-design-icons/FilterOutline.vue'
import FormatColumns from 'vue-material-design-icons/FormatColumns.vue'
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
		FilterOutline,
		FormatColumns,
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
</style>

<script setup>
import { objectStore, registerStore, schemaStore } from '../../store/store.js'
import { AppInstallService } from '../../services/appInstallService.js'
</script>

<template>
	<NcAppSidebar
		ref="sidebar"
		name="Object selection"
		subtitle="Select register and schema"
		subname="Within the federative network">
		<NcAppSidebarTab id="search-tab" name="Selection" :order="1">
			<template #icon>
				<Magnify :size="20" />
			</template>

			<!-- Search Section -->
			<div class="section">
				<h3 class="section-title">
					Search
				</h3>
				<NcSelect v-bind="registerOptions"
					:model-value="selectedRegisterValue"
					input-label="Register"
					:loading="registerLoading"
					:disabled="registerLoading"
					placeholder="Select a register"
					@update:model-value="handleRegisterChange" />

				<NcSelect v-bind="schemaOptions"
					:model-value="selectedSchemaValue"
					input-label="Schema"
					:loading="schemaLoading"
					:disabled="!registerStore.registerItem || schemaLoading"
					placeholder="Select a schema"
					@update:model-value="handleSchemaChange" />

				<NcTextField
					v-model="searchQuery"
					label="Search objects"
					type="search"
					:disabled="!registerStore.registerItem || !schemaStore.schemaItem"
					placeholder="Type to search..."
					class="search-input"
					@update:modelValue="handleSearch" />

				<NcNoteCard type="info" class="column-hint">
					You can customize visible columns in the
					<NcButton type="tertiary"
						class="inline-button"
						@click="$refs.sidebar.showTab('columns-tab')">
						Columns tab
					</NcButton>
				</NcNoteCard>
			</div>
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
	</NcAppSidebar>
</template>

<script>
import { NcAppSidebar, NcAppSidebarTab, NcSelect, NcButton, NcNoteCard, NcCheckboxRadioSwitch, NcTextField } from '@nextcloud/vue'
import Magnify from 'vue-material-design-icons/Magnify.vue'
import FormatColumns from 'vue-material-design-icons/FormatColumns.vue'

export default {
	name: 'SearchSideBar',
	components: {
		NcAppSidebar,
		NcAppSidebarTab,
		NcSelect,
		NcButton,
		NcNoteCard,
		NcCheckboxRadioSwitch,
		NcTextField,
		// Icons
		Magnify,
		FormatColumns,
	},
	data() {
		return {
			registerLoading: false,
			schemaLoading: false,
			appInstallService: new AppInstallService(),
			openConnectorInstalled: true,
			openConnectorInstallError: false,
			ignoreNextPageWatch: false,
			searchQuery: '',
		}
	},
	computed: {
		registerOptions() {
			return {
				options: registerStore.registerList.map(register => ({
					value: register.id,
					label: register.title,
					register,
				})),
				reduce: option => option.register,
				label: 'title',
				getOptionLabel: option => option.title || option.label,
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
						schema,
					})),
				reduce: option => option.schema,
				label: 'title',
				getOptionLabel: option => option.title || option.label,
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
	mounted() {
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

		this.initAppInstallService()
	},
	methods: {
		async initAppInstallService() {
			await this.appInstallService.init()
			this.openConnectorInstalled = await this.appInstallService.isAppInstalled('openconnector')
		},
		async installApp(appId) {
			try {
				await this.appInstallService.forceInstallApp(appId)
				this.openConnectorInstalled = true
			} catch (error) {
				if (error.status === 403 && error.data?.message === 'Password confirmation is required') {
					console.error('Password confirmation needed before installing apps')
				} else {
					console.error('Failed to install app:', error)
				}
				this.openConnectorInstallError = true
			}
		},
		handleSchemaChange(option) {
			if (option && option.schema) {
				const schema = option.schema
				schemaStore.setSchemaItem(schema)
				objectStore.setPagination(1)
				this.ignoreNextPageWatch = true

				objectStore.refreshObjectList({
					register: registerStore.registerItem.id,
					schema: schema.id,
				})

				const unwatch = this.$watch(
					() => objectStore.loading,
					(newVal) => {
						if (newVal === false) {
							this.ignoreNextPageWatch = false
							unwatch()
						}
					},
				)
			} else {
				schemaStore.setSchemaItem(null)
			}
		},
		handleRegisterChange(option) {
			if (option && option.register) {
				registerStore.setRegisterItem(option.register)
			} else {
				registerStore.setRegisterItem(null)
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
</style>

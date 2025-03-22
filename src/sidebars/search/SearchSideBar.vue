<script setup>
import { objectStore, registerStore, schemaStore } from '../../store/store.js'
import { AppInstallService } from '../../services/appInstallService.js'
import { EventBus } from '../../eventBus.js'
import { computed, ref, onMounted } from 'vue'

// Add search input ref and debounce function
const searchQuery = ref('')
let searchTimeout = null

// Debounced search function
const handleSearch = (value) => {
	if (searchTimeout) {
		clearTimeout(searchTimeout)
	}

	searchTimeout = setTimeout(() => {
		// Update the filters object with the search query
		objectStore.setFilters({
			_search: value || ''  // Set as object property instead of array
		})

		// Only refresh if we have both register and schema selected
		if (registerStore.registerItem && schemaStore.schemaItem) {
			objectStore.refreshObjectList({
				register: registerStore.registerItem.id,
				schema: schemaStore.schemaItem.id
			})
		}
	}, 1000) // 3 second delay
}

// Computed properties to handle the false values
const selectedRegisterValue = computed({
	get: () => {
		if (!registerStore.registerItem) return null
		// Return in the same format as the options
		return {
			value: registerStore.registerItem,
			label: registerStore.registerItem.title
		}
	},
	set: (value) => {
		registerStore.setRegisterItem(value?.value || null)
	}
})

const selectedSchemaValue = computed({
	get: () => {
		if (!schemaStore.schemaItem) return null
		// Return in the same format as the options
		return {
			value: schemaStore.schemaItem,
			label: schemaStore.schemaItem.title
		}
	},
	set: (value) => {
		schemaStore.setSchemaItem(value?.value || null)
	}
})

// Initialize column filters when component mounts
onMounted(() => {
	objectStore.initializeColumnFilters()
})

const metadataColumns = computed(() => {
	return Object.entries(objectStore.metadata).map(([id, meta]) => ({
		id,
		...meta
	}))
})
</script>

<template>
	<NcAppSidebar
		name="Object selection"
		subtitle="Select register and schema"
		subname="Within the federative network">
		<NcAppSidebarTab id="search-tab" name="Selection" :order="1">
			<template #icon>
				<Magnify :size="20" />
			</template>

			<!-- Search Section -->
			<div class="section">
				<h3 class="section-title">Search</h3>
				<NcSelect v-bind="registerOptions"
					:model-value="selectedRegisterValue"
					@update:model-value="selectedRegisterValue = $event"
					input-label="Register"
					:loading="registerLoading"
					:disabled="registerLoading"
					placeholder="Select a register" />
				
				<NcSelect v-bind="schemaOptions"
					:model-value="selectedSchemaValue"
					@update:model-value="selectedSchemaValue = $event"
					input-label="Schema"
					:loading="schemaLoading"
					:disabled="!selectedRegister || schemaLoading" />

				<NcTextField
					v-model="searchQuery"
					@update:modelValue="handleSearch"
					label="Search objects"
					type="search"
					:disabled="!selectedRegister || !selectedSchema"
					placeholder="Type to search..."
					class="search-input" />
			</div>

			<!-- Default Columns Section -->
			<div class="section">
				<h3 class="section-title">Metadata</h3>
				<div class="column-switches">
					<NcCheckboxRadioSwitch 
						v-for="meta in metadataColumns" 
						:key="meta.id"
						:checked="objectStore.columnFilters[meta.id]"
						@update:checked="(status) => objectStore.updateColumnFilter(meta.id, status)"
						:title="meta.description">
						{{ meta.label }}
					</NcCheckboxRadioSwitch>
				</div>
			</div>

			<!-- Custom Columns Section -->
			<div class="section" v-if="schemaStore.schemaItem">
				<h3 class="section-title">Properties</h3>
				<NcNoteCard v-if="!schemaStore.schemaItem" type="info">
					No schema selected. Please select a schema to view custom columns.
				</NcNoteCard>
				<NcNoteCard v-else-if="!schemaStore.schemaItem.properties?.length" type="warning">
					Selected schema has no properties. Please add properties to the schema.
				</NcNoteCard>
				<div v-else class="column-switches">
					<NcCheckboxRadioSwitch 
						v-for="(property, index) in schemaStore.schemaItem.properties" 
						:key="index"
						:checked="objectStore.columnFilters[property.name]"
						@update:checked="(status) => objectStore.updateColumnFilter(property.name, status)"
						:title="property.name || ''">
						{{ property.name }}
					</NcCheckboxRadioSwitch>
				</div>
			</div>
		</NcAppSidebarTab>

		<NcAppSidebarTab id="upload-tab" name="Upload" :order="2">
			<template #icon>
				<Upload :size="20" />
			</template>

			<NcNoteCard type="info">
				OpenConnector is required for this feature.
				<NcButton v-if="!openConnectorInstalled && !openConnectorInstallError" type="secondary" @click="installApp('openconnector')">
					Install OpenConnector
				</NcButton>
			</NcNoteCard>
			<NcNoteCard v-if="openConnectorInstallError" type="error">
				Failed to install OpenConnector. Check console for more details.
			</NcNoteCard>
		</NcAppSidebarTab>

		<NcAppSidebarTab id="download-tab" name="Download" :order="3">
			<template #icon>
				<Download :size="20" />
			</template>

			<NcNoteCard type="info">
				OpenConnector is required for this feature.
				<NcButton v-if="!openConnectorInstalled && !openConnectorInstallError" type="secondary" @click="installApp('openconnector')">
					Install OpenConnector
				</NcButton>
			</NcNoteCard>
			<NcNoteCard v-if="openConnectorInstallError" type="error">
				Failed to install OpenConnector. Check console for more details.
			</NcNoteCard>
		</NcAppSidebarTab>
	</NcAppSidebar>
</template>

<script>
import { NcAppSidebar, NcAppSidebarTab, NcSelect, NcButton, NcNoteCard, NcCheckboxRadioSwitch, NcTextField, NcEmptyContent } from '@nextcloud/vue'
import Magnify from 'vue-material-design-icons/Magnify.vue'
import Upload from 'vue-material-design-icons/Upload.vue'
import Download from 'vue-material-design-icons/Download.vue'

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
		NcEmptyContent,
		Magnify,
		Upload,
		Download,
	},
	data() {
		return {
			registerLoading: false,
			schemaLoading: false,
			appInstallService: new AppInstallService(),
			openConnectorInstalled: true,
			openConnectorInstallError: false,
		}
	},
	computed: {
		registerOptions() {
			return {
				options: registerStore.registerList.map(register => ({
					value: register,  // The full object goes in value
					label: register.title,
				})),
			}
		},
		schemaOptions() {
			const fullSelectedRegister = registerStore.registerList.find(
				register => register.id === (this.selectedRegister?.id || Symbol('no selected register'))
			)
			if (!fullSelectedRegister) return { options: [] }

			return {
				options: schemaStore.schemaList
					.filter(schema => fullSelectedRegister.schemas.includes(schema.id))
					.map(schema => ({
						value: schema,  // The full object goes in value
						label: schema.title,
					})),
			}
		},
		selectedRegister() {
			return registerStore.registerItem || false
		},
		selectedSchema() {
			return schemaStore.schemaItem || false
		},
		metadataColumns() {
			return Object.entries(objectStore.metadata).map(([id, meta]) => ({
				id,
				...meta
			}))
		}
	},
	watch: {
		selectedRegister(newValue) {
			if (!newValue) {
				schemaStore.setSchemaItem(false)
			}
		},
		selectedSchema(newValue) {
			if (newValue) {
				objectStore.setPagination(1)
				this.ignoreNextPageWatch = true

				objectStore.refreshObjectList({
					register: registerStore.registerItem.id,
					schema: schemaStore.schemaItem.id
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
			}
		},
	},
	mounted() {
		this.registerLoading = true
		this.schemaLoading = true
		
		registerStore.refreshRegisterList()
			.finally(() => (this.registerLoading = false))
		
		schemaStore.refreshSchemaList()
			.finally(() => (this.schemaLoading = false))

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
</style>

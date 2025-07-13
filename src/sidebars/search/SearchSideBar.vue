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
			</div>

			<!-- Search Section -->
			<div class="section">
				<h3 class="sectionTitle">{{ t('openregister', 'Search') }}</h3>
				<div class="search-input-container">
					<NcTextField
						v-model="searchQuery"
						:placeholder="searchPlaceholder"
						:disabled="searchLoading"
						@keyup.enter="addSearchTerms" />
					<NcButton
						v-if="searchQuery.trim()"
						type="secondary"
						:disabled="searchLoading"
						@click="addSearchTerms">
						{{ t('openregister', 'Add') }}
					</NcButton>
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
	},
	data() {
		return {
			registerLoading: false,
			schemaLoading: false,
			searchQuery: '',
			searchTerms: [],
			searchLoading: false,
			lastSearchStats: null,
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
			objectStore.refreshObjectList()
		}
	},
	methods: {
		t,
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
			
			// Automatically perform search after removing a term
			// This will either search with remaining terms or show all results if no terms left
			if (this.canSearch) {
				await this.performSearch()
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
				this.lastSearchStats = null
				
				// Set the search terms in the filters
				if (this.searchTerms.length > 0) {
					objectStore.setFilters({
						_search: this.searchTerms.join(' '),
					})
				} else {
					// Clear search filter if no terms
					objectStore.setFilters({
						_search: '',
					})
				}
				
				// Perform the search using the existing object store method
				await objectStore.refreshObjectList({
					register: registerStore.registerItem.id,
					schema: schemaStore.schemaItem.id,
				})
				
				// Calculate performance statistics
				const endTime = performance.now()
				const executionTime = Math.round(endTime - startTime)
				
				this.lastSearchStats = {
					total: objectStore.pagination.total || 0,
					time: executionTime,
				}
				
				console.log(`Search completed: ${this.lastSearchStats.total} results in ${executionTime}ms`)
				
			} catch (error) {
				console.error('Search failed:', error)
				this.lastSearchStats = {
					total: 0,
					time: 0,
				}
			} finally {
				this.searchLoading = false
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
</style>

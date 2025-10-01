<template>
	<NcDialog
		v-if="show"
		name="Configure SOLR Facets v3.0"
		:can-close="!loading"
		@closing="$emit('close')"
		size="large">
		
		<div class="facet-config-modal">
			<!-- Loading State -->
			<div v-if="loading" class="loading-section">
				<NcLoadingIcon :size="64" />
				<p>Loading SOLR facets...</p>
			</div>

			<!-- Error State -->
			<div v-else-if="error" class="error-section">
				<div class="error-banner">
					<div class="error-header">
						<AlertCircle :size="24" class="error-icon" />
						<h3 class="error-title">Failed to Load Facets</h3>
					</div>
					<div class="error-content">
						<p class="error-message">{{ error }}</p>
						<NcButton type="primary" @click="loadFacets">
							<template #icon>
								<Refresh :size="20" />
							</template>
							Try Again
						</NcButton>
					</div>
				</div>
			</div>

			<!-- Success State -->
			<div v-else-if="facetsData" class="results-section">
				<div class="results-header">
					<h3>Configure SOLR Facets</h3>
					<span class="results-count">{{ totalFacets }} facets discovered</span>
				</div>
				
				<div class="facets-summary">
					<div class="summary-card">
						<h4>Metadata Facets (@self)</h4>
						<p>{{ metadataCount }} facets</p>
					</div>
					<div class="summary-card">
						<h4>Object Field Facets</h4>
						<p>{{ objectFieldCount }} facets</p>
					</div>
				</div>

				<!-- Global Settings -->
				<div class="global-settings-section">
					<h4>Global Settings</h4>
					<div class="global-settings-form">
						<div class="form-row">
							<NcCheckboxRadioSwitch v-model="globalSettings.showCount">
								Show facet counts by default
							</NcCheckboxRadioSwitch>
						</div>
						<div class="form-row">
							<NcCheckboxRadioSwitch v-model="globalSettings.showEmpty">
								Show empty facets by default
							</NcCheckboxRadioSwitch>
						</div>
						<div class="form-row">
							<label>Default maximum items per facet:</label>
							<input 
								v-model.number="globalSettings.maxItems" 
								type="number" 
								min="1" 
								max="100" 
								class="form-input" />
						</div>
					</div>
				</div>

				<!-- Facet Configuration Form -->
				<div class="facet-config-section">
					<h4>Individual Facet Settings</h4>
					
					<!-- Metadata Facets -->
					<div v-if="metadataFacets.length > 0" class="facet-category">
						<h5>Metadata Facets (@self)</h5>
						<div class="facets-list">
							<VueDraggable 
								v-model="metadataFacets" 
								@end="onMetadataFacetReorder"
								easing="ease-in-out"
								class="draggable-container">
								<div 
									v-for="facet in metadataFacets" 
									:key="facet.fieldName" 
									class="facet-item draggable-facet-item">
								<div class="facet-header">
									<Drag class="drag-handle" :size="20" />
									<h6>{{ facet.displayName || facet.fieldName }}</h6>
									<div class="facet-header-controls">
										<NcCheckboxRadioSwitch v-model="facet.config.enabled">
											Enabled
										</NcCheckboxRadioSwitch>
										<button 
											class="chevron-toggle"
											@click="toggleFacetExpanded(facet)"
											:aria-label="facet.expanded ? 'Collapse details' : 'Expand details'">
											<ChevronUp v-if="facet.expanded" :size="20" />
											<ChevronDown v-else :size="20" />
										</button>
									</div>
								</div>
								
								<div v-if="facet.expanded" class="facet-details">
									<div class="form-row">
										<label>Display Title:</label>
										<input 
											v-model="facet.config.title" 
											type="text" 
											class="form-input" 
											:placeholder="facet.displayName || facet.fieldName" />
									</div>
									
									<div class="form-row">
										<label>Description:</label>
										<textarea 
											v-model="facet.config.description" 
											class="form-textarea" 
											placeholder="Optional description for this facet"
											rows="2"></textarea>
									</div>
									
									<div class="form-row">
										<label>Display Order:</label>
										<input 
											v-model.number="facet.config.order" 
											type="number" 
											class="form-input" 
											min="0" />
									</div>
									
									<div class="form-row">
										<label>Maximum Items:</label>
										<input 
											v-model.number="facet.config.maxItems" 
											type="number" 
											min="1" 
											max="100" 
											class="form-input" />
									</div>
									
									<div class="form-row">
										<label>Facet Type:</label>
										<select v-model="facet.config.facetType" class="form-select">
											<option value="terms">Terms (discrete values)</option>
											<option value="range">Range (numeric/date ranges)</option>
											<option value="date_histogram">Date Histogram</option>
										</select>
									</div>
									
									<div class="form-row">
										<label>Display Type:</label>
										<select v-model="facet.config.displayType" class="form-select">
											<option 
												v-for="displayType in facet.suggestedDisplayTypes" 
												:key="displayType" 
												:value="displayType">
												{{ formatDisplayType(displayType) }}
											</option>
										</select>
									</div>
									
									<div class="form-row">
										<NcCheckboxRadioSwitch v-model="facet.config.showCount">
											Show item counts
										</NcCheckboxRadioSwitch>
									</div>
								</div>
							</div>
							</VueDraggable>
						</div>
					</div>

					<!-- Object Field Facets -->
					<div v-if="objectFieldFacets.length > 0" class="facet-category">
						<h5>Object Field Facets</h5>
						<div class="facets-list">
							<VueDraggable 
								v-model="objectFieldFacets" 
								@end="onObjectFieldFacetReorder"
								easing="ease-in-out"
								class="draggable-container">
								<div 
									v-for="facet in objectFieldFacets" 
									:key="facet.fieldName" 
									class="facet-item draggable-facet-item">
								<div class="facet-header">
									<Drag class="drag-handle" :size="20" />
									<h6>{{ facet.displayName || facet.fieldName }}</h6>
									<div class="facet-header-controls">
										<NcCheckboxRadioSwitch v-model="facet.config.enabled">
											Enabled
										</NcCheckboxRadioSwitch>
										<button 
											class="chevron-toggle"
											@click="toggleFacetExpanded(facet)"
											:aria-label="facet.expanded ? 'Collapse details' : 'Expand details'">
											<ChevronUp v-if="facet.expanded" :size="20" />
											<ChevronDown v-else :size="20" />
										</button>
									</div>
								</div>
								
								<div v-if="facet.expanded" class="facet-details">
									<div class="form-row">
										<label>Display Title:</label>
										<input 
											v-model="facet.config.title" 
											type="text" 
											class="form-input" 
											:placeholder="facet.displayName || facet.fieldName" />
									</div>
									
									<div class="form-row">
										<label>Description:</label>
										<textarea 
											v-model="facet.config.description" 
											class="form-textarea" 
											placeholder="Optional description for this facet"
											rows="2"></textarea>
									</div>
									
									<div class="form-row">
										<label>Display Order:</label>
										<input 
											v-model.number="facet.config.order" 
											type="number" 
											class="form-input" 
											min="0" />
									</div>
									
									<div class="form-row">
										<label>Maximum Items:</label>
										<input 
											v-model.number="facet.config.maxItems" 
											type="number" 
											min="1" 
											max="100" 
											class="form-input" />
									</div>
									
									<div class="form-row">
										<label>Facet Type:</label>
										<select v-model="facet.config.facetType" class="form-select">
											<option value="terms">Terms (discrete values)</option>
											<option value="range">Range (numeric/date ranges)</option>
											<option value="date_histogram">Date Histogram</option>
										</select>
									</div>
									
									<div class="form-row">
										<label>Display Type:</label>
										<select v-model="facet.config.displayType" class="form-select">
											<option 
												v-for="displayType in facet.suggestedDisplayTypes" 
												:key="displayType" 
												:value="displayType">
												{{ formatDisplayType(displayType) }}
											</option>
										</select>
									</div>
									
									<div class="form-row">
										<NcCheckboxRadioSwitch v-model="facet.config.showCount">
											Show item counts
										</NcCheckboxRadioSwitch>
									</div>
								</div>
							</div>
							</VueDraggable>
						</div>
					</div>
				</div>
			</div>

			<!-- Initial State -->
			<div v-else class="initial-state">
				<div class="initial-content">
					<Tune :size="64" class="initial-icon" />
					<h3>Configure SOLR Facets</h3>
					<p>Load and configure facets from your SOLR index.</p>
					<NcButton type="primary" @click="loadFacets">
						<template #icon>
							<Magnify :size="20" />
						</template>
						Load Facets
					</NcButton>
				</div>
			</div>
		</div>

		<template #actions>
			<NcButton @click="$emit('close')">
				Cancel
			</NcButton>
			<NcButton 
				v-if="facetsData"
				type="primary" 
				@click="saveFacetConfiguration"
				:disabled="loading">
				<template #icon>
					<NcLoadingIcon v-if="loading" :size="20" />
					<Save v-else :size="20" />
				</template>
				Save Configuration
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import NcDialog from '@nextcloud/vue/dist/Components/NcDialog.js'
import NcButton from '@nextcloud/vue/dist/Components/NcButton.js'
import NcLoadingIcon from '@nextcloud/vue/dist/Components/NcLoadingIcon.js'
import NcCheckboxRadioSwitch from '@nextcloud/vue/dist/Components/NcCheckboxRadioSwitch.js'

import Magnify from 'vue-material-design-icons/Magnify.vue'
import AlertCircle from 'vue-material-design-icons/AlertCircle.vue'
import Tune from 'vue-material-design-icons/Tune.vue'
import Refresh from 'vue-material-design-icons/Refresh.vue'
import Save from 'vue-material-design-icons/ContentSave.vue'
import Drag from 'vue-material-design-icons/Drag.vue'
import ChevronDown from 'vue-material-design-icons/ChevronDown.vue'
import ChevronUp from 'vue-material-design-icons/ChevronUp.vue'

import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { showSuccess, showError } from '@nextcloud/dialogs'
// import { VueDraggable } from 'vue-draggable-plus'

export default {
	name: 'FacetConfigModal',
	components: {
		NcDialog,
		NcButton,
		NcLoadingIcon,
		NcCheckboxRadioSwitch,
		Magnify,
		AlertCircle,
		Tune,
		Refresh,
		Save,
		Drag,
		ChevronDown,
		ChevronUp,
		// VueDraggable,
	},
	props: {
		show: {
			type: Boolean,
			default: false,
		},
	},
	emits: ['close'],
	data() {
		return {
			loading: false,
			facetsData: null,
			error: null,
			globalSettings: {
				showCount: true,
				showEmpty: false,
				maxItems: 10,
			},
			metadataFacets: [],
			objectFieldFacets: [],
		}
	},
	computed: {
		totalFacets() {
			if (!this.facetsData || !this.facetsData.facets) return 0
			return this.metadataCount + this.objectFieldCount
		},
		metadataCount() {
			if (!this.facetsData || !this.facetsData.facets || !this.facetsData.facets['@self']) return 0
			return Object.keys(this.facetsData.facets['@self']).length
		},
		objectFieldCount() {
			if (!this.facetsData || !this.facetsData.facets || !this.facetsData.facets['object_fields']) return 0
			return Object.keys(this.facetsData.facets['object_fields']).length
		},
	},
	watch: {
		show: {
			handler(newVal) {
				if (newVal) {
					this.loadFacets()
				} else {
					this.resetModal()
				}
			},
			immediate: false
		},
	},
	methods: {
		/**
		 * Load facets from SOLR API
		 */
		async loadFacets() {
			console.log('🚀 FacetConfigModal: loadFacets called')
			this.loading = true
			this.error = null
			
			try {
				// Use the new unified endpoint that merges discovery with configuration
				const url = generateUrl('/apps/openregister/api/solr/facet-config')
				console.log('📡 FacetConfigModal: Making API call to:', url)
				
				const response = await axios.get(url)
				console.log('✅ FacetConfigModal: API response received:', response.data)
				
				if (response.data && response.data.success) {
					this.facetsData = response.data
					
					// Update global settings from response
					const globalSettings = response.data.global_settings || {
						show_count: true,
						show_empty: false,
						max_items: 10
					}
					
					this.globalSettings = {
						showCount: globalSettings.show_count,
						showEmpty: globalSettings.show_empty,
						maxItems: globalSettings.max_items
					}
					
					// Process metadata facets into reactive data
					this.metadataFacets = []
					if (this.facetsData.facets['@self']) {
						this.metadataFacets = Object.entries(this.facetsData.facets['@self']).map(([key, facetInfo]) => ({
							fieldName: `self_${key}`,
							...facetInfo,
							expanded: false, // Start collapsed for better drag UX
							config: facetInfo.config // Backend already merged the configuration
						}))
					}
					
					// Process object field facets into reactive data
					this.objectFieldFacets = []
					if (this.facetsData.facets['object_fields']) {
						this.objectFieldFacets = Object.entries(this.facetsData.facets['object_fields']).map(([key, facetInfo]) => ({
							fieldName: key,
							...facetInfo,
							expanded: false, // Start collapsed for better drag UX
							config: facetInfo.config // Backend already merged the configuration
						}))
						
						// Sort by order
						this.objectFieldFacets.sort((a, b) => a.config.order - b.config.order)
					}
					
					// Sort metadata facets by order
					if (this.metadataFacets.length > 0) {
						this.metadataFacets.sort((a, b) => a.config.order - b.config.order)
					}
					
					console.log(`✅ FacetConfigModal: Processed ${this.metadataFacets.length} metadata facets`)
					console.log(`✅ FacetConfigModal: Processed ${this.objectFieldFacets.length} object field facets`)
					console.log('✅ FacetConfigModal: Facets loaded with existing configuration')
				} else {
					throw new Error('Invalid response format: ' + JSON.stringify(response.data))
				}
				
			} catch (error) {
				console.error('❌ FacetConfigModal: Failed to load facets:', error)
				this.error = error.response?.data?.message || error.message || 'Failed to load facets'
			} finally {
				this.loading = false
			}
		},

		/**
		 * Format display type for human-readable labels
		 */
		formatDisplayType(displayType) {
			const typeMap = {
				'select': 'Dropdown Select',
				'multiselect': 'Multi-Select',
				'checkbox': 'Checkboxes',
				'radio': 'Radio Buttons',
				'range': 'Range Slider',
				'date_range': 'Date Range Picker'
			}
			return typeMap[displayType] || displayType
		},

		/**
		 * Save facet configuration
		 */
		async saveFacetConfiguration() {
			console.log('💾 Saving facet configuration...')
			this.loading = true
			
			try {
				// Collect all facet configurations
				const facetConfig = {
					global_settings: this.globalSettings,
					facets: {}
				}
				
				// Add metadata facets
				this.metadataFacets.forEach(facet => {
					facetConfig.facets[facet.fieldName] = facet.config
				})
				
				// Add object field facets
				this.objectFieldFacets.forEach(facet => {
					facetConfig.facets[facet.fieldName] = facet.config
				})
				
				console.log('💾 Facet configuration to save:', facetConfig)
				
				// Make API call to save configuration using the new unified endpoint
				const url = generateUrl('/apps/openregister/api/solr/facet-config')
				const response = await axios.post(url, facetConfig)
				
				// Check if the response is successful
				if (response.data && response.data.success) {
					showSuccess(`Successfully saved configuration for ${Object.keys(facetConfig.facets).length} facets!`)
					console.log('✅ Facet configuration saved successfully:', response.data)
				} else {
					throw new Error(response.data?.message || response.data?.error || 'Failed to save configuration')
				}
				
			} catch (error) {
				console.error('❌ Failed to save facet configuration:', error)
				showError(error.response?.data?.message || error.message || 'Failed to save facet configuration')
			} finally {
				this.loading = false
			}
		},

		/**
		 * Handle metadata facet reordering
		 */
		onMetadataFacetReorder() {
			console.log('🔄 Metadata facets reordered')
			this.updateFacetOrder(this.metadataFacets, 0)
		},

		/**
		 * Handle object field facet reordering
		 */
		onObjectFieldFacetReorder() {
			console.log('🔄 Object field facets reordered')
			this.updateFacetOrder(this.objectFieldFacets, 100)
		},

		/**
		 * Update facet order based on array position
		 */
		updateFacetOrder(facets, baseOrder) {
			facets.forEach((facet, index) => {
				facet.config.order = baseOrder + index
			})
			console.log('📊 Updated facet order:', facets.map(f => ({ name: f.fieldName, order: f.config.order })))
		},

		/**
		 * Toggle facet expanded state
		 */
		toggleFacetExpanded(facet) {
			facet.expanded = !facet.expanded
		},

		/**
		 * Reset modal state
		 */
		resetModal() {
			this.facetsData = null
			this.error = null
			this.metadataFacets = []
			this.objectFieldFacets = []
		},
	},
}
</script>

<style lang="scss" scoped>
.facet-config-modal {
	padding: 20px;
	max-height: 80vh;
	overflow-y: auto;
}

.loading-section, .initial-state {
	text-align: center;
	padding: 60px 20px;
	color: var(--color-text-maxcontrast);
	
	.initial-icon {
		margin-bottom: 16px;
		opacity: 0.7;
	}
	
	h3 {
		margin-bottom: 8px;
		color: var(--color-main-text);
	}
	
	p {
		margin-bottom: 16px;
	}
}

.error-section {
	.error-banner {
		background: var(--color-error);
		color: var(--color-primary-text);
		border-radius: var(--border-radius-large);
		padding: 16px;
		margin-bottom: 20px;
		
		.error-header {
			display: flex;
			align-items: center;
			gap: 12px;
			margin-bottom: 12px;
			
			.error-icon {
				flex-shrink: 0;
			}
			
			.error-title {
				margin: 0;
				font-size: 18px;
				font-weight: 600;
			}
		}
		
		.error-message {
			margin-bottom: 12px;
			font-weight: 500;
		}
	}
}

.results-section {
	.results-header {
		display: flex;
		justify-content: space-between;
		align-items: center;
		margin-bottom: 20px;
		
		h3 {
			margin: 0;
		}
		
		.results-count {
			color: var(--color-text-maxcontrast);
			font-size: 14px;
		}
	}
}

.facets-summary {
	display: grid;
	grid-template-columns: 1fr 1fr;
	gap: 16px;
	margin-bottom: 24px;
	
	.summary-card {
		background: var(--color-background-hover);
		padding: 16px;
		border-radius: var(--border-radius-large);
		text-align: center;
		
		h4 {
			margin: 0 0 8px 0;
			color: var(--color-main-text);
		}
		
		p {
			margin: 0;
			font-size: 24px;
			font-weight: bold;
			color: var(--color-primary-element);
		}
	}
}

.global-settings-section, .facet-config-section {
	margin-bottom: 24px;
	
	h4 {
		margin-bottom: 16px;
		color: var(--color-main-text);
		border-bottom: 1px solid var(--color-border);
		padding-bottom: 8px;
	}
}

.global-settings-form {
	background: var(--color-background-hover);
	padding: 16px;
	border-radius: var(--border-radius-large);
	
	.form-row {
		display: flex;
		align-items: center;
		gap: 12px;
		margin-bottom: 12px;
		
		&:last-child {
			margin-bottom: 0;
		}
		
		label {
			font-weight: 500;
			color: var(--color-main-text);
			min-width: 200px;
		}
		
		.form-input {
			padding: 8px 12px;
			border: 1px solid var(--color-border);
			border-radius: var(--border-radius);
			background: var(--color-main-background);
			color: var(--color-main-text);
			width: 100px;
		}
	}
}

.facet-category {
	margin-bottom: 32px;
	
	h5 {
		margin-bottom: 16px;
		color: var(--color-main-text);
		font-size: 16px;
		font-weight: 600;
	}
}

.draggable-container {
	.draggable-facet-item {
		cursor: move;
		transition: all 0.2s ease;
		
		&:hover {
			transform: translateY(-2px);
			box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
		}
	}
}

.facets-list {
	.facet-item {
		border: 1px solid var(--color-border);
		border-radius: var(--border-radius-large);
		margin-bottom: 16px;
		background: var(--color-main-background);
		
		.facet-header {
			display: flex;
			align-items: center;
			gap: 12px;
			padding: 16px;
			border-bottom: 1px solid var(--color-border);
			
			.drag-handle {
				color: var(--color-text-maxcontrast);
				cursor: grab;
				flex-shrink: 0;
				
				&:hover {
					color: var(--color-main-text);
				}
				
				&:active {
					cursor: grabbing;
				}
			}
			
			h6 {
				margin: 0;
				font-size: 14px;
				font-weight: 600;
				color: var(--color-main-text);
				flex: 1;
			}
			
			.facet-header-controls {
				display: flex;
				align-items: center;
				gap: 12px;
				
				.chevron-toggle {
					background: none;
					border: none;
					padding: 4px;
					border-radius: var(--border-radius);
					cursor: pointer;
					color: var(--color-text-maxcontrast);
					display: flex;
					align-items: center;
					justify-content: center;
					transition: all 0.2s ease;
					
					&:hover {
						background: var(--color-background-hover);
						color: var(--color-main-text);
					}
					
					&:active {
						background: var(--color-background-dark);
					}
				}
			}
		}
		
		.facet-details {
			padding: 16px;
			background: var(--color-background-hover);
			display: grid;
			grid-template-columns: 1fr 1fr;
			gap: 16px;
			
			.form-row {
				display: flex;
				flex-direction: column;
				gap: 6px;
				
				&:last-child {
					grid-column: 1 / -1;
				}
				
				label {
					font-weight: 500;
					color: var(--color-main-text);
					font-size: 13px;
				}
				
				.form-input, .form-select {
					padding: 8px 12px;
					border: 1px solid var(--color-border);
					border-radius: var(--border-radius);
					background: var(--color-main-background);
					color: var(--color-main-text);
					font-size: 14px;
				}
				
				.form-textarea {
					padding: 8px 12px;
					border: 1px solid var(--color-border);
					border-radius: var(--border-radius);
					background: var(--color-main-background);
					color: var(--color-main-text);
					font-size: 14px;
					resize: vertical;
					min-height: 60px;
				}
			}
		}
	}
}
</style>

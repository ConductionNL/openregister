<template>
	<NcDialog
		v-if="show"
		name="Inspect SOLR Index"
		:can-close="!loading"
		@closing="$emit('close')"
		size="large">
		
		<div class="inspect-index-modal">
			<!-- Search Controls -->
			<div class="search-controls">
				<div class="search-row">
					<NcTextField
						v-model="searchQuery"
						label="Search Query"
						placeholder="self_name:* or title:example or created:[2024-01-01T00:00:00Z TO NOW]"
						@keyup.enter="searchDocuments">
						<template #trailing-button-icon>
							<Magnify :size="20" />
						</template>
					</NcTextField>
					
					<NcButton type="primary" @click="searchDocuments" :disabled="loading">
						<template #icon>
							<Magnify :size="20" />
						</template>
						Search
					</NcButton>
					
					<NcButton type="tertiary" @click="openQueryHelp" :disabled="loading">
						<template #icon>
							<InformationOutline :size="20" />
						</template>
						Query Help
					</NcButton>
				</div>
				
				<div class="filter-row">
					<NcSelect
						v-model="selectedFields"
						:options="availableFields"
						label="Fields to Display"
						multiple
						placeholder="Select fields to display">
					</NcSelect>
					
					<div class="pagination-controls">
						<span class="pagination-info">
							Showing {{ startIndex + 1 }}-{{ Math.min(startIndex + pageSize, totalResults) }} of {{ totalResults }}
						</span>
						<NcButton
							type="tertiary"
							:disabled="startIndex === 0 || loading"
							@click="previousPage">
							<template #icon>
								<ChevronLeft :size="20" />
							</template>
						</NcButton>
						<NcButton
							type="tertiary"
							:disabled="startIndex + pageSize >= totalResults || loading"
							@click="nextPage">
							<template #icon>
								<ChevronRight :size="20" />
							</template>
						</NcButton>
					</div>
				</div>
			</div>

			<!-- Loading State -->
			<div v-if="loading" class="loading-section">
				<NcLoadingIcon :size="64" />
				<p>Searching SOLR index...</p>
			</div>

			<!-- Error State -->
			<div v-else-if="error" class="error-section">
				<div class="error-banner">
					<div class="error-header">
						<AlertCircle :size="24" class="error-icon" />
						<h3 class="error-title">Search Failed</h3>
					</div>
					<div class="error-content">
						<p class="error-message">{{ error }}</p>
						<div v-if="errorDetails" class="error-details">
							<NcButton type="tertiary" @click="showErrorDetails = !showErrorDetails">
								<template #icon>
									<ChevronDown v-if="!showErrorDetails" :size="20" />
									<ChevronUp v-else :size="20" />
								</template>
								{{ showErrorDetails ? 'Hide' : 'Show' }} Technical Details
							</NcButton>
							<div v-if="showErrorDetails" class="error-details-content">
								<pre>{{ JSON.stringify(errorDetails, null, 2) }}</pre>
							</div>
						</div>
					</div>
				</div>
			</div>

			<!-- Results -->
			<div v-else-if="documents && documents.length > 0" class="results-section">
				<div class="results-header">
					<h3>Search Results</h3>
					<span class="results-count">{{ totalResults }} documents found</span>
				</div>
				
				<div class="documents-list">
					<div
						v-for="(document, index) in documents"
						:key="document.id || index"
						class="document-card"
						:class="{ 'expanded': expandedDocs.includes(index) }"
						@click="toggleDocument(index)">
						
						<div class="document-header">
							<div class="document-title">
								<strong>Document {{ startIndex + index + 1 }}</strong>
								<span v-if="document.id" class="document-id">ID: {{ document.id }}</span>
							</div>
							<NcButton
								type="tertiary"
								:aria-expanded="expandedDocs.includes(index)"
								@click.stop="toggleDocument(index)">
								<template #icon>
									<ChevronDown v-if="!expandedDocs.includes(index)" :size="20" />
									<ChevronUp v-else :size="20" />
								</template>
							</NcButton>
						</div>
						
						<!-- Document Preview (always visible) -->
						<div class="document-preview">
							<div
								v-for="field in getPreviewFields(document)"
								:key="field.name"
								class="preview-field">
								<span class="field-name">{{ field.name }}:</span>
								<span class="field-value">{{ truncateValue(field.value) }}</span>
							</div>
						</div>
						
						<!-- Full Document (expandable) -->
						<div v-if="expandedDocs.includes(index)" class="document-details">
							<div class="document-fields">
								<div
									v-for="[fieldName, fieldValue] in Object.entries(document)"
									:key="fieldName"
									class="document-field"
									:class="getFieldClass(fieldName)">
									<div class="field-header">
										<span class="field-name">{{ fieldName }}</span>
										<span class="field-type">{{ getFieldType(fieldValue) }}</span>
									</div>
									<div class="field-content">
										<pre v-if="isComplexValue(fieldValue)" class="field-value complex">{{ JSON.stringify(fieldValue, null, 2) }}</pre>
										<span v-else class="field-value simple">{{ fieldValue }}</span>
									</div>
								</div>
							</div>
						</div>
					</div>
				</div>
			</div>

			<!-- No Results -->
			<div v-else-if="!loading && searchExecuted" class="no-results-section">
				<div class="no-results-content">
					<FileDocumentOutline :size="64" class="no-results-icon" />
					<h3>No Documents Found</h3>
					<p>Your search query didn't match any documents in the SOLR index.</p>
					<p><strong>Query:</strong> {{ searchQuery }}</p>
					<NcButton type="primary" @click="searchQuery = 'self_name:*'; searchDocuments()">
						<template #icon>
							<Magnify :size="20" />
						</template>
						Show Named Documents
					</NcButton>
				</div>
			</div>

			<!-- Initial State -->
			<div v-else class="initial-state">
				<div class="initial-content">
					<FileSearchOutline :size="64" class="initial-icon" />
					<h3>Inspect SOLR Index</h3>
					<p>Search and browse documents stored in your SOLR index.</p>
					<p>Use <code>self_name:*</code> to see documents with names, or enter a specific query like <code>title:example</code>.</p>
					<NcButton type="primary" @click="searchQuery = 'self_name:*'; searchDocuments()">
						<template #icon>
							<Magnify :size="20" />
						</template>
						Search Documents
					</NcButton>
				</div>
			</div>
		</div>
	</NcDialog>
</template>

<script>
import NcDialog from '@nextcloud/vue/dist/Components/NcDialog.js'
import NcButton from '@nextcloud/vue/dist/Components/NcButton.js'
import NcTextField from '@nextcloud/vue/dist/Components/NcTextField.js'
import NcSelect from '@nextcloud/vue/dist/Components/NcSelect.js'
import NcLoadingIcon from '@nextcloud/vue/dist/Components/NcLoadingIcon.js'

import Magnify from 'vue-material-design-icons/Magnify.vue'
import ChevronLeft from 'vue-material-design-icons/ChevronLeft.vue'
import ChevronRight from 'vue-material-design-icons/ChevronRight.vue'
import ChevronDown from 'vue-material-design-icons/ChevronDown.vue'
import ChevronUp from 'vue-material-design-icons/ChevronUp.vue'
import AlertCircle from 'vue-material-design-icons/AlertCircle.vue'
import FileDocumentOutline from 'vue-material-design-icons/FileDocumentOutline.vue'
import FileSearchOutline from 'vue-material-design-icons/FileSearchOutline.vue'
import InformationOutline from 'vue-material-design-icons/InformationOutline.vue'

import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

export default {
	name: 'InspectIndexModal',
	components: {
		NcDialog,
		NcButton,
		NcTextField,
		NcSelect,
		NcLoadingIcon,
		Magnify,
		ChevronLeft,
		ChevronRight,
		ChevronDown,
		ChevronUp,
		AlertCircle,
		FileDocumentOutline,
		FileSearchOutline,
		InformationOutline,
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
			searchExecuted: false,
			searchQuery: 'self_name:*',
			documents: [],
			totalResults: 0,
			startIndex: 0,
			pageSize: 20,
			selectedFields: [],
			availableFields: [],
			expandedDocs: [],
			error: null,
			errorDetails: null,
			showErrorDetails: false,
		}
	},
	computed: {
		/**
		 * Get preview fields to show in collapsed view
		 */
		getPreviewFields() {
			return (document) => {
				const previewFields = ['id', 'title', 'naam', 'name', 'self_tenant', 'created', 'updated']
				const fields = []
				
				for (const fieldName of previewFields) {
					if (document[fieldName] !== undefined) {
						fields.push({
							name: fieldName,
							value: document[fieldName]
						})
						if (fields.length >= 3) break
					}
				}
				
				// If no standard fields found, show first 3 fields
				if (fields.length === 0) {
					const allFields = Object.entries(document).slice(0, 3)
					for (const [name, value] of allFields) {
						fields.push({ name, value })
					}
				}
				
				return fields
			}
		},
	},
	watch: {
		show(newVal) {
			if (newVal) {
				this.loadAvailableFields()
			} else {
				this.resetModal()
			}
		},
	},
	methods: {
		/**
		 * Load available fields from SOLR schema
		 */
		async loadAvailableFields() {
			try {
				const response = await axios.get(generateUrl('/apps/openregister/api/settings/solr/fields'))
				if (response.data && response.data.fields) {
					this.availableFields = response.data.fields.map(field => ({
						id: field.name,
						label: field.name + (field.type ? ` (${field.type})` : ''),
					}))
				}
			} catch (error) {
				console.warn('Could not load SOLR fields:', error)
				this.availableFields = []
			}
		},

		/**
		 * Search documents in SOLR index
		 */
		async searchDocuments() {
			this.loading = true
			this.error = null
			this.errorDetails = null
			
			try {
				const params = {
					query: this.searchQuery || '*:*',
					start: this.startIndex,
					rows: this.pageSize,
				}
				
				if (this.selectedFields.length > 0) {
					params.fields = this.selectedFields.map(f => f.id).join(',')
				}
				
				const response = await axios.post(
					generateUrl('/apps/openregister/api/settings/solr/inspect'),
					params
				)
				
				if (response.data.success) {
					this.documents = response.data.documents || []
					this.totalResults = response.data.total || 0
					this.searchExecuted = true
				} else {
					this.error = response.data.error || 'Unknown error occurred'
					this.errorDetails = response.data.error_details || null
				}
				
			} catch (error) {
				console.error('SOLR inspect search failed:', error)
				this.error = error.response?.data?.error || error.message || 'Search request failed'
				this.errorDetails = error.response?.data?.error_details || null
			} finally {
				this.loading = false
			}
		},

		/**
		 * Go to next page
		 */
		nextPage() {
			if (this.startIndex + this.pageSize < this.totalResults) {
				this.startIndex += this.pageSize
				this.searchDocuments()
			}
		},

		/**
		 * Go to previous page
		 */
		previousPage() {
			if (this.startIndex > 0) {
				this.startIndex = Math.max(0, this.startIndex - this.pageSize)
				this.searchDocuments()
			}
		},

		/**
		 * Toggle document expansion
		 */
		toggleDocument(index) {
			const docIndex = this.expandedDocs.indexOf(index)
			if (docIndex > -1) {
				this.expandedDocs.splice(docIndex, 1)
			} else {
				this.expandedDocs.push(index)
			}
		},

		/**
		 * Truncate long values for preview
		 */
		truncateValue(value) {
			if (typeof value !== 'string') {
				value = String(value)
			}
			return value.length > 100 ? value.substring(0, 100) + '...' : value
		},

		/**
		 * Get field type for display
		 */
		getFieldType(value) {
			if (Array.isArray(value)) return 'array'
			if (value === null) return 'null'
			if (typeof value === 'object') return 'object'
			if (typeof value === 'boolean') return 'boolean'
			if (typeof value === 'number') return 'number'
			if (typeof value === 'string') {
				if (value.match(/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}/)) return 'datetime'
				if (value.match(/^[a-f0-9-]{36}$/i)) return 'uuid'
				return 'string'
			}
			return typeof value
		},

		/**
		 * Check if value is complex (object/array)
		 */
		isComplexValue(value) {
			return typeof value === 'object' && value !== null
		},

		/**
		 * Get CSS class for field based on name and type
		 */
		getFieldClass(fieldName) {
			if (fieldName === 'id' || fieldName.endsWith('_id')) return 'field-id'
			if (fieldName.includes('tenant')) return 'field-tenant'
			if (fieldName.includes('created') || fieldName.includes('updated')) return 'field-datetime'
			if (fieldName.startsWith('self_')) return 'field-system'
			return 'field-data'
		},

		/**
		 * Open query help documentation
		 */
		openQueryHelp() {
			// Open the official SOLR query documentation in a new tab
			const helpUrl = 'https://solr.apache.org/guide/solr/latest/query-guide/standard-query-parser.html'
			window.open(helpUrl, '_blank')
		},

		/**
		 * Reset modal state
		 */
		resetModal() {
			this.documents = []
			this.totalResults = 0
			this.startIndex = 0
			this.searchExecuted = false
			this.expandedDocs = []
			this.error = null
			this.errorDetails = null
			this.showErrorDetails = false
		},
	},
}
</script>

<style lang="scss" scoped>
.inspect-index-modal {
	padding: 20px;
	max-height: 80vh;
	overflow-y: auto;
}

.search-controls {
	margin-bottom: 20px;
	border-bottom: 1px solid var(--color-border);
	padding-bottom: 20px;
	
	.search-row {
		display: flex;
		gap: 12px;
		margin-bottom: 16px;
		align-items: flex-end;
		
		.text-field {
			flex: 1;
		}
	}
	
	.filter-row {
		display: flex;
		gap: 12px;
		align-items: center;
		flex-wrap: wrap;
		
		.multiselect {
			flex: 1;
			min-width: 200px;
		}
		
		.pagination-controls {
			display: flex;
			align-items: center;
			gap: 8px;
			
			.pagination-info {
				font-size: 14px;
				color: var(--color-text-maxcontrast);
				white-space: nowrap;
			}
		}
	}
}

.loading-section, .initial-state, .no-results-section {
	text-align: center;
	padding: 60px 20px;
	color: var(--color-text-maxcontrast);
	
	.loading-icon, .initial-icon, .no-results-icon {
		margin-bottom: 16px;
		opacity: 0.7;
	}
	
	h3 {
		margin-bottom: 8px;
		color: var(--color-main-text);
	}
	
	p {
		margin-bottom: 8px;
		
		code {
			background: var(--color-background-dark);
			padding: 2px 6px;
			border-radius: 3px;
			font-family: monospace;
		}
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
		
		.error-details-content {
			margin-top: 12px;
			background: rgba(255, 255, 255, 0.1);
			border-radius: var(--border-radius);
			padding: 12px;
			
			pre {
				margin: 0;
				font-size: 12px;
				white-space: pre-wrap;
				word-break: break-word;
			}
		}
	}
}

.results-section {
	.results-header {
		display: flex;
		justify-content: space-between;
		align-items: center;
		margin-bottom: 16px;
		
		h3 {
			margin: 0;
		}
		
		.results-count {
			color: var(--color-text-maxcontrast);
			font-size: 14px;
		}
	}
}

.documents-list {
	.document-card {
		border: 1px solid var(--color-border);
		border-radius: var(--border-radius-large);
		margin-bottom: 12px;
		background: var(--color-main-background);
		transition: all 0.2s ease;
		cursor: pointer;
		
		&:hover {
			border-color: var(--color-primary-element);
			box-shadow: 0 2px 8px var(--color-box-shadow);
		}
		
		&.expanded {
			border-color: var(--color-primary-element);
			box-shadow: 0 4px 12px var(--color-box-shadow);
		}
		
		.document-header {
			display: flex;
			justify-content: space-between;
			align-items: center;
			padding: 16px;
			border-bottom: 1px solid var(--color-border);
			
			.document-title {
				display: flex;
				flex-direction: column;
				gap: 4px;
				
				.document-id {
					font-size: 12px;
					color: var(--color-text-maxcontrast);
					font-family: monospace;
				}
			}
		}
		
		.document-preview {
			padding: 16px;
			
			.preview-field {
				display: flex;
				gap: 8px;
				margin-bottom: 8px;
				
				&:last-child {
					margin-bottom: 0;
				}
				
				.field-name {
					font-weight: 500;
					color: var(--color-text-maxcontrast);
					min-width: 80px;
				}
				
				.field-value {
					color: var(--color-main-text);
					word-break: break-word;
				}
			}
		}
		
		.document-details {
			border-top: 1px solid var(--color-border);
			background: var(--color-background-hover);
			
			.document-fields {
				padding: 16px;
				
				.document-field {
					margin-bottom: 16px;
					padding: 12px;
					background: var(--color-main-background);
					border-radius: var(--border-radius);
					border-left: 3px solid var(--color-border);
					
					&:last-child {
						margin-bottom: 0;
					}
					
					&.field-id {
						border-left-color: var(--color-primary-element);
					}
					
					&.field-tenant {
						border-left-color: var(--color-warning);
					}
					
					&.field-datetime {
						border-left-color: var(--color-success);
					}
					
					&.field-system {
						border-left-color: var(--color-text-maxcontrast);
					}
					
					.field-header {
						display: flex;
						justify-content: space-between;
						align-items: center;
						margin-bottom: 8px;
						
						.field-name {
							font-weight: 600;
							font-family: monospace;
							color: var(--color-main-text);
						}
						
						.field-type {
							font-size: 12px;
							background: var(--color-background-dark);
							padding: 2px 6px;
							border-radius: 3px;
							color: var(--color-text-maxcontrast);
						}
					}
					
					.field-content {
						.field-value {
							&.simple {
								color: var(--color-main-text);
								word-break: break-word;
							}
							
							&.complex {
								background: var(--color-background-dark);
								border-radius: var(--border-radius);
								padding: 12px;
								font-size: 12px;
								color: var(--color-main-text);
								margin: 0;
								white-space: pre-wrap;
								word-break: break-word;
								overflow-x: auto;
							}
						}
					}
				}
			}
		}
	}
}
</style>

<template>
	<div>
		<NcSettingsSection name="SOLR Search Management"
			description="Monitor and manage SOLR search performance and operations">
			
			<!-- Loading State -->
			<div v-if="loadingStats" class="loading-section">
				<NcLoadingIcon :size="64" />
				<p>Loading SOLR statistics...</p>
			</div>

			<!-- Error State -->
			<div v-else-if="solrError" class="error-section">
				<p class="error-message">❌ {{ solrErrorMessage }}</p>
				<NcButton type="primary" @click="loadSolrStats">
							<template #icon>
						<Refresh :size="20" />
							</template>
					Retry Connection
						</NcButton>
				</div>

			<!-- Success State -->
			<div v-else-if="solrStats && solrStats.available" class="solr-section">
				<h3>🔍 SOLR Dashboard</h3>
				
				<!-- Action Buttons -->
				<div class="button-group">
					<NcButton type="secondary" @click="loadSolrStats" :disabled="loadingStats">
									<template #icon>
							<Refresh :size="20" />
									</template>
						Refresh
								</NcButton>
					
					<NcButton type="primary" @click="openWarmupModal">
						<template #icon>
							<Fire :size="20" />
						</template>
						Warmup Index
					</NcButton>
					
					<NcButton type="secondary" @click="openClearModal">
					<template #icon>
							<Delete :size="20" />
					</template>
					Clear Index
				</NcButton>
				
				<NcButton type="error" @click="openDeleteCollectionModal">
					<template #icon>
						<DatabaseRemove :size="20" />
					</template>
					Delete Collection
				</NcButton>
				
				<NcButton type="secondary" @click="openInspectModal">
					<template #icon>
						<FileSearchOutline :size="20" />
					</template>
					Inspect Index
				</NcButton>
				</div>

				<!-- Basic Stats -->
							<div class="stats-grid">
					<div class="stat-card">
						<h4>Connection Status</h4>
						<p :class="connectionStatusClass">{{ solrStats.overview?.connection_status || 'Unknown' }}</p>
						</div>

					<div class="stat-card">
						<h4>Total Documents</h4>
						<p>{{ formatNumber(solrStats.overview?.total_documents || 0) }}</p>
						</div>

					<div class="stat-card">
						<h4>Active Collection</h4>
						<p>{{ solrStats.collection || 'Unknown' }}</p>
					</div>

					<div class="stat-card">
						<h4>Tenant ID</h4>
						<p>{{ solrStats.tenant_id || 'Unknown' }}</p>
						</div>
					</div>
				</div>

			<!-- Default State (no data) -->
			<div v-else class="no-data-section">
				<p>No SOLR data available</p>
				<NcButton type="primary" @click="loadSolrStats">
						<template #icon>
							<Refresh :size="20" />
						</template>
					Load Stats
					</NcButton>
				</div>
		</NcSettingsSection>

		<!-- Warmup Modal -->
		<SolrWarmupModal 
			:show="showWarmupDialog"
			:object-stats="objectStats"
			:memory-prediction="memoryPrediction"
			:warming-up="warmingUp"
			:completed="warmupCompleted"
			:results="warmupResults"
			@close="closeWarmupModal"
			@start-warmup="handleStartWarmup"
		/>

		<!-- Clear Modal -->
		<ClearIndexModal 
			:show="showClearDialog"
			@close="showClearDialog = false"
			@confirm="handleClearIndex"
		/>

		<!-- Inspect Modal -->
		<InspectIndexModal 
			:show="showInspectDialog"
			@close="showInspectDialog = false"
		/>

		<!-- Delete Collection Modal -->
		<DeleteCollectionModal
			:show="showDeleteCollectionDialog"
			@close="closeDeleteCollectionModal"
			@deleted="handleCollectionDeleted"
		/>
	</div>
</template>

<script>
import NcSettingsSection from '@nextcloud/vue/dist/Components/NcSettingsSection.js'
import NcButton from '@nextcloud/vue/dist/Components/NcButton.js'
import NcLoadingIcon from '@nextcloud/vue/dist/Components/NcLoadingIcon.js'
import Refresh from 'vue-material-design-icons/Refresh.vue'
import Fire from 'vue-material-design-icons/Fire.vue'
import Delete from 'vue-material-design-icons/Delete.vue'
import DatabaseRemove from 'vue-material-design-icons/DatabaseRemove.vue'
import FileSearchOutline from 'vue-material-design-icons/FileSearchOutline.vue'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { SolrWarmupModal, ClearIndexModal } from '../../../modals/settings'
import InspectIndexModal from '../../../modals/settings/InspectIndexModal.vue'
import DeleteCollectionModal from '../../../modals/settings/DeleteCollectionModal.vue'

export default {
	name: 'SolrDashboard',
	
	components: {
		NcSettingsSection,
		NcButton,
		NcLoadingIcon,
		Refresh,
		Fire,
		Delete,
		DatabaseRemove,
		FileSearchOutline,
		SolrWarmupModal,
		ClearIndexModal,
		InspectIndexModal,
		DeleteCollectionModal,
	},

	data() {
		return {
			loadingStats: false,
			solrError: false,
			solrErrorMessage: '',
			showWarmupDialog: false,
			showClearDialog: false,
			showInspectDialog: false,
			showDeleteCollectionDialog: false,
			solrStats: null,
			objectStats: {
				loading: false,
				totalObjects: 0,
			},
			memoryPrediction: {
				prediction_safe: true,
				formatted: {
					total_predicted: 'Unknown',
					available: 'Unknown'
				}
			},
			warmingUp: false,
			warmupCompleted: false,
			warmupResults: null,
		}
	},

	computed: {
		connectionStatusClass() {
			console.log('[DEBUG] connectionStatusClass called with solrStats:', this.solrStats)
			if (!this.solrStats || !this.solrStats.available) {
					return 'status-error'
			}
			if (this.solrStats.overview?.connection_status === 'Connected') {
				return 'status-success'
			}
			return 'status-warning'
		},
	},

	async mounted() {
		console.log('[DEBUG] SolrDashboard mounted')
		await this.loadSolrStats()
		await this.loadObjectStats()
	},

	methods: {
		async loadSolrStats() {
			console.log('[DEBUG] loadSolrStats called')
			this.loadingStats = true
			this.solrError = false
			this.solrErrorMessage = ''

			try {
				const url = generateUrl('/apps/openregister/api/solr/dashboard/stats')
				console.log('[DEBUG] Making API call to:', url)
				
				const response = await axios.get(url)
				console.log('[DEBUG] API response:', response.data)

				if (response.data && response.data.available) {
					// Transform flat response to expected structure
					this.solrStats = {
						available: response.data.available,
						overview: {
							connection_status: 'Connected',
							total_documents: response.data.document_count || 0,
						},
						cores: {
							active_core: response.data.collection || 'Unknown',
							tenant_id: response.data.tenant_id || 'Unknown',
						},
					}
					console.log('[DEBUG] Transformed solrStats:', this.solrStats)
				} else {
					this.solrError = true
					this.solrErrorMessage = response.data?.error || 'SOLR not available'
					this.solrStats = null
				}
			} catch (error) {
				console.error('[DEBUG] API call failed:', error)
				this.solrError = true
				this.solrErrorMessage = error.message || 'Failed to load SOLR statistics'
				this.solrStats = null
			} finally {
				this.loadingStats = false
				console.log('[DEBUG] loadSolrStats completed. Final state:', {
					loadingStats: this.loadingStats,
					solrError: this.solrError,
					solrStats: this.solrStats
				})
			}
		},

		formatNumber(num) {
			if (typeof num !== 'number') return num
			return num.toLocaleString()
		},

		openWarmupModal() {
			console.log('[DEBUG] openWarmupModal called')
			this.showWarmupDialog = true
			console.log('[DEBUG] showWarmupDialog set to:', this.showWarmupDialog)
		},

		closeWarmupModal() {
			console.log('[DEBUG] closeWarmupModal called')
			this.showWarmupDialog = false
			// Reset warmup state when modal is closed
			this.warmingUp = false
			this.warmupCompleted = false
			this.warmupResults = null
		},

		openClearModal() {
			console.log('[DEBUG] openClearModal called')
			this.showClearDialog = true
			console.log('[DEBUG] showClearDialog set to:', this.showClearDialog)
		},

		openInspectModal() {
			console.log('[DEBUG] openInspectModal called')
			this.showInspectDialog = true
			console.log('[DEBUG] showInspectDialog set to:', this.showInspectDialog)
		},

		openDeleteCollectionModal() {
			console.log('[DEBUG] openDeleteCollectionModal called')
			this.showDeleteCollectionDialog = true
			console.log('[DEBUG] showDeleteCollectionDialog set to:', this.showDeleteCollectionDialog)
		},

		closeDeleteCollectionModal() {
			console.log('[DEBUG] closeDeleteCollectionModal called')
			this.showDeleteCollectionDialog = false
		},

		async handleCollectionDeleted(result) {
			console.log('[DEBUG] handleCollectionDeleted called with result:', result)
			
			// Close the modal
			this.closeDeleteCollectionModal()
			
			// Refresh SOLR stats to reflect the deletion
			await this.loadSolrStats()
			
			// Show success message (already shown in modal, but this confirms)
			console.log('[DEBUG] Collection deletion completed:', result)
		},

		async handleClearIndex() {
			console.log('[DEBUG] handleClearIndex called')
			try {
				const url = generateUrl('/apps/openregister/api/settings/solr/clear')
				console.log('[DEBUG] Making clear index API call to:', url)
				
				const response = await axios.post(url)
				console.log('[DEBUG] Clear index response:', response.data)
				
				// Close modal and refresh stats
				this.showClearDialog = false
				await this.loadSolrStats()
			} catch (error) {
				console.error('[DEBUG] Clear index failed:', error)
				// Keep modal open on error so user can see what happened
			}
		},

		async loadObjectStats() {
			console.log('[DEBUG] loadObjectStats called')
			this.objectStats.loading = true
			
			try {
				const url = generateUrl('/apps/openregister/api/settings/stats')
				console.log('[DEBUG] Making object stats API call to:', url)
				
				const response = await axios.get(url)
				console.log('[DEBUG] Object stats response:', response.data)
				
				if (response.data && response.data.totals && response.data.totals.totalObjects) {
					this.objectStats.totalObjects = response.data.totals.totalObjects
					console.log('[DEBUG] Set totalObjects to:', this.objectStats.totalObjects)
					
					// Load memory prediction after getting object count
					await this.loadMemoryPrediction(0) // Default to all objects
				} else {
					console.warn('[DEBUG] No totalObjects found in response:', response.data)
					this.objectStats.totalObjects = 0
				}
			} catch (error) {
				console.error('[DEBUG] Failed to load object stats:', error)
				this.objectStats.totalObjects = 0
			} finally {
				this.objectStats.loading = false
				console.log('[DEBUG] loadObjectStats completed. Final objectStats:', this.objectStats)
			}
		},

		async loadMemoryPrediction(maxObjects = 0) {
			try {
				const url = generateUrl('/apps/openregister/api/settings/solr/memory-prediction')
				const response = await axios.post(url, { maxObjects })
				
				if (response.data && response.data.success) {
					this.memoryPrediction = response.data.prediction
				}
			} catch (error) {
				console.warn('Failed to load memory prediction:', error)
				// Keep default prediction data
			}
		},

		async handleStartWarmup(config) {
			console.log('[DEBUG] handleStartWarmup called with config:', config)
			
			// Set loading state
			this.warmingUp = true
			this.warmupCompleted = false
			this.warmupResults = null
			
			try {
				const url = generateUrl('/apps/openregister/api/settings/solr/warmup')
				console.log('[DEBUG] Making warmup API call to:', url)
				
				// Convert config to the expected format
				const warmupParams = {
					maxObjects: config.maxObjects || 0,
					mode: config.mode || 'serial',
					batchSize: config.batchSize || 1000,
				}
				console.log('[DEBUG] Warmup params:', warmupParams)
				
				const response = await axios.post(url, warmupParams)
				console.log('[DEBUG] Warmup response:', response.data)
				
				// Set results state
				this.warmupCompleted = true
				this.warmupResults = response.data
				
				// Refresh stats after warmup completes
				await this.loadSolrStats()
			} catch (error) {
				console.error('[DEBUG] Warmup failed:', error)
				
				// Set error state
				this.warmupCompleted = true
				this.warmupResults = {
					success: false,
					message: error.response?.data?.error || error.message || 'Warmup failed',
					error: true
				}
			} finally {
				// Clear loading state
				this.warmingUp = false
			}
		},
	},
}
</script>

<style scoped>
.loading-section {
	text-align: center;
	padding: 2rem;
}

.error-section {
	text-align: center;
	padding: 2rem;
}

.error-message {
	color: var(--color-error);
	margin-bottom: 1rem;
}

.no-data-section {
	text-align: center;
	padding: 2rem;
}

.solr-section {
	padding: 1rem;
}

.button-group {
	display: flex;
	gap: 0.5rem;
	margin-bottom: 2rem;
	flex-wrap: wrap;
}

.stats-grid {
	display: grid;
	grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
	gap: 1rem;
}

.stat-card {
	background: var(--color-background-hover);
	padding: 1rem;
	border-radius: var(--border-radius-large);
	border: 1px solid var(--color-border);
}

.stat-card h4 {
	margin: 0 0 0.5rem 0;
	font-size: 0.9rem;
	color: var(--color-text-maxcontrast);
}

.stat-card p {
	margin: 0;
	font-size: 1.1rem;
	font-weight: bold;
}

.status-success {
	color: var(--color-success);
}

.status-warning {
	color: var(--color-warning);
}

.status-error {
	color: var(--color-error);
}
</style>

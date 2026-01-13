<template>
	<NcDialog
		v-if="show"
		:name="t('openregister', 'Collection Management')"
		:size="'large'"
		@closing="$emit('closing')">
		<div class="collection-management-modal">
			<!-- Description -->
			<div class="modal-description">
				<p>{{ t('openregister', 'Manage SOLR Collections (data stores) and assign them for objects and files.') }}</p>
			</div>

			<!-- Loading State -->
			<div v-if="loading" class="loading-state">
				<NcLoadingIcon :size="32" />
				<p>{{ t('openregister', 'Loading collections...') }}</p>
			</div>

			<!-- Error State -->
			<div v-else-if="error" class="error-state">
				<p class="error-message">
					❌ {{ errorMessage }}
				</p>
				<NcButton type="primary" @click="loadCollections">
					<template #icon>
						<Refresh :size="20" />
					</template>
					{{ t('openregister', 'Retry') }}
				</NcButton>
			</div>

			<!-- Collections Table -->
			<div v-else class="collections-content">
				<!-- Collection Assignments -->
				<div class="collection-assignments">
					<h3>{{ t('openregister', 'Active Collections') }}</h3>
					<div class="assignments-grid">
						<div class="assignment-card">
							<label>{{ t('openregister', 'Object Collection') }}</label>
							<NcSelect
								v-model="selectedObjectCollection"
								:options="collectionOptions"
								:placeholder="t('openregister', 'Select collection for objects')"
								:label-outside="true"
								@update:modelValue="updateAssignments">
								<template #icon>
									<Database :size="20" />
								</template>
							</NcSelect>
							<p class="assignment-hint">
								{{ t('openregister', 'Collection used to store and index object data') }}
							</p>
						</div>

						<div class="assignment-card">
							<label>{{ t('openregister', 'File Collection') }}</label>
							<NcSelect
								v-model="selectedFileCollection"
								:options="collectionOptions"
								:placeholder="t('openregister', 'Select collection for files')"
								:label-outside="true"
								@update:modelValue="updateAssignments">
								<template #icon>
									<FileDocument :size="20" />
								</template>
							</NcSelect>
							<p class="assignment-hint">
								{{ t('openregister', 'Collection used to store and index file metadata and content') }}
							</p>
						</div>
					</div>
				</div>

				<!-- Collections List -->
				<div class="collections-list">
					<div class="list-header">
						<h3>{{ t('openregister', 'All Collections') }} ({{ collections.length }})</h3>
						<div class="header-actions">
							<NcButton
								type="primary"
								:disabled="loadingConfigSets"
								@click="openCreateDialog">
								<template #icon>
									<NcLoadingIcon v-if="loadingConfigSets" :size="20" />
									<Plus v-else :size="20" />
								</template>
								{{ loadingConfigSets ? t('openregister', 'Loading...') : t('openregister', 'Create Collection') }}
							</NcButton>
							<NcButton type="secondary" @click="loadCollections">
								<template #icon>
									<Refresh :size="20" />
								</template>
								{{ t('openregister', 'Refresh') }}
							</NcButton>
						</div>
					</div>

					<table v-if="collections.length > 0" class="collections-table">
						<thead>
							<tr>
								<th>{{ t('openregister', 'Name') }}</th>
								<th>{{ t('openregister', 'ConfigSet') }}</th>
								<th>{{ t('openregister', 'Documents') }}</th>
								<th>{{ t('openregister', 'Shards') }}</th>
								<th>{{ t('openregister', 'Replicas') }}</th>
								<th>{{ t('openregister', 'Health') }}</th>
								<th>{{ t('openregister', 'Actions') }}</th>
							</tr>
						</thead>
						<tbody>
							<tr v-for="collection in collections" :key="collection.name" class="collection-row">
								<td class="collection-name">
									<div class="name-container" :title="collection.name">
										<strong class="name-text">{{ truncateName(collection.name, 25) }}</strong>
										<div class="collection-badges">
											<span v-if="collection.name === selectedObjectCollection" class="badge object-badge">
												{{ t('openregister', 'Objects') }}
											</span>
											<span v-if="collection.name === selectedFileCollection" class="badge file-badge">
												{{ t('openregister', 'Files') }}
											</span>
										</div>
									</div>
								</td>
								<td class="configset-cell" :title="collection.configName">
									{{ truncateName(collection.configName, 20) }}
								</td>
								<td class="number-cell">
									{{ formatNumber(collection.documentCount) }}
								</td>
								<td class="number-cell">
									{{ collection.shards }}
								</td>
								<td class="number-cell">
									{{ collection.replicas }}
								</td>
								<td class="health-cell">
									<Check v-if="collection.health === 'healthy'" :size="20" class="health-icon healthy" />
									<Close v-else :size="20" class="health-icon degraded" />
								</td>
								<td class="actions-cell">
									<NcActions>
										<NcActionButton @click="reindexCollection(collection)">
											<template #icon>
												<Refresh :size="20" />
											</template>
											{{ t('openregister', 'Reindex') }}
										</NcActionButton>
										<NcActionButton @click="clearCollection(collection)">
											<template #icon>
												<Delete :size="20" />
											</template>
											{{ t('openregister', 'Clear Index') }}
										</NcActionButton>
										<NcActionButton @click="openCopyDialog(collection)">
											<template #icon>
												<ContentCopy :size="20" />
											</template>
											{{ t('openregister', 'Copy') }}
										</NcActionButton>
										<NcActionButton @click="deleteCollection(collection)">
											<template #icon>
												<DatabaseRemove :size="20" />
											</template>
											{{ t('openregister', 'Delete Collection') }}
										</NcActionButton>
									</NcActions>
								</td>
							</tr>
						</tbody>
					</table>

					<div v-else class="no-collections">
						<p>{{ t('openregister', 'No collections found') }}</p>
					</div>
				</div>
			</div>

			<!-- Create Collection Dialog -->
			<NcDialog
				v-if="showCreateDialog"
				:name="t('openregister', 'Create New Collection')"
				:size="'normal'"
				@closing="closeCreateDialog">
				<div class="create-dialog">
					<p>{{ t('openregister', 'Create a new SOLR collection from an existing ConfigSet') }}</p>

					<div class="form-group">
						<label>{{ t('openregister', 'Collection Name') }}*</label>
						<input
							v-model="newCollectionData.name"
							type="text"
							:placeholder="t('openregister', 'Enter collection name')"
							class="collection-name-input">
					</div>

					<div class="form-group">
						<label>{{ t('openregister', 'ConfigSet') }}*</label>
						<NcSelect
							v-model="newCollectionData.configSet"
							:options="configSetOptions"
							:placeholder="t('openregister', 'Select ConfigSet')"
							:label-outside="true" />
					</div>

					<div class="form-group-row">
						<div class="form-group">
							<label>{{ t('openregister', 'Shards') }}</label>
							<input
								v-model.number="newCollectionData.shards"
								type="number"
								min="1"
								class="number-input">
						</div>

						<div class="form-group">
							<label>{{ t('openregister', 'Replicas') }}</label>
							<input
								v-model.number="newCollectionData.replicas"
								type="number"
								min="1"
								class="number-input">
						</div>

						<div class="form-group">
							<label>{{ t('openregister', 'Max Shards/Node') }}</label>
							<input
								v-model.number="newCollectionData.maxShardsPerNode"
								type="number"
								min="1"
								class="number-input">
						</div>
					</div>

					<div class="form-actions">
						<NcButton @click="closeCreateDialog">
							{{ t('openregister', 'Cancel') }}
						</NcButton>
						<NcButton
							type="primary"
							:disabled="!newCollectionData.name || !newCollectionData.configSet || creating"
							@click="createCollection">
							<template #icon>
								<NcLoadingIcon v-if="creating" :size="20" />
								<Plus v-else :size="20" />
							</template>
							{{ creating ? t('openregister', 'Creating...') : t('openregister', 'Create Collection') }}
						</NcButton>
					</div>
				</div>
			</NcDialog>

			<!-- Copy Collection Dialog -->
			<NcDialog
				v-if="showCopyDialog"
				:name="t('openregister', 'Copy Collection')"
				:size="'normal'"
				@closing="closeCopyDialog">
				<div class="copy-dialog">
					<p>{{ t('openregister', 'Create a copy of collection:') }} <strong>{{ collectionToCopy?.name }}</strong></p>

					<div class="form-group">
						<label>{{ t('openregister', 'New Collection Name') }}</label>
						<input
							v-model="newCollectionName"
							type="text"
							:placeholder="t('openregister', 'Enter new collection name')"
							class="collection-name-input">
					</div>

					<div class="form-actions">
						<NcButton @click="closeCopyDialog">
							{{ t('openregister', 'Cancel') }}
						</NcButton>
						<NcButton
							type="primary"
							:disabled="!newCollectionName || copying"
							@click="copyCollection">
							<template #icon>
								<NcLoadingIcon v-if="copying" :size="20" />
								<ContentCopy v-else :size="20" />
							</template>
							{{ copying ? t('openregister', 'Copying...') : t('openregister', 'Copy Collection') }}
						</NcButton>
					</div>
				</div>
			</NcDialog>

			<!-- Modal Actions -->
			<div class="modal-actions">
				<NcButton @click="$emit('closing')">
					{{ t('openregister', 'Close') }}
				</NcButton>
			</div>
		</div>
	</NcDialog>
</template>

<script>
import { NcDialog, NcButton, NcLoadingIcon, NcSelect, NcActions, NcActionButton } from '@nextcloud/vue'
import Refresh from 'vue-material-design-icons/Refresh.vue'
import Database from 'vue-material-design-icons/Database.vue'
import FileDocument from 'vue-material-design-icons/FileDocument.vue'
import ContentCopy from 'vue-material-design-icons/ContentCopy.vue'
import Plus from 'vue-material-design-icons/Plus.vue'
import Delete from 'vue-material-design-icons/Delete.vue'
import DatabaseRemove from 'vue-material-design-icons/DatabaseRemove.vue'
import Check from 'vue-material-design-icons/Check.vue'
import Close from 'vue-material-design-icons/Close.vue'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { showSuccess, showError } from '@nextcloud/dialogs'

export default {
	name: 'CollectionManagementModal',

	components: {
		NcDialog,
		NcButton,
		NcLoadingIcon,
		NcSelect,
		NcActions,
		NcActionButton,
		Refresh,
		Database,
		FileDocument,
		ContentCopy,
		Plus,
		Delete,
		DatabaseRemove,
		Check,
		Close,
	},

	props: {
		show: {
			type: Boolean,
			default: false,
		},
	},

	emits: ['closing'],

	data() {
		return {
			loading: false,
			error: false,
			errorMessage: '',
			collections: [],
			configSets: [],
			selectedObjectCollection: null,
			selectedFileCollection: null,
			showCreateDialog: false,
			showCopyDialog: false,
			collectionToCopy: null,
			newCollectionName: '',
			newCollectionData: {
				name: '',
				configSet: null,
				shards: 1,
				replicas: 1,
				maxShardsPerNode: 1,
			},
			creating: false,
			copying: false,
			loadingConfigSets: false,
		}
	},

	computed: {
		collectionOptions() {
			return this.collections.map(col => ({
				id: col.name,
				label: `${col.name} (${this.formatNumber(col.documentCount)} docs)`,
			}))
		},

		configSetOptions() {
			return this.configSets.map(cs => ({
				id: cs.name,
				label: cs.name,
			}))
		},
	},

	async mounted() {
		await this.loadCollections()
		await this.loadConfigSets()
		await this.loadCurrentAssignments()
		// Listen for ConfigSet updates from Create/Delete dialogs
		this.$root.$on('configset-updated', this.handleConfigSetUpdate)
	},

	beforeDestroy() {
		// Clean up event listener
		this.$root.$off('configset-updated', this.handleConfigSetUpdate)
	},

	methods: {
		async loadCollections() {
			this.loading = true
			this.error = false
			this.errorMessage = ''

			try {
				const url = generateUrl('/apps/openregister/api/solr/collections')
				const response = await axios.get(url)

				if (response.data.success) {
					this.collections = response.data.collections
					// TODO: Load current assignments from settings
				} else {
					this.error = true
					this.errorMessage = response.data.error || 'Failed to load collections'
				}
			} catch (error) {
				console.error('Failed to load collections:', error)
				this.error = true
				this.errorMessage = error.response?.data?.error || error.message || 'Failed to load collections'
			} finally {
				this.loading = false
			}
		},

		async loadConfigSets() {
			try {
				const url = generateUrl('/apps/openregister/api/solr/configsets')
				const response = await axios.get(url)

				if (response.data.success) {
					this.configSets = response.data.configSets
				}
			} catch (error) {
				console.error('Failed to load ConfigSets:', error)
			}
		},

		async loadCurrentAssignments() {
			try {
				// Load settings to get current collection assignments
				const url = generateUrl('/apps/openregister/api/settings')
				const response = await axios.get(url)

				if (response.data && response.data.solr) {
					const solrSettings = response.data.solr

					// Set the selected collections if they exist
					if (solrSettings.objectCollection) {
						// Find the collection in our list and set it
						const objectCol = this.collections.find(col => col.name === solrSettings.objectCollection)
						if (objectCol) {
							this.selectedObjectCollection = objectCol.name
						}
					}

					if (solrSettings.fileCollection) {
						// Find the collection in our list and set it
						const fileCol = this.collections.find(col => col.name === solrSettings.fileCollection)
						if (fileCol) {
							this.selectedFileCollection = fileCol.name
						}
					}

					console.info('📦 Loaded current assignments:', {
						objectCollection: this.selectedObjectCollection,
						fileCollection: this.selectedFileCollection,
					})
				}
			} catch (error) {
				console.error('Failed to load current assignments:', error)
			}
		},

		async updateAssignments() {
			try {
				// Extract the IDs from the objects (NcSelect returns {id, label})
				const objectCollectionId = this.selectedObjectCollection
					? (typeof this.selectedObjectCollection === 'object'
						? this.selectedObjectCollection.id
						: this.selectedObjectCollection)
					: null

				const fileCollectionId = this.selectedFileCollection
					? (typeof this.selectedFileCollection === 'object'
						? this.selectedFileCollection.id
						: this.selectedFileCollection)
					: null

				const url = generateUrl('/apps/openregister/api/solr/collections/assignments')
				const response = await axios.put(url, {
					objectCollection: objectCollectionId,
					fileCollection: fileCollectionId,
				})

				if (response.data.success) {
					showSuccess(this.t('openregister', 'Collection assignments updated successfully'))
					// Reload assignments to confirm they were saved
					await this.loadCurrentAssignments()
				} else {
					showError(response.data.error || 'Failed to update assignments')
				}
			} catch (error) {
				console.error('Failed to update assignments:', error)
				showError(error.response?.data?.error || error.message || 'Failed to update assignments')
			}
		},

		async openCreateDialog() {
			// Show loading state on button
			this.loadingConfigSets = true

			try {
				// Reload ConfigSets to get the latest list
				await this.loadConfigSets()

				this.newCollectionData = {
					name: '',
					configSet: null,
					shards: 1,
					replicas: 1,
					maxShardsPerNode: 1,
				}
				this.showCreateDialog = true
			} finally {
				// Always clear loading state
				this.loadingConfigSets = false
			}
		},

		handleConfigSetUpdate() {
			console.info('📦 ConfigSet updated, reloading ConfigSets list')
			this.loadConfigSets()
		},

		closeCreateDialog() {
			this.showCreateDialog = false
			this.newCollectionData = {
				name: '',
				configSet: null,
				shards: 1,
				replicas: 1,
				maxShardsPerNode: 1,
			}
		},

		async createCollection() {
			this.creating = true

			try {
				// Extract the ID from the configSet object (NcSelect returns {id, label})
				const configSetId = typeof this.newCollectionData.configSet === 'object'
					? this.newCollectionData.configSet.id
					: this.newCollectionData.configSet

				const url = generateUrl('/apps/openregister/api/solr/collections')
				const response = await axios.post(url, {
					collectionName: this.newCollectionData.name,
					configName: configSetId,
					numShards: this.newCollectionData.shards,
					replicationFactor: this.newCollectionData.replicas,
					maxShardsPerNode: this.newCollectionData.maxShardsPerNode,
				})

				if (response.data.success) {
					showSuccess(this.t('openregister', 'Collection created successfully'))
					this.closeCreateDialog()
					await this.loadCollections()
				} else {
					showError(response.data.error || 'Failed to create collection')
				}
			} catch (error) {
				console.error('Failed to create collection:', error)
				showError(error.response?.data?.error || error.message || 'Failed to create collection')
			} finally {
				this.creating = false
			}
		},

		openCopyDialog(collection) {
			this.collectionToCopy = collection
			this.newCollectionName = `${collection.name}_copy`
			this.showCopyDialog = true
		},

		closeCopyDialog() {
			this.showCopyDialog = false
			this.collectionToCopy = null
			this.newCollectionName = ''
		},

		async copyCollection() {
			this.copying = true

			try {
				const url = generateUrl('/apps/openregister/api/solr/collections/copy')
				const response = await axios.post(url, {
					sourceCollection: this.collectionToCopy.name,
					targetCollection: this.newCollectionName,
					copyData: false,
				})

				if (response.data.success) {
					showSuccess(this.t('openregister', 'Collection copied successfully'))
					this.closeCopyDialog()
					await this.loadCollections()
				} else {
					showError(response.data.error || 'Failed to copy collection')
				}
			} catch (error) {
				console.error('Failed to copy collection:', error)
				showError(error.response?.data?.error || error.message || 'Failed to copy collection')
			} finally {
				this.copying = false
			}
		},

		truncateName(name, maxLength = 35) {
			if (!name || name.length <= maxLength) return name
			return name.substring(0, maxLength) + '...'
		},

		formatNumber(num) {
			if (typeof num !== 'number') return num
			return num.toLocaleString()
		},

		async reindexCollection(collection) {
			if (!confirm(this.t('openregister', 'Are you sure you want to reindex collection "{name}"?\n\nThis will:\n• Rebuild the index with all objects\n• Take several minutes to complete\n• May impact search performance during reindexing', { name: collection.name }))) {
				return
			}

			try {
				const url = generateUrl('/apps/openregister/api/solr/collections/{name}/reindex', { name: collection.name })
				const response = await axios.post(url)

				if (response.data.success) {
					const stats = response.data.stats || {}
					showSuccess(this.t('openregister', 'Reindex completed! Processed {count} objects in {duration}s', {
						count: stats.processed_objects || 0,
						duration: stats.duration_seconds || 0,
					}))
					await this.loadCollections()
				} else {
					showError(response.data.message || this.t('openregister', 'Reindex failed'))
				}
			} catch (error) {
				console.error('Reindex error:', error)
				showError(error.response?.data?.message || this.t('openregister', 'Failed to reindex collection'))
			}
		},

		async clearCollection(collection) {
			if (!confirm(this.t('openregister', 'Are you sure you want to clear all data from collection "{name}"?\n\nThis will:\n• Delete all indexed documents\n• Keep the collection structure intact\n• This action cannot be undone', { name: collection.name }))) {
				return
			}

			try {
				const url = generateUrl('/apps/openregister/api/solr/collections/{name}/clear', { name: collection.name })
				const response = await axios.post(url)

				if (response.data.success) {
					showSuccess(this.t('openregister', 'Collection cleared successfully'))
					await this.loadCollections()
				} else {
					showError(response.data.message || this.t('openregister', 'Failed to clear collection'))
				}
			} catch (error) {
				console.error('Clear collection error:', error)
				showError(error.response?.data?.message || this.t('openregister', 'Failed to clear collection'))
			}
		},

		async deleteCollection(collection) {
			if (!confirm(this.t('openregister', 'Are you sure you want to DELETE collection "{name}"?\n\nThis will:\n• Permanently delete the collection and all its data\n• Remove all indexed documents\n• This action cannot be undone', { name: collection.name }))) {
				return
			}

			try {
				const url = generateUrl('/apps/openregister/api/solr/collections/{name}', { name: collection.name })
				const response = await axios.delete(url)

				if (response.data.success) {
					showSuccess(this.t('openregister', 'Collection deleted successfully'))
					await this.loadCollections()
					// If deleted collection was assigned, clear the assignment
					if (this.selectedObjectCollection === collection.name) {
						this.selectedObjectCollection = null
						await this.updateAssignments()
					}
					if (this.selectedFileCollection === collection.name) {
						this.selectedFileCollection = null
						await this.updateAssignments()
					}
				} else {
					showError(response.data.message || this.t('openregister', 'Failed to delete collection'))
				}
			} catch (error) {
				console.error('Delete collection error:', error)
				showError(error.response?.data?.error || this.t('openregister', 'Failed to delete collection'))
			}
		},
	},
}
</script>

<style scoped>
.collection-management-modal {
	padding: 20px;
	min-height: 400px;
}

.modal-description {
	margin-bottom: 20px;
	color: var(--color-text-maxcontrast);
}

.loading-state,
.error-state {
	display: flex;
	flex-direction: column;
	align-items: center;
	justify-content: center;
	padding: 60px 20px;
	gap: 20px;
}

.error-message {
	color: var(--color-error);
	margin: 0;
}

.collections-content {
	display: flex;
	flex-direction: column;
	gap: 30px;
}

.collection-assignments {
	background: var(--color-background-hover);
	border-radius: var(--border-radius-large);
	padding: 20px;
}

.collection-assignments h3 {
	margin: 0 0 15px 0;
	font-size: 16px;
}

.assignments-grid {
	display: grid;
	grid-template-columns: 1fr 1fr;
	gap: 20px;
}

.assignment-card {
	display: flex;
	flex-direction: column;
	gap: 8px;
}

.assignment-card label {
	font-weight: 600;
	font-size: 14px;
	color: var(--color-main-text);
}

.assignment-hint {
	margin: 0;
	font-size: 12px;
	color: var(--color-text-maxcontrast);
}

.collections-list {
	display: flex;
	flex-direction: column;
	gap: 15px;
}

.list-header {
	display: flex;
	justify-content: space-between;
	align-items: center;
}

.list-header h3 {
	margin: 0;
	font-size: 16px;
}

.collections-table {
	width: 100%;
	border-collapse: collapse;
	background: var(--color-main-background);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
}

.collections-table thead {
	background: var(--color-background-dark);
}

.collections-table th {
	padding: 12px;
	text-align: left;
	font-weight: 600;
	border-bottom: 2px solid var(--color-border);
}

.collections-table tbody tr:hover {
	background: var(--color-background-hover);
}

.collections-table td {
	padding: 12px;
	border-bottom: 1px solid var(--color-border);
}

.collection-name {
	max-width: 300px;
	min-width: 200px;
}

.name-container {
	display: flex;
	flex-direction: column;
	gap: 5px;
	cursor: help;
}

.name-text {
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.configset-cell {
	max-width: 200px;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
	cursor: help;
}

.collection-badges {
	display: flex;
	gap: 5px;
}

.badge {
	font-size: 10px;
	padding: 2px 6px;
	border-radius: var(--border-radius-small);
	font-weight: 600;
	text-transform: uppercase;
}

.object-badge {
	background: var(--color-primary-element-light);
	color: var(--color-primary-element-text);
}

.file-badge {
	background: var(--color-warning);
	color: var(--color-main-background);
}

.number-cell {
	text-align: right;
	font-variant-numeric: tabular-nums;
}

.health-cell {
	text-align: center;
}

.health-icon {
	display: inline-block;
	vertical-align: middle;
}

.health-icon.healthy {
	color: var(--color-success);
}

.health-icon.degraded {
	color: var(--color-error);
}

.actions-cell {
	text-align: right;
}

.no-collections {
	text-align: center;
	padding: 40px;
	color: var(--color-text-maxcontrast);
}

.copy-dialog {
	padding: 20px;
}

.form-group {
	margin: 20px 0;
}

.form-group label {
	display: block;
	margin-bottom: 8px;
	font-weight: 600;
}

.form-group-row {
	display: grid;
	grid-template-columns: 1fr 1fr 1fr;
	gap: 15px;
	margin-bottom: 20px;
}

.collection-name-input,
.number-input {
	width: 100%;
	padding: 10px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	font-size: 14px;
}

.number-input {
	font-variant-numeric: tabular-nums;
}

.header-actions {
	display: flex;
	gap: 10px;
}

.form-actions {
	display: flex;
	justify-content: flex-end;
	gap: 10px;
	margin-top: 20px;
}

.modal-actions {
	display: flex;
	justify-content: flex-end;
	gap: 10px;
	margin-top: 20px;
	padding-top: 20px;
	border-top: 1px solid var(--color-border);
}
</style>

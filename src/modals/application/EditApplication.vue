<script setup>
import { applicationStore, organisationStore, navigationStore } from '../../store/store.js'
</script>

<template>
	<NcDialog :name="applicationStore.applicationItem?.uuid ? 'Edit Application' : 'Create Application'"
		size="large"
		:can-close="true"
		@update:open="handleDialogClose">
		<NcNoteCard v-if="success" type="success">
			<p>Application successfully {{ applicationStore.applicationItem?.uuid ? 'updated' : 'created' }}</p>
		</NcNoteCard>
		<NcNoteCard v-if="error" type="error">
			<p>{{ error }}</p>
		</NcNoteCard>
		<div v-if="!success">
			<!-- Tabs -->
			<div class="tabContainer">
				<BTabs v-model="activeTab" content-class="mt-3" justified>
					<BTab title="Basic Information" active>
						<div class="form-editor">
							<NcTextField
								:disabled="loading"
								label="Name *"
								:value.sync="applicationItem.name"
								:error="!applicationItem.name.trim()"
								placeholder="Enter application name" />

							<NcTextArea
								:disabled="loading"
								label="Description"
								:value.sync="applicationItem.description"
								placeholder="Enter application description (optional)"
								:rows="4" />

							<NcSelect
								v-model="selectedOrganisation"
								:disabled="loading"
								:options="organisationOptions"
								input-label="Organisation"
								label="name"
								track-by="id"
								placeholder="Select organisation (optional)"
								@input="updateOrganisation">
								<template #option="{ name, description }">
									<div class="option-content">
										<span class="option-title">{{ name }}</span>
										<span v-if="description" class="option-description">{{ description }}</span>
									</div>
								</template>
							</NcSelect>

							<div class="groups-select-container">
								<label class="groups-label">Nextcloud Groups</label>
								<NcSelect
									v-model="selectedGroups"
									:disabled="loading || loadingGroups"
									:options="availableGroups"
									input-label="Select groups with access to this application"
									label="name"
									track-by="id"
									:multiple="true"
									placeholder="Select groups (optional)"
									@input="updateGroups">
									<template #option="{ name }">
										<div class="group-option">
											<span class="group-name">{{ name }}</span>
										</div>
									</template>
								</NcSelect>
								<p class="field-hint">
									Select which Nextcloud groups have access to this application
								</p>
							</div>
						</div>
					</BTab>

					<BTab title="Resource Allocation">
						<div class="form-editor">
							<NcNoteCard type="info">
								<p><strong>Resource Quotas</strong></p>
								<p>Set limits for storage, bandwidth, and API usage. Use 0 for unlimited resources.</p>
							</NcNoteCard>

							<NcTextField
								:disabled="loading"
								label="Storage Quota (MB)"
								type="number"
								placeholder="0 = unlimited"
								:value="storageQuotaMB"
								@update:value="updateStorageQuota" />

							<NcTextField
								:disabled="loading"
								label="Bandwidth Quota (MB/month)"
								type="number"
								placeholder="0 = unlimited"
								:value="bandwidthQuotaMB"
								@update:value="updateBandwidthQuota" />

							<NcTextField
								:disabled="loading"
								label="API Request Quota (requests/day)"
								type="number"
								placeholder="0 = unlimited"
								:value="applicationItem.requestQuota || 0"
								@update:value="updateRequestQuota" />
						</div>
					</BTab>

					<BTab title="Security">
						<div class="security-section">
							<NcNoteCard type="info">
								<p><strong>Group Access Control</strong></p>
								<p>The following Nextcloud groups have access to this application.</p>
							</NcNoteCard>

							<div v-if="loadingGroups" class="loading-groups">
								<NcLoadingIcon :size="20" />
								<span>Loading user groups...</span>
							</div>

							<div v-else-if="selectedGroups.length > 0" class="groups-list">
								<h3>Selected Groups</h3>
								<div class="group-items">
									<div v-for="group in selectedGroups" :key="group.id" class="group-item">
										<span class="group-badge">{{ group.name }}</span>
										<NcButton
											type="tertiary"
											:disabled="loading"
											@click="removeGroup(group)">
											<template #icon>
												<Close :size="16" />
											</template>
										</NcButton>
									</div>
								</div>
							</div>

							<div v-else class="no-groups">
								<p>No groups selected. All users will have access to this application.</p>
							</div>
						</div>
					</BTab>
				</BTabs>
			</div>
		</div>

		<template #actions>
			<NcButton @click="closeModal">
				<template #icon>
					<Cancel :size="20" />
				</template>
				{{ success ? 'Close' : 'Cancel' }}
			</NcButton>
			<NcButton v-if="!success"
				:disabled="loading || !applicationItem.name.trim()"
				type="primary"
				@click="saveApplication()">
				<template #icon>
					<NcLoadingIcon v-if="loading" :size="20" />
					<ContentSaveOutline v-if="!loading && applicationStore.applicationItem?.uuid" :size="20" />
					<Plus v-if="!loading && !applicationStore.applicationItem?.uuid" :size="20" />
				</template>
				{{ applicationStore.applicationItem?.uuid ? 'Save' : 'Create' }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import {
	NcButton,
	NcDialog,
	NcTextField,
	NcTextArea,
	NcSelect,
	NcLoadingIcon,
	NcNoteCard,
} from '@nextcloud/vue'
import { BTabs, BTab } from 'bootstrap-vue'

import ContentSaveOutline from 'vue-material-design-icons/ContentSaveOutline.vue'
import Cancel from 'vue-material-design-icons/Cancel.vue'
import Plus from 'vue-material-design-icons/Plus.vue'
import Close from 'vue-material-design-icons/Close.vue'

export default {
	name: 'EditApplication',
	components: {
		NcDialog,
		NcTextField,
		NcTextArea,
		NcSelect,
		NcButton,
		NcLoadingIcon,
		NcNoteCard,
		BTabs,
		BTab,
		// Icons
		ContentSaveOutline,
		Cancel,
		Plus,
		Close,
	},
	data() {
		return {
			activeTab: 0,
			applicationItem: {
				name: '',
				description: '',
				organisation: null,
				storageQuota: 0,
				bandwidthQuota: 0,
				requestQuota: 0,
				groups: [],
			},
			selectedOrganisation: null,
			selectedGroups: [],
			availableGroups: [],
			loadingGroups: false,
			success: false,
			loading: false,
			error: false,
			closeModalTimeout: null,
		}
	},
	computed: {
		organisationOptions() {
			return organisationStore.organisationList.map(org => ({
				id: org.id,
				name: org.name,
				description: org.description || '',
			}))
		},
		storageQuotaMB() {
			if (!this.applicationItem.storageQuota) return 0
			return Math.round(this.applicationItem.storageQuota / (1024 * 1024))
		},
		bandwidthQuotaMB() {
			if (!this.applicationItem.bandwidthQuota) return 0
			return Math.round(this.applicationItem.bandwidthQuota / (1024 * 1024))
		},
	},
	async mounted() {
		await this.fetchOrganisations()
		await this.loadNextcloudGroups()
		// Initialize after groups are loaded so we can map IDs to objects
		this.initializeApplicationItem()
	},
	methods: {
		/**
		 * Fetch available organisations from the store
		 * 
		 * @return {Promise<void>}
		 */
		async fetchOrganisations() {
			await organisationStore.refreshOrganisationList()
		},

		/**
		 * Load available Nextcloud groups
		 * 
		 * @return {Promise<void>}
		 */
		async loadNextcloudGroups() {
			this.loadingGroups = true
			try {
				// Fetch groups from Nextcloud OCS API (using v1 for compatibility)
				// Use fetch() with direct path since OCS API is at root level, not under /index.php/
				const response = await fetch('/ocs/v1.php/cloud/groups?format=json', {
					headers: {
						'OCS-APIRequest': 'true',
					},
				})

				if (response.ok) {
					const data = await response.json()

					// v1 API returns groups as a simple array of group IDs
					if (data.ocs?.data?.groups) {
						this.availableGroups = data.ocs.data.groups.map(groupId => ({
							id: groupId,
							name: groupId,
							userCount: 0, // v1 API doesn't provide user count in list
						}))
					}
				} else {
					console.warn('Failed to load user groups:', response.statusText)
					this.error = 'Failed to load Nextcloud groups'
				}
			} catch (error) {
				console.error('Error loading Nextcloud groups:', error)
				this.error = 'Failed to load Nextcloud groups'
			} finally {
				this.loadingGroups = false
			}
		},

		/**
		 * Initialize application item from store
		 * 
		 * @return {void}
		 */
		initializeApplicationItem() {
			if (applicationStore.applicationItem?.uuid) {
				this.applicationItem = {
					...this.applicationItem, // Keep default structure
					...applicationStore.applicationItem,
				}

				// Load existing organisation selection
				if (this.applicationItem.organisation) {
					const org = organisationStore.organisationList.find(o => o.id === this.applicationItem.organisation)
					if (org) {
						this.selectedOrganisation = {
							id: org.id,
							name: org.name,
							description: org.description || '',
						}
					}
				}

				// Load existing groups selection
				// Groups are stored as an array of IDs, we need to map them to objects for the select component
				if (Array.isArray(this.applicationItem.groups) && this.applicationItem.groups.length > 0) {
					this.selectedGroups = this.applicationItem.groups
						.map(groupId => {
							// Find the group in availableGroups
							const group = this.availableGroups.find(g => g.id === groupId)
							if (group) {
								return group
							}
							// If not found in availableGroups, create a temporary object
							// This ensures we show the group even if the groups API failed
							return {
								id: groupId,
								name: groupId,
								userCount: 0,
							}
						})
						.filter(g => g !== null)
				}
			}
		},

		/**
		 * Update organisation selection
		 * 
		 * @param {object} value - Selected organisation
		 * @return {void}
		 */
		updateOrganisation(value) {
			this.applicationItem.organisation = value?.id || null
		},

		/**
		 * Update groups selection
		 * 
		 * @param {Array} groups - Selected groups
		 * @return {void}
		 */
		updateGroups(groups) {
			this.selectedGroups = groups || []
			// Store only the group IDs, not the full objects
			this.applicationItem.groups = this.selectedGroups.map(group => group.id)
		},

		/**
		 * Remove a group from selection
		 * 
		 * @param {object} groupToRemove - Group to remove
		 * @return {void}
		 */
		removeGroup(groupToRemove) {
			this.selectedGroups = this.selectedGroups.filter(g => g.id !== groupToRemove.id)
			// Store only the group IDs, not the full objects
			this.applicationItem.groups = this.selectedGroups.map(group => group.id)
		},

		/**
		 * Update storage quota (converts MB to bytes)
		 * 
		 * @param {number} value - Quota in MB
		 * @return {void}
		 */
		updateStorageQuota(value) {
			// Convert MB to bytes (0 = unlimited)
			const mbValue = value ? parseInt(value) : 0
			this.applicationItem.storageQuota = mbValue * 1024 * 1024
		},

		/**
		 * Update bandwidth quota (converts MB to bytes)
		 * 
		 * @param {number} value - Quota in MB
		 * @return {void}
		 */
		updateBandwidthQuota(value) {
			// Convert MB to bytes (0 = unlimited)
			const mbValue = value ? parseInt(value) : 0
			this.applicationItem.bandwidthQuota = mbValue * 1024 * 1024
		},

		/**
		 * Update request quota
		 * 
		 * @param {number} value - Quota value
		 * @return {void}
		 */
		updateRequestQuota(value) {
			// 0 = unlimited
			this.applicationItem.requestQuota = value ? parseInt(value) : 0
		},

		/**
		 * Close the modal and reset state
		 * 
		 * @return {void}
		 */
		closeModal() {
			this.success = false
			this.error = null
			this.selectedOrganisation = null
			this.selectedGroups = []
			this.activeTab = 0
			navigationStore.setModal(false)
			navigationStore.setDialog(false)
			clearTimeout(this.closeModalTimeout)
		},

		/**
		 * Save the application
		 * 
		 * @return {Promise<void>}
		 */
		async saveApplication() {
			this.loading = true
			this.error = null

			// Validate required fields
			if (!this.applicationItem.name.trim()) {
				this.error = 'Application name is required'
				this.loading = false
				return
			}

			try {
				const { response } = await applicationStore.saveApplication({
					...this.applicationItem,
				})

				this.success = response.ok
				this.error = false

				if (response.ok) {
					this.closeModalTimeout = setTimeout(this.closeModal, 2000)
				}

			} catch (error) {
				this.success = false
				this.error = error.message || 'An error occurred while saving the application'
			} finally {
				this.loading = false
			}
		},

		/**
		 * Handle dialog close event
		 * 
		 * @return {void}
		 */
		handleDialogClose() {
			this.closeModal()
		},
	},
}
</script>

<style scoped>
/* EditApplication-specific styles */
.tabContainer {
	margin-top: 20px;
}

.form-editor {
	display: flex;
	flex-direction: column;
	gap: 16px;
	padding: 16px 0;
}

.option-content {
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.option-title {
	font-weight: 500;
	color: var(--color-main-text);
}

.option-description {
	font-size: 12px;
	color: var(--color-text-lighter);
}

.groups-select-container {
	display: flex;
	flex-direction: column;
	gap: 8px;
}

.groups-label {
	font-weight: 500;
	color: var(--color-main-text);
	font-size: 14px;
}

.field-hint {
	font-size: 12px;
	color: var(--color-text-lighter);
	margin: 0;
}

.group-option {
	display: flex;
	align-items: center;
	gap: 8px;
}

.group-name {
	font-weight: 500;
}

.security-section {
	display: flex;
	flex-direction: column;
	gap: 16px;
	padding: 16px 0;
}

.loading-groups {
	display: flex;
	align-items: center;
	gap: 8px;
	color: var(--color-text-lighter);
	padding: 16px;
}

.groups-list {
	display: flex;
	flex-direction: column;
	gap: 12px;
}

.groups-list h3 {
	margin: 0;
	font-size: 16px;
	font-weight: 500;
	color: var(--color-main-text);
}

.group-items {
	display: flex;
	flex-direction: column;
	gap: 8px;
}

.group-item {
	display: flex;
	align-items: center;
	justify-content: space-between;
	padding: 8px 12px;
	background-color: var(--color-background-hover);
	border-radius: var(--border-radius);
}

.group-badge {
	display: inline-flex;
	align-items: center;
	padding: 4px 12px;
	background-color: var(--color-primary-element-light);
	color: var(--color-primary-element-text);
	border-radius: 16px;
	font-size: 13px;
	font-weight: 500;
}

.no-groups {
	padding: 16px;
	text-align: center;
	color: var(--color-text-lighter);
	font-style: italic;
}

.no-groups p {
	margin: 0;
}
</style>

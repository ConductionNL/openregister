<script setup>
import { organisationStore, navigationStore } from '../../store/store.js'
</script>

<template>
	<NcDialog :name="organisationStore.organisationItem?.uuid && !createAnother ? 'Edit Organisation' : 'Create Organisation'"
		size="large"
		:can-close="true"
		@update:open="handleDialogClose">
		<NcNoteCard v-if="success" type="success">
			<p>Organisation successfully {{ organisationStore.organisationItem?.uuid && !createAnother ? 'updated' : 'created' }}</p>
		</NcNoteCard>
		<NcNoteCard v-if="error" type="error">
			<p>{{ error }}</p>
		</NcNoteCard>
		<div v-if="createAnother || !success">
			<!-- Tabs -->
			<div class="tabContainer">
				<BTabs v-model="activeTab" content-class="mt-3" justified>
					<BTab title="Basic Information" active>
						<div class="form-editor">
							<NcTextField
								:disabled="loading"
								label="Name *"
								:value.sync="organisationItem.name"
								:error="!organisationItem.name.trim()"
								placeholder="Enter organisation name" />

							<NcTextField
								:disabled="loading"
								label="Slug"
								:value.sync="organisationItem.slug"
								placeholder="Optional URL-friendly identifier" />

							<NcTextArea
								:disabled="loading"
								label="Description"
								:value.sync="organisationItem.description"
								placeholder="Enter organisation description (optional)"
								:rows="4" />

							<div class="groups-select-container">
								<label class="groups-label">Nextcloud Groups</label>
								<NcSelect
									v-model="selectedGroups"
									:disabled="loading || loadingGroups"
									:options="availableGroups"
									input-label="Select groups with access to this organisation"
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
									Select which Nextcloud groups have access to this organisation
								</p>
							</div>
						</div>
					</BTab>

					<BTab title="Settings">
						<div class="form-editor">
							<NcCheckboxRadioSwitch
								v-if="organisationItem.uuid && canEditDefaultFlag"
								:disabled="loading"
								:checked.sync="organisationItem.isDefault">
								Default Organisation
							</NcCheckboxRadioSwitch>

							<NcNoteCard v-if="organisationItem.isDefault" type="info">
								<p>New users without specific organisation membership will be automatically added to this organisation</p>
							</NcNoteCard>

							<NcCheckboxRadioSwitch
								:disabled="loading"
								:checked.sync="organisationItem.active">
								Active
							</NcCheckboxRadioSwitch>

							<NcNoteCard v-if="!organisationItem.active" type="warning">
								<p>Inactive organisations cannot be used</p>
							</NcNoteCard>
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
								:value="organisationItem.requestQuota || 0"
								@update:value="updateRequestQuota" />
						</div>
					</BTab>

					<BTab title="Security">
						<div class="security-section">
							<NcNoteCard type="info">
								<p><strong>Group Access Control</strong></p>
								<p>The following Nextcloud groups have access to this organisation.</p>
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
								<p>No groups selected. All users will have access to this organisation.</p>
							</div>
						</div>
					</BTab>
				</BTabs>
			</div>
		</div>

		<template #actions>
			<NcCheckboxRadioSwitch
				v-if="!organisationStore.organisationItem?.uuid"
				class="create-another-checkbox"
				:disabled="loading"
				:checked.sync="createAnother">
				Create another
			</NcCheckboxRadioSwitch>
			<NcButton @click="closeModal">
				<template #icon>
					<Cancel :size="20" />
				</template>
				{{ success ? 'Close' : 'Cancel' }}
			</NcButton>
			<NcButton v-if="createAnother || !success"
				:disabled="loading || !organisationItem.name.trim()"
				type="primary"
				@click="saveOrganisation()">
				<template #icon>
					<NcLoadingIcon v-if="loading" :size="20" />
					<ContentSaveOutline v-if="!loading && organisationStore.organisationItem?.uuid" :size="20" />
					<Plus v-if="!loading && !organisationStore.organisationItem?.uuid" :size="20" />
				</template>
				{{ organisationStore.organisationItem?.uuid && !createAnother ? 'Save' : 'Create' }}
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
	NcCheckboxRadioSwitch,
} from '@nextcloud/vue'
import { BTabs, BTab } from 'bootstrap-vue'

import ContentSaveOutline from 'vue-material-design-icons/ContentSaveOutline.vue'
import Cancel from 'vue-material-design-icons/Cancel.vue'
import Plus from 'vue-material-design-icons/Plus.vue'
import Close from 'vue-material-design-icons/Close.vue'

export default {
	name: 'EditOrganisation',
	components: {
		NcDialog,
		NcTextField,
		NcTextArea,
		NcSelect,
		NcButton,
		NcLoadingIcon,
		NcNoteCard,
		NcCheckboxRadioSwitch,
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
			organisationItem: {
				name: '',
				slug: '',
				description: '',
				isDefault: false,
				active: true,
				storageQuota: 0,
				bandwidthQuota: 0,
				requestQuota: 0,
				roles: [],
			},
			selectedGroups: [],
			availableGroups: [],
			loadingGroups: false,
			createAnother: false,
			success: false,
			loading: false,
			error: false,
			closeModalTimeout: null,
		}
	},
	computed: {
		canEditDefaultFlag() {
			// Only system admin or already default organisation can edit default flag
			// This is a simplified check - in reality would need proper permission checks
			return this.organisationItem.isDefault || this.getCurrentUser() === 'admin'
		},
		storageQuotaMB() {
			if (!this.organisationItem.storageQuota) return 0
			return Math.round(this.organisationItem.storageQuota / (1024 * 1024))
		},
		bandwidthQuotaMB() {
			if (!this.organisationItem.bandwidthQuota) return 0
			return Math.round(this.organisationItem.bandwidthQuota / (1024 * 1024))
		},
	},
	async mounted() {
		await this.loadNextcloudGroups()
		// Initialize after groups are loaded so we can map IDs to objects
		this.initializeOrganisationItem()
	},
	methods: {
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
		 * Initialize organisation item from store
		 * 
		 * @return {void}
		 */
		initializeOrganisationItem() {
			if (organisationStore.organisationItem?.uuid) {
				this.organisationItem = {
					...this.organisationItem, // Keep default structure
					...organisationStore.organisationItem,
					active: organisationStore.organisationItem.active ?? true,
				}

				// Load existing roles/groups selection
				// Roles can be stored as either an array of IDs or array of objects (for backwards compatibility)
				if (Array.isArray(this.organisationItem.roles) && this.organisationItem.roles.length > 0) {
					this.selectedGroups = this.organisationItem.roles
						.map(role => {
							// Handle both formats: string IDs or objects
							const roleId = typeof role === 'string' ? role : (role.id || role.name)
							
							// Find the group in availableGroups
							const group = this.availableGroups.find(g => g.id === roleId)
							if (group) {
								return group
							}
							// If not found in availableGroups, create a temporary object
							// This ensures we show the group even if the groups API failed
							return {
								id: roleId,
								name: roleId,
								userCount: 0,
							}
						})
						.filter(g => g !== null)
				}
			}
		},

		/**
		 * Get current user
		 * 
		 * @return {string}
		 */
		getCurrentUser() {
			// Implementation would depend on how you get current user
			return 'current-user' // Placeholder
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
			this.organisationItem.roles = this.selectedGroups.map(group => group.id)
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
			this.organisationItem.roles = this.selectedGroups.map(group => group.id)
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
			this.organisationItem.storageQuota = mbValue * 1024 * 1024
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
			this.organisationItem.bandwidthQuota = mbValue * 1024 * 1024
		},

		/**
		 * Update request quota
		 * 
		 * @param {number} value - Quota value
		 * @return {void}
		 */
		updateRequestQuota(value) {
			// 0 = unlimited
			this.organisationItem.requestQuota = value ? parseInt(value) : 0
		},

		/**
		 * Close the modal and reset state
		 * 
		 * @return {void}
		 */
		closeModal() {
			this.success = false
			this.error = null
			this.createAnother = false
			this.selectedGroups = []
			this.activeTab = 0
			navigationStore.setModal(false)
			navigationStore.setDialog(false)
			clearTimeout(this.closeModalTimeout)
		},

		/**
		 * Save the organisation
		 * 
		 * @return {Promise<void>}
		 */
		async saveOrganisation() {
			this.loading = true
			this.error = null

			// Validate required fields
			if (!this.organisationItem.name.trim()) {
				this.error = 'Organisation name is required'
				this.loading = false
				return
			}

			try {
				const { response } = await organisationStore.saveOrganisation({
					...this.organisationItem,
				})

				if (this.createAnother) {
					// Clear the form after successful creation
					setTimeout(() => {
						this.organisationItem = {
							name: '',
							slug: '',
							description: '',
							isDefault: false,
							active: true,
							storageQuota: null,
							bandwidthQuota: null,
							requestQuota: null,
							roles: [],
						}
						this.selectedGroups = []
						this.activeTab = 0
					}, 500)

					this.success = response.ok
					this.error = false

					// Clear success message after 2s
					setTimeout(() => {
						this.success = null
					}, 2000)
				} else {
					this.success = response.ok
					this.error = false

					if (response.ok) {
						this.closeModalTimeout = setTimeout(this.closeModal, 2000)
					}
				}

			} catch (error) {
				this.success = false
				this.error = error.message || 'An error occurred while saving the organisation'
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
/* EditOrganisation-specific styles */
.tabContainer {
	margin-top: 20px;
}

.form-editor {
	display: flex;
	flex-direction: column;
	gap: 16px;
	padding: 16px 0;
}

.create-another-checkbox {
	margin-right: auto;
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

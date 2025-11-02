<script setup>
import { organisationStore, navigationStore } from '../../store/store.js'
</script>

<template>
	<NcDialog :name="organisationStore.organisationItem?.uuid && !createAnother ? 'Edit Organisation' : 'Create Organisation'"
		size="large"
		:can-close="true"
		@update:open="handleDialogOpen">
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
					<BTab active>
						<template #title>
							<Cog :size="16" />
							<span>Settings</span>
						</template>
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

					<BTab>
						<template #title>
							<Database :size="16" />
							<span>Resources</span>
						</template>
						<div class="form-editor">
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

					<BTab :disabled="!organisationItem.uuid">
						<template #title>
							<AccountMultiple :size="16" />
							<span>Users</span>
						</template>
						<div class="users-section">
							<div class="users-header">
								<NcButton
									v-if="organisationItem.uuid"
									type="primary"
									:disabled="loading"
									@click="showAddUserDialog = true">
									<template #icon>
										<AccountPlus :size="20" />
									</template>
									Add User
								</NcButton>
							</div>

							<div v-if="loadingUsers" class="loading-users">
								<NcLoadingIcon :size="20" />
								<span>Loading users...</span>
							</div>

							<div v-else-if="organisationUsers.length > 0" class="users-list">
								<h3>Members ({{ organisationUsers.length }})</h3>
								<div class="user-items">
									<div v-for="userId in organisationUsers" :key="userId" class="user-item">
										<div class="user-info">
											<AccountCircle :size="20" class="user-icon" />
											<span class="user-id">{{ userId }}</span>
											<span v-if="userId === organisationItem.owner" class="owner-badge">Owner</span>
										</div>
										<NcButton
											v-if="userId !== organisationItem.owner"
											type="tertiary"
											:disabled="loading || removingUser === userId"
											@click="removeUser(userId)">
											<template #icon>
												<NcLoadingIcon v-if="removingUser === userId" :size="16" />
												<AccountMinus v-else :size="16" />
											</template>
											Remove
										</NcButton>
									</div>
								</div>
							</div>

							<div v-else class="no-users">
								<p>No users in this organisation.</p>
							</div>

							<NcNoteCard v-if="!organisationItem.uuid" type="warning">
								<p>Save the organisation first to manage users.</p>
							</NcNoteCard>
						</div>
					</BTab>

					<BTab>
						<template #title>
							<Shield :size="16" />
							<span>Security</span>
						</template>
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
		<RemoveUserDialog
			:show="showRemoveUserDialog"
			:user-id="userToRemove"
			:removing="removingUser !== null"
			@cancel="cancelRemoveUser"
			@confirm="confirmRemoveUser" />
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
import { showSuccess, showError } from '@nextcloud/dialogs'

import ContentSaveOutline from 'vue-material-design-icons/ContentSaveOutline.vue'
import Cancel from 'vue-material-design-icons/Cancel.vue'
import Plus from 'vue-material-design-icons/Plus.vue'
import Close from 'vue-material-design-icons/Close.vue'
import AccountCircle from 'vue-material-design-icons/AccountCircle.vue'
import AccountMinus from 'vue-material-design-icons/AccountMinus.vue'
import AccountPlus from 'vue-material-design-icons/AccountPlus.vue'
import Cog from 'vue-material-design-icons/Cog.vue'
import Database from 'vue-material-design-icons/Database.vue'
import AccountMultiple from 'vue-material-design-icons/AccountMultiple.vue'
import Shield from 'vue-material-design-icons/Shield.vue'

import RemoveUserDialog from './RemoveUserDialog.vue'

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
		RemoveUserDialog,
		// Icons
		ContentSaveOutline,
		Cancel,
		Plus,
		Close,
		AccountCircle,
		AccountMinus,
		AccountPlus,
		Cog,
		Database,
		AccountMultiple,
		Shield,
	},
	data() {
		return {
			activeTab: 0,
			organisationItem: {
				name: '',
				slug: '',
				description: '',
				active: true,
				storageQuota: 0,
				bandwidthQuota: 0,
				requestQuota: 0,
				groups: [],
			},
			selectedGroups: [],
			availableGroups: [],
			loadingGroups: false,
			organisationUsers: [],
			loadingUsers: false,
			removingUser: null,
			showRemoveUserDialog: false,
			showAddUserDialog: false,
			userToRemove: null,
			createAnother: false,
			success: false,
			loading: false,
			error: false,
			closeModalTimeout: null,
		}
	},
	computed: {
		storageQuotaMB() {
			if (!this.organisationItem.storageQuota) return 0
			return Math.round(this.organisationItem.storageQuota / (1024 * 1024))
		},
		bandwidthQuotaMB() {
			if (!this.organisationItem.bandwidthQuota) return 0
			return Math.round(this.organisationItem.bandwidthQuota / (1024 * 1024))
		},
	},
	watch: {
		// Watch for changes in the store's organisationItem (e.g., when clicking edit on different organisations)
		'organisationStore.organisationItem': {
			handler(newVal, oldVal) {
				// Only reinitialize if the UUID changed (different organisation) or went from null to something
				if (newVal && (!oldVal || newVal.uuid !== oldVal?.uuid)) {
					this.initializeOrganisationItem()
					// Reload users for the new organisation
					if (newVal.uuid) {
						this.loadOrganisationUsers()
					}
				}
			},
			deep: true,
		},
	},
	async mounted() {
		await this.loadNextcloudGroups()
		// Initialize after groups are loaded so we can map IDs to objects
		this.initializeOrganisationItem()
		// Load users if editing existing organisation
		if (this.organisationItem.uuid) {
			await this.loadOrganisationUsers()
		}
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

				// Load existing groups selection
				// Groups can be stored as either an array of IDs or array of objects (for backwards compatibility)
				if (Array.isArray(this.organisationItem.groups) && this.organisationItem.groups.length > 0) {
					this.selectedGroups = this.organisationItem.groups
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

				// Load users array
				if (Array.isArray(this.organisationItem.users)) {
					this.organisationUsers = [...this.organisationItem.users]
				}
			}
		},

		/**
		 * Load organisation users
		 * 
		 * @return {Promise<void>}
		 */
		async loadOrganisationUsers() {
			if (!this.organisationItem.uuid) return
			
			this.loadingUsers = true
			try {
				const response = await fetch(`/index.php/apps/openregister/api/organisations/${this.organisationItem.uuid}`)
				if (response.ok) {
					const data = await response.json()
					if (data.organisation?.users) {
						this.organisationUsers = data.organisation.users
					}
				} else {
					console.warn('Failed to load organisation users:', response.statusText)
				}
			} catch (error) {
				console.error('Error loading organisation users:', error)
			} finally {
				this.loadingUsers = false
			}
		},

		/**
		 * Show confirmation dialog before removing a user
		 * 
		 * @param {string} userId - User ID to remove
		 * @return {void}
		 */
		removeUser(userId) {
			if (!this.organisationItem.uuid || !userId) return

			this.userToRemove = userId
			this.showRemoveUserDialog = true
		},

		/**
		 * Cancel user removal and close dialog
		 * 
		 * @return {void}
		 */
		cancelRemoveUser() {
			this.showRemoveUserDialog = false
			this.userToRemove = null
		},

		/**
		 * Confirm and execute user removal
		 * 
		 * @return {Promise<void>}
		 */
		async confirmRemoveUser() {
			if (!this.organisationItem.uuid || !this.userToRemove) return

			this.removingUser = this.userToRemove
			this.error = null

			try {
				const response = await fetch(
					`/index.php/apps/openregister/api/organisations/${this.organisationItem.uuid}/leave`,
					{
						method: 'POST',
						headers: {
							'Content-Type': 'application/json',
						},
						body: JSON.stringify({ userId: this.userToRemove }),
					},
				)

				if (response.ok) {
					// Remove user from local list
					this.organisationUsers = this.organisationUsers.filter(u => u !== this.userToRemove)
					// Also update the organisation item
					if (this.organisationItem.users) {
						this.organisationItem.users = this.organisationItem.users.filter(u => u !== this.userToRemove)
					}
					// Refresh organisation store
					await organisationStore.refreshOrganisations()
					
					// Close dialog
					this.showRemoveUserDialog = false
					this.userToRemove = null
					
					showSuccess(this.$t('openregister', 'User removed successfully'))
				} else {
					const errorData = await response.json()
					this.error = errorData.error || 'Failed to remove user from organisation'
					showError(this.error)
				}
			} catch (error) {
				console.error('Error removing user:', error)
				this.error = 'Failed to remove user from organisation'
				showError(this.error)
			} finally {
				this.removingUser = null
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
			this.organisationItem.groups = this.selectedGroups.map(group => group.id)
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
			this.organisationItem.groups = this.selectedGroups.map(group => group.id)
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
						active: true,
							storageQuota: null,
							bandwidthQuota: null,
							requestQuota: null,
							groups: [],
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
		 * Handle dialog open/close event
		 * 
		 * @param {boolean} isOpen - Whether the dialog is open
		 * @return {void}
		 */
		handleDialogOpen(isOpen) {
			// Only close the modal if the dialog is being closed (isOpen = false)
			if (!isOpen) {
				this.closeModal()
			}
		},
	},
}
</script>

<style scoped>
/* EditOrganisation-specific styles */
.tabContainer {
	margin-top: 20px;
}

/* Tab title styling with icons */
.tabContainer :deep(.nav-link) {
	display: flex;
	align-items: center;
	gap: 8px;
	justify-content: center;
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

/* Users section styles */
.users-section {
	display: flex;
	flex-direction: column;
	gap: 16px;
	padding: 16px 0;
}

.users-header {
	display: flex;
	justify-content: flex-end;
	margin-bottom: 16px;
}

.loading-users {
	display: flex;
	align-items: center;
	gap: 8px;
	color: var(--color-text-lighter);
	padding: 16px;
}

.users-list {
	display: flex;
	flex-direction: column;
	gap: 12px;
}

.users-list h3 {
	margin: 0;
	font-size: 16px;
	font-weight: 500;
	color: var(--color-main-text);
}

.user-items {
	display: flex;
	flex-direction: column;
	gap: 8px;
}

.user-item {
	display: flex;
	align-items: center;
	justify-content: space-between;
	padding: 8px 12px;
	background-color: var(--color-background-hover);
	border-radius: var(--border-radius);
}

.user-info {
	display: flex;
	align-items: center;
	gap: 8px;
}

.user-icon {
	color: var(--color-text-lighter);
}

.user-id {
	font-weight: 500;
	color: var(--color-main-text);
}

.owner-badge {
	display: inline-flex;
	align-items: center;
	padding: 2px 8px;
	background-color: var(--color-warning);
	color: var(--color-main-background);
	border-radius: 12px;
	font-size: 11px;
	font-weight: 600;
	text-transform: uppercase;
}

.no-users {
	padding: 16px;
	text-align: center;
	color: var(--color-text-lighter);
	font-style: italic;
}

.no-users p {
	margin: 0;
}
</style>

<script setup>
import { translate as t, translatePlural as n } from '@nextcloud/l10n'
import { organisationStore, navigationStore } from '../../store/store.js'
</script>

<template>
	<Fragment>
		<CnTabbedFormDialog
			ref="dialog"
			:tabs="dialogTabs"
			:item="organisationStore.item?.uuid ? organisationStore.item : null"
			entity-name="Organisation"
			:show-create-another="true"
			:disable-save="!organisationItem.name.trim()"
			@confirm="saveOrganisation"
			@close="closeModal"
			@reset="resetForm">
			<!-- Settings Tab -->
			<template #tab-settings="{ loading: dialogLoading }">
				<NcTextField
					:disabled="dialogLoading"
					label="Name *"
					:value.sync="organisationItem.name"
					:error="!organisationItem.name.trim()"
					placeholder="Enter organisation name" />

				<NcTextField
					:disabled="dialogLoading"
					label="Slug"
					:value.sync="organisationItem.slug"
					placeholder="Optional URL-friendly identifier" />

				<NcTextArea
					:disabled="dialogLoading"
					label="Description"
					:value.sync="organisationItem.description"
					placeholder="Enter organisation description (optional)"
					:rows="4" />

				<div class="groups-select-container">
					<label class="groups-label">Nextcloud Groups</label>
					<NcSelect
						v-model="selectedGroups"
						:disabled="dialogLoading || loadingGroups"
						:options="availableGroups"
						label="name"
						track-by="id"
						:multiple="true"
						:label-outside="true"
						:filterable="false"
						placeholder="Search groups..."
						@search-change="searchGroups"
						@input="updateGroups">
						<template #option="{ name }">
							<div class="group-option">
								<span class="group-name">{{ name }}</span>
							</div>
						</template>
						<template #no-options>
							<span v-if="loadingGroups">Loading groups...</span>
							<span v-else>No groups found. Try a different search.</span>
						</template>
					</NcSelect>
					<p class="field-hint">
						Only members of selected groups can access this organisation
					</p>
				</div>

				<NcCheckboxRadioSwitch
					:disabled="dialogLoading"
					:checked.sync="organisationItem.active">
					Active
				</NcCheckboxRadioSwitch>

				<NcNoteCard v-if="!organisationItem.active" type="warning">
					<p>Inactive organisations cannot be used</p>
				</NcNoteCard>
			</template>

			<!-- Quota Tab -->
			<template #tab-quota="{ loading: dialogLoading }">
				<NcTextField
					:disabled="dialogLoading"
					label="Storage Quota (MB)"
					type="number"
					placeholder="0 = unlimited"
					:value="storageQuotaMB"
					@update:value="updateStorageQuota" />

				<NcTextField
					:disabled="dialogLoading"
					label="Bandwidth Quota (MB/month)"
					type="number"
					placeholder="0 = unlimited"
					:value="bandwidthQuotaMB"
					@update:value="updateBandwidthQuota" />

				<NcTextField
					:disabled="dialogLoading"
					label="API Request Quota (requests/day)"
					type="number"
					placeholder="0 = unlimited"
					:value="organisationItem.quota?.requests || 0"
					@update:value="updateRequestQuota" />

				<NcTextField
					:disabled="dialogLoading"
					label="User Quota"
					type="number"
					placeholder="0 = unlimited"
					:value="organisationItem.quota?.users || 0"
					@update:value="updateUserQuota" />

				<NcTextField
					:disabled="dialogLoading"
					label="Group Quota"
					type="number"
					placeholder="0 = unlimited"
					:value="organisationItem.quota?.groups || 0"
					@update:value="updateGroupQuota" />
			</template>

			<!-- Users Tab -->
			<template #tab-users="{ loading: dialogLoading }">
				<div class="users-section">
					<div class="users-header">
						<NcButton
							v-if="organisationItem.uuid"
							type="primary"
							:disabled="dialogLoading"
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
									:disabled="dialogLoading || removingUser === userId"
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
			</template>

			<!-- Security Tab -->
			<template #tab-security>
				<div v-if="loadingGroups" class="loading-groups">
					<NcLoadingIcon :size="20" />
					<span>Loading user groups...</span>
				</div>

				<BTabs v-else content-class="mt-3" pills>
					<!-- Registers -->
					<BTab title="Registers">
						<RbacTable
							entity-type="register"
							:authorization="organisationItem.authorization || {}"
							:available-groups="availableGroups"
							:organisation-groups="organisationItem.groups || []"
							@update="updateEntityPermission" />
					</BTab>

					<!-- Schemas -->
					<BTab title="Schemas">
						<RbacTable
							entity-type="schema"
							:authorization="organisationItem.authorization || {}"
							:available-groups="availableGroups"
							:organisation-groups="organisationItem.groups || []"
							@update="updateEntityPermission" />
					</BTab>

					<!-- Objects -->
					<BTab title="Objects">
						<RbacTable
							entity-type="object"
							:authorization="organisationItem.authorization || {}"
							:available-groups="availableGroups"
							:organisation-groups="organisationItem.groups || []"
							@update="updateEntityPermission" />
					</BTab>

					<!-- Views -->
					<BTab title="Views">
						<RbacTable
							entity-type="view"
							:authorization="organisationItem.authorization || {}"
							:available-groups="availableGroups"
							:organisation-groups="organisationItem.groups || []"
							@update="updateEntityPermission" />
					</BTab>

					<!-- Agents -->
					<BTab title="Agents">
						<RbacTable
							entity-type="agent"
							:authorization="organisationItem.authorization || {}"
							:available-groups="availableGroups"
							:organisation-groups="organisationItem.groups || []"
							@update="updateEntityPermission" />
					</BTab>

					<!-- Configurations -->
					<BTab title="Configurations">
						<RbacTable
							entity-type="configuration"
							:authorization="organisationItem.authorization || {}"
							:available-groups="availableGroups"
							:organisation-groups="organisationItem.groups || []"
							@update="updateEntityPermission" />
					</BTab>

					<!-- Applications -->
					<BTab title="Applications">
						<RbacTable
							entity-type="application"
							:authorization="organisationItem.authorization || {}"
							:available-groups="availableGroups"
							:organisation-groups="organisationItem.groups || []"
							@update="updateEntityPermission" />
					</BTab>

					<!-- Special Rights -->
					<BTab title="Special Rights">
						<div class="special-rights-container">
							<p class="rbac-description">
								Grant additional permissions beyond standard CRUD operations
							</p>

							<table class="rbac-table special-rights-table">
								<thead>
									<tr>
										<th>Right</th>
										<th>Description</th>
										<th>Groups</th>
									</tr>
								</thead>
								<tbody>
									<tr>
										<td class="right-name">
											<span class="right-badge">object_publish</span>
										</td>
										<td class="right-description">
											Publish objects to make them publicly available
										</td>
										<td class="right-groups">
											<NcSelect
												v-model="selectedSpecialRights.object_publish"
												:options="filteredAvailableGroups"
												label="name"
												track-by="id"
												:multiple="true"
												placeholder="Select groups..."
												@input="updateSpecialRight('object_publish', $event)" />
										</td>
									</tr>
									<tr>
										<td class="right-name">
											<span class="right-badge">agent_use</span>
										</td>
										<td class="right-description">
											Use AI agents for processing and analysis
										</td>
										<td class="right-groups">
											<NcSelect
												v-model="selectedSpecialRights.agent_use"
												:options="filteredAvailableGroups"
												label="name"
												track-by="id"
												:multiple="true"
												placeholder="Select groups..."
												@input="updateSpecialRight('agent_use', $event)" />
										</td>
									</tr>
									<tr>
										<td class="right-name">
											<span class="right-badge">dashboard_view</span>
										</td>
										<td class="right-description">
											Access organisation dashboard and analytics
										</td>
										<td class="right-groups">
											<NcSelect
												v-model="selectedSpecialRights.dashboard_view"
												:options="filteredAvailableGroups"
												label="name"
												track-by="id"
												:multiple="true"
												placeholder="Select groups..."
												@input="updateSpecialRight('dashboard_view', $event)" />
										</td>
									</tr>
									<tr>
										<td class="right-name">
											<span class="right-badge">llm_use</span>
										</td>
										<td class="right-description">
											Use Large Language Model features
										</td>
										<td class="right-groups">
											<NcSelect
												v-model="selectedSpecialRights.llm_use"
												:options="filteredAvailableGroups"
												label="name"
												track-by="id"
												:multiple="true"
												placeholder="Select groups..."
												@input="updateSpecialRight('llm_use', $event)" />
										</td>
									</tr>
								</tbody>
							</table>
						</div>
					</BTab>
				</BTabs>
			</template>
		</CnTabbedFormDialog>

		<RemoveUserDialog
			:show="showRemoveUserDialog"
			:user-id="userToRemove"
			:removing="removingUser !== null"
			@cancel="cancelRemoveUser"
			@confirm="confirmRemoveUser" />
	</Fragment>
</template>

<script>
import {
	NcButton,
	NcTextField,
	NcTextArea,
	NcSelect,
	NcLoadingIcon,
	NcNoteCard,
	NcCheckboxRadioSwitch,
} from '@nextcloud/vue'
import { CnTabbedFormDialog } from '@conduction/nextcloud-vue'
import { BTabs, BTab } from 'bootstrap-vue'
import { showSuccess, showError } from '@nextcloud/dialogs'

import AccountCircle from 'vue-material-design-icons/AccountCircle.vue'
import AccountMinus from 'vue-material-design-icons/AccountMinus.vue'
import AccountPlus from 'vue-material-design-icons/AccountPlus.vue'
import Cog from 'vue-material-design-icons/Cog.vue'
import Database from 'vue-material-design-icons/Database.vue'
import AccountMultiple from 'vue-material-design-icons/AccountMultiple.vue'
import Shield from 'vue-material-design-icons/Shield.vue'

import RemoveUserDialog from './RemoveUserDialog.vue'
import RbacTable from '../../components/RbacTable.vue'

export default {
	name: 'EditOrganisation',
	components: {
		CnTabbedFormDialog,
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
		RbacTable,
		// Icons
		AccountCircle,
		AccountMinus,
		AccountPlus,
	},
	data() {
		return {
			organisationItem: {
				name: '',
				slug: '',
				description: '',
				active: true,
				quota: {
					storage: 0,
					bandwidth: 0,
					requests: 0,
					users: 0,
					groups: 0,
				},
				groups: [],
			},
			selectedGroups: [],
			availableGroups: [],
			loadingGroups: false,
			groupSearchDebounce: null,
			selectedSpecialRights: {
				object_publish: [],
				agent_use: [],
				dashboard_view: [],
				llm_use: [],
			},
			organisationUsers: [],
			loadingUsers: false,
			removingUser: null,
			showRemoveUserDialog: false,
			showAddUserDialog: false,
			userToRemove: null,
		}
	},
	computed: {
		/**
		 * Tab definitions for CnTabbedFormDialog
		 *
		 * @return {Array} Tab configuration
		 */
		dialogTabs() {
			return [
				{ id: 'settings', title: 'Settings', icon: Cog },
				{ id: 'quota', title: 'Quota', icon: Database },
				{ id: 'users', title: 'Users', icon: AccountMultiple, disabled: !this.organisationItem.uuid },
				{ id: 'security', title: 'Security', icon: Shield },
			]
		},
		storageQuotaMB() {
			if (!this.organisationItem.quota?.storage) return 0
			return Math.round(this.organisationItem.quota.storage / (1024 * 1024))
		},
		bandwidthQuotaMB() {
			if (!this.organisationItem.quota?.bandwidth) return 0
			return Math.round(this.organisationItem.quota.bandwidth / (1024 * 1024))
		},
		/**
		 * Filter available groups to only show those assigned to the organisation
		 *
		 * @return {Array} Filtered array of groups
		 */
		filteredAvailableGroups() {
			// If no groups assigned yet, show all available groups
			if (!this.organisationItem.groups || this.organisationItem.groups.length === 0) {
				return this.availableGroups
			}

			// Only show groups that are in the organisation's groups list
			return this.availableGroups.filter(group =>
				this.organisationItem.groups.includes(group.id),
			)
		},
	},
	watch: {
		// Watch for changes in the store's organisationItem (e.g., when clicking edit on different organisations)
		'organisationStore.item': {
			handler(newVal, oldVal) {
				// Only reinitialize if the UUID changed (different organisation) or went from null to something
				if (newVal && (!oldVal || newVal.uuid !== oldVal?.uuid)) {
					this.initializeOrganisationItem()
				}
			},
			deep: true,
		},
	},
	mounted() {
		// Use cached Nextcloud groups from store (preloaded on index page)
		// If not available, they'll be loaded asynchronously
		this.loadNextcloudGroupsFromStore()
		// Initialize with cached groups - users are already included in the organisation object from the store
		this.initializeOrganisationItem()
		// Initialize special rights from authorization
		this.initializeSpecialRights()
	},
	methods: {
		/**
		 * Load available Nextcloud groups from store (or fetch if not cached)
		 * Groups are preloaded on the index page for better performance
		 *
		 * @return {void}
		 */
		loadNextcloudGroupsFromStore() {
			// If groups are already cached in store, use them immediately
			if (organisationStore.nextcloudGroups && organisationStore.nextcloudGroups.length > 0) {
				this.availableGroups = organisationStore.nextcloudGroups
				this.loadingGroups = false
				console.info('Using cached Nextcloud groups from store:', this.availableGroups.length)
			} else {
				// Groups not cached yet - load them (fallback for direct navigation)
				console.info('Groups not cached, loading from API...')
				this.loadingGroups = true
				organisationStore.loadNextcloudGroups().then(() => {
					this.availableGroups = organisationStore.nextcloudGroups
					this.loadingGroups = false
					// Re-initialize to map groups now that they're loaded
					this.initializeOrganisationItem()
				}).catch(error => {
					console.error('Error loading Nextcloud groups:', error)
					this.loadingGroups = false
				})
			}
		},

		/**
		 * Search for Nextcloud groups with debouncing
		 *
		 * @param {string} searchQuery - The search query entered by user
		 * @return {void}
		 */
		searchGroups(searchQuery) {
			// Clear existing debounce timer
			if (this.groupSearchDebounce) {
				clearTimeout(this.groupSearchDebounce)
			}

			// If search is empty, load all cached groups
			if (!searchQuery || searchQuery.trim() === '') {
				this.loadNextcloudGroupsFromStore()
				return
			}

			// Debounce the search by 300ms
			this.groupSearchDebounce = setTimeout(async () => {
				this.loadingGroups = true
				try {
					// Query Nextcloud OCS API with search parameter
					const response = await fetch(`/ocs/v1.php/cloud/groups?format=json&search=${encodeURIComponent(searchQuery)}`, {
						headers: {
							'OCS-APIRequest': 'true',
						},
					})

					if (response.ok) {
						const data = await response.json()
						if (data.ocs?.data?.groups) {
							// Transform group IDs into objects
							const searchResults = data.ocs.data.groups.map(groupId => ({
								id: groupId,
								name: groupId,
								userCount: 0,
							}))

							// Merge with already selected groups to ensure they remain visible
							const selectedGroupIds = this.selectedGroups.map(g => g.id)
							const mergedGroups = [
								...this.selectedGroups,
								...searchResults.filter(g => !selectedGroupIds.includes(g.id)),
							]

							this.availableGroups = mergedGroups
						}
					} else {
						console.warn('Failed to search groups:', response.statusText)
					}
				} catch (error) {
					console.error('Error searching Nextcloud groups:', error)
				} finally {
					this.loadingGroups = false
				}
			}, 300)
		},

		/**
		 * Initialize organisation item from store
		 *
		 * @return {void}
		 */
		initializeOrganisationItem() {
			if (organisationStore.item?.uuid) {
				this.organisationItem = {
					...this.organisationItem, // Keep default structure
					...organisationStore.item,
					active: organisationStore.item.active ?? true,
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

				// Initialize special rights from authorization
				this.initializeSpecialRights()
			}
		},

		/**
		 * Reset form data for "create another" mode
		 *
		 * @return {void}
		 */
		resetForm() {
			this.organisationItem = {
				name: '',
				slug: '',
				description: '',
				active: true,
				quota: {
					storage: 0,
					bandwidth: 0,
					requests: 0,
					users: 0,
					groups: 0,
				},
				groups: [],
			}
			this.selectedGroups = []
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
					const errorMsg = errorData.error || 'Failed to remove user from organisation'
					showError(errorMsg)
				}
			} catch (error) {
				console.error('Error removing user:', error)
				showError('Failed to remove user from organisation')
			} finally {
				this.removingUser = null
			}
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
		 * Update storage quota (converts MB to bytes)
		 *
		 * @param {number} value - Quota in MB
		 * @return {void}
		 */
		updateStorageQuota(value) {
			// Convert MB to bytes (0 = unlimited)
			const mbValue = value ? parseInt(value) : 0
			if (!this.organisationItem.quota) {
				this.organisationItem.quota = { storage: 0, bandwidth: 0, requests: 0, users: 0, groups: 0 }
			}
			this.organisationItem.quota.storage = mbValue * 1024 * 1024
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
			if (!this.organisationItem.quota) {
				this.organisationItem.quota = { storage: 0, bandwidth: 0, requests: 0, users: 0, groups: 0 }
			}
			this.organisationItem.quota.bandwidth = mbValue * 1024 * 1024
		},

		/**
		 * Update request quota
		 *
		 * @param {number} value - Quota value
		 * @return {void}
		 */
		updateRequestQuota(value) {
			// 0 = unlimited
			if (!this.organisationItem.quota) {
				this.organisationItem.quota = { storage: 0, bandwidth: 0, requests: 0, users: 0, groups: 0 }
			}
			this.organisationItem.quota.requests = value ? parseInt(value) : 0
		},

		/**
		 * Update user quota
		 *
		 * @param {number} value - Quota value
		 * @return {void}
		 */
		updateUserQuota(value) {
			// 0 = unlimited
			if (!this.organisationItem.quota) {
				this.organisationItem.quota = { storage: 0, bandwidth: 0, requests: 0, users: 0, groups: 0 }
			}
			this.organisationItem.quota.users = value ? parseInt(value) : 0
		},

		/**
		 * Update group quota
		 *
		 * @param {number} value - Quota value
		 * @return {void}
		 */
		updateGroupQuota(value) {
			// 0 = unlimited
			if (!this.organisationItem.quota) {
				this.organisationItem.quota = { storage: 0, bandwidth: 0, requests: 0, users: 0, groups: 0 }
			}
			this.organisationItem.quota.groups = value ? parseInt(value) : 0
		},

		/**
		 * Close the modal and reset state
		 *
		 * @return {void}
		 */
		closeModal() {
			this.selectedGroups = []
			navigationStore.setModal(false)
			navigationStore.setDialog(false)
		},

		/**
		 * Save the organisation
		 *
		 * @return {Promise<void>}
		 */
		async saveOrganisation() {
			// Validate required fields
			if (!this.organisationItem.name.trim()) {
				this.$refs.dialog.setResult({ error: 'Organisation name is required' })
				return
			}

			try {
				const { response } = await organisationStore.save({
					...this.organisationItem,
				})

				if (response.ok) {
					// Explicitly refresh the organisation list to ensure UI is updated
					await organisationStore.refreshList()

					// Also refresh active organisation in case it was just created
					if (!this.organisationItem.uuid) {
						await organisationStore.getActiveOrganisation()
					}

					this.$refs.dialog.setResult({ success: true })
				}

			} catch (error) {
				console.error('Error saving organisation:', error)
				this.$refs.dialog.setResult({
					error: error.message || 'An error occurred while saving the organisation',
				})
			}
		},

		/**
		 * Update entity permission
		 *
		 * @param {object} payload - The permission update payload
		 * @param {string} payload.entityType - The entity type (register, schema, object, view, agent)
		 * @param {string} payload.groupId - The group ID
		 * @param {string} payload.action - The action (create, read, update, delete)
		 * @param {boolean} payload.hasPermission - Whether to grant or revoke permission
		 * @return {void}
		 */
		updateEntityPermission({ entityType, groupId, action, hasPermission }) {
			// Initialize authorization object if it doesn't exist
			if (!this.organisationItem.authorization) {
				this.$set(this.organisationItem, 'authorization', {})
			}

			// Initialize entity type if it doesn't exist
			if (!this.organisationItem.authorization[entityType]) {
				this.$set(this.organisationItem.authorization, entityType, {})
			}

			// Initialize action array if it doesn't exist
			if (!this.organisationItem.authorization[entityType][action]) {
				this.$set(this.organisationItem.authorization[entityType], action, [])
			}

			const currentPermissions = this.organisationItem.authorization[entityType][action]
			const groupIndex = currentPermissions.indexOf(groupId)

			if (hasPermission && groupIndex === -1) {
				// Add permission
				currentPermissions.push(groupId)
			} else if (!hasPermission && groupIndex !== -1) {
				// Remove permission
				currentPermissions.splice(groupIndex, 1)
			}
		},

		/**
		 * Update special right
		 *
		 * @param {string} right - The special right (object_publish, agent_use, dashboard_view, llm_use)
		 * @param {Array} groups - Array of group objects with {id, name}
		 * @return {void}
		 */
		updateSpecialRight(right, groups) {
			// Initialize authorization if it doesn't exist
			if (!this.organisationItem.authorization) {
				this.$set(this.organisationItem, 'authorization', {})
			}

			// Convert group objects to array of group IDs
			const groupIds = groups.map(g => g.id)

			// Update the authorization
			this.$set(this.organisationItem.authorization, right, groupIds)

			// Update the selected special rights for UI binding
			this.selectedSpecialRights[right] = groups
		},

		/**
		 * Initialize special rights from organization authorization
		 *
		 * @return {void}
		 */
		initializeSpecialRights() {
			const auth = this.organisationItem.authorization || {}
			const specialRightKeys = ['object_publish', 'agent_use', 'dashboard_view', 'llm_use']

			specialRightKeys.forEach(right => {
				if (auth[right] && Array.isArray(auth[right])) {
					// Map group IDs to group objects
					this.selectedSpecialRights[right] = auth[right]
						.map(groupId => {
							const group = this.availableGroups.find(g => g.id === groupId)
							return group || { id: groupId, name: groupId }
						})
						.filter(g => g !== null)
				} else {
					this.selectedSpecialRights[right] = []
				}
			})
		},
	},
}
</script>

<style scoped>
/* Organisation-specific styles (tab shell handled by CnTabbedFormDialog) */

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
	font-size: 0.875rem;
	color: var(--color-text-maxcontrast);
	margin: 0;
}

.selectField,
.checkboxField {
	display: flex;
	flex-direction: column;
	gap: 0.5rem;
}

.selectField label {
	font-weight: 500;
	color: var(--color-text-maxcontrast);
}

/* Dropdown option styles */
.option-content {
	display: flex;
	flex-direction: column;
	gap: 0.25rem;
}

.option-title {
	font-weight: 500;
}

.option-description {
	font-size: 0.875rem;
	color: var(--color-text-maxcontrast);
	max-width: 100%;
	white-space: normal;
	word-break: break-word;
}

.option-meta {
	font-size: 0.75rem;
	color: var(--color-text-maxcontrast);
	font-style: italic;
}

.group-option {
	display: flex;
	align-items: center;
	gap: 8px;
}

.group-name {
	font-weight: 500;
}

.loading-groups {
	display: flex;
	align-items: center;
	gap: 8px;
	color: var(--color-text-lighter);
	padding: 16px;
}

/* Users section styles */
.users-section {
	display: flex;
	flex-direction: column;
	gap: 16px;
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

/* RBAC Security Tab Styling */
.rbac-description {
	font-size: 14px;
	color: var(--color-text-lighter);
	margin: 0 0 16px 0;
}

.special-rights-container {
	display: flex;
	flex-direction: column;
	gap: 12px;
	padding: 8px 0;
}

.special-rights-table {
	width: 100%;
	border-collapse: collapse;
	border: 1px solid var(--color-border-dark);
	border-radius: 8px;
	overflow: hidden;
	box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
}

.special-rights-table thead tr {
	background: var(--color-background-dark);
}

.special-rights-table th {
	color: var(--color-text-dark);
	font-weight: 600;
	padding: 12px 16px;
	text-align: left;
	border-bottom: 2px solid var(--color-border-dark);
}

.special-rights-table td {
	padding: 12px 16px;
	border-bottom: 1px solid var(--color-border);
	vertical-align: middle;
}

.special-rights-table tbody tr:hover {
	background: var(--color-background-hover);
}

.right-name {
	width: 20%;
}

.right-badge {
	display: inline-block;
	padding: 4px 12px;
	border-radius: 12px;
	font-size: 12px;
	font-weight: 600;
	background: var(--color-primary-element);
	color: var(--color-primary-text);
}

.right-description {
	width: 40%;
	font-size: 13px;
	color: var(--color-text-lighter);
}

.right-groups {
	width: 40%;
}
</style>

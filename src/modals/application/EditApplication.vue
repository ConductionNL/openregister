<script setup>
import { translate as t } from '@nextcloud/l10n'
import { applicationStore, organisationStore, navigationStore } from '../../store/store.js'
</script>

<template>
	<CnTabbedFormDialog
		ref="dialog"
		:tabs="dialogTabs"
		:item="applicationStore.applicationItem?.uuid ? applicationStore.applicationItem : null"
		entity-name="Application"
		:show-create-another="true"
		:disable-save="!applicationItem.name.trim()"
		@confirm="saveApplication"
		@close="closeModal"
		@reset="resetForm">
		<!-- Settings Tab -->
		<template #tab-settings="{ loading: dialogLoading }">
			<NcTextField
				:disabled="dialogLoading"
				label="Name *"
				:value.sync="applicationItem.name"
				:error="!applicationItem.name.trim()"
				placeholder="Enter application name" />

			<NcTextArea
				:disabled="dialogLoading"
				label="Description"
				:value.sync="applicationItem.description"
				placeholder="Enter application description (optional)"
				:rows="4" />

			<!-- Organisation is automatically set to active organisation by backend -->

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
					Only members of selected groups can access this application
				</p>
			</div>
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
				:value="applicationItem.quota?.requests || 0"
				@update:value="updateRequestQuota" />

			<NcTextField
				:disabled="dialogLoading"
				label="User Quota"
				type="number"
				placeholder="0 = unlimited (not applicable for applications)"
				:value="applicationItem.quota?.users || 0"
				@update:value="updateUserQuota" />

			<NcTextField
				:disabled="dialogLoading"
				label="Group Quota"
				type="number"
				placeholder="0 = unlimited"
				:value="applicationItem.quota?.groups || 0"
				@update:value="updateGroupQuota" />
		</template>

		<!-- Security Tab -->
		<template #tab-security>
			<p class="rbac-description">
				Configure CRUD permissions for this application.
				Empty permissions = open access for all application groups.
				The 'admin' group always has full access.
			</p>

			<RbacTable
				entity-type="application"
				:authorization="applicationItem.authorization"
				:available-groups="availableGroups"
				:organisation-groups="applicationItem.groups || []"
				@update="updateApplicationPermission" />
		</template>
	</CnTabbedFormDialog>
</template>

<script>
import {
	NcTextField,
	NcTextArea,
	NcSelect,
} from '@nextcloud/vue'
import { CnTabbedFormDialog } from '@conduction/nextcloud-vue'

import Cog from 'vue-material-design-icons/Cog.vue'
import Database from 'vue-material-design-icons/Database.vue'
import Shield from 'vue-material-design-icons/Shield.vue'

import RbacTable from '../../components/RbacTable.vue'

export default {
	name: 'EditApplication',
	components: {
		CnTabbedFormDialog,
		NcTextField,
		NcTextArea,
		NcSelect,
		RbacTable,
	},
	data() {
		return {
			applicationItem: {
				name: '',
				description: '',
				// Organisation will be set automatically by backend based on active organisation
				quota: {
					storage: 0,
					bandwidth: 0,
					requests: 0,
					users: 0,
					groups: 0,
				},
				groups: [],
				authorization: {
					create: [],
					read: [],
					update: [],
					delete: [],
				},
			},
			selectedGroups: [],
			availableGroups: [],
			loadingGroups: false,
			groupSearchDebounce: null,
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
				{ id: 'security', title: 'Security', icon: Shield },
			]
		},
		storageQuotaMB() {
			if (!this.applicationItem.quota?.storage) return 0
			return Math.round(this.applicationItem.quota.storage / (1024 * 1024))
		},
		bandwidthQuotaMB() {
			if (!this.applicationItem.quota?.bandwidth) return 0
			return Math.round(this.applicationItem.quota.bandwidth / (1024 * 1024))
		},
		filteredAvailableGroups() {
			// Filter available groups to only show groups assigned to the application
			if (!this.applicationItem.groups || this.applicationItem.groups.length === 0) {
				return this.availableGroups
			}
			return this.availableGroups.filter(group => this.applicationItem.groups.includes(group.id))
		},
	},
	async mounted() {
		await this.fetchOrganisations()
		// Use cached Nextcloud groups from store (preloaded on index page)
		this.loadNextcloudGroupsFromStore()
		// Initialize after groups are loaded
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
		 * Load available Nextcloud groups from store (or fetch if not cached)
		 * Groups are preloaded on the index page for better performance
		 *
		 * @return {void}
		 */
		loadNextcloudGroupsFromStore() {
			// If groups are already cached in store, use them immediately
			if (applicationStore.nextcloudGroups && applicationStore.nextcloudGroups.length > 0) {
				this.availableGroups = applicationStore.nextcloudGroups
				this.loadingGroups = false
				console.info('Using cached Nextcloud groups from application store:', this.availableGroups.length)
			} else {
				// Groups not cached yet - load them (fallback for direct navigation)
				console.info('Groups not cached in application store, loading from API...')
				this.loadingGroups = true
				applicationStore.loadNextcloudGroups().then(() => {
					this.availableGroups = applicationStore.nextcloudGroups
					this.loadingGroups = false
					// Re-initialize to map groups now that they're loaded
					this.initializeApplicationItem()
				}).catch(error => {
					console.error('Error loading Nextcloud groups:', error)
					this.$refs.dialog.setResult({ error: 'Failed to load Nextcloud groups' })
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

				// Organisation is automatically set by backend based on active organisation

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
		 * Reset form data for "create another" mode
		 *
		 * @return {void}
		 */
		resetForm() {
			this.applicationItem = {
				name: '',
				description: '',
				quota: {
					storage: 0,
					bandwidth: 0,
					requests: 0,
					users: 0,
					groups: 0,
				},
				groups: [],
				authorization: {
					create: [],
					read: [],
					update: [],
					delete: [],
				},
			}
			this.selectedGroups = []
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
		 * Update CRUD permission for application
		 *
		 * @param {object} payload - Permission update payload
		 * @return {void}
		 */
		updateApplicationPermission(payload) {
			const { groupId, action, hasPermission } = payload
			console.info('Updating application permission:', { groupId, action, hasPermission })

			// Initialize authorization if not present
			if (!this.applicationItem.authorization) {
				this.applicationItem.authorization = {
					create: [],
					read: [],
					update: [],
					delete: [],
				}
			}

			// Ensure the action array exists
			if (!Array.isArray(this.applicationItem.authorization[action])) {
				this.applicationItem.authorization[action] = []
			}

			// Update the permission
			const groupIndex = this.applicationItem.authorization[action].indexOf(groupId)
			if (hasPermission && groupIndex === -1) {
				// Add the group
				this.applicationItem.authorization[action].push(groupId)
				console.info(`Added ${groupId} to ${action}:`, this.applicationItem.authorization[action])
			} else if (!hasPermission && groupIndex !== -1) {
				// Remove the group
				this.applicationItem.authorization[action].splice(groupIndex, 1)
				console.info(`Removed ${groupId} from ${action}:`, this.applicationItem.authorization[action])
			}

			// Force Vue to detect the change
			this.$set(this.applicationItem, 'authorization', { ...this.applicationItem.authorization })
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
			if (!this.applicationItem.quota) {
				this.applicationItem.quota = { storage: 0, bandwidth: 0, requests: 0, users: 0, groups: 0 }
			}
			this.applicationItem.quota.storage = mbValue * 1024 * 1024
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
			if (!this.applicationItem.quota) {
				this.applicationItem.quota = { storage: 0, bandwidth: 0, requests: 0, users: 0, groups: 0 }
			}
			this.applicationItem.quota.bandwidth = mbValue * 1024 * 1024
		},

		/**
		 * Update request quota
		 *
		 * @param {number} value - Quota value
		 * @return {void}
		 */
		updateRequestQuota(value) {
			// 0 = unlimited
			if (!this.applicationItem.quota) {
				this.applicationItem.quota = { storage: 0, bandwidth: 0, requests: 0, users: 0, groups: 0 }
			}
			this.applicationItem.quota.requests = value ? parseInt(value) : 0
		},

		/**
		 * Update user quota
		 *
		 * @param {number} value - Quota value
		 * @return {void}
		 */
		updateUserQuota(value) {
			// 0 = unlimited (not applicable for applications, but kept for consistency)
			if (!this.applicationItem.quota) {
				this.applicationItem.quota = { storage: 0, bandwidth: 0, requests: 0, users: 0, groups: 0 }
			}
			this.applicationItem.quota.users = value ? parseInt(value) : 0
		},

		/**
		 * Update group quota
		 *
		 * @param {number} value - Quota value
		 * @return {void}
		 */
		updateGroupQuota(value) {
			// 0 = unlimited
			if (!this.applicationItem.quota) {
				this.applicationItem.quota = { storage: 0, bandwidth: 0, requests: 0, users: 0, groups: 0 }
			}
			this.applicationItem.quota.groups = value ? parseInt(value) : 0
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
		 * Save the application
		 *
		 * @return {Promise<void>}
		 */
		async saveApplication() {
			// Validate required fields
			if (!this.applicationItem.name.trim()) {
				this.$refs.dialog.setResult({ error: 'Application name is required' })
				return
			}

			try {
				const { response } = await applicationStore.saveApplication({
					...this.applicationItem,
				})

				if (response.ok) {
					this.$refs.dialog.setResult({ success: true })
				}

			} catch (error) {
				console.error('Error saving application:', error)
				this.$refs.dialog.setResult({
					error: error.message || 'An error occurred while saving the application',
				})
			}
		},
	},
}
</script>

<style scoped>
/* Application-specific styles (tab shell handled by CnTabbedFormDialog) */

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

.rbac-description {
	font-size: 14px;
	color: var(--color-text-lighter);
	margin: 0 0 16px 0;
}
</style>

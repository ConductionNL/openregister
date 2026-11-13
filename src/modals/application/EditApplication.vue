<script setup>
import { translate as t } from '@nextcloud/l10n'
import {
	applicationStore,
	navigationStore,
	organisationStore,
} from '../../store/store.js'
</script>

<template>
	<NcDialog
		:name="
			applicationStore.applicationItem?.uuid
				? t('openregister', 'Edit Application')
				: t('openregister', 'Create Application')
		"
		:open="true"
		size="large"
		@update:open="handleDialogOpen">
		<NcNoteCard v-if="success" type="success">
			<p>
				{{
					applicationStore.applicationItem?.uuid
						? t('openregister', 'Application successfully updated')
						: t('openregister', 'Application successfully created')
				}}
			</p>
		</NcNoteCard>
		<NcNoteCard v-if="error" type="error">
			<p>{{ error }}</p>
		</NcNoteCard>
		<div v-if="!success">
			<!-- Tabs -->
			<div class="tabContainer">
				<AppTabs v-model="activeTab" contentClass="mt-3" justified>
					<AppTab active>
						<template #title>
							<Cog :size="16" />
							<span>{{ t('openregister', 'Settings') }}</span>
						</template>
						<div class="form-editor">
							<NcTextField
								v-model="applicationItem.name"
								:disabled="loading"
								:label="t('openregister', 'Name *')"
								:error="!applicationItem.name.trim()"
								:placeholder="
									t('openregister', 'Enter application name')
								" />

							<NcTextArea
								v-model="applicationItem.description"
								:disabled="loading"
								:label="t('openregister', 'Description')"
								:placeholder="
									t(
										'openregister',
										'Enter application description (optional)',
									)
								"
								:rows="4" />

							<!-- Organisation is automatically set to active organisation by backend -->

							<GroupSelect
								v-model="selectedGroups"
								label="Nextcloud Groups"
								:options="availableGroups"
								:disabled="loading"
								:loading="loadingGroups"
								:placeholder="t('openregister', 'Search groups...')"
								:emptyText="
									t(
										'openregister',
										'No groups found. Try a different search.',
									)
								"
								hint="Only members of selected groups can access this application"
								@searchChange="searchGroups"
								@update:modelValue="updateGroups" />
						</div>
					</AppTab>

					<AppTab>
						<template #title>
							<Database :size="16" />
							<span>Quota</span>
						</template>
						<div class="form-editor">
							<NcTextField
								:disabled="loading"
								label="Storage Quota (MB)"
								type="number"
								placeholder="0 = unlimited"
								:modelValue="storageQuotaMB"
								@update:modelValue="updateStorageQuota" />

							<NcTextField
								:disabled="loading"
								label="Bandwidth Quota (MB/month)"
								type="number"
								placeholder="0 = unlimited"
								:modelValue="bandwidthQuotaMB"
								@update:modelValue="updateBandwidthQuota" />

							<NcTextField
								:disabled="loading"
								label="API Request Quota (requests/day)"
								type="number"
								placeholder="0 = unlimited"
								:modelValue="applicationItem.quota?.requests || 0"
								@update:modelValue="updateRequestQuota" />

							<NcTextField
								:disabled="loading"
								label="User Quota"
								type="number"
								placeholder="0 = unlimited (not applicable for applications)"
								:modelValue="applicationItem.quota?.users || 0"
								@update:modelValue="updateUserQuota" />

							<NcTextField
								:disabled="loading"
								label="Group Quota"
								type="number"
								placeholder="0 = unlimited"
								:modelValue="applicationItem.quota?.groups || 0"
								@update:modelValue="updateGroupQuota" />
						</div>
					</AppTab>

					<AppTab>
						<template #title>
							<Shield :size="16" />
							<span>Security</span>
						</template>
						<div class="security-section">
							<div class="rbac-container">
								<div class="rbac-section">
									<p class="rbac-description">
										Configure CRUD permissions for this
										application. Empty permissions = open access
										for all application groups. The 'admin' group
										always has full access.
									</p>

									<RbacTable
										entityType="application"
										:authorization="
											applicationItem.authorization
										"
										:availableGroups="availableGroups"
										:organisationGroups="
											applicationItem.groups || []
										"
										@update="updateApplicationPermission" />
								</div>
							</div>
						</div>
					</AppTab>
				</AppTabs>
			</div>
		</div>

		<template #actions>
			<CloseCancelButton :label="success ? 'Close' : 'Cancel'" @click="closeModal" />
			<NcButton
				v-if="!success"
				:disabled="loading || !applicationItem.name.trim()"
				variant="primary"
				@click="saveApplication()">
				<template #icon>
					<NcLoadingIcon v-if="loading" :size="20" />
					<ContentSaveOutline
						v-if="!loading && applicationStore.applicationItem?.uuid"
						:size="20" />
					<Plus
						v-if="!loading && !applicationStore.applicationItem?.uuid"
						:size="20" />
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
	NcLoadingIcon,
	NcNoteCard,
	NcTextArea,
	NcTextField,
} from '@nextcloud/vue'
import Cog from 'vue-material-design-icons/Cog.vue'
import ContentSaveOutline from 'vue-material-design-icons/ContentSaveOutline.vue'
import Database from 'vue-material-design-icons/Database.vue'
import Plus from 'vue-material-design-icons/Plus.vue'
import Shield from 'vue-material-design-icons/Shield.vue'
import RbacTable from '../../components/RbacTable.vue'
import CloseCancelButton from '../../components/shared/CloseCancelButton.vue'
import GroupSelect from '../../components/shared/GroupSelect.vue'
import AppTab from '../../components/tabs/AppTab.vue'
import AppTabs from '../../components/tabs/AppTabs.vue'
import { searchGroupsDebounced } from '../../services/groupSearch.js'
import { quotaFields, unlimitedQuota } from '../../services/quotaFields.js'

/**
 * An authorization map that grants no group anything.
 *
 * @return {object} The map, per action.
 */
function emptyAuthorization() {
	return {
		create: [],
		read: [],
		update: [],
		delete: [],
	}
}

/**
 * The form of a new application: no name, no groups, unlimited quotas.
 *
 * @return {object} The application.
 */
function blankApplication() {
	return {
		name: '',
		description: '',
		// Organisation will be set automatically by backend based on active organisation
		quota: unlimitedQuota(),

		groups: [],
		authorization: emptyAuthorization(),
	}
}

export default {
	name: 'EditApplication',
	components: {
		GroupSelect,
		NcDialog,
		NcTextField,
		NcTextArea,
		NcButton,
		NcLoadingIcon,
		NcNoteCard,
		AppTabs,
		AppTab,
		RbacTable,
		// Icons
		ContentSaveOutline,
		CloseCancelButton,
		Plus,
		Cog,
		Database,
		Shield,
	},

	mixins: [quotaFields],

	data() {
		return {
			activeTab: 0,
			applicationItem: blankApplication(),

			selectedGroups: [],
			availableGroups: [],
			loadingGroups: false,
			groupSearchDebounce: null,
			success: false,
			loading: false,
			error: false,
			closeModalTimeout: null,
		}
	},

	computed: {
		/**
		 * @spec exclude Names the form item for the quota fields; UI plumbing.
		 */
		quotaItem() {
			return this.applicationItem
		},

		/**
		 * @spec exclude UI display helper — filters available groups to those assigned to the application.
		 */
		filteredAvailableGroups() {
			// Filter available groups to only show groups assigned to the application
			if (
				!this.applicationItem.groups
				|| this.applicationItem.groups.length === 0
			) {
				return this.availableGroups
			}
			return this.availableGroups.filter((group) =>
				this.applicationItem.groups.includes(group.id),
			)
		},
	},

	/**
	 * @spec exclude Vue lifecycle hook — loads organisations/groups and hydrates the modal.
	 */
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
		 * @spec openspec/specs/entity-management-modals/spec.md
		 */
		async fetchOrganisations() {
			await organisationStore.refreshOrganisationList()
		},

		/**
		 * Load available Nextcloud groups from store (or fetch if not cached)
		 * Groups are preloaded on the index page for better performance
		 *
		 * @return {void}
		 * @spec exclude Modal data-load plumbing — reads cached Nextcloud groups from the store.
		 */
		loadNextcloudGroupsFromStore() {
			// If groups are already cached in store, use them immediately
			if (
				applicationStore.nextcloudGroups
				&& applicationStore.nextcloudGroups.length > 0
			) {
				this.availableGroups = applicationStore.nextcloudGroups
				this.loadingGroups = false
			} else {
				// Groups not cached yet - load them (fallback for direct navigation)
				this.loadingGroups = true
				applicationStore
					.loadNextcloudGroups()
					.then(() => {
						this.availableGroups = applicationStore.nextcloudGroups
						this.loadingGroups = false
						// Re-initialize to map groups now that they're loaded
						this.initializeApplicationItem()
					})
					.catch((error) => {
						console.error('Error loading Nextcloud groups:', error)
						this.error = 'Failed to load Nextcloud groups'
						this.loadingGroups = false
					})
			}
		},

		/**
		 * Search for Nextcloud groups with debouncing
		 *
		 * @param {string} searchQuery - The search query entered by user
		 * @return {void}
		 * @spec exclude Modal data-load plumbing — debounced group search via OCS API.
		 */
		searchGroups(searchQuery) {
			searchGroupsDebounced(this, {
				query: searchQuery,
				onEmpty: () => this.loadNextcloudGroupsFromStore(),
				toGroup: (groupId) => ({ id: groupId, name: groupId, userCount: 0 }),
				warnOnRefusal: false,
			})
		},

		/**
		 * Initialize application item from store
		 *
		 * @return {void}
		 * @spec exclude Modal hydration plumbing — copies the store's applicationItem into local form state.
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
				if (
					Array.isArray(this.applicationItem.groups)
					&& this.applicationItem.groups.length > 0
				) {
					this.selectedGroups = this.groupsOf(this.applicationItem.groups)
				}
			}
		},

		/**
		 * The groups for the select component of the stored group ids.
		 *
		 * @param {Array<string>} groupIds The stored group ids
		 * @return {Array<object>} The groups
		 */
		groupsOf(groupIds) {
			return groupIds
				.map((groupId) => {
					// Find the group in availableGroups
					const group = this.availableGroups.find((g) => g.id === groupId)
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
				.filter((g) => g !== null)
		},

		/**
		 * Update groups selection
		 *
		 * @param {Array} groups - Selected groups
		 * @return {void}
		 * @spec exclude Form-field binding — maps selected groups to id array.
		 */
		updateGroups(groups) {
			this.selectedGroups = groups || []
			// Store only the group IDs, not the full objects
			this.applicationItem.groups = this.selectedGroups.map(
				(group) => group.id,
			)
		},

		/**
		 * Update CRUD permission for application
		 *
		 * @param {object} payload - Permission update payload
		 * @return {void}
		 * @spec exclude Form-field binding — toggles a group's CRUD permission in local authorization state.
		 */
		updateApplicationPermission(payload) {
			const { groupId, action, hasPermission } = payload
			const permitted = this.permittedGroups(action)

			// Update the permission
			const groupIndex = permitted.indexOf(groupId)
			if (hasPermission && groupIndex === -1) {
				// Add the group
				permitted.push(groupId)
			} else if (!hasPermission && groupIndex !== -1) {
				// Remove the group
				permitted.splice(groupIndex, 1)
			}

			// Force Vue to detect the change
			this.applicationItem.authorization = {
				...this.applicationItem.authorization,
			}
		},

		/**
		 * The groups allowed an action, creating the authorization map and the
		 * action's list when they are missing.
		 *
		 * @param {string} action The action (create, read, update, delete)
		 * @return {Array<string>} The group ids; changes to it change the map
		 */
		permittedGroups(action) {
			// Initialize authorization if not present
			if (!this.applicationItem.authorization) {
				this.applicationItem.authorization = emptyAuthorization()
			}

			// Ensure the action array exists
			if (!Array.isArray(this.applicationItem.authorization[action])) {
				this.applicationItem.authorization[action] = []
			}
			return this.applicationItem.authorization[action]
		},

		/**
		 * Close the modal and reset state
		 *
		 * @return {void}
		 * @spec exclude Modal close plumbing — resets local state and clears modal/dialog.
		 */
		closeModal() {
			this.success = false
			this.error = null
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
		 * @spec exclude Modal save plumbing — validates name then delegates to applicationStore.saveApplication.
		 */
		async saveApplication() {
			this.loading = true
			this.error = null

			if (this.refusedBlankName()) {
				return
			}

			try {
				await this.storeApplication()
			} catch (error) {
				this.showSaveFailure(error)
			} finally {
				this.loading = false
			}
		},

		/**
		 * Refuse a form without a name, saying so.
		 *
		 * @return {boolean} True when the name is blank
		 */
		refusedBlankName() {
			// Validate required fields
			if (!this.applicationItem.name.trim()) {
				this.error = 'Application name is required'
				this.loading = false
				return true
			}
			return false
		},

		/**
		 * Send a copy of the form to the store; on success, close after two
		 * seconds.
		 *
		 * @return {Promise<void>}
		 */
		async storeApplication() {
			const { response } = await applicationStore.saveApplication({
				...this.applicationItem,
			})

			this.success = response.ok
			this.error = false

			if (response.ok) {
				this.closeModalTimeout = setTimeout(this.closeModal, 2000)
			}
		},

		/**
		 * Say why the save failed.
		 *
		 * @param {Error} error What went wrong
		 * @return {void}
		 */
		showSaveFailure(error) {
			this.success = false
			this.error =
				error.message || 'An error occurred while saving the application'
		},

		/**
		 * Handle dialog open/close event
		 *
		 * @param {boolean} isOpen - Whether the dialog is open
		 * @return {void}
		 * @spec exclude UI event handler — closes the modal on dialog dismiss.
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

<style scoped lang="scss">
@use '../../styles/modals.scss' as *;

/* EditApplication-specific styles */
.tabContainer {
	margin-top: 20px;
}

/* Tab title styling with icons */
@include tab-container-deep-nav-link-rules;

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

@include security-section;

.rbac-container {
	display: flex;
	flex-direction: column;
	gap: 16px;
	padding: 0;
}

@include rbac-section;

@include rbac-description;

@include loading-groups-rules;

.no-groups p {
	margin: 0;
}
</style>

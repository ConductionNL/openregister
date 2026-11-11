<script setup>
import { translatePlural as n, translate as t } from '@nextcloud/l10n'
import { navigationStore, organisationStore } from '../../store/store.js'
</script>

<template>
	<NcDialog
		:name="
			organisationStore.organisationItem?.uuid && !createAnother
				? t('openregister', 'Edit Organisation')
				: t('openregister', 'Create Organisation')
		"
		size="large"
		@update:open="handleDialogOpen">
		<NcNoteCard v-if="success" type="success">
			<p>
				{{
					organisationStore.organisationItem?.uuid && !createAnother
						? t('openregister', 'Organisation successfully updated')
						: t('openregister', 'Organisation successfully created')
				}}
			</p>
		</NcNoteCard>
		<NcNoteCard v-if="error" type="error">
			<p>{{ error }}</p>
		</NcNoteCard>
		<div v-if="createAnother || !success">
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
								v-model="organisationItem.name"
								:disabled="loading"
								:label="t('openregister', 'Name *')"
								:error="!organisationItem.name.trim()"
								:placeholder="
									t('openregister', 'Enter organisation name')
								" />

							<NcTextField
								v-model="organisationItem.slug"
								:disabled="loading"
								:label="t('openregister', 'Slug')"
								:placeholder="
									t(
										'openregister',
										'Optional URL-friendly identifier',
									)
								" />

							<NcTextArea
								v-model="organisationItem.description"
								:disabled="loading"
								:label="t('openregister', 'Description')"
								:placeholder="
									t(
										'openregister',
										'Enter organisation description (optional)',
									)
								"
								:rows="4" />

							<GroupSelect
								v-model="selectedGroups"
								:label="t('openregister', 'Nextcloud Groups')"
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
								:hint="
									t(
										'openregister',
										'Only members of selected groups can access this organisation',
									)
								"
								@searchChange="searchGroups"
								@update:modelValue="updateGroups" />

							<NcCheckboxRadioSwitch
								v-model="organisationItem.active"
								:disabled="loading">
								{{ t('openregister', 'Active') }}
							</NcCheckboxRadioSwitch>

							<NcNoteCard
								v-if="!organisationItem.active"
								type="warning">
								<p>
									{{
										t(
											'openregister',
											'Inactive organisations cannot be used',
										)
									}}
								</p>
							</NcNoteCard>
						</div>
					</AppTab>

					<AppTab>
						<template #title>
							<Database :size="16" />
							<span>{{ t('openregister', 'Quota') }}</span>
						</template>
						<div class="form-editor">
							<NcTextField
								:disabled="loading"
								:label="t('openregister', 'Storage Quota (MB)')"
								type="number"
								:placeholder="t('openregister', '0 = unlimited')"
								:modelValue="storageQuotaMB"
								@update:modelValue="updateStorageQuota" />

							<NcTextField
								:disabled="loading"
								:label="
									t('openregister', 'Bandwidth Quota (MB/month)')
								"
								type="number"
								:placeholder="t('openregister', '0 = unlimited')"
								:modelValue="bandwidthQuotaMB"
								@update:modelValue="updateBandwidthQuota" />

							<NcTextField
								:disabled="loading"
								:label="
									t(
										'openregister',
										'API Request Quota (requests/day)',
									)
								"
								type="number"
								:placeholder="t('openregister', '0 = unlimited')"
								:modelValue="organisationItem.quota?.requests || 0"
								@update:modelValue="updateRequestQuota" />

							<NcTextField
								:disabled="loading"
								:label="t('openregister', 'User Quota')"
								type="number"
								:placeholder="t('openregister', '0 = unlimited')"
								:modelValue="organisationItem.quota?.users || 0"
								@update:modelValue="updateUserQuota" />

							<NcTextField
								:disabled="loading"
								:label="t('openregister', 'Group Quota')"
								type="number"
								:placeholder="t('openregister', '0 = unlimited')"
								:modelValue="organisationItem.quota?.groups || 0"
								@update:modelValue="updateGroupQuota" />
						</div>
					</AppTab>

					<AppTab :disabled="!organisationItem.uuid">
						<template #title>
							<AccountMultiple :size="16" />
							<span>{{ t('openregister', 'Users') }}</span>
						</template>
						<OrganisationMembers
							:organisation="organisationItem"
							:members="organisationUsers"
							:state="memberState"
							@add="showAddUserDialog = true"
							@remove="removeUser" />
					</AppTab>

					<AppTab>
						<template #title>
							<Shield :size="16" />
							<span>{{ t('openregister', 'Security') }}</span>
						</template>
						<div class="security-section">
							<div v-if="loadingGroups" class="loading-groups">
								<NcLoadingIcon :size="20" />
								<span>{{
									t('openregister', 'Loading user groups...')
								}}</span>
							</div>

							<div v-else class="rbac-container">
								<AppTabs contentClass="mt-3" pills>
									<AppTab
										v-for="tab in permissionTabs"
										:key="tab.entityType"
										:title="tab.title">
										<RbacTable
											:entityType="tab.entityType"
											:authorization="
												organisationItem.authorization || {}
											"
											:availableGroups="availableGroups"
											:organisationGroups="
												organisationItem.groups || []
											"
											@update="updateEntityPermission" />
									</AppTab>

									<!-- Special Rights -->
									<AppTab
										:title="t('openregister', 'Special Rights')">
										<SpecialRightsTable
											:selected="selectedSpecialRights"
											:groups="filteredAvailableGroups"
											@update="updateSpecialRight" />
									</AppTab>
								</AppTabs>
							</div>
						</div>
					</AppTab>
				</AppTabs>
			</div>
		</div>

		<template #actions>
			<NcCheckboxRadioSwitch
				v-if="!organisationStore.organisationItem?.uuid"
				v-model="createAnother"
				class="create-another-checkbox"
				:disabled="loading">
				{{ t('openregister', 'Create another') }}
			</NcCheckboxRadioSwitch>
			<CloseCancelButton :success="success" @click="closeModal" />
			<NcButton
				v-if="createAnother || !success"
				:disabled="loading || !organisationItem.name.trim()"
				variant="primary"
				@click="saveOrganisation()">
				<template #icon>
					<NcLoadingIcon v-if="loading" :size="20" />
					<ContentSaveOutline
						v-if="!loading && organisationStore.organisationItem?.uuid"
						:size="20" />
					<Plus
						v-if="!loading && !organisationStore.organisationItem?.uuid"
						:size="20" />
				</template>
				{{
					organisationStore.organisationItem?.uuid && !createAnother
						? t('openregister', 'Save')
						: t('openregister', 'Create')
				}}
			</NcButton>
		</template>
		<RemoveUserDialog
			:show="showRemoveUserDialog"
			:userId="userToRemove"
			:removing="removingUser !== null"
			@cancel="cancelRemoveUser"
			@confirm="confirmRemoveUser" />
	</NcDialog>
</template>

<script>
import { showError, showSuccess } from '@nextcloud/dialogs'
import {
	NcButton,
	NcCheckboxRadioSwitch,
	NcDialog,
	NcLoadingIcon,
	NcNoteCard,
	NcTextArea,
	NcTextField,
} from '@nextcloud/vue'
import AccountMultiple from 'vue-material-design-icons/AccountMultiple.vue'
import Cog from 'vue-material-design-icons/Cog.vue'
import ContentSaveOutline from 'vue-material-design-icons/ContentSaveOutline.vue'
import Database from 'vue-material-design-icons/Database.vue'
import Plus from 'vue-material-design-icons/Plus.vue'
import Shield from 'vue-material-design-icons/Shield.vue'
import CloseCancelButton from '../../components/shared/CloseCancelButton.vue'
import RbacTable from '../../components/RbacTable.vue'
import GroupSelect from '../../components/shared/GroupSelect.vue'
import AppTab from '../../components/tabs/AppTab.vue'
import AppTabs from '../../components/tabs/AppTabs.vue'
import OrganisationMembers from './OrganisationMembers.vue'
import RemoveUserDialog from './RemoveUserDialog.vue'
import SpecialRightsTable from './SpecialRightsTable.vue'
import { entityTabs, SPECIAL_RIGHTS } from './organisationRights.js'
import { searchGroupsDebounced } from '../../services/groupSearch.js'
import { freshState } from '../../services/initialState.js'
import { quotaFields, unlimitedQuota } from '../../services/quotaFields.js'

/**
 * The state every instance of this component starts from.
 */
const INITIAL_STATE = {
	activeTab: 0,
	organisationItem: {
		name: '',
		slug: '',
		description: '',
		active: true,
		quota: unlimitedQuota(),
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
	createAnother: false,
	success: false,
	loading: false,
	error: false,
	closeModalTimeout: null,
}

/**
 * The form after a save with "create another".
 */
const NEXT_FORM = {
	name: '',
	slug: '',
	description: '',
	active: true,
	quota: { storage: null, bandwidth: null, requests: null },
	groups: [],
}

/**
 * Take a user out of an organisation.
 *
 * @param {string} uuid The organisation
 * @param {string} userId The user
 * @return {Promise<Response>} The response
 */
function postLeave(uuid, userId) {
	return fetch(`/index.php/apps/openregister/api/organisations/${uuid}/leave`, {
		method: 'POST',
		headers: { 'Content-Type': 'application/json' },
		body: JSON.stringify({ userId }),
	})
}

export default {
	name: 'EditOrganisation',
	components: {
		GroupSelect,
		NcDialog,
		NcTextField,
		NcTextArea,
		NcButton,
		NcLoadingIcon,
		NcNoteCard,
		NcCheckboxRadioSwitch,
		AppTabs,
		AppTab,
		RemoveUserDialog,
		RbacTable,
		CloseCancelButton,
		OrganisationMembers,
		SpecialRightsTable,
		// Icons
		ContentSaveOutline,
		Plus,
		Cog,
		Database,
		AccountMultiple,
		Shield,
	},

	mixins: [quotaFields],

	data() {
		return freshState(INITIAL_STATE)
	},

	computed: {
		// A <script setup> binding is not on `this`, and a watch path reads `this`.
		organisationStore: () => organisationStore,

		/**
		 * @spec exclude Names the form item for the quota fields; UI plumbing.
		 */
		quotaItem() {
			return this.organisationItem
		},

		/**
		 * @spec exclude Static tab list for the template; UI presentation helper.
		 */
		permissionTabs() {
			return entityTabs()
		},

		/**
		 * @spec exclude Progress flags for the members tab; UI presentation helper.
		 */
		memberState() {
			return {
				loading: this.loading,
				loadingUsers: this.loadingUsers,
				removingUser: this.removingUser,
			}
		},

		/**
		 * Filter available groups to only show those assigned to the organisation
		 *
		 * @return {Array} Filtered array of groups
		 * @spec exclude Computed filtered group list for the select; UI presentation helper.
		 */
		filteredAvailableGroups() {
			// If no groups assigned yet, show all available groups
			if (
				!this.organisationItem.groups
				|| this.organisationItem.groups.length === 0
			) {
				return this.availableGroups
			}

			// Only show groups that are in the organisation's groups list
			return this.availableGroups.filter((group) =>
				this.organisationItem.groups.includes(group.id),
			)
		},
	},

	watch: {
		// Watch for changes in the store's organisationItem (e.g., when clicking edit on different organisations)
		'organisationStore.organisationItem': {
			/**
			 * @param newVal
			 * @param oldVal
			 * @spec exclude Watcher re-initializing the form when the edited organisation changes; UI reactivity plumbing.
			 */
			handler(newVal, oldVal) {
				// Only reinitialize if the UUID changed (different organisation) or went from null to something
				if (newVal && (!oldVal || newVal.uuid !== oldVal?.uuid)) {
					this.initializeOrganisationItem()
					// Users are already included in the organisation object, no need to fetch separately
				}
			},

			deep: true,
		},
	},

	/**
	 * @spec exclude Vue mounted() hook loading cached groups and initializing the form; modal init plumbing.
	 */
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
		 * @spec openspec/specs/entity-management-modals/spec.md
		 */
		loadNextcloudGroupsFromStore() {
			// If groups are already cached in store, use them immediately
			if (organisationStore.nextcloudGroups?.length > 0) {
				this.availableGroups = organisationStore.nextcloudGroups
				this.loadingGroups = false
				return
			}
			// Groups not cached yet - load them (fallback for direct navigation)
			this.loadingGroups = true
			organisationStore
				.loadNextcloudGroups()
				.then(this.groupsLoaded)
				.catch(this.groupsFailed)
		},

		/**
		 * Offer the fetched groups and map the form's groups to them.
		 *
		 * @return {void}
		 * @spec exclude Applies a group fetch to the form; modal init plumbing.
		 */
		groupsLoaded() {
			this.availableGroups = organisationStore.nextcloudGroups
			this.loadingGroups = false
			this.initializeOrganisationItem()
		},

		/**
		 * Show that the groups could not be fetched.
		 *
		 * @param {Error} error The failure
		 * @return {void}
		 * @spec exclude Error display for a failed group fetch; UI plumbing.
		 */
		groupsFailed(error) {
			console.error('Error loading Nextcloud groups:', error)
			this.error = t('openregister', 'Failed to load Nextcloud groups')
			this.loadingGroups = false
		},

		/**
		 * Search for Nextcloud groups with debouncing
		 *
		 * @param {string} searchQuery - The search query entered by user
		 * @return {void}
		 * @spec exclude Debounced group-autocomplete search; UI search plumbing.
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
		 * Initialize organisation item from store
		 *
		 * @return {void}
		 * @spec exclude Hydrates the local form model and selected groups/users from the store; modal init plumbing.
		 */
		initializeOrganisationItem() {
			if (!organisationStore.organisationItem?.uuid) {
				return
			}
			this.organisationItem = {
				...this.organisationItem, // Keep default structure
				...organisationStore.organisationItem,
				active: organisationStore.organisationItem.active ?? true,
			}
			this.loadGroupsSelection()
			if (Array.isArray(this.organisationItem.users)) {
				this.organisationUsers = [...this.organisationItem.users]
			}
			// Initialize special rights from authorization
			this.initializeSpecialRights()
		},

		/**
		 * Select the organisation's stored groups. Groups can be stored as either
		 * an array of IDs or an array of objects (for backwards compatibility).
		 *
		 * @return {void}
		 * @spec exclude Hydrates the selected groups from the form model; modal init plumbing.
		 */
		loadGroupsSelection() {
			const groups = this.organisationItem.groups
			if (Array.isArray(groups) && groups.length > 0) {
				this.selectedGroups = groups
					.map((role) => this.groupForRole(role))
					.filter((g) => g !== null)
			}
		},

		/**
		 * The group a stored role refers to. A group the groups API did not
		 * return is still shown, as a placeholder.
		 *
		 * @param {string|object} role A group id, or an object with an id or name
		 * @return {object} The group
		 * @spec exclude Resolves a stored group reference for display; UI plumbing.
		 */
		groupForRole(role) {
			const roleId = typeof role === 'string' ? role : role.id || role.name
			const group = this.availableGroups.find((g) => g.id === roleId)
			return group || { id: roleId, name: roleId, userCount: 0 }
		},

		/**
		 * Show confirmation dialog before removing a user
		 *
		 * @param {string} userId - User ID to remove
		 * @return {void}
		 * @spec exclude Opens the remove-user confirmation dialog; UI selection plumbing.
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
		 * @spec exclude Dismisses the remove-user confirmation dialog; UI plumbing.
		 */
		cancelRemoveUser() {
			this.showRemoveUserDialog = false
			this.userToRemove = null
		},

		/**
		 * Confirm and execute user removal
		 *
		 * @return {Promise<void>}
		 * @spec exclude Posts the organisation leave request and refreshes the store; UI orchestration plumbing.
		 */
		async confirmRemoveUser() {
			if (!this.organisationItem.uuid || !this.userToRemove) return
			this.removingUser = this.userToRemove
			this.error = null
			try {
				await this.sendRemoval()
			} catch (error) {
				this.removalFailed(error)
			} finally {
				this.removingUser = null
			}
		},

		/**
		 * Send the leave request and follow up on its answer.
		 *
		 * @return {Promise<void>}
		 * @spec exclude Posts the organisation leave request; UI orchestration plumbing.
		 */
		async sendRemoval() {
			const response = await postLeave(
				this.organisationItem.uuid,
				this.userToRemove,
			)
			await (response.ok ? this.userRemoved() : this.removalRefused(response))
		},

		/**
		 * Show that the leave request failed.
		 *
		 * @param {Error} error The failure
		 * @return {void}
		 * @spec exclude Error display for a failed leave request; UI plumbing.
		 */
		removalFailed(error) {
			console.error('Error removing user:', error)
			this.error = t('openregister', 'Failed to remove user from organisation')
			showError(this.error)
		},

		/**
		 * Drop the removed user from the form, refresh the store and close the dialog.
		 *
		 * @return {Promise<void>}
		 * @spec exclude Updates the local member list after a leave request; UI plumbing.
		 */
		async userRemoved() {
			const removed = this.userToRemove
			this.organisationUsers = this.organisationUsers.filter(
				(u) => u !== removed,
			)
			if (this.organisationItem.users) {
				this.organisationItem.users = this.organisationItem.users.filter(
					(u) => u !== removed,
				)
			}
			await organisationStore.refreshOrganisations()
			this.showRemoveUserDialog = false
			this.userToRemove = null
			showSuccess(t('openregister', 'User removed successfully'))
		},

		/**
		 * Show why the server refused to remove the user.
		 *
		 * @param {Response} response The refused response
		 * @return {Promise<void>}
		 * @spec exclude Error display for a refused leave request; UI plumbing.
		 */
		async removalRefused(response) {
			const errorData = await response.json()
			this.error =
				errorData.error
				|| t('openregister', 'Failed to remove user from organisation')
			showError(this.error)
		},

		/**
		 * Update groups selection
		 *
		 * @param {Array} groups - Selected groups
		 * @return {void}
		 * @spec exclude Reactive multi-select setter mapping selected groups to IDs; UI plumbing.
		 */
		updateGroups(groups) {
			this.selectedGroups = groups || []
			// Store only the group IDs, not the full objects
			this.organisationItem.groups = this.selectedGroups.map(
				(group) => group.id,
			)
		},

		/**
		 * Close the modal and reset state
		 *
		 * @return {void}
		 * @spec exclude Modal close handler resetting navigationStore.modal/dialog and local state; UI plumbing.
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
		 * @spec exclude Save handler delegating to organisationStore.saveOrganisation and refreshing lists; entity persistence lives in the store, this is modal orchestration plumbing.
		 */
		async saveOrganisation() {
			this.error = null
			if (!this.organisationItem.name.trim()) {
				this.error = t('openregister', 'Organisation name is required')
				return
			}
			this.loading = true
			try {
				await this.storeOrganisation()
			} catch (error) {
				this.saveFailed(error)
			} finally {
				this.loading = false
			}
		},

		/**
		 * Send the form to the store and follow up on a saved organisation.
		 *
		 * @return {Promise<void>}
		 * @spec exclude Delegates to organisationStore.saveOrganisation; modal orchestration plumbing.
		 */
		async storeOrganisation() {
			const { response } = await organisationStore.saveOrganisation({
				...this.organisationItem,
			})
			if (response.ok) {
				await this.organisationSaved()
			}
		},

		/**
		 * Show that the save failed.
		 *
		 * @param {Error} error The failure
		 * @return {void}
		 * @spec exclude Error display for a failed save; UI plumbing.
		 */
		saveFailed(error) {
			console.error('Error saving organisation:', error)
			this.success = false
			this.error =
				error.message
				|| t(
					'openregister',
					'An error occurred while saving the organisation',
				)
		},

		/**
		 * Refresh the lists after a save, then either clear the form for another
		 * organisation or close the modal.
		 *
		 * @return {Promise<void>}
		 * @spec exclude Post-save refresh and modal follow-up; UI orchestration plumbing.
		 */
		async organisationSaved() {
			// Explicitly refresh the organisation list to ensure UI is updated
			await organisationStore.refreshOrganisationList()
			// Also refresh active organisation in case it was just created
			if (!this.organisationItem.uuid) {
				await organisationStore.getActiveOrganisation()
			}
			this.success = true
			this.error = false
			if (this.createAnother) {
				this.prepareForAnother()
			} else {
				this.closeModalTimeout = setTimeout(this.closeModal, 2000)
			}
		},

		/**
		 * Clear the form shortly after a save, and the success message after 2s.
		 *
		 * @return {void}
		 * @spec exclude Form reset timers for creating another organisation; UI plumbing.
		 */
		prepareForAnother() {
			setTimeout(this.clearForm, 500)
			setTimeout(() => {
				this.success = null
			}, 2000)
		},

		/**
		 * Empty the form for the next organisation.
		 *
		 * @return {void}
		 * @spec exclude Form reset for creating another organisation; UI plumbing.
		 */
		clearForm() {
			this.organisationItem = freshState(NEXT_FORM)
			this.selectedGroups = []
			this.activeTab = 0
		},

		/**
		 * Handle dialog open/close event
		 *
		 * @param {boolean} isOpen - Whether the dialog is open
		 * @return {void}
		 * @spec exclude Dialog open/close event handler closing the modal on dismiss; UI plumbing.
		 */
		handleDialogOpen(isOpen) {
			// Only close the modal if the dialog is being closed (isOpen = false)
			if (!isOpen) {
				this.closeModal()
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
		 * @spec exclude Reactive setter toggling a group's entity-permission entry on the local authorization model; UI form plumbing.
		 */
		updateEntityPermission({ entityType, groupId, action, hasPermission }) {
			const currentPermissions = this.permissionList(entityType, action)
			const groupIndex = currentPermissions.indexOf(groupId)
			if (hasPermission && groupIndex === -1) {
				currentPermissions.push(groupId)
			} else if (!hasPermission && groupIndex !== -1) {
				currentPermissions.splice(groupIndex, 1)
			}
		},

		/**
		 * The groups allowed an action on an entity type, created empty when the
		 * authorization model has no entry for it yet.
		 *
		 * @param {string} entityType The entity type (register, schema, object, view, agent)
		 * @param {string} action The action (create, read, update, delete)
		 * @return {Array<string>} The group ids; changes to it change the model
		 * @spec exclude Accessor on the local authorization model; UI form plumbing.
		 */
		permissionList(entityType, action) {
			if (!this.organisationItem.authorization) {
				this.organisationItem.authorization = {}
			}
			const authorization = this.organisationItem.authorization
			if (!authorization[entityType]) {
				authorization[entityType] = {}
			}
			if (!authorization[entityType][action]) {
				authorization[entityType][action] = []
			}
			return authorization[entityType][action]
		},

		/**
		 * Update special right
		 *
		 * @param {object} update The change
		 * @param {string} update.right The special right (object_publish, agent_use, dashboard_view, llm_use)
		 * @param {Array} update.groups Array of group objects with {id, name}
		 * @return {void}
		 * @spec exclude Reactive setter writing a special-right's group IDs to the local authorization model; UI form plumbing.
		 */
		updateSpecialRight({ right, groups }) {
			// Initialize authorization if it doesn't exist
			if (!this.organisationItem.authorization) {
				this.organisationItem.authorization = {}
			}

			// Convert group objects to array of group IDs
			const groupIds = groups.map((g) => g.id)

			// Update the authorization
			this.organisationItem.authorization[right] = groupIds

			// Update the selected special rights for UI binding
			this.selectedSpecialRights[right] = groups
		},

		/**
		 * Initialize special rights from organization authorization
		 *
		 * @return {void}
		 * @spec exclude Seeds the special-rights UI binding from the authorization model on load; modal init plumbing.
		 */
		initializeSpecialRights() {
			const auth = this.organisationItem.authorization || {}
			SPECIAL_RIGHTS.forEach(({ right }) => {
				this.selectedSpecialRights[right] = Array.isArray(auth[right])
					? auth[right].map(this.specialRightGroup)
					: []
			})
		},

		/**
		 * The group a special right names. A group the groups API did not
		 * return is still shown, as a placeholder.
		 *
		 * @param {string} groupId The group id
		 * @return {object} The group
		 * @spec exclude Resolves a stored group id for display; UI plumbing.
		 */
		specialRightGroup(groupId) {
			const group = this.availableGroups.find((g) => g.id === groupId)
			return group || { id: groupId, name: groupId }
		},
	},
}
</script>

<style scoped lang="scss">
@use '../../styles/modals.scss' as *;

/* EditOrganisation-specific styles */
.tabContainer {
	margin-top: 20px;
}

/* Tab title styling with icons */
@include tab-container-deep-nav-link-rules;

.create-another-checkbox {
	margin-right: auto;
}

@include security-section;

@include loading-groups-rules;

/* RBAC Security Tab Styling */
.rbac-container {
	display: flex;
	flex-direction: column;
	gap: 16px;
	padding: 16px 0;
}
</style>

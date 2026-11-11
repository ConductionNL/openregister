<script setup>
import { translate as t } from '@nextcloud/l10n'
import { navigationStore, organisationStore } from '../../store/store.js'
</script>

<template>
	<NcDialog
		:name="t('openregister', 'Add User to Organisation')"
		size="normal"
		@update:open="handleDialogClose">
		<NcNoteCard v-if="success" type="success">
			<p>
				{{
					t(
						'openregister',
						'Successfully added user to organisation: {name}',
						{ name: joinedOrganisationName },
					)
				}}
			</p>
		</NcNoteCard>
		<NcNoteCard v-if="error" type="error">
			<p>{{ error }}</p>
		</NcNoteCard>

		<div v-if="!success">
			<div class="selection-section">
				<!-- Organisation Selection -->
				<div class="field-group">
					<label for="organisation-select">{{
						t('openregister', 'Organisation')
					}}</label>
					<NcSelect
						v-model="selectedOrganisation"
						inputId="organisation-select"
						:inputLabel="t('openregister', 'Organisation')"
						:disabled="loading"
						:loading="searchLoading"
						:options="organisationOptions"
						:filterable="true"
						:filterBy="filterOrganisation"
						:placeholder="
							t('openregister', 'Type to search for organisations')
						"
						labelOutside
						@search="handleOrganisationSearch">
						<template #option="{ name, description, users, isDefault }">
							<div class="organisation-option">
								<div class="organisation-header">
									<span class="organisation-name">{{ name }}</span>
									<span
										v-if="isDefault"
										class="badge badge-default"
										>{{ t('openregister', 'Default') }}</span
									>
								</div>
								<p
									v-if="description"
									class="organisation-description">
									{{ description }}
								</p>
								<span class="organisation-meta">{{
									t('openregister', '{count} members', {
										count: users?.length || 0,
									})
								}}</span>
							</div>
						</template>
						<template #selected-option="{ name }">
							<span>{{ name }}</span>
						</template>
					</NcSelect>
				</div>

				<!-- User Selection -->
				<div class="field-group">
					<label for="user-select">{{ t('openregister', 'User') }}</label>
					<NcSelect
						v-model="selectedUser"
						inputId="user-select"
						:inputLabel="t('openregister', 'User')"
						:disabled="loading"
						:loading="loadingUsers"
						:options="userOptions"
						:filterable="true"
						:placeholder="t('openregister', 'Type to search for users')"
						labelOutside
						@search="handleUserSearch">
						<template #option="{ id, displayName }">
							<div class="user-option">
								<span class="user-name">{{ displayName }}</span>
								<span class="user-id">{{ id }}</span>
							</div>
						</template>
						<template #selected-option="{ displayName }">
							<span>{{ displayName }}</span>
						</template>
					</NcSelect>
					<p class="helper-text">
						{{
							t(
								'openregister',
								'Defaults to current user. Select a different user if needed.',
							)
						}}
					</p>
				</div>

				<div class="info-help">
					<NcNoteCard type="info">
						<p>
							{{
								t(
									'openregister',
									'Select an organisation and user to add them as a member. Search for organisations by name.',
								)
							}}
						</p>
					</NcNoteCard>
				</div>
			</div>
		</div>

		<template #actions>
			<NcButton @click="closeModal">
				<template #icon>
					<Cancel :size="20" />
				</template>
				{{
					success
						? t('openregister', 'Close')
						: t('openregister', 'Cancel')
				}}
			</NcButton>
			<NcButton
				v-if="!success"
				variant="primary"
				:disabled="!selectedOrganisation || joining"
				@click="joinSelectedOrganisation">
				<template #icon>
					<NcLoadingIcon v-if="joining" :size="20" />
					<AccountPlus v-else :size="20" />
				</template>
				{{ t('openregister', 'Add User') }}
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
	NcSelect,
} from '@nextcloud/vue'
import AccountPlus from 'vue-material-design-icons/AccountPlus.vue'
import Cancel from 'vue-material-design-icons/Cancel.vue'
import { fetchUserOptions, organisationOption } from './joinOptions.js'

/**
 * A fetched organisation as an NcSelect option, keyed by its uuid.
 *
 * @param {object} organisation The organisation as the store returns it
 * @return {object} The option
 */
function preselectedOption(organisation) {
	return {
		id: organisation.uuid,
		uuid: organisation.uuid,
		name: organisation.name,
		label: organisation.name,
		description: organisation.description,
		users: organisation.users || [],
		isDefault: organisation.isDefault,
	}
}

export default {
	name: 'JoinOrganisation',
	components: {
		NcDialog,
		NcSelect,
		NcButton,
		NcLoadingIcon,
		NcNoteCard,
		// Icons
		AccountPlus,
		Cancel,
	},

	data() {
		return {
			selectedOrganisation: null,
			selectedUser: null,
			organisationOptions: [],
			userOptions: [],
			searchLoading: false,
			loadingUsers: false,
			success: false,
			loading: false,
			joining: false,
			error: false,
			joinedOrganisationName: '',
			searchTimeout: null,
			userSearchTimeout: null,
			closeModalTimeout: null,
		}
	},

	/**
	 * @spec exclude Vue lifecycle hook loading initial modal data
	 */
	async mounted() {
		// Set default user to current user
		this.setDefaultUser()

		// Load initial organisations list
		await this.loadInitialOrganisations()

		// Load initial users list
		await this.loadInitialUsers()

		// If organisation is pre-selected (passed via transferData), set it
		const transferData = navigationStore.getTransferData()
		if (transferData?.organisationUuid) {
			this.loadPreselectedOrganisation(transferData.organisationUuid)
			// Clear transfer data after using it
			navigationStore.clearTransferData()
		}
	},

	methods: {
		/**
		 * Get the current user information from Nextcloud
		 *
		 * @return {object|null} Current user object
		 * @spec openspec/specs/entity-management-modals/spec.md
		 */
		getCurrentUser() {
			if (window.OC && window.OC.getCurrentUser) {
				const user = window.OC.getCurrentUser()
				return {
					id: user.uid,
					displayName: user.displayName || user.uid,
				}
			}
			return null
		},

		/**
		 * Set default user to current user
		 *
		 * @spec exclude form-state default-user initializer
		 */
		setDefaultUser() {
			const currentUser = this.getCurrentUser()
			if (currentUser) {
				// Add label property for vue-select
				const userOption = {
					...currentUser,
					label: currentUser.displayName,
				}
				this.selectedUser = userOption
				this.userOptions = [userOption]
			}
		},

		/**
		 * Load initial list of organisations (first 20)
		 *
		 * @spec exclude form-state loader for organisation options
		 */
		async loadInitialOrganisations() {
			try {
				this.searchLoading = true
				// Load first 20 organisations (empty query returns all, paginated)
				const results = await organisationStore.searchOrganisations(
					'',
					20,
					0,
				)

				this.organisationOptions = results.map(organisationOption)
			} catch (error) {
				console.error('Error loading initial organisations:', error)
				this.organisationOptions = []
			} finally {
				this.searchLoading = false
			}
		},

		/**
		 * Load a preselected organisation
		 *
		 * @param {string} uuid - The UUID of the organisation to load
		 * @spec exclude form-state loader for preselected organisation
		 */
		async loadPreselectedOrganisation(uuid) {
			try {
				// Find the organisation in already loaded options, else fetch it
				const orgOption =
					this.organisationOptions.find(
						(org) => org.uuid === uuid || org.id === uuid,
					) || (await this.fetchPreselectedOption(uuid))

				// Select the organisation
				if (orgOption) {
					this.selectedOrganisation = orgOption
				}
			} catch (error) {
				console.error('Error loading preselected organisation:', error)
			}
		},

		/**
		 * Fetch an organisation that is not among the loaded options and add
		 * it to them, unless an option with its uuid is already there.
		 *
		 * @param {string} uuid The UUID of the organisation to load
		 * @return {Promise<object|undefined>} Its option, or undefined when the store has none
		 */
		async fetchPreselectedOption(uuid) {
			const organisation = await organisationStore.getOrganisation(uuid)
			if (!organisation) {
				return undefined
			}
			const orgOption = preselectedOption(organisation)
			if (!this.organisationOptions.some((o) => o.uuid === orgOption.uuid)) {
				this.organisationOptions.unshift(orgOption)
			}
			return orgOption
		},

		/**
		 * Handle organisation search with pagination
		 *
		 * @param {string} query - The query to search for
		 * @spec exclude debounced search UI handler delegating to organisationStore
		 */
		async handleOrganisationSearch(query) {
			// Clear previous timeout
			if (this.searchTimeout) {
				clearTimeout(this.searchTimeout)
			}

			// If empty query, reload initial list
			if (!query || query.trim().length === 0) {
				await this.loadInitialOrganisations()
				return
			}

			// Debounce search
			this.searchTimeout = setTimeout(async () => {
				if (query.trim().length < 2) {
					return
				}
				await this.searchOrganisations(query.trim())
			}, 500)
		},

		/**
		 * Offer the organisations matching a search (at most 20).
		 *
		 * @param {string} query The trimmed query
		 * @return {Promise<void>}
		 * @spec exclude debounced organisation-search UI handler
		 */
		async searchOrganisations(query) {
			this.searchLoading = true
			this.error = null

			try {
				// Search with limit of 20 results
				const results = await organisationStore.searchOrganisations(
					query,
					20,
					0,
				)

				this.organisationOptions = results.map(organisationOption)
			} catch (error) {
				console.error('Error searching organisations:', error)
				this.error = 'Failed to search organisations: ' + error.message
				this.organisationOptions = []
			} finally {
				this.searchLoading = false
			}
		},

		/**
		 * Load initial list of users (first 20)
		 *
		 * @spec exclude form-state loader for user options via OCS API
		 */
		async loadInitialUsers() {
			this.loadingUsers = true

			try {
				// Get list of users from Nextcloud API with pagination
				// Limit to first 20 users for performance
				this.userOptions = await fetchUserOptions(
					'/ocs/v2.php/cloud/users?limit=20&offset=0',
					'Failed to load users',
				)

				// Ensure current user is in the list and selected
				this.selectCurrentUser()
			} catch (error) {
				console.error('Error loading initial users:', error)
				this.setDefaultUser() // Fallback to current user only
			} finally {
				this.loadingUsers = false
			}
		},

		/**
		 * Handle user search with pagination
		 *
		 * @param {string} query - The query to search for
		 * @spec exclude debounced user-search UI handler via OCS API
		 */
		async handleUserSearch(query) {
			// Clear previous timeout
			if (this.userSearchTimeout) {
				clearTimeout(this.userSearchTimeout)
			}

			// If query is empty, reload initial users
			if (!query || query.trim().length === 0) {
				await this.loadInitialUsers()
				return
			}

			// Debounce search
			this.userSearchTimeout = setTimeout(async () => {
				if (query.trim().length < 2) {
					return
				}
				await this.searchUsers(query.trim())
			}, 500)
		},

		/**
		 * Offer the users matching a search (at most 20), always with the
		 * current user among them.
		 *
		 * @param {string} query The trimmed query
		 * @return {Promise<void>}
		 * @spec exclude debounced user-search UI handler via OCS API
		 */
		async searchUsers(query) {
			this.loadingUsers = true

			try {
				// Search for users via Nextcloud API with limit
				this.userOptions = await fetchUserOptions(
					`/ocs/v2.php/cloud/users?search=${encodeURIComponent(query)}&limit=20`,
					'Failed to search users',
				)

				// Always include current user in options
				this.offerCurrentUser()
			} catch (error) {
				console.error('Error searching users:', error)
				// Don't clear existing options on error
			} finally {
				this.loadingUsers = false
			}
		},

		/**
		 * The current user as a user option, if anyone is signed in.
		 *
		 * @return {object|null} The option
		 * @spec exclude form-state helper for the user select
		 */
		currentUserOption() {
			const currentUser = this.getCurrentUser()
			if (!currentUser) {
				return null
			}
			return { ...currentUser, label: currentUser.displayName }
		},

		/**
		 * Offer the current user among the user options, and select them.
		 *
		 * @return {void}
		 * @spec exclude form-state helper for the user select
		 */
		selectCurrentUser() {
			const currentUserOption = this.offerCurrentUser()
			if (currentUserOption) {
				// Keep current user as selected
				this.selectedUser = currentUserOption
			}
		},

		/**
		 * Offer the current user among the user options, if anyone is signed in.
		 *
		 * @return {object|null} The current user's option
		 * @spec exclude form-state helper for the user select
		 */
		offerCurrentUser() {
			const currentUserOption = this.currentUserOption()
			if (currentUserOption) {
				this.offerUser(currentUserOption)
			}
			return currentUserOption
		},

		/**
		 * Put a user first among the user options, unless it is offered already.
		 *
		 * @param {object} option The user option
		 * @return {void}
		 * @spec exclude form-state helper for the user select
		 */
		offerUser(option) {
			if (!this.userOptions.some((u) => u.id === option.id)) {
				this.userOptions.unshift(option)
			}
		},

		/**
		 * Filter organisation for local filtering
		 *
		 * @param {object} option - The organisation option to filter
		 * @param {string} label - The label of the organisation option
		 * @param {string} search - The search query
		 * @spec exclude client-side filter helper for organisation select
		 */
		filterOrganisation(option, label, search) {
			return (
				option.name?.toLowerCase().includes(search.toLowerCase())
				|| option.description?.toLowerCase().includes(search.toLowerCase())
			)
		},

		/**
		 * Join the selected organisation
		 *
		 * @spec exclude modal submit handler delegating to organisationStore.joinOrganisation
		 */
		async joinSelectedOrganisation() {
			if (this.refusedIncompleteJoin()) {
				return
			}

			this.joining = true
			this.error = null

			try {
				await this.joinOrganisation()
			} catch (error) {
				this.showJoinFailure(error)
			} finally {
				this.joining = false
			}
		},

		/**
		 * Log a failed join and say why it failed.
		 *
		 * @param {Error} error What went wrong
		 * @return {void}
		 * @spec exclude modal submit failure display
		 */
		showJoinFailure(error) {
			console.error('Error joining organisation:', error)
			this.error =
				error.message
				|| t('openregister', 'Failed to add user to organisation')
		},

		/**
		 * Refuse to join without an organisation or a user, saying which.
		 *
		 * @return {boolean} True when one is missing
		 * @spec exclude modal submit guard
		 */
		refusedIncompleteJoin() {
			if (!this.selectedOrganisation) {
				this.error = t('openregister', 'Please select an organisation')
				return true
			}

			if (!this.selectedUser) {
				this.error = t('openregister', 'Please select a user')
				return true
			}
			return false
		},

		/**
		 * Add the selected user to the selected organisation, say so and close
		 * after three seconds.
		 *
		 * @return {Promise<void>}
		 * @spec exclude modal submit handler delegating to organisationStore.joinOrganisation
		 */
		async joinOrganisation() {
			// Get userId - use selected user or default to current user
			const userId = this.selectedUser?.id || null

			// Check if the SELECTED USER (not logged-in user) is already a member
			// We can't easily check this client-side, so we'll rely on the backend to validate
			// and return an appropriate error message

			await organisationStore.joinOrganisation(
				this.selectedOrganisation.uuid,
				userId,
			)

			this.success = true
			this.joinedOrganisationName = this.selectedOrganisation.name

			this.closeModalTimeout = setTimeout(this.closeModal, 3000)
		},

		/**
		 * Close the modal
		 *
		 * @spec exclude modal close + form-state reset handler
		 */
		closeModal() {
			this.success = false
			this.error = null
			this.selectedOrganisation = null
			this.organisationOptions = []
			this.joinedOrganisationName = ''
			this.joining = false
			this.setDefaultUser() // Reset to current user
			navigationStore.setModal(false)
			clearTimeout(this.closeModalTimeout)
			clearTimeout(this.searchTimeout)
			clearTimeout(this.userSearchTimeout)
		},

		/**
		 * Handle dialog close event
		 *
		 * @spec exclude modal open/close UI handler
		 */
		handleDialogClose() {
			this.closeModal()
		},
	},
}
</script>

<style scoped lang="scss">
@use '../../styles/common.scss' as *;

/* JoinOrganisation-specific styles */
.selection-section {
	display: flex;
	flex-direction: column;
	gap: 20px;
}

@include field-group;

.field-group label {
	font-weight: 600;
	color: var(--color-text-dark);
	font-size: 14px;
}

.helper-text {
	font-size: 12px;
	color: var(--color-text-lighter);
	margin: 4px 0 0 0;
}

.info-help {
	margin-top: 8px;
}

/* Organisation option styling */
.organisation-option {
	display: flex;
	flex-direction: column;
	gap: 4px;
	padding: 4px 0;
}

.organisation-header {
	display: flex;
	align-items: center;
	gap: 8px;
}

.organisation-name {
	font-weight: 600;
	color: var(--color-text-dark);
}

.badge {
	display: inline-block;
	padding: 2px 8px;
	border-radius: 12px;
	font-size: 11px;
	font-weight: 600;
	text-transform: uppercase;
}

.badge-default {
	background: var(--color-warning);
	color: var(--color-primary-text);
}

.organisation-description {
	color: var(--color-text-lighter);
	font-size: 13px;
	line-height: 1.4;
	margin: 0;
}

.organisation-meta {
	font-size: 12px;
	color: var(--color-text-lighter);
}

/* User option styling */
.user-option {
	display: flex;
	flex-direction: column;
	gap: 2px;
	padding: 4px 0;
}

.user-name {
	font-weight: 500;
	color: var(--color-text-dark);
}

.user-id {
	font-size: 12px;
	color: var(--color-text-lighter);
}

@media screen and (max-width: 768px) {
	.selection-section {
		gap: 16px;
	}
}
</style>

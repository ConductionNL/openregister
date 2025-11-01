<script setup>
import { organisationStore, navigationStore } from '../../store/store.js'
</script>

<template>
	<NcDialog
		name="Add User to Organisation"
		size="normal"
		:can-close="true"
		@update:open="handleDialogClose">
		<NcNoteCard v-if="success" type="success">
			<p>Successfully added user to organisation: {{ joinedOrganisationName }}</p>
		</NcNoteCard>
		<NcNoteCard v-if="error" type="error">
			<p>{{ error }}</p>
		</NcNoteCard>

		<div v-if="!success">
			<div class="selection-section">
				<!-- Organisation Selection -->
				<div class="field-group">
					<label for="organisation-select">Organisation</label>
					<NcSelect
						v-model="selectedOrganisation"
						input-id="organisation-select"
						input-label="Organisation"
						:disabled="loading"
						:loading="searchLoading"
						:options="organisationOptions"
						:filterable="true"
						:filter-by="filterOrganisation"
						placeholder="Type to search for organisations"
						label-outside
						@search="handleOrganisationSearch">
						<template #option="{ name, description, userCount, isDefault }">
							<div class="organisation-option">
								<div class="organisation-header">
									<span class="organisation-name">{{ name }}</span>
									<span v-if="isDefault" class="badge badge-default">Default</span>
								</div>
								<p v-if="description" class="organisation-description">{{ description }}</p>
								<span class="organisation-meta">{{ userCount || 0 }} members</span>
							</div>
						</template>
						<template #selected-option="{ name }">
							<span>{{ name }}</span>
						</template>
					</NcSelect>
				</div>

				<!-- User Selection -->
				<div class="field-group">
					<label for="user-select">User</label>
					<NcSelect
						v-model="selectedUser"
						input-id="user-select"
						input-label="User"
						:disabled="loading"
						:loading="loadingUsers"
						:options="userOptions"
						:filterable="true"
						placeholder="Type to search for users"
						label-outside
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
					<p class="helper-text">Defaults to current user. Select a different user if needed.</p>
				</div>

				<div class="info-help">
					<NcNoteCard type="info">
						<p>Select an organisation and user to add them as a member. Search for organisations by name.</p>
					</NcNoteCard>
				</div>
			</div>
		</div>

		<template #actions>
			<NcButton @click="closeModal">
				<template #icon>
					<Cancel :size="20" />
				</template>
				{{ success ? 'Close' : 'Cancel' }}
			</NcButton>
			<NcButton
				v-if="!success"
				type="primary"
				:disabled="!selectedOrganisation || joining"
				@click="joinSelectedOrganisation">
				<template #icon>
					<NcLoadingIcon v-if="joining" :size="20" />
					<AccountPlus v-else :size="20" />
				</template>
				Add User
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import {
	NcButton,
	NcDialog,
	NcSelect,
	NcLoadingIcon,
	NcNoteCard,
	NcEmptyContent,
} from '@nextcloud/vue'

import AccountPlus from 'vue-material-design-icons/AccountPlus.vue'
import Cancel from 'vue-material-design-icons/Cancel.vue'

export default {
	name: 'JoinOrganisation',
	components: {
		NcDialog,
		NcSelect,
		NcButton,
		NcLoadingIcon,
		NcNoteCard,
		NcEmptyContent,
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
		 */
		async loadInitialOrganisations() {
			try {
				this.searchLoading = true
				// Load first 20 organisations (empty query returns all, paginated)
				const results = await organisationStore.searchOrganisations('', 20, 0)
				
				// Transform results to NcSelect format with all necessary fields
				this.organisationOptions = results.map(org => ({
					id: org.uuid || org.id,
					uuid: org.uuid || org.id,
					name: org.name,
					label: org.name,
					description: org.description,
					userCount: org.userCount,
					isDefault: org.isDefault,
				}))
			} catch (error) {
				console.error('Error loading initial organisations:', error)
				this.organisationOptions = []
			} finally {
				this.searchLoading = false
			}
		},
		/**
		 * Load a preselected organisation
		 */
		async loadPreselectedOrganisation(uuid) {
			try {
				// Find the organisation in already loaded options
				let orgOption = this.organisationOptions.find(org => org.uuid === uuid || org.id === uuid)
				
				// If not found in options, fetch it
				if (!orgOption) {
					const organisation = await organisationStore.getOrganisation(uuid)
					
					if (organisation) {
						orgOption = {
							id: organisation.uuid,
							uuid: organisation.uuid,
							name: organisation.name,
							label: organisation.name,
							description: organisation.description,
							userCount: organisation.userCount,
							isDefault: organisation.isDefault,
						}
						// Add to options if not already there
						if (!this.organisationOptions.some(o => o.uuid === orgOption.uuid)) {
							this.organisationOptions.unshift(orgOption)
						}
					}
				}
				
				// Select the organisation
				if (orgOption) {
					this.selectedOrganisation = orgOption
				}
			} catch (error) {
				console.error('Error loading preselected organisation:', error)
			}
		},
		/**
		 * Handle organisation search with pagination
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

				this.searchLoading = true
				this.error = null

				try {
					// Search with limit of 20 results
					const results = await organisationStore.searchOrganisations(query.trim(), 20, 0)
					
					// Transform results to NcSelect format with all necessary fields
					this.organisationOptions = results.map(org => ({
						id: org.uuid || org.id,
						uuid: org.uuid || org.id,
						name: org.name,
						label: org.name,
						description: org.description,
						userCount: org.userCount,
						isDefault: org.isDefault,
					}))
				} catch (error) {
					console.error('Error searching organisations:', error)
					this.error = 'Failed to search organisations: ' + error.message
					this.organisationOptions = []
				} finally {
					this.searchLoading = false
				}
			}, 500)
		},
		/**
		 * Load initial list of users (first 20)
		 */
		async loadInitialUsers() {
			this.loadingUsers = true

			try {
				// Get list of users from Nextcloud API with pagination
				// Limit to first 20 users for performance
				const response = await fetch(
					'/ocs/v2.php/cloud/users?limit=20&offset=0',
					{
						headers: {
							'OCS-APIRequest': 'true',
							'Accept': 'application/json',
						},
					},
				)

				if (!response.ok) {
					throw new Error('Failed to load users')
				}

				const data = await response.json()
				const users = data?.ocs?.data?.users || []

				// Transform to NcSelect format with label property
				this.userOptions = users.map(userId => ({
					id: userId,
					displayName: userId,
					label: userId, // Required by vue-select
				}))

				// Ensure current user is in the list and selected
				const currentUser = this.getCurrentUser()
				if (currentUser) {
					const currentUserOption = {
						...currentUser,
						label: currentUser.displayName,
					}
					
					if (!this.userOptions.some(u => u.id === currentUser.id)) {
						this.userOptions.unshift(currentUserOption)
					}
					
					// Keep current user as selected
					this.selectedUser = currentUserOption
				}
			} catch (error) {
				console.error('Error loading initial users:', error)
				this.setDefaultUser() // Fallback to current user only
			} finally {
				this.loadingUsers = false
			}
		},
		/**
		 * Handle user search with pagination
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

				this.loadingUsers = true

				try {
					// Search for users via Nextcloud API with limit
					const response = await fetch(
						`/ocs/v2.php/cloud/users?search=${encodeURIComponent(query.trim())}&limit=20`,
						{
							headers: {
								'OCS-APIRequest': 'true',
								'Accept': 'application/json',
							},
						},
					)

					if (!response.ok) {
						throw new Error('Failed to search users')
					}

					const data = await response.json()
					const users = data?.ocs?.data?.users || []

					// Transform to NcSelect format with label property
					this.userOptions = users.map(userId => ({
						id: userId,
						displayName: userId,
						label: userId, // Required by vue-select
					}))

					// Always include current user in options
					const currentUser = this.getCurrentUser()
					if (currentUser && !this.userOptions.some(u => u.id === currentUser.id)) {
						this.userOptions.unshift({
							...currentUser,
							label: currentUser.displayName,
						})
					}
				} catch (error) {
					console.error('Error searching users:', error)
					// Don't clear existing options on error
				} finally {
					this.loadingUsers = false
				}
			}, 500)
		},
		/**
		 * Filter organisation for local filtering
		 */
		filterOrganisation(option, label, search) {
			return (
				option.name?.toLowerCase().includes(search.toLowerCase()) ||
				option.description?.toLowerCase().includes(search.toLowerCase())
			)
		},
		/**
		 * Join the selected organisation
		 */
		async joinSelectedOrganisation() {
			if (!this.selectedOrganisation) {
				this.error = 'Please select an organisation'
				return
			}

			if (!this.selectedUser) {
				this.error = 'Please select a user'
				return
			}

			this.joining = true
			this.error = null

			try {
				// Get userId - use selected user or default to current user
				const userId = this.selectedUser?.id || null

				// Check if the SELECTED USER (not logged-in user) is already a member
				// We can't easily check this client-side, so we'll rely on the backend to validate
				// and return an appropriate error message

				await organisationStore.joinOrganisation(this.selectedOrganisation.uuid, userId)

				this.success = true
				this.joinedOrganisationName = this.selectedOrganisation.name

				this.closeModalTimeout = setTimeout(this.closeModal, 3000)
			} catch (error) {
				console.error('Error joining organisation:', error)
				this.error = error.message || 'Failed to add user to organisation'
			} finally {
				this.joining = false
			}
		},
		/**
		 * Close the modal
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
		 */
		handleDialogClose() {
			this.closeModal()
		},
	},
}
</script>

<style scoped>
/* JoinOrganisation-specific styles */
.selection-section {
	display: flex;
	flex-direction: column;
	gap: 20px;
}

.field-group {
	display: flex;
	flex-direction: column;
	gap: 8px;
}

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

<template>
	<NcDialog :name="t('openregister', 'Manage Organisation Roles')"
		size="normal"
		:can-close="true"
		@update:open="handleDialogClose">
		<NcNoteCard v-if="success" type="success">
			<p>{{ t('openregister', 'Roles updated successfully') }}</p>
		</NcNoteCard>
		<NcNoteCard v-if="error" type="error">
			<p>{{ error }}</p>
		</NcNoteCard>

		<div v-if="!success" class="roles-manager">
			<!-- Organisation Info -->
			<div class="organisation-info">
				<h3>{{ organisationItem.name }}</h3>
				<p class="info-text">
					{{ t('openregister', 'Select which Nextcloud groups are available for this organisation. Users in these groups will have access to organisation resources.') }}
				</p>
			</div>

			<!-- Selected Roles -->
			<div v-if="selectedRoles.length > 0" class="selected-roles-section">
				<h4>{{ t('openregister', 'Selected Groups') }} ({{ selectedRoles.length }})</h4>
				<div class="roles-list">
					<div v-for="role in selectedRoles" :key="role.id" class="role-chip">
						<AccountGroup :size="16" />
						<span class="role-name">{{ role.name }}</span>
						<NcButton type="tertiary"
							:aria-label="t('openregister', 'Remove group')"
							@click="removeRole(role)">
							<template #icon>
								<Close :size="16" />
							</template>
						</NcButton>
					</div>
				</div>
			</div>

			<!-- Add Roles -->
			<div class="add-roles-section">
				<h4>{{ t('openregister', 'Add Groups') }}</h4>
				<NcSelect
					v-model="roleToAdd"
					:options="availableGroupOptions"
					:placeholder="t('openregister', 'Select a Nextcloud group to add')"
					:loading="loadingGroups"
					:filterable="true"
					label-outside
					input-label="Nextcloud Groups"
					@input="addRole">
					<template #option="{ id, name, userCount }">
						<div class="group-option">
							<AccountGroup :size="20" />
							<div class="group-info">
								<span class="group-name">{{ name }}</span>
								<span class="group-meta">{{ userCount }} {{ t('openregister', 'members') }}</span>
							</div>
						</div>
					</template>
				</NcSelect>
			</div>
		</div>

		<template #actions>
			<NcButton @click="closeModal">
				<template #icon>
					<Cancel :size="20" />
				</template>
				{{ success ? t('openregister', 'Close') : t('openregister', 'Cancel') }}
			</NcButton>
			<NcButton v-if="!success"
				:disabled="loading || !hasChanges"
				type="primary"
				@click="saveRoles()">
				<template #icon>
					<NcLoadingIcon v-if="loading" :size="20" />
					<ContentSaveOutline v-else :size="20" />
				</template>
				{{ t('openregister', 'Save Roles') }}
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

import ContentSaveOutline from 'vue-material-design-icons/ContentSaveOutline.vue'
import Cancel from 'vue-material-design-icons/Cancel.vue'
import Close from 'vue-material-design-icons/Close.vue'
import AccountGroup from 'vue-material-design-icons/AccountGroup.vue'

import { organisationStore, navigationStore } from '../../store/store.js'
import { generateUrl } from '@nextcloud/router'
import axios from '@nextcloud/axios'

/**
 * ManageOrganisationRoles
 * @module Components
 * @package OpenRegister
 * 
 * Modal component for managing organisation roles using Nextcloud groups
 * 
 * @author   Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version  GIT: <git_id>
 * @link     https://www.OpenRegister.nl
 */
export default {
	name: 'ManageOrganisationRoles',
	components: {
		NcDialog,
		NcButton,
		NcLoadingIcon,
		NcNoteCard,
		NcSelect,
		// Icons
		ContentSaveOutline,
		Cancel,
		Close,
		AccountGroup,
	},
	data() {
		return {
			organisationItem: {
				name: '',
				roles: [],
			},
			selectedRoles: [],
			originalRoles: [],
			availableGroups: [],
			roleToAdd: null,
			loadingGroups: false,
			loading: false,
			success: false,
			error: null,
			closeModalTimeout: null,
		}
	},
	computed: {
		/**
		 * Get available groups that haven't been selected yet
		 * 
		 * @return {Array} Available groups
		 */
		availableGroupOptions() {
			const selectedIds = this.selectedRoles.map(r => r.id)
			return this.availableGroups
				.filter(group => !selectedIds.includes(group.id))
				.map(group => ({
					...group,
					label: group.name,
				}))
		},

		/**
		 * Check if there are unsaved changes
		 * 
		 * @return {boolean} True if there are changes
		 */
		hasChanges() {
			const originalIds = this.originalRoles.map(r => r.id).sort()
			const currentIds = this.selectedRoles.map(r => r.id).sort()
			return JSON.stringify(originalIds) !== JSON.stringify(currentIds)
		},
	},
	mounted() {
		this.initializeOrganisationItem()
		this.loadNextcloudGroups()
	},
	methods: {
		/**
		 * Initialize organisation data
		 * 
		 * @return {void}
		 */
		initializeOrganisationItem() {
			if (organisationStore.organisationItem?.uuid) {
				this.organisationItem = {
					...this.organisationItem,
					...organisationStore.organisationItem,
				}
				
				// Initialize selected roles from organisation
				this.selectedRoles = Array.isArray(this.organisationItem.roles) 
					? [...this.organisationItem.roles] 
					: []
				this.originalRoles = [...this.selectedRoles]
			}
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
				const response = await axios.get(
					generateUrl('/ocs/v1.php/cloud/groups?format=json'),
					{
						headers: {
							'OCS-APIRequest': 'true',
							'Accept': 'application/json',
						},
					}
				)

				// v1 API returns groups as a simple array of group IDs
				if (response.data?.ocs?.data?.groups) {
					this.availableGroups = response.data.ocs.data.groups.map(groupId => ({
						id: groupId,
						name: groupId,
						userCount: 0, // v1 API doesn't provide user count in list
					}))
				}
			} catch (error) {
				console.error('Error loading Nextcloud groups:', error)
				this.error = this.t('openregister', 'Failed to load Nextcloud groups')
			} finally {
				this.loadingGroups = false
			}
		},

		/**
		 * Add a role/group to the organisation
		 * 
		 * @param {object} group - The group to add
		 * @return {void}
		 */
		addRole(group) {
			if (group && !this.selectedRoles.find(r => r.id === group.id)) {
				this.selectedRoles.push({
					id: group.id,
					name: group.name,
					userCount: group.userCount,
				})
			}
			this.roleToAdd = null
		},

		/**
		 * Remove a role/group from the organisation
		 * 
		 * @param {object} role - The role to remove
		 * @return {void}
		 */
		removeRole(role) {
			this.selectedRoles = this.selectedRoles.filter(r => r.id !== role.id)
		},

		/**
		 * Save the roles to the organisation
		 * 
		 * @return {Promise<void>}
		 */
		async saveRoles() {
			this.loading = true
			this.error = null

			try {
				await organisationStore.saveOrganisation({
					...this.organisationItem,
					roles: this.selectedRoles,
				})

				this.success = true
				this.originalRoles = [...this.selectedRoles]

				// Auto-close after 2 seconds
				this.closeModalTimeout = setTimeout(this.closeModal, 2000)

			} catch (error) {
				console.error('Error saving roles:', error)
				this.error = error.message || this.t('openregister', 'Failed to save roles')
			} finally {
				this.loading = false
			}
		},

		/**
		 * Close the modal
		 * 
		 * @return {void}
		 */
		closeModal() {
			this.success = false
			this.error = null
			navigationStore.setModal(false)
			navigationStore.setDialog(false)
			clearTimeout(this.closeModalTimeout)
		},

		/**
		 * Handle dialog close
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
.roles-manager {
	display: flex;
	flex-direction: column;
	gap: 24px;
	padding: 8px 0;
}

.organisation-info h3 {
	margin: 0 0 8px 0;
	font-size: 18px;
	font-weight: 600;
	color: var(--color-main-text);
}

.info-text {
	margin: 0;
	font-size: 14px;
	color: var(--color-text-maxcontrast);
	line-height: 1.4;
}

.selected-roles-section,
.add-roles-section {
	display: flex;
	flex-direction: column;
	gap: 12px;
}

.selected-roles-section h4,
.add-roles-section h4 {
	margin: 0;
	font-size: 14px;
	font-weight: 600;
	color: var(--color-main-text);
}

.roles-list {
	display: flex;
	flex-wrap: wrap;
	gap: 8px;
}

.role-chip {
	display: flex;
	align-items: center;
	gap: 8px;
	padding: 6px 8px 6px 12px;
	background: var(--color-primary-element-light);
	border: 1px solid var(--color-primary-element);
	border-radius: 16px;
	font-size: 14px;
	color: var(--color-primary-element-text);
}

.role-chip .material-design-icon {
	color: var(--color-primary-element);
}

.role-name {
	font-weight: 500;
}

.role-chip button {
	min-width: auto !important;
	min-height: auto !important;
	padding: 2px !important;
}

.group-option {
	display: flex;
	align-items: center;
	gap: 12px;
	width: 100%;
}

.group-info {
	display: flex;
	flex-direction: column;
	gap: 2px;
	flex: 1;
}

.group-name {
	font-weight: 500;
	color: var(--color-main-text);
}

.group-meta {
	font-size: 12px;
	color: var(--color-text-maxcontrast);
}
</style>


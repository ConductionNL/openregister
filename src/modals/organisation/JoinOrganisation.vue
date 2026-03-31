<script setup>
import { translate as t } from '@nextcloud/l10n'
import { organisationStore, navigationStore } from '../../store/store.js'
</script>

<template>
	<CnFormDialog
		ref="formDialog"
		:fields="fields"
		dialog-title="Add User to Organisation"
		confirm-label="Add User"
		@confirm="onConfirm"
		@close="onClose">
		<!-- Organisation option: name, badge, description, member count -->
		<template #field-organisation-option="{ name, description, users, isDefault }">
			<div class="organisation-option">
				<div class="organisation-header">
					<span class="organisation-name">{{ name }}</span>
					<span v-if="isDefault" class="badge badge-default">Default</span>
				</div>
				<p v-if="description" class="organisation-description">
					{{ description }}
				</p>
				<span class="organisation-meta">{{ (users?.length || 0) }} members</span>
			</div>
		</template>
		<template #field-organisation-selected-option="{ name }">
			<span>{{ name }}</span>
		</template>

		<!-- User option: display name + id -->
		<template #field-user-option="{ id, displayName }">
			<div class="user-option">
				<span class="user-name">{{ displayName }}</span>
				<span class="user-id">{{ id }}</span>
			</div>
		</template>
		<template #field-user-selected-option="{ displayName }">
			<span>{{ displayName }}</span>
		</template>

		<!-- Info note below the form -->
		<template #after-fields>
			<NcNoteCard type="info">
				<p>Select an organisation and user to add them as a member. Search for organisations by name.</p>
			</NcNoteCard>
		</template>
	</CnFormDialog>
</template>

<script>
import { NcNoteCard } from '@nextcloud/vue'
import { CnFormDialog } from '@conduction/nextcloud-vue'

export default {
	name: 'JoinOrganisation',
	components: {
		CnFormDialog,
		NcNoteCard,
	},
	data() {
		return {
			fields: [
				{
					key: 'organisation',
					widget: 'select',
					label: 'Organisation',
					required: true,
					description: 'Type to search for organisations',
					enum: async (query) => {
						const results = await organisationStore.searchOrganisations(query, 20, 0)
						return results.map(org => ({
							label: org.name,
							id: org.uuid || org.id,
							uuid: org.uuid || org.id,
							name: org.name,
							description: org.description,
							users: org.users || [],
							isDefault: org.isDefault,
						}))
					},
					debounce: 500,
				},
				{
					key: 'user',
					widget: 'select',
					label: 'User',
					required: true,
					description: 'Defaults to current user. Select a different user if needed.',
					enum: this.fetchUsers,
					debounce: 500,
				},
			],
		}
	},
	async mounted() {
		// Pre-select current user
		const currentUser = this.getCurrentUser()
		if (currentUser) {
			this.$refs.formDialog.updateField('user', currentUser)
		}

		// If organisation is pre-selected (passed via transferData), load and select it
		const transferData = navigationStore.getTransferData()
		if (transferData?.organisationUuid) {
			navigationStore.clearTransferData()
			try {
				const organisation = await organisationStore.getOne(transferData.organisationUuid)
				if (organisation) {
					this.$refs.formDialog.updateField('organisation', {
						label: organisation.name,
						id: organisation.uuid,
						uuid: organisation.uuid,
						name: organisation.name,
						description: organisation.description,
						users: organisation.users || [],
						isDefault: organisation.isDefault,
					})
				}
			} catch (error) {
				console.error('Error loading preselected organisation:', error)
			}
		}
	},
	methods: {
		/**
		 * Get the current user information from Nextcloud
		 *
		 * @return {object|null} Current user object with label for NcSelect
		 */
		getCurrentUser() {
			if (window.OC && window.OC.getCurrentUser) {
				const user = window.OC.getCurrentUser()
				return {
					id: user.uid,
					displayName: user.displayName || user.uid,
					label: user.displayName || user.uid,
				}
			}
			return null
		},
		async fetchUsers(query) {
			const response = await fetch(
				`/ocs/v2.php/cloud/users?search=${encodeURIComponent(query)}&limit=20`,
				{
					headers: {
						'OCS-APIRequest': 'true',
						Accept: 'application/json',
					},
				},
			)
			if (!response.ok) {
				throw new Error('Failed to search users')
			}
			const data = await response.json()
			const users = (data?.ocs?.data?.users || []).map(userId => ({
				id: userId,
				displayName: userId,
				label: userId,
			}))

			// Ensure current user is always in the list
			const currentUser = this.getCurrentUser()
			if (currentUser && !users.some(u => u.id === currentUser.id)) {
				users.unshift(currentUser)
			}

			return users
		},
		/**
		 * Handle form confirmation — join the organisation
		 *
		 * @param {object} formData Form data with organisation and user objects
		 */
		async onConfirm(formData) {
			try {
				const userId = formData.user?.id || null
				await organisationStore.joinOrganisation(formData.organisation.uuid, userId)
				this.$refs.formDialog.setResult({ success: true })
			} catch (error) {
				console.error('Error joining organisation:', error)
				this.$refs.formDialog.setResult({
					error: error.message || 'Failed to add user to organisation',
				})
			}
		},
		/**
		 * Handle dialog close
		 */
		onClose() {
			navigationStore.setModal(false)
		},
	},
}
</script>

<style scoped>
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
</style>

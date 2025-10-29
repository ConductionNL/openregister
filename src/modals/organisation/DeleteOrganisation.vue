<script setup>
import { organisationStore, navigationStore } from '../../store/store.js'
</script>

<template>
	<NcDialog
		name="Delete Organisation"
		size="normal"
		:can-close="false">
		<p v-if="!success && canDelete">
			Are you sure you want to permanently delete <b>{{ organisationStore.organisationItem?.name }}</b>? This action cannot be undone.
		</p>
		<p v-if="!success && !canDelete && organisationStore.organisationItem?.isDefault">
			Cannot delete the default organisation. The default organisation is required for the system to function properly.
		</p>
		<p v-if="!success && !canDelete && !isOwner">
			You can only delete organisations that you own. You are not the owner of <b>{{ organisationStore.organisationItem?.name }}</b>.
		</p>
		<p v-if="!success && !canDelete && hasMembers">
			This organisation has {{ memberCount }} members. Please remove all members before deleting the organisation.
		</p>
		<p v-if="!success && !canDelete && isActiveOrganisation">
			Cannot delete your currently active organisation. Please switch to another organisation first.
		</p>

		<NcNoteCard v-if="!success && canDelete" type="warning">
			<p><strong>Warning:</strong> This will permanently delete:</p>
			<ul>
				<li>The organisation and all its metadata</li>
				<li>All registers, schemas, and objects belonging to this organisation</li>
				<li>All audit trails and search history for this organisation</li>
			</ul>
		</NcNoteCard>

		<NcNoteCard v-if="success" type="success">
			<p>Organisation successfully deleted</p>
		</NcNoteCard>

		<NcNoteCard v-if="error" type="error">
			<p>{{ error }}</p>
		</NcNoteCard>

		<template #actions>
			<NcButton @click="closeDialog">
				<template #icon>
					<Cancel :size="20" />
				</template>
				{{ success ? 'Close' : 'Cancel' }}
			</NcButton>
			<NcButton
				v-if="!success"
				:disabled="loading || !canDelete"
				type="error"
				@click="deleteOrganisation()">
				<template #icon>
					<NcLoadingIcon v-if="loading" :size="20" />
					<TrashCanOutline v-if="!loading" :size="20" />
				</template>
				Delete Organisation
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
} from '@nextcloud/vue'

import Cancel from 'vue-material-design-icons/Cancel.vue'
import TrashCanOutline from 'vue-material-design-icons/TrashCanOutline.vue'

export default {
	name: 'DeleteOrganisation',
	components: {
		NcDialog,
		NcButton,
		NcLoadingIcon,
		NcNoteCard,
		// Icons
		TrashCanOutline,
		Cancel,
	},
	data() {
		return {
			success: false,
			loading: false,
			error: false,
			closeModalTimeout: null,
		}
	},
	computed: {
		isOwner() {
			// Check if current user is the owner of the organisation
			const currentUser = this.getCurrentUser()
			return organisationStore.organisationItem?.owner === currentUser
		},
		hasMembers() {
			// Check if organisation has members (excluding the owner)
			return (organisationStore.organisationItem?.userCount || 0) > 1
		},
		memberCount() {
			return organisationStore.organisationItem?.userCount || 0
		},
		isActiveOrganisation() {
			// Check if this is the currently active organisation
			return organisationStore.userStats.active
				   && organisationStore.userStats.active.uuid === organisationStore.organisationItem?.uuid
		},
		canDelete() {
			// Can only delete if:
			// 1. Not the default organisation
			// 2. User is the owner
			// 3. No other members (or only the owner)
			// 4. Not the currently active organisation
			return !organisationStore.organisationItem?.isDefault
				   && this.isOwner
				   && !this.hasMembers
				   && !this.isActiveOrganisation
		},
	},
	methods: {
		getCurrentUser() {
			// Implementation would depend on how you get current user
			return 'current-user' // Placeholder
		},
		closeDialog() {
			navigationStore.setModal(false)
			clearTimeout(this.closeModalTimeout)
			this.success = false
			this.loading = false
			this.error = false
		},
		async deleteOrganisation() {
			this.loading = true
			this.error = null

			try {
				const { response } = await organisationStore.deleteOrganisation({
					...organisationStore.organisationItem,
				})

				this.success = response.ok

				if (response.ok) {
					// Navigate back to organisations list
					this.$router.push('/organisation')
					this.closeModalTimeout = setTimeout(this.closeDialog, 2000)
				}

			} catch (error) {
				console.error('Error deleting organisation:', error)
				this.success = false
				this.error = error.message || 'An error occurred while deleting the organisation'
			} finally {
				this.loading = false
			}
		},
	},
}
</script>

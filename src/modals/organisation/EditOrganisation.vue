<script setup>
import { organisationStore, navigationStore } from '../../store/store.js'
</script>

<template>
	<NcDialog :name="organisationStore.organisationItem?.uuid && !createAnother ? 'Edit Organisation' : 'Create Organisation'"
		size="large"
		:can-close="true"
		@update:open="handleDialogClose">
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
					<BTab title="Basic Information" active>
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
						</div>
					</BTab>

					<BTab title="Settings">
						<div class="form-editor">
							<NcCheckboxRadioSwitch
								v-if="organisationItem.uuid && canEditDefaultFlag"
								:disabled="loading"
								:checked.sync="organisationItem.isDefault">
								Default Organisation
							</NcCheckboxRadioSwitch>

							<NcNoteCard v-if="organisationItem.isDefault" type="info">
								<p>New users without specific organisation membership will be automatically added to this organisation</p>
							</NcNoteCard>

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

					<BTab title="Resource Allocation">
						<div class="form-editor">
							<NcNoteCard type="info">
								<p><strong>Resource Quotas</strong></p>
								<p>Set limits for storage, bandwidth, and API usage. Use 0 for unlimited resources.</p>
							</NcNoteCard>

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

					<BTab title="Groups">
						<div class="form-editor">
							<NcNoteCard type="info">
								<p><strong>Nextcloud Group Access</strong></p>
								<p>Select which Nextcloud groups have access to this organisation</p>
							</NcNoteCard>

							<!-- Group selection would go here -->
							<p style="color: var(--color-text-lighter); font-style: italic;">
								Group management feature coming soon
							</p>
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
	</NcDialog>
</template>

<script>
import {
	NcButton,
	NcDialog,
	NcTextField,
	NcTextArea,
	NcLoadingIcon,
	NcNoteCard,
	NcCheckboxRadioSwitch,
} from '@nextcloud/vue'
import { BTabs, BTab } from 'bootstrap-vue'

import ContentSaveOutline from 'vue-material-design-icons/ContentSaveOutline.vue'
import Cancel from 'vue-material-design-icons/Cancel.vue'
import Plus from 'vue-material-design-icons/Plus.vue'

export default {
	name: 'EditOrganisation',
	components: {
		NcDialog,
		NcTextField,
		NcTextArea,
		NcButton,
		NcLoadingIcon,
		NcNoteCard,
		NcCheckboxRadioSwitch,
		BTabs,
		BTab,
		// Icons
		ContentSaveOutline,
		Cancel,
		Plus,
	},
	data() {
		return {
			activeTab: 0,
			organisationItem: {
				name: '',
				slug: '',
				description: '',
				isDefault: false,
				active: true,
				storageQuota: 0,
				bandwidthQuota: 0,
				requestQuota: 0,
			},
			createAnother: false,
			success: false,
			loading: false,
			error: false,
			closeModalTimeout: null,
		}
	},
	computed: {
		canEditDefaultFlag() {
			// Only system admin or already default organisation can edit default flag
			// This is a simplified check - in reality would need proper permission checks
			return this.organisationItem.isDefault || this.getCurrentUser() === 'admin'
		},
		storageQuotaMB() {
			if (!this.organisationItem.storageQuota) return 0
			return Math.round(this.organisationItem.storageQuota / (1024 * 1024))
		},
		bandwidthQuotaMB() {
			if (!this.organisationItem.bandwidthQuota) return 0
			return Math.round(this.organisationItem.bandwidthQuota / (1024 * 1024))
		},
	},
	mounted() {
		this.initializeOrganisationItem()
	},
	methods: {
		initializeOrganisationItem() {
			if (organisationStore.organisationItem?.uuid) {
				this.organisationItem = {
					...this.organisationItem, // Keep default structure
					...organisationStore.organisationItem,
					active: organisationStore.organisationItem.active ?? true,
				}
			}
		},
		getCurrentUser() {
			// Implementation would depend on how you get current user
			return 'current-user' // Placeholder
		},
		updateStorageQuota(value) {
			// Convert MB to bytes (0 = unlimited)
			const mbValue = value ? parseInt(value) : 0
			this.organisationItem.storageQuota = mbValue * 1024 * 1024
		},
		updateBandwidthQuota(value) {
			// Convert MB to bytes (0 = unlimited)
			const mbValue = value ? parseInt(value) : 0
			this.organisationItem.bandwidthQuota = mbValue * 1024 * 1024
		},
		updateRequestQuota(value) {
			// 0 = unlimited
			this.organisationItem.requestQuota = value ? parseInt(value) : 0
		},
		closeModal() {
			this.success = false
			this.error = null
			this.createAnother = false
			this.activeTab = 0
			navigationStore.setModal(false)
			navigationStore.setDialog(false)
			clearTimeout(this.closeModalTimeout)
		},
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
							isDefault: false,
							active: true,
							storageQuota: null,
							bandwidthQuota: null,
							requestQuota: null,
						}
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
		handleDialogClose() {
			this.closeModal()
		},
	},
}
</script>

<style scoped>
/* EditOrganisation-specific styles */
.tabContainer {
	margin-top: 20px;
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
</style>

<script setup>
import { applicationStore, organisationStore, navigationStore } from '../../store/store.js'
</script>

<template>
	<NcDialog :name="applicationStore.applicationItem?.uuid ? 'Edit Application' : 'Create Application'"
		size="large"
		:can-close="true"
		@update:open="handleDialogClose">
		<NcNoteCard v-if="success" type="success">
			<p>Application successfully {{ applicationStore.applicationItem?.uuid ? 'updated' : 'created' }}</p>
		</NcNoteCard>
		<NcNoteCard v-if="error" type="error">
			<p>{{ error }}</p>
		</NcNoteCard>
		<div v-if="!success">
			<!-- Tabs -->
			<div class="tabContainer">
				<BTabs v-model="activeTab" content-class="mt-3" justified>
					<BTab title="Basic Information" active>
						<div class="form-editor">
							<NcTextField
								:disabled="loading"
								label="Name *"
								:value.sync="applicationItem.name"
								:error="!applicationItem.name.trim()"
								placeholder="Enter application name" />

							<NcTextArea
								:disabled="loading"
								label="Description"
								:value.sync="applicationItem.description"
								placeholder="Enter application description (optional)"
								:rows="4" />
						</div>
					</BTab>

					<BTab title="Organisation">
						<div class="form-editor">
							<NcNoteCard type="info">
								<p><strong>Organisation Assignment</strong></p>
								<p>Assign this application to an organisation for access control and resource management</p>
							</NcNoteCard>

							<NcSelect
								v-model="selectedOrganisation"
								:disabled="loading"
								:options="organisationOptions"
								input-label="Organisation"
								label="name"
								track-by="id"
								placeholder="Select organisation (optional)"
								@input="updateOrganisation">
								<template #option="{ name, description }">
									<div class="option-content">
										<span class="option-title">{{ name }}</span>
										<span v-if="description" class="option-description">{{ description }}</span>
									</div>
								</template>
							</NcSelect>
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
								:value="applicationItem.requestQuota || 0"
								@update:value="updateRequestQuota" />
						</div>
					</BTab>

					<BTab title="Groups">
						<div class="form-editor">
							<NcNoteCard type="info">
								<p><strong>Nextcloud Group Access</strong></p>
								<p>Select which Nextcloud groups have access to this application</p>
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
			<NcButton @click="closeModal">
				<template #icon>
					<Cancel :size="20" />
				</template>
				{{ success ? 'Close' : 'Cancel' }}
			</NcButton>
			<NcButton v-if="!success"
				:disabled="loading || !applicationItem.name.trim()"
				type="primary"
				@click="saveApplication()">
				<template #icon>
					<NcLoadingIcon v-if="loading" :size="20" />
					<ContentSaveOutline v-if="!loading && applicationStore.applicationItem?.uuid" :size="20" />
					<Plus v-if="!loading && !applicationStore.applicationItem?.uuid" :size="20" />
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
	NcTextField,
	NcTextArea,
	NcSelect,
	NcLoadingIcon,
	NcNoteCard,
} from '@nextcloud/vue'
import { BTabs, BTab } from 'bootstrap-vue'

import ContentSaveOutline from 'vue-material-design-icons/ContentSaveOutline.vue'
import Cancel from 'vue-material-design-icons/Cancel.vue'
import Plus from 'vue-material-design-icons/Plus.vue'

export default {
	name: 'EditApplication',
	components: {
		NcDialog,
		NcTextField,
		NcTextArea,
		NcSelect,
		NcButton,
		NcLoadingIcon,
		NcNoteCard,
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
			applicationItem: {
				name: '',
				description: '',
				organisation: null,
				storageQuota: 0,
				bandwidthQuota: 0,
				requestQuota: 0,
			},
			selectedOrganisation: null,
			success: false,
			loading: false,
			error: false,
			closeModalTimeout: null,
		}
	},
	computed: {
		organisationOptions() {
			return organisationStore.organisationList.map(org => ({
				id: org.id,
				name: org.name,
				description: org.description || '',
			}))
		},
		storageQuotaMB() {
			if (!this.applicationItem.storageQuota) return 0
			return Math.round(this.applicationItem.storageQuota / (1024 * 1024))
		},
		bandwidthQuotaMB() {
			if (!this.applicationItem.bandwidthQuota) return 0
			return Math.round(this.applicationItem.bandwidthQuota / (1024 * 1024))
		},
	},
	async mounted() {
		await this.fetchOrganisations()
		this.initializeApplicationItem()
	},
	methods: {
		async fetchOrganisations() {
			await organisationStore.refreshOrganisationList()
		},
		initializeApplicationItem() {
			if (applicationStore.applicationItem?.uuid) {
				this.applicationItem = {
					...this.applicationItem, // Keep default structure
					...applicationStore.applicationItem,
				}

				// Load existing organisation selection
				if (this.applicationItem.organisation) {
					const org = organisationStore.organisationList.find(o => o.id === this.applicationItem.organisation)
					if (org) {
						this.selectedOrganisation = {
							id: org.id,
							name: org.name,
							description: org.description || '',
						}
					}
				}
			}
		},
		updateOrganisation(value) {
			this.applicationItem.organisation = value?.id || null
		},
		updateStorageQuota(value) {
			// Convert MB to bytes (0 = unlimited)
			const mbValue = value ? parseInt(value) : 0
			this.applicationItem.storageQuota = mbValue * 1024 * 1024
		},
		updateBandwidthQuota(value) {
			// Convert MB to bytes (0 = unlimited)
			const mbValue = value ? parseInt(value) : 0
			this.applicationItem.bandwidthQuota = mbValue * 1024 * 1024
		},
		updateRequestQuota(value) {
			// 0 = unlimited
			this.applicationItem.requestQuota = value ? parseInt(value) : 0
		},
		closeModal() {
			this.success = false
			this.error = null
			this.selectedOrganisation = null
			this.activeTab = 0
			navigationStore.setModal(false)
			navigationStore.setDialog(false)
			clearTimeout(this.closeModalTimeout)
		},
		async saveApplication() {
			this.loading = true
			this.error = null

			// Validate required fields
			if (!this.applicationItem.name.trim()) {
				this.error = 'Application name is required'
				this.loading = false
				return
			}

			try {
				const { response } = await applicationStore.saveApplication({
					...this.applicationItem,
				})

				this.success = response.ok
				this.error = false

				if (response.ok) {
					this.closeModalTimeout = setTimeout(this.closeModal, 2000)
				}

			} catch (error) {
				this.success = false
				this.error = error.message || 'An error occurred while saving the application'
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
/* EditApplication-specific styles */
.tabContainer {
	margin-top: 20px;
}

.form-editor {
	display: flex;
	flex-direction: column;
	gap: 16px;
	padding: 16px 0;
}

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
</style>

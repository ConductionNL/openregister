<script setup>
import { organisationStore, navigationStore } from '../../store/store.js'
</script>

<template>
	<NcDialog :name="organisationStore.organisationItem?.uuid && !createAnother ? 'Edit Organisation' : 'Create Organisation'"
		size="normal"
		:can-close="true"
		@update:open="handleDialogClose">
		<NcNoteCard v-if="success" type="success">
			<p>Organisation successfully {{ organisationStore.organisationItem?.uuid && !createAnother ? 'updated' : 'created' }}</p>
		</NcNoteCard>
		<NcNoteCard v-if="error" type="error">
			<p>{{ error }}</p>
		</NcNoteCard>
		<div v-if="createAnother || !success">
			<!-- Metadata Display -->
			<div v-if="organisationItem.uuid" class="detail-grid">
				<div class="detail-item id-card">
					<div class="id-card-header">
						<span class="detail-label">UUID:</span>
						<NcButton class="copy-button" @click="copyToClipboard(organisationItem.uuid)">
							<template #icon>
								<Check v-if="isCopied" :size="20" />
								<ContentCopy v-else :size="20" />
							</template>
							{{ isCopied ? 'Copied' : 'Copy' }}
						</NcButton>
					</div>
					<span class="detail-value">{{ organisationItem.uuid }}</span>
				</div>
				<div v-if="organisationItem.created" class="detail-item">
					<span class="detail-label">Created:</span>
					<span class="detail-value">{{ new Date(organisationItem.created).toLocaleString() }}</span>
				</div>
				<div v-if="organisationItem.updated" class="detail-item">
					<span class="detail-label">Updated:</span>
					<span class="detail-value">{{ new Date(organisationItem.updated).toLocaleString() }}</span>
				</div>
				<div v-if="organisationItem.owner" class="detail-item">
					<span class="detail-label">Owner:</span>
					<span class="detail-value">{{ organisationItem.owner }}</span>
				</div>
				<div v-if="organisationItem.userCount" class="detail-item">
					<span class="detail-label">Members:</span>
					<span class="detail-value">{{ organisationItem.userCount }}</span>
				</div>
			</div>

			<!-- Organisation Form -->
			<div class="form-editor">
				<NcTextField
					:disabled="loading"
					label="Organisation Name *"
					:value.sync="organisationItem.name"
					:error="!organisationItem.name.trim()"
					placeholder="Enter organisation name" />

				<NcTextArea
					:disabled="loading"
					label="Description"
					:value.sync="organisationItem.description"
					placeholder="Optional description for the organisation"
					:rows="3" />

				<NcCheckboxRadioSwitch
					v-if="organisationItem.uuid && canEditDefaultFlag"
					:disabled="loading"
					:checked.sync="organisationItem.isDefault">
					Default Organisation
				</NcCheckboxRadioSwitch>

				<NcNoteCard v-if="organisationItem.isDefault" type="info">
					<p>This is the default organisation. New users without specific organisation membership will be automatically added to this organisation.</p>
				</NcNoteCard>
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

import ContentSaveOutline from 'vue-material-design-icons/ContentSaveOutline.vue'
import Cancel from 'vue-material-design-icons/Cancel.vue'
import Plus from 'vue-material-design-icons/Plus.vue'
import ContentCopy from 'vue-material-design-icons/ContentCopy.vue'
import Check from 'vue-material-design-icons/Check.vue'

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
		// Icons
		ContentSaveOutline,
		Cancel,
		Plus,
		ContentCopy,
		Check,
	},
	data() {
		return {
			isCopied: false,
			organisationItem: {
				name: '',
				description: '',
				isDefault: false,
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
				}
			}
		},
		getCurrentUser() {
			// Implementation would depend on how you get current user
			return 'current-user' // Placeholder
		},
		async copyToClipboard(text) {
			try {
				await navigator.clipboard.writeText(text)
				this.isCopied = true
				setTimeout(() => { this.isCopied = false }, 2000)
			} catch (err) {
				console.error('Failed to copy text:', err)
			}
		},
		closeModal() {
			this.success = false
			this.error = null
			this.createAnother = false
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
							description: '',
							isDefault: false,
						}
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
.detail-grid {
	display: grid;
	grid-template-columns: 1fr 1fr;
	gap: 16px;
	margin-bottom: 24px;
	padding: 16px;
	background: var(--color-background-dark);
	border-radius: 8px;
	border: 1px solid var(--color-border);
}

.detail-item {
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.id-card {
	grid-column: 1 / -1;
}

.id-card-header {
	display: flex;
	align-items: center;
	justify-content: space-between;
}

.detail-label {
	font-size: 12px;
	font-weight: 600;
	color: var(--color-text-lighter);
	text-transform: uppercase;
	letter-spacing: 0.5px;
}

.detail-value {
	font-size: 14px;
	color: var(--color-text-dark);
	word-break: break-all;
}

.copy-button {
	min-width: auto !important;
	padding: 4px 8px !important;
}

.form-editor {
	display: flex;
	flex-direction: column;
	gap: 16px;
}

.create-another-checkbox {
	margin-right: auto;
}

@media screen and (max-width: 768px) {
	.detail-grid {
		grid-template-columns: 1fr;
	}

	.id-card {
		grid-column: 1;
	}
}
</style>

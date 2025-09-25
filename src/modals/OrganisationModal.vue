<template>
	<NcModal v-if="show"
		:name="modalTitle"
		size="large"
		@close="closeModal">
		<div class="organisation-modal">
			<form @submit.prevent="saveOrganisation" class="organisation-form">
				<div class="form-section">
					<h3>{{ t('openregister', 'Basic Information') }}</h3>
					
					<div class="form-row">
						<NcTextField
							:value="formData.naam"
							:label="t('openregister', 'Name')"
							:placeholder="t('openregister', 'Enter organisation name')"
							required
							@update:value="formData.naam = $event" />
					</div>

					<div class="form-row">
						<NcTextField
							:value="formData.website"
							:label="t('openregister', 'Website')"
							:placeholder="t('openregister', 'https://www.example.com')"
							type="url"
							@update:value="formData.website = $event" />
					</div>

					<div class="form-row">
						<NcSelect
							:value="selectedType"
							:options="organisationTypes"
							:label="t('openregister', 'Type')"
							:placeholder="t('openregister', 'Select organisation type')"
							@update:value="selectedType = $event; formData.type = $event?.id" />
					</div>

					<div class="form-row">
						<NcTextField
							:value="formData.beschrijvingKort"
							:label="t('openregister', 'Short Description')"
							:placeholder="t('openregister', 'Brief description of the organisation')"
							@update:value="formData.beschrijvingKort = $event" />
					</div>
				</div>

				<div class="form-section">
					<h3>{{ t('openregister', 'Contact Information') }}</h3>
					
					<div class="form-row">
						<NcTextField
							:value="formData['e-mailadres']"
							:label="t('openregister', 'Email Address')"
							:placeholder="t('openregister', 'contact@organisation.com')"
							type="email"
							@update:value="formData['e-mailadres'] = $event" />
					</div>

					<div class="form-row">
						<NcTextField
							:value="formData.telefoonnummer"
							:label="t('openregister', 'Phone Number')"
							:placeholder="t('openregister', '+31 20 123 4567')"
							@update:value="formData.telefoonnummer = $event" />
					</div>
				</div>

				<div class="form-section">
					<h3>{{ t('openregister', 'Additional Information') }}</h3>
					
					<div class="form-row">
						<NcTextField
							:value="formData.oin"
							:label="t('openregister', 'OIN Number')"
							:placeholder="t('openregister', 'Organisation Identification Number')"
							@update:value="formData.oin = $event" />
					</div>

					<div class="form-row">
						<NcTextField
							:value="formData.cbs"
							:label="t('openregister', 'CBS Number')"
							:placeholder="t('openregister', 'Statistics Netherlands number')"
							@update:value="formData.cbs = $event" />
					</div>

					<div class="form-row">
						<NcTextField
							:value="formData.links"
							:label="t('openregister', 'Additional Links')"
							:placeholder="t('openregister', 'Additional website links')"
							@update:value="formData.links = $event" />
					</div>

					<div class="form-row">
						<NcTextField
							:value="formData.rol"
							:label="t('openregister', 'Role')"
							:placeholder="t('openregister', 'Organisation role or function')"
							@update:value="formData.rol = $event" />
					</div>
				</div>

				<div class="form-actions">
					<NcButton type="secondary" @click="closeModal">
						{{ t('openregister', 'Cancel') }}
					</NcButton>
					<NcButton type="primary" 
						:disabled="loading || !isFormValid"
						native-type="submit">
						<template #icon>
							<NcLoadingIcon v-if="loading" :size="20" />
						</template>
						{{ isEditMode ? t('openregister', 'Update Organisation') : t('openregister', 'Create Organisation') }}
					</NcButton>
				</div>
			</form>
		</div>
	</NcModal>
</template>

<script>
import { 
	NcModal,
	NcTextField,
	NcSelect,
	NcButton,
	NcLoadingIcon
} from '@nextcloud/vue'
import { organisationStore } from '../store/store.js'
import { showSuccess, showError } from '@nextcloud/dialogs'

export default {
	name: 'OrganisationModal',
	components: {
		NcModal,
		NcTextField,
		NcSelect,
		NcButton,
		NcLoadingIcon,
	},
	props: {
		show: {
			type: Boolean,
			default: false,
		},
		organisation: {
			type: Object,
			default: null,
		},
		mode: {
			type: String,
			default: 'create', // 'create', 'edit', 'copy'
		},
	},
	data() {
		return {
			formData: {
				naam: '',
				website: '',
				type: '',
				beschrijvingKort: '',
				'e-mailadres': '',
				telefoonnummer: '',
				oin: '',
				cbs: '',
				links: '',
				rol: '',
				status: 'Concept',
				deelnemers: [],
				contactpersonen: [],
			},
			selectedType: null,
			loading: false,
			organisationTypes: [
				{ id: 'Gemeente', label: 'Gemeente' },
				{ id: 'Leverancier', label: 'Leverancier' },
				{ id: 'Samenwerking', label: 'Samenwerking' },
				{ id: 'Community', label: 'Community' },
			],
		}
	},
	computed: {
		isEditMode() {
			return this.mode === 'edit'
		},
		isCopyMode() {
			return this.mode === 'copy'
		},
		modalTitle() {
			if (this.isEditMode) {
				return this.t('openregister', 'Edit Organisation')
			} else if (this.isCopyMode) {
				return this.t('openregister', 'Copy Organisation')
			}
			return this.t('openregister', 'Create Organisation')
		},
		isFormValid() {
			return this.formData.naam.trim() !== '' && this.formData.type !== ''
		},
	},
	watch: {
		show(newVal) {
			if (newVal) {
				this.resetForm()
				if (this.organisation) {
					this.loadOrganisationData()
				}
			}
		},
		organisation: {
			handler() {
				if (this.show && this.organisation) {
					this.loadOrganisationData()
				}
			},
			immediate: true,
		},
	},
	methods: {
		resetForm() {
			this.formData = {
				naam: '',
				website: '',
				type: '',
				beschrijvingKort: '',
				'e-mailadres': '',
				telefoonnummer: '',
				oin: '',
				cbs: '',
				links: '',
				rol: '',
				status: 'Concept',
				deelnemers: [],
				contactpersonen: [],
			}
			this.selectedType = null
			this.loading = false
		},
		loadOrganisationData() {
			if (!this.organisation) return

			// Load organisation data into form
			this.formData = {
				naam: this.organisation.naam || '',
				website: this.organisation.website || '',
				type: this.organisation.type || '',
				beschrijvingKort: this.organisation.beschrijvingKort || '',
				'e-mailadres': this.organisation['e-mailadres'] || '',
				telefoonnummer: this.organisation.telefoonnummer || '',
				oin: this.organisation.oin || '',
				cbs: this.organisation.cbs || '',
				links: this.organisation.links || '',
				rol: this.organisation.rol || '',
				status: this.organisation.status || 'Concept',
				deelnemers: this.organisation.deelnemers || [],
				contactpersonen: this.organisation.contactpersonen || [],
			}

			// Set selected type for NcSelect
			this.selectedType = this.organisationTypes.find(type => type.id === this.formData.type) || null

			// For copy mode, remove ID and contactpersonen
			if (this.isCopyMode) {
				delete this.formData.id
				this.formData.contactpersonen = []
				this.formData.status = 'Concept'
			}
		},
		closeModal() {
			this.$emit('close')
		},
		async saveOrganisation() {
			if (!this.isFormValid) {
				showError(this.t('openregister', 'Please fill in all required fields'))
				return
			}

			this.loading = true

			try {
				let result
				
				if (this.isEditMode) {
					// Update existing organisation
					const updateData = {
						...this.formData,
						id: this.organisation.id,
					}
					result = await organisationStore.updateOrganisation(updateData)
					showSuccess(this.t('openregister', 'Organisation updated successfully'))
				} else {
					// Create new organisation (both create and copy modes)
					result = await organisationStore.createOrganisation(this.formData)
					showSuccess(this.t('openregister', 'Organisation created successfully'))
				}

				// Refresh organisation list
				await organisationStore.refreshOrganisationList()
				
				// Close modal
				this.closeModal()

			} catch (error) {
				console.error('Error saving organisation:', error)
				showError(this.t('openregister', 'Failed to save organisation: {error}', { error: error.message }))
			} finally {
				this.loading = false
			}
		},
	},
}
</script>

<style scoped>
.organisation-modal {
	padding: 20px;
	max-width: 600px;
	margin: 0 auto;
}

.organisation-form {
	width: 100%;
}

.form-section {
	margin-bottom: 30px;
}

.form-section h3 {
	margin: 0 0 16px 0;
	font-size: 16px;
	font-weight: 600;
	color: var(--color-text-dark);
	border-bottom: 1px solid var(--color-border);
	padding-bottom: 8px;
}

.form-row {
	margin-bottom: 16px;
}

.form-actions {
	display: flex;
	justify-content: flex-end;
	gap: 12px;
	margin-top: 30px;
	padding-top: 20px;
	border-top: 1px solid var(--color-border);
}

/* Make form fields full width */
.form-row :deep(.input-field),
.form-row :deep(.select) {
	width: 100%;
}

.form-row :deep(.input-field__main-wrapper) {
	width: 100%;
}

.form-row :deep(.select__main-wrapper) {
	width: 100%;
}
</style>

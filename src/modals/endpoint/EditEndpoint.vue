<script setup>
import { translate as t } from '@nextcloud/l10n'
import { endpointStore, navigationStore } from '../../store/store.js'
</script>

<template>
	<CnFormDialog
		v-if="navigationStore.modal === 'editEndpoint'"
		ref="dialog"
		:item="savedEndpoint"
		:dialogTitle="
			endpointStore.endpointItem?.id
				? t('openregister', 'Edit Endpoint')
				: t('openregister', 'Add Endpoint')
		"
		size="large"
		:cancelLabel="t('openregister', 'Cancel')"
		:confirmLabel="t('openregister', 'Save')"
		:confirmDisabled="
			!endpointStore.endpointItem.name
				|| !endpointStore.endpointItem.endpoint
				|| !endpointStore.endpointItem.method
				|| !endpointStore.endpointItem.targetType
		"
		:recoverDraft="false"
		:feedback="false"
		@confirm="saveEndpoint()"
		@close="navigationStore.setModal(false)">
		<template #form>
			<div class="form">
				<NcTextField
					v-model="endpointStore.endpointItem.name"
					:label="t('openregister', 'Name*')"
					required
					maxlength="255" />
				<NcTextArea
					v-model="endpointStore.endpointItem.description"
					:label="t('openregister', 'Description')"
					rows="3" />
				<NcTextField
					v-model="endpointStore.endpointItem.endpoint"
					:label="t('openregister', 'Endpoint Path*')"
					:placeholder="endpointPlaceholder"
					required
					maxlength="255" />
				<NcSelect
					v-model="endpointStore.endpointItem.method"
					inputLabel="Endpoint Store Endpoint Item Method"
					:options="methodOptions"
					:label="t('openregister', 'Method*')"
					:placeholder="t('openregister', 'Select HTTP method')" />
				<NcSelect
					v-model="endpointStore.endpointItem.targetType"
					inputLabel="Endpoint Store Endpoint Item Target Type"
					:options="targetTypeOptions"
					:label="t('openregister', 'Target Type*')"
					:placeholder="t('openregister', 'Select target type')" />
				<NcTextField
					v-model="endpointStore.endpointItem.targetId"
					:label="t('openregister', 'Target ID')"
					:placeholder="t('openregister', 'ID of the target resource')" />
				<NcTextField
					v-model="endpointStore.endpointItem.version"
					:label="t('openregister', 'Version')"
					placeholder="0.0.0" />
				<NcTextField
					v-model="endpointStore.endpointItem.inputMapping"
					:label="t('openregister', 'Input Mapping')"
					:placeholder="
						t('openregister', 'ID of input mapping (optional)')
					" />
				<NcTextField
					v-model="endpointStore.endpointItem.outputMapping"
					:label="t('openregister', 'Output Mapping')"
					:placeholder="
						t('openregister', 'ID of output mapping (optional)')
					" />
			</div>
		</template>
	</CnFormDialog>
</template>

<script>
import { CnFormDialog } from '@conduction/nextcloud-vue'
import { NcSelect, NcTextArea, NcTextField } from '@nextcloud/vue'

export default {
	name: 'EditEndpoint',
	components: {
		CnFormDialog,
		NcTextField,
		NcTextArea,
		NcSelect,
	},

	data() {
		return {
			methodOptions: [
				'GET',
				'POST',
				'PUT',
				'DELETE',
				'PATCH',
				'OPTIONS',
				'HEAD',
			],

			targetTypeOptions: ['view', 'agent', 'webhook', 'register', 'schema'],
			endpointPlaceholder: '/api/example/{{id}}',
		}
	},

	computed: {
		/**
		 * The stored endpoint as the dialog's item, so the dialog knows it
		 * edits rather than creates; only the id is read.
		 *
		 * @spec exclude Dialog plumbing: create-or-edit mode of the dialog.
		 * @return {object|null} The id of the stored endpoint, or null
		 */
		savedEndpoint() {
			const id = endpointStore.endpointItem?.id
			return id ? { id } : null
		},
	},

	methods: {
		/**
		 * @spec openspec/specs/entity-management-modals/spec.md
		 */
		saveEndpoint() {
			if (endpointStore.endpointItem.id) {
				endpointStore
					.updateEndpoint(endpointStore.endpointItem)
					.then(() => {
						navigationStore.setModal(false)
						OCP.Toast.success('Endpoint updated successfully')
					})
					.catch((error) => {
						OCP.Toast.error(`Error updating endpoint: ${error.message}`)
						this.backToForm()
					})
			} else {
				endpointStore
					.createEndpoint(endpointStore.endpointItem)
					.then(() => {
						navigationStore.setModal(false)
						OCP.Toast.success('Endpoint created successfully')
					})
					.catch((error) => {
						OCP.Toast.error(`Error creating endpoint: ${error.message}`)
						this.backToForm()
					})
			}
		},

		/**
		 * Leave the dialog's saving state after a failed save, so the form
		 * can be corrected and saved again.
		 *
		 * @spec exclude Dialog plumbing: resets the dialog's loading state.
		 */
		backToForm() {
			this.$refs.dialog?.setValidationErrors({}, null)
		},
	},
}
</script>

<style>
.formContainer {
	margin: 20px;
}

.form {
	display: flex;
	flex-direction: column;
	gap: 10px;
	margin-bottom: 20px;
}

.modalFooter {
	display: flex;
	gap: 10px;
	justify-content: flex-end;
	margin-top: 20px;
}
</style>

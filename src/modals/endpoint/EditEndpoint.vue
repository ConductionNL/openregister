<script setup>
import { translate as t } from '@nextcloud/l10n'
import { endpointStore, navigationStore } from '../../store/store.js'
</script>

<template>
	<NcDialog v-if="navigationStore.modal === 'editEndpoint'"
<<<<<<< HEAD
		:name="endpointStore.endpointItem?.id ? t('openregister', 'Edit Endpoint') : t('openregister', 'Add Endpoint')"
=======
		:name="endpointStore.endpointItem?.id ? 'Edit Endpoint' : 'Add Endpoint'"
>>>>>>> 23880afe22b6f7f799fd5c26a65e169f6b16c773
		size="large"
		:can-close="true"
		@update:open="navigationStore.setModal(false)">
		<div class="formContainer">
			<div class="form">
				<NcTextField
					:value.sync="endpointStore.endpointItem.name"
<<<<<<< HEAD
					:label="t('openregister', 'Name*')"
=======
					label="Name*"
>>>>>>> 23880afe22b6f7f799fd5c26a65e169f6b16c773
					required
					maxlength="255" />
				<NcTextArea
					:value.sync="endpointStore.endpointItem.description"
<<<<<<< HEAD
					:label="t('openregister', 'Description')"
					rows="3" />
				<NcTextField
					:value.sync="endpointStore.endpointItem.endpoint"
					:label="t('openregister', 'Endpoint Path*')"
=======
					label="Description"
					rows="3" />
				<NcTextField
					:value.sync="endpointStore.endpointItem.endpoint"
					label="Endpoint Path*"
>>>>>>> 23880afe22b6f7f799fd5c26a65e169f6b16c773
					:placeholder="endpointPlaceholder"
					required
					maxlength="255" />
				<NcSelect
<<<<<<< HEAD
						input-label="Endpoint Store Endpoint Item Method"
					v-model="endpointStore.endpointItem.method"
					:options="methodOptions"
					:label="t('openregister', 'Method*')"
					:placeholder="t('openregister', 'Select HTTP method')" />
				<NcSelect
						input-label="Endpoint Store Endpoint Item Target Type"
					v-model="endpointStore.endpointItem.targetType"
					:options="targetTypeOptions"
					:label="t('openregister', 'Target Type*')"
					:placeholder="t('openregister', 'Select target type')" />
				<NcTextField
					:value.sync="endpointStore.endpointItem.targetId"
					:label="t('openregister', 'Target ID')"
					:placeholder="t('openregister', 'ID of the target resource')" />
				<NcTextField
					:value.sync="endpointStore.endpointItem.version"
					:label="t('openregister', 'Version')"
					placeholder="0.0.0" />
				<NcTextField
					:value.sync="endpointStore.endpointItem.inputMapping"
					:label="t('openregister', 'Input Mapping')"
					:placeholder="t('openregister', 'ID of input mapping (optional)')" />
				<NcTextField
					:value.sync="endpointStore.endpointItem.outputMapping"
					:label="t('openregister', 'Output Mapping')"
					:placeholder="t('openregister', 'ID of output mapping (optional)')" />
=======
					v-model="endpointStore.endpointItem.method"
					:options="methodOptions"
					label="Method*"
					placeholder="Select HTTP method" />
				<NcSelect
					v-model="endpointStore.endpointItem.targetType"
					:options="targetTypeOptions"
					label="Target Type*"
					placeholder="Select target type" />
				<NcTextField
					:value.sync="endpointStore.endpointItem.targetId"
					label="Target ID"
					placeholder="ID of the target resource" />
				<NcTextField
					:value.sync="endpointStore.endpointItem.version"
					label="Version"
					placeholder="0.0.0" />
				<NcTextField
					:value.sync="endpointStore.endpointItem.inputMapping"
					label="Input Mapping"
					placeholder="ID of input mapping (optional)" />
				<NcTextField
					:value.sync="endpointStore.endpointItem.outputMapping"
					label="Output Mapping"
					placeholder="ID of output mapping (optional)" />
>>>>>>> 23880afe22b6f7f799fd5c26a65e169f6b16c773
			</div>
			<div class="modalFooter">
				<NcButton @click="navigationStore.setModal(false)">
					<template #icon>
						<Cancel :size="20" />
					</template>
<<<<<<< HEAD
					{{ t('openregister', 'Cancel') }}
=======
					Cancel
>>>>>>> 23880afe22b6f7f799fd5c26a65e169f6b16c773
				</NcButton>
				<NcButton
					:disabled="!endpointStore.endpointItem.name || !endpointStore.endpointItem.endpoint || !endpointStore.endpointItem.method || !endpointStore.endpointItem.targetType"
					type="primary"
					@click="saveEndpoint()">
					<template #icon>
						<ContentSaveOutline :size="20" />
					</template>
<<<<<<< HEAD
					{{ t('openregister', 'Save') }}
=======
					Save
>>>>>>> 23880afe22b6f7f799fd5c26a65e169f6b16c773
				</NcButton>
			</div>
		</div>
	</NcDialog>
</template>

<script>
import {
	NcDialog,
	NcTextField,
	NcTextArea,
	NcButton,
	NcSelect,
} from '@nextcloud/vue'
import ContentSaveOutline from 'vue-material-design-icons/ContentSaveOutline.vue'
import Cancel from 'vue-material-design-icons/Cancel.vue'

export default {
	name: 'EditEndpoint',
	components: {
		NcDialog,
		NcTextField,
		NcTextArea,
		NcButton,
		NcSelect,
		ContentSaveOutline,
		Cancel,
	},
	data() {
		return {
			methodOptions: ['GET', 'POST', 'PUT', 'DELETE', 'PATCH', 'OPTIONS', 'HEAD'],
			targetTypeOptions: ['view', 'agent', 'webhook', 'register', 'schema'],
			endpointPlaceholder: '/api/example/{{id}}',
		}
	},
	methods: {
<<<<<<< HEAD
		/**
		 * @spec openspec/changes/retrofit-2026-05-24-2b-modals/tasks.md#task-1
		 */
=======
>>>>>>> 23880afe22b6f7f799fd5c26a65e169f6b16c773
		saveEndpoint() {
			if (endpointStore.endpointItem.id) {
				endpointStore.updateEndpoint(endpointStore.endpointItem)
					.then(() => {
						navigationStore.setModal(false)
						OCP.Toast.success('Endpoint updated successfully')
					})
					.catch((error) => {
						OCP.Toast.error(`Error updating endpoint: ${error.message}`)
					})
			} else {
				endpointStore.createEndpoint(endpointStore.endpointItem)
					.then(() => {
						navigationStore.setModal(false)
						OCP.Toast.success('Endpoint created successfully')
					})
					.catch((error) => {
						OCP.Toast.error(`Error creating endpoint: ${error.message}`)
					})
			}
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

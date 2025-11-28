<script setup>
import { endpointStore, navigationStore } from '../../store/store.js'
</script>

<template>
	<NcDialog v-if="navigationStore.modal === 'editEndpoint'"
		:name="endpointStore.endpointItem?.id ? 'Edit Endpoint' : 'Add Endpoint'"
		size="large"
		:can-close="true"
		@update:open="navigationStore.setModal(false)">
		<div class="formContainer">
			<div class="form">
				<NcTextField
					:value.sync="endpointStore.endpointItem.name"
					label="Name*"
					required
					maxlength="255" />
				<NcTextArea
					:value.sync="endpointStore.endpointItem.description"
					label="Description"
					rows="3" />
				<NcTextField
					:value.sync="endpointStore.endpointItem.endpoint"
					label="Endpoint Path*"
					placeholder="/api/example/{{id}}"
					required
					maxlength="255" />
				<NcSelect
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
			</div>
			<div class="modalFooter">
				<NcButton @click="navigationStore.setModal(false)">
					<template #icon>
						<Cancel :size="20" />
					</template>
					Cancel
				</NcButton>
				<NcButton
					:disabled="!endpointStore.endpointItem.name || !endpointStore.endpointItem.endpoint || !endpointStore.endpointItem.method || !endpointStore.endpointItem.targetType"
					type="primary"
					@click="saveEndpoint()">
					<template #icon>
						<ContentSaveOutline :size="20" />
					</template>
					Save
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
		}
	},
	methods: {
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


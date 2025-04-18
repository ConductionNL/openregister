<script setup>
import { configurationStore, navigationStore } from '../../store/store.js'
</script>

<template>
	<NcDialog v-if="navigationStore.modal === 'importConfiguration'"
		name="importConfiguration"
		title="Import Configuration"
		size="large"
		:can-close="false">
		<NcNoteCard v-if="success" type="success">
			<p>Configuration imported successfully!</p>
		</NcNoteCard>

		<NcNoteCard v-if="error" type="error">
			<p>{{ error }}</p>
		</NcNoteCard>

		<div class="formContainer">
			<input
				ref="fileInput"
				type="file"
				accept=".json"
				style="display: none"
				@change="handleFileUpload">

			<div class="fileSelection">
				<NcButton @click="$refs.fileInput.click()">
					<template #icon>
						<Upload :size="20" />
					</template>
					Select JSON File
				</NcButton>
				<span v-if="selectedFile">{{ selectedFile.name }}</span>
			</div>

			<div class="includeObjects">
				<NcCheckboxRadioSwitch 
					:checked="includeObjects"
					@update:checked="includeObjects = $event"
					type="switch">
					Include objectsin the import
					<template #helper>
						This will create or update objects on the register
					</template>
				</NcCheckboxRadioSwitch>
			</div>
		</div>

		<template #actions>
			<NcButton @click="closeModal">
				<template #icon>
					<Cancel :size="20" />
				</template>
				Cancel
			</NcButton>
			<NcButton
				:disabled="loading || !selectedFile"
				type="primary"
				@click="importConfiguration">
				<template #icon>
					<NcLoadingIcon v-if="loading" :size="20" />
					<Import v-else :size="20" />
				</template>
				Import
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
	NcCheckboxRadioSwitch,
} from '@nextcloud/vue'

import Cancel from 'vue-material-design-icons/Cancel.vue'
import Import from 'vue-material-design-icons/Import.vue'
import Upload from 'vue-material-design-icons/Upload.vue'

export default {
	name: 'ImportConfiguration',
	components: {
		NcDialog,
		NcButton,
		NcLoadingIcon,
		NcNoteCard,
		NcCheckboxRadioSwitch,
		// Icons
		Cancel,
		Import,
		Upload,
	},
	data() {
		return {
			selectedFile: null,
			loading: false,
			success: false,
			error: null,
			includeObjects: false,
		}
	},
	methods: {
		handleFileUpload(event) {
			this.selectedFile = event.target.files[0]
			this.error = null
		},
		closeModal() {
			navigationStore.setModal(false)
			this.selectedFile = null
			this.loading = false
			this.success = false
			this.error = null
			this.includeObjects = false
		},
		async importConfiguration() {
			this.loading = true
			this.error = null

			try {
				await configurationStore.importConfiguration(this.selectedFile, this.includeObjects)
				this.success = true
				setTimeout(() => this.closeModal(), 1500)
			} catch (error) {
				this.error = error.message || 'Failed to import configuration'
				this.loading = false
			}
		},
	},
}
</script>

<style>
.formContainer {
	display: flex;
	flex-direction: column;
	gap: 1rem;
}

.fileSelection {
	display: flex;
	align-items: center;
	gap: 1rem;
}

.includeObjects {
	margin-top: 1rem;
}
</style> 
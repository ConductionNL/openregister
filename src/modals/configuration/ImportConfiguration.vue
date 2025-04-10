<script setup>
import { configurationStore, navigationStore } from '../../store/store.js'
</script>

<template>
	<NcDialog v-if="navigationStore.modal === 'importConfiguration'"
		title="Import Configuration"
		size="normal"
		:can-close="false">
		<NcNoteCard v-if="error" type="error">
			<p>{{ error }}</p>
		</NcNoteCard>

		<div class="formContainer">
			<input
				ref="fileInput"
				type="file"
				accept=".json"
				@change="handleFileUpload"
				style="display: none">

			<NcButton
				type="secondary"
				@click="$refs.fileInput.click()">
				<template #icon>
					<Upload :size="20" />
				</template>
				Select JSON File
			</NcButton>

			<div v-if="selectedFile" class="selectedFile">
				<p>Selected file: {{ selectedFile.name }}</p>
			</div>

			<NcNoteCard type="info">
				<p>Please select a JSON file containing a valid configuration.</p>
			</NcNoteCard>
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
		// Icons
		Cancel,
		Import,
		Upload,
	},
	data() {
		return {
			selectedFile: null,
			loading: false,
			error: null,
		}
	},
	methods: {
		handleFileUpload(event) {
			const file = event.target.files[0]
			if (file && file.type === 'application/json') {
				this.selectedFile = file
				this.error = null
			} else {
				this.error = 'Please select a valid JSON file'
				this.selectedFile = null
			}
		},
		closeModal() {
			navigationStore.setModal(false)
			this.loading = false
			this.error = null
			this.selectedFile = null
			this.$refs.fileInput.value = ''
		},
		async importConfiguration() {
			if (!this.selectedFile) {
				this.error = 'Please select a file to import'
				return
			}

			this.loading = true
			this.error = null

			try {
				const reader = new FileReader()
				reader.onload = async (e) => {
					try {
						const configuration = JSON.parse(e.target.result)
						await configurationStore.uploadConfiguration(configuration)
						this.closeModal()
					} catch (error) {
						this.error = 'Invalid JSON format'
						this.loading = false
					}
				}
				reader.onerror = () => {
					this.error = 'Error reading file'
					this.loading = false
				}
				reader.readAsText(this.selectedFile)
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

.selectedFile {
	padding: 0.5rem;
	background-color: var(--color-background-dark);
	border-radius: var(--border-radius);
}
</style> 
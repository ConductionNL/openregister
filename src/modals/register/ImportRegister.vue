<script setup>
import { registerStore, navigationStore } from '../../store/store.js'
</script>

<template>
	<NcDialog v-if="navigationStore.modal === 'importRegister'"
		name="Import Register"
		size="normal"
		:can-close="false">
		<NcNoteCard v-if="success" type="success">
			<p>Register successfully imported</p>
		</NcNoteCard>
		<NcNoteCard v-if="error" type="error">
			<p>{{ error }}</p>
		</NcNoteCard>

		<div v-if="!success" class="formContainer">
			<input type="file"
				accept="application/json"
				@change="handleFileUpload"
				:disabled="loading">
			<NcNoteCard type="info">
				<p>Please select a JSON file containing the register data.</p>
			</NcNoteCard>
		</div>

		<template #actions>
			<NcButton @click="closeModal">
				<template #icon>
					<Cancel :size="20" />
				</template>
				{{ success ? 'Close' : 'Cancel' }}
			</NcButton>
			<NcButton v-if="!success"
				:disabled="loading || !selectedFile"
				type="primary"
				@click="importRegister()">
				<template #icon>
					<NcLoadingIcon v-if="loading" :size="20" />
					<Upload v-if="!loading" :size="20" />
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
import Upload from 'vue-material-design-icons/Upload.vue'

export default {
	name: 'ImportRegister',
	components: {
		NcDialog,
		NcButton,
		NcLoadingIcon,
		NcNoteCard,
		// Icons
		Upload,
		Cancel,
	},
	data() {
		return {
			selectedFile: null,
			success: false,
			loading: false,
			error: false,
			closeModalTimeout: null,
		}
	},
	methods: {
		handleFileUpload(event) {
			this.selectedFile = event.target.files[0]
		},
		closeModal() {
			navigationStore.setModal(false)
			clearTimeout(this.closeModalTimeout)
			this.success = false
			this.loading = false
			this.error = false
			this.selectedFile = null
		},
		async importRegister() {
			this.loading = true

			const reader = new FileReader()
			reader.onload = async (e) => {
				try {
					const register = JSON.parse(e.target.result)
					registerStore.importRegister(register)
						.then(({ response }) => {
							this.success = response.ok
							this.error = false
							response.ok && (this.closeModalTimeout = setTimeout(this.closeModal, 2000))
						}).catch((error) => {
							this.success = false
							this.error = error.message || 'An error occurred while importing the register'
						}).finally(() => {
							this.loading = false
						})
				} catch (error) {
					this.loading = false
					this.error = 'Invalid JSON file format'
				}
			}
			reader.onerror = () => {
				this.loading = false
				this.error = 'Error reading file'
			}
			reader.readAsText(this.selectedFile)
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

input[type="file"] {
	padding: 8px;
	border: 2px dashed var(--color-border);
	border-radius: var(--border-radius);
	cursor: pointer;
}

input[type="file"]:disabled {
	cursor: not-allowed;
	opacity: 0.5;
}
</style> 
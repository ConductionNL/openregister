<script setup>
import { registerStore, navigationStore } from '../../store/store.js'
</script>

<template>
	<NcDialog v-if="navigationStore.modal === 'importRegister'"
		name="importRegister"
		title="Import Register"
		size="large"
		:can-close="false">
		<NcNoteCard v-if="success" type="success">
			<p>Register imported successfully!</p>
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
				@click="importRegister">
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
	name: 'ImportRegister',
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
			success: false,
			error: null,
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
		},
		async importRegister() {
			this.loading = true
			this.error = null

			try {
				await registerStore.importRegister(this.selectedFile)
				this.success = true
				setTimeout(() => this.closeModal(), 1500)
			} catch (error) {
				this.error = error.message || 'Failed to import register'
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
</style>
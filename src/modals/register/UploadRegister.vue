<script setup>
import { registerStore, navigationStore } from '../../store/store.js'
</script>

<template>
	<NcDialog v-if="navigationStore.modal === 'uploadRegister'"
		name="Upload Register"
		size="normal"
		:can-close="false">
		<NcNoteCard v-if="success" type="success">
			<p>Register successfully uploaded</p>
		</NcNoteCard>
		<NcNoteCard v-if="error" type="error">
			<p>{{ error }}</p>
		</NcNoteCard>

		<div v-if="!success" class="formContainer">
			<NcTextField :disabled="loading"
				label="Url"
				:value.sync="register.url" />
			<NcTextArea :disabled="loading"
				label="Schema"
				:value.sync="register.json" />
		</div>

		<template #actions>
			<NcButton @click="closeModal">
				<template #icon>
					<Cancel :size="20" />
				</template>
				{{ success ? 'Close' : 'Cancel' }}
			</NcButton>
			<NcButton v-if="!success"
				:disabled="loading"
				type="primary"
				@click="uploadRegister()">
				<template #icon>
					<NcLoadingIcon v-if="loading" :size="20" />
					<Upload :size="20" />
				</template>
				Upload
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
	NcSelect,
} from '@nextcloud/vue'

import ContentSaveOutline from 'vue-material-design-icons/ContentSaveOutline.vue'
import Cancel from 'vue-material-design-icons/Cancel.vue'
import Upload from 'vue-material-design-icons/Upload.vue'

export default {
	name: 'UploadRegister',
	components: {
		NcDialog,
		NcTextField,
		NcTextArea,
		NcButton,
		NcLoadingIcon,
		NcNoteCard,
		NcSelect,
		// Icons
		ContentSaveOutline,
		Cancel,
		Upload,
	},
	data() {
		return {
			register: {
				json: '{}',
				url: '',
			},
			success: false,
			loading: false,
			error: false,
			hasUpdated: false,
			closeModalTimeout: null,
		}
	},
	methods: {
		closeModal() {
			navigationStore.setModal(false)
			clearTimeout(this.closeModalTimeout)
			this.success = null
			this.loading = false
			this.error = false
			this.hasUpdated = false
			this.register = {
				json: '{}',
				url: '',
			}
		},
		async uploadRegister() {
			this.loading = true

			registerStore.uploadRegister(this.register).then(({ response }) => {
				this.success = response.ok
				this.error = false
				response.ok && (this.closeModalTimeout = setTimeout(this.closeModal, 2000))
			}).catch((error) => {
				this.success = false
				this.error = error.message || 'An error occurred while uploading the register'
			}).finally(() => {
				this.loading = false
			})
		},
	},
}
</script>

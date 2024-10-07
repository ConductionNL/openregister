<script setup>
import { schemaStore, navigationStore } from '../../store/store.js'
</script>

<template>
	<NcDialog v-if="navigationStore.modal === 'uploadSchema'"
		name="Upload Schema"
		size="normal"
		:can-close="false">
		<NcNoteCard v-if="success" type="success">
			<p>Schema successfully uploaded</p>
		</NcNoteCard>
		<NcNoteCard v-if="error" type="error">
			<p>{{ error }}</p>
		</NcNoteCard>

		<div v-if="!success" class="formContainer">
			<NcTextArea :disabled="loading"
				label="Schema"
				:value.sync="schema" />
		</div>

		<template #actions>
			<NcButton @click="closeModal">
				<template #icon>
					<Cancel :size="20" />
				</template>
				{{ success ? 'Close' : 'Cancel' }}
			</NcButton>
			<NcButton v-if="!success"
				:disabled="loading || !schema"
				type="primary"
				@click="uploadSchema()">
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
	name: 'UploadSchema',
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
			schema: '{}',
			success: false,
			loading: false,
			error: false,
			hasUpdated: false,
		}
	},
	methods: {
		closeModal() {
			navigationStore.setModal(false)
			this.success = null
			this.loading = false
			this.error = false
			this.hasUpdated = false
			this.schema = '{}'
		},
		async uploadSchema() {
			this.loading = true

			schemaStore.uploadSchema(this.schema).then(({ response }) => {
				this.success = response.ok
				this.error = false
				response.ok && setTimeout(this.closeModal, 2000)
			}).catch((error) => {
				this.success = false
				this.error = error.message || 'An error occurred while uploading the schema'
			}).finally(() => {
				this.loading = false
			})
		},
	},
}
</script>

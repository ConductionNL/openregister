<script setup>
import { registerStore, navigationStore } from '../../store/store.js'
</script>

<template>
	<NcDialog v-if="navigationStore.modal === 'exportRegister'"
		name="Export Register"
		size="normal"
		:can-close="false">
		<NcNoteCard v-if="error" type="error">
			<p>{{ error }}</p>
		</NcNoteCard>

		<div class="formContainer">
			<NcCheckboxRadioSwitch
				:checked="includeSchemas"
				@update:checked="includeSchemas = $event"
				type="switch">
				Include schemas
			</NcCheckboxRadioSwitch>
			<NcNoteCard type="info">
				<p>The register will be exported as a JSON file.</p>
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
				:disabled="loading"
				type="primary"
				@click="exportRegister()">
				<template #icon>
					<NcLoadingIcon v-if="loading" :size="20" />
					<Download v-if="!loading" :size="20" />
				</template>
				Export
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
import Download from 'vue-material-design-icons/Download.vue'

export default {
	name: 'ExportRegister',
	components: {
		NcDialog,
		NcButton,
		NcLoadingIcon,
		NcNoteCard,
		NcCheckboxRadioSwitch,
		// Icons
		Download,
		Cancel,
	},
	data() {
		return {
			includeSchemas: true,
			loading: false,
			error: false,
		}
	},
	methods: {
		closeModal() {
			navigationStore.setModal(false)
			this.loading = false
			this.error = false
			this.includeSchemas = true
		},
		async exportRegister() {
			this.loading = true
			this.error = false

			try {
				const { data } = await registerStore.exportRegister(this.includeSchemas)
				const blob = new Blob([JSON.stringify(data, null, 2)], { type: 'application/json' })
				const url = window.URL.createObjectURL(blob)
				const link = document.createElement('a')
				link.href = url
				link.download = `register_${data.id}_${new Date().toISOString().split('T')[0]}.json`
				document.body.appendChild(link)
				link.click()
				document.body.removeChild(link)
				window.URL.revokeObjectURL(url)
				this.closeModal()
			} catch (error) {
				this.error = error.message || 'An error occurred while exporting the register'
			} finally {
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
</style> 
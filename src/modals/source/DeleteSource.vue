<script setup>
import { sourceStore, navigationStore } from '../../store/store.js'
</script>

<template>
	<NcDialog v-if="navigationStore.dialog === 'deleteSource'"
		name="Delete Source"
		size="normal"
		:can-close="false">
		<p v-if="!success">
			Do you want to permanently delete <b>{{ sourceStore.sourceItem?.title }}</b>? This action cannot be undone.
		</p>

		<NcNoteCard v-if="success" type="success">
			<p>Source successfully deleted</p>
		</NcNoteCard>
		<NcNoteCard v-if="error" type="error">
			<p>{{ error }}</p>
		</NcNoteCard>

		<template #actions>
			<NcButton @click="closeDialog">
				<template #icon>
					<Cancel :size="20" />
				</template>
				{{ success ? 'Close' : 'Cancel' }}
			</NcButton>
			<NcButton
				v-if="!success"
				:disabled="loading"
				type="error"
				@click="deleteSource()">
				<template #icon>
					<NcLoadingIcon v-if="loading" :size="20" />
					<TrashCanOutline v-if="!loading" :size="20" />
				</template>
				Delete
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
import TrashCanOutline from 'vue-material-design-icons/TrashCanOutline.vue'

export default {
	name: 'DeleteSource',
	components: {
		NcDialog,
		NcButton,
		NcLoadingIcon,
		NcNoteCard,
		// Icons
		TrashCanOutline,
		Cancel,
	},
	data() {
		return {
			success: false,
			loading: false,
			error: false,
			closeModalTimeout: null,
		}
	},
	methods: {
		closeDialog() {
			navigationStore.setDialog(false)
			clearTimeout(this.closeModalTimeout)
			this.success = false
			this.loading = false
			this.error = false
		},
		async deleteSource() {
			this.loading = true

			sourceStore.deleteSource({
				...sourceStore.sourceItem,
			}).then(({ response }) => {
				this.success = response.ok
				this.error = false
				response.ok && (this.closeModalTimeout = setTimeout(this.closeDialog, 2000))
			}).catch((error) => {
				this.success = false
				this.error = error.message || 'An error occurred while deleting the source'
			}).finally(() => {
				this.loading = false
			})
		},
	},
}
</script>

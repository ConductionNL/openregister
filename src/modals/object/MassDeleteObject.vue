<script setup>
import { objectStore } from '../../store/store.js'
</script>

<template>
	<NcDialog name="Delete Object"
		size="normal">
		<p v-if="success === null">
			Do you want to permanently delete <b>{{ selectedObjects.length }}</b> {{ selectedObjects.length > 1 ? 'objects' : 'object' }}? This action cannot be undone.
		</p>

		<NcNoteCard v-if="success" type="success">
			<p>Object{{ selectedObjects.length > 1 ? 's' : '' }} successfully deleted</p>
		</NcNoteCard>
		<NcNoteCard v-if="error" type="error">
			<p>{{ error }}</p>
		</NcNoteCard>

		<template #actions>
			<NcButton @click="closeDialog">
				<template #icon>
					<Cancel :size="20" />
				</template>
				{{ success === null ? 'Cancel' : 'Close' }}
			</NcButton>
			<NcButton v-if="success === null"
				:disabled="loading"
				type="error"
				@click="deleteObject()">
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
	name: 'MassDeleteObject',
	components: {
		NcDialog,
		NcButton,
		NcLoadingIcon,
		NcNoteCard,
		// Icons
		TrashCanOutline,
		Cancel,
	},
	props: {
		selectedObjects: {
			type: Array,
			required: true,
		},
	},
	data() {
		return {
			success: null,
			loading: false,
			error: false,
			result: null,
			closeModalTimeout: null,
		}
	},
	methods: {
		closeDialog() {
			clearTimeout(this.closeModalTimeout)
			this.startClosing = true
			this.$emit('close-modal')
		},
		async deleteObject() {
			this.loading = true

			objectStore.massDeleteObject(this.selectedObjects)
				.then((result) => {
					this.result = result
					this.success = result.successfulIds.length > 0
					this.error = result.failedIds.length > 0
					result.successfulIds.length > 0 && (this.closeModalTimeout = setTimeout(() => {
						this.closeDialog()
						this.$emit('success', this.result)
					}, 2000))
				}).catch((error) => {
					this.success = false
					this.error = error.message || 'An error occurred while deleting the object'
				}).finally(() => {
					this.loading = false
				})
		},
	},
}
</script>

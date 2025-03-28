<script setup>
import { navigationStore, schemaStore } from '../../store/store.js'
</script>

<template>
	<NcDialog
		v-if="navigationStore.modal === 'deleteSchemaProperty'"
		name="Delete Schema Property"
		:can-close="false">
		<div v-if="success !== null || error">
			<NcNoteCard v-if="success" type="success">
				<p>Schema property successfully deleted</p>
			</NcNoteCard>
			<NcNoteCard v-if="!success" type="error">
				<p>An error occurred while deleting the schema property</p>
			</NcNoteCard>
			<NcNoteCard v-if="error" type="error">
				<p>{{ error }}</p>
			</NcNoteCard>
		</div>

		<p v-if="success === null">
			Are you sure you want to permanently delete <b>{{ schemaStore.schemaItem.properties[schemaStore.schemaPropertyKey]?.title }}</b>? This action cannot be undone.
		</p>

		<template #actions>
			<div class="buttonContainer">
				<NcButton :disabled="loading" icon="" @click="closeModal">
					<template #icon>
						<Cancel :size="20" />
					</template>
					{{ success !== null ? 'Close' : 'Cancel' }}
				</NcButton>
				<NcButton
					v-if="success === null"
					:disabled="loading"
					icon="Delete"
					type="error"
					@click="DeleteProperty()">
					<template #icon>
						<NcLoadingIcon v-if="loading" :size="20" />
						<Delete v-if="!loading" :size="20" />
					</template>
					Delete
				</NcButton>
			</div>
		</template>
	</NcDialog>
</template>

<script>
import { NcButton, NcDialog, NcNoteCard, NcLoadingIcon } from '@nextcloud/vue'

import Cancel from 'vue-material-design-icons/Cancel.vue'
import Delete from 'vue-material-design-icons/Delete.vue'

export default {
	name: 'DeleteSchemaProperty',
	components: {
		NcDialog,
		NcButton,
		NcNoteCard,
		NcLoadingIcon,
		// Icons
		Cancel,
		Delete,
	},
	data() {
		return {
			loading: false,
			success: null,
			error: false,
			closeModalTimeout: null,
		}
	},
	methods: {
		closeModal() {
			navigationStore.setModal(null)
			schemaStore.setSchemaPropertyKey(null)
			clearTimeout(this.closeModalTimeout)
			this.success = null
			this.error = false
		},
		DeleteProperty() {
			this.loading = true

			const schemaItemClone = { ...schemaStore.schemaItem }

			delete schemaItemClone.properties[schemaStore.schemaPropertyKey]

			const newSchemaItem = {
				...schemaItemClone,
			}

			schemaStore.saveSchema(newSchemaItem)
				.then(({ response }) => {
					this.loading = false
					this.success = response.ok

					// Wait for the user to read the feedback then close the modal
					this.closeModalTimeout = setTimeout(this.closeModal, 2000)
				})
				.catch((err) => {
					this.error = err
					this.loading = false
				})
		},
	},
}
</script>

<style>
.modal__content {
    margin: var(--OC-margin-50);
    text-align: center;
}

.schemaDetailsContainer {
    margin-block-start: var(--OC-margin-20);
    margin-inline-start: var(--OC-margin-20);
    margin-inline-end: var(--OC-margin-20);
}

.success {
    color: green;
}
</style>

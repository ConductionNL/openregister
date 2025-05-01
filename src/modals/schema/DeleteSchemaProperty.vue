<script setup>
import { navigationStore, schemaStore, objectStore, registerStore } from '../../store/store.js'
</script>

<template>
	<NcDialog
		v-if="navigationStore.modal === 'deleteSchemaProperty'"
		name="Verwijder Schema-eigenschap"
		:can-close="false">
		<div v-if="success !== null || error">
			<NcNoteCard v-if="success" type="success">
				<p>Schema-eigenschap succesvol verwijderd</p>
			</NcNoteCard>
			<NcNoteCard v-if="!success" type="error">
				<p>Er is een fout opgetreden bij het verwijderen van de schema-eigenschap</p>
			</NcNoteCard>
			<NcNoteCard v-if="error" type="error">
				<p>{{ error }}</p>
			</NcNoteCard>
		</div>

		<p v-if="success === null">
			Weet u zeker dat u <b>{{ schemaStore.schemaItem.properties[schemaStore.schemaPropertyKey]?.title }}</b> permanent wilt verwijderen? Deze actie kan niet ongedaan worden gemaakt.
		</p>
		<NcNoteCard v-if="!canDelete" type="warning">
			<p>Meerdere objecten zullen niet beschikbaar zijn, omdat ze deze eigenschap gebruiken.</p>
		</NcNoteCard>
		<template #actions>
			<NcButton :disabled="loading" icon="" @click="closeModal">
				<template #icon>
					<Cancel :size="20" />
				</template>
				{{ success !== null ? 'Sluiten' : 'Annuleren' }}
			</NcButton>
			<NcButton
				v-if="success === null"
				:disabled="loading || !canDelete"
				icon="Delete"
				type="error"
				@click="deleteProperty()">
				<template #icon>
					<NcLoadingIcon v-if="loading" :size="20" />
					<Delete v-if="!loading" :size="20" />
				</template>
				Verwijder
			</NcButton>
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
			objects: [],
			isUpdated: false,
		}
	},
	computed: {
		canDelete() {
			return this.objects.length === 0
		},
	},
	updated() {
		if (!this.isUpdated && navigationStore.modal === 'deleteSchemaProperty') {
			this.isUpdated = true
			this.initDialog()
		}
	},
	methods: {
		async initDialog() {
			await registerStore.refreshRegisterList()
			if (registerStore.registerList.length === 0) {
				return
			}

			for (const reg of registerStore.registerList) {
				if (reg.schemas.includes(schemaStore.schemaItem.id)) {
					await objectStore.refreshObjectList({
						register: reg.id,
						schema: schemaStore.schemaItem.id,
						search: '',
					})
					if (objectStore.objectList?.results?.length) {
						for (const obj of objectStore.objectList.results) {
							if (obj[schemaStore.schemaPropertyKey]) {
								this.objects.push(obj)
							}
						}
					}
				}
			}
		},
		closeModal() {
			navigationStore.setModal(null)
			schemaStore.setSchemaPropertyKey(null)
			clearTimeout(this.closeModalTimeout)
			this.success = null
			this.error = false
			this.objects = []
			this.isUpdated = false
		},
		deleteProperty() {
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

<script setup>
import { objectStore, navigationStore } from '../../store/store.js'
</script>

<template>
	<NcDialog v-if="navigationStore.dialog === 'deleteObject'"
		name="Object verwijderen"
		size="normal"
		:can-close="false">
		<p v-if="success === null">
			Wil je <b>{{ objectStore.objectItem?.uuid }}</b> definitief verwijderen? Deze actie kan niet ongedaan worden gemaakt.
		</p>

		<NcNoteCard v-if="success" type="success">
			<p>Object succesvol verwijderd</p>
		</NcNoteCard>
		<NcNoteCard v-if="error" type="error">
			<p>{{ error }}</p>
		</NcNoteCard>

		<template #actions>
			<NcButton @click="closeDialog">
				<template #icon>
					<Cancel :size="20" />
				</template>
				{{ success === null ? 'Annuleer' : 'Sluiten' }}
			</NcButton>
			<NcButton
				v-if="success === null"
				:disabled="loading"
				type="error"
				@click="deleteObject()">
				<template #icon>
					<NcLoadingIcon v-if="loading" :size="20" />
					<TrashCanOutline v-if="!loading" :size="20" />
				</template>
				Verwijderen
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
	name: 'DeleteObject',
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
			success: null,
			loading: false,
			error: false,
		}
	},
	methods: {
		closeDialog() {
			navigationStore.setDialog(false)
			this.success = null
			this.loading = false
			this.error = false
		},
		async deleteObject() {
			this.loading = true

			objectStore.deleteObject({
				...objectStore.objectItem,
			}).then(({ response }) => {
				this.success = response.ok
				this.error = false
				response.ok && setTimeout(this.closeDialog, 2000)
			}).catch((error) => {
				this.success = false
				this.error = error.message || 'Er is een fout opgetreden bij het verwijderen van het object'
			}).finally(() => {
				this.loading = false
			})
		},
	},
}
</script>

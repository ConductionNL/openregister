<script setup>
import { registerStore, navigationStore } from '../../store/store.js'
</script>

<template>
	<NcDialog v-if="navigationStore.dialog === 'deleteRegister'"
		name="Register verwijderen"
		size="normal"
		:can-close="false">
		<p v-if="!success">
			Wil je <b>{{ registerStore.registerItem?.title }}</b> definitief verwijderen? Deze actie kan niet ongedaan worden gemaakt.
		</p>

		<NcNoteCard v-if="success" type="success">
			<p>Register succesvol verwijderd</p>
		</NcNoteCard>
		<NcNoteCard v-if="error" type="error">
			<p>{{ error }}</p>
		</NcNoteCard>

		<template #actions>
			<div class="buttonContainer">
				<NcButton @click="closeDialog">
					<template #icon>
						<Cancel :size="20" />
					</template>
					{{ success ? 'Sluiten' : 'Annuleer' }}
				</NcButton>
				<NcButton
					v-if="!success"
					:disabled="loading"
					type="error"
					@click="deleteRegister()">
					<template #icon>
						<NcLoadingIcon v-if="loading" :size="20" />
						<TrashCanOutline v-if="!loading" :size="20" />
					</template>
					Verwijderen
				</NcButton>
			</div>
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
	name: 'DeleteRegister',
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
		async deleteRegister() {
			this.loading = true

			registerStore.deleteRegister({
				...registerStore.registerItem,
			}).then(({ response }) => {
				this.success = response.ok
				this.error = false
				response.ok && (this.closeModalTimeout = setTimeout(this.closeDialog, 2000))
			}).catch((error) => {
				this.success = false
				this.error = error.message || 'Er is een fout opgetreden bij het verwijderen van het register'
			}).finally(() => {
				this.loading = false
			})
		},
	},
}
</script>

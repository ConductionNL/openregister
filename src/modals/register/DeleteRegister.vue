<script setup>
import { registerStore, navigationStore } from '../../store/store.js'
</script>

<template>
	<NcDialog v-if="navigationStore.dialog === 'deleteRegister'"
		name="Register verwijderen"
		size="normal"
		:can-close="false">
		<p v-if="!success && registerStore.registerItem?.schemas.length === 0">
			Wil je <b>{{ registerStore.registerItem?.title }}</b> definitief verwijderen? Deze actie kan niet ongedaan worden gemaakt.
		</p>
		<p v-if="!success && registerStore.registerItem?.schemas.length > 0">
			Het register kan niet worden verwijderd omdat het nog schema's bevat. Verwijder eerst alle schema's voordat u het register verwijdert.
			Er {{ registerStore.registerItem?.schemas.length > 1 ? 'zijn' : 'is' }} nog <b>{{ registerStore.registerItem?.schemas.length }}</b> schema{{ registerStore.registerItem?.schemas.length > 1 ? "'s" : '' }} in het register.
		</p>
		<NcNoteCard v-if="success" type="success">
			<p>Register succesvol verwijderd</p>
		</NcNoteCard>
		<NcNoteCard v-if="error" type="error">
			<p>{{ error }}</p>
		</NcNoteCard>
		<template v-if="registerStore.registerItem?.schemas.length === 0" #actions>
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
		</template>
		<template v-else #actions>
			<NcButton @click="closeDialog">
				<template #icon>
					<Cancel :size="20" />
				</template>
				Sluiten
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
			if (registerStore.registerItem?.schemas.length > 0) {
				return
			}
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

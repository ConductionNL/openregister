<script setup>
import { schemaStore, navigationStore, objectStore, registerStore} from '../../store/store.js'
</script>

<template>
	<NcDialog v-if="navigationStore.dialog === 'deleteSchema'"
		name="Verwijder Schema"
		size="normal"
		:can-close="false">
		<p v-if="!success && canDelete">
			Wil je <b>{{ schemaStore.schemaItem?.title }}</b> permanent verwijderen? Deze actie kan niet ongedaan worden gemaakt.
		</p>
		<p v-if="!success && !canDelete">
			Er zijn objecten in dit schema in het register <b>{{ registerName }}</b>. Je moet deze eerst verwijderen.
		</p>
		<NcNoteCard v-if="error" type="error">
			<p>{{ error }}</p>
		</NcNoteCard>

		<template #actions>
			<NcButton @click="closeDialog">
				<template #icon>
					<Cancel :size="20" />
				</template>
				{{ success ? 'Sluiten' : 'Annuleren' }}
			</NcButton>
			<NcButton
				v-if="!success"
				:disabled="loading || !canDelete"
				type="error"
				@click="handleDeleteSchema()">
				<template #icon>
					<NcLoadingIcon v-if="loading" :size="20" />
					<TrashCanOutline v-if="!loading" :size="20" />
				</template>
				Verwijder
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
	name: 'DeleteSchema',
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
			objects: [],
			registerName: '',
			canDelete: true,
		}
	},
	methods: {
		closeDialog() {
			navigationStore.setDialog(false)
			clearTimeout(this.closeModalTimeout)
			this.success = false
			this.loading = false
			this.error = false
			this.objects = []
			this.registerName = ''
			this.canDelete = true
		},
		async deleteSchema() {
			this.loading = true

			schemaStore.deleteSchema({
				...schemaStore.schemaItem,
			}).then(({ response }) => {
				this.success = response.ok
				this.error = false
				response.ok && (this.closeModalTimeout = setTimeout(this.closeDialog, 2000))
			}).catch((error) => {
				this.success = false
				this.error = error.message || 'An error occurred while deleting the schema'
			}).finally(() => {
				this.loading = false
			})
		},
		async handleDeleteSchema() {
			this.objects = []
			await registerStore.refreshRegisterList()
			if (registerStore.registerList.length === 0) {
				return;
			}
			for (const reg of registerStore.registerList) {
				if (reg.schemas.includes(schemaStore.schemaItem.id)) {
					await objectStore.refreshObjectList({
						register: reg.id,
						schema:   schemaStore.schemaItem.id,
						search:   '',
					})
					if (objectStore.objectList?.results?.length) {
						this.objects.push(...objectStore.objectList.results)
						this.registerName = reg.title
					}
				}
			}
			if (!this.objects.length) {
				await this.deleteSchema()
			} else {
				this.canDelete = false
			}
		},
	},
}
</script>

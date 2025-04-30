<script setup>
import { schemaStore, navigationStore, objectStore, registerStore } from '../../store/store.js'
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
			Er {{ objects.length > 1 ? 'zijn' : 'is' }} {{ objects.length }} {{ objects.length > 1 ? 'objecten' : 'object' }} in dit schema in het register <b>{{ registerName }}</b>. Je moet {{ objects.length > 1 ? 'deze' : 'dit' }} eerst verwijderen.
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
				@click="deleteSchema()">
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
			isUpdated: false,
		}
	},
	computed: {
		canDelete() {
			return this.objects.length === 0
		},
	},
	updated() {
		if (!this.isUpdated && navigationStore.dialog === 'deleteSchema') {
			this.isUpdated = true
			this.initDialog()
		}
	},
	methods: {
		async initDialog() {
			await registerStore.refreshRegisterList()
			if (!registerStore.registerList.length) {
				return
			}

			for (const reg of registerStore.registerList) {
				if (!reg.schemas.includes(schemaStore.schemaItem.id)) {
					continue
				}

				await objectStore.refreshObjectList({
					register: reg.id,
					schema: schemaStore.schemaItem.id,
					search: '',
				})

				if (objectStore.objectList?.results?.length) {
					this.objects.push(...objectStore.objectList.results)
					this.registerName = reg.title
				}
			}

			this.canDelete = this.objects.length === 0
		},
		closeDialog() {
			navigationStore.setDialog(false)
			clearTimeout(this.closeModalTimeout)
			this.success = false
			this.loading = false
			this.error = false
			this.objects = []
			this.registerName = ''
			this.canDelete = false
			this.isUpdated = false
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
	},
}
</script>

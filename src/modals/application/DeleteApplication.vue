<script setup>
import { applicationStore, navigationStore } from '../../store/store.js'
</script>

<template>
	<NcDialog
		:name="t('openregister', 'Delete Application')"
		:message="deleteMessage"
		@update:open="handleDialogClose">
		<template #actions>
			<NcButton @click="navigationStore.setDialog(false)">
				<template #icon>
					<Cancel :size="20" />
				</template>
				{{ t('openregister', 'Cancel') }}
			</NcButton>
			<NcButton
				type="error"
				@click="deleteApplication">
				<template #icon>
					<TrashCanOutline :size="20" />
				</template>
				{{ t('openregister', 'Delete') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import { NcDialog, NcButton } from '@nextcloud/vue'
import Cancel from 'vue-material-design-icons/Cancel.vue'
import TrashCanOutline from 'vue-material-design-icons/TrashCanOutline.vue'

export default {
	name: 'DeleteApplication',
	components: {
		NcDialog,
		NcButton,
		Cancel,
		TrashCanOutline,
	},
	computed: {
		deleteMessage() {
			return t('openregister', 'Are you sure you want to delete the application "{name}"? This action cannot be undone.', {
				name: applicationStore.applicationItem?.name || t('openregister', 'this application'),
			})
		},
	},
	methods: {
		async deleteApplication() {
			try {
				await applicationStore.deleteApplication(applicationStore.applicationItem)
				navigationStore.setDialog(false)
			} catch (error) {
				console.error('Error deleting application:', error)
			}
		},
		handleDialogClose(open) {
			if (!open) {
				navigationStore.setDialog(false)
			}
		},
	},
}
</script>


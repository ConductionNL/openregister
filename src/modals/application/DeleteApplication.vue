<script setup>
import { translatePlural as n, translate as t } from '@nextcloud/l10n'
import { applicationStore, navigationStore } from '../../store/store.js'
</script>

<template>
	<NcDialog
		:name="t('openregister', 'Delete Application')"
		:message="deleteMessage"
		@update:open="handleDialogClose">
		<template #actions>
			<CloseCancelButton :label="t('openregister', 'Cancel')" @click="navigationStore.setDialog(false)" />
			<NcButton variant="error" @click="deleteApplication">
				<template #icon>
					<TrashCanOutline :size="20" />
				</template>
				{{ t('openregister', 'Delete') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import { NcButton, NcDialog } from '@nextcloud/vue'
import TrashCanOutline from 'vue-material-design-icons/TrashCanOutline.vue'
import CloseCancelButton from '../../components/shared/CloseCancelButton.vue'

export default {
	name: 'DeleteApplication',
	components: {
		NcDialog,
		NcButton,
		CloseCancelButton,
		TrashCanOutline,
	},

	computed: {
		/**
		 * @spec exclude computed display helper for confirmation message
		 */
		deleteMessage() {
			return t(
				'openregister',
				'Are you sure you want to delete the application "{name}"? This action cannot be undone.',
				{
					name:
						applicationStore.applicationItem?.name
						|| t('openregister', 'this application'),
				},
			)
		},
	},

	methods: {
		/**
		 * @spec openspec/specs/entity-management-modals/spec.md
		 */
		async deleteApplication() {
			try {
				await applicationStore.deleteApplication(
					applicationStore.applicationItem,
				)
				navigationStore.setDialog(false)
			} catch (error) {
				console.error('Error deleting application:', error)
			}
		},

		/**
		 * @param {boolean} open
		 * @spec exclude modal open/close UI handler
		 */
		handleDialogClose(open) {
			if (!open) {
				navigationStore.setDialog(false)
			}
		},
	},
}
</script>

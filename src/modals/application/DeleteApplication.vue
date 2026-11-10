<script setup>
import { translatePlural as n, translate as t } from '@nextcloud/l10n'
import { applicationStore, navigationStore } from '../../store/store.js'
</script>

<template>
	<ConfirmDialogFrame
		:name="t('openregister', 'Delete Application')"
		size="small"
		closable
		:message="deleteMessage"
		:closeLabel="t('openregister', 'Cancel')"
		:confirmLabel="t('openregister', 'Delete')"
		showConfirm
		@cancel="navigationStore.setDialog(false)"
		@confirm="deleteApplication" />
</template>

<script>
import ConfirmDialogFrame from '../shared/ConfirmDialogFrame.vue'

export default {
	name: 'DeleteApplication',
	components: { ConfirmDialogFrame },

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
	},
}
</script>

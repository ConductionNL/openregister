<script setup>
import { translatePlural as n, translate as t } from '@nextcloud/l10n'
import { navigationStore, objectStore } from '../../store/store.js'
</script>

<template>
	<NcDialog
		:name="
			n(
				'openregister',
				'Delete {count} object',
				'Delete {count} objects',
				selectedObjects.length,
				{ count: selectedObjects.length },
			)
		"
		noClose
		size="normal">
		<!-- Object Selection Review -->
		<div v-if="success === null" class="delete-step">
			<h3 class="step-title">
				{{ t('openregister', 'Confirm Object Deletion') }}
			</h3>

			<NcNoteCard type="info">
				{{
					t(
						'openregister',
						"Review the selected objects below. You can remove any objects you don't want to delete by clicking the remove button.",
					)
				}}<br /><br />
				{{
					t(
						'openregister',
						'Objects will be soft deleted and moved to the',
					)
				}}
				<a
					href="#"
					class="deleted-link"
					@click.prevent="navigateToDeleted"
					>{{ t('openregister', 'deleted objects section') }}</a
				>.
				{{
					t(
						'openregister',
						"They will be retained according to their schema's configured retention period and automatically permanently deleted when the retention period expires. The retention period is configurable per schema and can be found in the schema's settings.",
					)
				}}
			</NcNoteCard>

			<SelectedObjectsList
				:objects="selectedObjects"
				:emptyDescription="
					t(
						'openregister',
						'No objects are currently selected for deletion.',
					)
				"
				@remove="removeObject" />
		</div>

		<NcNoteCard v-if="success" type="success">
			<p>
				{{
					n(
						'openregister',
						'Object successfully deleted',
						'Objects successfully deleted',
						objectStore.selectedObjects.length,
					)
				}}
			</p>
		</NcNoteCard>
		<NcNoteCard v-if="error" type="error">
			<p>{{ error }}</p>
		</NcNoteCard>

		<template #actions>
			<NcButton @click="closeDialog">
				<template #icon>
					<Cancel :size="20" />
				</template>
				{{
					success === null
						? t('openregister', 'Cancel')
						: t('openregister', 'Close')
				}}
			</NcButton>
			<NcButton
				v-if="success === null"
				:disabled="loading || selectedObjects.length === 0"
				variant="error"
				@click="deleteObject()">
				<template #icon>
					<NcLoadingIcon v-if="loading" :size="20" />
					<TrashCanOutline v-if="!loading" :size="20" />
				</template>
				{{ t('openregister', 'Delete') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import { NcButton, NcDialog, NcLoadingIcon, NcNoteCard } from '@nextcloud/vue'
import Cancel from 'vue-material-design-icons/Cancel.vue'
import TrashCanOutline from 'vue-material-design-icons/TrashCanOutline.vue'
import SelectedObjectsList from '../../components/shared/SelectedObjectsList.vue'
import selectedObjectsDialog, {
	refreshObjectLists,
} from './selectedObjectsDialog.js'

export default {
	name: 'MassDeleteObject',
	components: {
		SelectedObjectsList,
		NcDialog,
		NcButton,
		NcLoadingIcon,
		NcNoteCard,
		// Icons
		TrashCanOutline,
		Cancel,
	},

	mixins: [selectedObjectsDialog],

	data() {
		return {
			success: null,
			loading: false,
			error: false,
			result: null,
			closeModalTimeout: null,
		}
	},

	mounted() {
		this.initializeSelection()
	},

	methods: {
		/**
		 * @spec exclude modal close UI handler
		 */
		closeDialog() {
			clearTimeout(this.closeModalTimeout)
			this.startClosing = true
			navigationStore.setDialog(false)
		},

		/**
		 * @spec exclude router navigation UI handler
		 */
		navigateToDeleted() {
			// Close the dialog first
			this.closeDialog()
			// Navigate to the deleted objects section
			this.$router.push('/deleted')
		},

		/**
		 * @spec exclude modal bulk-delete submit handler delegating to objectStore.massDeleteObject
		 */
		async deleteObject() {
			this.loading = true

			objectStore
				.massDeleteObject(this.selectedObjects.map((obj) => obj.id))
				.then((result) => {
					this.result = result
					this.success = result.successfulIds.length > 0
					this.error = result.failedIds.length > 0
					if (result.successfulIds.length > 0) {
						refreshObjectLists()

						// Close immediately rather than after a 2s delay. The selection is
						// already empty at this point, so leaving the dialog mounted let the
						// template fall back to its "no objects selected" state — an empty
						// delete confirmation the user had to dismiss by hand.
						this.closeDialog()
					}
				})
				.catch((error) => {
					this.success = false
					this.error =
						error.message
						|| t(
							'openregister',
							'An error occurred while deleting the object',
						)
				})
				.finally(() => {
					this.loading = false
				})
		},
	},
}
</script>

<style scoped lang="scss">
@use '../../styles/modals.scss' as *;

.delete-step {
	padding: 0;
}

@include step-title;

.deleted-link {
	color: var(--color-primary);
	text-decoration: underline;
	cursor: pointer;
}

.deleted-link:hover {
	color: var(--color-primary-hover);
}
</style>

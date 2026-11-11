<script setup>
import { translatePlural as n, translate as t } from '@nextcloud/l10n'
import { navigationStore, objectStore } from '../../store/store.js'
</script>

<template>
	<SelectedObjectsDialogFrame
		:name="
			n(
				'openregister',
				'Validate {count} object',
				'Validate {count} objects',
				selectedObjects.length,
				{ count: selectedObjects.length },
			)
		"
		stepClass="validate-step"
		:title="t('openregister', 'Confirm validation')"
		:objects="selectedObjects"
		:emptyDescription="
			t('openregister', 'Select objects in the list to validate them.')
		"
		:success="success"
		:successText="
			n(
				'openregister',
				'{count} object validated',
				'{count} objects validated',
				validatedCount,
				{ count: validatedCount },
			)
		"
		:error="error"
		:loading="loading"
		:actionLabel="t('openregister', 'Validate')"
		actionVariant="primary"
		@close="closeDialog"
		@remove="removeObject"
		@submit="validateObjects()">
		<template #intro>
			{{
				t(
					'openregister',
					'Remove any object you want to skip. Use this after you change a schema, to apply new rules and refresh generated fields.',
				)
			}}<br /><br />
			{{
				t(
					'openregister',
					'We save each object unchanged, which checks it against the current schema.',
				)
			}}
		</template>
		<template #actionIcon>
			<CheckCircle :size="20" />
		</template>
	</SelectedObjectsDialogFrame>
</template>

<script>
import CheckCircle from 'vue-material-design-icons/CheckCircle.vue'
import SelectedObjectsDialogFrame from './SelectedObjectsDialogFrame.vue'
import selectedObjectsDialog, {
	refreshObjectLists,
} from './selectedObjectsDialog.js'

export default {
	name: 'MassValidateObjects',
	components: {
		SelectedObjectsDialogFrame,
		// Icons
		CheckCircle,
	},

	mixins: [selectedObjectsDialog],

	data() {
		return {
			success: null,
			loading: false,
			error: false,
			validatedCount: 0,
			closeModalTimeout: null,
		}
	},

	mounted() {
		this.initializeSelection()
	},

	methods: {
		/**
		 * @spec exclude Modal close plumbing: closes the mass-validate dialog.
		 */
		closeDialog() {
			clearTimeout(this.closeModalTimeout)
			navigationStore.setDialog(false)
		},

		/**
		 * Save one object as it is. A row that is not loaded is fetched first:
		 * saving replaces the object, so `{ id }` alone would empty it.
		 *
		 * @param {object} obj The selected row
		 * @spec openspec/specs/entity-management-modals/spec.md
		 * @return {Promise<boolean>} Whether the object was saved
		 */
		async validateObject(obj) {
			const type = objectStore.currentType
			try {
				const row = obj['@self']
					? obj
					: await objectStore.fetchObject(type, obj.id)
				return (
					Boolean(row)
					&& (await objectStore.saveObject(type, { ...row, id: obj.id }))
						!== null
				)
			} catch (error) {
				console.error(`Failed to validate object ${obj.id}:`, error)
				return false
			}
		},

		/**
		 * Report how the save went. The dialog closes by itself only when
		 * every object was saved.
		 *
		 * @param {Array<boolean>} outcomes One entry per object
		 * @spec openspec/specs/entity-management-modals/spec.md
		 * @return {void}
		 */
		showOutcome(outcomes) {
			this.validatedCount = outcomes.filter(Boolean).length
			const failed = outcomes.length - this.validatedCount
			if (this.validatedCount > 0) {
				this.success = true
				refreshObjectLists()
			}
			if (failed > 0) {
				this.error = this.notValidatedText(failed)
				return
			}
			this.closeModalTimeout = setTimeout(() => this.closeDialog(), 2000)
		},

		/**
		 * Why some objects are not validated: how many failed.
		 *
		 * @param {number} failed The objects that could not be validated
		 * @return {string} The message
		 */
		notValidatedText(failed) {
			return n(
				'openregister',
				'{count} object could not be validated',
				'{count} objects could not be validated',
				failed,
				{ count: failed },
			)
		},

		/**
		 * @spec openspec/specs/entity-management-modals/spec.md
		 */
		async validateObjects() {
			this.loading = true
			this.error = false
			const outcomes = await Promise.all(
				this.selectedObjects.map((obj) => this.validateObject(obj)),
			)
			this.showOutcome(outcomes)
			this.loading = false
		},
	},
}
</script>

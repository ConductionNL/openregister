<script setup>
import { translatePlural as n, translate as t } from '@nextcloud/l10n'
import { navigationStore, objectStore } from '../../store/store.js'
</script>

<template>
	<NcDialog
		:name="
			n(
				'openregister',
				'Validate {count} object',
				'Validate {count} objects',
				selectedObjects.length,
				{ count: selectedObjects.length },
			)
		"
		noClose
		size="normal">
		<!-- Object Selection Review -->
		<div v-if="success === null" class="validate-step">
			<h3 class="step-title">
				{{ t('openregister', 'Confirm validation') }}
			</h3>

			<NcNoteCard type="info">
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
			</NcNoteCard>

			<SelectedObjectsList
				:objects="selectedObjects"
				:emptyDescription="
					t('openregister', 'Select objects in the list to validate them.')
				"
				@remove="removeObject" />
		</div>

		<NcNoteCard v-if="success" type="success">
			<p>
				{{
					n(
						'openregister',
						'{count} object validated',
						'{count} objects validated',
						validatedCount,
						{ count: validatedCount },
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
				variant="primary"
				@click="validateObjects()">
				<template #icon>
					<NcLoadingIcon v-if="loading" :size="20" />
					<CheckCircle v-if="!loading" :size="20" />
				</template>
				{{ t('openregister', 'Validate') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import { NcButton, NcDialog, NcLoadingIcon, NcNoteCard } from '@nextcloud/vue'
import Cancel from 'vue-material-design-icons/Cancel.vue'
import CheckCircle from 'vue-material-design-icons/CheckCircle.vue'
import SelectedObjectsList from '../../components/shared/SelectedObjectsList.vue'
import selectedObjectsDialog, {
	refreshObjectLists,
} from './selectedObjectsDialog.js'

export default {
	name: 'MassValidateObjects',
	components: {
		SelectedObjectsList,
		NcDialog,
		NcButton,
		NcLoadingIcon,
		NcNoteCard,
		// Icons
		CheckCircle,
		Cancel,
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
				this.error = n(
					'openregister',
					'{count} object could not be validated',
					'{count} objects could not be validated',
					failed,
					{ count: failed },
				)
				return
			}
			this.closeModalTimeout = setTimeout(() => this.closeDialog(), 2000)
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

<style scoped lang="scss">
@use '../../styles/modals.scss' as *;

.validate-step {
	padding: 0;
}

@include step-title;
</style>

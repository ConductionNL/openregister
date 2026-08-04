<script setup>
import { translate as t, translatePlural as n } from '@nextcloud/l10n'
import { objectStore, navigationStore } from '../../store/store.js'
</script>

<template>
	<NcDialog :name="n('openregister', 'Delete {count} object', 'Delete {count} objects', selectedObjects.length, { count: selectedObjects.length })"
		:can-close="false"
		size="normal">
		<!-- Object Selection Review -->
		<div v-if="success === null" class="delete-step">
			<h3 class="step-title">
				{{ t('openregister', 'Confirm Object Deletion') }}
			</h3>

			<NcNoteCard type="info">
				{{ t('openregister', 'Review the selected objects below. You can remove any objects you don\'t want to delete by clicking the remove button.') }}<br><br>
				{{ t('openregister', 'Objects will be soft deleted and moved to the') }}
				<a href="#" class="deleted-link" @click.prevent="navigateToDeleted">{{ t('openregister', 'deleted objects section') }}</a>.
				{{ t('openregister', 'They will be retained according to their schema\'s configured retention period and automatically permanently deleted when the retention period expires. The retention period is configurable per schema and can be found in the schema\'s settings.') }}
			</NcNoteCard>

			<div class="selected-objects-container">
				<h4>{{ t('openregister', 'Selected Objects ({count})', { count: selectedObjects.length }) }}</h4>

				<div v-if="selectedObjects.length" class="selected-objects-list">
					<div v-for="obj in selectedObjects"
						:key="obj.id"
						class="selected-object-item">
						<div class="object-info">
							<strong>{{ obj['@self']?.name || obj.name || obj.title || obj['@self']?.title || t('openregister', 'Unnamed Object') }}</strong>
							<p class="object-id">
								{{ t('openregister', 'ID: {id}', { id: obj.id || obj['@self']?.id }) }}
							</p>
						</div>
						<NcButton variant="tertiary"
							:aria-label="t('openregister', 'Remove {title}', { title: obj['@self']?.name || obj.name || obj.title || obj['@self']?.title || obj.id })"
							@click="removeObject(obj.id)">
							<template #icon>
								<Close :size="20" />
							</template>
						</NcButton>
					</div>
				</div>

				<NcEmptyContent v-else :name="t('openregister', 'No objects selected')">
					<template #description>
						{{ t('openregister', 'No objects are currently selected for deletion.') }}
					</template>
				</NcEmptyContent>
			</div>
		</div>

		<NcNoteCard v-if="success" type="success">
			<p>{{ n('openregister', 'Object successfully deleted', 'Objects successfully deleted', objectStore.selectedObjects.length) }}</p>
		</NcNoteCard>
		<NcNoteCard v-if="error" type="error">
			<p>{{ error }}</p>
		</NcNoteCard>

		<template #actions>
			<NcButton @click="closeDialog">
				<template #icon>
					<Cancel :size="20" />
				</template>
				{{ success === null ? t('openregister', 'Cancel') : t('openregister', 'Close') }}
			</NcButton>
			<NcButton v-if="success === null"
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
import {
	NcButton,
	NcDialog,
	NcEmptyContent,
	NcLoadingIcon,
	NcNoteCard,
} from '@nextcloud/vue'

import Cancel from 'vue-material-design-icons/Cancel.vue'
import TrashCanOutline from 'vue-material-design-icons/TrashCanOutline.vue'
import Close from 'vue-material-design-icons/Close.vue'

export default {
	name: 'MassDeleteObject',
	components: {
		NcDialog,
		NcButton,
		NcEmptyContent,
		NcLoadingIcon,
		NcNoteCard,
		// Icons
		TrashCanOutline,
		Cancel,
		Close,
	},

	data() {
		return {
			success: null,
			loading: false,
			error: false,
			result: null,
			closeModalTimeout: null,
			selectedObjects: [],
		}
	},
	mounted() {
		this.initializeSelection()
	},
	methods: {
		/**
		 * @spec openspec/specs/entity-management-modals/spec.md
		 */
		initializeSelection() {
			// `objectStore.selectedObjects` holds plain ID STRINGS — that is the
			// contract the table binding relies on (SearchIndex's
			// `selectedIdsForPage` does `list.map(String)`), so it must NOT be
			// changed to objects. This dialog, however, renders objects
			// (`obj['@self']?.name`, `obj.id`) and submits `map(obj => obj.id)`.
			// Resolve ids to their rows here, keeping the store as-is. Anything that
			// cannot be resolved still yields `{ id }` so the delete itself works
			// even when the row is not in the loaded page.
			const selection = objectStore.selectedObjects || []
			const pool = Array.isArray(objectStore.searchCollection) ? objectStore.searchCollection : []
			const byId = new Map()
			for (const row of pool) {
				const rowId = row?.['@self']?.id ?? row?.id
				if (rowId) byId.set(String(rowId), row)
			}

			this.selectedObjects = selection.map((entry) => {
				if (entry && typeof entry === 'object') {
					return { ...entry, id: entry['@self']?.id ?? entry.id }
				}
				const row = byId.get(String(entry))
				return row
					? { ...row, id: row['@self']?.id ?? row.id }
					: { id: String(entry) }
			}).filter((obj) => obj.id)

			if (this.selectedObjects.length === 0) {
				this.closeDialog()
			}
		},
		/**
		 * @param objectId
		 * @spec exclude form-state helper to deselect an object from the list
		 */
		removeObject(objectId) {
			this.selectedObjects = this.selectedObjects.filter(obj => obj.id !== objectId)
			// Write IDs back, never objects — the table's `selectedIdsForPage`
			// stringifies whatever is here, and objects would serialise to
			// "[object Object]" and silently clear the visible selection.
			objectStore.selectedObjects = this.selectedObjects.map(obj => obj.id)
			if (this.selectedObjects.length === 0) {
				this.closeDialog()
			}
		},
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

			objectStore.massDeleteObject(this.selectedObjects.map(obj => obj.id))
				.then((result) => {
					this.result = result
					this.success = result.successfulIds.length > 0
					this.error = result.failedIds.length > 0
					if (result.successfulIds.length > 0) {
						// Clear selected objects and refresh whichever list is on screen.
						//
						// `refreshObjectList()` alone was not enough: it refetches the
						// register/schema collection derived from registerStore/
						// schemaStore, but the search view renders
						// `objectStore.searchCollection`, which is only refilled by
						// `refetchSearchCollection()`. Deleting from the search view
						// therefore left the deleted rows on screen.
						objectStore.selectedObjects = []
						if (typeof objectStore.refetchSearchCollection === 'function') {
							objectStore.refetchSearchCollection()
						}
						objectStore.refreshObjectList().catch(() => {
							// The register/schema pair is unavailable on views that do not
							// set it (e.g. global search). The search refetch above is the
							// authoritative refresh there, so this is not an error.
						})

						// Close immediately rather than after a 2s delay. The selection is
						// already empty at this point, so leaving the dialog mounted let the
						// template fall back to its "no objects selected" state — an empty
						// delete confirmation the user had to dismiss by hand.
						this.closeDialog()
					}
				}).catch((error) => {
					this.success = false
					this.error = error.message || t('openregister', 'An error occurred while deleting the object')
				}).finally(() => {
					this.loading = false
				})
		},
	},
}
</script>

<style scoped>
.delete-step {
	padding: 0;
}

.step-title {
	margin-top: 0 !important;
	margin-bottom: 16px;
	color: var(--color-main-text);
}

.selected-objects-container {
	margin: 20px 0;
}

.selected-objects-list {
	max-height: 300px;
	overflow-y: auto;
	border: 1px solid var(--color-border);
	border-radius: 4px;
}

.selected-object-item {
	display: flex;
	justify-content: space-between;
	align-items: center;
	padding: 12px;
	border-bottom: 1px solid var(--color-border);
	background-color: var(--color-background-hover);
}

.selected-object-item:last-child {
	border-bottom: none;
}

.object-info strong {
	display: block;
	margin-bottom: 4px;
	color: var(--color-main-text);
}

.object-id {
	color: var(--color-text-maxcontrast);
	font-size: 0.9em;
	margin: 0;
}

.deleted-link {
	color: var(--color-primary);
	text-decoration: underline;
	cursor: pointer;
}

.deleted-link:hover {
	color: var(--color-primary-hover);
}
</style>

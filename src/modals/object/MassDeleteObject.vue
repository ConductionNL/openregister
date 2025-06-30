<script setup>
import { objectStore, navigationStore } from '../../store/store.js'
</script>

<template>
	<NcDialog :name="`Delete ${selectedObjects.length} object${selectedObjects.length !== 1 ? 's' : ''}`"
		:can-close="false"
		size="normal">
		<!-- Object Selection Review -->
		<div v-if="success === null" class="delete-step">
			<h3 class="step-title">
				Confirm Object Deletion
			</h3>

			<NcNoteCard type="info">
				Review the selected objects below. You can remove any objects you don't want to delete by clicking the remove button.<br><br>
				Objects will be soft deleted and moved to the <a href="#" class="deleted-link" @click.prevent="navigateToDeleted">deleted objects section</a>. They will be retained according to their schema's configured retention period and automatically permanently deleted when the retention period expires. The retention period is configurable per schema and can be found in the schema's settings.
			</NcNoteCard>

			<div class="selected-objects-container">
				<h4>Selected Objects ({{ selectedObjects.length }})</h4>

				<div v-if="selectedObjects.length" class="selected-objects-list">
					<div v-for="obj in selectedObjects"
						:key="obj.id"
						class="selected-object-item">
						<div class="object-info">
							<strong>{{ obj['@self']?.name || obj.name || obj.title || obj['@self']?.title || 'Unnamed Object' }}</strong>
							<p class="object-id">
								ID: {{ obj.id || obj['@self']?.id }}
							</p>
						</div>
						<NcButton type="tertiary"
							:aria-label="`Remove ${obj['@self']?.name || obj.name || obj.title || obj['@self']?.title || obj.id}`"
							@click="removeObject(obj.id)">
							<template #icon>
								<Close :size="20" />
							</template>
						</NcButton>
					</div>
				</div>

				<NcEmptyContent v-else name="No objects selected">
					<template #description>
						No objects are currently selected for deletion.
					</template>
				</NcEmptyContent>
			</div>
		</div>

		<NcNoteCard v-if="success" type="success">
			<p>Object{{ objectStore.selectedObjects.length > 1 ? 's' : '' }} successfully deleted</p>
		</NcNoteCard>
		<NcNoteCard v-if="error" type="error">
			<p>{{ error }}</p>
		</NcNoteCard>

		<template #actions>
			<NcButton @click="closeDialog">
				<template #icon>
					<Cancel :size="20" />
				</template>
				{{ success === null ? 'Cancel' : 'Close' }}
			</NcButton>
			<NcButton v-if="success === null"
				:disabled="loading || selectedObjects.length === 0"
				type="error"
				@click="deleteObject()">
				<template #icon>
					<NcLoadingIcon v-if="loading" :size="20" />
					<TrashCanOutline v-if="!loading" :size="20" />
				</template>
				Delete
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
		initializeSelection() {
			// Get selected objects from the store or navigation context
			this.selectedObjects = objectStore.selectedObjects || []
			if (this.selectedObjects.length === 0) {
				this.closeDialog()
			}
		},
		removeObject(objectId) {
			this.selectedObjects = this.selectedObjects.filter(obj => obj.id !== objectId)
			// Update the store as well
			objectStore.selectedObjects = this.selectedObjects
			if (this.selectedObjects.length === 0) {
				this.closeDialog()
			}
		},
		closeDialog() {
			clearTimeout(this.closeModalTimeout)
			this.startClosing = true
			navigationStore.setDialog(false)
		},
		navigateToDeleted() {
			// Close the dialog first
			this.closeDialog()
			// Navigate to the deleted objects section
			navigationStore.setSelected('deleted')
		},
		async deleteObject() {
			this.loading = true

			objectStore.massDeleteObject(this.selectedObjects.map(obj => obj.id))
				.then((result) => {
					this.result = result
					this.success = result.successfulIds.length > 0
					this.error = result.failedIds.length > 0
					if (result.successfulIds.length > 0) {
						// Clear selected objects and refresh the object list
						objectStore.selectedObjects = []
						objectStore.refreshObjectList()

						this.closeModalTimeout = setTimeout(() => {
							this.closeDialog()
						}, 2000)
					}
				}).catch((error) => {
					this.success = false
					this.error = error.message || 'An error occurred while deleting the object'
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

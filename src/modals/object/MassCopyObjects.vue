<script setup>
import { translate as t } from '@nextcloud/l10n'
import { navigationStore, objectStore } from '../../store/store.js'
</script>

<template>
	<NcDialog
		v-if="navigationStore.dialog === 'massCopyObjects'"
		:name="`Copy ${objectStore.selectedObjects.length} Objects`"
		size="normal"
		:canClose="false">
		<div v-if="success === null">
			<p>
				{{ t('openregister', 'Create copies of') }}
				<b
					>{{ objectStore.selectedObjects.length }}
					{{ t('openregister', 'selected objects') }}</b
				>
			</p>

			<div class="form-group">
				<label for="namingPattern">{{
					t('openregister', 'Naming pattern for copies:')
				}}</label>
				<NcSelect
					v-model="selectedNamingPattern"
					inputLabel="Selected Naming Pattern"
					:options="namingPatternOptions"
					:disabled="loading"
					label="label"
					trackBy="value" />
				<p class="help-text">
					{{ t('openregister', 'Preview:') }} "{{
						getPreviewName(objectStore.selectedObjects[0])
					}}"
				</p>
			</div>

			<div v-if="selectedNamingPattern?.value === 'custom'" class="form-group">
				<label for="customPattern">{{
					t('openregister', 'Custom pattern:')
				}}</label>
				<NcTextField
					id="customPattern"
					v-model="customPattern"
					:placeholder="t('openregister', 'Copy of {name}')"
					:disabled="loading"
					@update:modelValue="updateCustomPreview" />
				<p class="help-text">
					Use {name} for the original name, {id} for the original ID
				</p>
			</div>
		</div>

		<NcNoteCard v-if="success" type="success">
			<p>Successfully copied {{ successCount }} of {{ totalCount }} objects</p>
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
			<NcButton
				v-if="success === null"
				:disabled="loading"
				variant="primary"
				@click="copyObjects()">
				<template #icon>
					<NcLoadingIcon v-if="loading" :size="20" />
					<ContentCopy v-if="!loading" :size="20" />
				</template>
				Copy All
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
	NcSelect,
	NcTextField,
} from '@nextcloud/vue'
import Cancel from 'vue-material-design-icons/Cancel.vue'
import ContentCopy from 'vue-material-design-icons/ContentCopy.vue'
import { copyName, copyPayload } from '../../services/objectCopy.js'

export default {
	name: 'MassCopyObjects',
	components: {
		NcDialog,
		NcButton,
		NcLoadingIcon,
		NcNoteCard,
		NcTextField,
		NcSelect,
		// Icons
		ContentCopy,
		Cancel,
	},

	data() {
		return {
			success: null,
			loading: false,
			error: false,
			successCount: 0,
			totalCount: 0,
			closeModalTimeout: null,
			selectedNamingPattern: null,
			customPattern: 'Copy of {name}',
			namingPatternOptions: [
				{
					label: 'Copy of [Original Name]',
					value: 'copy_of',
					pattern: 'Copy of {name}',
				},
				{
					label: '[Original Name] - Copy',
					value: 'name_copy',
					pattern: '{name} - Copy',
				},
				{
					label: '[Original Name] (Copy)',
					value: 'name_copy_parentheses',
					pattern: '{name} (Copy)',
				},
				{
					label: 'Custom pattern',
					value: 'custom',
					pattern: 'Copy of {name}',
				},
			],
		}
	},

	watch: {
		'navigationStore.dialog': function (newDialog) {
			if (newDialog === 'massCopyObjects') {
				this.selectedNamingPattern = this.namingPatternOptions[0]
				this.customPattern = 'Copy of {name}'
				this.success = null
				this.loading = false
				this.error = false
				this.successCount = 0
				this.totalCount = 0
			}
		},
	},

	methods: {
		/**
		 * @spec openspec/specs/entity-management-modals/spec.md
		 */
		closeDialog() {
			navigationStore.setDialog(false)
			clearTimeout(this.closeModalTimeout)
			this.success = null
			this.loading = false
			this.error = false
			this.successCount = 0
			this.totalCount = 0
			// Clear selection after closing
			objectStore.selectedObjects = []
		},

		/**
		 * @param object
		 * @spec exclude computed display helper for copy-name preview
		 */
		getPreviewName(object) {
			if (!object) return 'Preview Name'
			return copyName(object, this.namingPattern())
		},

		/**
		 * The naming pattern the user chose, or `Copy of {name}`.
		 *
		 * @return {string}
		 * @spec exclude mass-copy UI plumbing: resolves the chosen naming pattern
		 */
		namingPattern() {
			return this.selectedNamingPattern?.value === 'custom'
				? this.customPattern
				: this.selectedNamingPattern?.pattern || 'Copy of {name}'
		},

		/**
		 * @spec exclude form-state UI helper to refresh preview
		 */
		updateCustomPreview() {
			// Trigger reactivity for preview update
			this.$forceUpdate()
		},

		/**
		 * @spec exclude modal bulk-submit handler delegating to objectStore.saveObject
		 */
		async copyObjects() {
			this.loading = true
			this.error = false
			this.successCount = 0
			this.totalCount = objectStore.selectedObjects.length

			const pattern = this.namingPattern()

			try {
				for (const object of objectStore.selectedObjects) {
					if (await this.copyObject(object, pattern)) {
						this.successCount++
					}
				}

				if (this.successCount === 0) {
					throw new Error('Failed to copy any objects')
				}
				this.success = true
				// Refresh the object list to show the new copies
				objectStore.refreshObjectList()
				this.closeModalTimeout = setTimeout(this.closeDialog, 3000)
			} catch (error) {
				this.success = false
				this.error =
					error.message || 'An error occurred while copying the objects'
			} finally {
				this.loading = false
			}
		},

		/**
		 * Save one renamed copy under the original's register and schema.
		 *
		 * @param {object} object The original object.
		 * @param {string} pattern The naming pattern.
		 * @return {Promise<boolean>} Whether the copy was saved.
		 * @spec exclude mass-copy UI plumbing: saves one copy and logs a failure
		 */
		async copyObject(object, pattern) {
			try {
				const response = await objectStore.saveObject(
					copyPayload(object, pattern),
					{
						register: object['@self'].register,
						schema: object['@self'].schema,
					},
				)
				return Boolean(response.response.ok)
			} catch (error) {
				console.error('Failed to copy object:', object['@self']?.id, error)
				// Continue with other objects even if one fails
				return false
			}
		},
	},
}
</script>

<style scoped>
.form-group {
	margin-bottom: 1rem;
}

.form-group label {
	display: block;
	margin-bottom: 0.5rem;
	font-weight: bold;
}

.help-text {
	margin-top: 0.25rem;
	font-size: 0.9rem;
	color: var(--color-text-maxcontrast);
}
</style>

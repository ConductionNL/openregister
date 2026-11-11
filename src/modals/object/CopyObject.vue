<script setup>
import { translate as t } from '@nextcloud/l10n'
import { navigationStore, objectStore } from '../../store/store.js'
</script>

<template>
	<NcDialog
		v-if="navigationStore.dialog === 'copyObject'"
		:name="
			'Copy '
			+ (objectStore.objectItem?.['@self']?.name
				|| objectStore.objectItem?.name
				|| objectStore.objectItem?.['@self']?.title
				|| objectStore.objectItem?.id
				|| 'Object')
		"
		size="normal"
		noClose>
		<div v-if="success === null">
			<p>
				Create a copy of
				<b>{{
					objectStore.objectItem?.['@self']?.name
					|| objectStore.objectItem?.name
					|| objectStore.objectItem?.['@self']?.title
					|| objectStore.objectItem?.id
				}}</b>
			</p>

			<div class="form-group">
				<label for="copyName">Name for the copy:</label>
				<NcTextField
					id="copyName"
					v-model="copyName"
					:placeholder="defaultCopyName"
					:disabled="loading"
					@keyup.enter="copyObject" />
			</div>
		</div>

		<NcNoteCard v-if="success" type="success">
			<p>Object successfully copied as "{{ copyName }}"</p>
		</NcNoteCard>
		<NcNoteCard v-if="error" type="error">
			<p>{{ error }}</p>
		</NcNoteCard>

		<template #actions>
			<CloseCancelButton :label="success === null ? 'Cancel' : 'Close'" @click="closeDialog" />
			<NcButton
				v-if="success === null"
				:disabled="loading || !copyName.trim()"
				variant="primary"
				@click="copyObject()">
				<template #icon>
					<NcLoadingIcon v-if="loading" :size="20" />
					<ContentCopy v-if="!loading" :size="20" />
				</template>
				Copy
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
	NcTextField,
} from '@nextcloud/vue'
import ContentCopy from 'vue-material-design-icons/ContentCopy.vue'
import CloseCancelButton from '../../components/shared/CloseCancelButton.vue'
import { renameCopy, withoutOriginalMetadata } from '../../services/objectCopy.js'

export default {
	name: 'CopyObject',
	components: {
		NcDialog,
		NcButton,
		NcLoadingIcon,
		NcNoteCard,
		NcTextField,
		// Icons
		ContentCopy,
		CloseCancelButton,
	},

	data() {
		return {
			success: null,
			loading: false,
			error: false,
			copyName: '',
			closeModalTimeout: null,
		}
	},

	computed: {
		// A <script setup> binding is not on `this`, and a watch path reads `this`.
		navigationStore: () => navigationStore,

		/**
		 * @spec exclude computed display helper for default copy name
		 */
		defaultCopyName() {
			const originalName =
				objectStore.objectItem?.['@self']?.name
				|| objectStore.objectItem?.name
				|| objectStore.objectItem?.['@self']?.title
				|| `Object ${objectStore.objectItem?.['@self']?.id}`

			return `Copy of ${originalName}`
		},
	},

	watch: {
		'navigationStore.dialog': function (newDialog) {
			if (newDialog === 'copyObject') {
				this.copyName = this.defaultCopyName
				this.success = null
				this.loading = false
				this.error = false
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
			this.copyName = ''
		},

		/**
		 * @spec exclude modal submit handler delegating to objectStore.saveObject
		 */
		async copyObject() {
			if (this.refusedEmptyName()) {
				return
			}

			this.loading = true
			this.error = false

			try {
				await this.copyAndOpen()
			} catch (error) {
				this.showCopyFailure(error)
			} finally {
				this.loading = false
			}
		},

		/**
		 * Refuse a copy without a name, saying so.
		 *
		 * @return {boolean} True when the name is blank
		 */
		refusedEmptyName() {
			if (!this.copyName.trim()) {
				this.error = 'Please provide a name for the copy'
				return true
			}
			return false
		},

		/**
		 * Save the copy, say so, and open it after two seconds.
		 *
		 * @return {Promise<void>}
		 */
		async copyAndOpen() {
			const data = await this.createCopy()
			this.success = true
			this.closeModalTimeout = setTimeout(() => this.openCopy(data), 2000)
		},

		/**
		 * Save a renamed copy of the object, without its metadata.
		 *
		 * @return {Promise<object>} The saved copy
		 * @throws {Error} When nothing was saved
		 */
		async createCopy() {
			const objectToCopy = withoutOriginalMetadata(objectStore.objectItem)
			renameCopy(objectToCopy, this.copyName.trim())
			const data = await this.saveCopy(objectToCopy)

			if (!data) {
				throw new Error('Failed to create copy')
			}
			return data
		},

		/**
		 * Say why the copy failed.
		 *
		 * @param {Error} error What went wrong
		 * @return {void}
		 */
		showCopyFailure(error) {
			this.success = false
			this.error =
				error.message || 'An error occurred while copying the object'
		},

		/**
		 * Close the dialog and open the copy.
		 *
		 * @param {object} data The saved copy
		 * @return {void}
		 */
		openCopy(data) {
			this.closeDialog()

			// Set the new object as selected and open ViewObject modal
			objectStore.setObjectItem(data)
			navigationStore.setModal('viewObject')
		},

		/**
		 * Save the copy in the original's register and schema, then reload that list.
		 *
		 * @param {object} objectToCopy The copy to save
		 * @spec exclude UI plumbing — saves the copy through the object store
		 * @return {Promise<object|null>} The saved object
		 */
		async saveCopy(objectToCopy) {
			const register = objectStore.objectItem['@self'].register
			const schema = objectStore.objectItem['@self'].schema
			const type = `${register}-${schema}`
			objectStore.registerObjectType(type, schema, register)
			const data = await objectStore.saveObject(type, objectToCopy)
			await objectStore.refreshObjectList({ register, schema })
			return data
		},
	},
}
</script>

<style scoped lang="scss">
@use '../../styles/modals.scss' as *;

.form-group {
	margin-bottom: 1rem;
}

@include form-group-label;
</style>

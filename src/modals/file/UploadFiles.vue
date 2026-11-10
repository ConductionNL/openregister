<!-- eslint-disable -->
<script setup>
import { translate as t } from '@nextcloud/l10n'
import { navigationStore, objectStore } from '../../store/store.js'
</script>

<template>
	<NcModal
		v-if="navigationStore.modal === 'uploadFiles'"
		ref="modalRef"
		labelId="AddAttachmentModal"
		@close="closeModal()">
		<div class="modal__content TestMappingMainModal">
			<h2>{{ t('openregister', 'Add Attachment') }}</h2>

			<div class="labelAndShareContainer">
				<NcSelect
					v-bind="labelOptions"
					v-model="labelOptions.value"
					inputLabel="Label Options Value"
					:disabled="loading || tagsLoading"
					:loading="tagsLoading"
					:taggable="true"
					:multiple="true"
					:selectable="(option) => isSelectable(option)" />
				<NcCheckboxRadioSwitch
					v-model="share"
					:disabled="loading"
					:label="t('openregister', 'Auto share')"
					type="switch">
					{{ t('openregister', 'Auto share') }}
				</NcCheckboxRadioSwitch>
			</div>

			<div class="container">
				<div
					v-if="!labelOptions.value?.length || loading"
					class="filesListDragDropNotice tabPanelFileUpload">
					<div v-if="!labelOptions.value?.length">
						<NcNoteCard type="info">
							<p>
								{{
									t(
										'openregister',
										'Select or create labels, or select "No label" to add files',
									)
								}}
							</p>
						</NcNoteCard>
					</div>
					<UploadNotice
						:success="success"
						:error="error"
						disabled
						@add="openFileUpload()" />
				</div>
				<div
					v-if="labelOptions.value?.length && !loading"
					ref="dropzone"
					class="filesListDragDropNotice tabPanelFileUpload">
					<div v-if="checkForTooBigFiles(filesComputed)">
						<NcNoteCard type="warning">
							<p class="folderLink">
								{{
									t(
										'openregister',
										'To add files larger than or equal to 512MB, go to the',
									)
								}}
								<NcButton
									variant="secondary"
									class="folderLinkButton"
									:aria-label="t('openregister', 'Open folder')"
									@click="
										openFolder(
											objectStore.objectItem?.['@self']
												?.folder,
										)
									">
									<template #icon>
										<FolderOutline :size="20" />
									</template>
									{{ t('openregister', 'folder') }}
								</NcButton>
								{{ t('openregister', 'and add the files there.') }}
							</p>
						</NcNoteCard>
					</div>
					<UploadNotice
						:success="success"
						:error="error"
						@add="openFileUpload()" />
				</div>
				<div v-if="!filesComputed">
					{{ t('openregister', 'No files selected') }}
				</div>

				<table v-if="filesComputed" class="files-table">
					<thead>
						<tr class="files-table-tr">
							<th class="files-table-td-status" />
							<th scope="col">
								{{ t('openregister', 'File name') }}
							</th>
							<th scope="col">
								{{ t('openregister', 'Size') }}
							</th>
							<th scope="col">
								{{ t('openregister', 'Labels') }}
							</th>
						</tr>
					</thead>
					<tbody>
						<tr
							v-for="file of filesComputed"
							:key="file.name"
							class="files-table-tr">
							<td>
								<CheckCircle
									v-if="file.status === 'uploaded'"
									class="success"
									:size="20" />
								<NcLoadingIcon
									v-if="file.status === 'uploading'"
									:size="20" />
								<AlphaXCircle
									v-if="file.status === 'failed'"
									class="failed"
									:size="20" />
								<Exclamation
									v-if="file.status === 'too_large'"
									class="failed"
									:size="20" />
							</td>
							<td
								class="files-table-td-name"
								:class="{
									'files-table-name-wrong': getTooBigFiles(
										file.size,
									),
								}">
								<span class="files-table-name">{{
									getFileNameAndExtension(file.name).name
								}}</span>
								<span class="files-table-extension"
									>.{{
										getFileNameAndExtension(file.name).extension
									}}</span
								>
							</td>
							<td>
								{{ bytesToSize(file.size) }}
							</td>
							<td class="files-table-td-labels">
								<span
									v-if="editingTags !== file.name"
									class="files-list__row-action--inline files-list__row-action-system-tags">
									<ul
										v-if="file.tags && file.tags.length > 0"
										class="files-list__system-tags"
										:aria-label="
											t(
												'openregister',
												'Assigned collaborative tags',
											)
										">
										<li
											v-for="label of file.tags"
											:key="label"
											class="files-list__system-tag"
											:title="label">
											{{ label }}
										</li>
									</ul>
									<span
										v-if="!file.tags || file.tags.length === 0">
										{{ t('openregister', 'No labels') }}
									</span>
								</span>
								<NcSelect
									v-if="editingTags === file.name"
									v-model="editedTags"
									:disabled="loading || tagsLoading"
									:loading="tagsLoading"
									:taggable="true"
									:multiple="true"
									:aria-label-combobox="
										labelOptionsEdit.inputLabel
									"
									:options="labelOptionsEdit.options" />

								<span class="buttonContainer">
									<!-- Tags Buttons -->
									<NcButton
										v-if="editingTags !== file.name"
										:title="t('openregister', 'Edit labels')"
										:disabled="
											(editingTags
												&& editingTags !== file.name)
											|| loading
											|| file.status === 'too_large'
											|| tagsLoading
										"
										:aria-label="
											t(
												'openregister',
												'Edit tags for {name}',
												{ name: file.name },
											)
										"
										variant="secondary"
										class="editTagsButton"
										@click="
											((editingTags = file.name),
											(editedTags = file.tags))
										">
										<template #icon>
											<TagEdit :size="20" />
										</template>
									</NcButton>
									<NcButton
										v-if="editingTags === file.name"
										:title="t('openregister', 'Save labels')"
										variant="primary"
										:aria-label="
											t(
												'openregister',
												'Save tags for {name}',
												{ name: file.name },
											)
										"
										class="editTagsButton"
										@click="saveTags(file, editedTags)">
										<template #icon>
											<ContentSaveOutline :size="20" />
										</template>
									</NcButton>

									<!-- File Actions -->
									<NcButton
										v-if="file.status === 'failed'"
										:title="t('openregister', 'Retry upload')"
										variant="primary"
										@click="addAttachments(file)">
										<template #icon>
											<Refresh :size="20" />
										</template>
									</NcButton>
									<NcButton
										v-if="file.status === 'too_large'"
										:title="
											t('openregister', 'Remove from list')
										"
										variant="primary"
										@click="removeFile(file.name)">
										<template #icon>
											<Minus :size="20" />
										</template>
									</NcButton>
								</span>
							</td>
						</tr>
					</tbody>
				</table>
			</div>
		</div>
	</NcModal>
</template>

<script>
import {
	NcButton,
	NcCheckboxRadioSwitch,
	NcLoadingIcon,
	NcModal,
	NcNoteCard,
	NcSelect,
} from '@nextcloud/vue'
import { ref } from 'vue'
import AlphaXCircle from 'vue-material-design-icons/AlphaXCircle.vue'
import CheckCircle from 'vue-material-design-icons/CheckCircle.vue'
import ContentSaveOutline from 'vue-material-design-icons/ContentSaveOutline.vue'
import Exclamation from 'vue-material-design-icons/Exclamation.vue'
import FolderOutline from 'vue-material-design-icons/FolderOutline.vue'
import Minus from 'vue-material-design-icons/Minus.vue'
import Refresh from 'vue-material-design-icons/Refresh.vue'
import TagEdit from 'vue-material-design-icons/TagEdit.vue'
import UploadNotice from './UploadNotice.vue'
import * as nextcloudFiles from '../../composables/nextcloudFiles.js'
import { useFileSelection } from './../../composables/UseFileSelection.js'

const dropzone = ref(null)

const { openFileUpload, files, reset, setTags } = useFileSelection({
	allowMultiple: true,
	dropzone,
})

/**
 * The settings of a label multiselect before its options are loaded.
 *
 * @return {{inputLabel: string, multiple: boolean}} The settings
 */
function labelSelect() {
	return { inputLabel: 'Labels', multiple: true }
}

export default {
	name: 'UploadFiles',
	components: {
		UploadNotice,
		NcModal,
		NcButton,
		NcLoadingIcon,
		NcNoteCard,
		NcSelect,
		NcCheckboxRadioSwitch,
		TagEdit,
		ContentSaveOutline,
		FolderOutline,
		CheckCircle,
		AlphaXCircle,
		Refresh,
		Exclamation,
		Minus,
	},

	data() {
		return {
			loading: false,
			success: null,
			error: false,
			share: false,
			editingTags: null,
			editedTags: [],
			labelOptions: labelSelect(),
			labelOptionsEdit: labelSelect(),
			tagsLoading: false,
		}
	},

	computed: {
		/**
		 * @spec exclude Computed passthrough exposing objectStore.objectItem to the template; UI state helper.
		 */
		objectItem() {
			return objectStore.objectItem
		},

		/**
		 * @spec exclude Computed register-id extraction from the object's @self metadata; UI state helper.
		 */
		registerId() {
			const register = this.objectItem?.['@self']?.register
			return typeof register === 'object' && register !== null
				? register.id
				: register
		},

		/**
		 * @spec exclude Computed schema-id extraction from the object's @self metadata; UI state helper.
		 */
		schemaId() {
			const schema = this.objectItem?.['@self']?.schema
			return typeof schema === 'object' && schema !== null ? schema.id : schema
		},

		/**
		 * @spec exclude Computed object-id extraction from the object's @self metadata; UI state helper.
		 */
		objectId() {
			return this.objectItem?.['@self']?.id || this.objectItem?.id
		},

		/**
		 * @spec exclude Computed passthrough exposing the upload-queue ref to the template; UI state helper.
		 */
		filesComputed() {
			return files.value
		},
	},

	watch: {
		filesComputed: {
			/**
			 * @param {Array<File>|null} newFiles
			 * @param {Array<File>|null} _oldFiles
			 * @spec exclude Watcher auto-triggering upload when files are queued; UI reactivity plumbing.
			 */
			handler(newFiles, _oldFiles) {
				if (newFiles?.length) {
					this.addAttachments()
				}
			},

			deep: true,
		},

		labelOptions: {
			/**
			 * @spec exclude UI handler/computed reactive label-options sync
			 */
			handler() {
				setTags(this.getLabels())
			},

			deep: true,
		},
	},

	mounted() {
		this.getAllTags()
	},

	methods: {
		/**
		 * @spec exclude Modal close handler resetting upload state and navigationStore.modal; UI plumbing.
		 */
		closeModal() {
			this.success = null
			this.error = null
			reset()
			navigationStore.setModal(false)
		},

		/**
		 * @param {number} bytes
		 * @spec exclude Human-readable byte-size formatter for file display; UI presentation helper.
		 */
		bytesToSize(bytes) {
			const sizes = ['Bytes', 'KB', 'MB', 'GB', 'TB']
			if (bytes === 0) return 'n/a'
			const i = parseInt(Math.floor(Math.log(bytes) / Math.log(1024)))
			if (i === 0 && sizes[i] === 'Bytes') return '< 1 KB'
			if (i === 0) return bytes + ' ' + sizes[i]
			return (bytes / Math.pow(1024, i)).toFixed(1) + ' ' + sizes[i]
		},

		/**
		 * @param {string} fullname
		 * @spec exclude Splits a filename into name + extension for display; UI presentation helper.
		 */
		getFileNameAndExtension(fullname) {
			const lastDot = fullname.lastIndexOf('.')
			const name = fullname.slice(0, lastDot)
			const extension = fullname.slice(lastDot + 1)
			return { name, extension }
		},

		/**
		 * @param {Array<File>|null} files
		 * @spec exclude Flags over-size files in the queue with a status; UI validation helper.
		 */
		checkForTooBigFiles(files) {
			if (!files) return false
			const wrongFiles = files.filter((file) => {
				return this.getTooBigFiles(file.size)
			})

			wrongFiles.forEach((file) => {
				file.status = 'too_large'
			})

			return wrongFiles.length > 0
		},

		getTooBigFiles(size) {
			return size > 536870480 // 512MB
		},

		/**
		 * @param {string} option
		 * @spec exclude Mutual-exclusion guard for the tag multiselect ("No label" vs real tags); UI selection helper.
		 */
		isSelectable(option) {
			const noLabel = t('openregister', 'No label')
			const chosen = this.labelOptions.value
			if (chosen?.includes(noLabel)) {
				return option === noLabel
			}
			return !(chosen?.length >= 1 && option === noLabel)
		},

		/**
		 * @spec exclude Maps the "No label" sentinel to null for the tag value; UI selection helper.
		 */
		getLabels() {
			if (this.labelOptions.value?.includes(t('openregister', 'No label'))) {
				return null
			} else {
				return this.labelOptions.value
			}
		},

		/**
		 * @spec exclude Loads available tags via objectStore.fetchTags to populate the label multiselect; UI form-loading plumbing.
		 */
		getAllTags() {
			this.tagsLoading = true
			// @TODO - this functionality always calls and therefore always has to wait.
			// split this up into a computed value and action, the computed value can dervive tags from `objectStore.getTags` (getter) or `objectStore.tags` (state)
			// this avoids the waiting time in later calls
			objectStore
				.fetchTags()
				.then((tags) => this.applyTags(tags))
				.finally(() => {
					this.tagsLoading = false
				})
		},

		/**
		 * Offer the stored tags as labels: with "No label" first when adding,
		 * without it when editing, each tag once.
		 *
		 * @param {Array<string>} tags The stored tags
		 * @spec exclude Fills the label multiselects; UI form-loading plumbing.
		 * @return {void}
		 */
		applyTags(tags) {
			const noLabel = t('openregister', 'No label')
			this.labelOptions.options = Array.from(new Set([noLabel, ...tags]))
			this.labelOptionsEdit.options = Array.from(new Set(tags))
		},

		openFolder: nextcloudFiles.openFolder,

		/**
		 * @param {File} file
		 * @param {Array<string>} editedTags
		 * @spec exclude Applies edited tags to a queued file and re-triggers upload; UI selection plumbing.
		 */
		saveTags(file, editedTags) {
			file.tags = editedTags
			file.status = 'pending'
			this.addAttachments()

			this.editingTags = null
			this.editedTags = []
		},

		/**
		 * @param {string} fileName
		 * @spec exclude Removes a file from the upload queue; UI file-picker plumbing.
		 */
		removeFile(fileName) {
			reset(fileName)
			if (this.editingTags === fileName) {
				this.editingTags = null
			}
		},

		/**
		 * @spec exclude Computed-style enablement guard for the upload action; UI state helper.
		 */
		checkIfDisabled() {
			if (
				this.objectStore.objectItem.downloadUrl
				|| this.objectStore.objectItem.title
			)
				return true
			return false
		},

		/**
		 * @param {File|null} specificFile
		 * @spec exclude Uploads queued attachments to the object via objectStore; entity-file persistence lives in the store, this is modal orchestration plumbing.
		 */
		async addAttachments(specificFile) {
			if (!this.hasObjectContext()) {
				this.error = 'Missing object context'
				return
			}
			this.loading = true
			this.error = null
			try {
				await this.uploadAll(this.filesToSend(specificFile))
			} catch (err) {
				// This block generally catches unexpected errors.
				this.error = err.response?.data?.error ?? err
			} finally {
				this.loading = false
			}
		},

		/**
		 * The files an upload sends: the one given, or every uploadable queued file.
		 *
		 * @param {File|null} specificFile The one file to send, if any
		 * @return {Array<File>} The files
		 * @spec exclude UI state helper selecting the files to upload
		 */
		filesToSend(specificFile) {
			return specificFile ? [specificFile] : this.uploadableFiles()
		},

		/**
		 * Whether the modal knows the register, schema and object to upload to.
		 *
		 * @return {boolean} Whether all three are set
		 * @spec exclude UI state guard for the upload action
		 */
		hasObjectContext() {
			return Boolean(this.registerId && this.schemaId && this.objectId)
		},

		/**
		 * The queued files that are not uploaded or uploading yet and not too big.
		 *
		 * @return {Array<File>} The files
		 * @spec exclude UI state helper selecting the files to upload
		 */
		uploadableFiles() {
			return this.filesComputed
				.filter(
					(file) =>
						file.status !== 'uploaded' && file.status !== 'uploading',
				)
				.filter((file) => !this.getTooBigFiles(file.size))
		},

		/**
		 * Upload files to the object, refresh its files and record the outcome.
		 *
		 * @param {Array<File>} files The files
		 * @spec exclude Uploads attachments via objectStore; modal orchestration plumbing.
		 */
		async uploadAll(files) {
			if (files.length === 0) {
				return
			}
			const type = this.objectType()
			const calls = await Promise.all(
				files.map((file) => this.uploadOne(type, file)),
			)
			this.getAllTags()
			await objectStore.fetchFiles(type, this.objectId)
			this.recordUploads(calls)
		},

		/**
		 * The object type of the register and schema, registered with the
		 * object store when it does not know it yet.
		 *
		 * @return {string} The object type
		 * @spec exclude Uploads attachments via objectStore; modal orchestration plumbing.
		 */
		objectType() {
			const type = `${this.registerId}-${this.schemaId}`
			if (!objectStore.objectTypes?.includes(type)) {
				objectStore.registerObjectType(type, this.schemaId, this.registerId)
			}
			return type
		},

		/**
		 * Record the outcome of the uploads: the first failure, or success.
		 *
		 * @param {Array} calls The upload results, each [succeeded, error]
		 * @spec exclude Uploads attachments via objectStore; modal orchestration plumbing.
		 * @return {void}
		 */
		recordUploads(calls) {
			const failed = calls.filter((result) => result[0] === false)
			if (failed.length > 0) {
				this.error = failed[0][1].message
			} else {
				this.success = true
			}
		},

		/**
		 * Upload one file and track its status on the file.
		 *
		 * @param {string} type The register-schema object type
		 * @param {File} file The file
		 * @return {Promise<Array>} [true, response] or [false, error]
		 * @spec exclude Uploads one attachment via objectStore; modal orchestration plumbing.
		 */
		async uploadOne(type, file) {
			file.status = 'uploading'
			try {
				const responseJson = await objectStore.uploadFiles(
					type,
					this.objectId,
					this.uploadForm(file),
				)
				file.status = 'uploaded'
				return [true, responseJson]
			} catch (error) {
				file.status = 'failed'
				return [false, error]
			}
		},

		/**
		 * The form data that uploads one file with its tags.
		 *
		 * @param {File} file The file
		 * @return {FormData} The form data
		 * @spec exclude UI plumbing building the upload form data
		 */
		uploadForm(file) {
			const formData = new FormData()
			formData.append('files[]', file)
			if (Array.isArray(file.tags)) {
				file.tags.forEach((tag) => formData.append('tags[]', tag))
			}
			if (this.share) {
				formData.append('share', '1')
			}
			return formData
		},
	},
}
</script>

<style>
div[class='modal-container']:has(.TestMappingMainModal) {
	width: clamp(1000px, 100%, 1200px) !important;
}

.modal__content {
	margin: var(--OC-margin-50);
	text-align: center;
}
</style>

<style scoped>
.zaakDetailsContainer {
	margin-block-start: var(--OC-margin-20);
	margin-inline-start: var(--OC-margin-20);
	margin-inline-end: var(--OC-margin-20);
}

.folderLink {
	display: flex;
	align-items: center;
}

.folderLinkButton {
	margin-inline-start: 1ch;
	margin-inline-end: 1ch;
}

.importButtonContainer {
	display: flex;
	justify-content: flex-end;
}

.container {
	padding-inline: 25px;
}

.files-table-name-wrong > span {
	color: #ff0000 !important;
}

.files-table {
	width: 100%;
	border-collapse: collapse;
}

.files-table-td-name {
	overflow: hidden;
	white-space: nowrap;
	text-overflow: ellipsis;
	max-width: 75ch;
}

.files-table-td-name span {
	float: left;
	overflow: hidden;
	white-space: nowrap;
	text-overflow: ellipsis;
	max-width: calc(100% - 15%);
}

.files-table-td-status {
	width: 40px;
}

.files-table-name {
	color: var(--color-main-text);
}

.files-table-extension {
	color: var(--color-text-maxcontrast);
}

.files-table-tr {
	color: var(--color-text-maxcontrast);
	border-bottom: 1px solid var(--color-border);
}

.files-table-tr:hover {
	background-color: var(--color-background-hover);
	--color-text-maxcontrast: var(--color-main-text);
	--color-border: var(--color-border-dark);
}

.files-table-tr > td {
	height: 55px;
}

.files-table-remove-button {
	text-align: -webkit-right;
}

.files-list__row-icon {
	position: relative;
	display: flex;
	overflow: visible;
	align-items: center;
	flex: 0 0 32px;
	justify-content: center;
	width: 32px;
	height: 100%;
	margin-right: var(--checkbox-padding);
	color: var(--color-primary-element);
}

.files-list__row-action-system-tags {
	margin-right: 7px;
	display: flex;
}

.files-list__system-tags {
	--min-size: 32px;
	display: flex;
	justify-content: center;
	align-items: center;
	min-width: calc(var(--min-size) * 2);
	max-width: 300px;
}

.files-list__system-tag {
	padding: 5px 10px;
	border: 1px solid;
	border-radius: var(--border-radius-pill);
	border-color: var(--color-border);
	color: var(--color-text-maxcontrast);
	height: var(--min-size);
	white-space: nowrap;
	overflow: hidden;
	text-overflow: ellipsis;
	line-height: 22px;
	text-align: center;
	box-sizing: border-box;
}

.files-list__system-tag:not(:first-child) {
	margin-inline-start: 5px;
}

.editTagsButton {
	margin-inline-end: 3px;
	margin-inline-start: 3px;
}

.files-table-td-labels {
	display: flex;
	justify-content: space-between;
	text-align: unset;
	align-items: center;
	box-sizing: border-box;
}

.labelAndShareContainer {
	display: flex;
	justify-content: center;
	align-items: end;
	margin-block-end: 15px;
	gap: 10px;
}

.success {
	color: var(--color-success);
}

.failed {
	color: var(--color-error);
}

.buttonContainer {
	display: flex;
	gap: 10px;
}
</style>

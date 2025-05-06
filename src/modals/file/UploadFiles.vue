<!-- eslint-disable -->
<script setup>
import { navigationStore, objectStore } from '../../store/store.js'
</script>

<template>
	<NcModal v-if="navigationStore.modal === 'uploadFiles'"
		ref="modalRef"
		label-id="AddAttachmentModal"
		@close="closeModal()">
		<div class="modal__content TestMappingMainModal">
			<h2>Bijlage toevoegen</h2>

			<div class="labelAndShareContainer">
				<NcSelect v-bind="labelOptions"
					v-model="labelOptions.value"
					:disabled="loading || tagsLoading"
					:loading="tagsLoading"
					:taggable="true"
					:multiple="true"
					:selectable="(option) => isSelectable(option)" />
				<NcCheckboxRadioSwitch :disabled="loading"
					label="Automatisch delen"
					type="switch"
					:checked.sync="share">
					Automatisch delen
				</NcCheckboxRadioSwitch>
			</div>

			<div class="container">
				<div v-if="!labelOptions.value?.length || loading" class="filesListDragDropNotice" :class="'tabPanelFileUpload'">
					<div v-if="!labelOptions.value?.length">
						<NcNoteCard type="info">
							<p>Selecteer of maak labels aan of selecteer "Geen label" om bestanden toe te voegen</p>
						</NcNoteCard>
					</div>
					<div v-if="success !== null || error">
						<NcNoteCard v-if="success" type="success">
							<p>Bestanden succesvol toegevoegd</p>
						</NcNoteCard>
						<NcNoteCard v-if="error && !success" type="error">
							<p>Er is iets fout gegaan bij het toevoegen van bestanden</p>
						</NcNoteCard>
						<NcNoteCard v-if="error && !success" type="error">
							<p>{{ error }}</p>
						</NcNoteCard>
						<div v-if="false">
							<NcNoteCard type="error">
								<p>Selecteer bestanden met de juiste extensie</p>
							</NcNoteCard>
						</div>
					</div>
					<div class="filesListDragDropNoticeWrapper" :class="{ 'filesListDragDropNoticeWrapper--disabled': !labelOptions.value?.length || loading }">
						<div class="filesListDragDropNoticeWrapperIcon">
							<TrayArrowDown :size="48" />
							<h3 class="filesListDragDropNoticeTitle">
								Sleep een bestand of bestanden hierheen
							</h3>
						</div>

						<h3 class="filesListDragDropNoticeTitle">
							Of
						</h3>

						<div class="filesListDragDropNoticeTitle">
							<NcButton
								:disabled="loading || !labelOptions.value?.length"
								type="primary"
								@click="openFileUpload()">
								<template #icon>
									<Plus :size="20" />
								</template>
								Een bestand of bestanden toevoegen
							</NcButton>
						</div>
					</div>
				</div>
				<div v-if="labelOptions.value?.length && !loading"
					class="filesListDragDropNotice"
					:class="'tabPanelFileUpload'">
					<div v-if="!labelOptions.value?.length">
						<NcNoteCard type="info">
							<p>Selecteer of maak labels aan of selecteer "Geen label" om bestanden toe te voegen</p>
						</NcNoteCard>
					</div>
					<div v-if="checkForTooBigFiles(filesComputed)">
						<NcNoteCard type="warning">
							<p class="folderLink">
								Als je bestanden groter of gelijk aan 512MB wilt toevoegen, ga dan naar de
								<NcButton type="secondary"
									class="folderLinkButton"
									aria-label="Open map"
									@click="openFolder(objectStore.objectItem?.['@self']?.folder)">
									<template #icon>
										<FolderOutline :size="20" />
									</template>
									map
								</NcButton>
								en voeg de bestanden daar toe.
							</p>
						</NcNoteCard>
					</div>
					<div v-if="success !== null || error">
						<NcNoteCard v-if="success" type="success">
							<p>Bestanden succesvol toegevoegd</p>
						</NcNoteCard>
						<NcNoteCard v-if="error && !success" type="error">
							<p>Er is iets fout gegaan bij het toevoegen van bestanden</p>
						</NcNoteCard>
						<NcNoteCard v-if="error && !success" type="error">
							<p>{{ error }}</p>
						</NcNoteCard>
						<div v-if="false">
							<NcNoteCard type="error">
								<p>Selecteer bestanden met de juiste extensie</p>
							</NcNoteCard>
						</div>
					</div>
					<div class="filesListDragDropNoticeWrapper" :class="{ 'filesListDragDropNoticeWrapper--disabled': !labelOptions.value?.length }">
						<div class="filesListDragDropNoticeWrapperIcon">
							<TrayArrowDown :size="48" />
							<h3 class="filesListDragDropNoticeTitle">
								Sleep een bestand of bestanden hierheen
							</h3>
						</div>

						<h3 class="filesListDragDropNoticeTitle">
							Of
						</h3>

						<div class="filesListDragDropNoticeTitle">
							<NcButton
								:disabled="loading || !labelOptions.value?.length"
								type="primary"
								@click="openFileUpload()">
								<template #icon>
									<Plus :size="20" />
								</template>
								Een bestand of bestanden toevoegen
							</NcButton>
						</div>
					</div>
				</div>
				<div v-if="!filesComputed">
					Geen bestanden geselecteerd
				</div>

				<table v-if="filesComputed" class="files-table">
					<thead>
						<tr class="files-table-tr">
							<th class="files-table-td-status" />
							<th>
								Bestandsnaam
							</th>
							<th>
								Grootte
							</th>
							<th>
								Labels
							</th>
						</tr>
					</thead>
					<tbody>
						<tr v-for="file of filesComputed" :key="file.name" class="files-table-tr">
							<td>
								<CheckCircle v-if="file.status === 'uploaded'" class="success" :size="20" />
								<NcLoadingIcon v-if="file.status === 'uploading'" :size="20" />
								<AlphaXCircle v-if="file.status === 'failed'" class="failed" :size="20" />
								<Exclamation v-if="file.status === 'too_large'" class="failed" :size="20" />
							</td>
							<td class="files-table-td-name" :class="{ 'files-table-name-wrong': getTooBigFiles(file.size) }">
								<span class="files-table-name">{{ getFileNameAndExtension(file.name).name }}</span>
								<span class="files-table-extension">.{{ getFileNameAndExtension(file.name).extension }}</span>
							</td>
							<td>
								{{ bytesToSize(file.size) }}
							</td>
							<td class="files-table-td-labels">
								<span v-if="editingTags !== file.name"
									class="files-list__row-action--inline files-list__row-action-system-tags">
									<ul v-if="file.tags && file.tags.length > 0" class="files-list__system-tags" aria-label="Assigned collaborative tags">
										<li v-for="label of file.tags"
											:key="label"
											class="files-list__system-tag"
											:title="label">
											{{ label }}
										</li>
									</ul>
									<span v-if="!file.tags || file.tags.length === 0">
										Geen labels
									</span>
								</span>
								<NcSelect
									v-if="editingTags === file.name"
									v-model="editedTags"
									:disabled="loading || tagsLoading"
									:loading="tagsLoading"
									:taggable="true"
									:multiple="true"
									:aria-label-combobox="labelOptionsEdit.inputLabel"
									:options="labelOptionsEdit.options" />

								<span class="buttonContainer">
									<!-- Tags Buttons -->
									<NcButton
										v-if="editingTags !== file.name"
										v-tooltip="'Labels bewerken'"
										:disabled="editingTags && editingTags !== file.name || loading || file.status === 'too_large' || tagsLoading"
										:aria-label="`edit tags for ${file.name}`"
										type="secondary"
										class="editTagsButton"
										@click="editingTags = file.name, editedTags = file.tags">
										<template #icon>
											<TagEdit :size="20" />
										</template>
									</NcButton>
									<NcButton
										v-if="editingTags === file.name"
										v-tooltip="'Labels opslaan'"
										type="primary"
										:aria-label="`save tags for ${file.name}`"
										class="editTagsButton"
										@click="saveTags(file, editedTags)">
										<template #icon>
											<ContentSaveOutline :size="20" />
										</template>
									</NcButton>

									<!-- File Actions -->
									<NcButton v-if="file.status === 'failed'"
										v-tooltip="'Opnieuw uploaden'"
										type="primary"
										@click="addAttachments(file)">
										<template #icon>
											<Refresh :size="20" />
										</template>
									</NcButton>
									<NcButton
										v-if="file.status === 'too_large'"
										v-tooltip="'Verwijder uit lijst'"
										type="primary"
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
import { NcButton, NcLoadingIcon, NcModal, NcNoteCard, NcSelect, NcCheckboxRadioSwitch } from '@nextcloud/vue'
import { useFileSelection } from './../../composables/UseFileSelection.js'
import Plus from 'vue-material-design-icons/Plus.vue'
import TrayArrowDown from 'vue-material-design-icons/TrayArrowDown.vue'
import TagEdit from 'vue-material-design-icons/TagEdit.vue'
import ContentSaveOutline from 'vue-material-design-icons/ContentSaveOutline.vue'
import FolderOutline from 'vue-material-design-icons/FolderOutline.vue'
import CheckCircle from 'vue-material-design-icons/CheckCircle.vue'
import AlphaXCircle from 'vue-material-design-icons/AlphaXCircle.vue'
import Refresh from 'vue-material-design-icons/Refresh.vue'
import Exclamation from 'vue-material-design-icons/Exclamation.vue'
import Minus from 'vue-material-design-icons/Minus.vue'

const { openFileUpload, files, reset, setTags } = useFileSelection({
	allowMultiple: true,
})

export default {
	name: 'UploadFiles',
	components: {
		NcModal,
		NcButton,
		NcLoadingIcon,
		NcNoteCard,
		NcSelect,
		NcCheckboxRadioSwitch,
		Plus,
		TrayArrowDown,
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
			labelOptions: {
				inputLabel: 'Labels',
				multiple: true,
			},
			labelOptionsEdit: {
				inputLabel: 'Labels',
				multiple: true,
			},
			tagsLoading: false,
		}
	},
	computed: {
		objectItem() {
			return objectStore.objectItem
		},
		registerId() {
			return this.objectItem?.['@self']?.register
		},
		schemaId() {
			return this.objectItem?.['@self']?.schema
		},
		objectId() {
			return this.objectItem?.['@self']?.id
		},
		filesComputed() {
			return files.value
		},
	},
	watch: {
		filesComputed: {
			handler(newFiles, oldFiles) {
				if (newFiles?.length) {
					this.addAttachments()
				}
			},
			deep: true,
		},
		labelOptions: {
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
		closeModal() {
			this.success = null
			this.error = null
			reset()
			navigationStore.setModal(false)
		},
		bytesToSize(bytes) {
			const sizes = ['Bytes', 'KB', 'MB', 'GB', 'TB']
			if (bytes === 0) return 'n/a'
			const i = parseInt(Math.floor(Math.log(bytes) / Math.log(1024)))
			if (i === 0 && sizes[i] === 'Bytes') return '< 1 KB'
			if (i === 0) return bytes + ' ' + sizes[i]
			return (bytes / Math.pow(1024, i)).toFixed(1) + ' ' + sizes[i]
		},

		getFileNameAndExtension(fullname) {
			const lastDot = fullname.lastIndexOf('.')
			const name = fullname.slice(0, lastDot)
			const extension = fullname.slice(lastDot + 1)
			return { name, extension }
		},

		checkForTooBigFiles(files) {
			if (!files) return false
			const wrongFiles = files.filter(file => {
				return this.getTooBigFiles(file.size)
			})

			wrongFiles.forEach(file => {
				file.status = 'too_large'
			})

			return wrongFiles.length > 0
		},

		getTooBigFiles(size) {
			return size > 536870480 // 512MB
		},

		isSelectable(option) {
			if (this.labelOptions.value?.includes('Geen label') && option !== 'Geen label') {
				return false
			}
			if (this.labelOptions.value?.length >= 1 && !this.labelOptions.value?.includes('Geen label') && option === 'Geen label') {
				return false
			}
			return true
		},

		getLabels() {
			if (this.labelOptions.value?.includes('Geen label')) {
				return null
			} else {
				return this.labelOptions.value
			}
		},

		getAllTags() {
			this.tagsLoading = true
			objectStore.getTags().then(({ response, data }) => {

				const newLabelOptions = []
				const newLabelOptionsEdit = []

				newLabelOptions.push('Geen label')

				const tags = data.map((tag) => tag)

				newLabelOptions.push(...tags)
				newLabelOptionsEdit.push(...tags)

				this.labelOptions.options = newLabelOptions
				this.labelOptionsEdit.options = newLabelOptionsEdit
			}).finally(() => {
				this.tagsLoading = false
			})
		},

		/**
		 * Opens the folder URL in a new tab after parsing the encoded URL and converting to Nextcloud format
		 * @param {string} url - The encoded folder URL to open (e.g. "Open Registers\/Publicatie Register\/Publicatie\/123")
		 */
		 openFolder(url) {
			// Parse the encoded URL by replacing escaped characters
			const decodedUrl = url.replace(/\\\//g, '/')

			// Ensure URL starts with forward slash
			const normalizedUrl = decodedUrl.startsWith('/') ? decodedUrl : '/' + decodedUrl

			// Construct the proper Nextcloud Files app URL with the normalized path
			// Use window.location.origin to get the current domain instead of hardcoding
			const nextcloudUrl = `${window.location.origin}/index.php/apps/files/files?dir=${encodeURIComponent(normalizedUrl)}`

			// Open URL in new tab
			window.open(nextcloudUrl, '_blank')
		},

		saveTags(file, editedTags) {
			file.tags = editedTags
			file.status = 'pending'
			this.addAttachments()

			this.editingTags = null
			this.editedTags = []
		},

		removeFile(fileName) {
			reset(fileName)
			if (this.editingTags === fileName) {
				this.editingTags = null
			}
		},
		checkIfDisabled() {
			if (this.objectStore.objectItem.downloadUrl || this.objectStore.objectItem.title) return true
			return false
		},

		async addAttachments(specificFile = null) {
			if (!this.registerId || !this.schemaId || !this.objectId) {
				this.error = 'Missing object context'
				return
			}
			this.loading = true
			this.error = null

			try {
				let filesToUpload = []

				// only get the specific file if it is passed
				if (specificFile) {
					filesToUpload = [specificFile]
				} else {
					// filter out successful and pending files
					filesToUpload = this.filesComputed.filter(file => file.status !== 'uploaded' && file.status !== 'uploading')

					// filter out files too large
					filesToUpload = filesToUpload.filter(file => !this.getTooBigFiles(file.size))
				}

				if (filesToUpload.length === 0) {
					this.loading = false
					return
				}

				// file calls
				const calls = filesToUpload.map(async (file) => {
					// Set status to 'uploading'
					file.status = 'uploading'
					try {
						const response = await objectStore.uploadFiles({
							register: this.registerId,
							schema: this.schemaId,
							objectId: this.objectId,
							files: [file],
							labels: this.labelOptions.value || [],
							share: this.share,
						})
						// Set status to 'uploaded' on success
						if (response.status === 200) file.status = 'uploaded'
						else file.status = 'failed'

						return response
					} catch (error) {
						// Set status to 'failed' on error
						file.status = 'failed'
						throw error
					}
				})

				const results = await Promise.allSettled(calls)

				this.getAllTags()

				// Refresh files for the object
				await objectStore.getFiles({
					id: this.objectId,
					register: this.registerId,
					schema: this.schemaId,
				})

				const failed = results.filter(result => result.status === 'rejected')

				if (failed.length > 0) {
					this.error = failed[0].reason
				} else {
					this.success = true
				}
			} catch (err) {
				// This block generally catches unexpected errors.
				this.error = err.response?.data?.error ?? err
			} finally {
				this.loading = false
			}
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

.filesListDragDropNoticeWrapper--disabled{
	opacity: 0.4;
}

.success {
    color: green;
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

.files-table-td-name{
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
	min-width: calc(var(--min-size)* 2);
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
	-webkit-box-align: end;
	box-sizing: border-box;
}

.labelAndShareContainer{
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

/**
 * UploadFiles Modal
 * Handles file uploads and tag selection for objects
 * @category Modals
 * @package OpenRegister
 * @author Your Name
 * @copyright 2024 Your Company
 * @license AGPL-3.0
 * @version 1.0.0
 * @link https://your-app-link.example.com
 */

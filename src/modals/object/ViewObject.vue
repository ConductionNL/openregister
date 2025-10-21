/**
 * @file ViewObject.vue
 * @module Modals/Object
 * @author Your Name
 * @copyright 2024 Your Organization
 * @license AGPL-3.0-or-later
 * @version 1.0.0
 */

<script setup>
import { objectStore, navigationStore, registerStore, schemaStore } from '../../store/store.js'
</script>

<template>
	<div>
		<NcDialog v-if="navigationStore.modal === 'viewObject'"
			:name="getModalTitle()"
			size="large"
			:can-close="true"
			@update:open="handleDialogClose">
			<div class="formContainer viewObjectDialog">
				<!-- Display Object -->
				<div>
					<div class="tabContainer">
						<BTabs v-model="activeTab" content-class="mt-3" justified>
							<BTab title="Properties" active>
								<div class="viewTableContainer">
									<table class="viewTable">
										<thead>
											<tr class="viewTableRow">
												<th class="tableColumnConstrained">
													Property
												</th>
												<th class="tableColumnExpanded">
													Value
												</th>
											</tr>
										</thead>
										<tbody>
											<tr
												v-for="([key, value]) in objectProperties"
												:key="key"
												class="viewTableRow"
												:class="{
													'selected-row': selectedProperty === key,
													'edited-row': formData[key] !== undefined,
													'non-editable-row': !isPropertyEditable(key, formData[key] !== undefined ? formData[key] : value),
													...getPropertyValidationClass(key, value)
												}"
												@click="handleRowClick(key, $event)">
												<td class="tableColumnConstrained prop-cell">
													<div class="prop-cell-content">
														<AlertCircle v-if="getPropertyValidationClass(key, value) === 'property-invalid'"
															v-tooltip="getPropertyErrorMessage(key, value)"
															class="validation-icon error-icon"
															:size="16" />
														<Alert v-else-if="getPropertyValidationClass(key, value) === 'property-warning'"
															v-tooltip="getPropertyWarningMessage(key, value)"
															class="validation-icon warning-icon"
															:size="16" />
														<Plus v-else-if="getPropertyValidationClass(key, value) === 'property-new'"
															v-tooltip="getPropertyNewMessage(key)"
															class="validation-icon new-icon"
															:size="16" />
														<LockOutline v-else-if="!isPropertyEditable(key, formData[key] !== undefined ? formData[key] : value)"
															v-tooltip="getEditabilityWarning(key, formData[key] !== undefined ? formData[key] : value)"
															class="validation-icon lock-icon"
															:size="16" />
														<span
															v-tooltip="getPropertyTooltip(key)">
															{{ getPropertyDisplayName(key) }}
														</span>
													</div>
												</td>
												<td class="tableColumnExpanded value-cell">
													<div v-if="selectedProperty === key && isPropertyEditable(key, formData[key] !== undefined ? formData[key] : value)" class="value-input-container" @click.stop>
														<!-- Boolean properties -->
														<NcCheckboxRadioSwitch
															v-if="getPropertyInputComponent(key) === 'NcCheckboxRadioSwitch'"
															:checked="formData[key] !== undefined ? formData[key] : value"
															type="switch"
															@update:checked="updatePropertyValue(key, $event)">
															{{ getPropertyDisplayName(key) }}
														</NcCheckboxRadioSwitch>

														<!-- Date/Time properties -->
														<NcDateTimePickerNative
															v-else-if="getPropertyInputComponent(key) === 'NcDateTimePickerNative'"
															:value="formData[key] !== undefined ? formData[key] : value"
															:type="getPropertyInputType(key)"
															:label="getPropertyDisplayName(key)"
															@update:value="updatePropertyValue(key, $event)" />

														<!-- Text/Number properties -->
														<NcTextField
															v-else
															ref="propertyValueInput"
															:value="String(formData[key] !== undefined ? formData[key] : value || '')"
															:type="getPropertyInputType(key)"
															:placeholder="getPropertyDisplayName(key)"
															:min="getPropertyMinimum(key)"
															:max="getPropertyMaximum(key)"
															:step="getPropertyStep(key)"
															@update:value="updatePropertyValue(key, $event)" />
													</div>
													<div v-else>
														<template v-if="formData[key] !== undefined">
															<!-- Show edited value -->
															<pre
																v-if="typeof formData[key] === 'object' && formData[key] !== null"
																v-tooltip="'JSON object (edited)'"
																class="json-value">{{ formatValue(formData[key]) }}</pre>
															<span
																v-else-if="isValidDate(formData[key])"
																v-tooltip="`Date: ${new Date(formData[key]).toISOString()} (edited)`">{{ new Date(formData[key]).toLocaleString() }}</span>
															<span
																v-else
																v-tooltip="currentSchema?.properties?.[key]?.description || `Property: ${key} (edited)`">{{ getDisplayValue(key, value) }}</span>
														</template>
														<template v-else>
															<!-- Show original value -->
															<pre
																v-if="typeof value === 'object' && value !== null"
																v-tooltip="'JSON object'"
																class="json-value">{{ formatValue(value) }}</pre>
															<span
																v-else-if="isValidDate(value)"
																v-tooltip="`Date: ${new Date(value).toISOString()}`">{{ new Date(value).toLocaleString() }}</span>
															<span
																v-else
																v-tooltip="currentSchema?.properties?.[key]?.description || `Property: ${key}`">{{ getDisplayValue(key, value) }}</span>
														</template>
													</div>
												</td>
											</tr>
										</tbody>
									</table>
								</div>
							</BTab>
							<BTab v-if="!isNewObject" title="Metadata">
								<div class="viewTableContainer">
									<table class="viewTable">
										<thead>
											<tr class="viewTableRow">
												<th class="tableColumnConstrained">
													Metadata
												</th>
												<th class="tableColumnExpanded">
													Value
												</th>
												<th class="tableColumnActions">
													Actions
												</th>
											</tr>
										</thead>
										<tbody>
											<tr
												v-for="([key, value, hasAction]) in metadataProperties"
												:key="key"
												class="viewTableRow">
												<td class="tableColumnConstrained">
													{{ key }}
												</td>
												<td class="tableColumnExpanded">
													{{ value }}
												</td>
												<td class="tableColumnActions">
													<NcButton
														v-if="hasAction && key === 'ID'"
														class="copy-button"
														size="small"
														@click="copyToClipboard(objectStore.objectItem.id)">
														<template #icon>
															<Check v-if="isCopied" :size="16" />
															<ContentCopy v-else :size="16" />
														</template>
														{{ isCopied ? 'Copied' : 'Copy' }}
													</NcButton>
													<NcButton
														v-else-if="hasAction && key === 'Published'"
														:disabled="isPublishing"
														size="small"
														@click="openPublishModal">
														<template #icon>
															<NcLoadingIcon v-if="isPublishing" :size="16" />
															<Publish v-else :size="16" />
														</template>
														Change
													</NcButton>
													<NcButton
														v-else-if="hasAction && key === 'Depublished'"
														:disabled="isDepublishing"
														size="small"
														@click="openDepublishModal">
														<template #icon>
															<NcLoadingIcon v-if="isDepublishing" :size="16" />
															<PublishOff v-else :size="16" />
														</template>
														Change
													</NcButton>
												</td>
											</tr>
										</tbody>
									</table>
								</div>
							</BTab>
							<BTab title="Data">
								<NcNoteCard v-if="success" type="success" class="note-card">
									<p>Object successfully modified</p>
								</NcNoteCard>
								<div class="json-editor">
									<div :class="`codeMirrorContainer ${getTheme()}`">
										<CodeMirror
											v-model="jsonData"
											:basic="true"
											placeholder="{ &quot;key&quot;: &quot;value&quot; }"
											:dark="getTheme() === 'dark'"
											:linter="jsonParseLinter()"
											:lang="json()"
											:extensions="[json()]"
											:tab-size="2"
											style="height: 400px" />
										<NcButton
											class="format-json-button"
											type="secondary"
											size="small"
											@click="formatJSON">
											Format JSON
										</NcButton>
									</div>
									<span v-if="!isValidJson(jsonData)" class="error-message">
										Invalid JSON format
									</span>
								</div>
							</BTab>
							<BTab v-if="!isNewObject" title="Uses">
								<div v-if="objectStore.uses.results.length > 0" class="search-list-table">
									<table class="table">
										<thead>
											<tr class="table-row">
												<th>ID</th>
												<th>URI</th>
												<th>Schema</th>
												<th>Register</th>
												<th>Actions</th>
											</tr>
										</thead>
										<tbody>
											<tr v-for="use in objectStore.uses.results"
												:key="use['@self'].id"
												class="table-row">
												<td>{{ use['@self'].id }}</td>
												<td>{{ use['@self'].uri }}</td>
												<td>{{ use['@self'].schema }}</td>
												<td>{{ use['@self'].register }}</td>
												<td>
													<NcButton @click="objectStore.setObjectItem(use); navigationStore.setModal('viewObject')">
														<template #icon>
															<Eye :size="20" />
														</template>
														View Object
													</NcButton>
												</td>
											</tr>
										</tbody>
									</table>
								</div>
								<NcNoteCard v-else type="info">
									<p>No uses found for this object</p>
								</NcNoteCard>
							</BTab>
							<BTab v-if="!isNewObject" title="Used by">
								<div v-if="objectStore.used.results.length > 0" class="search-list-table">
									<table class="table">
										<thead>
											<tr class="table-row">
												<th>ID</th>
												<th>URI</th>
												<th>Schema</th>
												<th>Register</th>
												<th>Actions</th>
											</tr>
										</thead>
										<tbody>
											<tr v-for="usedBy in objectStore.used.results"
												:key="usedBy['@self'].id"
												class="table-row">
												<td>{{ usedBy['@self'].id }}</td>
												<td>{{ usedBy['@self'].uri }}</td>
												<td>{{ usedBy['@self'].schema }}</td>
												<td>{{ usedBy['@self'].register }}</td>
												<td>
													<NcButton @click="objectStore.setObjectItem(usedBy); navigationStore.setModal('viewObject')">
														<template #icon>
															<Eye :size="20" />
														</template>
														View Object
													</NcButton>
												</td>
											</tr>
										</tbody>
									</table>
								</div>
								<NcNoteCard v-else type="info">
									<p>No objects are using this object</p>
								</NcNoteCard>
							</BTab>
							<BTab v-if="!isNewObject" title="Contracts">
								<div v-if="objectStore.contracts.length > 0" class="search-list-table">
									<table class="table">
										<thead>
											<tr class="table-row">
												<th>ID</th>
												<th>URI</th>
												<th>Schema</th>
												<th>Register</th>
												<th>Actions</th>
											</tr>
										</thead>
										<tbody>
											<tr v-for="contract in objectStore.contracts"
												:key="contract['@self'].id"
												class="table-row">
												<td>{{ contract['@self'].id }}</td>
												<td>{{ contract['@self'].uri }}</td>
												<td>{{ contract['@self'].schema }}</td>
												<td>{{ contract['@self'].register }}</td>
												<td>
													<NcButton @click="objectStore.setObjectItem(contract); navigationStore.setModal('viewObject')">
														<template #icon>
															<Eye :size="20" />
														</template>
														View Object
													</NcButton>
												</td>
											</tr>
										</tbody>
									</table>
								</div>
								<NcNoteCard v-else type="info">
									<p>No contracts found for this object</p>
								</NcNoteCard>
							</BTab>
							<BTab v-if="!isNewObject" title="Files">
								<div v-if="paginatedFiles.length > 0" class="viewTableContainer">
									<table class="viewTable">
										<thead>
											<tr class="viewTableRow">
												<th class="tableColumnCheckbox">
													<NcCheckboxRadioSwitch
														:checked="allFilesSelected"
														:indeterminate="someFilesSelected"
														@update:checked="toggleSelectAllFiles" />
												</th>
												<th class="tableColumnExpanded">
													Name
												</th>
												<th class="tableColumnConstrained">
													Size
												</th>
												<th class="tableColumnConstrained">
													Type
												</th>
												<th class="tableColumnConstrained">
													Labels
												</th>
												<th class="tableColumnActions">
													<NcActions
														:force-name="true"
														:disabled="selectedAttachments.length === 0"
														:title="selectedAttachments.length === 0 ? 'Select one or more files to use mass actions' : `Mass actions (${selectedAttachments.length} selected)`"
														:menu-name="`Mass Actions (${selectedAttachments.length})`">
														<template #icon>
															<FormatListChecks :size="20" />
														</template>
														<NcActionButton
															:disabled="publishLoading.length > 0 || selectedAttachments.length === 0"
															@click="publishSelectedFiles">
															<template #icon>
																<NcLoadingIcon v-if="publishLoading.length > 0" :size="20" />
																<FileOutline v-else :size="20" />
															</template>
															Publish {{ selectedAttachments.length }} file{{ selectedAttachments.length > 1 ? 's' : '' }}
														</NcActionButton>
														<NcActionButton
															:disabled="depublishLoading.length > 0 || selectedAttachments.length === 0"
															@click="depublishSelectedFiles">
															<template #icon>
																<NcLoadingIcon v-if="depublishLoading.length > 0" :size="20" />
																<LockOutline v-else :size="20" />
															</template>
															Depublish {{ selectedAttachments.length }} file{{ selectedAttachments.length > 1 ? 's' : '' }}
														</NcActionButton>
														<NcActionButton
															:disabled="fileIdsLoading.length > 0 || selectedAttachments.length === 0"
															@click="deleteSelectedFiles">
															<template #icon>
																<NcLoadingIcon v-if="fileIdsLoading.length > 0" :size="20" />
																<Delete v-else :size="20" />
															</template>
															Delete {{ selectedAttachments.length }} file{{ selectedAttachments.length > 1 ? 's' : '' }}
														</NcActionButton>
													</NcActions>
												</th>
											</tr>
										</thead>
										<tbody>
											<tr v-for="(attachment, i) in paginatedFiles"
												:key="`${attachment.id}${i}`"
												:class="{ 'active': activeAttachment === attachment.id }"
												class="viewTableRow"
												@click="() => {
													if (activeAttachment === attachment.id) activeAttachment = null
													else activeAttachment = attachment.id
												}">
												<td class="tableColumnCheckbox">
													<NcCheckboxRadioSwitch
														:checked="selectedAttachments.includes(attachment.id)"
														@update:checked="(checked) => toggleFileSelection(attachment.id, checked)" />
												</td>
												<td class="tableColumnExpanded table-row-title">
													<!-- Show warning icon if file is not shared -->
													<ExclamationThick v-if="!attachment.accessUrl && !attachment.downloadUrl"
														v-tooltip="'Not shared'"
														class="warningIcon"
														:size="20" />
													<!-- Show published icon if file is shared -->
													<FileOutline v-else class="publishedIcon" :size="20" />
													{{ truncateFileName(attachment.name ?? attachment?.title) }}
												</td>
												<td class="tableColumnConstrained">
													{{ formatFileSize(attachment?.size) }}
												</td>
												<td class="tableColumnConstrained">
													{{ attachment?.type || 'No type' }}
												</td>
												<td class="tableColumnConstrained">
													<div class="fileLabelsContainer">
														<NcCounterBubble v-for="label of attachment.labels" :key="label">
															{{ label }}
														</NcCounterBubble>
													</div>
												</td>
												<td class="tableColumnActions">
													<NcActions>
														<NcActionButton @click="openFile(attachment)">
															<template #icon>
																<OpenInNew :size="20" />
															</template>
															View
														</NcActionButton>
														<NcActionButton @click="editFileLabels(attachment)">
															<template #icon>
																<Tag :size="20" />
															</template>
															Labels
														</NcActionButton>
														<NcActionButton
															v-if="!attachment.accessUrl && !attachment.downloadUrl"
															:disabled="publishLoading.includes(attachment.id)"
															@click="publishFile(attachment)">
															<template #icon>
																<NcLoadingIcon v-if="publishLoading.includes(attachment.id)" :size="20" />
																<FileOutline v-else :size="20" />
															</template>
															Publish
														</NcActionButton>
														<NcActionButton
															v-else
															:disabled="depublishLoading.includes(attachment.id)"
															@click="depublishFile(attachment)">
															<template #icon>
																<NcLoadingIcon v-if="depublishLoading.includes(attachment.id)" :size="20" />
																<LockOutline v-else :size="20" />
															</template>
															Depublish
														</NcActionButton>
														<NcActionButton
															:disabled="fileIdsLoading.includes(attachment.id)"
															@click="deleteFile(attachment)">
															<template #icon>
																<NcLoadingIcon v-if="fileIdsLoading.includes(attachment.id)" :size="20" />
																<Delete v-else :size="20" />
															</template>
															Delete
														</NcActionButton>
													</NcActions>
												</td>
											</tr>
										</tbody>
									</table>
								</div>
								<NcEmptyContent v-else
									name="No files attached"
									:description="isNewObject ? 'Save the object first to attach files' : 'No files have been attached to this object'">
									<template #icon>
										<FileOutline :size="64" />
									</template>
								</NcEmptyContent>

								<!-- Files Pagination -->
								<PaginationComponent
									v-if="objectStore.files?.results?.length > filesPerPage"
									:current-page="filesCurrentPage"
									:total-pages="filesTotalPages"
									:total-items="objectStore.files?.results?.length || 0"
									:current-page-size="filesPerPage"
									:min-items-to-show="5"
									@page-changed="onFilesPageChanged"
									@page-size-changed="onFilesPageSizeChanged" />
							</BTab>
						</BTabs>
					</div>
				</div>
			</div>

			<template #actions>
				<NcButton @click="closeModal">
					<template #icon>
						<Cancel :size="20" />
					</template>
					Close
				</NcButton>
				<NcButton v-if="!isNewObject" @click="navigationStore.setModal('uploadFiles'); objectStore.setObjectItem(objectStore.objectItem)">
					<template #icon>
						<Upload :size="20" />
					</template>
					Add File
				</NcButton>
				<NcButton @click="viewAuditTrails" v-if="!isNewObject">
					<template #icon>
						<TextBoxOutline :size="20" />
					</template>
					Audit Trails
				</NcButton>
				<NcButton type="primary" :disabled="isSaving" @click="saveObject">
					<template #icon>
						<NcLoadingIcon v-if="isSaving" :size="20" />
						<ContentSave v-else :size="20" />
					</template>
					{{ isSaving ? (isNewObject ? 'Creating...' : 'Saving...') : (isNewObject ? 'Create' : 'Save') }}
				</NcButton>
			</template>
		</NcDialog>

		<!-- Publish Object Modal -->
		<NcDialog :open="showPublishModal"
			name="Publish Object"
			size="small"
			:style="{ zIndex: 10001 }"
			@update:open="showPublishModal = $event">
			<div class="modal-content">
				<p>Set the publication date for this object. Leave empty to NOT publish this object.</p>

				<NcDateTimePickerNative
					v-model="publishDate"
					label="Publication Date"
					type="datetime-local" />
			</div>

			<template #actions>
				<NcButton @click="closePublishModal">
					<template #icon>
						<Cancel :size="20" />
					</template>
					Cancel
				</NcButton>
				<NcButton type="primary"
					:disabled="isPublishing"
					@click="publishObject">
					<template #icon>
						<NcLoadingIcon v-if="isPublishing" :size="20" />
						<ContentSave v-else :size="20" />
					</template>
					{{ isPublishing ? 'Publishing...' : 'Save' }}
				</NcButton>
			</template>
		</NcDialog>

		<!-- Depublish Object Modal -->
		<NcDialog :open="showDepublishModal"
			name="Depublish Object"
			size="small"
			:style="{ zIndex: 10001 }"
			@update:open="showDepublishModal = $event">
			<div class="modal-content">
				<p>Set the depublication date for this object. Leave empty to NOT depublish this object.</p>

				<NcDateTimePickerNative
					v-model="depublishDate"
					label="Depublication Date"
					type="datetime-local" />
			</div>

			<template #actions>
				<NcButton @click="closeDepublishModal">
					<template #icon>
						<Cancel :size="20" />
					</template>
					Cancel
				</NcButton>
				<NcButton type="primary"
					:disabled="isDepublishing"
					@click="depublishObject">
					<template #icon>
						<NcLoadingIcon v-if="isDepublishing" :size="20" />
						<ContentSave v-else :size="20" />
					</template>
					{{ isDepublishing ? 'Depublishing...' : 'Save' }}
				</NcButton>
			</template>
		</NcDialog>
	</div>
</template>

<script>
import {
	NcDialog,
	NcButton,
	NcActions,
	NcActionButton,
	NcNoteCard,
	NcCounterBubble,
	NcTextField,
	NcCheckboxRadioSwitch,
	NcLoadingIcon,
	NcDateTimePickerNative,
	NcEmptyContent,
} from '@nextcloud/vue'
import { json, jsonParseLinter } from '@codemirror/lang-json'
import CodeMirror from 'vue-codemirror6'
import { BTabs, BTab } from 'bootstrap-vue'
import { getTheme } from '../../services/getTheme.js'
import Cancel from 'vue-material-design-icons/Cancel.vue'
import FileOutline from 'vue-material-design-icons/FileOutline.vue'
import OpenInNew from 'vue-material-design-icons/OpenInNew.vue'
import Eye from 'vue-material-design-icons/Eye.vue'
import Delete from 'vue-material-design-icons/Delete.vue'
import Upload from 'vue-material-design-icons/Upload.vue'
import LockOutline from 'vue-material-design-icons/LockOutline.vue'
import ContentCopy from 'vue-material-design-icons/ContentCopy.vue'
import Check from 'vue-material-design-icons/Check.vue'
import ContentSave from 'vue-material-design-icons/ContentSave.vue'
import TextBoxOutline from 'vue-material-design-icons/TextBoxOutline.vue'
import Tag from 'vue-material-design-icons/Tag.vue'
import FormatListChecks from 'vue-material-design-icons/FormatListChecks.vue'
import Alert from 'vue-material-design-icons/Alert.vue'
import AlertCircle from 'vue-material-design-icons/AlertCircle.vue'
import Plus from 'vue-material-design-icons/Plus.vue'
import Publish from 'vue-material-design-icons/Publish.vue'
import PublishOff from 'vue-material-design-icons/PublishOff.vue'
import ExclamationThick from 'vue-material-design-icons/ExclamationThick.vue'
import PaginationComponent from '../../components/PaginationComponent.vue'
export default {
	name: 'ViewObject',
	components: {
		NcDialog,
		NcButton,
		NcNoteCard,
		NcCounterBubble,
		NcTextField,
		NcCheckboxRadioSwitch,
		NcLoadingIcon,
		NcActions,
		NcActionButton,
		NcDateTimePickerNative,
		NcEmptyContent,
		CodeMirror,
		BTabs,
		BTab,
		Cancel,
		FileOutline,
		OpenInNew,
		Eye,
		Delete,
		Upload,
		LockOutline,
		ContentCopy,
		Check,
		ContentSave,
		TextBoxOutline,
		Tag,
		FormatListChecks,
		Alert,
		AlertCircle,
		Plus,
		Publish,
		PublishOff,
		ExclamationThick,
		PaginationComponent,
	},
	data() {
		return {
			closeModalTimeout: null,
			activeAttachment: null,
			registerTitle: '',
			schemaTitle: '',
			isUpdated: false,
			isCopied: false,
			error: null,
			success: null,
			formData: {},
			jsonData: '',
			activeTab: 0,
			isInternalUpdate: false, // Flag to prevent infinite loops during synchronization
			objectEditors: {},
			tabOptions: ['Properties', 'Metadata', 'Data', 'Uses', 'Used by', 'Contracts', 'Files'],
			selectedAttachments: [],
			publishLoading: [],
			depublishLoading: [],
			fileIdsLoading: [],
			filesCurrentPage: 1,
			filesPerPage: 10,
			// Object publish/depublish modal states
			showPublishModal: false,
			showDepublishModal: false,
			publishDate: null,
			depublishDate: null,
			isPublishing: false,
			isDepublishing: false,
			selectedProperty: null,
			isSaving: false,
		}
	},
	computed: {
		objectProperties() {
			console.log('objectProperties computed called:', {
				objectItem: objectStore?.objectItem,
				currentSchema: this.currentSchema,
				isNewObject: this.isNewObject,
				schemaId: this.currentSchema?.id,
				schemaProperties: this.currentSchema?.properties
			})
			
			// For new objects, show schema properties with default values
			if (!objectStore?.objectItem) {
				const schemaProperties = this.currentSchema?.properties
				if (!schemaProperties) {
					console.log('No schema properties available')
					return []
				}
				
				console.log('Schema properties found:', Object.keys(schemaProperties))
				const defaultProperties = []
				
				for (const [key, schemaProperty] of Object.entries(schemaProperties)) {
					// Add with appropriate default value based on type
					let defaultValue
					switch (schemaProperty.type) {
					case 'string':
						defaultValue = schemaProperty.const || ''
						break
					case 'number':
					case 'integer':
						defaultValue = 0
						break
					case 'boolean':
						defaultValue = false
						break
					case 'array':
						defaultValue = []
						break
					case 'object':
						defaultValue = {}
						break
					default:
						defaultValue = ''
					}
					defaultProperties.push([key, defaultValue])
				}
				
				console.log('objectProperties returning default properties:', defaultProperties)
				return defaultProperties
			}

			const objectData = objectStore.objectItem
			const schemaProperties = this.currentSchema?.properties || {}

			// Start with properties that exist in the object
			const existingProperties = Object.entries(objectData)
				.filter(([key]) => key !== '@self' && key !== 'id')

			// Add schema properties that don't exist in the object yet
			const missingSchemaProperties = []
			for (const [key, schemaProperty] of Object.entries(schemaProperties)) {
				if (!Object.prototype.hasOwnProperty.call(objectData, key)) {
					// Add with appropriate default value based on type
					let defaultValue
					switch (schemaProperty.type) {
					case 'string':
						defaultValue = schemaProperty.const || ''
						break
					case 'number':
					case 'integer':
						defaultValue = 0
						break
					case 'boolean':
						defaultValue = false
						break
					case 'array':
						defaultValue = []
						break
					case 'object':
						defaultValue = {}
						break
					default:
						defaultValue = ''
					}
					missingSchemaProperties.push([key, defaultValue])
				}
			}

			// Combine existing properties and missing schema properties
			return [...existingProperties, ...missingSchemaProperties]
		},
		editorContent() {
			return JSON.stringify(objectStore.objectItem, null, 2)
		},
		currentRegister() {
			return registerStore.registerItem
		},
		currentSchema() {
			return schemaStore.schemaItem
		},
		selectedPublishedCount() {
			return this.selectedAttachments.filter((a) => {
				const found = objectStore.files.results
					?.find(item => item.id === a)
				if (!found) return false

				return !!found.published
			}).length
		},
		selectedUnpublishedCount() {
			return this.selectedAttachments.filter((a) => {
				const found = objectStore.files.results
					?.find(item => item.id === a)
				if (!found) return false
				return found.published === null
			}).length
		},
		allPublishedSelected() {
			const published = objectStore.files.results
				?.filter(item => !!item.published)
				.map(item => item.id) || []

			if (!published.length) {
				return false
			}
			return published.every(pubId => this.selectedAttachments.includes(pubId))
		},
		allUnpublishedSelected() {
			const unpublished = objectStore.files.results
				?.filter(item => !item.published)
				.map(item => item.id) || []

			if (!unpublished.length) {
				return false
			}
			return unpublished.every(unpubId => this.selectedAttachments.includes(unpubId))
		},
		loading() {
			return this.publishLoading.length > 0 || this.depublishLoading.length > 0 || this.fileIdsLoading.length > 0
		},
		filesHasPublished() {
			return objectStore.files.results?.some(item => !!item.published)
		},
		filesHasUnpublished() {
			return objectStore.files.results?.some(item => !item.published)
		},
		paginatedFiles() {
			const files = objectStore.files?.results || []
			const start = (this.filesCurrentPage - 1) * this.filesPerPage
			const end = start + this.filesPerPage
			return files.slice(start, end)
		},
		filesTotalPages() {
			const totalFiles = objectStore.files?.results?.length || 0
			return Math.ceil(totalFiles / this.filesPerPage)
		},
		allFilesSelected() {
			return this.paginatedFiles.length > 0 && this.paginatedFiles.every(file => this.selectedAttachments.includes(file.id))
		},
		someFilesSelected() {
			return this.selectedAttachments.length > 0 && !this.allFilesSelected
		},
		formFields() {
			console.log('formFields computed called:', {
				currentSchema: this.currentSchema,
				hasProperties: this.currentSchema?.properties,
				propertiesCount: this.currentSchema?.properties ? Object.keys(this.currentSchema.properties).length : 0
			})
			
			// Combine schema properties and object properties
			const fields = {}

			// First, add all schema properties
			if (this.currentSchema && this.currentSchema.properties) {
				for (const [key, value] of Object.entries(this.currentSchema.properties)) {
					fields[key] = value || { type: 'string' }
				}
			}

			// Then, add any object properties that aren't in the schema
			if (objectStore.objectItem) {
				for (const [key, value] of Object.entries(objectStore.objectItem)) {
					if (key !== '@self' && key !== 'id' && !fields[key]) {
						// Infer type from the value
						let type = 'string'
						if (typeof value === 'boolean') {
							type = 'boolean'
						} else if (typeof value === 'number') {
							type = 'number'
						} else if (Array.isArray(value)) {
							type = 'array'
						} else if (typeof value === 'object' && value !== null) {
							type = 'object'
						}

						fields[key] = {
							type,
							title: key,
							description: `Property: ${key}`,
						}
					}
				}
			}

			console.log('formFields returning:', fields)
			return fields
		},
		metadataProperties() {
			// Return array of [key, value, hasAction] for metadata display
			// Use formData instead of objectStore.objectItem to reflect real-time changes
			const obj = this.formData || objectStore?.objectItem
			if (!obj) return []

			const metadata = []

			// ID with copy action
			metadata.push([
				'ID',
				obj.id || 'Not set',
				true,
			])

			// Register
			metadata.push([
				'Register',
				this.registerTitle || 'Not set',
				false,
			])

			// Schema
			metadata.push([
				'Schema',
				this.schemaTitle || 'Not set',
				false,
			])

			// Version
			metadata.push([
				'Version',
				obj['@self']?.version || 'Not set',
				false,
			])

			// Created
			metadata.push([
				'Created',
				obj['@self']?.created ? new Date(obj['@self'].created).toLocaleString() : 'Not set',
				false,
			])

			// Updated
			metadata.push([
				'Updated',
				obj['@self']?.updated ? new Date(obj['@self'].updated).toLocaleString() : 'Not set',
				false,
			])

			// Published with change action
			metadata.push([
				'Published',
				obj['@self']?.published ? new Date(obj['@self'].published).toLocaleString() : 'Not published',
				true,
			])

			// Depublished with change action
			metadata.push([
				'Depublished',
				obj['@self']?.depublished ? new Date(obj['@self'].depublished).toLocaleString() : 'Not depublished',
				true,
			])

			// Validation
			let validationText = 'Not validated'
			if (obj['@self']?.validation !== null) {
				validationText = obj['@self'].validation ? 'Valid' : 'Invalid'
			}
			metadata.push([
				'Validation',
				validationText,
				false,
			])

			// Owner
			metadata.push([
				'Owner',
				obj['@self']?.owner || 'Not set',
				false,
			])

			// Application
			metadata.push([
				'Application',
				obj['@self']?.application || 'Not set',
				false,
			])

			// Organisation
			metadata.push([
				'Organisation',
				obj['@self']?.organisation || 'Not set',
				false,
			])

			return metadata
		},
		isNewObject() {
			return !objectStore?.objectItem || !objectStore?.objectItem['@self']?.id
		},

	},
	watch: {
		objectStore: {
			handler(newValue) {
				if (newValue) {
					this.initializeData()
				}
			},
			deep: true,
		},
		// Watch for schema changes to re-initialize data
		currentSchema: {
			handler(newSchema) {
				console.log('Schema changed in ViewObject:', newSchema)
				if (newSchema && this.isNewObject) {
					// Re-initialize data when schema becomes available for new objects
					this.initializeData()
				}
				// Force Vue to re-evaluate computed properties
				this.$forceUpdate()
			},
			immediate: true,
		},
		// Watch for register changes to re-initialize data
		currentRegister: {
			handler(newRegister) {
				console.log('Register changed in ViewObject:', newRegister)
				if (newRegister && this.isNewObject) {
					// Re-initialize data when register becomes available for new objects
					this.initializeData()
				}
			},
			immediate: true,
		},
		jsonData: {
			handler(newValue) {
				if (!this.isInternalUpdate && this.isValidJson(newValue)) {
					this.updateFormFromJson()
				}
			},
		},
		formData: {
			handler(newValue) {
				if (!this.isInternalUpdate) {
					this.updateJsonFromForm()
				}
			},
			deep: true,
		},
	},
	mounted() {
		// Debug: Log current state when modal opens
		console.log('ViewObject mounted:', {
			objectItem: objectStore.objectItem,
			schemaItem: schemaStore.schemaItem,
			registerItem: registerStore.registerItem,
			isNewObject: this.isNewObject
		})
		
		// Initialize data when modal opens
		this.initializeData()
		this.loadTitles()
	},
	updated() {
		if (!this.isUpdated && navigationStore.modal === 'viewObject') {
			this.isUpdated = true
			this.loadTitles()
			this.initializeData()
		}
	},
	methods: {
		getModalTitle() {
			if (!objectStore?.objectItem || !objectStore.objectItem['@self']?.id) {
				return 'Add Object'
			}

			const name = objectStore.objectItem['@self']?.name
				|| objectStore.objectItem.name
				|| objectStore.objectItem.id

			const schemaName = this.currentSchema?.title
				|| this.currentSchema?.name
				|| 'Unknown Schema'

			// Add status icon before the title
			let statusIcon = ''
			if (objectStore.objectItem['@self']?.published) {
				statusIcon = '📄 ' // Published
			} else if (objectStore.objectItem['@self']?.depublished) {
				statusIcon = '⚠️ ' // Depublished
			} else {
				statusIcon = '✏️ ' // Draft/Unpublished
			}

			return `${statusIcon}${name} (${schemaName})`
		},
		async loadTitles() {
			// Only load titles if we have an existing object with @self data
			if (!objectStore.objectItem || !objectStore.objectItem['@self']) {
				this.registerTitle = 'Not set'
				this.schemaTitle = 'Not set'
				return
			}

			const register = await registerStore.getRegister(objectStore.objectItem['@self'].register)
			const schema = await schemaStore.getSchema(objectStore.objectItem['@self'].schema)

			this.registerTitle = register?.title || 'Not set'
			this.schemaTitle = schema?.title || 'Not set'
		},
		closeModal() {
			// Clear state first
			this.isUpdated = false
			this.registerTitle = ''
			this.schemaTitle = ''
			this.activeTab = 0
			this.selectedAttachments = []
			this.activeAttachment = null
			this.success = null
			this.error = null
			this.isCopied = false

			// Clear publish/depublish modal states
			this.showPublishModal = false
			this.showDepublishModal = false
			this.publishDate = null
			this.depublishDate = null
			this.isPublishing = false
			this.isDepublishing = false

			// Clear any timeouts
			clearTimeout(this.closeModalTimeout)

			// Close modal and dialog
			navigationStore.setModal(null)
			navigationStore.setDialog(null)
		},
		handleDialogClose(isOpen) {
			if (!isOpen) {
				this.closeModal()
			}
		},
		/**
		 * Open a file in the Nextcloud Files app
		 * @param {object} file - The file object to open
		 */
		openFile(file) {
			const dirPath = file.path.substring(0, file.path.lastIndexOf('/'))
			const cleanPath = dirPath.replace(/^\/admin\/files\//, '/')
			const filesAppUrl = `/index.php/apps/files/files/${file.id}?dir=${encodeURIComponent(cleanPath)}&openfile=true`
			window.open(filesAppUrl, '_blank')
		},
		/**
		 * Format file size for display
		 * @param {number} bytes - The file size in bytes
		 * @return {string} The formatted file size
		 */
		formatFileSize(bytes) {
			const sizes = ['Bytes', 'KB', 'MB', 'GB', 'TB']
			if (bytes === 0) return 'n/a'
			const i = parseInt(Math.floor(Math.log(bytes) / Math.log(1024)))
			if (i === 0 && sizes[i] === 'Bytes') return '< 1 KB'
			if (i === 0) return bytes + ' ' + sizes[i]
			return (bytes / Math.pow(1024, i)).toFixed(1) + ' ' + sizes[i]
		},
		/**
		 * Truncate file name to prevent dialog alignment issues
		 * @param {string} fileName - The file name to truncate
		 * @return {string} The truncated file name (22 chars + ... if longer than 25)
		 */
		truncateFileName(fileName) {
			if (!fileName) return ''
			if (fileName.length <= 25) return fileName
			return fileName.substring(0, 22) + '...'
		},
		isValidDate(value) {
			if (!value) return false
			const date = new Date(value)
			return date instanceof Date && !isNaN(date)
		},
		formatValue(val) {
			return JSON.stringify(val, null, 2)
		},

		getTheme,
		async copyToClipboard(text) {
			try {
				await navigator.clipboard.writeText(text)
				this.isCopied = true
				setTimeout(() => { this.isCopied = false }, 2000)
			} catch (err) {
				console.error('Failed to copy text:', err)
			}
		},
		initializeData() {
			console.log('initializeData called:', {
				objectItem: objectStore.objectItem,
				currentSchema: this.currentSchema,
				currentRegister: this.currentRegister
			})
			
			// Initialize with empty data for new objects
			if (!objectStore.objectItem) {
				const initialData = {
					'@self': {
						id: '',
						uuid: '',
						uri: '',
						register: this.currentRegister?.id || '',
						schema: this.currentSchema?.id || '',
						relations: '',
						files: '',
						folder: '',
						updated: '',
						created: '',
						locked: null,
						owner: '',
					},
				}
				this.formData = initialData
				this.jsonData = JSON.stringify(initialData, null, 2)
				return
			}

			// For existing objects, use their complete data structure (like EditObject)
			const initialData = { ...objectStore.objectItem }
			this.formData = initialData
			this.jsonData = JSON.stringify(initialData, null, 2)
			
		},

		async saveObject() {
			if (!this.currentRegister || !this.currentSchema) {
				this.error = 'Register and schema are required'
				return
			}

			this.isSaving = true
			this.error = null

			try {
				let dataToSave
				if (this.activeTab === 1) {
					if (!this.jsonData.trim()) {
						throw new Error('JSON data cannot be empty')
					}
					try {
						dataToSave = JSON.parse(this.jsonData)
					} catch (e) {
						throw new Error('Invalid JSON format: ' + e.message)
					}
				} else {
					dataToSave = this.formData
				}

				const { response } = await objectStore.saveObject(dataToSave, {
					register: this.currentRegister.id,
					schema: this.currentSchema.id,
				})
				console.log('Save object response:', response)
				this.success = response.ok
				if (this.success) {
					// Re-initialize data to refresh jsonData with the newly created object
					this.initializeData()
					setTimeout(() => {
						this.success = null
					}, 2000)
				}
			} catch (e) {
				this.error = e.message || 'Failed to save object'
				this.success = false
			} finally {
				this.isSaving = false
			}
		},
		updateFormFromJson() {
			if (this.isInternalUpdate) return
			
			try {
				this.isInternalUpdate = true
				const parsed = JSON.parse(this.jsonData)
				this.formData = parsed
			} catch (e) {
				this.error = 'Invalid JSON format'
			} finally {
				this.$nextTick(() => {
					this.isInternalUpdate = false
				})
			}
		},

		updateJsonFromForm() {
			if (this.isInternalUpdate) return
			
			try {
				this.isInternalUpdate = true
				this.jsonData = JSON.stringify(this.formData, null, 2)
			} catch (e) {
				console.error('Error updating JSON:', e)
			} finally {
				this.$nextTick(() => {
					this.isInternalUpdate = false
				})
			}
		},

		isValidJson(str) {
			if (!str || !str.trim()) {
				return false
			}
			try {
				JSON.parse(str)
				return true
			} catch (e) {
				return false
			}
		},

		formatJSON() {
			try {
				if (this.jsonData) {
					const parsed = JSON.parse(this.jsonData)
					this.jsonData = JSON.stringify(parsed, null, 2)
				}
			} catch (e) {
				// Keep invalid JSON as-is
			}
		},

		setFieldValue(key, value) {
			this.formData[key] = value
		},
		updateArrayItem(key, index, value) {
			if (!this.formData[key]) {
				this.formData[key] = []
			}
			this.formData[key][index] = value
		},
		toDisplay(v) { return v === null ? '' : v },
		toPayload(v) { return v === '' ? null : v },

		addArrayItem(key) {
			if (!this.formData[key] || !Array.isArray(this.formData[key])) {
				this.formData[key] = []
			}
			this.formData[key].push('')
		},
		removeArrayItem(key, i) {
			if (this.formData[key] && Array.isArray(this.formData[key])) {
				this.formData[key].splice(i, 1)
			}
		},
		updateObjectField(key, val) {
			this.objectEditors[key] = val
			try {
				this.formData[key] = JSON.parse(val)
			} catch (e) {
				console.error('Invalid JSON format:', e)
			}
		},
		toggleSelectAllFiles(checked) {
			if (checked) {
				// Add all current page files to selection
				this.paginatedFiles.forEach(file => {
					if (!this.selectedAttachments.includes(file.id)) {
						this.selectedAttachments.push(file.id)
					}
				})
			} else {
				// Remove all current page files from selection
				const currentPageIds = this.paginatedFiles.map(file => file.id)
				this.selectedAttachments = this.selectedAttachments.filter(id => !currentPageIds.includes(id))
			}
		},
		toggleFileSelection(fileId, checked) {
			if (checked) {
				if (!this.selectedAttachments.includes(fileId)) {
					this.selectedAttachments.push(fileId)
				}
			} else {
				this.selectedAttachments = this.selectedAttachments.filter(id => id !== fileId)
			}
		},
		onFilesPageChanged(page) {
			this.filesCurrentPage = page
		},
		onFilesPageSizeChanged(pageSize) {
			this.filesPerPage = pageSize
			this.filesCurrentPage = 1
		},
		viewAuditTrails() {
			// Close the current modal and navigate to audit trails
			this.closeModal()
			this.$router.push('/audit-trails')
		},
		async publishSelectedFiles() {
			if (this.selectedAttachments.length === 0) return

			try {
				this.publishLoading = [...this.selectedAttachments]

				// Get the selected files
				const selectedFiles = objectStore.files.results.filter(file =>
					this.selectedAttachments.includes(file.id),
				)

				// Publish each file individually using the store method
				for (const file of selectedFiles) {
					await objectStore.publishFile({
						register: objectStore.objectItem['@self'].register,
						schema: objectStore.objectItem['@self'].schema,
						objectId: objectStore.objectItem.id,
						fileId: file.id,
					})
				}

				// Clear selection after successful operation
				this.selectedAttachments = []

			} catch (error) {
				// eslint-disable-next-line no-console
				console.error('Error publishing files:', error)
			} finally {
				this.publishLoading = []
			}
		},
		async depublishSelectedFiles() {
			if (this.selectedAttachments.length === 0) return

			try {
				this.depublishLoading = [...this.selectedAttachments]

				// Get the selected files
				const selectedFiles = objectStore.files.results.filter(file =>
					this.selectedAttachments.includes(file.id),
				)

				// Depublish each file individually using the store method
				for (const file of selectedFiles) {
					await objectStore.unpublishFile({
						register: objectStore.objectItem['@self'].register,
						schema: objectStore.objectItem['@self'].schema,
						objectId: objectStore.objectItem.id,
						fileId: file.id,
					})
				}

				// Clear selection after successful operation
				this.selectedAttachments = []

			} catch (error) {
				// eslint-disable-next-line no-console
				console.error('Error depublishing files:', error)
			} finally {
				this.depublishLoading = []
			}
		},
		async deleteSelectedFiles() {
			if (this.selectedAttachments.length === 0) return

			try {
				this.fileIdsLoading = [...this.selectedAttachments]

				// Get the selected files
				const selectedFiles = objectStore.files.results?.filter(item =>
					this.selectedAttachments.includes(item.id),
				) || []

				// Delete each selected file
				for (const file of selectedFiles) {
					await objectStore.deleteFile({
						register: objectStore.objectItem['@self'].register,
						schema: objectStore.objectItem['@self'].schema,
						objectId: objectStore.objectItem.id,
						fileId: file.id,
					})
				}

				// Clear selection - files list is automatically refreshed by the store methods
				this.selectedAttachments = []
			} catch (error) {
				// eslint-disable-next-line no-console
				console.error('Failed to delete selected files:', error)
			} finally {
				this.fileIdsLoading = []
			}
		},
		async publishFile(file) {
			try {
				this.publishLoading.push(file.id)

				await objectStore.publishFile({
					register: objectStore.objectItem['@self'].register,
					schema: objectStore.objectItem['@self'].schema,
					objectId: objectStore.objectItem.id,
					fileId: file.id,
				})

				// Files list is automatically refreshed by the store method
			} catch (error) {
				// eslint-disable-next-line no-console
				console.error('Failed to publish file:', error)
			} finally {
				this.publishLoading = this.publishLoading.filter(id => id !== file.id)
			}
		},
		async depublishFile(file) {
			try {
				this.depublishLoading.push(file.id)

				await objectStore.unpublishFile({
					register: objectStore.objectItem['@self'].register,
					schema: objectStore.objectItem['@self'].schema,
					objectId: objectStore.objectItem.id,
					fileId: file.id,
				})

				// Files list is automatically refreshed by the store method
			} catch (error) {
				// eslint-disable-next-line no-console
				console.error('Failed to depublish file:', error)
			} finally {
				this.depublishLoading = this.depublishLoading.filter(id => id !== file.id)
			}
		},
		async deleteFile(file) {
			try {
				this.fileIdsLoading.push(file.id)

				await objectStore.deleteFile({
					register: objectStore.objectItem['@self'].register,
					schema: objectStore.objectItem['@self'].schema,
					objectId: objectStore.objectItem.id,
					fileId: file.id,
				})

				// Files list is automatically refreshed by the store method
			} catch (error) {
				// eslint-disable-next-line no-console
				console.error('Failed to delete file:', error)
			} finally {
				this.fileIdsLoading = this.fileIdsLoading.filter(id => id !== file.id)
			}
		},
		editFileLabels(file) {
			// You'll need to implement the labels editing functionality
			// This could open a modal or inline editor for file labels
			// eslint-disable-next-line no-console
			console.log('Editing labels for file:', file.name)
			// Placeholder for labels editing implementation
		},
		getPropertyValidationClass(key, value) {
			// Skip @self as it's metadata
			if (key === '@self') {
				return ''
			}

			// Check if property exists in schema
			const schemaProperty = this.currentSchema?.properties?.[key]
			const existsInObject = objectStore.objectItem ? Object.prototype.hasOwnProperty.call(objectStore.objectItem, key) : false

			if (!schemaProperty) {
				// Property exists in object but not in schema - warning (yellow)
				return 'property-warning'
			}

			if (!existsInObject) {
				// Property exists in schema but not in object yet - neutral (no special class)
				return 'property-new'
			}

			// Property exists in both schema and object, validate the value
			if (this.isValidPropertyValue(key, value, schemaProperty)) {
				// Valid property - success (green)
				return 'property-valid'
			} else {
				// Invalid property - error (red)
				return 'property-invalid'
			}
		},
		isValidPropertyValue(key, value, schemaProperty) {
			// Handle null/undefined values
			if (value === null || value === undefined || value === '') {
				// Check if property is required
				const isRequired = this.currentSchema?.required?.includes(key) || schemaProperty.required
				return !isRequired // Valid if not required, invalid if required
			}

			// Validate based on schema type
			switch (schemaProperty.type) {
			case 'string':
				if (typeof value !== 'string') return false
				// Check format constraints
				if (schemaProperty.format === 'date-time') {
					return this.isValidDate(value)
				}
				// Check const constraint
				if (schemaProperty.const && value !== schemaProperty.const) {
					return false
				}
				return true

			case 'number':
				return typeof value === 'number' && !isNaN(value)

			case 'boolean':
				return typeof value === 'boolean'

			case 'array':
				return Array.isArray(value)

			case 'object':
				return typeof value === 'object' && value !== null && !Array.isArray(value)

			default:
				return true // Unknown type, assume valid
			}
		},
		getPropertyErrorMessage(key, value) {
			const schemaProperty = this.currentSchema?.properties?.[key]

			if (!schemaProperty) {
				return `Property '${key}' is not defined in the current schema. This property exists in the object but is not part of the schema definition.`
			}

			// Check if required but empty
			const isRequired = this.currentSchema?.required?.includes(key) || schemaProperty.required
			if ((value === null || value === undefined || value === '') && isRequired) {
				return `Required property '${key}' is missing or empty`
			}

			// Check type mismatch
			const expectedType = schemaProperty.type
			const actualType = Array.isArray(value) ? 'array' : typeof value

			if (expectedType !== actualType) {
				return `Property '${key}' should be ${expectedType} but is ${actualType}`
			}

			// Check format constraints
			if (schemaProperty.format === 'date-time' && !this.isValidDate(value)) {
				return `Property '${key}' should be a valid date-time format`
			}

			// Check const constraint
			if (schemaProperty.const && value !== schemaProperty.const) {
				return `Property '${key}' should be '${schemaProperty.const}' but is '${value}'`
			}

			return `Property '${key}' has an invalid value`
		},
		getPropertyWarningMessage(key, value) {
			return `Property '${key}' exists in the object but is not defined in the current schema. This might happen when property names are changed in the schema.`
		},
		getPropertyNewMessage(key) {
			return `Property '${key}' is defined in the schema but doesn't have a value yet. Click to add a value.`
		},
		/**
		 * Convert any value to a string suitable for NcTextField
		 * @param {*} value - The value to convert
		 * @return {string} The string representation
		 */
		getStringValue(value) {
			if (value === null || value === undefined) {
				return ''
			}
			if (typeof value === 'string') {
				return value
			}
			if (typeof value === 'number' || typeof value === 'boolean') {
				return String(value)
			}
			if (typeof value === 'object') {
				// For objects and arrays, return JSON string
				try {
					return JSON.stringify(value)
				} catch (e) {
					return String(value)
				}
			}
			return String(value)
		},
		/**
		 * Open the publish modal and pre-fill current value
		 */
		openPublishModal() {
			// Pre-fill with current published date if it exists
			if (objectStore.objectItem['@self'].published) {
				// Convert ISO string to Date object
				this.publishDate = new Date(objectStore.objectItem['@self'].published)
			} else {
				this.publishDate = null
			}
			this.showPublishModal = true
		},
		/**
		 * Open the depublish modal and pre-fill current value
		 */
		openDepublishModal() {
			// Pre-fill with current depublished date if it exists
			if (objectStore.objectItem['@self'].depublished) {
				// Convert ISO string to Date object
				this.depublishDate = new Date(objectStore.objectItem['@self'].depublished)
			} else {
				this.depublishDate = null
			}
			this.showDepublishModal = true
		},

		/**
		 * Close the publish modal and reset state
		 */
		closePublishModal() {
			this.showPublishModal = false
			this.publishDate = null
			this.isPublishing = false
		},
		/**
		 * Close the depublish modal and reset state
		 */
		closeDepublishModal() {
			this.showDepublishModal = false
			this.depublishDate = null
			this.isDepublishing = false
		},
		/**
		 * Publish the current object with optional date
		 */
		async publishObject() {
			this.isPublishing = true
			try {
				// If no date is provided, set published to null (unpublish)
				const publishedDate = this.publishDate ? this.publishDate.toISOString() : null

				await objectStore.publishObject({
					register: objectStore.objectItem['@self'].register,
					schema: objectStore.objectItem['@self'].schema,
					objectId: objectStore.objectItem['@self'].id,
					publishedDate,
				})

				this.closePublishModal()

				// Show success message
				const message = this.publishDate ? 'Object published successfully' : 'Object unpublished successfully'
				this.success = message
				setTimeout(() => {
					this.success = null
				}, 3000)
			} catch (error) {
				console.error('Failed to update object publication:', error)
				this.error = 'Failed to update object publication: ' + error.message
				setTimeout(() => {
					this.error = null
				}, 5000)
			} finally {
				this.isPublishing = false
			}
		},
		/**
		 * Depublish the current object with optional date
		 */
		async depublishObject() {
			this.isDepublishing = true
			try {
				// If no date is provided, set depublished to null (remove depublication)
				const depublishedDate = this.depublishDate ? this.depublishDate.toISOString() : null

				await objectStore.depublishObject({
					register: objectStore.objectItem['@self'].register,
					schema: objectStore.objectItem['@self'].schema,
					objectId: objectStore.objectItem['@self'].id,
					depublishedDate,
				})

				this.closeDepublishModal()

				// Show success message
				const message = this.depublishDate ? 'Object depublished successfully' : 'Object depublication removed successfully'
				this.success = message
				setTimeout(() => {
					this.success = null
				}, 3000)
			} catch (error) {
				console.error('Failed to update object depublication:', error)
				this.error = 'Failed to update object depublication: ' + error.message
				setTimeout(() => {
					this.error = null
				}, 5000)
			} finally {
				this.isDepublishing = false
			}
		},
		handleRowClick(key, event) {
			// Don't select if clicking on an input or button
			if (event.target.tagName === 'INPUT' || event.target.tagName === 'BUTTON' || event.target.closest('.value-input-container')) {
				return
			}

			// Don't deselect if already selected
			if (this.selectedProperty === key) {
				return
			}

			// Check if property is editable
			const value = this.formData[key] !== undefined ? this.formData[key] : this.objectProperties.find(([k]) => k === key)?.[1]
			if (!this.isPropertyEditable(key, value)) {
				// Show warning for non-editable properties
				const warning = this.getEditabilityWarning(key, value)
				if (warning) {
					this.showWarningNotification(warning)
				}
				return
			}

			// Only allow editing for supported property types (same as EditObject.vue)
			const schemaProperty = this.currentSchema?.properties?.[key]
			if (schemaProperty && ['string', 'number', 'integer', 'boolean'].includes(schemaProperty.type)) {
				this.selectProperty(key)
			} else if (!schemaProperty) {
				// Allow editing for properties not in schema (free-form)
				this.selectProperty(key)
			} else {
				// Show info for unsupported types
				this.showWarningNotification(`Property '${this.getPropertyDisplayName(key)}' has type '${schemaProperty.type}' which is not supported for inline editing. Use the Data tab for complex types.`)
			}
		},
		selectProperty(key) {
			this.selectedProperty = key

			// Focus the input field after Vue updates the DOM
			this.$nextTick(() => {
				if (this.$refs.propertyValueInput && this.$refs.propertyValueInput[0]) {
					const input = this.$refs.propertyValueInput[0].$el.querySelector('input')
					if (input) {
						input.focus()
						input.select()
					}
				}
			})
		},
		updatePropertyValue(key, newValue) {
			// Get the old value for comparison
			const oldValue = this.formData[key] !== undefined
				? this.formData[key]
				: this.objectProperties.find(([k]) => k === key)?.[1]

			// Convert value based on schema property type
			const schemaProperty = this.currentSchema?.properties?.[key]
			let convertedValue = newValue

			if (schemaProperty) {
				switch (schemaProperty.type) {
				case 'number':
					convertedValue = newValue === '' ? null : parseFloat(newValue)
					if (isNaN(convertedValue)) convertedValue = null
					break
				case 'integer':
					convertedValue = newValue === '' ? null : parseInt(newValue, 10)
					if (isNaN(convertedValue)) convertedValue = null
					break
				case 'boolean':
					convertedValue = Boolean(newValue)
					break
				case 'string':
				default:
					convertedValue = newValue
					break
				}
			}

			// Update the form data using Vue 3 reactivity
			this.formData = { ...this.formData, [key]: convertedValue }

			// Show notification if value actually changed
			if (oldValue !== convertedValue) {
				this.showPropertyChangeNotification(key, oldValue, convertedValue)
			}
		},
		showPropertyChangeNotification(key, oldValue, newValue) {
			// Create a simple notification - you could replace this with a proper toast library
			const notification = document.createElement('div')
			notification.style.cssText = `
				position: fixed;
				top: 20px;
				right: 20px;
				background: var(--color-success);
				color: white;
				padding: 12px 16px;
				border-radius: 6px;
				box-shadow: 0 2px 8px rgba(0,0,0,0.2);
				z-index: 10000;
				font-size: 14px;
				max-width: 300px;
			`
			notification.textContent = `Property '${key}' changed from '${oldValue}' to '${newValue}'`

			document.body.appendChild(notification)

			// Remove notification after 3 seconds
			setTimeout(() => {
				if (notification.parentNode) {
					notification.parentNode.removeChild(notification)
				}
			}, 3000)
		},
		isStringProperty(key) {
			const schemaProperty = this.currentSchema?.properties?.[key]
			return schemaProperty?.type === 'string'
		},
		isPropertyEditable(key, value) {
			const schemaProperty = this.currentSchema?.properties?.[key]

			// If no schema property, allow editing (it's a free-form property)
			if (!schemaProperty) return true

			// Check if property is const
			if (schemaProperty.const !== undefined) {
				return false // Const properties cannot be edited
			}

			// Check if property is immutable and already has a value
			if (schemaProperty.immutable && (value !== null && value !== undefined && value !== '')) {
				return false // Immutable properties with values cannot be edited
			}

			return true
		},
		getEditabilityWarning(key, value) {
			const schemaProperty = this.currentSchema?.properties?.[key]

			if (schemaProperty?.const !== undefined) {
				return `This property is constant and must always be '${schemaProperty.const}'. Const properties cannot be modified to maintain data integrity.`
			}

			if (schemaProperty?.immutable && (value !== null && value !== undefined && value !== '')) {
				return `This property is immutable and cannot be changed once it has a value. Current value: '${value}'. Immutable properties preserve data consistency.`
			}

			return null
		},
		getPropertyInputType(key) {
			const schemaProperty = this.currentSchema?.properties?.[key]
			if (!schemaProperty) return 'text'

			const type = schemaProperty.type
			const format = schemaProperty.format

			// Handle different types and formats
			switch (type) {
			case 'string':
				if (format === 'date') return 'date'
				if (format === 'time') return 'time'
				if (format === 'date-time') return 'datetime-local'
				if (format === 'email') return 'email'
				if (format === 'url' || format === 'uri') return 'url'
				if (format === 'password') return 'password'
				return 'text'
			case 'number':
			case 'integer':
				return 'number'
			case 'boolean':
				return 'checkbox'
			default:
				return 'text'
			}
		},
		getPropertyInputComponent(key) {
			const schemaProperty = this.currentSchema?.properties?.[key]
			if (!schemaProperty) return 'NcTextField'

			const type = schemaProperty.type
			const format = schemaProperty.format

			// Handle different types and formats
			switch (type) {
			case 'boolean':
				return 'NcCheckboxRadioSwitch'
			case 'string':
				if (format === 'date' || format === 'time' || format === 'date-time') {
					return 'NcDateTimePickerNative'
				}
				return 'NcTextField'
			case 'number':
			case 'integer':
				return 'NcTextField'
			default:
				return 'NcTextField'
			}
		},
		/**
		 * Get the display name for a property (title if available, otherwise key)
		 * @param {string} key - The property key
		 * @return {string} The display name
		 */
		getPropertyDisplayName(key) {
			const schemaProperty = this.currentSchema?.properties?.[key]
			return schemaProperty?.title || key
		},
		/**
		 * Get the tooltip text for a property
		 * @param {string} key - The property key
		 * @return {string} The tooltip text
		 */
		getPropertyTooltip(key) {
			const schemaProperty = this.currentSchema?.properties?.[key]

			if (schemaProperty?.description) {
				// If we have both title and description, show both
				if (schemaProperty.title && schemaProperty.title !== key) {
					return `${schemaProperty.title}: ${schemaProperty.description}`
				}
				// If only description or title same as key, just show description
				return schemaProperty.description
			}

			// Fallback to property key info
			return `Property: ${key}`
		},
		/**
		 * Get the minimum value for a property
		 * @param {string} key - The property key
		 * @return {number|undefined} The minimum value
		 */
		getPropertyMinimum(key) {
			const schemaProperty = this.currentSchema?.properties?.[key]
			return schemaProperty?.minimum
		},
		/**
		 * Get the maximum value for a property
		 * @param {string} key - The property key
		 * @return {number|undefined} The maximum value
		 */
		getPropertyMaximum(key) {
			const schemaProperty = this.currentSchema?.properties?.[key]
			return schemaProperty?.maximum
		},
		/**
		 * Get the step value for a property
		 * @param {string} key - The property key
		 * @return {string|undefined} The step value
		 */
		getPropertyStep(key) {
			const schemaProperty = this.currentSchema?.properties?.[key]
			if (schemaProperty?.type === 'integer') {
				return '1'
			}
			if (schemaProperty?.type === 'number') {
				return 'any'
			}
			return undefined
		},
		getDisplayValue(key, value) {
			const schemaProperty = this.currentSchema?.properties?.[key]

			// If property is const, always show the const value
			if (schemaProperty?.const !== undefined) {
				return schemaProperty.const
			}

			// If we have an edited value in formData, use that
			if (this.formData[key] !== undefined) {
				return this.formData[key]
			}

			// If this is a schema property that doesn't exist in the object yet, show placeholder
			if (objectStore.objectItem && !Object.prototype.hasOwnProperty.call(objectStore.objectItem, key) && schemaProperty) {
				return value // This will be the default value we set in objectProperties
			}

			// Otherwise use the original value
			return value
		},
		showWarningNotification(warning) {
			// Create a warning notification
			const notification = document.createElement('div')
			notification.style.cssText = `
				position: fixed;
				top: 20px;
				right: 20px;
				background: var(--color-warning);
				color: white;
				padding: 12px 16px;
				border-radius: 6px;
				box-shadow: 0 2px 8px rgba(0,0,0,0.2);
				z-index: 10000;
				font-size: 14px;
				max-width: 350px;
				line-height: 1.4;
			`
			notification.innerHTML = `
				<div style="display: flex; align-items: center; gap: 8px;">
					<svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor">
						<path d="M12,2A10,10 0 0,0 2,12A10,10 0 0,0 12,22A10,10 0 0,0 22,12A10,10 0 0,0 12,2M12,7A1.25,1.25 0 0,1 13.25,8.25A1.25,1.25 0 0,1 12,9.5A1.25,1.25 0 0,1 10.75,8.25A1.25,1.25 0 0,1 12,7M11,11H13V17H11V11Z"/>
					</svg>
					<span>${warning}</span>
				</div>
			`

			document.body.appendChild(notification)

			// Remove notification after 5 seconds (longer for warnings)
			setTimeout(() => {
				if (notification.parentNode) {
					notification.parentNode.removeChild(notification)
				}
			}, 5000)
		},
	},
}
</script>

<style scoped>
/* Property table row border colors matching validation states */
.viewTableRow.property-invalid {
	background-color: var(--color-error-light);
	border-left: 4px solid var(--color-error);
}

.viewTableRow.property-warning {
	background-color: var(--color-warning-light);
	border-left: 4px solid var(--color-warning);
}

.viewTableRow.property-new {
	background-color: var(--color-primary-element-light);
	border-left: 4px solid var(--color-primary-element);
}

.viewTableRow.property-valid {
	border-left: 4px solid var(--color-success);
}

/* Icon colors for file status */
.warningIcon {
	color: var(--color-warning);
}

.publishedIcon {
	color: var(--color-success);
}

/* Validation icons */
.validation-icon {
	flex-shrink: 0;
}

.error-icon {
	color: var(--color-error);
}

.warning-icon {
	color: var(--color-warning);
}

.lock-icon {
	color: var(--color-text-lighter);
}

.new-icon {
	color: var(--color-primary-element);
}

.prop-cell-content {
	display: flex;
	align-items: center;
	gap: 8px;
}

/* Other necessary styles */
.viewTableRow {
	cursor: pointer;
	transition: background-color 0.2s ease;
}

.viewTableRow:hover {
	background-color: var(--color-background-hover);
}

.viewTableRow.selected-row {
	background-color: var(--color-primary-light);
}

.viewTableRow.edited-row {
	background-color: var(--color-success-light);
	border-left: 3px solid var(--color-success);
}

.viewTableRow.edited-row.selected-row {
	background-color: var(--color-primary-light);
	border-left: 3px solid var(--color-success);
}

.viewTableRow.non-editable-row {
	background-color: var(--color-background-dark);
	cursor: not-allowed;
	opacity: 0.7;
}

.viewTableRow.non-editable-row:hover {
	background-color: var(--color-background-dark);
}
</style>

<style scoped>
/* ViewObject-specific overrides only */
.tableColumnActions {
	width: 100px;
	text-align: center;
}

/* Inline editing styles */
.viewTableRow {
	cursor: pointer;
	transition: background-color 0.2s ease;
}

.viewTableRow:hover {
	background-color: var(--color-background-hover);
}

.viewTableRow.selected-row {
	background-color: var(--color-primary-light);
}

.viewTableRow.edited-row {
	background-color: var(--color-success-light);
	border-left: 3px solid var(--color-success);
}

.viewTableRow.edited-row.selected-row {
	background-color: var(--color-primary-light);
	border-left: 3px solid var(--color-success);
}

.viewTableRow.property-invalid {
	background-color: var(--color-error-light);
	border-left: 4px solid var(--color-error);
}

.viewTableRow.property-warning {
	background-color: var(--color-warning-light);
	border-left: 4px solid var(--color-warning);
}

.viewTableRow.property-new {
	background-color: var(--color-primary-element-light);
	border-left: 4px solid var(--color-primary-element);
}

.prop-cell-content {
	display: flex;
	align-items: center;
	gap: 8px;
}

.validation-icon {
	flex-shrink: 0;
}

.error-icon {
	color: var(--color-error);
}

.warning-icon {
	color: var(--color-warning);
}

.lock-icon {
	color: var(--color-text-lighter);
}

.new-icon {
	color: var(--color-primary-element);
}

/* Icon colors for file status */
.warningIcon {
	color: var(--color-warning);
}

.publishedIcon {
	color: var(--color-success);
}

.viewTableRow.non-editable-row {
	background-color: var(--color-background-dark);
	cursor: not-allowed;
	opacity: 0.7;
}

.viewTableRow.non-editable-row:hover {
	background-color: var(--color-background-dark);
}

.value-cell {
	position: relative;
}

.value-input-container {
	padding: 0;
	margin: 0;
	width: 100%;
}

.value-input-container .text-field {
	margin: 0;
	padding: 0;
}

.json-value {
	max-height: 200px;
	overflow-y: auto;
	white-space: pre-wrap;
	font-family: monospace;
	font-size: 12px;
	background: var(--color-background-dark);
	padding: 8px;
	border-radius: 4px;
	margin: 0;
}

/* CodeMirror selection styles - matching EditObject modal */
.codeMirrorContainer {
	margin-block-start: 6px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	position: relative;
}

.codeMirrorContainer :deep(.cm-editor) {
	height: 100%;
}

.codeMirrorContainer :deep(.cm-scroller) {
	overflow: auto;
}

.codeMirrorContainer :deep(.cm-content) {
	border-radius: 0 !important;
	border: none !important;
}

.codeMirrorContainer :deep(.cm-editor) {
	outline: none !important;
}

.codeMirrorContainer.light > .vue-codemirror {
	border: 1px dotted silver;
}

.codeMirrorContainer.dark > .vue-codemirror {
	border: 1px dotted grey;
}

/* value text color */
/* string */
.codeMirrorContainer.light :deep(.ͼe) {
	color: #448c27;
}
.codeMirrorContainer.dark :deep(.ͼe) {
	color: #88c379;
}

/* boolean */
.codeMirrorContainer.light :deep(.ͼc) {
	color: #221199;
}
.codeMirrorContainer.dark :deep(.ͼc) {
	color: #8d64f7;
}

/* null */
.codeMirrorContainer.light :deep(.ͼb) {
	color: #770088;
}
.codeMirrorContainer.dark :deep(.ͼb) {
	color: #be55cd;
}

/* number */
.codeMirrorContainer.light :deep(.ͼd) {
	color: #d19a66;
}
.codeMirrorContainer.dark :deep(.ͼd) {
	color: #9d6c3a;
}

/* text cursor */
.codeMirrorContainer :deep(.cm-content) * {
	cursor: text !important;
}

/* selection color - THIS FIXES THE WHITE SELECTION ISSUE */
.codeMirrorContainer.light :deep(.cm-line)::selection,
.codeMirrorContainer.light :deep(.cm-line) ::selection {
	background-color: #d7eaff !important;
	color: black;
}
.codeMirrorContainer.dark :deep(.cm-line)::selection,
.codeMirrorContainer.dark :deep(.cm-line) ::selection {
	background-color: #8fb3e6 !important;
	color: black;
}

/* string selection */
.codeMirrorContainer.light :deep(.cm-line .ͼe)::selection {
	color: #2d770f;
}
.codeMirrorContainer.dark :deep(.cm-line .ͼe)::selection {
	color: #104e0c;
}

/* boolean selection */
.codeMirrorContainer.light :deep(.cm-line .ͼc)::selection {
	color: #221199;
}
.codeMirrorContainer.dark :deep(.cm-line .ͼc)::selection {
	color: #4026af;
}

/* null selection */
.codeMirrorContainer.light :deep(.cm-line .ͼb)::selection {
	color: #770088;
}
.codeMirrorContainer.dark :deep(.cm-line .ͼb)::selection {
	color: #770088;
}

/* number selection */
.codeMirrorContainer.light :deep(.cm-line .ͼd)::selection {
	color: #8c5c2c;
}
.codeMirrorContainer.dark :deep(.cm-line .ͼd)::selection {
	color: #623907;
}
</style>

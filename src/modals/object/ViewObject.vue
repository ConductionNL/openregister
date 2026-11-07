<script setup>
import { translatePlural as n, translate as t } from '@nextcloud/l10n'
import {
	navigationStore,
	objectStore,
	registerStore,
	schemaStore,
} from '../../store/store.js'
</script>

<template>
	<div>
		<NcDialog
			v-if="navigationStore.modal === 'viewObject'"
			:name="getModalTitle()"
			size="large"
			@update:open="handleDialogClose">
			<div class="formContainer viewObjectDialog">
				<!-- Register/Schema Selection (for new objects with multiple options) -->
				<div v-if="showRegisterSchemaSelection" class="selection-step">
					<div class="selection-container">
						<h3>
							{{ t('openregister', 'Select Register and Schema') }}
						</h3>
						<p class="selection-hint">
							{{
								t(
									'openregister',
									'Please select which register and schema to use for the new object',
								)
							}}
						</p>

						<div class="selection-fields">
							<div
								v-if="availableRegisters.length > 1"
								class="field-group">
								<label for="register-select">{{
									t('openregister', 'Register')
								}}</label>
								<NcSelect
									id="register-select"
									v-model="selectedRegisterForNewObject"
									inputLabel="Selected Register For New Object"
									:options="availableRegisters"
									label="title"
									trackBy="id"
									:placeholder="
										t('openregister', 'Choose a register')
									"
									:clearable="false" />
							</div>

							<div
								v-if="availableSchemas.length > 1"
								class="field-group">
								<label for="schema-select">{{
									t('openregister', 'Schema')
								}}</label>
								<NcSelect
									id="schema-select"
									v-model="selectedSchemaForNewObject"
									inputLabel="Selected Schema For New Object"
									:options="availableSchemas"
									label="title"
									trackBy="id"
									:placeholder="
										t('openregister', 'Choose a schema')
									"
									:clearable="false" />
							</div>

							<NcButton
								variant="primary"
								:disabled="!canProceedToProperties"
								@click="confirmRegisterSchemaSelection">
								<template #icon>
									<ArrowRight :size="20" />
								</template>
								{{ t('openregister', 'Continue to Properties') }}
							</NcButton>
						</div>
					</div>
				</div>

				<!-- Display Object -->
				<div v-else>
					<div class="tabContainer">
						<AppTabs v-model="activeTab" contentClass="mt-3" justified>
							<AppTab :title="t('openregister', 'Properties')" active>
								<div class="viewTableContainer">
									<table class="viewTable">
										<thead>
											<tr class="viewTableRow">
												<th
													scope="col"
													class="tableColumnConstrained">
													Property
												</th>
												<th
													scope="col"
													class="tableColumnExpanded">
													Value
												</th>
											</tr>
										</thead>
										<tbody>
											<tr
												v-for="[
													key,
													value,
												] in objectProperties"
												:key="key"
												class="viewTableRow"
												:class="{
													'selected-row':
														selectedProperty === key,
													'edited-row':
														formData[key] !== undefined,
													'non-editable-row':
														!isPropertyEditable(
															key,
															formData[key]
																!== undefined
																? formData[key]
																: value,
														),
													...getPropertyValidationClass(
														key,
														value,
													),
												}"
												@click="handleRowClick(key, $event)">
												<td
													class="tableColumnConstrained prop-cell">
													<div class="prop-cell-content">
														<AlertCircle
															v-if="
																getPropertyValidationClass(
																	key,
																	value,
																)
																=== 'property-invalid'
															"
															:title="
																getPropertyErrorMessage(
																	key,
																	value,
																)
															"
															class="validation-icon error-icon"
															:size="16" />
														<Alert
															v-else-if="
																getPropertyValidationClass(
																	key,
																	value,
																)
																=== 'property-warning'
															"
															:title="
																getPropertyWarningMessage(
																	key,
																	value,
																)
															"
															class="validation-icon warning-icon"
															:size="16" />
														<Plus
															v-else-if="
																getPropertyValidationClass(
																	key,
																	value,
																) === 'property-new'
															"
															:title="
																getPropertyNewMessage(
																	key,
																)
															"
															class="validation-icon new-icon"
															:size="16" />
														<LockOutline
															v-else-if="
																!isPropertyEditable(
																	key,
																	formData[key]
																		!== undefined
																		? formData[
																				key
																			]
																		: value,
																)
															"
															:title="
																getEditabilityWarning(
																	key,
																	formData[key]
																		!== undefined
																		? formData[
																				key
																			]
																		: value,
																)
															"
															class="validation-icon lock-icon"
															:size="16" />
														<span
															:title="
																getPropertyTooltip(
																	key,
																)
															">
															{{
																getPropertyDisplayName(
																	key,
																)
															}}
														</span>
													</div>
												</td>
												<td
													class="tableColumnExpanded value-cell">
													<div
														v-if="
															selectedProperty === key
															&& isPropertyEditable(
																key,
																formData[key]
																	!== undefined
																	? formData[key]
																	: value,
															)
														"
														class="value-input-container"
														@click.stop>
														<!-- Boolean properties -->
														<NcCheckboxRadioSwitch
															v-if="
																getPropertyInputComponent(
																	key,
																)
																=== 'NcCheckboxRadioSwitch'
															"
															:modelValue="
																formData[key]
																!== undefined
																	? formData[key]
																	: value
															"
															type="switch"
															@update:modelValue="
																updatePropertyValue(
																	key,
																	$event,
																)
															">
															{{
																getPropertyDisplayName(
																	key,
																)
															}}
														</NcCheckboxRadioSwitch>

														<!-- Date/Time properties -->
														<NcDateTimePickerNative
															v-else-if="
																getPropertyInputComponent(
																	key,
																)
																=== 'NcDateTimePickerNative'
															"
															:modelValue="
																stringToDate(
																	formData[key]
																		!== undefined
																		? formData[
																				key
																			]
																		: value,
																	currentSchema
																		.properties[
																		key
																	].format,
																) || undefined
															"
															:type="
																getPropertyInputType(
																	key,
																)
															"
															:label="
																getPropertyDisplayName(
																	key,
																)
															"
															@update:modelValue="
																updatePropertyValue(
																	key,
																	$event,
																)
															" />

														<!-- Enum and oneOf properties: a choice list of the declared values (REQ-RFCE-001) -->
														<NcSelect
															v-else-if="
																getPropertyInputComponent(
																	key,
																) === 'NcSelect'
															"
															:modelValue="
																enumOptionFor(
																	key,
																	formData[key]
																		!== undefined
																		? formData[
																				key
																			]
																		: value,
																)
															"
															:options="
																enumOptionsFor(key)
															"
															label="label"
															:inputLabel="
																getPropertyDisplayName(
																	key,
																)
															"
															@update:modelValue="
																updatePropertyValue(
																	key,
																	$event
																		? $event.value
																		: null,
																)
															" />

														<!-- File properties: a file picker (REQ-RFCE-001) -->
														<input
															v-else-if="
																getPropertyInputComponent(
																	key,
																) === 'file'
															"
															type="file"
															class="property-file-input"
															:aria-label="
																getPropertyDisplayName(
																	key,
																)
															"
															@change="
																onPropertyFilePicked(
																	key,
																	$event,
																)
															" />

														<!-- Translatable properties: one input per register language (REQ-RFCE-001) -->
														<TranslationFieldEditor
															v-else-if="
																getPropertyInputComponent(
																	key,
																)
																=== 'TranslationFieldEditor'
															"
															:value="
																translationValueFor(
																	formData[key]
																		!== undefined
																		? formData[
																				key
																			]
																		: value,
																)
															"
															:languages="
																registerLanguages
															"
															:label="
																getPropertyDisplayName(
																	key,
																)
															"
															@input="
																updatePropertyValue(
																	key,
																	$event,
																)
															" />

														<!-- Text/Number properties -->
														<NcTextField
															v-else
															ref="propertyValueInput"
															:modelValue="
																String(
																	formData[key]
																		!== undefined
																		? formData[
																				key
																			]
																		: value
																				|| '',
																)
															"
															:type="
																getPropertyInputType(
																	key,
																)
															"
															:aria-label="
																getPropertyDisplayName(
																	key,
																)
															"
															:placeholder="
																getPropertyDisplayName(
																	key,
																)
															"
															:min="
																getPropertyMinimum(
																	key,
																)
															"
															:max="
																getPropertyMaximum(
																	key,
																)
															"
															:step="
																getPropertyStep(key)
															"
															@update:modelValue="
																updatePropertyValue(
																	key,
																	$event,
																)
															" />
													</div>
													<div v-else>
														<template
															v-if="
																formData[key]
																!== undefined
															">
															<!-- Show edited value -->
															<pre
																v-if="
																	typeof formData[
																		key
																	] === 'object'
																	&& formData[key]
																		!== null
																"
																title="JSON object (edited)"
																class="json-value"
																>{{
																	formatValue(
																		formData[
																			key
																		],
																	)
																}}</pre>
															<span
																v-else-if="
																	isValidDate(
																		formData[
																			key
																		],
																	)
																"
																:title="`Date: ${new Date(formData[key]).toISOString()} (edited)`"
																>{{
																	new Date(
																		formData[
																			key
																		],
																	).toLocaleString()
																}}</span
															>
															<span
																v-else
																:title="
																	currentSchema
																		?.properties?.[
																		key
																	]?.description
																	|| `Property: ${key} (edited)`
																"
																>{{
																	getDisplayValue(
																		key,
																		value,
																	)
																}}</span
															>
														</template>
														<template v-else>
															<!-- Show original value -->
															<pre
																v-if="
																	typeof value
																		=== 'object'
																	&& value !== null
																"
																title="JSON object"
																class="json-value"
																>{{
																	formatValue(
																		value,
																	)
																}}</pre>
															<span
																v-else-if="
																	isValidDate(
																		value,
																	)
																"
																:title="`Date: ${new Date(value).toISOString()}`"
																>{{
																	new Date(
																		value,
																	).toLocaleString()
																}}</span
															>
															<span
																v-else
																:title="
																	currentSchema
																		?.properties?.[
																		key
																	]?.description
																	|| `Property: ${key}`
																"
																>{{
																	getDisplayValue(
																		key,
																		value,
																	)
																}}</span
															>
														</template>
													</div>
												</td>
											</tr>
										</tbody>
									</table>
								</div>
							</AppTab>
							<AppTab
								v-if="!isNewObject"
								:title="t('openregister', 'Metadata')">
								<div class="viewTableContainer">
									<table class="viewTable">
										<thead>
											<tr class="viewTableRow">
												<th
													scope="col"
													class="tableColumnConstrained">
													{{
														t('openregister', 'Metadata')
													}}
												</th>
												<th
													scope="col"
													class="tableColumnExpanded">
													Value
												</th>
												<th
													scope="col"
													class="tableColumnActions">
													Actions
												</th>
											</tr>
										</thead>
										<tbody>
											<tr
												v-for="[
													key,
													value,
													hasAction,
												] in metadataProperties"
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
														v-if="
															hasAction && key === 'ID'
														"
														class="copy-button"
														size="small"
														@click="
															copyToClipboard(
																objectStore
																	.objectItem.id,
															)
														">
														<template #icon>
															<Check
																v-if="isCopied"
																:size="16" />
															<ContentCopy
																v-else
																:size="16" />
														</template>
														{{
															isCopied
																? t(
																		'openregister',
																		'Copied',
																	)
																: t(
																		'openregister',
																		'Copy',
																	)
														}}
													</NcButton>
												</td>
											</tr>
										</tbody>
									</table>
								</div>
							</AppTab>
							<AppTab :title="t('openregister', 'Data')">
								<NcNoteCard
									v-if="success"
									type="success"
									class="note-card">
									<p>
										{{
											t(
												'openregister',
												'Object successfully modified',
											)
										}}
									</p>
								</NcNoteCard>
								<div class="json-editor">
									<div
										:class="`codeMirrorContainer ${getTheme()}`">
										<CodeMirror
											v-model="jsonData"
											:basic="true"
											placeholder='{ "key": "value" }'
											:dark="getTheme() === 'dark'"
											:linter="jsonParseLinter()"
											:lang="json()"
											:extensions="[json()]"
											:tabSize="2"
											style="height: 400px" />
										<NcButton
											class="format-json-button"
											variant="secondary"
											size="small"
											@click="formatJSON">
											Format JSON
										</NcButton>
									</div>
									<span
										v-if="!isValidJson(jsonData)"
										class="error-message">
										Invalid JSON format
									</span>
								</div>
							</AppTab>
							<AppTab
								v-if="!isNewObject"
								:title="t('openregister', 'Uses')">
								<div
									v-if="objectStore.uses.results.length > 0"
									class="search-list-table">
									<table class="table">
										<thead>
											<tr class="table-row">
												<th scope="col">ID</th>
												<th scope="col">URI</th>
												<th scope="col">Schema</th>
												<th scope="col">Register</th>
												<th scope="col">Actions</th>
											</tr>
										</thead>
										<tbody>
											<tr
												v-for="use in objectStore.uses
													.results"
												:key="use['@self'].id"
												class="table-row">
												<td>{{ use['@self'].id }}</td>
												<td>{{ use['@self'].uri }}</td>
												<td>{{ use['@self'].schema }}</td>
												<td>{{ use['@self'].register }}</td>
												<td>
													<NcButton
														@click="
															() => {
																objectStore.setObjectItem(
																	use,
																)
																navigationStore.setModal(
																	'viewObject',
																)
															}
														">
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
							</AppTab>
							<AppTab
								v-if="!isNewObject"
								:title="t('openregister', 'Used by')">
								<div
									v-if="objectStore.used.results.length > 0"
									class="search-list-table">
									<table class="table">
										<thead>
											<tr class="table-row">
												<th scope="col">ID</th>
												<th scope="col">URI</th>
												<th scope="col">Schema</th>
												<th scope="col">Register</th>
												<th scope="col">Actions</th>
											</tr>
										</thead>
										<tbody>
											<tr
												v-for="usedBy in objectStore.used
													.results"
												:key="usedBy['@self'].id"
												class="table-row">
												<td>{{ usedBy['@self'].id }}</td>
												<td>{{ usedBy['@self'].uri }}</td>
												<td>{{ usedBy['@self'].schema }}</td>
												<td>
													{{ usedBy['@self'].register }}
												</td>
												<td>
													<NcButton
														@click="
															() => {
																objectStore.setObjectItem(
																	usedBy,
																)
																navigationStore.setModal(
																	'viewObject',
																)
															}
														">
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
							</AppTab>
							<AppTab
								v-if="!isNewObject"
								:title="t('openregister', 'Contracts')">
								<div
									v-if="objectStore.contracts.length > 0"
									class="search-list-table">
									<table class="table">
										<thead>
											<tr class="table-row">
												<th scope="col">ID</th>
												<th scope="col">URI</th>
												<th scope="col">Schema</th>
												<th scope="col">Register</th>
												<th scope="col">Actions</th>
											</tr>
										</thead>
										<tbody>
											<tr
												v-for="contract in objectStore.contracts"
												:key="contract['@self'].id"
												class="table-row">
												<td>{{ contract['@self'].id }}</td>
												<td>{{ contract['@self'].uri }}</td>
												<td>
													{{ contract['@self'].schema }}
												</td>
												<td>
													{{ contract['@self'].register }}
												</td>
												<td>
													<NcButton
														@click="
															() => {
																objectStore.setObjectItem(
																	contract,
																)
																navigationStore.setModal(
																	'viewObject',
																)
															}
														">
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
							</AppTab>
							<AppTab
								v-if="!isNewObject"
								:title="t('openregister', 'Files')">
								<div
									v-if="paginatedFiles.length > 0"
									class="viewTableContainer">
									<table class="viewTable">
										<thead>
											<tr class="viewTableRow">
												<th class="tableColumnCheckbox">
													<NcCheckboxRadioSwitch
														:modelValue="
															allFilesSelected
														"
														:indeterminate="
															someFilesSelected
														"
														:aria-label="
															t(
																'openregister',
																'Select All',
															)
														"
														@update:modelValue="
															toggleSelectAllFiles
														" />
												</th>
												<th
													scope="col"
													class="tableColumnExpanded">
													Name
												</th>
												<th
													scope="col"
													class="tableColumnConstrained">
													Size
												</th>
												<th
													scope="col"
													class="tableColumnConstrained">
													Type
												</th>
												<th
													scope="col"
													class="tableColumnConstrained">
													Labels
												</th>
												<th
													scope="col"
													class="tableColumnActions">
													<NcActions
														:forceName="true"
														:disabled="
															selectedAttachments.length
															=== 0
														"
														:title="
															selectedAttachments.length
															=== 0
																? 'Select one or more files to use mass actions'
																: `Mass actions (${selectedAttachments.length} selected)`
														"
														:menuName="`Mass Actions (${selectedAttachments.length})`">
														<template #icon>
															<FormatListChecks
																:size="20" />
														</template>
														<NcActionButton
															:disabled="
																fileIdsLoading.length
																	> 0
																|| selectedAttachments.length
																	=== 0
															"
															@click="
																deleteSelectedFiles
															">
															<template #icon>
																<NcLoadingIcon
																	v-if="
																		fileIdsLoading.length
																		> 0
																	"
																	:size="20" />
																<Delete
																	v-else
																	:size="20" />
															</template>
															Delete
															{{
																selectedAttachments.length
															}}
															file{{
																selectedAttachments.length
																> 1
																	? 's'
																	: ''
															}}
														</NcActionButton>
													</NcActions>
												</th>
											</tr>
										</thead>
										<tbody>
											<tr
												v-for="(
													attachment, i
												) in paginatedFiles"
												:key="`${attachment.id}${i}`"
												:class="{
													active:
														activeAttachment
														=== attachment.id,
												}"
												class="viewTableRow"
												@click="
													() => {
														if (
															activeAttachment
															=== attachment.id
														)
															activeAttachment = null
														else
															activeAttachment =
																attachment.id
													}
												">
												<td class="tableColumnCheckbox">
													<NcCheckboxRadioSwitch
														:modelValue="
															selectedAttachments.includes(
																attachment.id,
															)
														"
														:aria-labelledby="`attachment-row-title-${attachment.id}`"
														@update:modelValue="
															(checked) =>
																toggleFileSelection(
																	attachment.id,
																	checked,
																)
														" />
												</td>
												<td
													:id="`attachment-row-title-${attachment.id}`"
													class="tableColumnExpanded table-row-title">
													<!-- Show warning icon if file is not shared -->
													<ExclamationThick
														v-if="
															!attachment.accessUrl
															&& !attachment.downloadUrl
														"
														title="Not shared"
														class="warningIcon"
														:size="20" />
													<!-- Show published icon if file is shared -->
													<FileOutline
														v-else
														class="publishedIcon"
														:size="20" />
													{{
														truncateFileName(
															attachment.name
																?? attachment?.title,
														)
													}}
												</td>
												<td class="tableColumnConstrained">
													{{
														formatFileSize(
															attachment?.size,
														)
													}}
												</td>
												<td class="tableColumnConstrained">
													{{
														attachment?.type || 'No type'
													}}
												</td>
												<td class="tableColumnConstrained">
													<div
														v-if="
															editingLabelsFileId
															!== attachment.id
														"
														class="fileLabelsContainer">
														<NcCounterBubble
															v-for="label of attachment.labels"
															:key="label">
															{{ label }}
														</NcCounterBubble>
													</div>
													<div
														v-else
														class="fileLabelsEditContainer">
														<NcSelect
															v-model="editingLabels"
															:options="
																availableLabels
															"
															:taggable="true"
															:multiple="true"
															:disabled="labelsLoading"
															:inputLabel="
																t(
																	'openregister',
																	'Labels',
																)
															" />
														<div
															class="fileLabelsEditActions">
															<NcButton
																:disabled="
																	labelsLoading
																"
																variant="primary"
																:aria-label="
																	t(
																		'openregister',
																		'Save labels',
																	)
																"
																@click="
																	saveFileLabels(
																		attachment,
																	)
																">
																<template #icon>
																	<NcLoadingIcon
																		v-if="
																			labelsLoading
																		"
																		:size="20" />
																	<Check
																		v-else
																		:size="20" />
																</template>
															</NcButton>
															<NcButton
																:disabled="
																	labelsLoading
																"
																:aria-label="
																	t(
																		'openregister',
																		'Cancel',
																	)
																"
																@click="
																	cancelFileLabels()
																">
																<template #icon>
																	<Cancel
																		:size="20" />
																</template>
															</NcButton>
														</div>
													</div>
												</td>
												<td class="tableColumnActions">
													<NcActions>
														<NcActionButton
															@click="
																openFile(attachment)
															">
															<template #icon>
																<OpenInNew
																	:size="20" />
															</template>
															View
														</NcActionButton>
														<NcActionButton
															@click="
																editFileLabels(
																	attachment,
																)
															">
															<template #icon>
																<Tag :size="20" />
															</template>
															Labels
														</NcActionButton>
														<NcActionButton
															:disabled="
																fileIdsLoading.includes(
																	attachment.id,
																)
															"
															@click="
																deleteFile(
																	attachment,
																)
															">
															<template #icon>
																<NcLoadingIcon
																	v-if="
																		fileIdsLoading.includes(
																			attachment.id,
																		)
																	"
																	:size="20" />
																<Delete
																	v-else
																	:size="20" />
															</template>
															Delete
														</NcActionButton>
													</NcActions>
												</td>
											</tr>
										</tbody>
									</table>
								</div>
								<NcEmptyContent
									v-else
									name="No files attached"
									:description="
										isNewObject
											? 'Save the object first to attach files'
											: 'No files have been attached to this object'
									">
									<template #icon>
										<FileOutline :size="64" />
									</template>
								</NcEmptyContent>

								<!-- Files Pagination -->
								<PaginationComponent
									v-if="
										objectStore.files?.results?.length
										> filesPerPage
									"
									:currentPage="filesCurrentPage"
									:totalPages="filesTotalPages"
									:totalItems="
										objectStore.files?.results?.length || 0
									"
									:currentPageSize="filesPerPage"
									:minItemsToShow="5"
									@pageChanged="onFilesPageChanged"
									@pageSizeChanged="onFilesPageSizeChanged" />
							</AppTab>
							<AppTab
								v-if="!isNewObject && relationContext"
								title="Emails">
								<EmailsTab
									:register="relationContext.register"
									:schema="relationContext.schema"
									:objectId="relationContext.id" />
							</AppTab>
							<AppTab
								v-if="!isNewObject && relationContext"
								title="Events">
								<EventsTab
									:register="relationContext.register"
									:schema="relationContext.schema"
									:objectId="relationContext.id" />
							</AppTab>
							<AppTab
								v-if="!isNewObject && relationContext"
								title="Contacts">
								<ContactsTab
									:register="relationContext.register"
									:schema="relationContext.schema"
									:objectId="relationContext.id" />
							</AppTab>
							<AppTab
								v-if="!isNewObject && relationContext"
								title="Deck">
								<DeckTab
									:register="relationContext.register"
									:schema="relationContext.schema"
									:objectId="relationContext.id" />
							</AppTab>
							<AppTab
								v-if="!isNewObject && relationContext"
								title="Relations">
								<RelationsTab
									:register="relationContext.register"
									:schema="relationContext.schema"
									:objectId="relationContext.id" />
							</AppTab>
						</AppTabs>
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
				<NcButton
					v-if="!isNewObject"
					@click="
						() => {
							navigationStore.setModal('uploadFiles')
							objectStore.setObjectItem(objectStore.objectItem)
						}
					">
					<template #icon>
						<Upload :size="20" />
					</template>
					Add File
				</NcButton>
				<NcButton v-if="!isNewObject" @click="viewAuditTrails">
					<template #icon>
						<TextBoxOutline :size="20" />
					</template>
					Audit Trails
				</NcButton>
				<NcButton variant="primary" :disabled="isSaving" @click="saveObject">
					<template #icon>
						<NcLoadingIcon v-if="isSaving" :size="20" />
						<ContentSave v-else :size="20" />
					</template>
					{{
						isSaving
							? isNewObject
								? t('openregister', 'Creating…')
								: t('openregister', 'Saving…')
							: isNewObject
								? t('openregister', 'Create')
								: t('openregister', 'Save')
					}}
				</NcButton>
			</template>
		</NcDialog>
	</div>
</template>

<script>
import { json, jsonParseLinter } from '@codemirror/lang-json'
import { showWarning } from '@nextcloud/dialogs'
import {
	NcActionButton,
	NcActions,
	NcButton,
	NcCheckboxRadioSwitch,
	NcCounterBubble,
	NcDateTimePickerNative,
	NcDialog,
	NcEmptyContent,
	NcLoadingIcon,
	NcNoteCard,
	NcSelect,
	NcTextField,
} from '@nextcloud/vue'
import CodeMirror from 'vue-codemirror6'
import Alert from 'vue-material-design-icons/Alert.vue'
import AlertCircle from 'vue-material-design-icons/AlertCircle.vue'
import ArrowRight from 'vue-material-design-icons/ArrowRight.vue'
import Cancel from 'vue-material-design-icons/Cancel.vue'
import Check from 'vue-material-design-icons/Check.vue'
import ContentCopy from 'vue-material-design-icons/ContentCopy.vue'
import ContentSave from 'vue-material-design-icons/ContentSave.vue'
import Delete from 'vue-material-design-icons/Delete.vue'
import ExclamationThick from 'vue-material-design-icons/ExclamationThick.vue'
import Eye from 'vue-material-design-icons/Eye.vue'
import FileOutline from 'vue-material-design-icons/FileOutline.vue'
import FormatListChecks from 'vue-material-design-icons/FormatListChecks.vue'
import LockOutline from 'vue-material-design-icons/LockOutline.vue'
import OpenInNew from 'vue-material-design-icons/OpenInNew.vue'
import Plus from 'vue-material-design-icons/Plus.vue'
import Tag from 'vue-material-design-icons/Tag.vue'
import TextBoxOutline from 'vue-material-design-icons/TextBoxOutline.vue'
import Upload from 'vue-material-design-icons/Upload.vue'
import TranslationFieldEditor from '../../components/i18n/TranslationFieldEditor.vue'
import ContactsTab from '../../components/object-relations/ContactsTab.vue'
import DeckTab from '../../components/object-relations/DeckTab.vue'
import EmailsTab from '../../components/object-relations/EmailsTab.vue'
import EventsTab from '../../components/object-relations/EventsTab.vue'
import RelationsTab from '../../components/object-relations/RelationsTab.vue'
import PaginationComponent from '../../components/PaginationComponent.vue'
import AppTab from '../../components/tabs/AppTab.vue'
import AppTabs from '../../components/tabs/AppTabs.vue'
import { stringToDate } from '../../services/dateUtils.js'
import { updateFileLabels } from '../../services/fileMetadata.js'
import { getTheme } from '../../services/getTheme.js'
import {
	editorFor,
	enumOptions,
	readFileAsDataUri,
} from '../../services/propertyEditor.js'
import {
	convertInputValue,
	defaultValueFor,
	inputTypeFor,
	isValidDate,
	isValidPropertyValue,
} from '../../services/propertyValues.js'

/**
 * A metadata value, or 'Not set' when it is empty.
 *
 * @param {string|number} value The value
 * @return {string|number} The value or 'Not set'
 */
function orNotSet(value) {
	return value || 'Not set'
}

/**
 * A metadata timestamp in local time, or 'Not set' when it is empty.
 *
 * @param {string} value The timestamp
 * @return {string} The local time or 'Not set'
 */
function localTimeOrNotSet(value) {
	return value ? new Date(value).toLocaleString() : 'Not set'
}

/**
 * The validation state of a record's metadata.
 *
 * @param {object} self The record's metadata
 * @return {string} 'Valid', 'Invalid' or 'Not validated'
 */
function validationText(self) {
	if (self?.validation === null) {
		return 'Not validated'
	}
	return self.validation ? 'Valid' : 'Invalid'
}

const BLANK_VALUES = {
	string: () => '',
	number: () => 0,
	integer: () => 0,
	boolean: () => false,
	array: () => [],
	object: () => ({}),
}

/**
 * The blank value a new record gets for a property of this type, or null.
 *
 * @param {object} property The schema property.
 * @return {unknown}
 */
function blankValueFor(property) {
	return Object.hasOwn(BLANK_VALUES, property.type)
		? BLANK_VALUES[property.type]()
		: null
}

/**
 * The id of a reference that is either an object with an id or the id itself.
 *
 * @param {unknown} reference The reference.
 * @return {unknown}
 */
function idOf(reference) {
	return typeof reference === 'object' && reference !== null
		? reference.id
		: reference
}

/**
 * The properties a schema inherits from its allOf parents, later parents
 * overriding earlier ones.
 *
 * @param {Array} allOf The parent references: ids, uuids or objects with an id
 * @param {Array<object>} schemas The known schemas
 * @return {object} The inherited properties
 */
function inheritedProperties(allOf, schemas) {
	const inherited = {}
	for (const ref of allOf) {
		const parent = schemaById(schemas, typeof ref === 'object' ? ref.id : ref)
		if (parent?.properties) {
			Object.assign(inherited, parent.properties)
		}
	}
	return inherited
}

/**
 * The schema with an id or uuid, compared loosely so '3' finds 3.
 *
 * @param {Array<object>} schemas The known schemas
 * @param {string|number} id The id or uuid
 * @return {object|undefined} The schema
 */
function schemaById(schemas, id) {
	return schemas.find(
		(s) => s.id === id || s.uuid === id || String(s.id) === String(id),
	)
}

/**
 * The form fields of a schema's properties; a property without a definition
 * is a string.
 *
 * @param {object|null} schema The schema
 * @return {object} The fields
 */
function schemaFields(schema) {
	const fields = {}
	if (schema && schema.properties) {
		for (const [key, value] of Object.entries(schema.properties)) {
			fields[key] = value || { type: 'string' }
		}
	}
	return fields
}

const NOT_FIELDS = ['@self', 'id']

/**
 * Add a field for each object property that has none yet, typed by its value.
 *
 * @param {object} fields The fields so far; extended in place
 * @param {object|null} item The object
 */
function addObjectFields(fields, item) {
	for (const [key, value] of Object.entries(item || {})) {
		if (!NOT_FIELDS.includes(key) && !fields[key]) {
			fields[key] = {
				type: valueType(value),
				title: key,
				description: `Property: ${key}`,
			}
		}
	}
}

/**
 * The JSON schema type a value looks like; anything else is a string.
 *
 * @param {*} value The value
 * @return {string} The type
 */
function valueType(value) {
	if (typeof value === 'boolean' || typeof value === 'number') {
		return typeof value
	}
	if (Array.isArray(value)) {
		return 'array'
	}
	return typeof value === 'object' && value !== null ? 'object' : 'string'
}

/**
 * Whether a schema uses composition but has no properties yet, so the list
 * endpoint returned it un-resolved.
 *
 * @param {object|null} schema The schema
 * @return {boolean} Whether to fetch the resolved schema
 */
function isUnresolved(schema) {
	if (!schema?.id) {
		return false
	}
	const composed = ['allOf', 'oneOf', 'anyOf'].some(
		(key) => (schema[key]?.length || 0) > 0,
	)
	return composed && Object.keys(schema.properties || {}).length === 0
}

/**
 * Fetch the resolved schema into the store.
 *
 * @param {object} schema The un-resolved schema
 * @return {Promise<boolean>} Whether the fetch succeeded
 */
async function fetchResolvedSchema(schema) {
	try {
		await schemaStore.getSchema(schema.id, { setItem: true })
		return true
	} catch {
		return false
	}
}

/**
 * Whether a value is missing or empty.
 *
 * @param {*} value The value
 * @return {boolean} Whether it is blank
 */
function isBlank(value) {
	return value === null || value === undefined || value === ''
}

/**
 * The actual type of a value as a schema names it.
 *
 * @param {*} value The value
 * @return {string} The type
 */
function actualType(value) {
	return Array.isArray(value) ? 'array' : typeof value
}

/**
 * The checks of a property value, in order; each returns its message or null.
 */
const PROPERTY_PROBLEMS = [
	(c) =>
		isBlank(c.value) && c.required
			? `Required property '${c.key}' is missing or empty`
			: null,
	(c) =>
		c.property.type !== actualType(c.value)
			? `Property '${c.key}' should be ${c.property.type} but is ${actualType(c.value)}`
			: null,
	(c) =>
		c.property.format === 'date-time' && !c.isValidDate(c.value)
			? `Property '${c.key}' should be a valid date-time format`
			: null,
	(c) =>
		c.property.const && c.value !== c.property.const
			? `Property '${c.key}' should be '${c.property.const}' but is '${c.value}'`
			: null,
]

/**
 * A value as JSON, or as plain text when it cannot be serialised.
 *
 * @param {object} value The value
 * @return {string} The text
 */
function jsonOrString(value) {
	try {
		return JSON.stringify(value)
	} catch {
		return String(value)
	}
}

/**
 * Whether a click landed on an inline control rather than on the row.
 *
 * @param {Element} target The clicked element
 * @return {boolean} Whether it is a control
 */
function isInlineControl(target) {
	return (
		target.tagName === 'INPUT'
		|| target.tagName === 'BUTTON'
		|| Boolean(target.closest('.value-input-container'))
	)
}

const INLINE_TYPES = ['string', 'number', 'integer', 'boolean']

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
		NcDateTimePickerNative,
		NcActions,
		NcActionButton,
		NcEmptyContent,
		NcSelect,
		TranslationFieldEditor,
		CodeMirror,
		AppTabs,
		AppTab,
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
		ExclamationThick,
		ArrowRight,
		PaginationComponent,
		EmailsTab,
		EventsTab,
		ContactsTab,
		DeckTab,
		RelationsTab,
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
			// Vue 3 initialises `instance.update` to null and only assigns the render
			// effect in setupRenderEffect, i.e. AFTER the `immediate: true` watchers
			// below have already run. Calling $forceUpdate() in that window makes Vue
			// queue a null job and throw `Cannot read properties of null (reading
			// 'flags')`. Vue 2's $forceUpdate() guarded on `vm._watcher &&` so the same
			// call was a silent no-op there. Gate the call on this flag instead.
			isMounted: false,
			objectEditors: {},
			tabOptions: [
				'Properties',
				'Metadata',
				'Data',
				'Uses',
				'Used by',
				'Contracts',
				'Files',
			],

			selectedAttachments: [],
			fileIdsLoading: [],
			editingLabelsFileId: null,
			editingLabels: [],
			availableLabels: [],
			labelsLoading: false,
			// Register/Schema selection for new objects with multiple options
			selectedRegisterForNewObject: null,
			selectedSchemaForNewObject: null,
			registerSchemaSelectionConfirmed: false,
			filesCurrentPage: 1,
			filesPerPage: 10,
			selectedProperty: null,
			isSaving: false,
			propertyChangeDebounceTimers: {}, // Track debounce timers per property
			pendingNotifications: {}, // Store pending notification info per property
		}
	},

	computed: {
		// Check if we need to show register/schema selection
		/**
		 * @spec exclude computed gate for register/schema selection step
		 */
		showRegisterSchemaSelection() {
			return (
				this.isNewObject
				&& !this.registerSchemaSelectionConfirmed
				&& (this.availableRegisters.length > 1
					|| this.availableSchemas.length > 1)
			)
		},

		// Available registers for selection
		/**
		 * @spec exclude computed display helper exposing store registers
		 */
		availableRegisters() {
			return objectStore.availableRegistersForNewObject || []
		},

		// Available schemas for selection
		/**
		 * @spec exclude computed display helper exposing store schemas
		 */
		availableSchemas() {
			return objectStore.availableSchemasForNewObject || []
		},

		// Can proceed to properties if selections are made
		/**
		 * @spec exclude computed form-validation gate
		 */
		canProceedToProperties() {
			const hasRegister =
				this.availableRegisters.length === 1
				|| this.selectedRegisterForNewObject
			const hasSchema =
				this.availableSchemas.length === 1 || this.selectedSchemaForNewObject
			return hasRegister && hasSchema
		},

		/**
		 * @spec exclude computed display helper building property rows from schema
		 */
		objectProperties() {
			// For new objects, show schema properties with default values
			if (!objectStore?.objectItem) {
				const schemaProperties = this.currentSchema?.properties
				if (!schemaProperties) {
					return []
				}

				const defaultProperties = []

				for (const [key, schemaProperty] of Object.entries(
					schemaProperties,
				)) {
					defaultProperties.push([key, defaultValueFor(schemaProperty)])
				}

				return defaultProperties
			}

			const objectData = objectStore.objectItem
			const schemaProperties = this.currentSchema?.properties || {}

			// Start with properties that exist in the object
			const existingProperties = Object.entries(objectData).filter(
				([key]) => key !== '@self' && key !== 'id',
			)

			// Add schema properties that don't exist in the object yet
			const missingSchemaProperties = []
			for (const [key, schemaProperty] of Object.entries(schemaProperties)) {
				if (!Object.hasOwn(objectData, key)) {
					missingSchemaProperties.push([
						key,
						defaultValueFor(schemaProperty),
					])
				}
			}

			// Combine existing properties and missing schema properties
			return [...existingProperties, ...missingSchemaProperties]
		},

		/**
		 * @spec exclude computed JSON display helper
		 */
		editorContent() {
			return JSON.stringify(objectStore.objectItem, null, 2)
		},

		/**
		 * @spec exclude computed accessor for active register from store
		 */
		currentRegister() {
			return registerStore.registerItem
		},

		/**
		 * The register's languages, which give a translatable field one input each.
		 *
		 * @spec openspec/specs/objects-crud/spec.md#requirement-req-rfce-001-the-record-form-gives-each-declared-field-its-own-editor
		 * @return {Array<string>}
		 */
		registerLanguages() {
			const languages = this.currentRegister?.languages
			return Array.isArray(languages) ? languages : []
		},

		/**
		 * @spec exclude computed accessor merging inherited schema properties for display
		 */
		currentSchema() {
			const schema = schemaStore.schemaItem
			const allOf = schema?.allOf || []
			if (!allOf.length) {
				return schema
			}
			// Merge inherited properties from allOf parent schemas so extended schemas
			// expose the full property set (own + inherited) in the form dialog.
			const inherited = inheritedProperties(
				schema.allOf,
				schemaStore.schemaList,
			)
			return {
				...schema,
				properties: { ...inherited, ...(schema.properties || {}) },
			}
		},

		/**
		 * @spec exclude computed aggregate loading flag for file actions
		 */
		loading() {
			return this.fileIdsLoading.length > 0
		},

		/**
		 * @spec exclude computed pagination slice of files
		 */
		paginatedFiles() {
			const files = objectStore.files?.results || []
			const start = (this.filesCurrentPage - 1) * this.filesPerPage
			const end = start + this.filesPerPage
			return files.slice(start, end)
		},

		/**
		 * @spec exclude computed total page count for files pagination
		 */
		filesTotalPages() {
			const totalFiles = objectStore.files?.results?.length || 0
			return Math.ceil(totalFiles / this.filesPerPage)
		},

		/**
		 * @spec exclude computed select-all state for current files page
		 */
		allFilesSelected() {
			return (
				this.paginatedFiles.length > 0
				&& this.paginatedFiles.every((file) =>
					this.selectedAttachments.includes(file.id),
				)
			)
		},

		/**
		 * @spec exclude computed indeterminate-selection state for files
		 */
		someFilesSelected() {
			return this.selectedAttachments.length > 0 && !this.allFilesSelected
		},

		/**
		 * @spec exclude computed display helper merging schema + object fields
		 */
		formFields() {
			// Schema properties first, then object properties the schema lacks.
			const fields = schemaFields(this.currentSchema)
			addObjectFields(fields, objectStore.objectItem)
			return fields
		},

		/**
		 * @spec exclude computed display helper building metadata rows
		 */
		metadataProperties() {
			// Return array of [key, value, hasAction] for metadata display
			// Use formData instead of objectStore.objectItem to reflect real-time changes
			const obj = this.formData || objectStore?.objectItem
			if (!obj) return []
			const self = obj['@self']

			return [
				['ID', orNotSet(obj.id), true],
				['Register', orNotSet(this.registerTitle), false],
				['Schema', orNotSet(this.schemaTitle), false],
				['Version', orNotSet(self?.version), false],
				['Created', localTimeOrNotSet(self?.created), false],
				['Updated', localTimeOrNotSet(self?.updated), false],
				['Validation', validationText(self), false],
				['Owner', orNotSet(self?.owner), false],
				['Application', orNotSet(self?.application), false],
				['Organisation', orNotSet(self?.organisation), false],
			]
		},

		isNewObject() {
			return !objectStore?.objectItem || !objectStore?.objectItem['@self']?.id
		},

		/**
		 * Build the (register, schema, id) triple used by the entity-relations
		 * tabs (Emails, Events, Contacts, Deck, Relations). Returns null when
		 * any of the three is missing, so the tabs only render once a saved
		 * object is being viewed.
		 *
		 * @return {{register:(string|number), schema:(string|number), id:string}|null}
		 * @spec exclude computed context object for relation tabs
		 */
		relationContext() {
			const self = objectStore?.objectItem?.['@self']
			if (!self) {
				return null
			}

			const register = self.register ?? registerStore.registerItem?.id
			const schema = self.schema ?? schemaStore.schemaItem?.id
			const id = self.id || self.uuid
			if (!register || !schema || !id) {
				return null
			}

			return { register, schema, id }
		},
	},

	watch: {
		objectStore: {
			/**
			 * @param {object} newValue
			 * @spec exclude watcher re-initializing data on store change
			 */
			handler(newValue) {
				if (newValue) {
					this.initializeData()
				}
			},

			deep: true,
		},

		// Watch for schema changes to re-initialize data
		currentSchema: {
			/**
			 * @param {object|null} newSchema
			 * @spec exclude watcher re-resolving schema and re-initializing data
			 */
			async handler(newSchema) {
				// The schema list endpoint returns un-resolved schemas — for schemas
				// using composition (allOf/oneOf/anyOf) `properties` is empty until
				// the detail endpoint merges in the parent's properties. When the
				// active schema looks un-resolved, refetch via the detail endpoint
				// and let the watcher fire again with merged properties. If that
				// fetch fails, the un-resolved schema is used.
				if (
					isUnresolved(newSchema)
					&& (await fetchResolvedSchema(newSchema))
				) {
					return
				}
				if (newSchema && this.isNewObject) {
					// Re-initialize data when schema becomes available for new objects
					this.initializeData()
				}
				// Force Vue to re-evaluate computed properties. Only legal once the
				// render effect exists — see the `isMounted` note in data().
				if (this.isMounted) {
					this.$forceUpdate()
				}
			},

			immediate: true,
		},

		// Watch for register changes to re-initialize data
		currentRegister: {
			/**
			 * @param {object|null} newRegister
			 * @spec exclude watcher re-initializing data on register change
			 */
			handler(newRegister) {
				if (newRegister && this.isNewObject) {
					// Re-initialize data when register becomes available for new objects
					this.initializeData()
				}
			},

			immediate: true,
		},

		jsonData: {
			/**
			 * @param {string} newValue
			 * @spec exclude watcher syncing form from JSON editor
			 */
			handler(newValue) {
				if (!this.isInternalUpdate && this.isValidJson(newValue)) {
					this.updateFormFromJson()
				}
			},
		},

		formData: {
			/**
			 * @param {object} _newValue
			 * @spec exclude watcher syncing JSON editor from form
			 */
			handler(_newValue) {
				if (!this.isInternalUpdate) {
					this.updateJsonFromForm()
				}
			},

			deep: true,
		},
	},

	/**
	 * @spec exclude Vue lifecycle hook initializing modal data
	 */
	async mounted() {
		// Refetch the active schema by id so the store holds the resolved version
		// (with allOf/oneOf/anyOf composition merged in by the backend). The schema
		// list endpoint returns raw schemas with empty properties for extended
		// schemas — the detail endpoint resolves composition.
		if (schemaStore.schemaItem?.id) {
			try {
				await schemaStore.getSchema(schemaStore.schemaItem.id, {
					setItem: true,
				})
			} catch {
				// Fall through to use the un-resolved schema if the detail fetch fails
			}
		}

		// Initialize data when modal opens
		this.initializeData()
		this.loadTitles()

		// The render effect exists from here on, so $forceUpdate() is safe.
		this.isMounted = true
	},

	/**
	 * @spec exclude Vue lifecycle hook re-loading data on update
	 */
	updated() {
		if (!this.isUpdated && navigationStore.modal === 'viewObject') {
			this.isUpdated = true
			this.loadTitles()
			this.initializeData()
		}
	},

	methods: {
		/**
		 * Returns { type, objectId } needed for all file store operations.
		 * Handles both string IDs and embedded register/schema objects.
		 *
		 * @spec openspec/specs/entity-management-modals/spec.md
		 */
		_getFileParams() {
			const rawRegister = objectStore.objectItem['@self']?.register
			const rawSchema = objectStore.objectItem['@self']?.schema
			const registerId =
				typeof rawRegister === 'object' && rawRegister !== null
					? rawRegister.id
					: rawRegister
			const schemaId =
				typeof rawSchema === 'object' && rawSchema !== null
					? rawSchema.id
					: rawSchema
			const objectId =
				objectStore.objectItem['@self']?.id || objectStore.objectItem?.id
			const type = `${registerId}-${schemaId}`
			if (!objectStore.objectTypes?.includes(type)) {
				objectStore.registerObjectType(type, schemaId, registerId)
			}
			return { type, objectId }
		},

		/**
		 * @spec exclude modal step transition handler for register/schema selection
		 */
		confirmRegisterSchemaSelection() {
			// Set the selected register and schema in the store
			const selectedRegister =
				this.selectedRegisterForNewObject || this.availableRegisters[0]
			const selectedSchema =
				this.selectedSchemaForNewObject || this.availableSchemas[0]

			registerStore.setRegisterItem(selectedRegister)
			schemaStore.setSchemaItem(selectedSchema)

			// Confirm selection so we show the properties
			this.registerSchemaSelectionConfirmed = true

			// Initialize data with the selected schema
			this.initializeData()
		},

		/**
		 * @spec exclude computed display helper for modal title
		 */
		getModalTitle() {
			if (!objectStore?.objectItem || !objectStore.objectItem['@self']?.id) {
				return 'Add Object'
			}

			const name =
				objectStore.objectItem['@self']?.name
				|| objectStore.objectItem.name
				|| objectStore.objectItem.id

			const schemaName =
				this.currentSchema?.title
				|| this.currentSchema?.name
				|| 'Unknown Schema'

			return `${name} (${schemaName})`
		},

		/**
		 * @spec exclude form-state loader resolving register/schema titles for display
		 */
		async loadTitles() {
			// Only load titles if we have an existing object with @self data
			if (!objectStore.objectItem || !objectStore.objectItem['@self']) {
				this.registerTitle = 'Not set'
				this.schemaTitle = 'Not set'
				return
			}

			const register = await registerStore.getRegister(
				objectStore.objectItem['@self'].register,
			)
			const schema = await schemaStore.getSchema(
				objectStore.objectItem['@self'].schema,
			)

			this.registerTitle = register?.title || 'Not set'
			this.schemaTitle = schema?.title || 'Not set'
		},

		/**
		 * @spec exclude modal close + form-state reset handler
		 */
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

			// Clear register/schema selection state
			this.selectedRegisterForNewObject = null
			this.selectedSchemaForNewObject = null
			this.registerSchemaSelectionConfirmed = false
			objectStore.availableRegistersForNewObject = null
			objectStore.availableSchemasForNewObject = null

			// Clear debounce timers
			Object.values(this.propertyChangeDebounceTimers).forEach((timer) => {
				if (timer) clearTimeout(timer)
			})
			this.propertyChangeDebounceTimers = {}
			this.pendingNotifications = {}

			// Clear any timeouts
			clearTimeout(this.closeModalTimeout)

			// Close modal and dialog
			navigationStore.setModal(null)
			navigationStore.setDialog(null)
		},

		/**
		 * @param {boolean} isOpen
		 * @spec exclude modal open/close UI handler
		 */
		handleDialogClose(isOpen) {
			if (!isOpen) {
				this.closeModal()
			}
		},

		/**
		 * Open a file in the Nextcloud Files app
		 *
		 * @param {object} file - The file object to open
		 * @spec exclude opens file in Nextcloud Files app (UI navigation)
		 */
		openFile(file) {
			const dirPath = file.path.substring(0, file.path.lastIndexOf('/'))
			const cleanPath = dirPath.replace(/^\/admin\/files\//, '/')
			const filesAppUrl = `/index.php/apps/files/files/${file.id}?dir=${encodeURIComponent(cleanPath)}&openfile=true`
			window.open(filesAppUrl, '_blank')
		},

		/**
		 * Format file size for display
		 *
		 * @param {number} bytes - The file size in bytes
		 * @return {string} The formatted file size
		 * @spec exclude display helper formatting file size
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
		 *
		 * @param {string} fileName - The file name to truncate
		 * @return {string} The truncated file name (22 chars + ... if longer than 25)
		 * @spec exclude display helper truncating file name
		 */
		truncateFileName(fileName) {
			if (!fileName) return ''
			if (fileName.length <= 25) return fileName
			return fileName.substring(0, 22) + '...'
		},

		/**
		 * @param {unknown} value
		 * @spec exclude client-side date validation helper
		 */
		isValidDate(value) {
			return isValidDate(value)
		},

		/**
		 * @param {unknown} val
		 * @spec exclude display helper formatting value as JSON
		 */
		formatValue(val) {
			return JSON.stringify(val, null, 2)
		},

		getTheme,
		/**
		 * @param {string} text
		 * @spec exclude clipboard UI helper
		 */
		async copyToClipboard(text) {
			try {
				await navigator.clipboard.writeText(text)
				this.isCopied = true
				setTimeout(() => {
					this.isCopied = false
				}, 2000)
			} catch (err) {
				console.error('Failed to copy text:', err)
			}
		},

		/**
		 * @spec exclude form-state initializer seeding formData/jsonData
		 */
		initializeData() {
			const initialData = objectStore.objectItem
				? { ...objectStore.objectItem }
				: this.blankObjectData()
			this.formData = initialData
			this.jsonData = JSON.stringify(initialData, null, 2)
			if (objectStore.objectItem) {
				this.fetchObjectFiles(objectStore.objectItem)
			}
		},

		/**
		 * The form data of a new record: blank metadata and, per schema
		 * property, its default or a blank value of its type.
		 *
		 * @return {object}
		 * @spec exclude form-state helper seeding a new record
		 */
		blankObjectData() {
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
			for (const [key, property] of Object.entries(
				this.currentSchema?.properties || {},
			)) {
				initialData[key] =
					property.default !== undefined
						? property.default
						: blankValueFor(property)
			}
			return initialData
		},

		/**
		 * Fetch the files of an existing record under its register-schema type.
		 *
		 * @param {object} item The record.
		 * @spec exclude store plumbing fetching a record's files
		 */
		fetchObjectFiles(item) {
			const registerId = idOf(item['@self']?.register)
			const schemaId = idOf(item['@self']?.schema)
			const objectId = item['@self']?.id || item?.id
			if (!registerId || !schemaId || !objectId) {
				return
			}
			const type = `${registerId}-${schemaId}`
			if (!objectStore.objectTypes?.includes(type)) {
				objectStore.registerObjectType(type, schemaId, registerId)
			}
			objectStore.fetchFiles(type, objectId)
		},

		/**
		 * @spec exclude modal submit handler delegating to objectStore.saveObject
		 */
		async saveObject() {
			if (!this.currentRegister || !this.currentSchema) {
				this.error = 'Register and schema are required'
				return
			}

			this.isSaving = true
			this.error = null

			try {
				this.markSaved(await this.storeObject(this.objectPayload()))
			} catch (e) {
				this.error = e.message || 'Failed to save object'
				this.success = false
			} finally {
				this.isSaving = false
			}
		},

		/**
		 * Show whether the save returned an object. After a save the form is
		 * re-initialised from the saved object and the notice clears after 2
		 * seconds.
		 *
		 * @spec exclude modal submit handler delegating to objectStore.saveObject
		 * @param {object|null} data The saved object
		 * @return {void}
		 */
		markSaved(data) {
			this.success = !!data
			if (this.success) {
				this.initializeData()
				setTimeout(() => {
					this.success = null
				}, 2000)
			}
		},

		/**
		 * The object to save: the parsed JSON on the JSON tab, the form data
		 * otherwise.
		 *
		 * @spec exclude modal submit handler delegating to objectStore.saveObject
		 * @return {object} The object to save
		 * @throws {Error} When the JSON is empty or does not parse
		 */
		objectPayload() {
			if (this.activeTab !== 1) {
				return this.formData
			}
			if (!this.jsonData.trim()) {
				throw new Error('JSON data cannot be empty')
			}
			try {
				return JSON.parse(this.jsonData)
			} catch (e) {
				throw new Error('Invalid JSON format: ' + e.message, { cause: e })
			}
		},

		/**
		 * Save the object under the current register and schema, select the
		 * saved object and refresh the lists that show it.
		 *
		 * @spec exclude modal submit handler delegating to objectStore.saveObject
		 * @param {object} dataToSave The object to save
		 * @return {Promise<object|null>} The saved object
		 */
		async storeObject(dataToSave) {
			const type = `${this.currentRegister.id}-${this.currentSchema.id}`
			objectStore.registerObjectType(
				type,
				this.currentSchema.id,
				this.currentRegister.id,
			)
			const data = await objectStore.saveObject(type, dataToSave)
			if (data) objectStore.setObjectItem(data)
			await objectStore.refreshObjectList({
				register: this.currentRegister.id,
				schema: this.currentSchema.id,
			})
			objectStore.refetchSearchCollection()
			return data
		},

		/**
		 * @spec exclude form/JSON sync helper (JSON editor to form)
		 */
		updateFormFromJson() {
			if (this.isInternalUpdate) return

			try {
				this.isInternalUpdate = true
				const parsed = JSON.parse(this.jsonData)
				this.formData = parsed
			} catch {
				this.error = 'Invalid JSON format'
			} finally {
				this.$nextTick(() => {
					this.isInternalUpdate = false
				})
			}
		},

		/**
		 * @spec exclude form/JSON sync helper (form to JSON editor)
		 */
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

		/**
		 * @param {string} str
		 * @spec exclude client-side JSON validation helper
		 */
		isValidJson(str) {
			if (!str || !str.trim()) {
				return false
			}
			try {
				JSON.parse(str)
				return true
			} catch {
				return false
			}
		},

		/**
		 * @spec exclude JSON formatting UI helper
		 */
		formatJSON() {
			try {
				if (this.jsonData) {
					const parsed = JSON.parse(this.jsonData)
					this.jsonData = JSON.stringify(parsed, null, 2)
				}
			} catch {
				// Keep invalid JSON as-is
			}
		},

		setFieldValue(key, value) {
			this.formData[key] = value
		},

		/**
		 * @param {string} key
		 * @param {number} index
		 * @param {unknown} value
		 * @spec exclude form-state helper updating an array item
		 */
		updateArrayItem(key, index, value) {
			if (!this.formData[key]) {
				this.formData[key] = []
			}
			this.formData[key][index] = value
		},

		/**
		 * @param {unknown} v
		 * @spec exclude display/payload coercion helper for null values
		 */
		toDisplay(v) {
			return v === null ? '' : v
		},

		/**
		 * @param {unknown} v
		 * @spec exclude display/payload coercion helper for empty values
		 */
		toPayload(v) {
			return v === '' ? null : v
		},

		/**
		 * @param {string} key
		 * @spec exclude form-state helper adding an array item
		 */
		addArrayItem(key) {
			if (!this.formData[key] || !Array.isArray(this.formData[key])) {
				this.formData[key] = []
			}
			this.formData[key].push('')
		},

		/**
		 * @param {string} key
		 * @param {number} i
		 * @spec exclude form-state helper removing an array item
		 */
		removeArrayItem(key, i) {
			if (this.formData[key] && Array.isArray(this.formData[key])) {
				this.formData[key].splice(i, 1)
			}
		},

		/**
		 * @param {string} key
		 * @param {string} val
		 * @spec exclude form-state helper updating an object-typed field
		 */
		updateObjectField(key, val) {
			this.objectEditors[key] = val
			try {
				this.formData[key] = JSON.parse(val)
			} catch (e) {
				console.error('Invalid JSON format:', e)
			}
		},

		/**
		 * @param {boolean} checked
		 * @spec exclude file-selection UI toggle (select all)
		 */
		toggleSelectAllFiles(checked) {
			if (checked) {
				// Add all current page files to selection
				this.paginatedFiles.forEach((file) => {
					if (!this.selectedAttachments.includes(file.id)) {
						this.selectedAttachments.push(file.id)
					}
				})
			} else {
				// Remove all current page files from selection
				const currentPageIds = this.paginatedFiles.map((file) => file.id)
				this.selectedAttachments = this.selectedAttachments.filter(
					(id) => !currentPageIds.includes(id),
				)
			}
		},

		/**
		 * @param {string|number} fileId
		 * @param {boolean} checked
		 * @spec exclude file-selection UI toggle (single file)
		 */
		toggleFileSelection(fileId, checked) {
			if (checked) {
				if (!this.selectedAttachments.includes(fileId)) {
					this.selectedAttachments.push(fileId)
				}
			} else {
				this.selectedAttachments = this.selectedAttachments.filter(
					(id) => id !== fileId,
				)
			}
		},

		/**
		 * @param {number} page
		 * @spec exclude files pagination UI handler
		 */
		onFilesPageChanged(page) {
			this.filesCurrentPage = page
		},

		/**
		 * @param {number} pageSize
		 * @spec exclude files page-size UI handler
		 */
		onFilesPageSizeChanged(pageSize) {
			this.filesPerPage = pageSize
			this.filesCurrentPage = 1
		},

		/**
		 * @spec exclude router navigation UI handler to audit trails
		 */
		viewAuditTrails() {
			// Close the current modal and navigate to audit trails
			this.closeModal()
			this.$router.push('/audit-trails')
		},

		// Apply a batch action across the selected files. Prefers the
		// shared-store batchFiles action (one POST to /files/batch); falls
		// back to N sequential single-file calls when the runtime store
		// doesn't expose batchFiles yet (older @conduction/nextcloud-vue).
		/**
		 * @param {string} action
		 * @param {Function} perFileFallback
		 * @spec exclude file batch-action dispatch helper delegating to objectStore.batchFiles
		 */
		async _runBatchAction(action, perFileFallback) {
			if (this.selectedAttachments.length === 0) return
			const { type, objectId } = this._getFileParams()
			const fileIds = [...this.selectedAttachments]

			if (typeof objectStore.batchFiles === 'function') {
				await objectStore.batchFiles(type, objectId, action, fileIds)
				return
			}

			// Legacy fallback: loop the per-file action.
			for (const fileId of fileIds) {
				await perFileFallback(type, objectId, fileId)
			}
		},

		/**
		 * @spec exclude bulk file-delete handler delegating to objectStore
		 */
		async deleteSelectedFiles() {
			if (this.selectedAttachments.length === 0) return

			try {
				this.fileIdsLoading = [...this.selectedAttachments]
				await this._runBatchAction('delete', (type, objectId, fileId) =>
					objectStore.deleteFile(type, objectId, fileId),
				)
				this.selectedAttachments = []
			} catch (error) {
				// eslint-disable-next-line no-console
				console.error('Failed to delete selected files:', error)
			} finally {
				this.fileIdsLoading = []
			}
		},

		/**
		 * @param {object} file
		 * @spec exclude single file-delete handler delegating to objectStore.deleteFile
		 */
		async deleteFile(file) {
			try {
				this.fileIdsLoading.push(file.id)
				const { type, objectId } = this._getFileParams()
				await objectStore.deleteFile(type, objectId, file.id)
			} catch (error) {
				// eslint-disable-next-line no-console
				console.error('Failed to delete file:', error)
			} finally {
				this.fileIdsLoading = this.fileIdsLoading.filter(
					(id) => id !== file.id,
				)
			}
		},

		/**
		 * @param {object} file
		 * @spec exclude form-state handler entering file-label edit mode
		 */
		async editFileLabels(file) {
			this.editingLabelsFileId = file.id
			this.editingLabels = [...(file.labels || [])]
			const tags = await objectStore.fetchTags()
			this.availableLabels = Array.isArray(tags)
				? tags
				: objectStore.getTags || []
		},

		/**
		 * @spec exclude form-state handler cancelling file-label edit
		 */
		cancelFileLabels() {
			this.editingLabelsFileId = null
			this.editingLabels = []
		},

		// Persist label changes via the dedicated /files/{fileId}/labels
		// endpoint with optimistic UI: the in-memory attachment.labels
		// flips to the new value as soon as the user clicks save, and
		// reverts on error so the UI never lies if the request fails.
		// API call shape is implemented in services/fileMetadata.js so it
		// can be unit-tested without mounting this modal.
		/**
		 * @param {object} file
		 * @spec exclude file-label save handler delegating to services/fileMetadata
		 */
		async saveFileLabels(file) {
			const { type, objectId } = this._getFileParams()
			const rawRegister = objectStore.objectItem['@self']?.register
			const rawSchema = objectStore.objectItem['@self']?.schema
			const registerId =
				typeof rawRegister === 'object' && rawRegister !== null
					? rawRegister.id
					: rawRegister
			const schemaId =
				typeof rawSchema === 'object' && rawSchema !== null
					? rawSchema.id
					: rawSchema

			// Snapshot the current value so we can revert on failure.
			const previousLabels = Array.isArray(file.labels) ? [...file.labels] : []
			const nextLabels = [...this.editingLabels]

			// Optimistic update — flip the in-memory file row immediately.
			file.labels = nextLabels
			this.labelsLoading = true

			try {
				await updateFileLabels({
					registerId,
					schemaId,
					objectId,
					fileId: file.id,
					labels: nextLabels,
				})
				// Re-fetch to pick up server-side normalisation (dedup, tag
				// resolution) and any other fields that change as a side
				// effect of the label update.
				await objectStore.fetchFiles(type, objectId)
				this.cancelFileLabels()
			} catch (error) {
				// Revert the optimistic update so the UI matches the server.
				file.labels = previousLabels
				// eslint-disable-next-line no-console
				console.error('Failed to save file labels:', error)
			} finally {
				this.labelsLoading = false
			}
		},

		/**
		 * @param {string} key
		 * @param {unknown} value
		 * @spec exclude computed CSS-class helper for property validation state
		 */
		getPropertyValidationClass(key, value) {
			// Skip @self as it's metadata
			if (key === '@self') {
				return ''
			}

			// Check if property exists in schema
			const schemaProperty = this.currentSchema?.properties?.[key]
			const existsInObject = objectStore.objectItem
				? Object.hasOwn(objectStore.objectItem, key)
				: false

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

		/**
		 * @param {string} key
		 * @param {unknown} value
		 * @param {object} schemaProperty
		 * @spec exclude client-side property-value validation helper
		 */
		isValidPropertyValue(key, value, schemaProperty) {
			const isRequired =
				this.currentSchema?.required?.includes(key)
				|| schemaProperty.required
			return isValidPropertyValue(value, schemaProperty, isRequired)
		},

		/**
		 * @param {string} key
		 * @param {unknown} value
		 * @spec exclude display helper building property error message
		 */
		getPropertyErrorMessage(key, value) {
			const property = this.currentSchema?.properties?.[key]
			if (!property) {
				return `Property '${key}' is not defined in the current schema. This property exists in the object but is not part of the schema definition.`
			}
			const required =
				this.currentSchema?.required?.includes(key) || property.required
			const check = {
				key,
				value,
				property,
				required,
				isValidDate: (v) => this.isValidDate(v),
			}
			for (const problem of PROPERTY_PROBLEMS) {
				const message = problem(check)
				if (message) return message
			}
			return `Property '${key}' has an invalid value`
		},

		getPropertyWarningMessage(key, _value) {
			return `Property '${key}' exists in the object but is not defined in the current schema. This might happen when property names are changed in the schema.`
		},

		getPropertyNewMessage(key) {
			return `Property '${key}' is defined in the schema but doesn't have a value yet. Click to add a value.`
		},

		/**
		 * Convert any value to a string suitable for NcTextField
		 *
		 * @param {*} value - The value to convert
		 * @return {string} The string representation
		 * @spec exclude display helper coercing value to string
		 */
		getStringValue(value) {
			if (value === null || value === undefined) {
				return ''
			}
			return typeof value === 'object' ? jsonOrString(value) : String(value)
		},

		/**
		 * @param {string} key
		 * @param {MouseEvent} event
		 * @spec exclude table-row click UI handler for inline edit
		 */
		handleRowClick(key, event) {
			// Don't select when clicking an input or button, or reselect the selected row.
			if (isInlineControl(event.target) || this.selectedProperty === key) {
				return
			}
			const value =
				this.formData[key] !== undefined
					? this.formData[key]
					: this.objectProperties.find(([k]) => k === key)?.[1]
			if (!this.isPropertyEditable(key, value)) {
				this.warnNotEditable(key, value)
				return
			}
			this.editInline(key)
		},

		/**
		 * Show why a property cannot be edited, when there is a reason.
		 *
		 * @param {string} key The property
		 * @param {*} value Its value
		 * @spec exclude UI plumbing — editability warning toast
		 */
		warnNotEditable(key, value) {
			const warning = this.getEditabilityWarning(key, value)
			if (warning) {
				showWarning(warning)
			}
		},

		/**
		 * Start editing a property inline when its type allows it (same as
		 * EditObject.vue); free-form properties outside the schema always can.
		 *
		 * @param {string} key The property
		 * @spec exclude UI plumbing — inline edit selection
		 */
		editInline(key) {
			const schemaProperty = this.currentSchema?.properties?.[key]
			if (!schemaProperty || INLINE_TYPES.includes(schemaProperty.type)) {
				this.selectProperty(key)
				return
			}
			showWarning(
				`Property '${this.getPropertyDisplayName(key)}' has type '${schemaProperty.type}' which is not supported for inline editing. Use the Data tab for complex types.`,
			)
		},

		/**
		 * @param {string} key
		 * @spec exclude form-state handler selecting/focusing a property for edit
		 */
		selectProperty(key) {
			this.selectedProperty = key

			// Focus the input field after Vue updates the DOM
			this.$nextTick(() => {
				if (
					this.$refs.propertyValueInput
					&& this.$refs.propertyValueInput[0]
				) {
					const input =
						this.$refs.propertyValueInput[0].$el.querySelector('input')
					if (input) {
						input.focus()
						input.select()
					}
				}
			})
		},

		/**
		 * @param {string} key
		 * @param {unknown} newValue
		 * @spec exclude form-state handler updating a property value with type coercion
		 */
		updatePropertyValue(key, newValue) {
			// Get the old value for comparison
			const oldValue =
				this.formData[key] !== undefined
					? this.formData[key]
					: this.objectProperties.find(([k]) => k === key)?.[1]

			// Convert value based on schema property type
			const schemaProperty = this.currentSchema?.properties?.[key]
			const convertedValue = convertInputValue(newValue, schemaProperty)

			// Update the form data using Vue 3 reactivity
			this.formData = { ...this.formData, [key]: convertedValue }

			// Show notification if value actually changed (debounced)
			if (oldValue !== convertedValue) {
				// Store pending notification info
				this.pendingNotifications[key] = {
					oldValue,
					newValue: convertedValue,
				}

				// Clear existing debounce timer for this property
				if (this.propertyChangeDebounceTimers[key]) {
					clearTimeout(this.propertyChangeDebounceTimers[key])
				}

				// Set new debounce timer (500ms delay)
				this.propertyChangeDebounceTimers[key] = setTimeout(() => {
					const notification = this.pendingNotifications[key]
					if (notification) {
						this.showPropertyChangeNotification(
							key,
							notification.oldValue,
							notification.newValue,
						)
						delete this.pendingNotifications[key]
					}
					delete this.propertyChangeDebounceTimers[key]
				}, 500)
			}
		},

		/**
		 * Remove all existing toast notifications
		 *
		 * @spec exclude toast cleanup UI helper
		 */
		clearAllToasts() {
			const toasts = document.querySelectorAll(
				'.property-change-toast, .property-warning-toast',
			)
			toasts.forEach((toast) => {
				if (toast.parentNode) {
					toast.parentNode.removeChild(toast)
				}
			})
		},

		/**
		 * @param {string} key
		 * @param {unknown} oldValue
		 * @param {unknown} newValue
		 * @spec exclude toast notification UI helper for property changes
		 */
		showPropertyChangeNotification(key, oldValue, newValue) {
			// Clear any existing toasts before showing a new one
			this.clearAllToasts()

			// Create a simple notification - you could replace this with a proper toast library
			const notification = document.createElement('div')
			notification.className = 'property-change-toast'
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

		/**
		 * @param {string} key
		 * @spec exclude display helper checking string-typed property
		 */
		isStringProperty(key) {
			const schemaProperty = this.currentSchema?.properties?.[key]
			return schemaProperty?.type === 'string'
		},

		/**
		 * @param {string} key
		 * @param {unknown} value
		 * @spec exclude display helper checking property editability (const/immutable)
		 */
		isPropertyEditable(key, value) {
			const schemaProperty = this.currentSchema?.properties?.[key]

			// If no schema property, allow editing (it's a free-form property)
			if (!schemaProperty) return true

			// Check if property is const
			if (schemaProperty.const !== undefined) {
				return false // Const properties cannot be edited
			}

			// Check if property is immutable and already has a value
			if (
				schemaProperty.immutable
				&& value !== null
				&& value !== undefined
				&& value !== ''
			) {
				return false // Immutable properties with values cannot be edited
			}

			return true
		},

		/**
		 * @param {string} key
		 * @param {unknown} value
		 * @spec exclude display helper building editability warning text
		 */
		getEditabilityWarning(key, value) {
			const schemaProperty = this.currentSchema?.properties?.[key]

			if (schemaProperty?.const !== undefined) {
				return `This property is constant and must always be '${schemaProperty.const}'. Const properties cannot be modified to maintain data integrity.`
			}

			if (
				schemaProperty?.immutable
				&& value !== null
				&& value !== undefined
				&& value !== ''
			) {
				return `This property is immutable and cannot be changed once it has a value. Current value: '${value}'. Immutable properties preserve data consistency.`
			}

			return null
		},

		/**
		 * @param {string} value
		 * @param {string} format
		 * @spec exclude display helper delegating to dateUtils.stringToDate
		 */
		stringToDate(value, format) {
			return stringToDate(value, format)
		},

		/**
		 * @param {string} key
		 * @spec exclude display helper mapping schema type to input type
		 */
		getPropertyInputType(key) {
			return inputTypeFor(this.currentSchema?.properties?.[key])
		},

		/**
		 * @param {string} key
		 * @spec exclude display helper mapping schema type to input component
		 */
		getPropertyInputComponent(key) {
			// One rule for the record form and the list's cells, in
			// propertyEditor.js (REQ-RFCE-001).
			const editor = editorFor(
				this.currentSchema?.properties?.[key],
				this.registerLanguages,
			)
			return (
				{
					switch: 'NcCheckboxRadioSwitch',
					date: 'NcDateTimePickerNative',
					select: 'NcSelect',
					file: 'file',
					translation: 'TranslationFieldEditor',
				}[editor] || 'NcTextField'
			)
		},

		/**
		 * The choices a property declares, for its select.
		 *
		 * @param {string} key The property.
		 * @return {Array<{value: *, label: string}>}
		 * @spec openspec/specs/objects-crud/spec.md#requirement-req-rfce-001-the-record-form-gives-each-declared-field-its-own-editor
		 */
		enumOptionsFor(key) {
			return enumOptions(this.currentSchema?.properties?.[key])
		},

		/**
		 * The option that holds the current value, or null.
		 *
		 * @param {string} key The property.
		 * @param {*} current The current value.
		 * @return {object|null}
		 * @spec exclude display helper: the select's current option
		 */
		enumOptionFor(key, current) {
			return (
				this.enumOptionsFor(key).find((option) => option.value === current)
				|| null
			)
		},

		/**
		 * A translatable value as the `{lang: value}` map the editor takes. A
		 * plain string is the default language's value.
		 *
		 * @param {*} current The current value.
		 * @return {object}
		 * @spec exclude display helper: shape a stored value for the translation editor
		 */
		translationValueFor(current) {
			if (current && typeof current === 'object' && !Array.isArray(current))
				return current
			if (current === null || current === undefined || current === '')
				return {}
			const first = this.registerLanguages[0]
			return first ? { [first]: String(current) } : {}
		},

		/**
		 * Read a chosen file and store it as the data URI the save path accepts.
		 *
		 * @param {string} key The property.
		 * @param {Event} event The change event of the file input.
		 * @return {Promise<void>}
		 * @spec openspec/specs/objects-crud/spec.md#requirement-req-rfce-001-the-record-form-gives-each-declared-field-its-own-editor
		 */
		async onPropertyFilePicked(key, event) {
			const file = event?.target?.files?.[0]
			if (!file) return
			this.updatePropertyValue(key, await readFileAsDataUri(file))
		},

		/**
		 * Get the display name for a property (title if available, otherwise key)
		 *
		 * @param {string} key - The property key
		 * @return {string} The display name
		 * @spec exclude display helper for property display name
		 */
		getPropertyDisplayName(key) {
			const schemaProperty = this.currentSchema?.properties?.[key]
			return schemaProperty?.title || key
		},

		/**
		 * Get the tooltip text for a property
		 *
		 * @param {string} key - The property key
		 * @return {string} The tooltip text
		 * @spec exclude display helper for property tooltip text
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
		 *
		 * @param {string} key - The property key
		 * @return {number|undefined} The minimum value
		 * @spec exclude display helper exposing schema minimum
		 */
		getPropertyMinimum(key) {
			const schemaProperty = this.currentSchema?.properties?.[key]
			return schemaProperty?.minimum
		},

		/**
		 * Get the maximum value for a property
		 *
		 * @param {string} key - The property key
		 * @return {number|undefined} The maximum value
		 * @spec exclude display helper exposing schema maximum
		 */
		getPropertyMaximum(key) {
			const schemaProperty = this.currentSchema?.properties?.[key]
			return schemaProperty?.maximum
		},

		/**
		 * Get the step value for a property
		 *
		 * @param {string} key - The property key
		 * @return {string|undefined} The step value
		 * @spec exclude display helper exposing input step
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

		/**
		 * @param {string} key
		 * @param {unknown} value
		 * @spec exclude display helper resolving value to show for a property
		 */
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
			if (
				objectStore.objectItem
				&& !Object.hasOwn(objectStore.objectItem, key)
				&& schemaProperty
			) {
				return value // This will be the default value we set in objectProperties
			}

			// Otherwise use the original value
			return value
		},
	},
}
</script>

<style scoped>
/* Register/Schema Selection Step */
.selection-step {
	padding: 24px;
	min-height: 300px;
}

.selection-container {
	max-width: 600px;
	margin: 0 auto;
}

.selection-container h3 {
	margin-bottom: 12px;
	font-size: 20px;
	font-weight: 600;
}

.selection-hint {
	margin-bottom: 24px;
	color: var(--color-text-maxcontrast);
	font-size: 14px;
}

.selection-fields {
	display: flex;
	flex-direction: column;
	gap: 20px;
}

.selection-fields .field-group {
	display: flex;
	flex-direction: column;
	gap: 8px;
}

.selection-fields .field-group label {
	font-weight: 600;
	font-size: 14px;
}

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

@media (prefers-reduced-motion: reduce) {
	.viewTableRow {
		transition: none;
	}
}
</style>

<style scoped lang="scss">
@use '../../styles/modals.scss' as *;
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
	outline: none !important;
}

.codeMirrorContainer :deep(.cm-scroller) {
	overflow: auto;
}

.codeMirrorContainer :deep(.cm-content) {
	border-radius: 0 !important;
	border: none !important;
}

.codeMirrorContainer.light > .vue-codemirror {
	border: 1px dotted silver;
}

.codeMirrorContainer.dark > .vue-codemirror {
	border: 1px dotted grey;
}

@include code-mirror-theme;

@media (prefers-reduced-motion: reduce) {
	.viewTableRow {
		transition: none;
	}
}
</style>

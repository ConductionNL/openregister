<script setup>
import {
	navigationStore,
	objectStore,
	registerStore,
	schemaStore,
} from '../../store/store.js'
import { t } from './l10n.js'
</script>

<template>
	<NcDialog :name="t('openregister', 'Merge objects')" size="large" noClose>
		<!-- Register and Schema Information -->
		<div class="detail-grid">
			<div class="detail-item">
				<span class="detail-label">{{
					t('openregister', 'Register:')
				}}</span>
				<span class="detail-value">{{
					registerStore.registerItem?.title
					|| registerStore.registerItem?.id
				}}</span>
			</div>
			<div class="detail-item">
				<span class="detail-label">{{ t('openregister', 'Schema:') }}</span>
				<span class="detail-value">{{
					schemaStore.schemaItem?.title || schemaStore.schemaItem?.id
				}}</span>
			</div>
		</div>

		<!-- Information about merge restrictions (only show if not completed) -->
		<NcNoteCard v-if="step !== 3" type="info">
			{{
				t(
					'openregister',
					'You can merge objects from the same register and schema. Migrate objects from elsewhere first.',
				)
			}}
		</NcNoteCard>

		<!-- Step 1: Select Target Object -->
		<div v-if="step === 1" class="merge-step step-1">
			<h3 class="step-title">
				{{ t('openregister', 'Select the target object') }}
			</h3>
			<p>
				{{
					t('openregister', 'Select the object to merge {source} into.', {
						source: objectLabel(sourceObject),
					})
				}}
			</p>

			<div class="search-container">
				<NcTextField
					v-model="searchTerm"
					:label="t('openregister', 'Search objects')"
					:placeholder="t('openregister', 'Type to search for objects...')"
					@update:modelValue="searchObjects" />
			</div>

			<div v-if="loading" class="loading-container">
				<NcLoadingIcon :size="32" />
				<p>{{ t('openregister', 'Loading objects…') }}</p>
			</div>

			<div v-else-if="availableObjects.length" class="object-list">
				<div
					v-for="obj in availableObjects"
					:key="obj['@self'].id"
					class="object-item table-row-selectable"
					:class="{
						'table-row-selected':
							selectedTargetObject?.['@self']?.id === obj['@self'].id,
					}"
					role="button"
					tabindex="0"
					:aria-pressed="
						selectedTargetObject?.['@self']?.id === obj['@self'].id
					"
					@click="selectTargetObject(obj)"
					@keydown.enter="selectTargetObject(obj)"
					@keydown.space.prevent="selectTargetObject(obj)">
					<div class="object-info">
						<strong>{{ objectLabel(obj) }}</strong>
						<p class="object-id">
							{{
								t('openregister', 'ID: {id}', {
									id: obj['@self'].id,
								})
							}}
						</p>
					</div>
				</div>
			</div>

			<NcEmptyContent
				v-else-if="!loading"
				:name="t('openregister', 'No objects found')">
				<template #description>
					{{
						searchTerm
							? t('openregister', 'No objects match your search')
							: t('openregister', 'No objects to merge with')
					}}
				</template>
			</NcEmptyContent>
		</div>

		<!-- Step 2: Merge Configuration -->
		<div v-if="step === 2" class="merge-step">
			<h3>{{ t('openregister', 'Configure the merge') }}</h3>
			<p>
				{{
					t('openregister', 'You merge {source} into {target}.', {
						source: objectLabel(sourceObject),
						target: objectLabel(selectedTargetObject),
					})
				}}
			</p>

			<!-- Property Comparison Table -->
			<div class="merge-table-container">
				<table class="merge-table">
					<thead>
						<tr>
							<th scope="col">{{ t('openregister', 'Property') }}</th>
							<th scope="col">{{ t('openregister', 'Source') }}</th>
							<th scope="col">{{ t('openregister', 'Target') }}</th>
							<th scope="col">
								{{ t('openregister', 'Result value') }}
							</th>
						</tr>
					</thead>
					<tbody>
						<tr v-for="property in mergeableProperties" :key="property">
							<td class="property-name">
								{{ property }}
							</td>
							<td
								class="source-value"
								:title="displayValue(sourceObject[property], 1000)">
								{{ displayValue(sourceObject[property], 40) }}
							</td>
							<td
								class="target-value"
								:title="
									displayValue(
										selectedTargetObject[property],
										1000,
									)
								">
								{{
									displayValue(selectedTargetObject[property], 40)
								}}
							</td>
							<td class="merge-target">
								<template v-if="property === 'id'">
									<span class="fixed-value">{{
										t('openregister', '{id} (target ID)', {
											id: selectedTargetObject[property],
										})
									}}</span>
								</template>
								<template v-else>
									<NcSelect
										v-model="propertySelections[property]"
										:inputLabel="
											t(
												'openregister',
												'Value for {property}',
												{ property },
											)
										"
										:options="getMergeOptions(property)"
										label="label"
										trackBy="value"
										:placeholder="
											t(
												'openregister',
												'Choose a value for {property}',
												{ property },
											)
										"
										@update:modelValue="
											onPropertySelectionChange(
												property,
												$event,
											)
										" />
									<NcTextField
										v-if="mergedData[property] === 'custom'"
										v-model="customValues[property]"
										:aria-label="
											t(
												'openregister',
												'Enter a custom value for {property}',
												{ property },
											)
										"
										:placeholder="
											t(
												'openregister',
												'Enter a custom value for {property}',
												{ property },
											)
										"
										class="custom-input" />
								</template>
							</td>
						</tr>
					</tbody>
				</table>
			</div>

			<!-- File Handling Options -->
			<div class="options-section">
				<h4>
					{{
						t('openregister', 'Files on the source object ({count})', {
							count: sourceFiles.length,
						})
					}}
				</h4>

				<div class="radio-options">
					<NcCheckboxRadioSwitch
						v-model="fileAction"
						value="transfer"
						name="fileAction"
						type="radio">
						{{
							t(
								'openregister',
								'Move to the folder of the target object',
							)
						}}
					</NcCheckboxRadioSwitch>
					<NcCheckboxRadioSwitch
						v-model="fileAction"
						value="delete"
						name="fileAction"
						type="radio">
						{{ t('openregister', 'Delete files') }}
					</NcCheckboxRadioSwitch>
				</div>

				<div class="table-toggle">
					<NcButton variant="tertiary" @click="toggleFileList">
						{{
							showFileList
								? t('openregister', 'Hide files')
								: t('openregister', 'View files')
						}}
						<template #icon>
							<ChevronUp v-if="showFileList" :size="20" />
							<ChevronDown v-else :size="20" />
						</template>
					</NcButton>
				</div>

				<div v-if="showFileList && sourceFiles.length" class="file-list">
					<table class="file-table">
						<thead>
							<tr>
								<th scope="col">
									{{ t('openregister', 'Filename') }}
								</th>
								<th scope="col">{{ t('openregister', 'Size') }}</th>
								<th scope="col">{{ t('openregister', 'Type') }}</th>
							</tr>
						</thead>
						<tbody>
							<tr
								v-for="file in sourceFiles"
								:key="file.name || file.filename">
								<td :title="file.name || file.filename">
									{{
										truncateText(file.name || file.filename, 40)
									}}
								</td>
								<td>{{ formatFileSize(file.size) }}</td>
								<td>
									{{ getFileType(file.name || file.filename) }}
								</td>
							</tr>
						</tbody>
					</table>
				</div>

				<div
					v-else-if="showFileList && !sourceFiles.length"
					class="no-files">
					<p>{{ t('openregister', 'The source object has no files') }}</p>
				</div>
			</div>

			<!-- Outgoing Relation Handling Options -->
			<div class="options-section">
				<h4>
					{{
						t('openregister', 'Outgoing relations ({count})', {
							count: sourceRelations.length,
						})
					}}
				</h4>

				<div class="radio-options">
					<NcCheckboxRadioSwitch
						v-model="relationAction"
						value="transfer"
						name="relationAction"
						type="radio">
						{{ t('openregister', 'Move to the target object') }}
					</NcCheckboxRadioSwitch>
					<NcCheckboxRadioSwitch
						v-model="relationAction"
						value="drop"
						name="relationAction"
						type="radio">
						{{ t('openregister', 'Drop relations') }}
					</NcCheckboxRadioSwitch>
				</div>

				<div class="table-toggle">
					<NcButton variant="tertiary" @click="toggleRelationList">
						{{
							showRelationList
								? t('openregister', 'Hide relations')
								: t('openregister', 'View relations')
						}}
						<template #icon>
							<ChevronUp v-if="showRelationList" :size="20" />
							<ChevronDown v-else :size="20" />
						</template>
					</NcButton>
				</div>

				<div
					v-if="showRelationList && sourceRelations.length"
					class="relation-list">
					<table class="relation-table">
						<thead>
							<tr>
								<th scope="col">
									{{ t('openregister', 'Related object') }}
								</th>
								<th scope="col">
									{{ t('openregister', 'Relation type') }}
								</th>
							</tr>
						</thead>
						<tbody>
							<tr
								v-for="relation in sourceRelations"
								:key="relation.id">
								<td
									:title="
										relation.title
										|| relation.name
										|| relation.id
									">
									{{
										truncateText(
											relation.title
												|| relation.name
												|| relation.id,
											40,
										)
									}}
								</td>
								<td>
									{{
										relation.relationType
										|| t('openregister', 'Related')
									}}
								</td>
							</tr>
						</tbody>
					</table>
				</div>

				<div
					v-else-if="showRelationList && !sourceRelations.length"
					class="no-relations">
					<p>
						{{
							t(
								'openregister',
								'The source object has no outgoing relations',
							)
						}}
					</p>
				</div>
			</div>

			<!-- Incoming References Handling Options -->
			<div class="options-section">
				<h4>
					{{
						t('openregister', 'Incoming references ({count})', {
							count: sourceIncomingReferences.length,
						})
					}}
				</h4>
				<p class="options-description">
					{{
						t(
							'openregister',
							'Objects in other schemas refer to the source object. You can point them to the target object.',
						)
					}}
				</p>

				<div class="radio-options">
					<NcCheckboxRadioSwitch
						v-model="referenceAction"
						value="transfer"
						name="referenceAction"
						type="radio">
						{{
							t(
								'openregister',
								'Point references to the target object',
							)
						}}
					</NcCheckboxRadioSwitch>
					<NcCheckboxRadioSwitch
						v-model="referenceAction"
						value="keep"
						name="referenceAction"
						type="radio">
						{{ t('openregister', 'Keep references as they are') }}
					</NcCheckboxRadioSwitch>
				</div>

				<div class="table-toggle">
					<NcButton variant="tertiary" @click="toggleReferenceList">
						{{
							showReferenceList
								? t('openregister', 'Hide references')
								: t('openregister', 'View references')
						}}
						<template #icon>
							<ChevronUp v-if="showReferenceList" :size="20" />
							<ChevronDown v-else :size="20" />
						</template>
					</NcButton>
				</div>

				<div
					v-if="showReferenceList && sourceIncomingReferences.length"
					class="relation-list">
					<table class="relation-table">
						<thead>
							<tr>
								<th scope="col">
									{{ t('openregister', 'Referencing object') }}
								</th>
								<th scope="col">{{ t('openregister', 'Type') }}</th>
								<th scope="col">
									{{ t('openregister', 'Register / schema') }}
								</th>
							</tr>
						</thead>
						<tbody>
							<tr
								v-for="ref in sourceIncomingReferences"
								:key="ref.id">
								<td :title="ref.title || ref.id">
									{{ truncateText(ref.title || ref.id, 40) }}
								</td>
								<td>
									{{
										ref.relationType
										|| t('openregister', 'Related')
									}}
								</td>
								<td
									:title="
										(ref.register || 'N/A')
										+ ' / '
										+ (ref.schema || 'N/A')
									">
									{{
										truncateText(
											(ref.register || 'N/A')
												+ ' / '
												+ (ref.schema || 'N/A'),
											30,
										)
									}}
								</td>
							</tr>
						</tbody>
					</table>
				</div>

				<div
					v-else-if="showReferenceList && !sourceIncomingReferences.length"
					class="no-relations">
					<p>
						{{
							t(
								'openregister',
								'No objects refer to the source object',
							)
						}}
					</p>
				</div>
			</div>
		</div>

		<!-- Step 3: Merge Report -->
		<div v-if="step === 3" class="merge-step">
			<h3 class="report-title">{{ t('openregister', 'Merge report') }}</h3>

			<NcNoteCard v-if="mergeResult?.success" type="success">
				<p>{{ t('openregister', 'Objects merged') }}</p>
			</NcNoteCard>
			<NcNoteCard v-if="mergeResult && !mergeResult.success" type="error">
				<p>
					{{ t('openregister', 'Merge failed. Check the details below.') }}
				</p>
			</NcNoteCard>

			<div v-if="mergeResult" class="merge-report">
				<!-- Object Information -->
				<div class="report-section">
					<h4>{{ t('openregister', 'Merge summary') }}</h4>
					<div class="object-info">
						<div class="object-detail">
							<strong>{{
								t('openregister', 'Target object (result):')
							}}</strong>
							<div class="object-meta">
								<span class="object-id"
									>ID:
									{{
										selectedTargetObject?.['@self']?.id
										|| selectedTargetObject?.id
									}}</span
								>
								<span class="object-title">{{
									selectedTargetObject?.['@self']?.name
									|| selectedTargetObject?.name
									|| selectedTargetObject?.['@self']?.title
									|| selectedTargetObject?.title
									|| t('openregister', 'Untitled')
								}}</span>
							</div>
						</div>
						<div class="object-detail">
							<strong>{{
								t('openregister', 'Source object:')
							}}</strong>
							<div class="object-meta">
								<span class="object-id"
									>ID:
									{{
										sourceObject?.['@self']?.id
										|| sourceObject?.id
									}}</span
								>
								<span class="object-title">{{
									sourceObject?.['@self']?.name
									|| sourceObject?.name
									|| sourceObject?.['@self']?.title
									|| sourceObject?.title
									|| t('openregister', 'Untitled')
								}}</span>
								<span class="object-status deleted"
									>Status: Deleted</span
								>
							</div>
						</div>
					</div>
				</div>
				<!-- Statistics -->
				<div class="report-section">
					<h4>{{ t('openregister', 'Statistics') }}</h4>
					<ul>
						<li>
							{{
								t('openregister', 'Properties changed: {count}', {
									count:
										mergeResult.statistics?.propertiesChanged
										|| 0,
								})
							}}
						</li>
						<li>
							{{
								t('openregister', 'Files transferred: {count}', {
									count:
										mergeResult.statistics?.filesTransferred
										|| 0,
								})
							}}
						</li>
						<li>
							{{
								t('openregister', 'Files deleted: {count}', {
									count: mergeResult.statistics?.filesDeleted || 0,
								})
							}}
						</li>
						<li>
							{{
								t('openregister', 'Relations transferred: {count}', {
									count:
										mergeResult.statistics?.relationsTransferred
										|| 0,
								})
							}}
						</li>
						<li>
							{{
								t('openregister', 'Relations dropped: {count}', {
									count:
										mergeResult.statistics?.relationsDropped
										|| 0,
								})
							}}
						</li>
						<li>
							{{
								t('openregister', 'References updated: {count}', {
									count:
										mergeResult.statistics?.referencesUpdated
										|| 0,
								})
							}}
						</li>
					</ul>
				</div>

				<!-- Changed Properties -->
				<div
					v-if="mergeResult.actions?.properties?.length"
					class="report-section">
					<h4>{{ t('openregister', 'Changed properties') }}</h4>
					<table class="report-table">
						<thead>
							<tr>
								<th scope="col">
									{{ t('openregister', 'Property') }}
								</th>
								<th scope="col">
									{{ t('openregister', 'Old value') }}
								</th>
								<th scope="col">
									{{ t('openregister', 'New value') }}
								</th>
							</tr>
						</thead>
						<tbody>
							<tr
								v-for="change in mergeResult.actions.properties"
								:key="change.property">
								<td>{{ change.property }}</td>
								<td>{{ displayValue(change.oldValue) }}</td>
								<td>{{ displayValue(change.newValue) }}</td>
							</tr>
						</tbody>
					</table>
				</div>

				<!-- File Actions -->
				<div
					v-if="mergeResult.actions?.files?.length"
					class="report-section">
					<h4>{{ t('openregister', 'File actions') }}</h4>
					<ul>
						<li
							v-for="fileActionItem in mergeResult.actions.files"
							:key="fileActionItem.name">
							{{ fileActionItem.name }}: {{ fileActionItem.action }}
							<span v-if="!fileActionItem.success" class="error-text">
								({{
									t('openregister', 'Failed: {error}', {
										error: fileActionItem.error,
									})
								}})</span
							>
						</li>
					</ul>
				</div>

				<TransferMessages :result="mergeResult" />
			</div>
		</div>

		<template #actions>
			<NcButton @click="closeModal">
				<template #icon>
					<Cancel :size="20" />
				</template>
				{{
					step === 3
						? t('openregister', 'Close')
						: t('openregister', 'Cancel')
				}}
			</NcButton>

			<NcButton
				v-if="step === 3 && mergeResult?.success"
				variant="secondary"
				@click="viewMergedObject">
				<template #icon>
					<Eye :size="20" />
				</template>
				{{ t('openregister', 'View object') }}
			</NcButton>

			<NcButton
				v-if="step === 1"
				:disabled="!selectedTargetObject"
				variant="primary"
				@click="nextStep">
				<template #icon>
					<ArrowRight :size="20" />
				</template>
				{{ t('openregister', 'Next') }}
			</NcButton>

			<NcButton v-if="step === 2" variant="secondary" @click="previousStep">
				<template #icon>
					<ArrowLeft :size="20" />
				</template>
				{{ t('openregister', 'Back') }}
			</NcButton>

			<NcButton
				v-if="step === 2"
				:disabled="loading || !canMerge"
				variant="primary"
				@click="performMerge">
				<template #icon>
					<NcLoadingIcon v-if="loading" :size="20" />
					<Merge v-else :size="20" />
				</template>
				{{ t('openregister', 'Merge objects') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import {
	NcButton,
	NcCheckboxRadioSwitch,
	NcDialog,
	NcEmptyContent,
	NcLoadingIcon,
	NcNoteCard,
	NcSelect,
	NcTextField,
} from '@nextcloud/vue'
import ArrowLeft from 'vue-material-design-icons/ArrowLeft.vue'
import ArrowRight from 'vue-material-design-icons/ArrowRight.vue'
// Icons
import Cancel from 'vue-material-design-icons/Cancel.vue'
import ChevronDown from 'vue-material-design-icons/ChevronDown.vue'
import ChevronUp from 'vue-material-design-icons/ChevronUp.vue'
import Eye from 'vue-material-design-icons/Eye.vue'
import Merge from 'vue-material-design-icons/Merge.vue'
import TransferMessages from './TransferMessages.vue'
import { refreshObjectLists } from './selectedObjectsDialog.js'
import { failedTransfer } from './transferResult.js'

/**
 * The outgoing relations of an object, from its `@self` relations or its
 * legacy _relations, as rows for the merge preview.
 *
 * @param {object} object The source object
 * @return {Array<object>} The relation rows
 */
function outgoingRelations(object) {
	const relations = object?.['@self']?.relations || object?._relations || []
	return (Array.isArray(relations) ? relations : []).map((rel) => ({
		id: rel,
		title: rel,
		relationType: 'Outgoing',
	}))
}

/**
 * The display title of a referring object.
 *
 * @param {object} obj The referring object
 * @return {string|undefined} Its name, title or id
 */
function referenceTitle(obj) {
	return (
		obj?.['@self']?.name
		|| obj?.['@self']?.title
		|| obj?.['@self']?.id
		|| obj?.id
	)
}

/**
 * A referring object as a row for the merge preview.
 *
 * @param {object} obj The referring object
 * @return {object} The row
 */
function incomingReference(obj) {
	return {
		id: obj?.['@self']?.id || obj?.id,
		title: referenceTitle(obj),
		relationType: obj?.['@self']?.schema
			? `Schema ${obj['@self'].schema}`
			: 'Related',
		register: obj?.['@self']?.register || 'N/A',
		schema: obj?.['@self']?.schema || 'N/A',
	}
}

/**
 * The actual value of a merge choice: the `value` of a label/value option,
 * anything else as it is.
 *
 * @param {*} value The chosen value
 * @return {*} The value to save
 */
function optionValue(value) {
	if (value && typeof value === 'object' && value.value !== undefined) {
		return value.value
	}
	return value
}

export default {
	name: 'MergeObject',
	components: {
		NcButton,
		NcDialog,
		NcTextField,
		NcCheckboxRadioSwitch,
		NcEmptyContent,
		NcLoadingIcon,
		NcNoteCard,
		NcSelect,
		Cancel,
		ArrowRight,
		ArrowLeft,
		Merge,
		Eye,
		ChevronDown,
		ChevronUp,
		TransferMessages,
	},

	data() {
		return {
			step: 1, // 1: select target, 2: configure merge, 3: report
			loading: false,
			searchTerm: '',
			availableObjects: [],
			selectedTargetObject: null,
			mergedData: {},
			customValues: {},
			propertySelections: {}, // Intermediate values for NcSelect v-model
			fileAction: 'transfer',
			relationAction: 'transfer',
			referenceAction: 'transfer',
			mergeResult: null,
			showFileList: true,
			showRelationList: true,
			showReferenceList: true,
			sourceFiles: [],
			sourceRelations: [],
			sourceIncomingReferences: [],
		}
	},

	computed: {
		/**
		 * @spec exclude Computed passthrough exposing objectStore.objectItem as the merge source; UI state helper.
		 */
		sourceObject() {
			return objectStore.objectItem
		},

		/**
		 * @spec exclude Computed union of source+target property keys for the merge UI; UI presentation helper.
		 */
		mergeableProperties() {
			if (!this.sourceObject || !this.selectedTargetObject) {
				return []
			}

			const sourceProps = Object.keys(this.sourceObject).filter(
				(key) => !key.startsWith('@') && !key.startsWith('_'),
			)
			const targetProps = Object.keys(this.selectedTargetObject).filter(
				(key) => !key.startsWith('@') && !key.startsWith('_'),
			)

			return [...new Set([...sourceProps, ...targetProps])]
		},

		/**
		 * @spec exclude Computed enablement guard for the merge button; UI validation helper.
		 */
		canMerge() {
			return Object.keys(this.mergedData).length > 0
		},
	},

	mounted() {
		this.initializeMerge()
	},

	methods: {
		/**
		 * @spec exclude Wizard bootstrap loading source data and candidate targets on open; modal init plumbing.
		 */
		initializeMerge() {
			if (!this.sourceObject) {
				this.closeModal()
				return
			}
			this.loadSourceData()
			this.searchObjects()
		},

		/**
		 * @spec exclude Fetches candidate merge-target objects (excluding the source); UI search plumbing.
		 */
		async searchObjects() {
			if (!registerStore.registerItem || !schemaStore.schemaItem) {
				return
			}

			this.loading = true
			try {
				this.availableObjects = await this.fetchMergeCandidates()
			} catch (error) {
				console.error('Error searching objects:', error)
				this.availableObjects = []
			} finally {
				this.loading = false
			}
		},

		/**
		 * The objects matching the search in the current register and schema,
		 * without the source object.
		 *
		 * @return {Promise<Array<object>>} The candidates
		 * @spec exclude Fetches candidate merge-target objects (excluding the source); UI search plumbing.
		 */
		async fetchMergeCandidates() {
			const response = await fetch(
				`/index.php/apps/openregister/api/objects/${registerStore.registerItem.id}/${schemaStore.schemaItem.id}?_search=${this.searchTerm}`,
			)
			const data = await response.json()

			// Filter out the source object
			return data.results.filter(
				(obj) => obj['@self'].id !== this.sourceObject['@self'].id,
			)
		},

		/**
		 * @param {object} obj
		 * @spec exclude Records the chosen merge target in local state; UI selection plumbing.
		 */
		selectTargetObject(obj) {
			this.selectedTargetObject = obj
		},

		/**
		 * @spec exclude Wizard forward-navigation guard advancing to the merge step; UI step plumbing.
		 */
		nextStep() {
			if (this.step === 1 && this.selectedTargetObject) {
				this.step = 2
				this.initializeMergeData()
			}
		},

		/**
		 * @spec exclude Wizard back-navigation to the target-selection step; UI step plumbing.
		 */
		previousStep() {
			if (this.step === 2) {
				this.step = 1
			}
		},

		/**
		 * @spec exclude Seeds per-property merge defaults and dropdown selections; UI form-init plumbing.
		 */
		initializeMergeData() {
			// Initialize merge data with default values
			this.mergedData = {}
			this.customValues = {}
			this.propertySelections = {}

			this.mergeableProperties.forEach((property) => {
				if (property === 'id') {
					// ID always uses target value
					this.mergedData[property] = this.selectedTargetObject[property]
				} else {
					this.seedMergeChoice(property)
				}
			})
		},

		/**
		 * Choose a property's merged value and its dropdown selection: the
		 * target value if it exists, else the source value if that does, else
		 * a custom value.
		 *
		 * @param {string} property The property
		 * @spec exclude Seeds per-property merge defaults and dropdown selections; UI form-init plumbing.
		 */
		seedMergeChoice(property) {
			const targetValue = this.selectedTargetObject[property]
			const sourceValue = this.sourceObject[property]

			let selectedValue
			// Always store the actual value, never the option object
			if (isFilled(targetValue)) {
				selectedValue = targetValue
			} else if (isFilled(sourceValue)) {
				selectedValue = sourceValue
			} else {
				selectedValue = 'custom'
				this.customValues[property] = ''
			}

			this.mergedData[property] = selectedValue
			this.selectDefaultOption(property, selectedValue)
		},

		/**
		 * Set up the selection object for a property's dropdown.
		 *
		 * @param {string} property The property
		 * @param {unknown} selectedValue The value chosen for it
		 * @spec exclude Seeds per-property merge defaults and dropdown selections; UI form-init plumbing.
		 */
		selectDefaultOption(property, selectedValue) {
			const options = this.getMergeOptions(property)
			this.propertySelections[property] =
				options.find((opt) => opt.value === selectedValue) || null
		},

		/**
		 * @param {string} property
		 * @spec exclude Builds the source/target/custom option list for a property dropdown; UI presentation helper.
		 */
		getMergeOptions(property) {
			const options = []
			const sourceValue = this.sourceObject[property]
			const targetValue = this.selectedTargetObject[property]

			if (isFilled(sourceValue)) {
				options.push({
					label: t('openregister', 'From source: {value}', {
						value: this.displayValue(sourceValue),
					}),
					value: sourceValue,
				})
			}

			if (isFilled(targetValue)) {
				options.push({
					label: t('openregister', 'From target: {value}', {
						value: this.displayValue(targetValue),
					}),
					value: targetValue,
				})
			}

			// Always add the custom option
			options.push(customOption())

			return options
		},

		/**
		 * @param {string} property
		 * @param {object|null} selectedOption
		 * @spec exclude Updates merged-data state when a per-property dropdown changes; UI reactivity plumbing.
		 */
		onPropertySelectionChange(property, selectedOption) {
			if (selectedOption && selectedOption.value !== undefined) {
				// Always store the actual value, never the option object
				this.mergedData[property] = selectedOption.value
				this.propertySelections[property] = selectedOption

				// Clear custom value if switching away from custom
				if (selectedOption.value !== 'custom') {
					this.customValues[property] = ''
				}
			}
		},

		/**
		 * @param {unknown} value
		 * @param {number} maxLength
		 * @spec exclude Stringifying/truncating value formatter for property display; UI presentation helper.
		 */
		displayValue(value, maxLength = 100) {
			if (value === null || value === undefined) {
				return 'N/A'
			}

			let displayText
			if (typeof value === 'object') {
				displayText = JSON.stringify(value, null, 2)
			} else {
				displayText = String(value)
			}

			// Truncate if too long
			if (displayText.length > maxLength) {
				return displayText.substring(0, maxLength) + '...'
			}

			return displayText
		},

		/**
		 * @param {object} object
		 * @spec exclude Display name of an object; UI presentation helper.
		 * @return {string|undefined} Its name, title or id
		 */
		objectLabel(object) {
			const self = object?.['@self']
			return (
				self?.name
				|| object?.name
				|| object?.title
				|| self?.title
				|| self?.id
			)
		},

		/**
		 * @param {string} text
		 * @param {number} maxLength
		 * @spec exclude Simple string-truncation helper for display; UI presentation helper.
		 */
		truncateText(text, maxLength) {
			if (!text) return ''
			if (text.length <= maxLength) return text
			return text.substring(0, maxLength) + '...'
		},

		/**
		 * @spec exclude Merge-confirm handler delegating to objectStore.mergeObjects; entity merge lives in the store, this is modal orchestration plumbing.
		 */
		async performMerge() {
			if (!this.canMerge) {
				return
			}

			this.loading = true
			try {
				this.mergeResult = await this.requestMerge()
				this.step = 3
				refreshObjectLists()
			} catch (error) {
				this.showMergeFailure(error)
			} finally {
				this.loading = false
			}
		},

		/**
		 * Ask the store to merge the source into the target.
		 *
		 * @return {Promise<object>} The merge result
		 * @throws {Error} With the store's lifecycle error when the merge is refused
		 */
		async requestMerge() {
			const result = await objectStore.mergeObjects(
				objectStore.currentType,
				this.sourceObject['@self'].id,
				this.mergeRequest(),
			)
			if (!result) {
				throw new Error(objectStore.lifecycleError?.message)
			}
			return result
		},

		/**
		 * Log a failed merge and show it on the report step.
		 *
		 * @param {Error} error What went wrong
		 * @return {void}
		 */
		showMergeFailure(error) {
			console.error('Error performing merge:', error)
			this.mergeResult = failedTransfer(
				error,
				t('openregister', 'An error occurred during merge'),
			)
			this.step = 3
		},

		/**
		 * The merge the store is asked for: the target, the resolved data and
		 * what happens to files, relations and references.
		 *
		 * @return {object} The merge request
		 */
		mergeRequest() {
			return {
				target: this.selectedTargetObject['@self'].id,
				object: this.finalMergedData(),
				fileAction: this.fileAction,
				relationAction: this.relationAction,
				referenceAction: this.referenceAction,
			}
		},

		/**
		 * The merged data with custom values resolved and no id included.
		 *
		 * @return {object} The data to save on the target
		 */
		finalMergedData() {
			const finalMergedData = {}
			Object.keys(this.mergedData).forEach((property) => {
				// Skip any ID-related properties
				if (property === 'id' || property === '@self') {
					return
				}
				finalMergedData[property] =
					this.mergedData[property] === 'custom'
						? this.customValues[property] || ''
						: optionValue(this.mergedData[property])
			})
			return finalMergedData
		},

		/**
		 * @spec exclude Navigates to the merged object's view modal; UI navigation plumbing.
		 */
		viewMergedObject() {
			// Navigate to the merged object in view mode
			if (this.selectedTargetObject) {
				objectStore.setObjectItem(this.selectedTargetObject)
				navigationStore.setModal('viewObject')
			}
		},

		/**
		 * @spec exclude Toggles the files-list disclosure; UI presentation plumbing.
		 */
		toggleFileList() {
			this.showFileList = !this.showFileList
		},

		/**
		 * @spec exclude Toggles the relations-list disclosure; UI presentation plumbing.
		 */
		toggleRelationList() {
			this.showRelationList = !this.showRelationList
		},

		/**
		 * @spec exclude Toggles the references-list disclosure; UI presentation plumbing.
		 */
		toggleReferenceList() {
			this.showReferenceList = !this.showReferenceList
		},

		/**
		 * @param {number} bytes
		 * @spec exclude Human-readable byte-size formatter for file display; UI presentation helper.
		 */
		formatFileSize(bytes) {
			if (!bytes) return 'N/A'
			const sizes = ['Bytes', 'KB', 'MB', 'GB']
			const i = Math.floor(Math.log(bytes) / Math.log(1024))
			return (
				Math.round((bytes / Math.pow(1024, i)) * 100) / 100 + ' ' + sizes[i]
			)
		},

		/**
		 * @param {string} filename
		 * @spec exclude Maps a filename extension to a friendly type label; UI presentation helper.
		 */
		getFileType(filename) {
			if (!filename) return 'Unknown'
			const ext = filename.split('.').pop()?.toLowerCase()
			return fileTypeNames()[ext] || ext?.toUpperCase() || 'Unknown'
		},

		/**
		 * @spec exclude Loads the source object's files/relations/references for the merge preview; UI form-loading plumbing.
		 */
		async loadSourceData() {
			if (!this.sourceObject) return

			try {
				this.sourceFiles = Array.isArray(this.sourceObject.attachments)
					? this.sourceObject.attachments
					: []
				this.sourceRelations = outgoingRelations(this.sourceObject)
				await this.loadIncomingReferences()
			} catch (error) {
				console.error('Error loading source data:', error)
				this.sourceFiles = []
				this.sourceRelations = []
				this.sourceIncomingReferences = []
			}
		},

		/**
		 * Load the objects that refer to the source object, through its /used
		 * endpoint. Skipped when the register, schema or source id is unknown.
		 *
		 * @spec exclude Loads the source object's files/relations/references for the merge preview; UI form-loading plumbing.
		 * @return {Promise<void>}
		 */
		async loadIncomingReferences() {
			const url = this.usedUrl()
			if (!url) {
				return
			}
			try {
				this.sourceIncomingReferences = await fetchIncomingReferences(url)
			} catch (error) {
				console.error('Error loading incoming references:', error)
				this.sourceIncomingReferences = []
			}
		},

		/**
		 * The /used endpoint of the source object in the current register and
		 * schema.
		 *
		 * @spec exclude Loads the source object's files/relations/references for the merge preview; UI form-loading plumbing.
		 * @return {string} The URL, or an empty string when a part is unknown
		 */
		usedUrl() {
			const register = registerStore.registerItem?.id
			const schema = schemaStore.schemaItem?.id
			const objectId = this.sourceObject?.['@self']?.id
			if (register && schema && objectId) {
				return `/index.php/apps/openregister/api/objects/${register}/${schema}/${objectId}/used`
			}
			return ''
		},

		/**
		 * @spec exclude Modal close handler resetting navigationStore.modal; UI plumbing.
		 */
		closeModal() {
			navigationStore.setModal(false)
		},
	},
}

/**
 * The file type named for each extension.
 *
 * @return {object} The type per extension
 */
function fileTypeNames() {
	return { ...documentTypeNames(), ...imageAndArchiveTypeNames() }
}

/**
 * The document file types, per extension.
 *
 * @return {object} The type per extension
 */
function documentTypeNames() {
	return {
		pdf: 'PDF',
		doc: 'Word',
		docx: 'Word',
		xls: 'Excel',
		xlsx: 'Excel',
		ppt: 'PowerPoint',
		pptx: 'PowerPoint',
		txt: 'Text',
	}
}

/**
 * The image and archive file types, per extension.
 *
 * @return {object} The type per extension
 */
function imageAndArchiveTypeNames() {
	return {
		jpg: 'Image',
		jpeg: 'Image',
		png: 'Image',
		gif: 'Image',
		zip: 'Archive',
		rar: 'Archive',
	}
}

/**
 * The dropdown option for a custom value.
 *
 * @return {{label: string, value: string}} The option
 */
function customOption() {
	return {
		label: t('openregister', 'Other value'),
		value: 'custom',
	}
}

/**
 * The objects that refer to the source object, as the merge lists them.
 *
 * @param {string} url The used-by endpoint
 * @return {Promise<Array<object>>} The references
 */
async function fetchIncomingReferences(url) {
	const response = await fetch(url)
	const data = await response.json()
	return (data.results || []).map(incomingReference)
}

/**
 * Whether a value is filled in: not undefined, null or empty.
 *
 * @param {unknown} value The value
 * @return {boolean} True when filled in
 */
function isFilled(value) {
	return value !== undefined && value !== null && value !== ''
}
</script>

<style scoped lang="scss">
@use '../../styles/modals.scss' as *;

.merge-step {
	padding: 0;
}

.step-1 {
	padding-top: 0 !important;
}

@include step-title-rules;

.merge-step h3 {
	margin-bottom: 16px;
	color: var(--color-main-text);
}

.search-container {
	margin-bottom: 20px;
}

@include loading-container;

.object-list {
	max-height: 400px;
	overflow-y: auto;
	border: 1px solid var(--color-border);
	border-radius: 4px;
}

.object-item {
	padding: 12px;
	border-bottom: 1px solid var(--color-border);
	cursor: pointer;
	transition: background-color 0.2s;
}

.object-item:last-child {
	border-bottom: none;
}

@include object-info-strong;

.object-id {
	color: var(--color-text-maxcontrast);
	font-size: 0.9em;
	margin: 0;
	font-family: monospace;
}

.merge-table-container {
	margin: 20px 0;
	border: 1px solid var(--color-border);
	border-radius: 4px;
	overflow-x: auto;
}

.merge-table {
	width: 100%;
	border-collapse: collapse;
	table-layout: fixed;
}

.merge-table th,
.merge-table td {
	padding: 12px;
	text-align: left;
	border-bottom: 1px solid var(--color-border);
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.merge-table th {
	background-color: var(--color-background-dark);
	font-weight: bold;
	color: var(--color-main-text);
}

.property-name {
	font-weight: bold;
	color: var(--color-main-text);
	width: 15%;
	min-width: 120px;
}

.source-value,
.target-value {
	background-color: var(--color-background-hover);
	font-family: monospace;
	font-size: 0.9em;
	width: 25%;
	max-width: 200px;
	cursor: help;
}

.merge-target {
	width: 35%;
	min-width: 200px;
}

.fixed-value {
	color: var(--color-text-maxcontrast);
	font-style: italic;
}

.custom-input {
	margin-top: 8px;
}

.options-section {
	margin: 20px 0;
	padding: 16px;
	background-color: var(--color-background-hover);
	border-radius: 4px;
}

.options-section h4 {
	margin-bottom: 12px;
	color: var(--color-main-text);
}

.options-description {
	margin-bottom: 12px;
	color: var(--color-text-maxcontrast);
	font-size: 0.9em;
}

.radio-options {
	margin-bottom: 16px;
}

.table-toggle {
	margin-bottom: 12px;
	display: flex;
	justify-content: flex-start;
}

.file-list,
.relation-list {
	margin: 12px 0;
	max-height: 300px;
	overflow-y: auto;
	border: 1px solid var(--color-border);
	border-radius: 4px;
}

.file-table,
.relation-table {
	width: 100%;
	border-collapse: collapse;
	table-layout: fixed;
}

.file-table th,
.file-table td,
.relation-table th,
.relation-table td {
	padding: 8px 12px;
	text-align: left;
	border-bottom: 1px solid var(--color-border);
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.file-table th:nth-child(1),
.file-table td:nth-child(1) {
	width: 60%;
}

.file-table th:nth-child(2),
.file-table td:nth-child(2) {
	width: 20%;
}

.file-table th:nth-child(3),
.file-table td:nth-child(3) {
	width: 20%;
}

.relation-table th:nth-child(1),
.relation-table td:nth-child(1) {
	width: 50%;
}

.relation-table th:nth-child(2),
.relation-table td:nth-child(2) {
	width: 25%;
}

.relation-table th:nth-child(3),
.relation-table td:nth-child(3) {
	width: 25%;
}

.file-table th,
.relation-table th {
	background-color: var(--color-background-dark);
	font-weight: bold;
	position: sticky;
	top: 0;
	z-index: 1;
}

.file-table tbody tr:hover,
.relation-table tbody tr:hover {
	background-color: var(--color-background-hover);
}

.no-files,
.no-relations {
	padding: 20px;
	text-align: center;
	color: var(--color-text-maxcontrast);
	font-style: italic;
}

.merge-report {
	margin-top: 16px;
}

@include report-section;

.object-info {
	display: flex;
	flex-direction: column;
	gap: 16px;
}

.object-detail {
	padding: 12px;
	background-color: var(--color-background-dark);
	border-radius: 4px;
}

.object-meta {
	display: flex;
	flex-direction: column;
	gap: 4px;
	margin-top: 8px;
}

.object-title {
	color: var(--color-main-text);
	font-weight: 500;
}

.object-status {
	font-size: 0.9em;
	font-weight: bold;
}

.object-status.deleted {
	color: var(--color-error);
}

@include report-section-h4-rules;

.report-section li {
	margin-bottom: 4px;
}

.report-table {
	width: 100%;
	border-collapse: collapse;
	margin-top: 8px;
}

.report-table th,
.report-table td {
	padding: 8px 12px;
	text-align: left;
	border-bottom: 1px solid var(--color-border);
}

.report-table th {
	background-color: var(--color-background-dark);
	font-weight: bold;
}

@include error-text-rules;

@media (prefers-reduced-motion: reduce) {
	.object-item {
		transition: none;
	}
}
</style>

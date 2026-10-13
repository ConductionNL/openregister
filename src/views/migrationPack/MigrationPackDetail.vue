<!--
  One migration pack: its general settings, the ordered field mappings with
  their transforms, defaults and rows to skip, an Advanced (JSON) view and a
  sample-row test.

  A Nextcloud administrator edits; anyone else reads. The rows stay in the
  stored order, because the engine writes them in order and the later row
  wins for the same target. Saving an unedited pack sends the stored
  definition back unchanged, including keys this editor does not show.

  Leaving with unsaved changes asks first: a route change through the router
  guards, closing or reloading the tab through the browser's own prompt.

  @spec openspec/changes/migration-pack-editor-ui/specs/migration-mapping-packs/spec.md#requirement-administrators-must-be-able-to-list-and-edit-migration-packs-in-the-openregister-ui
  @spec openspec/changes/migration-pack-editor-ui/specs/migration-mapping-packs/spec.md#requirement-pack-changes-must-be-limited-to-administrators-and-logged
  @spec openspec/changes/migration-pack-editor-ui/specs/migration-mapping-packs/spec.md#requirement-the-slug-of-a-built-in-pack-must-not-change
  SPDX-License-Identifier: EUPL-1.2
  SPDX-FileCopyrightText: 2026 Conduction B.V.
-->
<template>
	<NcAppContent>
		<div class="migration-pack">
			<router-link
				class="migration-pack__back"
				:to="{ name: 'migration-packs' }">
				<ArrowLeft :size="20" />
				{{ t('openregister', 'Migration packs') }}
			</router-link>

			<NcLoadingIcon v-if="loading" />
			<NcNoteCard v-else-if="loadError" type="error">
				{{ loadError }}
			</NcNoteCard>

			<template v-else-if="draft">
				<div class="migration-pack__header">
					<h2>{{ pack.name || pack.packSlug }}</h2>
					<span v-if="pack.builtin" class="migration-pack__badge">
						{{ t('openregister', 'Built-in') }}
					</span>
				</div>
				<NcNoteCard v-if="!isAdmin" type="info">
					{{
						t(
							'openregister',
							'Only administrators can change migration packs. You can read this one.',
						)
					}}
				</NcNoteCard>
				<NcNoteCard type="info">
					{{
						t(
							'openregister',
							'An app that imports with this pack can add rules of its own, for example which columns hold dates or which sheet a row comes from. Check that app before you change a pack it installed.',
						)
					}}
				</NcNoteCard>

				<div
					class="migration-pack__views"
					role="group"
					:aria-label="t('openregister', 'View')">
					<NcButton
						:variant="view === 'form' ? 'primary' : 'secondary'"
						:aria-pressed="view === 'form' ? 'true' : 'false'"
						@click="showForm">
						{{ t('openregister', 'Form') }}
					</NcButton>
					<NcButton
						:variant="view === 'json' ? 'primary' : 'secondary'"
						:aria-pressed="view === 'json' ? 'true' : 'false'"
						@click="showJson">
						{{ t('openregister', 'Advanced (JSON)') }}
					</NcButton>
				</div>

				<template v-if="view === 'form'">
					<section
						class="migration-pack__card"
						aria-labelledby="migration-pack-general">
						<h3 id="migration-pack-general">
							{{ t('openregister', 'General') }}
						</h3>
						<NcTextField
							:modelValue="draft.name"
							:label="t('openregister', 'Name')"
							:disabled="!isAdmin"
							@update:modelValue="draft.name = $event" />
						<NcTextField
							:modelValue="draft.slug"
							:label="t('openregister', 'Slug')"
							:disabled="!isAdmin || slugLocked"
							:helperText="
								slugLocked
									? t(
											'openregister',
											'The slug of a built-in pack cannot change: the app that installed it finds the pack by this slug.',
										)
									: t(
											'openregister',
											'Imports find the pack by this slug.',
										)
							"
							@update:modelValue="draft.slug = $event" />
						<NcTextArea
							:modelValue="draft.description"
							:label="t('openregister', 'Description')"
							:disabled="!isAdmin"
							resize="vertical"
							@update:modelValue="draft.description = $event" />
						<div class="migration-pack__inline">
							<NcTextField
								:modelValue="draft.version"
								:label="t('openregister', 'Version')"
								:disabled="!isAdmin"
								@update:modelValue="draft.version = $event" />
							<div class="migration-pack__select">
								<label for="migration-pack-source-format">{{
									t('openregister', 'Source format')
								}}</label>
								<select
									id="migration-pack-source-format"
									v-model="draft.sourceFormat"
									:disabled="!isAdmin">
									<option
										v-for="format in sourceFormats"
										:key="format.value"
										:value="format.value">
										{{ format.label }}
									</option>
								</select>
							</div>
							<div class="migration-pack__select">
								<label for="migration-pack-id-strategy">{{
									t('openregister', 'Object id')
								}}</label>
								<select
									id="migration-pack-id-strategy"
									v-model="draft.idStrategyType"
									:disabled="!isAdmin">
									<option value="generate">
										{{ t('openregister', 'Generate a new id') }}
									</option>
									<option value="sourceField">
										{{
											t(
												'openregister',
												'Take the id from a column',
											)
										}}
									</option>
								</select>
							</div>
							<NcTextField
								v-if="draft.idStrategyType === 'sourceField'"
								:modelValue="draft.idStrategyField"
								:label="t('openregister', 'Id column')"
								:disabled="!isAdmin"
								@update:modelValue="
									draft.idStrategyField = $event
								" />
						</div>
					</section>

					<section
						class="migration-pack__card"
						aria-labelledby="migration-pack-mappings">
						<h3 id="migration-pack-mappings">
							{{ t('openregister', 'Field mappings') }}
						</h3>
						<p class="migration-pack__help">
							{{
								t(
									'openregister',
									'Rows run from top to bottom. When two rows write the same field and both columns have a value, the lower row wins.',
								)
							}}
						</p>
						<div
							class="migration-pack__table-scroll"
							role="region"
							aria-labelledby="migration-pack-mappings"
							tabindex="0">
							<table class="migration-pack__table">
								<colgroup>
									<col class="migration-pack__col-number" />
									<col class="migration-pack__col-field" />
									<col class="migration-pack__col-field" />
									<col class="migration-pack__col-required" />
									<col />
									<col
										v-if="isAdmin"
										class="migration-pack__col-actions" />
								</colgroup>
								<thead>
									<tr>
										<th scope="col">#</th>
										<th scope="col">
											{{ t('openregister', 'Source column') }}
										</th>
										<th scope="col">
											{{ t('openregister', 'Target field') }}
										</th>
										<th scope="col">
											{{ t('openregister', 'Required') }}
										</th>
										<th scope="col">
											{{ t('openregister', 'Transform') }}
										</th>
										<th v-if="isAdmin" scope="col">
											<span class="hidden-visually">{{
												t('openregister', 'Actions')
											}}</span>
										</th>
									</tr>
								</thead>
								<tbody>
									<tr
										v-for="(row, index) in draft.rows"
										:key="row.uid">
										<td>{{ index + 1 }}</td>
										<td>
											<input
												v-model="row.source"
												type="text"
												:disabled="!isAdmin"
												:aria-label="
													t(
														'openregister',
														'Source column of row {number}',
														{ number: index + 1 },
													)
												" />
										</td>
										<td>
											<input
												v-model="row.target"
												type="text"
												:disabled="!isAdmin"
												:aria-label="
													t(
														'openregister',
														'Target field of row {number}',
														{ number: index + 1 },
													)
												"
												:aria-describedby="
													targetHint(row)
														? hintId(row)
														: undefined
												" />
											<p
												v-if="targetHint(row)"
												:id="hintId(row)"
												class="migration-pack__hint">
												{{ targetHint(row) }}
											</p>
										</td>
										<td>
											<input
												v-model="row.required"
												type="checkbox"
												:disabled="!isAdmin"
												:aria-label="
													t(
														'openregister',
														'Row {number} is required',
														{ number: index + 1 },
													)
												" />
										</td>
										<td>
											<NcButton
												class="migration-pack__transform"
												variant="tertiary"
												@click="editingRow = index">
												<span class="hidden-visually">{{
													t(
														'openregister',
														'Transform of row {number}:',
														{ number: index + 1 },
													)
												}}</span>
												{{ summary(row) }}
											</NcButton>
										</td>
										<td v-if="isAdmin">
											<div class="migration-pack__row-actions">
												<NcButton
													variant="tertiary"
													:data-row-control="`${row.uid}-up`"
													:disabled="index === 0"
													:aria-label="
														t(
															'openregister',
															'Move row {number} up',
															{ number: index + 1 },
														)
													"
													@click="move(index, -1)">
													<template #icon>
														<ArrowUp :size="20" />
													</template>
												</NcButton>
												<NcButton
													variant="tertiary"
													:data-row-control="`${row.uid}-down`"
													:disabled="
														index
														=== draft.rows.length - 1
													"
													:aria-label="
														t(
															'openregister',
															'Move row {number} down',
															{ number: index + 1 },
														)
													"
													@click="move(index, 1)">
													<template #icon>
														<ArrowDown :size="20" />
													</template>
												</NcButton>
												<NcButton
													variant="tertiary"
													:data-row-control="`${row.uid}-remove`"
													:aria-label="
														t(
															'openregister',
															'Remove row {number}',
															{ number: index + 1 },
														)
													"
													@click="removeRow(index)">
													<template #icon>
														<TrashCanOutline
															:size="20" />
													</template>
												</NcButton>
											</div>
										</td>
									</tr>
								</tbody>
							</table>
						</div>
						<div class="hidden-visually" aria-live="polite">
							{{ announcement }}
						</div>
						<NcButton
							v-if="isAdmin"
							data-row-control="add-row"
							@click="addRow">
							<template #icon>
								<Plus :size="20" />
							</template>
							{{ t('openregister', 'Add field mapping') }}
						</NcButton>
					</section>

					<section
						class="migration-pack__card"
						aria-labelledby="migration-pack-defaults">
						<h3 id="migration-pack-defaults">
							{{ t('openregister', 'Defaults') }}
						</h3>
						<p class="migration-pack__help">
							{{
								t(
									'openregister',
									'Values every imported object starts with. A mapping that gives a field a value replaces its default.',
								)
							}}
						</p>
						<KeyValueRows
							:rows="draft.defaults"
							:keyLabel="t('openregister', 'Field')"
							:valueLabel="t('openregister', 'Default value')"
							:addLabel="t('openregister', 'Add default')"
							:emptyText="t('openregister', 'No defaults.')"
							:disabled="!isAdmin"
							@update:rows="draft.defaults = $event" />
						<NcTextField
							:modelValue="draft.skipRows"
							:label="t('openregister', 'Rows to skip')"
							:helperText="
								t(
									'openregister',
									'Row numbers in the source, separated by commas. Row 1 is the first data row.',
								)
							"
							:disabled="!isAdmin"
							@update:modelValue="draft.skipRows = $event" />
					</section>
				</template>

				<section
					v-else
					class="migration-pack__card"
					aria-labelledby="migration-pack-json">
					<h3 id="migration-pack-json">
						{{ t('openregister', 'Advanced (JSON)') }}
					</h3>
					<p class="migration-pack__help">
						{{
							t(
								'openregister',
								'The whole pack definition. Changes here replace the form when you go back to it or save.',
							)
						}}
					</p>
					<div class="migration-pack__code" :class="theme">
						<CodeMirror
							v-model="jsonText"
							:basic="true"
							:dark="theme === 'dark'"
							:readonly="!isAdmin"
							:lang="jsonLang"
							:linter="jsonLinter" />
					</div>
				</section>

				<div aria-live="polite">
					<NcNoteCard v-if="packDeleted" type="error">
						<p>
							{{
								t(
									'openregister',
									'Someone deleted this pack while you were editing it. Your changes are not saved: save them as a new pack, or copy them from the JSON.',
								)
							}}
						</p>
						<div v-if="isAdmin" class="migration-pack__problem-actions">
							<NcButton :disabled="saving" @click="saveAsNew">
								{{ t('openregister', 'Save as a new pack') }}
							</NcButton>
							<NcButton v-if="view !== 'json'" @click="showJson">
								{{ t('openregister', 'Show the JSON') }}
							</NcButton>
						</div>
					</NcNoteCard>
					<NcNoteCard v-if="view === 'json' && jsonError" type="error">
						{{ jsonError }}
					</NcNoteCard>
					<NcNoteCard v-if="saveProblem" type="error">
						<p>{{ saveProblem.message }}</p>
						<ul v-if="saveProblem.details.length > 0">
							<li v-for="detail in saveProblem.details" :key="detail">
								{{ detail }}
							</li>
						</ul>
						<NcButton v-if="saveProblem.conflict" @click="reload">
							{{ t('openregister', 'Reload the pack') }}
						</NcButton>
					</NcNoteCard>
					<NcNoteCard v-if="saved" type="success">
						{{
							t('openregister', 'Saved as version {version}.', {
								version: pack.version,
							})
						}}
					</NcNoteCard>
				</div>

				<div v-if="isAdmin" class="migration-pack__footer">
					<p v-if="dirty && versionWillBump" class="migration-pack__help">
						{{
							t(
								'openregister',
								'Saving raises the version to {version}.',
								{ version: bumpedVersion },
							)
						}}
					</p>
					<NcButton :disabled="saving || !dirty" @click="reset">
						{{ t('openregister', 'Undo changes') }}
					</NcButton>
					<NcButton
						variant="primary"
						:disabled="saving || !dirty || packDeleted"
						@click="save">
						<template #icon>
							<NcLoadingIcon v-if="saving" :size="20" />
							<ContentSave v-else :size="20" />
						</template>
						{{ t('openregister', 'Save') }}
					</NcButton>
				</div>

				<PackPreviewPanel
					v-if="isAdmin"
					class="migration-pack__card"
					:definition="previewDefinition"
					:columns="previewColumns"
					:blockedText="
						view === 'json'
							? t(
									'openregister',
									'Switch to the form to test the pack.',
								)
							: ''
					" />
			</template>
		</div>

		<TransformDialog
			v-if="editingRow !== null && draft && draft.rows[editingRow]"
			:transform="draft.rows[editingRow].transform"
			:rowNumber="editingRow + 1"
			:source="draft.rows[editingRow].source"
			:target="draft.rows[editingRow].target"
			:readOnly="!isAdmin"
			@apply="applyTransform"
			@close="editingRow = null" />

		<UnsavedChangesDialog
			v-if="leavePrompt"
			@leave="resolveLeave(true)"
			@stay="resolveLeave(false)" />
	</NcAppContent>
</template>

<script>
import { json, jsonParseLinter } from '@codemirror/lang-json'
import {
	NcAppContent,
	NcButton,
	NcLoadingIcon,
	NcNoteCard,
	NcTextArea,
	NcTextField,
} from '@nextcloud/vue'
import { getCurrentInstance } from 'vue'
import CodeMirror from 'vue-codemirror6'
import { onBeforeRouteLeave, onBeforeRouteUpdate } from 'vue-router'
import ArrowDown from 'vue-material-design-icons/ArrowDown.vue'
import ArrowLeft from 'vue-material-design-icons/ArrowLeft.vue'
import ArrowUp from 'vue-material-design-icons/ArrowUp.vue'
import ContentSave from 'vue-material-design-icons/ContentSave.vue'
import Plus from 'vue-material-design-icons/Plus.vue'
import TrashCanOutline from 'vue-material-design-icons/TrashCanOutline.vue'
import KeyValueRows from '../../components/migrationPack/KeyValueRows.vue'
import PackPreviewPanel from '../../components/migrationPack/PackPreviewPanel.vue'
import TransformDialog from '../../dialogs/migrationPack/TransformDialog.vue'
import UnsavedChangesDialog from '../../dialogs/migrationPack/UnsavedChangesDialog.vue'
import { getTheme } from '../../services/getTheme.js'
import {
	canEditPacks,
	createPack,
	explainError,
	getPack,
	updatePack,
} from '../../services/migrationPacks.js'
import { t } from './l10n.js'
import {
	draftFromStored,
	duplicateTargets,
	moveRow,
	newRow,
	nextVersion,
	payloadFromDraft,
	sameDefinition,
	sampleColumns,
	SOURCE_FORMATS,
	transformSummary,
	validateDraft,
} from './packDraft.js'

export default {
	name: 'MigrationPackDetail',
	components: {
		ArrowDown,
		ArrowLeft,
		ArrowUp,
		CodeMirror,
		ContentSave,
		KeyValueRows,
		NcAppContent,
		NcButton,
		NcLoadingIcon,
		NcNoteCard,
		NcTextArea,
		NcTextField,
		PackPreviewPanel,
		Plus,
		TransformDialog,
		TrashCanOutline,
		UnsavedChangesDialog,
	},

	/**
	 * Ask before the router leaves the pack, or moves to another pack, while
	 * the editor holds unsaved changes.
	 *
	 * The page is rendered by CnPageRenderer inside the router view, so the
	 * options-API `beforeRouteLeave` hook (route components only) would never
	 * run; the composition guards work for any component under the view.
	 *
	 * @spec openspec/changes/migration-pack-editor-ui/specs/migration-mapping-packs/spec.md#requirement-administrators-must-be-able-to-list-and-edit-migration-packs-in-the-openregister-ui
	 */
	setup() {
		const component = getCurrentInstance()
		const guard = () => component.proxy.confirmLeave()
		onBeforeRouteLeave(guard)
		onBeforeRouteUpdate(guard)
	},

	data() {
		return {
			pack: null,
			draft: null,
			loading: true,
			loadError: '',
			view: 'form',
			jsonText: '',
			jsonError: '',
			editingRow: null,
			saving: false,
			saveProblem: null,
			saved: false,
			packDeleted: false,
			leavePrompt: null,
			announcement: '',
			isAdmin: canEditPacks(),
			theme: getTheme(),
			jsonLang: json(),
			jsonLinter: jsonParseLinter(),
		}
	},

	computed: {
		/**
		 * The pack's row id from the route.
		 *
		 * @return {string} The id.
		 *
		 * @spec openspec/changes/migration-pack-editor-ui/specs/migration-mapping-packs/spec.md#requirement-administrators-must-be-able-to-list-and-edit-migration-packs-in-the-openregister-ui
		 */
		packId() {
			return String(this.$route?.params?.id ?? '')
		},

		/**
		 * Whether the slug may not change: the pack is built-in.
		 *
		 * @return {boolean} True for a built-in pack.
		 *
		 * @spec openspec/changes/migration-pack-editor-ui/specs/migration-mapping-packs/spec.md#requirement-the-slug-of-a-built-in-pack-must-not-change
		 */
		slugLocked() {
			return this.pack?.builtin === true
		},

		/**
		 * The source formats the picker offers.
		 *
		 * @return {Array<{value: string, label: string}>} The options.
		 *
		 * @spec openspec/changes/migration-pack-editor-ui/specs/migration-mapping-packs/spec.md#requirement-administrators-must-be-able-to-list-and-edit-migration-packs-in-the-openregister-ui
		 */
		sourceFormats() {
			const labels = { csv: 'CSV', json: 'JSON', excel: 'Excel' }
			return SOURCE_FORMATS.map((value) => ({ value, label: labels[value] }))
		},

		/**
		 * The definition the form stands for, or null while it cannot be built.
		 *
		 * @return {object|null} The definition.
		 *
		 * @spec openspec/changes/migration-pack-editor-ui/specs/migration-mapping-packs/spec.md#requirement-the-pack-editor-must-save-a-definition-without-losing-what-it-does-not-show
		 */
		currentDefinition() {
			if (!this.draft) {
				return null
			}
			try {
				return payloadFromDraft(this.draft)
			} catch {
				return null
			}
		},

		/**
		 * Whether the editor holds changes that are not saved.
		 *
		 * @return {boolean} True when the definition differs from the stored one.
		 *
		 * @spec openspec/changes/migration-pack-editor-ui/specs/migration-mapping-packs/spec.md#requirement-the-pack-editor-must-save-a-definition-without-losing-what-it-does-not-show
		 */
		dirty() {
			if (!this.pack) {
				return false
			}
			if (this.view === 'json') {
				// By content: a layout-only change saves nothing new.
				return (
					this.jsonDefinition === null
					|| !sameDefinition(this.jsonDefinition, this.pack.definition)
				)
			}
			return (
				this.currentDefinition === null
				|| !sameDefinition(this.currentDefinition, this.pack.definition)
			)
		},

		/**
		 * The stored definition as the JSON view shows it.
		 *
		 * @return {string} Pretty-printed JSON.
		 *
		 * @spec openspec/changes/migration-pack-editor-ui/specs/migration-mapping-packs/spec.md#requirement-the-pack-editor-must-save-a-definition-without-losing-what-it-does-not-show
		 */
		storedJson() {
			return JSON.stringify(this.pack?.definition ?? {}, null, 2)
		},

		/**
		 * The JSON view's text as a definition, or null when it is not a JSON object.
		 *
		 * @return {object|null} The definition.
		 *
		 * @spec openspec/changes/migration-pack-editor-ui/specs/migration-mapping-packs/spec.md#requirement-administrators-must-be-able-to-list-and-edit-migration-packs-in-the-openregister-ui
		 */
		jsonDefinition() {
			try {
				const parsed = JSON.parse(this.jsonText)
				return parsed !== null
					&& typeof parsed === 'object'
					&& !Array.isArray(parsed)
					? parsed
					: null
			} catch {
				return null
			}
		},

		/**
		 * Whether a save raises the version: the user left it as stored.
		 *
		 * In the JSON view that also needs a change to the definition itself,
		 * not only to its layout.
		 *
		 * @return {boolean} True when the version is unchanged.
		 *
		 * @spec openspec/changes/migration-pack-editor-ui/specs/migration-mapping-packs/spec.md#requirement-administrators-must-be-able-to-list-and-edit-migration-packs-in-the-openregister-ui
		 */
		versionWillBump() {
			if (this.view === 'json') {
				const definition = this.jsonDefinition
				return (
					definition !== null
					&& definition.version === this.pack?.version
					&& !sameDefinition(definition, this.pack.definition)
				)
			}
			return this.view === 'form' && this.draft?.version === this.pack?.version
		},

		/**
		 * The version a save gives the pack when the user did not set one.
		 *
		 * @return {string} The next patch version.
		 *
		 * @spec openspec/changes/migration-pack-editor-ui/specs/migration-mapping-packs/spec.md#requirement-administrators-must-be-able-to-list-and-edit-migration-packs-in-the-openregister-ui
		 */
		bumpedVersion() {
			return nextVersion(this.pack?.version || '')
		},

		/**
		 * The definition the sample-row test runs, or null while the form has problems.
		 *
		 * @return {object|null} The definition.
		 *
		 * @spec openspec/changes/migration-pack-editor-ui/specs/migration-mapping-packs/spec.md#requirement-a-pack-must-be-testable-against-sample-rows-without-saving-it
		 */
		previewDefinition() {
			if (
				this.view !== 'form'
				|| !this.draft
				|| validateDraft(this.draft).length > 0
			) {
				return null
			}
			return this.currentDefinition
		},

		/**
		 * The source columns the sample row asks a value for.
		 *
		 * @return {Array<string>} The columns.
		 *
		 * @spec openspec/changes/migration-pack-editor-ui/specs/migration-mapping-packs/spec.md#requirement-a-pack-must-be-testable-against-sample-rows-without-saving-it
		 */
		previewColumns() {
			return this.draft ? sampleColumns(this.draft) : []
		},

		/**
		 * Targets that more than one row writes.
		 *
		 * @return {Map<string, Array<number>>} Target to row numbers.
		 *
		 * @spec openspec/changes/migration-pack-editor-ui/specs/migration-mapping-packs/spec.md#requirement-the-pack-editor-must-save-a-definition-without-losing-what-it-does-not-show
		 */
		sharedTargets() {
			return this.draft ? duplicateTargets(this.draft.rows) : new Map()
		},
	},

	watch: {
		/**
		 * Load the other pack when the route moves to it.
		 *
		 * @spec openspec/changes/migration-pack-editor-ui/specs/migration-mapping-packs/spec.md#requirement-administrators-must-be-able-to-list-and-edit-migration-packs-in-the-openregister-ui
		 */
		packId() {
			// Save as a new pack moves to the pack it already holds.
			if (this.pack && String(this.pack.id) === this.packId) {
				return
			}
			this.load()
		},
	},

	/**
	 * Warn before the browser unloads unsaved changes, then load the pack.
	 *
	 * @return {Promise<void>}
	 *
	 * @spec openspec/changes/migration-pack-editor-ui/specs/migration-mapping-packs/spec.md#requirement-administrators-must-be-able-to-list-and-edit-migration-packs-in-the-openregister-ui
	 */
	async mounted() {
		window.addEventListener('beforeunload', this.onBeforeUnload)
		await this.load()
	},

	/**
	 * Stop the unload warning and settle a pending leave prompt.
	 *
	 * @spec openspec/changes/migration-pack-editor-ui/specs/migration-mapping-packs/spec.md#requirement-administrators-must-be-able-to-list-and-edit-migration-packs-in-the-openregister-ui
	 */
	beforeUnmount() {
		window.removeEventListener('beforeunload', this.onBeforeUnload)
		this.resolveLeave(false)
	},

	methods: {
		t,
		/**
		 * Load the pack and start a fresh draft from it.
		 *
		 * @return {Promise<void>}
		 *
		 * @spec openspec/changes/migration-pack-editor-ui/specs/migration-mapping-packs/spec.md#requirement-administrators-must-be-able-to-list-and-edit-migration-packs-in-the-openregister-ui
		 */
		async load() {
			this.loading = true
			this.loadError = ''
			this.saveProblem = null
			this.saved = false
			this.packDeleted = false
			try {
				this.pack = await getPack(this.packId)
				this.reset()
			} catch (error) {
				this.pack = null
				this.draft = null
				this.loadError =
					error?.response?.status === 404
						? t('openregister', 'This migration pack does not exist.')
						: t('openregister', 'Could not load the migration pack.')
			} finally {
				this.loading = false
			}
		},

		/**
		 * Load the pack again after someone else saved it.
		 *
		 * @return {Promise<void>}
		 *
		 * @spec openspec/changes/migration-pack-editor-ui/specs/migration-mapping-packs/spec.md#requirement-administrators-must-be-able-to-list-and-edit-migration-packs-in-the-openregister-ui
		 */
		async reload() {
			await this.load()
		},

		/**
		 * Drop every unsaved change.
		 *
		 * @spec openspec/changes/migration-pack-editor-ui/specs/migration-mapping-packs/spec.md#requirement-administrators-must-be-able-to-list-and-edit-migration-packs-in-the-openregister-ui
		 */
		reset() {
			this.draft = draftFromStored(this.pack?.definition)
			this.jsonText = this.storedJson
			this.jsonError = ''
			this.saveProblem = null
		},

		/**
		 * Whether the page may be left: at once without unsaved changes,
		 * otherwise after the user confirms in a dialog.
		 *
		 * @return {boolean|Promise<boolean>} True to leave.
		 *
		 * @spec openspec/changes/migration-pack-editor-ui/specs/migration-mapping-packs/spec.md#requirement-administrators-must-be-able-to-list-and-edit-migration-packs-in-the-openregister-ui
		 */
		confirmLeave() {
			if (!this.isAdmin || !this.dirty) {
				return true
			}
			// A newer navigation replaces one that is still asking.
			this.resolveLeave(false)
			return new Promise((resolve) => {
				this.leavePrompt = { resolve }
			})
		},

		/**
		 * Answer the open leave question, if any.
		 *
		 * @param {boolean} leave True to leave, false to stay.
		 *
		 * @spec openspec/changes/migration-pack-editor-ui/specs/migration-mapping-packs/spec.md#requirement-administrators-must-be-able-to-list-and-edit-migration-packs-in-the-openregister-ui
		 */
		resolveLeave(leave) {
			const prompt = this.leavePrompt
			this.leavePrompt = null
			if (prompt) {
				prompt.resolve(leave)
			}
		},

		/**
		 * Let the browser ask before the tab closes or reloads with unsaved changes.
		 *
		 * @param {BeforeUnloadEvent} event The event.
		 *
		 * @spec openspec/changes/migration-pack-editor-ui/specs/migration-mapping-packs/spec.md#requirement-administrators-must-be-able-to-list-and-edit-migration-packs-in-the-openregister-ui
		 */
		onBeforeUnload(event) {
			if (!this.isAdmin || !this.dirty) {
				return
			}
			event.preventDefault()
			// Older browsers only ask when returnValue is set.
			event.returnValue = ''
		},

		/**
		 * The id of the hint under a row's target field.
		 *
		 * @param {object} row The row.
		 * @return {string} The id.
		 *
		 * @spec openspec/changes/migration-pack-editor-ui/specs/migration-mapping-packs/spec.md#requirement-the-pack-editor-must-save-a-definition-without-losing-what-it-does-not-show
		 */
		hintId(row) {
			return `migration-pack-target-hint-${row.uid}`
		},

		/**
		 * Move keyboard focus to a control of the mapping table once it renders.
		 *
		 * @param {string} name The control's `data-row-control` value.
		 *
		 * @spec openspec/changes/migration-pack-editor-ui/specs/migration-mapping-packs/spec.md#requirement-administrators-must-be-able-to-list-and-edit-migration-packs-in-the-openregister-ui
		 */
		focusControl(name) {
			this.$nextTick(() => {
				const element = this.$el?.querySelector?.(
					`[data-row-control="${name}"]`,
				)
				if (element) {
					element.focus()
				}
			})
		},

		/**
		 * Tell a screen reader what a row action did, through the polite live region.
		 *
		 * @param {string} text The message.
		 *
		 * @spec openspec/changes/migration-pack-editor-ui/specs/migration-mapping-packs/spec.md#requirement-administrators-must-be-able-to-list-and-edit-migration-packs-in-the-openregister-ui
		 */
		announce(text) {
			// Cleared first, so the same message twice is read twice.
			this.announcement = ''
			this.$nextTick(() => {
				this.announcement = text
			})
		},

		/**
		 * The readable transform of a row.
		 *
		 * @param {object} row The row.
		 * @return {string} The summary.
		 *
		 * @spec openspec/changes/migration-pack-editor-ui/specs/migration-mapping-packs/spec.md#requirement-administrators-must-be-able-to-list-and-edit-migration-packs-in-the-openregister-ui
		 */
		summary(row) {
			return transformSummary(row.transform)
		},

		/**
		 * The hint under a target that other rows write too.
		 *
		 * @param {object} row The row.
		 * @return {string} The hint, or '' when only this row writes the target.
		 *
		 * @spec openspec/changes/migration-pack-editor-ui/specs/migration-mapping-packs/spec.md#requirement-the-pack-editor-must-save-a-definition-without-losing-what-it-does-not-show
		 */
		targetHint(row) {
			const numbers = this.sharedTargets.get(row.target)
			if (!numbers) {
				return ''
			}
			return t(
				'openregister',
				'Rows {rows} write this field; the lowest row with a value wins.',
				{
					rows: numbers.join(', '),
				},
			)
		},

		/**
		 * Add an empty mapping row at the end.
		 *
		 * @spec openspec/changes/migration-pack-editor-ui/specs/migration-mapping-packs/spec.md#requirement-administrators-must-be-able-to-list-and-edit-migration-packs-in-the-openregister-ui
		 */
		addRow() {
			this.draft.rows = [...this.draft.rows, newRow()]
		},

		/**
		 * Remove a mapping row.
		 *
		 * @param {number} index The row.
		 *
		 * @spec openspec/changes/migration-pack-editor-ui/specs/migration-mapping-packs/spec.md#requirement-administrators-must-be-able-to-list-and-edit-migration-packs-in-the-openregister-ui
		 */
		removeRow(index) {
			const rows = this.draft.rows.filter((_, i) => i !== index)
			this.draft.rows = rows
			// The focused button is gone: go to the row that took its place.
			const next = rows[index] ?? rows[index - 1]
			this.focusControl(next ? `${next.uid}-remove` : 'add-row')
			this.announce(
				t('openregister', 'Row {number} removed.', { number: index + 1 }),
			)
		},

		/**
		 * Move a mapping row up or down.
		 *
		 * @param {number} index The row.
		 * @param {number} delta -1 for up, 1 for down.
		 *
		 * @spec openspec/changes/migration-pack-editor-ui/specs/migration-mapping-packs/spec.md#requirement-administrators-must-be-able-to-list-and-edit-migration-packs-in-the-openregister-ui
		 */
		move(index, delta) {
			const to = index + delta
			if (to < 0 || to >= this.draft.rows.length) {
				return
			}
			const row = this.draft.rows[index]
			this.draft.rows = moveRow(this.draft.rows, index, delta)
			// Keep focus on the moved row. At the top or bottom the pressed
			// button is disabled, so its other move button takes focus.
			const atEdge = to === 0 || to === this.draft.rows.length - 1
			const up = delta < 0
			this.focusControl(`${row.uid}-${up !== atEdge ? 'up' : 'down'}`)
			this.announce(
				t('openregister', 'Row moved to position {position} of {total}.', {
					position: to + 1,
					total: this.draft.rows.length,
				}),
			)
		},

		/**
		 * Take the transform the dialog hands back.
		 *
		 * @param {object|null} transform The transform draft, or null for none.
		 *
		 * @spec openspec/changes/migration-pack-editor-ui/specs/migration-mapping-packs/spec.md#requirement-administrators-must-be-able-to-list-and-edit-migration-packs-in-the-openregister-ui
		 */
		applyTransform(transform) {
			const index = this.editingRow
			this.draft.rows = this.draft.rows.map((row, i) =>
				i === index ? { ...row, transform } : row,
			)
			this.editingRow = null
		},

		/**
		 * Switch to the JSON view, showing what the form holds now.
		 *
		 * @spec openspec/changes/migration-pack-editor-ui/specs/migration-mapping-packs/spec.md#requirement-administrators-must-be-able-to-list-and-edit-migration-packs-in-the-openregister-ui
		 */
		showJson() {
			if (this.view === 'json') {
				return
			}
			const definition = this.currentDefinition
			if (definition === null) {
				this.saveProblem = {
					message: t(
						'openregister',
						'The form has a value that is not valid JSON. Fix it first.',
					),

					details: validateDraft(this.draft),
				}
				return
			}
			this.jsonText = sameDefinition(definition, this.pack.definition)
				? this.storedJson
				: JSON.stringify(definition, null, 2)
			this.jsonError = ''
			this.view = 'json'
		},

		/**
		 * Switch back to the form, taking over what the JSON view holds.
		 *
		 * @spec openspec/changes/migration-pack-editor-ui/specs/migration-mapping-packs/spec.md#requirement-administrators-must-be-able-to-list-and-edit-migration-packs-in-the-openregister-ui
		 */
		showForm() {
			if (this.view === 'form') {
				return
			}
			if (this.applyJson()) {
				this.view = 'form'
			}
		},

		/**
		 * Replace the draft with the JSON view's text.
		 *
		 * @return {boolean} False when the text cannot be used; the reason is shown.
		 *
		 * @spec openspec/changes/migration-pack-editor-ui/specs/migration-mapping-packs/spec.md#requirement-the-slug-of-a-built-in-pack-must-not-change
		 */
		applyJson() {
			let parsed
			try {
				parsed = JSON.parse(this.jsonText)
			} catch (error) {
				this.jsonError = t(
					'openregister',
					'This is not valid JSON: {reason}',
					{
						reason: error.message,
					},
				)
				return false
			}
			if (
				parsed === null
				|| typeof parsed !== 'object'
				|| Array.isArray(parsed)
			) {
				this.jsonError = t(
					'openregister',
					'A pack definition is a JSON object.',
				)
				return false
			}
			if (this.slugLocked && parsed.id !== this.pack.definition?.id) {
				this.jsonError = t(
					'openregister',
					'The slug of a built-in pack cannot change. Keep "id" as {slug}.',
					{
						slug: this.pack.definition?.id ?? '',
					},
				)
				return false
			}
			this.jsonError = ''
			this.draft = draftFromStored(parsed)
			return true
		},

		/**
		 * The definition to save, or null with the reason shown.
		 *
		 * @return {object|null} The definition.
		 *
		 * @spec openspec/changes/migration-pack-editor-ui/specs/migration-mapping-packs/spec.md#requirement-the-pack-editor-must-save-a-definition-without-losing-what-it-does-not-show
		 */
		definitionToSave() {
			if (this.view === 'json') {
				// The server validates a hand-written definition; send it as
				// written, with the version raised when the user left it.
				if (!this.applyJson()) {
					return null
				}
				const definition = JSON.parse(this.jsonText)
				if (this.versionWillBump) {
					definition.version = nextVersion(definition.version)
				}
				return definition
			}
			const problems = validateDraft(this.draft)
			if (problems.length > 0) {
				this.saveProblem = {
					message: t('openregister', 'The pack cannot be saved yet.'),
					details: problems,
				}
				return null
			}
			const definition = payloadFromDraft(this.draft)
			if (definition.version === this.pack.version) {
				definition.version = nextVersion(definition.version)
			}
			return definition
		},

		/**
		 * Save the pack, unless someone else saved it since it was loaded.
		 *
		 * Two checks. The update names the `updatedAt` the editor loaded, and
		 * the server refuses it with 409 `pack-changed` when the pack was saved
		 * since. That time has a resolution of one second, so the editor also
		 * reads the pack again just before the update and compares its
		 * definition, which tells apart two saves within the same second. Both
		 * refusals are shown the same way. A 412 is not one of them: on this
		 * route Nextcloud sends it only for a failed request-token check, so it
		 * is explained like any other refusal and the edits are not thrown away.
		 *
		 * @return {Promise<void>}
		 *
		 * @spec openspec/changes/migration-pack-editor-ui/specs/migration-mapping-packs/spec.md#requirement-create-and-update-must-accept-a-definition-envelope-and-keep-the-route-id
		 */
		async save() {
			this.saveProblem = null
			this.saved = false
			const definition = this.definitionToSave()
			if (definition === null) {
				return
			}
			this.saving = true
			try {
				const current = await getPack(this.packId)
				if (this.changedElsewhere(current)) {
					this.saveProblem = this.conflictProblem()
					return
				}
				this.pack = await updatePack(
					this.packId,
					definition,
					this.pack.updatedAt,
				)
				this.reset()
				this.saved = true
			} catch (error) {
				const status = error?.response?.status
				if (status === 404) {
					// Shown with its own actions; the edits stay in the editor.
					this.packDeleted = true
				} else if (error?.response?.data?.error === 'pack-changed') {
					this.saveProblem = this.conflictProblem()
				} else {
					this.saveProblem = explainError(
						error,
						t('openregister', 'Could not save the migration pack.'),
					)
				}
			} finally {
				this.saving = false
			}
		},

		/**
		 * Whether the pack on the server is no longer the one the editor loaded.
		 *
		 * @param {object} current The pack as the server has it now.
		 * @return {boolean} True when someone else saved it.
		 *
		 * @spec openspec/changes/migration-pack-editor-ui/specs/migration-mapping-packs/spec.md#requirement-administrators-must-be-able-to-list-and-edit-migration-packs-in-the-openregister-ui
		 */
		changedElsewhere(current) {
			return (
				current.updatedAt !== this.pack.updatedAt
				|| !sameDefinition(current.definition, this.pack.definition)
			)
		},

		/**
		 * The problem shown when someone else saved the pack first.
		 *
		 * @return {object} The problem, with the reload action.
		 *
		 * @spec openspec/changes/migration-pack-editor-ui/specs/migration-mapping-packs/spec.md#requirement-administrators-must-be-able-to-list-and-edit-migration-packs-in-the-openregister-ui
		 */
		conflictProblem() {
			return {
				message: t(
					'openregister',
					'Someone else saved this pack after you opened it. Reload it to see their changes; your changes are not saved.',
				),

				details: [],
				conflict: true,
			}
		},

		/**
		 * Save the edited definition as a new pack, after this one was deleted.
		 *
		 * @return {Promise<void>}
		 *
		 * @spec openspec/changes/migration-pack-editor-ui/specs/migration-mapping-packs/spec.md#requirement-create-and-update-must-accept-a-definition-envelope-and-keep-the-route-id
		 */
		async saveAsNew() {
			this.saveProblem = null
			const definition = this.definitionToSave()
			if (definition === null) {
				return
			}
			this.saving = true
			try {
				this.pack = await createPack(definition)
				this.packDeleted = false
				this.reset()
				this.saved = true
				await this.$router.push({
					name: 'migration-pack-detail',
					params: { id: String(this.pack.id) },
				})
			} catch (error) {
				this.saveProblem = explainError(
					error,
					t('openregister', 'Could not save the migration pack.'),
				)
			} finally {
				this.saving = false
			}
		},
	},
}
</script>

<style scoped>
.migration-pack {
	padding: 16px 24px;
	max-width: 1100px;
}

.migration-pack__back {
	display: inline-flex;
	align-items: center;
	gap: 4px;
	margin-block-end: 8px;
}

.migration-pack__header {
	display: flex;
	align-items: center;
	gap: 8px;
}

.migration-pack__badge {
	border: 1px solid var(--color-border-dark);
	border-radius: var(--border-radius-pill, 16px);
	padding: 0 8px;
	color: var(--color-text-maxcontrast);
}

.migration-pack__views {
	display: flex;
	gap: 8px;
	margin-block: 16px;
}

.migration-pack__card {
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large, 8px);
	padding: 16px;
	margin-block-end: 16px;
	display: flex;
	flex-direction: column;
	gap: 8px;
}

.migration-pack__inline {
	display: flex;
	flex-wrap: wrap;
	align-items: flex-end;
	gap: 8px;
}

.migration-pack__select {
	display: flex;
	flex-direction: column;
}

.migration-pack__help,
.migration-pack__hint {
	color: var(--color-text-maxcontrast);
	margin: 0;
}

.migration-pack__table-scroll {
	overflow-x: auto;
}

.migration-pack__table {
	width: 100%;
	/* Below this the fixed columns would crush the text inputs; the wrapper
	   scrolls sideways instead of the page. */
	min-width: 720px;
	border-collapse: collapse;
	/* Fixed columns: a long hint or transform summary wraps inside its own
	   cell instead of squeezing the source and target columns. */
	table-layout: fixed;
}

.migration-pack__col-number {
	width: 3em;
}

.migration-pack__col-field {
	width: 24%;
}

.migration-pack__col-required {
	width: 5.5em;
}

.migration-pack__col-actions {
	width: 132px;
}

.migration-pack__table .migration-pack__hint {
	white-space: normal;
	overflow-wrap: anywhere;
	font-size: 0.9em;
}

.migration-pack__transform {
	max-width: 100%;
	height: auto;
	min-height: var(--default-clickable-area);
	text-align: start;
}

.migration-pack__transform :deep(.button-vue__wrapper),
.migration-pack__transform :deep(.button-vue__text) {
	white-space: normal;
	overflow-wrap: anywhere;
	text-align: start;
	justify-content: flex-start;
}

.migration-pack__table th,
.migration-pack__table td {
	padding: 4px 8px;
	text-align: start;
	vertical-align: top;
	border-bottom: 1px solid var(--color-border);
}

.migration-pack__table input[type='text'] {
	width: 100%;
	margin: 0;
}

.migration-pack__row-actions {
	display: flex;
	gap: 2px;
}

.migration-pack__code {
	border: 1px dotted var(--color-border-dark);
}

.migration-pack__problem-actions {
	display: flex;
	flex-wrap: wrap;
	gap: 8px;
}

.migration-pack__footer {
	display: flex;
	align-items: center;
	justify-content: flex-end;
	gap: 8px;
	margin-block-end: 16px;
}
</style>

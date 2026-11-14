<template>
	<div class="schemaRightsTab">
		<NcAppContentDetails>
			<h2>{{ t('openregister', 'Rights') }}</h2>
			<p class="lead">
				{{ t('openregister', 'Who may do what with objects of this schema, per value of a field and per role group.') }}
			</p>

			<div v-if="!declared" class="matrixSetup">
				<label class="setupField">
					{{ t('openregister', 'Field') }}
					<select v-model="grid.field" data-testid="matrix-field">
						<option v-for="name in propertyNames" :key="name" :value="name">
							{{ name }}
						</option>
					</select>
				</label>
				<label class="setupField">
					{{ t('openregister', 'Group prefix of a user\'s own values') }}
					<input v-model="groupPrefix" type="text" data-testid="matrix-group-prefix">
				</label>
			</div>
			<p v-else class="matrixField">
				{{ t('openregister', 'Field') }}: <code>{{ grid.field }}</code>
			</p>

			<p v-if="grid.kept.length > 0" class="hint">
				{{ t('openregister', 'Rows with a condition or several values are kept as declared and not shown here.') }}
			</p>

			<div class="gridScroll">
				<table class="rightsGrid" data-testid="rights-grid">
					<thead>
						<tr>
							<th scope="col">
								{{ t('openregister', 'Value') }}
							</th>
							<th v-for="group in grid.groups" :key="group" scope="col">
								{{ group }}
							</th>
						</tr>
					</thead>
					<tbody>
						<tr v-for="value in grid.values" :key="value">
							<th scope="row">
								<code>{{ value }}</code>
							</th>
							<td v-for="group in grid.groups" :key="group">
								<label v-for="action in grid.actions" :key="action" class="actionCheck">
									<input
										type="checkbox"
										:checked="isGranted(value, group, action)"
										:aria-label="value + ' / ' + group + ' / ' + actionLabel(action)"
										@change="toggle(value, group, action, $event.target.checked)">
									{{ actionLabel(action) }}
								</label>
								<p
									v-for="finding in cellFindings[cellKey(value, group)] || []"
									:key="finding"
									class="refusal">
									{{ finding }}
								</p>
							</td>
						</tr>
					</tbody>
				</table>
			</div>

			<div class="gridAdd">
				<label v-if="!hasEnum" class="setupField">
					{{ t('openregister', 'Add value') }}
					<input
						v-model="newValue"
						type="text"
						data-testid="matrix-new-value"
						@keyup.enter="addValue">
				</label>
				<NcButton v-if="!hasEnum" :disabled="newValue.trim() === ''" @click="addValue">
					{{ t('openregister', 'Add value') }}
				</NcButton>
				<label class="setupField">
					{{ t('openregister', 'Add role group') }}
					<input
						v-model="newGroup"
						type="text"
						data-testid="matrix-new-group"
						@keyup.enter="addGroup">
				</label>
				<NcButton :disabled="newGroup.trim() === ''" @click="addGroup">
					{{ t('openregister', 'Add role group') }}
				</NcButton>
			</div>

			<p v-for="finding in generalFindings" :key="finding" class="refusal">
				{{ finding }}
			</p>

			<SaveButton
				:saving="saving"
				:disabled="saving || grid.field === ''"
				data-testid="rights-save"
				@click="save" />

			<section class="matrixPreview">
				<h3>{{ t('openregister', 'Preview') }}</h3>
				<NcSelect
					v-model="previewUser"
					:inputLabel="t('openregister', 'Select a user')"
					:options="userOptions"
					label="label"
					trackBy="id"
					:filterable="false"
					:loading="searching"
					@search="findUsers"
					@update:modelValue="loadPreview" />

				<NcLoadingIcon v-if="previewLoading" :size="32" />
				<p v-else-if="previewError" class="refusal">
					{{ previewError }}
				</p>
				<div v-else-if="preview" data-testid="matrix-preview">
					<p>
						{{ t('openregister', 'Own values: {values}', { values: preview.values.join(', ') || '-' }) }}
					</p>
					<p v-if="previewScopes.length === 0">
						{{ t('openregister', 'This matrix grants this user nothing.') }}
					</p>
					<ul v-else>
						<li v-for="(scope, index) in previewScopes" :key="index">
							<strong>{{ actionLabel(scope.action) }}</strong>
							— {{ scope.group }}:
							<code>{{ scope.condition }}</code>
						</li>
					</ul>
				</div>
			</section>
		</NcAppContentDetails>
	</div>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import { NcAppContentDetails, NcButton, NcLoadingIcon, NcSelect } from '@nextcloud/vue'
import SaveButton from '../../components/shared/SaveButton.vue'
import {
	cellKey,
	fetchMatrixPreview,
	findingsByCell,
	gridFromSchema,
	refusalText,
	rowsFromGrid,
	schemaWithMatrix,
	searchUsers,
} from '../../services/rightsMatrix.js'
import { schemaStore } from '../../store/store.js'

/**
 * The department by role matrix of a schema as a grid: one row per value of
 * the matrix field, one column per role group, a checkbox per action. A save
 * writes `authorization.matrix` and nothing else; the save-time validation
 * answers a refusal next to the cell it names. Below the grid, a preview
 * shows the scopes the matrix compiles to for a chosen user.
 *
 * @visual exclude {Admin grid; the e2e grid and preview step is still to be added to tests/e2e/ci/rbac-department-role-matrix.spec.ts.}
 * @spec openspec/changes/rbac-department-role-matrix/specs/rbac-scopes/spec.md
 */
export default {
	name: 'SchemaRightsTab',

	components: {
		NcAppContentDetails,
		NcButton,
		NcLoadingIcon,
		NcSelect,
		SaveButton,
	},

	props: {
		/** The schema being edited. */
		schema: { type: Object, required: true },
	},

	data() {
		return {
			grid: gridFromSchema(this.schema),
			groupPrefix: 'dept:',
			newValue: '',
			newGroup: '',
			saving: false,
			generalFindings: [],
			cellFindings: {},
			previewUser: null,
			userOptions: [],
			searching: false,
			preview: null,
			previewLoading: false,
			previewError: '',
		}
	},

	computed: {
		/**
		 * Whether the schema already declares a matrix field.
		 *
		 * @spec openspec/changes/rbac-department-role-matrix/specs/rbac-scopes/spec.md
		 * @return {boolean}
		 */
		declared() {
			return typeof this.schema?.authorization?.matrix?.field === 'string'
				&& this.schema.authorization.matrix.field !== ''
		},

		/**
		 * Whether the matrix field declares its values as an enum.
		 *
		 * @spec openspec/changes/rbac-department-role-matrix/specs/rbac-scopes/spec.md
		 * @return {boolean}
		 */
		hasEnum() {
			return Array.isArray(this.schema?.properties?.[this.grid.field]?.enum)
		},

		/**
		 * The schema's property names, for choosing the matrix field.
		 *
		 * @spec openspec/changes/rbac-department-role-matrix/specs/rbac-scopes/spec.md
		 * @return {string[]}
		 */
		propertyNames() {
			return Object.keys(this.schema?.properties ?? {})
		},

		/**
		 * The previewed scopes, one line per action and group.
		 *
		 * @spec openspec/changes/rbac-department-role-matrix/specs/rbac-scopes/spec.md
		 * @return {Array<{action: string, group: string, condition: string}>}
		 */
		previewScopes() {
			const lines = []
			for (const [action, scopes] of Object.entries(this.preview?.scopes ?? {})) {
				for (const scope of Array.isArray(scopes) ? scopes : []) {
					lines.push({
						action,
						group: scope.group ?? '',
						condition: JSON.stringify(scope.match ?? scope.where ?? {}),
					})
				}
			}
			return lines
		},
	},

	watch: {
		schema() {
			this.grid = gridFromSchema(this.schema)
		},
	},

	methods: {
		t,
		cellKey,

		/**
		 * The label of an action.
		 *
		 * @param {string} action The action.
		 * @return {string} The label.
		 * @spec openspec/changes/rbac-department-role-matrix/specs/rbac-scopes/spec.md
		 */
		actionLabel(action) {
			const labels = {
				read: t('openregister', 'Read'),
				update: t('openregister', 'Update'),
				handle: t('openregister', 'Handle'),
				delete: t('openregister', 'Delete'),
			}
			return labels[action] ?? action
		},

		/**
		 * Whether a cell grants an action.
		 *
		 * @param {string} value The field value.
		 * @param {string} group The role group.
		 * @param {string} action The action.
		 * @return {boolean}
		 * @spec openspec/changes/rbac-department-role-matrix/specs/rbac-scopes/spec.md
		 */
		isGranted(value, group, action) {
			return (this.grid.cells[cellKey(value, group)] ?? []).includes(action)
		},

		/**
		 * Grant or withdraw one action in one cell.
		 *
		 * @param {string} value The field value.
		 * @param {string} group The role group.
		 * @param {string} action The action.
		 * @param {boolean} granted Whether it is granted now.
		 * @spec openspec/changes/rbac-department-role-matrix/specs/rbac-scopes/spec.md
		 */
		toggle(value, group, action, granted) {
			const key = cellKey(value, group)
			const cell = (this.grid.cells[key] ?? []).filter((name) => name !== action)
			if (granted) {
				cell.push(action)
			}
			this.grid.cells = { ...this.grid.cells, [key]: cell }
		},

		/**
		 * Add a field value row.
		 *
		 * @spec openspec/changes/rbac-department-role-matrix/specs/rbac-scopes/spec.md
		 */
		addValue() {
			const value = this.newValue.trim()
			if (value !== '' && !this.grid.values.includes(value)) {
				this.grid.values.push(value)
			}
			this.newValue = ''
		},

		/**
		 * Add a role group column.
		 *
		 * @spec openspec/changes/rbac-department-role-matrix/specs/rbac-scopes/spec.md
		 */
		addGroup() {
			const group = this.newGroup.trim()
			if (group !== '' && !this.grid.groups.includes(group)) {
				this.grid.groups.push(group)
			}
			this.newGroup = ''
		},

		/**
		 * Save the matrix, and only the matrix.
		 *
		 * @spec openspec/changes/rbac-department-role-matrix/specs/rbac-scopes/spec.md
		 * @return {Promise<void>}
		 */
		async save() {
			if (!this.declared && this.grid.userSource === null && this.groupPrefix.trim() !== '') {
				this.grid.userSource = { groupPrefix: this.groupPrefix.trim() }
			}
			const { cellsByRow } = rowsFromGrid(this.grid)
			this.saving = true
			this.generalFindings = []
			this.cellFindings = {}
			try {
				await schemaStore.saveSchema(schemaWithMatrix(this.schema, this.grid))
			} catch (error) {
				const text = refusalText(error)
				if (text === '') {
					this.generalFindings = [t('openregister', 'The rights could not be saved.')]
				} else {
					const findings = findingsByCell(text, cellsByRow)
					this.generalFindings = findings.general
					this.cellFindings = findings.cells
				}
			} finally {
				this.saving = false
			}
		},

		/**
		 * Look up users for the preview picker.
		 *
		 * @param {string} search The search text.
		 * @spec openspec/changes/rbac-department-role-matrix/specs/rbac-scopes/spec.md
		 * @return {Promise<void>}
		 */
		async findUsers(search) {
			this.searching = true
			try {
				this.userOptions = await searchUsers(search)
			} catch {
				this.userOptions = []
			} finally {
				this.searching = false
			}
		},

		/**
		 * Load the scopes the matrix compiles to for the chosen user.
		 *
		 * @spec openspec/changes/rbac-department-role-matrix/specs/rbac-scopes/spec.md
		 * @return {Promise<void>}
		 */
		async loadPreview() {
			this.preview = null
			this.previewError = ''
			if (!this.previewUser) {
				return
			}
			this.previewLoading = true
			try {
				this.preview = await fetchMatrixPreview(this.schema.id, this.previewUser.id)
			} catch (error) {
				this.previewError = refusalText(error) || t('openregister', 'The preview could not be loaded.')
			} finally {
				this.previewLoading = false
			}
		},
	},
}
</script>

<style scoped>
.schemaRightsTab {
	padding: 20px;
}

.lead,
.hint {
	color: var(--color-text-maxcontrast);
	margin-bottom: 16px;
}

.matrixSetup,
.gridAdd {
	display: flex;
	flex-wrap: wrap;
	align-items: flex-end;
	gap: 12px;
	margin: 12px 0;
}

.setupField {
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.gridScroll {
	overflow-x: auto;
}

.rightsGrid {
	border-collapse: collapse;
}

.rightsGrid th,
.rightsGrid td {
	padding: 8px;
	border: 1px solid var(--color-border);
	text-align: start;
	vertical-align: top;
}

.actionCheck {
	display: block;
	white-space: nowrap;
}

.matrixPreview {
	margin-top: 28px;
	padding-top: 20px;
	border-top: 2px solid var(--color-border);
}

.refusal {
	color: var(--color-error-text, var(--color-error));
}
</style>

<template>
	<NcDialog
		v-if="show"
		:name="t('openregister', 'New {name}', { name: schemaName })"
		:noClose="saving"
		size="normal"
		class="or-create-connected-dialog"
		@closing="$emit('cancel')">
		<p class="or-create-connected-dialog__intro">
			{{
				t(
					'openregister',
					'Fields marked * are required. The new {name} is connected to this mail.',
					{ name: schemaName },
				)
			}}
		</p>

		<div class="or-create-connected-dialog__form">
			<div
				v-for="field in fields"
				:key="field.key"
				class="or-create-connected-dialog__field"
				:class="{ 'or-create-connected-dialog__field--wide': field.control === 'textarea' }">
				<label
					:for="`occ-${field.key}`"
					class="or-create-connected-dialog__label">
					{{ field.label }}<span v-if="field.required" aria-hidden="true"> *</span>
					<span v-else class="or-create-connected-dialog__optional">{{
						t('openregister', '(optional)')
					}}</span>
				</label>

				<select
					v-if="field.control === 'select' || field.control === 'group'"
					:id="`occ-${field.key}`"
					v-model="form[field.key]"
					:required="field.required"
					class="or-create-connected-dialog__input">
					<option v-for="opt in field.options" :key="opt.value" :value="opt.value">
						{{ opt.label }}
					</option>
				</select>

				<textarea
					v-else-if="field.control === 'textarea'"
					:id="`occ-${field.key}`"
					v-model="form[field.key]"
					:required="field.required"
					rows="4"
					class="or-create-connected-dialog__input" />

				<input
					v-else-if="field.control === 'checkbox'"
					:id="`occ-${field.key}`"
					v-model="form[field.key]"
					type="checkbox" />

				<NcDateTimePicker
					v-else-if="field.control === 'date'"
					:id="`occ-${field.key}`"
					:modelValue="dateOf(form[field.key])"
					type="date"
					:clearable="!field.required"
					class="or-create-connected-dialog__date"
					@update:modelValue="form[field.key] = isoDateOf($event)" />

				<input
					v-else
					:id="`occ-${field.key}`"
					v-model="form[field.key]"
					:type="field.control"
					:required="field.required"
					class="or-create-connected-dialog__input"
					@focus="showFieldStart" />

			</div>
		</div>

		<template #actions>
			<NcButton :disabled="saving" variant="secondary" @click="$emit('cancel')">
				{{ t('openregister', 'Cancel') }}
			</NcButton>
			<NcButton
				:disabled="saving"
				variant="primary"
				@click="$emit('confirm', collect())">
				<template #icon>
					<NcLoadingIcon v-if="saving" :size="20" />
					<Plus v-else :size="20" />
				</template>
				{{ t('openregister', 'Create and connect') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
/**
 * Create-object dialog for the mail sidebar: renders the schema's
 * mailObjectTemplate fields prefilled from the open email, lets the user
 * review/edit, and emits the final field map on confirm (the parent then
 * creates the object and connects the email to it). A relation property
 * (`$ref`) is a choice of the related objects by name, so a templated
 * reference such as a case type shows its name, not its id. Lives in its
 * own file per ADR-004 modal-isolation.
 *
 * @spec openspec/specs/integration-email/spec.md
 */
import axios from '@nextcloud/axios'
import { loadTranslations, translate as t } from '@nextcloud/l10n'
import { generateOcsUrl, generateUrl } from '@nextcloud/router'
import { NcButton, NcDateTimePicker, NcDialog, NcLoadingIcon } from '@nextcloud/vue'
import Plus from 'vue-material-design-icons/Plus.vue'
import { itemName, sentenceName } from '../../mail-sidebar/sentenceName.js'

/**
 * The form control for a property, by the first rule that applies to its key
 * and definition; a property no rule claims is plain text.
 */
const CONTROL_RULES = [
	{ control: 'group', applies: (key, def) => isGroup(def) },
	{
		control: 'select',
		applies: (key, def) => Array.isArray(def.enum) && def.enum.length > 0,
	},
	{ control: 'select', applies: (key, def) => isRelation(def) },
	{ control: 'checkbox', applies: (key, def) => def.type === 'boolean' },
	{
		control: 'number',
		applies: (key, def) => def.type === 'number' || def.type === 'integer',
	},
	{ control: 'date', applies: (key, def) => def.format === 'date' },
	// Multi-line for the obviously long fields.
	{
		control: 'textarea',
		applies: (key) => /notes|description|body|message|summary|content/i.test(key),
	},
]

/**
 * Whether a property holds a Nextcloud group id (the markers nextcloud-vue's
 * schema forms read as a group picker).
 *
 * @param {object} def The property definition.
 * @return {boolean}
 */
export function isGroup(def) {
	return def.referenceType === 'nextcloud-group' || def.format === 'nc-group' || def.widget === 'group'
}

/**
 * The fields in the schema's own order (`order`), those without one after
 * the others in template order, as the board puts the case type first.
 *
 * @param {Array<{key: string}>} fields The fields in template order.
 * @param {object} props The schema's properties.
 * @return {Array<{key: string}>}
 */
export function inSchemaOrder(fields, props) {
	const rank = (field) => (Number.isFinite(props[field.key]?.order) ? props[field.key].order : Number.POSITIVE_INFINITY)
	return fields.map((field, index) => ({ field, index }))
		.sort((a, b) => (rank(a.field) - rank(b.field)) || (a.index - b.index))
		.map(({ field }) => field)
}

/**
 * Whether a property refers to one object of another schema.
 *
 * @param {object} def The property definition.
 * @return {boolean}
 */
export function isRelation(def) {
	return typeof def.$ref === 'string' && def.$ref !== '' && def.type !== 'array' && def.type !== 'object'
}

/**
 * The choices of a relation property: the related objects by name, keeping
 * the current value even when it is not among them.
 *
 * @param {Array<object>} objects The related objects.
 * @param {object} filter The property's `x-relation-filter`; `IS NULL` keeps objects without the field.
 * @param {string} current The current value.
 * @return {Array<{value: string, label: string}>}
 */
export function relationOptions(objects, filter, current) {
	const keep = (object) => Object.entries(filter || {}).every(([field, wanted]) => {
		if (wanted === 'IS NULL') return object[field] === null || object[field] === undefined || object[field] === ''
		if (wanted === 'IS NOT NULL') return object[field] !== null && object[field] !== undefined && object[field] !== ''
		return object[field] === undefined || object[field] === wanted
	})
	const options = objects.filter((object) => keep(object) || (object.id ?? object['@self']?.id) === current).map((object) => {
		const value = String(object.id ?? object['@self']?.id ?? object.uuid ?? '')
		return { value, label: String(object.name || object.title || object['@self']?.name || value) }
	}).filter((option) => option.value !== '')
	if (current && !options.some((option) => option.value === current)) {
		options.unshift({ value: current, label: current })
	}
	return options
}

/**
 * The calendar date of a stored value ("2026-11-23", or a full timestamp) as a
 * local Date, or null when there is none.
 *
 * @param {string|null} value The stored value.
 * @return {Date|null}
 */
export function dateOf(value) {
	const match = /^(\d{4})-(\d{2})-(\d{2})/.exec(String(value || ''))
	return match ? new Date(Number(match[1]), Number(match[2]) - 1, Number(match[3])) : null
}

/**
 * A picked Date as the stored calendar date ("2026-11-23"), or '' when cleared.
 *
 * @param {Date|null} date The picked date.
 * @return {string}
 */
export function isoDateOf(date) {
	if (!(date instanceof Date) || Number.isNaN(date.getTime())) return ''
	const pad = (n) => String(n).padStart(2, '0')
	return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}`
}

/**
 * The one choice a relation or group field shows while its names load: the
 * current value under "Loading…", never its raw id.
 *
 * @param {string} current The current value.
 * @return {Array<{value: string, label: string}>}
 */
export function loadingOptions(current) {
	return current ? [{ value: current, label: t('openregister', 'Loading…') }] : []
}

/**
 * The choices of a group field: each group by its display name, keeping the
 * current value even when it is not among them.
 *
 * @param {Array<{id: string, label: string}>} groups The groups.
 * @param {string} current The current value.
 * @return {Array<{value: string, label: string}>}
 */
export function groupOptions(groups, current) {
	const options = (groups || []).map((group) => ({ value: group.id, label: group.label || group.id }))
	if (current && !options.some((option) => option.value === current)) {
		options.unshift({ value: current, label: current })
	}
	return options
}

/**
 * The display label of an enum value: the schema's `x-enum-labels` map, or
 * the `enumNames` / `enumLabels` list parallel to `enum`, else the value.
 *
 * @param {object} def The property definition.
 * @param {*} value The enum value.
 * @param {number} index The value's place in `enum`.
 * @return {string}
 */
export function enumLabel(def, value, index) {
	const map = def['x-enum-labels'] || def.enumLabels
	if (map && !Array.isArray(map) && typeof map[value] === 'string' && map[value] !== '') return map[value]
	const list = Array.isArray(def.enumNames) ? def.enumNames : (Array.isArray(def.enumLabels) ? def.enumLabels : null)
	if (list && typeof list[index] === 'string' && list[index] !== '') return list[index]
	return String(value)
}

export default {
	name: 'CreateConnectedObjectDialog',
	components: {
		NcButton,
		NcDateTimePicker,
		NcDialog,
		NcLoadingIcon,
		Plus,
	},

	props: {
		show: {
			type: Boolean,
			required: true,
		},

		// The full schema object (title + properties + configuration).
		schema: {
			type: Object,
			default: null,
		},

		// The template applied to this email — the initial field values.
		initialData: {
			type: Object,
			default: () => ({}),
		},

		// The register the object is created in; relation choices are read from it.
		register: {
			type: Object,
			default: null,
		},

		saving: {
			type: Boolean,
			default: false,
		},
	},

	data() {
		return {
			form: {},
			relations: {},
			// The Nextcloud groups a group field offers, as {id, label}.
			groups: [],
			// Whether the group list has been read for this opening.
			groupsLoaded: false,
			// Whether the first focus of this opening has been handled.
			startShown: false,
			// Bumped once the schema owner's catalogue has loaded, so labels re-translate.
			catalogueLoaded: 0,
		}
	},

	computed: {
		/**
		 * @spec openspec/specs/integration-email/spec.md
		 */
		schemaTitle() {
			return this.schema?.title || t('openregister', 'object')
		},

		/**
		 * The schema's title inside a sentence ("New case"), in sentence case.
		 *
		 * @spec openspec/specs/integration-email/spec.md
		 */
		schemaName() {
			return this.schema ? itemName(this.schema) : sentenceName(this.schemaTitle)
		},

		/**
		 * Build the editable field descriptors from the template keys, typed
		 * against the schema's property definitions.
		 *
		 * @spec openspec/specs/integration-email/spec.md
		 */
		fields() {
			const props = this.schema?.properties || {}
			return inSchemaOrder(Object.keys(this.initialData).map((key) => {
				const def = props[key] || {}
				const current = String(this.initialData[key] ?? '')
				let options = (Array.isArray(def.enum) ? def.enum : []).map((value, index) => ({
					value,
					label: this.translateLabel(enumLabel(def, value, index)),
				}))
				if (options.length === 0 && isRelation(def)) {
					options = this.relations[key] || loadingOptions(current)
				}
				if (isGroup(def)) {
					options = this.groupsLoaded ? groupOptions(this.groups, current) : loadingOptions(current)
				}
				return {
					key,
					label: def.title ? this.translateLabel(def.title) : this.humanize(key),
					control: this.controlFor(key, def),
					options,
					required: (this.schema?.required || []).includes(key),
				}
			}), props)
		},
	},

	watch: {
		/**
		 * Reset the working copy whenever the dialog (re)opens for an email.
		 *
		 * @spec openspec/specs/integration-email/spec.md
		 */
		show: {
			immediate: true,
			/**
			 * On open: the template values, the relation choices and the owner's catalogue.
			 *
			 * @param {boolean} open Whether the dialog is open.
			 * @spec openspec/specs/integration-email/spec.md
			 */
			handler(open) {
				if (open) {
					this.form = { ...this.initialData }
					this.startShown = false
					this.loadRelations()
					this.loadOwnerCatalogue()
					this.groupsLoaded = false
					this.loadGroups()
				}
			},
		},
	},

	methods: {
		t,
		dateOf,
		isoDateOf,

		/**
		 * Translate a schema-authored English label (a property title or an
		 * enum label) through the catalogue of the app that owns the register,
		 * then OpenRegister's own; untranslated it stays English.
		 *
		 * @param {string} text The English label.
		 * @return {string}
		 * @spec openspec/specs/integration-email/spec.md
		 */
		translateLabel(text) {
			// Read so a loaded catalogue re-renders the labels.
			void this.catalogueLoaded
			const app = this.register?.application
			if (app && app !== 'openregister') {
				const own = t(app, text)
				if (own !== text) return own
			}
			return t('openregister', text)
		},

		/**
		 * Read the groups a group field offers, by display name, when the
		 * template has one. A failed read keeps the current id as the only choice.
		 *
		 * @spec openspec/specs/integration-email/spec.md
		 */
		async loadGroups() {
			const props = this.schema?.properties || {}
			if (!Object.keys(this.initialData).some((key) => isGroup(props[key] || {}))) return
			try {
				const response = await axios.get(generateOcsUrl('core/autocomplete/get'), {
					params: { search: '', itemType: ' ', itemId: ' ', 'shareTypes[]': 1, limit: 200 },
					headers: { 'OCS-APIRequest': 'true' },
				})
				const data = response.data?.ocs?.data
				this.groups = Array.isArray(data) ? data.filter((entry) => entry.source === 'groups' || entry.source === undefined) : []
			} catch {
				// The current id stays the only choice.
			} finally {
				this.groupsLoaded = true
			}
		},

		/**
		 * The field the dialog focuses as it opens shows the start of its
		 * prefilled value, not its end; later focus is left to the user.
		 *
		 * @param {FocusEvent} event The focus event.
		 * @spec openspec/specs/integration-email/spec.md
		 */
		showFieldStart(event) {
			if (this.startShown) return
			this.startShown = true
			const input = event.target
			if (input?.type === 'text' && typeof input.setSelectionRange === 'function') {
				input.setSelectionRange(0, 0)
				input.scrollLeft = 0
			}
		},

		/**
		 * Load the catalogue of the app that owns the register: Mail's page
		 * carries only OpenRegister's. A missing catalogue (English) is fine.
		 *
		 * @spec openspec/specs/integration-email/spec.md
		 */
		async loadOwnerCatalogue() {
			const app = this.register?.application
			if (!app || app === 'openregister' || typeof loadTranslations !== 'function') return
			try {
				await loadTranslations(app)
				this.catalogueLoaded++
			} catch {
				// No catalogue for this language; the labels stay English.
			}
		},

		/**
		 * @param {string} key
		 * @spec openspec/specs/integration-email/spec.md
		 */
		humanize(key) {
			return key
				.replace(/([A-Z])/g, ' $1')
				.replace(/[_-]+/g, ' ')
				.replace(/^./, (c) => c.toUpperCase())
				.trim()
		},

		/**
		 * Pick an input control for a property based on its schema type.
		 *
		 * @param {string} key
		 * @param {object} def
		 * @spec openspec/specs/integration-email/spec.md
		 */
		controlFor(key, def) {
			const rule = CONTROL_RULES.find(({ applies }) => applies(key, def))
			return rule ? rule.control : 'text'
		},

		/**
		 * Read the choices of every relation field from the register: the
		 * objects of the related schema, by name. A failed read keeps the
		 * current value as the only choice.
		 *
		 * @spec openspec/specs/integration-email/spec.md
		 */
		async loadRelations() {
			const props = this.schema?.properties || {}
			const registerId = this.register?.id
			if (!registerId) return
			await Promise.all(Object.keys(this.initialData).filter((key) => isRelation(props[key] || {})).map(async (key) => {
				const def = props[key]
				const filter = def['x-relation-filter'] || {}
				const params = { _limit: 100 }
				for (const [field, wanted] of Object.entries(filter)) {
					if (wanted !== 'IS NULL' && wanted !== 'IS NOT NULL') params[field] = wanted
				}
				try {
					const response = await axios.get(
						generateUrl('/apps/openregister/api/objects/{register}/{schema}', { register: registerId, schema: def.$ref }),
						{ params },
					)
					const objects = Array.isArray(response.data?.results) ? response.data.results : []
					this.relations = { ...this.relations, [key]: relationOptions(objects, filter, String(this.initialData[key] ?? '')) }
				} catch {
					// The current value stays the only choice.
					this.relations = { ...this.relations, [key]: relationOptions([], {}, String(this.initialData[key] ?? '')) }
				}
			}))
		},

		/**
		 * Return the edited values merged over the full template so fields
		 * that were not surfaced still get their templated values.
		 *
		 * @spec openspec/specs/integration-email/spec.md
		 */
		collect() {
			return { ...this.initialData, ...this.form }
		},
	},
}
</script>

<style scoped>
/* Drawn as the DqNieuweZaak board: the title on the left, an intro line, a
 * two-column grid of fields with "(optional)" after the labels that are not
 * required, and Cancel and Create on the right. */
.or-create-connected-dialog :deep(.modal-container) {
	max-width: 720px;
}

.or-create-connected-dialog__intro {
	margin: 0 0 16px;
	font-size: 14px;
	line-height: 21px;
	color: var(--color-text-maxcontrast);
}

.or-create-connected-dialog__form {
	display: grid;
	grid-template-columns: repeat(2, minmax(0, 1fr));
	gap: 16px 20px;
	max-height: 60vh;
	overflow-y: auto;
	padding: 2px 4px 2px 2px;
}

.or-create-connected-dialog__field {
	display: flex;
	flex-direction: column;
	gap: 6px;
	min-width: 0;
}

.or-create-connected-dialog__field--wide {
	grid-column: 1 / -1;
}

.or-create-connected-dialog__label {
	font-size: 14px;
	font-weight: 600;
	line-height: 20px;
}

.or-create-connected-dialog__optional {
	margin-inline-start: 4px;
	font-weight: 400;
	color: var(--color-text-maxcontrast);
}

.or-create-connected-dialog__input {
	width: 100%;
	min-height: 40px;
	margin: 0;
	box-sizing: border-box;
	border: 1px solid var(--color-border-dark);
	border-radius: var(--border-radius-element, 8px);
	font-size: 15px;
}

textarea.or-create-connected-dialog__input {
	min-height: 96px;
	padding: 8px 12px;
}

.or-create-connected-dialog__date {
	width: 100%;
}

/* Important: the theme's heading sizes outrank a scoped rule. */
.or-create-connected-dialog :deep(.dialog__name) {
	text-align: start;
	font-size: 20px !important;
	line-height: 26px !important;
}

.or-create-connected-dialog :deep(.dialog__actions) {
	justify-content: flex-end;
}

.or-create-connected-dialog :deep(.dialog__actions .button-vue) {
	flex: 0 0 auto;
	width: auto;
	min-height: 40px;
}

@media (max-width: 600px) {
	.or-create-connected-dialog__form {
		grid-template-columns: minmax(0, 1fr);
	}
}
</style>

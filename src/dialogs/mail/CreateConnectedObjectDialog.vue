<template>
	<NcDialog
		v-if="show"
		:name="t('openregister', 'New {name}', { name: schemaTitle })"
		:noClose="saving"
		size="normal"
		class="or-create-connected-dialog"
		@closing="$emit('cancel')">
		<p class="or-create-connected-dialog__intro">
			{{
				t(
					'openregister',
					'Review the details below. The new {name} will be connected to this email.',
					{ name: schemaTitle },
				)
			}}
		</p>

		<div class="or-create-connected-dialog__form">
			<div
				v-for="field in fields"
				:key="field.key"
				class="or-create-connected-dialog__field">
				<label
					:for="`occ-${field.key}`"
					class="or-create-connected-dialog__label">
					{{ field.label }}
				</label>

				<select
					v-if="field.control === 'select'"
					:id="`occ-${field.key}`"
					v-model="form[field.key]"
					class="or-create-connected-dialog__input">
					<option v-for="opt in field.options" :key="opt.value" :value="opt.value">
						{{ opt.label }}
					</option>
				</select>

				<textarea
					v-else-if="field.control === 'textarea'"
					:id="`occ-${field.key}`"
					v-model="form[field.key]"
					rows="4"
					class="or-create-connected-dialog__input" />

				<input
					v-else-if="field.control === 'checkbox'"
					:id="`occ-${field.key}`"
					v-model="form[field.key]"
					type="checkbox" />

				<input
					v-else
					:id="`occ-${field.key}`"
					v-model="form[field.key]"
					:type="field.control"
					class="or-create-connected-dialog__input" />
			</div>
		</div>

		<template #actions>
			<CloseCancelButton :label="t('openregister', 'Cancel')" :disabled="saving" @click="$emit('cancel')" />
			<NcButton
				:disabled="saving"
				variant="primary"
				@click="$emit('confirm', collect())">
				<template #icon>
					<NcLoadingIcon v-if="saving" :size="20" />
					<Plus v-else :size="20" />
				</template>
				{{ t('openregister', 'Create & connect') }}
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
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { NcButton, NcDialog, NcLoadingIcon } from '@nextcloud/vue'
import Plus from 'vue-material-design-icons/Plus.vue'
import CloseCancelButton from '../../components/shared/CloseCancelButton.vue'

/**
 * The form control for a property, by the first rule that applies to its key
 * and definition; a property no rule claims is plain text.
 */
const CONTROL_RULES = [
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

export default {
	name: 'CreateConnectedObjectDialog',
	components: {
		NcButton,
		NcDialog,
		NcLoadingIcon,
		CloseCancelButton,
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
		 * Build the editable field descriptors from the template keys, typed
		 * against the schema's property definitions.
		 *
		 * @spec openspec/specs/integration-email/spec.md
		 */
		fields() {
			const props = this.schema?.properties || {}
			return Object.keys(this.initialData).map((key) => {
				const def = props[key] || {}
				const current = String(this.initialData[key] ?? '')
				let options = (Array.isArray(def.enum) ? def.enum : []).map((value) => ({ value, label: String(value) }))
				if (options.length === 0 && isRelation(def)) {
					options = this.relations[key] || relationOptions([], {}, current)
				}
				return {
					key,
					label: def.title || this.humanize(key),
					control: this.controlFor(key, def),
					options,
				}
			})
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
			handler(open) {
				if (open) {
					this.form = { ...this.initialData }
					this.loadRelations()
				}
			},
		},
	},

	methods: {
		t,
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
.or-create-connected-dialog__intro {
	margin-bottom: 12px;
	color: var(--color-text-maxcontrast);
}

.or-create-connected-dialog__form {
	display: flex;
	flex-direction: column;
	gap: 12px;
	max-height: 50vh;
	overflow-y: auto;
	padding-right: 4px;
}

.or-create-connected-dialog__field {
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.or-create-connected-dialog__label {
	font-size: 13px;
	font-weight: 600;
}

.or-create-connected-dialog__input {
	width: 100%;
	box-sizing: border-box;
}
</style>

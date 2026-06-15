<template>
	<NcDialog
		v-if="show"
		:name="t('openregister', 'New {name}', { name: schemaTitle })"
		:can-close="!saving"
		size="normal"
		class="or-create-connected-dialog"
		@closing="$emit('cancel')">
		<p class="or-create-connected-dialog__intro">
			{{ t('openregister', 'Review the details below. The new {name} will be connected to this email.', { name: schemaTitle }) }}
		</p>

		<div class="or-create-connected-dialog__form">
			<div
				v-for="field in fields"
				:key="field.key"
				class="or-create-connected-dialog__field">
				<label :for="`occ-${field.key}`" class="or-create-connected-dialog__label">
					{{ field.label }}
				</label>

				<select
					v-if="field.control === 'select'"
					:id="`occ-${field.key}`"
					v-model="form[field.key]"
					class="or-create-connected-dialog__input">
					<option v-for="opt in field.options" :key="opt" :value="opt">{{ opt }}</option>
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
					type="checkbox">

				<input
					v-else
					:id="`occ-${field.key}`"
					v-model="form[field.key]"
					:type="field.control"
					class="or-create-connected-dialog__input">
			</div>
		</div>

		<template #actions>
			<NcButton :disabled="saving" @click="$emit('cancel')">
				<template #icon>
					<Cancel :size="20" />
				</template>
				{{ t('openregister', 'Cancel') }}
			</NcButton>
			<NcButton
				:disabled="saving"
				type="primary"
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
 * creates the object and connects the email to it). Lives in its own file
 * per ADR-004 modal-isolation.
 *
 * @spec openspec/changes/integration-email/tasks.md
 */
import { translate as t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/dist/Components/NcButton.js'
import NcDialog from '@nextcloud/vue/dist/Components/NcDialog.js'
import NcLoadingIcon from '@nextcloud/vue/dist/Components/NcLoadingIcon.js'

import Cancel from 'vue-material-design-icons/Cancel.vue'
import Plus from 'vue-material-design-icons/Plus.vue'

export default {
	name: 'CreateConnectedObjectDialog',
	components: {
		NcButton,
		NcDialog,
		NcLoadingIcon,
		Cancel,
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
		saving: {
			type: Boolean,
			default: false,
		},
	},
	data() {
		return {
			form: {},
		}
	},
	computed: {
		/**
		 * @spec openspec/changes/integration-email/tasks.md
		 */
		schemaTitle() {
			return this.schema?.title || t('openregister', 'object')
		},
		/**
		 * Build the editable field descriptors from the template keys, typed
		 * against the schema's property definitions.
		 *
		 * @spec openspec/changes/integration-email/tasks.md
		 */
		fields() {
			const props = this.schema?.properties || {}
			return Object.keys(this.initialData).map((key) => {
				const def = props[key] || {}
				return {
					key,
					label: def.title || this.humanize(key),
					control: this.controlFor(key, def),
					options: def.enum || [],
				}
			})
		},
	},
	watch: {
		/**
		 * Reset the working copy whenever the dialog (re)opens for an email.
		 *
		 * @spec openspec/changes/integration-email/tasks.md
		 */
		show: {
			immediate: true,
			handler(open) {
				if (open) {
					this.form = { ...this.initialData }
				}
			},
		},
	},
	methods: {
		t,
		/**
		 * @spec openspec/changes/integration-email/tasks.md
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
		 * @spec openspec/changes/integration-email/tasks.md
		 */
		controlFor(key, def) {
			if (Array.isArray(def.enum) && def.enum.length) {
				return 'select'
			}
			if (def.type === 'boolean') {
				return 'checkbox'
			}
			if (def.type === 'number' || def.type === 'integer') {
				return 'number'
			}
			if (def.format === 'date') {
				return 'date'
			}
			// Multi-line for the obviously long fields.
			if (/notes|description|body|message|summary|content/i.test(key)) {
				return 'textarea'
			}
			return 'text'
		},
		/**
		 * Return the edited values merged over the full template so fields
		 * that were not surfaced still get their templated values.
		 *
		 * @spec openspec/changes/integration-email/tasks.md
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

<template>
	<div class="token-grant-editor">
		<fieldset class="token-grant-editor__verbs">
			<legend>{{ t('openregister', 'Allowed actions') }}</legend>
			<label v-for="verb in verbs" :key="verb.id" class="token-grant-editor__verb">
				<input
					v-model="form.verbs"
					type="checkbox"
					:value="verb.id"
					:data-verb="verb.id">
				{{ verb.label }}
			</label>
			<p v-if="errors.includes('verbs')" class="token-grant-editor__error" role="alert">
				{{ t('openregister', 'Choose at least one action') }}
			</p>
		</fieldset>

		<label class="token-grant-editor__field">
			<span>{{ t('openregister', 'Registers') }}</span>
			<input v-model="form.registers" type="text" data-field="registers" :aria-describedby="slugHintId">
		</label>
		<label class="token-grant-editor__field">
			<span>{{ t('openregister', 'Schemas') }}</span>
			<input v-model="form.schemas" type="text" data-field="schemas" :aria-describedby="slugHintId">
		</label>
		<p :id="slugHintId" class="token-grant-editor__hint">
			{{ t('openregister', 'Comma-separated slugs; leave empty for all') }}
		</p>

		<label class="token-grant-editor__field">
			<span>{{ t('openregister', 'Row condition (JSON)') }}</span>
			<textarea v-model="form.match" rows="3" data-field="match" />
		</label>
		<p v-if="errors.includes('match')" class="token-grant-editor__error" role="alert">
			{{ t('openregister', 'The row condition is not valid JSON') }}
		</p>

		<label class="token-grant-editor__field">
			<span>{{ t('openregister', 'End date') }}</span>
			<input v-model="form.expiresAt" type="date" required data-field="expiresAt">
		</label>
		<p v-if="errors.includes('expiresAt')" class="token-grant-editor__error" role="alert">
			{{ t('openregister', 'An end date is required') }}
		</p>

		<label class="token-grant-editor__field">
			<span>{{ t('openregister', 'Calls per minute') }}</span>
			<input v-model="form.rateLimit" type="number" min="1" step="1" data-field="rateLimit">
		</label>

		<div class="token-grant-editor__actions">
			<NcButton variant="primary" :disabled="busy" data-action="save" @click="save">
				{{ t('openregister', 'Save') }}
			</NcButton>
			<NcButton v-if="removable" variant="error" :disabled="busy" data-action="remove" @click="$emit('remove')">
				{{ t('openregister', 'Remove grant') }}
			</NcButton>
		</div>

		<section v-if="previewable" class="token-grant-editor__preview">
			<h4>{{ t('openregister', 'Preview on an object') }}</h4>
			<div class="token-grant-editor__preview-fields">
				<label class="token-grant-editor__field">
					<span>{{ t('openregister', 'Register') }}</span>
					<input v-model="target.register" type="text" data-field="previewRegister">
				</label>
				<label class="token-grant-editor__field">
					<span>{{ t('openregister', 'Schema') }}</span>
					<input v-model="target.schema" type="text" data-field="previewSchema">
				</label>
				<label class="token-grant-editor__field">
					<span>{{ t('openregister', 'Object ID') }}</span>
					<input v-model="target.object" type="text" data-field="previewObject">
				</label>
			</div>
			<NcButton :disabled="busy || !targetComplete" data-action="preview" @click="preview">
				{{ t('openregister', 'Preview') }}
			</NcButton>

			<p v-if="previewResult && previewResult.expired" class="token-grant-editor__error" role="alert">
				{{ t('openregister', 'This grant has lapsed, so the token is refused.') }}
			</p>
			<table v-if="previewResult" class="token-grant-editor__preview-table">
				<thead>
					<tr>
						<th scope="col">
							{{ t('openregister', 'Action') }}
						</th>
						<th scope="col">
							{{ t('openregister', 'You') }}
						</th>
						<th scope="col">
							{{ t('openregister', 'Token') }}
						</th>
					</tr>
				</thead>
				<tbody>
					<tr v-for="row in previewResult.verbs" :key="row.verb" :data-preview-verb="row.verb">
						<td>{{ row.verb }}</td>
						<td>{{ row.holder ? t('openregister', 'Yes') : t('openregister', 'No') }}</td>
						<td>{{ row.token ? t('openregister', 'Yes') : t('openregister', 'No') }}</td>
					</tr>
				</tbody>
			</table>
		</section>
	</div>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import { NcButton } from '@nextcloud/vue'
import { formFromGrant, GRANTABLE_VERBS, grantFromForm } from '../../services/tokenGrant.js'

let editorCount = 0

export default {
	name: 'TokenGrantEditor',
	components: { NcButton },

	props: {
		/** The stored grant, or null for a new one. */
		grant: { type: Object, default: null },
		/** Whether the preview on an object is offered. */
		previewable: { type: Boolean, default: false },
		/** The last preview answer: `{verbs: [{verb, holder, token}], expired}`. */
		previewResult: { type: Object, default: null },
		/** Whether a save, removal or preview is under way. */
		busy: { type: Boolean, default: false },
		/** Whether the grant can be removed. */
		removable: { type: Boolean, default: false },
	},

	emits: ['save', 'remove', 'preview'],

	data() {
		editorCount += 1
		return {
			form: formFromGrant(this.grant),
			errors: [],
			target: { register: '', schema: '', object: '' },
			slugHintId: `token-grant-slugs-${editorCount}`,
		}
	},

	computed: {
		verbs() {
			const labels = {
				read: t('openregister', 'Read'),
				create: t('openregister', 'Create'),
				update: t('openregister', 'Update'),
				delete: t('openregister', 'Delete'),
				share: t('openregister', 'Share'),
			}
			return GRANTABLE_VERBS.map((id) => ({ id, label: labels[id] }))
		},

		targetComplete() {
			return Object.values(this.target).every((value) => String(value).trim() !== '')
		},
	},

	watch: {
		grant(grant) {
			this.form = formFromGrant(grant)
			this.errors = []
		},
	},

	methods: {
		t,
		/**
		 * The grant the form describes, or null after showing why it cannot be saved.
		 *
		 * @spec openspec/changes/scoped-api-tokens/specs/auth-system/spec.md#requirement-a-token-carries-a-required-end-date-and-is-warned-before-it-lapses-req-sat-003
		 * @return {object|null} the grant
		 */
		validGrant() {
			const { grant, errors } = grantFromForm(this.form)
			this.errors = errors
			return grant
		},

		/**
		 * Emit the grant to save.
		 *
		 * @spec openspec/changes/scoped-api-tokens/specs/auth-system/spec.md#requirement-a-token-or-consumer-may-carry-a-grant-narrower-than-its-user
		 * @return {void}
		 */
		save() {
			const grant = this.validGrant()
			if (grant !== null) this.$emit('save', grant)
		},

		/**
		 * Emit the grant and the chosen object to preview.
		 *
		 * @spec openspec/changes/scoped-api-tokens/specs/auth-system/spec.md#requirement-a-token-or-consumer-may-carry-a-grant-narrower-than-its-user
		 * @return {void}
		 */
		preview() {
			const grant = this.validGrant()
			if (grant !== null) {
				this.$emit('preview', {
					grant,
					register: this.target.register.trim(),
					schema: this.target.schema.trim(),
					object: this.target.object.trim(),
				})
			}
		},
	},
}
</script>

<style scoped>
.token-grant-editor {
	display: flex;
	flex-direction: column;
	gap: 8px;
}

.token-grant-editor__verbs {
	display: flex;
	flex-wrap: wrap;
	gap: 12px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	padding: 8px 12px;
}

.token-grant-editor__verb {
	display: inline-flex;
	align-items: center;
	gap: 4px;
}

.token-grant-editor__field {
	display: flex;
	flex-direction: column;
	gap: 2px;
}

.token-grant-editor__hint {
	margin: 0;
	color: var(--color-text-maxcontrast);
	font-size: 0.9em;
}

.token-grant-editor__error {
	margin: 0;
	color: var(--color-text-error, var(--color-error));
}

.token-grant-editor__actions {
	display: flex;
	gap: 8px;
}

.token-grant-editor__preview {
	border-top: 1px solid var(--color-border);
	padding-top: 8px;
}

.token-grant-editor__preview-fields {
	display: flex;
	flex-wrap: wrap;
	gap: 8px;
}

.token-grant-editor__preview-table {
	margin-top: 8px;
	border-collapse: collapse;
}

.token-grant-editor__preview-table th,
.token-grant-editor__preview-table td {
	padding: 4px 12px 4px 0;
	text-align: start;
}
</style>

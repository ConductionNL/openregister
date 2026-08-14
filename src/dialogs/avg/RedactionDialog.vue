<template>
	<NcDialog
		:name="t('openregister', 'Apply redaction')"
		size="normal"
		:can-close="!saving"
		@closing="$emit('close')">
		<form class="redactionForm" @submit.prevent="onSubmit">
			<p class="redactionIntro">
				{{
					t(
						'openregister',
						'Record a field-level redaction on this case. The redaction and its ground are logged in the case audit trail.',
					)
				}}
			</p>

			<NcTextField
				v-model="form.field"
				:label="t('openregister', 'Field to redact *')"
				required />

			<NcTextField
				v-model="form.after"
				:label="
					t(
						'openregister',
						'Replacement value (leave empty to blank the field)',
					)
				" />

			<NcSelect
				v-model="form.ground"
				:options="groundOptions"
				:label-outside="false"
				:input-label="t('openregister', 'Redaction ground')"
				:aria-label-combobox="t('openregister', 'Redaction ground')"
				:reduce="(o) => o.value"
				required />

			<NcNoteCard v-if="error" type="error">
				{{ error }}
			</NcNoteCard>

			<div class="redactionActions">
				<NcButton
					variant="tertiary"
					:disabled="saving"
					@click="$emit('close')">
					{{ t('openregister', 'Cancel') }}
				</NcButton>
				<NcButton
					variant="primary"
					type="submit"
					:disabled="saving || !form.field || !form.ground">
					<template #icon>
						<NcLoadingIcon v-if="saving" :size="20" />
					</template>
					{{ t('openregister', 'Apply redaction') }}
				</NcButton>
			</div>
		</form>
	</NcDialog>
</template>

<script>
import {
	NcDialog,
	NcTextField,
	NcSelect,
	NcButton,
	NcLoadingIcon,
	NcNoteCard,
} from '@nextcloud/vue'

import { avgStore } from '../../store/store.js'
import { resolveGroundOptions } from '../../store/modules/avg.js'

export default {
	name: 'RedactionDialog',

	components: {
		NcDialog,
		NcTextField,
		NcSelect,
		NcButton,
		NcLoadingIcon,
		NcNoteCard,
	},

	props: {
		caseId: {
			type: String,
			required: true,
		},
		pack: {
			type: Object,
			default: null,
		},
	},

	emits: ['close', 'redacted'],

	data() {
		return {
			form: {
				field: '',
				after: '',
				ground: '',
			},
			saving: false,
			error: null,
		}
	},

	computed: {
		/**
		 * @spec exclude Presentation glue: reuses the pack's denial-grounds enum as the redaction-ground options.
		 */
		groundOptions() {
			return resolveGroundOptions(this.pack)
		},
	},

	methods: {
		/**
		 * Apply the field-level redaction via the store.
		 *
		 * @spec exclude UI plumbing — delegates to avgStore.applyRedaction.
		 * @return {Promise<void>}
		 */
		async onSubmit() {
			if (!this.form.field || !this.form.ground) return
			this.saving = true
			this.error = null
			try {
				await avgStore.applyRedaction(this.caseId, {
					field: this.form.field,
					after: this.form.after,
					ground: this.form.ground,
				})
				this.$emit('redacted')
			} catch (e) {
				this.error = avgStore.getError ?? e.message ?? 'Redaction failed'
			} finally {
				this.saving = false
			}
		},
	},
}
</script>

<style scoped>
.redactionForm {
	display: flex;
	flex-direction: column;
	gap: 12px;
	padding: 16px;
}

.redactionIntro {
	color: var(--color-text-maxcontrast);
}

.redactionActions {
	display: flex;
	justify-content: flex-end;
	gap: 8px;
	margin-top: 8px;
}
</style>

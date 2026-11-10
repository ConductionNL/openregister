<template>
	<NcDialog
		:name="t('openregister', 'Apply redaction')"
		size="normal"
		:noClose="saving"
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
				:labelOutside="false"
				:inputLabel="t('openregister', 'Redaction ground')"
				:aria-label-combobox="t('openregister', 'Redaction ground')"
				:reduce="(o) => o.value"
				required />

			<AvgFormFooter
				:error="error"
				:saving="saving"
				:incomplete="!form.field || !form.ground"
				:submitLabel="t('openregister', 'Apply redaction')"
				@close="$emit('close')" />
		</form>
	</NcDialog>
</template>

<script>
import {
	NcDialog,
	NcSelect,
	NcTextField,
} from '@nextcloud/vue'
import AvgFormFooter from './AvgFormFooter.vue'
import { resolveGroundOptions } from '../../store/modules/avg.js'
import { avgStore } from '../../store/store.js'

export default {
	name: 'RedactionDialog',

	components: {
		AvgFormFooter,
		NcDialog,
		NcTextField,
		NcSelect,
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
				await avgStore.applyRedaction(this.caseId, this.redaction())
				this.$emit('redacted')
			} catch (e) {
				this.error = avgStore.getError ?? e.message ?? 'Redaction failed'
			} finally {
				this.saving = false
			}
		},

		/**
		 * The redaction the form describes: the field, its new value and the
		 * ground for it.
		 *
		 * @return {{field: string, after: string, ground: string}} The redaction
		 */
		redaction() {
			return {
				field: this.form.field,
				after: this.form.after,
				ground: this.form.ground,
			}
		},
	},
}
</script>

<style scoped lang="scss">
@use '../../styles/dialogs.scss' as *;

.redactionForm {
	display: flex;
	flex-direction: column;
	gap: 12px;
	padding: 16px;
}

.redactionIntro {
	color: var(--color-text-maxcontrast);
}
</style>

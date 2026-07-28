<template>
	<NcDialog
		:name="dialogTitle"
		size="large"
		:can-close="!saving"
		@closing="$emit('close')">
		<form class="avgEditForm" @submit.prevent="onSave">
			<NcTextField
				:value.sync="form.naam"
				:label="t('openregister', 'Name *')"
				required />

			<NcTextField
				:value.sync="form.code"
				:label="t('openregister', 'Code (short readable key, e.g. v-2026-001)')" />

			<label class="avgField">
				<span>{{ t('openregister', 'Description') }}</span>
				<textarea v-model="form.beschrijving" rows="3" class="avgTextarea" />
			</label>

			<label class="avgField">
				<span>{{ t('openregister', 'Purpose limitation *') }}</span>
				<textarea v-model="form.doelbinding"
					rows="3"
					class="avgTextarea"
					required />
			</label>

			<NcSelect
				v-model="form.rechtsgrond"
				:options="rechtsgrondOptions"
				:label-outside="false"
				:input-label="t('openregister', 'Legal basis *')"
				:reduce="(o) => o.value"
				required />

			<NcTextField
				:value.sync="form.bewaartermijn"
				:label="t('openregister', 'Retention period (ISO-8601 duration, e.g. P10Y, P30D)')" />

			<NcSelect
				v-model="form.status"
				:options="statusOptions"
				:label-outside="false"
				input-label="Status"
				:reduce="(o) => o.value" />

			<label class="avgField">
				<span>{{ t('openregister', 'Categories of data subjects (one per line)') }}</span>
				<textarea v-model="categorieenBetrokkenenText" rows="3" class="avgTextarea" />
			</label>

			<label class="avgField">
				<span>{{ t('openregister', 'Categories of personal data (one per line)') }}</span>
				<textarea v-model="categorieenPersoonsgegevensText" rows="3" class="avgTextarea" />
			</label>

			<label class="avgField">
				<span>{{ t('openregister', 'Technical measures') }}</span>
				<textarea v-model="form.technischeMaatregelen" rows="3" class="avgTextarea" />
			</label>

			<label class="avgField">
				<span>{{ t('openregister', 'Organisational measures') }}</span>
				<textarea v-model="form.organisatorischeMaatregelen" rows="3" class="avgTextarea" />
			</label>

			<NcNoteCard v-if="error" type="error">
				{{ error }}
			</NcNoteCard>

			<div class="avgEditActions">
				<NcButton type="tertiary" :disabled="saving" @click="$emit('close')">
					{{ t('openregister', 'Cancel') }}
				</NcButton>
				<NcButton type="primary" native-type="submit" :disabled="saving">
					<template #icon>
						<NcLoadingIcon v-if="saving" :size="20" />
					</template>
					{{ activity ? t('openregister', 'Save changes') : t('openregister', 'Create') }}
				</NcButton>
			</div>
		</form>
	</NcDialog>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import {
	NcDialog,
	NcTextField,
	NcButton,
	NcSelect,
	NcLoadingIcon,
	NcNoteCard,
} from '@nextcloud/vue'

import { avgStore } from '../../store/store.js'
import { RECHTSGROND_VOCABULARY, STATUS_VOCABULARY } from '../../store/modules/avg.js'

export default {
	name: 'EditActivityDialog',

	components: {
		NcDialog,
		NcTextField,
		NcButton,
		NcSelect,
		NcLoadingIcon,
		NcNoteCard,
	},

	props: {
		activity: {
			type: Object,
			default: null,
		},
	},

	emits: ['close', 'saved'],

	data() {
		return {
			form: this.makeForm(this.activity),
			saving: false,
			error: null,
		}
	},

	computed: {
		/**
		 * @spec exclude Presentation glue: edit-vs-create dialog title string; no standalone behavioural contract.
		 */
		dialogTitle() {
			return this.activity
				? t('openregister', 'Edit processing activity')
				: t('openregister', 'New processing activity')
		},
		/**
		 * @spec exclude Presentation glue: maps the rechtsgrond vocabulary to select options; no standalone behavioural contract.
		 */
		rechtsgrondOptions() {
			return RECHTSGROND_VOCABULARY.map((v) => ({ value: v, label: v.replace(/_/g, ' ') }))
		},
		/**
		 * @spec exclude Presentation glue: maps the status vocabulary to select options; no standalone behavioural contract.
		 */
		statusOptions() {
			return STATUS_VOCABULARY.map((v) => ({ value: v, label: v }))
		},
		categorieenBetrokkenenText: {
			/**
			 * @spec exclude Presentation glue: textarea getter joining a string array into newline-separated text; no standalone behavioural contract.
			 */
			get() {
				return Array.isArray(this.form.categorieenBetrokkenen)
					? this.form.categorieenBetrokkenen.join('\n')
					: ''
			},
			/**
			 * @param value
			 * @spec exclude Presentation glue: textarea setter splitting newline text into a trimmed string array; no standalone behavioural contract.
			 */
			set(value) {
				this.form.categorieenBetrokkenen = (value ?? '')
					.split('\n')
					.map((s) => s.trim())
					.filter((s) => s !== '')
			},
		},
		categorieenPersoonsgegevensText: {
			/**
			 * @spec exclude Presentation glue: textarea getter joining a string array into newline-separated text; no standalone behavioural contract.
			 */
			get() {
				return Array.isArray(this.form.categorieenPersoonsgegevens)
					? this.form.categorieenPersoonsgegevens.join('\n')
					: ''
			},
			/**
			 * @param value
			 * @spec exclude Presentation glue: textarea setter splitting newline text into a trimmed string array; no standalone behavioural contract.
			 */
			set(value) {
				this.form.categorieenPersoonsgegevens = (value ?? '')
					.split('\n')
					.map((s) => s.trim())
					.filter((s) => s !== '')
			},
		},
	},

	methods: {
		/**
		 * Seed the form from an existing activity or with Art-30 defaults.
		 *
		 * @param activity
		 * @spec openspec/specs/avg-verwerkingsregister/spec.md
		 */
		makeForm(activity) {
			return {
				naam: activity?.naam ?? '',
				code: activity?.code ?? '',
				beschrijving: activity?.beschrijving ?? '',
				doelbinding: activity?.doelbinding ?? '',
				rechtsgrond: activity?.rechtsgrond ?? 'publieke_taak',
				bewaartermijn: activity?.bewaartermijn ?? '',
				status: activity?.status ?? 'concept',
				categorieenBetrokkenen: activity?.categorieenBetrokkenen ?? [],
				categorieenPersoonsgegevens: activity?.categorieenPersoonsgegevens ?? [],
				technischeMaatregelen: activity?.technischeMaatregelen ?? '',
				organisatorischeMaatregelen: activity?.organisatorischeMaatregelen ?? '',
			}
		},

		/**
		 * Strip empty optional fields before writing the activity.
		 *
		 * @spec openspec/specs/avg-verwerkingsregister/spec.md
		 */
		buildPayload() {
			const payload = { ...this.form }
			// Strip empty optional fields so we don't override server-side defaults.
			Object.keys(payload).forEach((k) => {
				if (payload[k] === '' || payload[k] === null) delete payload[k]
			})
			return payload
		},

		/**
		 * Dispatch create vs update of the processing activity against avgStore.
		 *
		 * @spec openspec/specs/avg-verwerkingsregister/spec.md
		 */
		async onSave() {
			this.saving = true
			this.error = null
			try {
				if (this.activity) {
					await avgStore.updateActivity(this.activity.uuid, this.buildPayload())
				} else {
					await avgStore.createActivity(this.buildPayload())
				}
				this.$emit('saved')
			} catch (e) {
				this.error = avgStore.getError ?? e.message ?? 'Save failed'
			} finally {
				this.saving = false
			}
		},
	},
}
</script>

<style scoped>
.avgEditForm {
	display: flex;
	flex-direction: column;
	gap: 12px;
	padding: 16px;
}
.avgField {
	display: flex;
	flex-direction: column;
	gap: 4px;
}
.avgField span {
	font-weight: 600;
	color: var(--color-text-maxcontrast);
}
.avgTextarea {
	width: 100%;
	min-height: 60px;
	padding: 8px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	font: inherit;
	resize: vertical;
}
.avgEditActions {
	display: flex;
	justify-content: flex-end;
	gap: 8px;
	margin-top: 8px;
}
</style>

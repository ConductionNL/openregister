<template>
	<NcDialog
		:name="dialogTitle"
		size="large"
		:can-close="!saving"
		@closing="$emit('close')">
		<form class="avgEditForm" @submit.prevent="onSave">
			<NcTextField
				:value.sync="form.naam"
				:label="t('openregister', 'Naam *')"
				required />

			<NcTextField
				:value.sync="form.code"
				:label="t('openregister', 'Code (short readable key, e.g. v-2026-001)')" />

			<label class="avgField">
				<span>{{ t('openregister', 'Beschrijving') }}</span>
				<textarea v-model="form.beschrijving" rows="3" class="avgTextarea" />
			</label>

			<label class="avgField">
				<span>{{ t('openregister', 'Doelbinding *') }}</span>
				<textarea v-model="form.doelbinding" rows="3" class="avgTextarea" required />
			</label>

			<NcSelect
				v-model="form.rechtsgrond"
				:options="rechtsgrondOptions"
				:label-outside="false"
				input-label="Rechtsgrond *"
				:reduce="(o) => o.value"
				required />

			<NcTextField
				:value.sync="form.bewaartermijn"
				:label="t('openregister', 'Bewaartermijn (ISO-8601 duration, e.g. P10Y, P30D)')" />

			<NcSelect
				v-model="form.status"
				:options="statusOptions"
				:label-outside="false"
				input-label="Status"
				:reduce="(o) => o.value" />

			<label class="avgField">
				<span>{{ t('openregister', 'Categorieën betrokkenen (één per regel)') }}</span>
				<textarea v-model="categorieenBetrokkenenText" rows="3" class="avgTextarea" />
			</label>

			<label class="avgField">
				<span>{{ t('openregister', 'Categorieën persoonsgegevens (één per regel)') }}</span>
				<textarea v-model="categorieenPersoonsgegevensText" rows="3" class="avgTextarea" />
			</label>

			<label class="avgField">
				<span>{{ t('openregister', 'Technische maatregelen') }}</span>
				<textarea v-model="form.technischeMaatregelen" rows="3" class="avgTextarea" />
			</label>

			<label class="avgField">
				<span>{{ t('openregister', 'Organisatorische maatregelen') }}</span>
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
		t() {
			return t
		},
		dialogTitle() {
			return this.activity
				? t('openregister', 'Edit verwerkingsactiviteit')
				: t('openregister', 'New verwerkingsactiviteit')
		},
		rechtsgrondOptions() {
			return RECHTSGROND_VOCABULARY.map((v) => ({ value: v, label: v.replace(/_/g, ' ') }))
		},
		statusOptions() {
			return STATUS_VOCABULARY.map((v) => ({ value: v, label: v }))
		},
		categorieenBetrokkenenText: {
			get() {
				return Array.isArray(this.form.categorieenBetrokkenen)
					? this.form.categorieenBetrokkenen.join('\n')
					: ''
			},
			set(value) {
				this.form.categorieenBetrokkenen = (value ?? '')
					.split('\n')
					.map((s) => s.trim())
					.filter((s) => s !== '')
			},
		},
		categorieenPersoonsgegevensText: {
			get() {
				return Array.isArray(this.form.categorieenPersoonsgegevens)
					? this.form.categorieenPersoonsgegevens.join('\n')
					: ''
			},
			set(value) {
				this.form.categorieenPersoonsgegevens = (value ?? '')
					.split('\n')
					.map((s) => s.trim())
					.filter((s) => s !== '')
			},
		},
	},

	methods: {
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

		buildPayload() {
			const payload = { ...this.form }
			// Strip empty optional fields so we don't override server-side defaults.
			Object.keys(payload).forEach((k) => {
				if (payload[k] === '' || payload[k] === null) delete payload[k]
			})
			return payload
		},

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

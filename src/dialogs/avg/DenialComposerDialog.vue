<template>
	<NcDialog
		:name="t('openregister', 'Compose denial')"
		size="normal"
		:can-close="!saving"
		@closing="$emit('close')">
		<form class="denialForm" @submit.prevent="onSubmit">
			<p class="denialIntro">
				{{ t('openregister', 'Record a proposed denial ground for this request. Drafting does not require a regulator reference; finalising the denial does.') }}
			</p>

			<NcSelect
				v-model="ground"
				:options="groundOptions"
				:label-outside="false"
				:input-label="t('openregister', 'Denial ground')"
				:aria-label-combobox="t('openregister', 'Denial ground')"
				:reduce="(o) => o.value"
				required />

			<p v-if="selectedGround && selectedGround.citation" class="denialCitation">
				<span class="denialCitationLabel">{{ t('openregister', 'Statutory citation') }}:</span>
				{{ selectedGround.citation }}
			</p>

			<div v-if="templateRef" class="denialTemplate">
				<span class="denialTemplateLabel">{{ t('openregister', 'Denial letter template') }}:</span>
				<code>{{ templateRef }}</code>
				<p class="denialTemplateHint">
					{{ t('openregister', 'The letter body is rendered from this template reference (a document leaf); it is not stored in this form.') }}
				</p>
			</div>
			<p v-else class="denialTemplateHint">
				{{ t('openregister', 'The active policy pack supplies no denial-letter template reference for this jurisdiction.') }}
			</p>

			<NcNoteCard v-if="error" type="error">
				{{ error }}
			</NcNoteCard>

			<div class="denialActions">
				<NcButton variant="tertiary" :disabled="saving" @click="$emit('close')">
					{{ t('openregister', 'Cancel') }}
				</NcButton>
				<NcButton variant="primary" type="submit" :disabled="saving || !ground">
					<template #icon>
						<NcLoadingIcon v-if="saving" :size="20" />
					</template>
					{{ t('openregister', 'Draft denial') }}
				</NcButton>
			</div>
		</form>
	</NcDialog>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import {
	NcDialog,
	NcSelect,
	NcButton,
	NcLoadingIcon,
	NcNoteCard,
} from '@nextcloud/vue'

import { avgStore } from '../../store/store.js'
import { resolveGroundOptions, resolveTemplateRef } from '../../store/modules/avg.js'

export default {
	name: 'DenialComposerDialog',

	components: {
		NcDialog,
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

	emits: ['close', 'drafted'],

	data() {
		return {
			ground: '',
			saving: false,
			error: null,
		}
	},

	computed: {
		/**
		 * @spec exclude Presentation glue: exposes the translate helper to the template; no standalone behavioural contract.
		 */
		t() {
			return t
		},
		/**
		 * @spec exclude Presentation glue: maps the active pack's denial-grounds enum to NcSelect options; resolution owned by the store helper.
		 */
		groundOptions() {
			return resolveGroundOptions(this.pack)
		},
		/**
		 * @spec exclude Presentation glue: looks up the selected option for the citation display.
		 */
		selectedGround() {
			return this.groundOptions.find((o) => o.value === this.ground) ?? null
		},
		/**
		 * @spec exclude Presentation glue: resolves the denial-letter template reference (leaf) from the pack.
		 */
		templateRef() {
			return resolveTemplateRef(this.pack, 'denial')
		},
	},

	methods: {
		/**
		 * Draft the denial: record the selected ground and post the
		 * draftDenial transition via the store.
		 *
		 * @spec exclude UI plumbing — delegates to avgStore.draftDenial.
		 * @return {Promise<void>}
		 */
		async onSubmit() {
			if (!this.ground) return
			this.saving = true
			this.error = null
			try {
				await avgStore.draftDenial(this.caseId, this.ground)
				this.$emit('drafted')
			} catch (e) {
				this.error = avgStore.getError ?? e.message ?? 'Draft failed'
			} finally {
				this.saving = false
			}
		},
	},
}
</script>

<style scoped>
.denialForm {
	display: flex;
	flex-direction: column;
	gap: 12px;
	padding: 16px;
}

.denialIntro {
	color: var(--color-text-maxcontrast);
}

.denialCitation {
	background: var(--color-background-hover);
	border-radius: var(--border-radius);
	padding: 8px 12px;
}

.denialCitationLabel,
.denialTemplateLabel {
	font-weight: 600;
}

.denialTemplate {
	background: var(--color-background-hover);
	border-radius: var(--border-radius);
	padding: 8px 12px;
}

.denialTemplateHint {
	color: var(--color-text-maxcontrast);
	font-size: 0.9em;
	margin-top: 4px;
}

.denialActions {
	display: flex;
	justify-content: flex-end;
	gap: 8px;
	margin-top: 8px;
}
</style>

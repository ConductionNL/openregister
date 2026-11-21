<template>
	<NcDialog
		:open="open"
		:name="t('openregister', 'Translation')"
		size="normal"
		:noClose="loading"
		@closing="$emit('close')">
		<form class="bulkTranslateForm" @submit.prevent="onSubmit">
			<NcSelect
				v-model="source"
				:options="languages"
				:labelOutside="false"
				:inputLabel="t('openregister', 'From language')"
				:ariaLabelCombobox="t('openregister', 'From language')"
				:disabled="loading" />

			<NcSelect
				v-model="target"
				:options="languages"
				:labelOutside="false"
				:inputLabel="t('openregister', 'To language')"
				:ariaLabelCombobox="t('openregister', 'To language')"
				:selectable="isSelectableTarget"
				:disabled="loading" />

			<NcNoteCard v-if="sameLanguage" type="warning">
				{{ t('openregister', 'Choose two different languages.') }}
			</NcNoteCard>

			<div v-if="result" class="bulkTranslateResult" data-test="result">
				<p v-if="translatedCount > 0">
					{{ n('openregister', '%n field translated.', '%n fields translated.', translatedCount) }}
				</p>
				<template v-if="outcome.drafted.length > 0">
					<p>{{ n('openregister', '%n field saved as a draft. Check the terms the glossary asks for:', '%n fields saved as a draft. Check the terms the glossary asks for:', outcome.drafted.length) }}</p>
					<ul class="bulkTranslateList" data-test="drafted">
						<li v-for="entry in outcome.drafted" :key="entry.property">
							<strong>{{ entry.property }}</strong>: {{ entry.terms }}
						</li>
					</ul>
				</template>
				<template v-if="outcome.skipped.length > 0">
					<p>{{ n('openregister', '%n field not translated:', '%n fields not translated:', outcome.skipped.length) }}</p>
					<ul class="bulkTranslateList" data-test="skipped">
						<li v-for="entry in outcome.skipped" :key="entry.property">
							<strong>{{ entry.property === '_global' ? t('openregister', 'Object') : entry.property }}</strong>: {{ reasonText(entry.reason) }}
						</li>
					</ul>
				</template>
			</div>

			<NcNoteCard v-if="error" type="error">
				{{ error }}
			</NcNoteCard>

			<div class="bulkTranslateActions">
				<NcButton
					variant="tertiary"
					:disabled="loading"
					@click="$emit('close')">
					{{ result ? t('openregister', 'Close') : t('openregister', 'Cancel') }}
				</NcButton>
				<NcButton variant="primary" type="submit" :disabled="!canSubmit">
					<template #icon>
						<NcLoadingIcon v-if="loading" :size="20" />
					</template>
					{{ t('openregister', 'Translate') }}
				</NcButton>
			</div>
		</form>
	</NcDialog>
</template>

<script>
import { translatePlural as n, translate as t } from '@nextcloud/l10n'
import {
	NcButton,
	NcDialog,
	NcLoadingIcon,
	NcNoteCard,
	NcSelect,
} from '@nextcloud/vue'
import { useTranslationsStore } from '../../store/modules/translations.js'
import { draftedAndSkipped } from '../../views/object/objectTranslation.js'

/**
 * Translate one object (ai-translation-with-a-glossary task 3.2). Calls
 * `POST /api/translations/object/{uuid}/bulk-translate` through the
 * translations store, emits `translated` with the answer for the object page
 * to save, and lists what was translated, what was saved as a draft because a
 * glossary term is missing, and what was not translated.
 *
 * @spec openspec/changes/ai-translation-with-a-glossary/tasks.md
 */
export default {
	name: 'BulkTranslateDialog',

	components: {
		NcButton,
		NcDialog,
		NcLoadingIcon,
		NcNoteCard,
		NcSelect,
	},

	props: {
		/**
		 * @spec exclude dialog open/visibility prop, UI plumbing
		 */
		open: {
			type: Boolean,
			default: false,
		},

		uuid: {
			type: String,
			required: true,
		},

		/**
		 * The register's languages, the default first.
		 */
		languages: {
			type: Array,
			required: true,
		},
	},

	emits: ['close', 'translated'],

	data() {
		return {
			from: '',
			to: '',
			loading: false,
			error: null,
			result: null,
		}
	},

	computed: {
		/**
		 * NcSelect models an empty selection as `null`; this component models it
		 * as `''` so "unset" has one spelling everywhere else.
		 *
		 * @spec exclude NcSelect null/'' model proxy, UI plumbing
		 */
		source: {
			/**
			 * @return {string|null} The source language, null when unset.
			 * @spec exclude NcSelect null/'' model proxy getter, UI plumbing
			 */
			get() {
				return this.from === '' ? null : this.from
			},

			/**
			 * @param {string|null} value The language NcSelect emitted.
			 * @return {void}
			 * @spec exclude NcSelect null/'' model proxy setter, UI plumbing
			 */
			set(value) {
				this.from = value ?? ''
			},
		},

		/**
		 * The target-language counterpart of `source`.
		 *
		 * @spec exclude NcSelect null/'' model proxy, UI plumbing
		 */
		target: {
			/**
			 * @return {string|null} The target language, null when unset.
			 * @spec exclude NcSelect null/'' model proxy getter, UI plumbing
			 */
			get() {
				return this.to === '' ? null : this.to
			},

			/**
			 * @param {string|null} value The language NcSelect emitted.
			 * @return {void}
			 * @spec exclude NcSelect null/'' model proxy setter, UI plumbing
			 */
			set(value) {
				this.to = value ?? ''
			},
		},

		/**
		 * @spec exclude computed submit-enabled form-validation flag, UI plumbing
		 */
		canSubmit() {
			return !this.loading && this.from !== '' && this.to !== '' && this.from !== this.to
		},

		/**
		 * @spec exclude computed form-validation flag, UI plumbing
		 */
		sameLanguage() {
			return this.from !== '' && this.from === this.to
		},

		/**
		 * @return {number} How many fields the answer translated, drafts included.
		 * @spec openspec/changes/ai-translation-with-a-glossary/tasks.md
		 */
		translatedCount() {
			return Object.keys(this.result?.translated || {}).length
		},

		/**
		 * @return {{drafted: Array, skipped: Array}} The drafted and the untranslated fields.
		 * @spec openspec/changes/ai-translation-with-a-glossary/tasks.md
		 */
		outcome() {
			return draftedAndSkipped(this.result)
		},
	},

	watch: {
		/**
		 * Reset the form each time the dialog is opened.
		 *
		 * @param {boolean} opened Whether the dialog just became visible.
		 * @return {void}
		 * @spec exclude UI handler/computed dialog-open trigger
		 */
		open(opened) {
			if (opened) {
				this.from = ''
				this.to = ''
				this.error = null
				this.result = null
			}
		},
	},

	methods: {
		t,
		n,

		/**
		 * Whether a language may be picked as the target: not the source.
		 *
		 * @param {string} language The candidate target language.
		 * @return {boolean} True when it may be selected.
		 * @spec exclude NcSelect selectable predicate, UI plumbing
		 */
		isSelectableTarget(language) {
			return language !== this.from
		},

		/**
		 * A skip reason in words; an unknown one as the server gave it.
		 *
		 * @param {string} reason The reason code.
		 * @return {string} The reason to show.
		 * @spec openspec/changes/ai-translation-with-a-glossary/tasks.md
		 */
		reasonText(reason) {
			if (reason.startsWith('provider-error') || reason === 'provider-returned-empty') {
				return t('openregister', 'The translation service gave no translation.')
			}
			const known = {
				'no-source-value': t('openregister', 'No text in the source language.'),
				'target-slot-already-filled': t('openregister', 'Already has a text in the target language.'),
				'encrypted-at-rest': t('openregister', 'Stored encrypted, so it is not sent for translation.'),
			}
			return known[reason] ?? reason
		},

		/**
		 * @spec exclude store passthrough invoking bulk-translate; bulk-translate contract owned by register-i18n capability
		 */
		async onSubmit() {
			if (!this.canSubmit) return
			this.loading = true
			this.error = null
			this.result = null
			try {
				const store = useTranslationsStore()
				this.result = await store.bulkTranslate(this.uuid, this.from, this.to)
				this.$emit('translated', this.result)
			} catch (e) {
				this.error = e?.response?.data?.error ?? e?.message ?? t('openregister', 'The translation failed.')
			} finally {
				this.loading = false
			}
		},
	},
}
</script>

<style scoped>
.bulkTranslateForm {
	display: flex;
	flex-direction: column;
	gap: 12px;
}

.bulkTranslateResult {
	background: var(--color-background-dark);
	padding: 8px 12px;
	border-radius: var(--border-radius);
}

.bulkTranslateList {
	margin: 4px 0 0 16px;
	padding: 0;
}

.bulkTranslateActions {
	display: flex;
	justify-content: flex-end;
	gap: 8px;
}
</style>

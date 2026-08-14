<template>
	<NcDialog
		:open="open"
		name="Bulk translate"
		size="normal"
		:can-close="!loading"
		@closing="$emit('close')">
		<form class="bulkTranslateForm" @submit.prevent="onSubmit">
			<NcSelect
				v-model="source"
				:options="languages"
				:label-outside="false"
				input-label="From language"
				aria-label-combobox="From language"
				:disabled="loading" />

			<NcSelect
				v-model="target"
				:options="languages"
				:label-outside="false"
				input-label="To language"
				aria-label-combobox="To language"
				:selectable="isSelectableTarget"
				:disabled="loading" />

			<NcNoteCard v-if="sameLanguage" type="warning">
				Source and target language must differ.
			</NcNoteCard>

			<div v-if="result" class="bulkTranslateResult">
				<p v-if="hasTranslated">
					Translated
					<strong>{{ Object.keys(result.translated).length }}</strong>
					field(s).
				</p>
				<p v-if="hasSkipped">
					Skipped
					<strong>{{ Object.keys(result.skipped).length }}</strong>
					field(s):
				</p>
				<ul v-if="hasSkipped" class="bulkTranslateSkipped">
					<li v-for="(reason, prop) in result.skipped" :key="prop">
						<strong>{{ prop }}</strong
						>: {{ reason }}
					</li>
				</ul>
			</div>

			<NcNoteCard v-if="error" type="error">
				{{ error }}
			</NcNoteCard>

			<div class="bulkTranslateActions">
				<NcButton
					variant="tertiary"
					:disabled="loading"
					@click="$emit('close')">
					{{ result ? 'Close' : 'Cancel' }}
				</NcButton>
				<NcButton variant="primary" type="submit" :disabled="!canSubmit">
					<template #icon>
						<NcLoadingIcon v-if="loading" :size="20" />
					</template>
					{{ loading ? 'Translating…' : 'Translate' }}
				</NcButton>
			</div>
		</form>
	</NcDialog>
</template>

<script>
import {
	NcButton,
	NcDialog,
	NcLoadingIcon,
	NcNoteCard,
	NcSelect,
} from '@nextcloud/vue'

import { useTranslationsStore } from '../../store/modules/translations.js'

/**
 * Bulk-translate dialog. Calls
 * `POST /api/translations/object/{uuid}/bulk-translate` via the
 * translations Pinia store and surfaces the {translated, skipped}
 * result inline.
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
		 * Languages to choose from. Typically the register's
		 * configured `languages` list, plus any extra variants present
		 * on the object itself.
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
		 * as `''` so "unset" has one spelling everywhere else (data, watcher
		 * reset, canSubmit). The proxy translates between the two rather than
		 * letting `null` leak into the submit payload, where the API would
		 * receive the string "null" as a language code.
		 *
		 * @return {string|null} The selected source language, or null when unset.
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
		 * @return {string|null} The selected target language, or null when unset.
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
			return (
				!this.loading
				&& this.from !== ''
				&& this.to !== ''
				&& this.from !== this.to
			)
		},
		/**
		 * Whether a source language is chosen and the target repeats it.
		 *
		 * @return {boolean} True when the two selections are the same language.
		 *
		 * @spec exclude computed form-validation flag, UI plumbing
		 */
		sameLanguage() {
			return this.from !== '' && this.from === this.to
		},
		hasTranslated() {
			return (
				this.result?.translated
				&& Object.keys(this.result.translated).length > 0
			)
		},
		hasSkipped() {
			return (
				this.result?.skipped && Object.keys(this.result.skipped).length > 0
			)
		},
	},

	watch: {
		/**
		 * Reset the form each time the dialog is opened.
		 *
		 * @param {boolean} opened Whether the dialog just became visible.
		 *
		 * @return {void}
		 *
		 * @spec exclude UI handler/computed dialog-open trigger
		 */
		open(opened) {
			if (opened) {
				// Reset form state on open.
				this.from = ''
				this.to = ''
				this.error = null
				this.result = null
			}
		},
	},

	methods: {
		/**
		 * Whether a language may be picked as the target.
		 *
		 * Translating a language into itself is a no-op the backend would still
		 * charge a provider call for, so the source is removed from the target
		 * list rather than only warned about after selection.
		 *
		 * @param {string} language The candidate target language.
		 *
		 * @return {boolean} True when it may be selected.
		 *
		 * @spec exclude NcSelect selectable predicate, UI plumbing
		 */
		isSelectableTarget(language) {
			return language !== this.from
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
				this.result = await store.bulkTranslate(
					this.uuid,
					this.from,
					this.to,
				)
				this.$emit('translated', this.result)
			} catch (e) {
				this.error =
					e?.response?.data?.error ?? e?.message ?? 'Translation failed'
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
	font-size: 0.875rem;
}

.bulkTranslateSkipped {
	margin: 4px 0 0 16px;
	padding: 0;
	font-size: 0.8rem;
}

.bulkTranslateActions {
	display: flex;
	justify-content: flex-end;
	gap: 8px;
}
</style>

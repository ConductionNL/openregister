<template>
	<div class="translation-field-editor">
		<label v-if="label" class="translation-field-editor__label">{{ label }}</label>

		<div v-for="lang in orderedLanguages"
			:key="lang"
			class="translation-field-editor__row">
			<div class="translation-field-editor__row-header">
				<span class="translation-field-editor__lang-badge">{{ lang.toUpperCase() }}</span>
				<TranslationStatusChip v-if="getStatus(lang)"
					:status="getStatus(lang)"
					:language="lang" />
			</div>

			<textarea v-if="multiline"
				:value="getValue(lang)"
				:dir="dirFor(lang)"
				:lang="lang"
				:placeholder="placeholderFor(lang)"
				:rows="4"
				:disabled="disabled"
				class="translation-field-editor__textarea"
				@input="onInput(lang, $event.target.value)" />
			<input v-else
				type="text"
				:value="getValue(lang)"
				:dir="dirFor(lang)"
				:lang="lang"
				:placeholder="placeholderFor(lang)"
				:disabled="disabled"
				class="translation-field-editor__input"
				@input="onInput(lang, $event.target.value)">
		</div>
	</div>
</template>

<script>
import TranslationStatusChip from './TranslationStatusChip.vue'
import { isRtlLanguage } from '../../store/modules/translations.js'

/**
 * Per-language input set for a translatable schema property.
 *
 * Renders one input (text or textarea) per language in `languages`,
 * each with its workflow-status chip. Auto-applies `dir="rtl"` for
 * known RTL languages (Arabic, Hebrew, Persian, Urdu, etc.).
 *
 * Two-way bound via `v-model` to the property's nested
 * `{lang: value}` JSONB shape.
 *
 * @example
 *   <TranslationFieldEditor
 *     v-model="object.title"
 *     :languages="['nl', 'en']"
 *     :statuses="object._translationStatuses?.title"
 *     label="Title" />
 */
export default {
	name: 'TranslationFieldEditor',
	components: { TranslationStatusChip },
	props: {
		/**
		 * The current property value, shape `{lang: value, ...}`.
		 * v-model.
		 */
		value: {
			type: Object,
			default: () => ({}),
		},
		/**
		 * Languages to render inputs for. Order is preserved.
		 */
		languages: {
			type: Array,
			required: true,
		},
		/**
		 * Optional per-language status map (from translations sidecar).
		 *
		 * @type {Object<string, string>}
		 */
		statuses: {
			type: Object,
			default: () => ({}),
		},
		label: {
			type: String,
			default: '',
		},
		multiline: {
			type: Boolean,
			default: false,
		},
		disabled: {
			type: Boolean,
			default: false,
		},
		/**
		 * Hide languages that have no value. Useful in read-mostly
		 * views to reduce noise. Defaults to false (show all configured
		 * languages so empty slots are visible to translators).
		 */
		hideEmpty: {
			type: Boolean,
			default: false,
		},
	},
	computed: {
		orderedLanguages() {
			if (!this.hideEmpty) return this.languages
			return this.languages.filter((lang) => this.getValue(lang) !== '')
		},
	},
	methods: {
		getValue(lang) {
			const v = this.value?.[lang]
			return typeof v === 'string' ? v : ''
		},
		getStatus(lang) {
			return this.statuses?.[lang] ?? null
		},
		dirFor(lang) {
			return isRtlLanguage(lang) ? 'rtl' : 'ltr'
		},
		placeholderFor(lang) {
			return `Translation in ${lang.toUpperCase()}`
		},
		onInput(lang, newValue) {
			const next = { ...this.value, [lang]: newValue }
			// Drop empty slots so they don't bloat the JSONB nor create
			// empty translation rows in the sidecar.
			if (newValue === '') {
				delete next[lang]
			}
			this.$emit('input', next)
		},
	},
}
</script>

<style scoped>
.translation-field-editor {
	display: flex;
	flex-direction: column;
	gap: 8px;
}

.translation-field-editor__label {
	font-weight: 600;
	font-size: 0.875rem;
}

.translation-field-editor__row {
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.translation-field-editor__row-header {
	display: flex;
	align-items: center;
	gap: 6px;
}

.translation-field-editor__lang-badge {
	font-size: 0.7rem;
	font-weight: 600;
	padding: 2px 6px;
	border-radius: 4px;
	background: var(--color-background-darker, #eee);
	color: var(--color-text-maxcontrast, #333);
}

.translation-field-editor__input,
.translation-field-editor__textarea {
	width: 100%;
	padding: 6px 8px;
	border: 1px solid var(--color-border, #ddd);
	border-radius: 4px;
	font-size: 0.875rem;
	background: var(--color-main-background, #fff);
	color: var(--color-main-text, #000);
}

.translation-field-editor__textarea {
	font-family: inherit;
	resize: vertical;
}

.translation-field-editor__input:focus,
.translation-field-editor__textarea:focus {
	outline: 2px solid var(--color-primary, #0082c9);
	outline-offset: 1px;
}

.translation-field-editor__input:disabled,
.translation-field-editor__textarea:disabled {
	background: var(--color-background-dark, #f5f5f5);
	cursor: not-allowed;
}
</style>

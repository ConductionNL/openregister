<template>
	<div class="register-languages-editor">
		<label v-if="label" class="register-languages-editor__label">{{ label }}</label>
		<p v-if="helperText" class="register-languages-editor__help">
			{{ helperText }}
		</p>

		<ul v-if="value && value.length" class="register-languages-editor__list">
			<li v-for="(lang, idx) in value"
				:key="lang"
				class="register-languages-editor__row">
				<span class="register-languages-editor__lang">
					{{ lang.toUpperCase() }}
					<span v-if="idx === 0" class="register-languages-editor__default">{{ defaultLabel }}</span>
				</span>
				<div class="register-languages-editor__row-actions">
					<button type="button"
						class="register-languages-editor__btn"
						:disabled="idx === 0 || disabled"
						:aria-label="moveUpLabel"
						@click="move(idx, idx - 1)">
						▲
					</button>
					<button type="button"
						class="register-languages-editor__btn"
						:disabled="idx === value.length - 1 || disabled"
						:aria-label="moveDownLabel"
						@click="move(idx, idx + 1)">
						▼
					</button>
					<button type="button"
						class="register-languages-editor__btn register-languages-editor__btn--danger"
						:disabled="disabled"
						:aria-label="removeLabel"
						@click="remove(idx)">
						✕
					</button>
				</div>
			</li>
		</ul>
		<p v-else class="register-languages-editor__empty">
			{{ emptyLabel }}
		</p>

		<form class="register-languages-editor__add" @submit.prevent="addCurrent">
			<input v-model="draft"
				type="text"
				:placeholder="addPlaceholder"
				:disabled="disabled"
				maxlength="35"
				class="register-languages-editor__input"
				@keydown.enter.prevent="addCurrent">
			<button type="submit"
				class="register-languages-editor__btn"
				:disabled="!canAdd">
				{{ addLabel }}
			</button>
		</form>
		<p v-if="error" class="register-languages-editor__error">
			{{ error }}
		</p>
	</div>
</template>

<script>
const BCP_47_RE = /^[A-Za-z]{2,3}(?:-[A-Za-z0-9]{2,8})*$/

/**
 * Ordered list editor for `Register::languages`.
 *
 * v-model is a string[] of BCP 47 language tags (e.g. ['nl', 'en']).
 * The first element is the register's default language; reordering
 * affects fallback chains and Accept-Language negotiation.
 *
 * Validates new entries with a relaxed BCP 47 regex (subtag length
 * limits per RFC 5646) and rejects duplicates case-insensitively.
 */
export default {
	name: 'RegisterLanguagesEditor',
	props: {
		value: {
			type: Array,
			default: () => [],
		},
		label: {
			type: String,
			default: '',
		},
		helperText: {
			type: String,
			default: '',
		},
		disabled: {
			type: Boolean,
			default: false,
		},
		defaultLabel: {
			type: String,
			default: 'default',
		},
		emptyLabel: {
			type: String,
			default: 'No languages configured',
		},
		addPlaceholder: {
			type: String,
			default: 'BCP 47 tag (e.g. nl, en, ar-SA)',
		},
		addLabel: {
			type: String,
			default: 'Add',
		},
		moveUpLabel: {
			type: String,
			default: 'Move up',
		},
		moveDownLabel: {
			type: String,
			default: 'Move down',
		},
		removeLabel: {
			type: String,
			default: 'Remove',
		},
	},
	data() {
		return {
			draft: '',
			error: null,
		}
	},
	computed: {
		canAdd() {
			return !this.disabled && this.normalizedDraft !== '' && this.validateDraft(this.normalizedDraft) === null
		},
		normalizedDraft() {
			return (this.draft || '').trim().toLowerCase()
		},
	},
	methods: {
		validateDraft(candidate) {
			if (candidate === '') return 'empty'
			if (!BCP_47_RE.test(candidate)) return 'invalid'
			const seen = (this.value || []).map((l) => String(l).toLowerCase())
			if (seen.includes(candidate)) return 'duplicate'
			return null
		},
		addCurrent() {
			const candidate = this.normalizedDraft
			const reason = this.validateDraft(candidate)
			if (reason !== null) {
				this.error = this.errorMessageFor(reason)
				return
			}
			this.error = null
			const next = [...(this.value || []), candidate]
			this.$emit('input', next)
			this.draft = ''
		},
		errorMessageFor(reason) {
			switch (reason) {
			case 'empty': return 'Enter a BCP 47 language tag.'
			case 'invalid': return 'Not a valid BCP 47 tag (e.g. nl, en, ar-SA).'
			case 'duplicate': return 'That language is already in the list.'
			default: return 'Invalid input.'
			}
		},
		remove(idx) {
			if (this.disabled) return
			const next = [...(this.value || [])]
			next.splice(idx, 1)
			this.$emit('input', next)
		},
		move(from, to) {
			if (this.disabled) return
			const list = [...(this.value || [])]
			if (to < 0 || to >= list.length || from < 0 || from >= list.length) return
			const [item] = list.splice(from, 1)
			list.splice(to, 0, item)
			this.$emit('input', list)
		},
	},
}
</script>

<style scoped>
.register-languages-editor {
	display: flex;
	flex-direction: column;
	gap: 8px;
}

.register-languages-editor__label {
	font-weight: 600;
	font-size: 0.875rem;
}

.register-languages-editor__help {
	font-size: 0.8rem;
	color: var(--color-text-maxcontrast, #555);
	margin: 0;
}

.register-languages-editor__list {
	list-style: none;
	margin: 0;
	padding: 0;
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.register-languages-editor__row {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: 8px;
	padding: 6px 10px;
	border: 1px solid var(--color-border, #ddd);
	border-radius: 4px;
	background: var(--color-background-darker, #f5f5f5);
}

.register-languages-editor__lang {
	font-weight: 600;
	display: inline-flex;
	align-items: center;
	gap: 8px;
}

.register-languages-editor__default {
	font-size: 0.7rem;
	font-weight: 500;
	padding: 1px 6px;
	border-radius: 4px;
	background: var(--utrecht-status-badge-info-background-color, #dbeafe);
	color: var(--utrecht-status-badge-info-color, #1e40af);
	text-transform: uppercase;
}

.register-languages-editor__row-actions {
	display: inline-flex;
	gap: 4px;
}

.register-languages-editor__empty {
	font-size: 0.8rem;
	color: var(--color-text-maxcontrast, #777);
	margin: 0;
	font-style: italic;
}

.register-languages-editor__add {
	display: flex;
	gap: 6px;
}

.register-languages-editor__input {
	flex: 1;
	padding: 6px 8px;
	border: 1px solid var(--color-border, #ddd);
	border-radius: 4px;
	font-size: 0.875rem;
}

.register-languages-editor__btn {
	padding: 4px 10px;
	border: 1px solid var(--color-border, #ddd);
	border-radius: 4px;
	background: var(--color-main-background, #fff);
	cursor: pointer;
	font-size: 0.8rem;
}

.register-languages-editor__btn:disabled {
	opacity: 0.4;
	cursor: not-allowed;
}

.register-languages-editor__btn--danger {
	color: var(--color-error, #c0392b);
}

.register-languages-editor__error {
	font-size: 0.8rem;
	color: var(--color-error, #c0392b);
	margin: 0;
}
</style>

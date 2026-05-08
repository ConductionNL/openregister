<template>
	<div v-if="open" class="bulk-translate-dialog__backdrop" @click.self="$emit('close')">
		<div class="bulk-translate-dialog" role="dialog" aria-labelledby="bulk-translate-dialog-title">
			<header class="bulk-translate-dialog__header">
				<h3 id="bulk-translate-dialog-title">
					Bulk translate
				</h3>
			</header>

			<form @submit.prevent="onSubmit">
				<div class="bulk-translate-dialog__body">
					<label class="bulk-translate-dialog__field">
						<span>From language</span>
						<select v-model="from" :disabled="loading">
							<option value="">— select source —</option>
							<option v-for="lang in languages"
								:key="lang"
								:value="lang">{{ lang.toUpperCase() }}</option>
						</select>
					</label>

					<label class="bulk-translate-dialog__field">
						<span>To language</span>
						<select v-model="to" :disabled="loading">
							<option value="">— select target —</option>
							<option v-for="lang in languages"
								:key="lang"
								:value="lang"
								:disabled="lang === from">{{ lang.toUpperCase() }}</option>
						</select>
					</label>

					<p v-if="from === to && from !== ''" class="bulk-translate-dialog__warning">
						Source and target language must differ.
					</p>

					<div v-if="result" class="bulk-translate-dialog__result">
						<p v-if="hasTranslated">
							Translated <strong>{{ Object.keys(result.translated).length }}</strong> field(s).
						</p>
						<p v-if="hasSkipped">
							Skipped <strong>{{ Object.keys(result.skipped).length }}</strong> field(s):
						</p>
						<ul v-if="hasSkipped" class="bulk-translate-dialog__skipped">
							<li v-for="(reason, prop) in result.skipped" :key="prop">
								<strong>{{ prop }}</strong>: {{ reason }}
							</li>
						</ul>
					</div>

					<p v-if="error" class="bulk-translate-dialog__error">
						{{ error }}
					</p>
				</div>

				<footer class="bulk-translate-dialog__footer">
					<button type="button"
						class="bulk-translate-dialog__btn"
						:disabled="loading"
						@click="$emit('close')">
						{{ result ? 'Close' : 'Cancel' }}
					</button>
					<button type="submit"
						class="bulk-translate-dialog__btn bulk-translate-dialog__btn--primary"
						:disabled="!canSubmit">
						{{ loading ? 'Translating…' : 'Translate' }}
					</button>
				</footer>
			</form>
		</div>
	</div>
</template>

<script>
import { useTranslationsStore } from '../../store/modules/translations.js'

/**
 * Bulk-translate dialog. Calls
 * `POST /api/translations/object/{uuid}/bulk-translate` via the
 * translations Pinia store and surfaces the {translated, skipped}
 * result inline.
 */
export default {
	name: 'BulkTranslateDialog',
	props: {
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
		canSubmit() {
			return !this.loading
				&& this.from !== ''
				&& this.to !== ''
				&& this.from !== this.to
		},
		hasTranslated() {
			return this.result?.translated && Object.keys(this.result.translated).length > 0
		},
		hasSkipped() {
			return this.result?.skipped && Object.keys(this.result.skipped).length > 0
		},
	},
	watch: {
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
				this.error = e?.response?.data?.error ?? e?.message ?? 'Translation failed'
			} finally {
				this.loading = false
			}
		},
	},
}
</script>

<style scoped>
.bulk-translate-dialog__backdrop {
	position: fixed;
	inset: 0;
	background: rgba(0, 0, 0, 0.4);
	display: flex;
	align-items: center;
	justify-content: center;
	z-index: 1000;
}

.bulk-translate-dialog {
	background: var(--color-main-background, #fff);
	color: var(--color-main-text, #000);
	border-radius: 8px;
	max-width: 480px;
	width: 90%;
	box-shadow: 0 8px 32px rgba(0, 0, 0, 0.2);
	max-height: 90vh;
	overflow: auto;
}

.bulk-translate-dialog__header {
	padding: 16px 24px 8px;
	border-bottom: 1px solid var(--color-border, #ddd);
}

.bulk-translate-dialog__header h3 {
	margin: 0;
}

.bulk-translate-dialog__body {
	padding: 16px 24px;
	display: flex;
	flex-direction: column;
	gap: 12px;
}

.bulk-translate-dialog__field {
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.bulk-translate-dialog__field span {
	font-weight: 500;
	font-size: 0.875rem;
}

.bulk-translate-dialog__field select {
	padding: 6px 8px;
	border: 1px solid var(--color-border, #ddd);
	border-radius: 4px;
}

.bulk-translate-dialog__warning,
.bulk-translate-dialog__error {
	font-size: 0.875rem;
	margin: 0;
}

.bulk-translate-dialog__warning {
	color: var(--color-warning, #c0392b);
}

.bulk-translate-dialog__error {
	color: var(--color-error, #c0392b);
	background: var(--color-background-darker, #fee2e2);
	padding: 8px 12px;
	border-radius: 4px;
}

.bulk-translate-dialog__result {
	background: var(--color-background-darker, #f5f5f5);
	padding: 8px 12px;
	border-radius: 4px;
	font-size: 0.875rem;
}

.bulk-translate-dialog__skipped {
	margin: 4px 0 0 16px;
	padding: 0;
	font-size: 0.8rem;
}

.bulk-translate-dialog__footer {
	padding: 12px 24px;
	display: flex;
	justify-content: flex-end;
	gap: 8px;
	border-top: 1px solid var(--color-border, #ddd);
}

.bulk-translate-dialog__btn {
	padding: 8px 16px;
	border: 1px solid var(--color-border, #ddd);
	border-radius: 4px;
	background: var(--color-main-background, #fff);
	cursor: pointer;
	font-size: 0.875rem;
}

.bulk-translate-dialog__btn:disabled {
	opacity: 0.5;
	cursor: not-allowed;
}

.bulk-translate-dialog__btn--primary {
	background: var(--color-primary, #0082c9);
	color: var(--color-primary-text, #fff);
	border-color: var(--color-primary, #0082c9);
}
</style>

<template>
	<div class="translation-completeness-badge"
		:title="tooltipText"
		:aria-label="tooltipText">
		<div v-for="lang in languages"
			:key="lang"
			:class="['translation-completeness-badge__pill', completenessClassFor(lang)]">
			<span class="translation-completeness-badge__lang">{{ lang.toUpperCase() }}</span>
			<span class="translation-completeness-badge__ratio">{{ ratioPercent(lang) }}</span>
		</div>
	</div>
</template>

<script>
/**
 * Compact per-language completeness widget.
 *
 * Reads the `@self.translationCompleteness` payload that
 * `RenderObject` attaches to every rendered object. Renders a
 * coloured pill per language with the percentage filled.
 *
 * Example payload shape (from the backend):
 *   { nl: { translated: 4, total: 4, ratio: 1.0 },
 *     en: { translated: 2, total: 4, ratio: 0.5 } }
 */
export default {
	name: 'TranslationCompletenessBadge',
	props: {
		/**
		 * Per-language completeness map from `@self.translationCompleteness`.
		 *
		 * @type {Object<string, {translated: number, total: number, ratio: number}>}
		 */
		completeness: {
			type: Object,
			required: true,
		},
		/**
		 * Optional language ordering. When omitted, languages are listed
		 * alphabetically. Useful for matching the register's configured
		 * languages list.
		 */
		languageOrder: {
			type: Array,
			default: () => [],
		},
	},
	computed: {
		languages() {
			const present = Object.keys(this.completeness)
			if (this.languageOrder?.length) {
				const ordered = this.languageOrder.filter((l) => present.includes(l))
				const extras = present.filter((l) => !this.languageOrder.includes(l)).sort()
				return [...ordered, ...extras]
			}
			return present.slice().sort()
		},
		tooltipText() {
			const parts = this.languages.map((lang) => {
				const c = this.completeness[lang]
				return `${lang.toUpperCase()}: ${c.translated}/${c.total}`
			})
			return `Translation completeness — ${parts.join(', ')}`
		},
	},
	methods: {
		ratioPercent(lang) {
			const c = this.completeness[lang]
			if (!c || typeof c.ratio !== 'number') return '?'
			return `${Math.round(c.ratio * 100)}%`
		},
		completenessClassFor(lang) {
			const ratio = this.completeness[lang]?.ratio ?? 0
			if (ratio >= 1) return 'translation-completeness-badge__pill--complete'
			if (ratio >= 0.5) return 'translation-completeness-badge__pill--partial'
			return 'translation-completeness-badge__pill--low'
		},
	},
}
</script>

<style scoped>
.translation-completeness-badge {
	display: inline-flex;
	gap: 4px;
	flex-wrap: wrap;
}

.translation-completeness-badge__pill {
	display: inline-flex;
	align-items: center;
	gap: 3px;
	padding: 2px 6px;
	border-radius: 4px;
	font-size: 0.7rem;
	font-weight: 500;
	white-space: nowrap;
}

.translation-completeness-badge__pill--complete {
	background: var(--utrecht-status-badge-success-background-color, #d1fae5);
	color: var(--utrecht-status-badge-success-color, #065f46);
}

.translation-completeness-badge__pill--partial {
	background: var(--utrecht-status-badge-warning-background-color, #fef3c7);
	color: var(--utrecht-status-badge-warning-color, #92400e);
}

.translation-completeness-badge__pill--low {
	background: var(--utrecht-status-badge-error-background-color, #fee2e2);
	color: var(--utrecht-status-badge-error-color, #991b1b);
}

.translation-completeness-badge__lang {
	font-weight: 600;
}
</style>

<template>
	<span :class="['translation-status-chip', `translation-status-chip--${status}`]"
		:title="tooltipText"
		:aria-label="tooltipText">
		<span class="translation-status-chip__icon" aria-hidden="true">
			{{ iconChar }}
		</span>
		<span class="translation-status-chip__label">{{ labelText }}</span>
	</span>
</template>

<script>
import { TRANSLATION_STATUSES } from '../../store/modules/translations.js'

const STATUS_META = {
	[TRANSLATION_STATUSES.DRAFT]: { icon: '✎', label: 'Draft' },
	[TRANSLATION_STATUSES.MACHINE_TRANSLATED]: { icon: '⚙', label: 'Machine' },
	[TRANSLATION_STATUSES.HUMAN_REVIEWED]: { icon: '👁', label: 'Reviewed' },
	[TRANSLATION_STATUSES.APPROVED]: { icon: '✓', label: 'Approved' },
}

export default {
	name: 'TranslationStatusChip',
	props: {
		status: {
			type: String,
			required: true,
			validator: (v) => Object.values(TRANSLATION_STATUSES).includes(v),
		},
		language: {
			type: String,
			default: '',
		},
	},
	computed: {
		meta() {
			return STATUS_META[this.status] ?? { icon: '?', label: this.status }
		},
		iconChar() {
			return this.meta.icon
		},
		labelText() {
			return this.meta.label
		},
		tooltipText() {
			const lang = this.language ? ` (${this.language.toUpperCase()})` : ''
			return `Translation status: ${this.labelText}${lang}`
		},
	},
}
</script>

<style scoped>
.translation-status-chip {
	display: inline-flex;
	align-items: center;
	gap: 4px;
	padding: 2px 8px;
	border-radius: 12px;
	font-size: 0.75rem;
	line-height: 1.4;
	font-weight: 500;
	white-space: nowrap;
	background: var(--color-background-darker, #ddd);
	color: var(--color-text-maxcontrast, #333);
}

/*
 * Use NL Design System CSS custom properties where available so the
 * chip blends into government-styled deployments. Fallback colours
 * provided for non-NLDS contexts.
 */
.translation-status-chip--draft {
	background: var(--utrecht-status-badge-warning-background-color, #fef3c7);
	color: var(--utrecht-status-badge-warning-color, #92400e);
}

.translation-status-chip--machine_translated {
	background: var(--utrecht-status-badge-info-background-color, #dbeafe);
	color: var(--utrecht-status-badge-info-color, #1e40af);
}

.translation-status-chip--human_reviewed {
	background: var(--utrecht-status-badge-info-background-color, #ede9fe);
	color: var(--utrecht-status-badge-info-color, #5b21b6);
}

.translation-status-chip--approved {
	background: var(--utrecht-status-badge-success-background-color, #d1fae5);
	color: var(--utrecht-status-badge-success-color, #065f46);
}

.translation-status-chip__icon {
	font-size: 0.875rem;
}
</style>

<template>
	<div class="kpi-chip">
		<NcActions
			:forceMenu="true"
			container="body"
			:menuTitle="t('openregister', 'Change date range')">
			<template #icon>
				<span class="kpi-chip-label">{{ rangeText }}</span>
			</template>
			<NcActionButton
				v-for="option in presets"
				:key="option.id"
				:closeAfterClick="true"
				@click="$emit('pick', option.id)">
				<template v-if="preset === option.id" #icon>
					<CalendarRange :size="16" />
				</template>
				{{ t('openregister', option.label) }}
			</NcActionButton>
		</NcActions>
	</div>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import { NcActionButton, NcActions } from '@nextcloud/vue'
import CalendarRange from 'vue-material-design-icons/CalendarRange.vue'
import { presetOf, RANGE_PRESETS } from './kpiRange.js'

/**
 * The date-range pill on a range-driven KPI card (Searches, Events).
 *
 * @spec exclude Presentational menu shared by the dashboard's KPI cards.
 */
export default {
	name: 'KpiRangeChip',
	components: { CalendarRange, NcActionButton, NcActions },

	props: {
		preset: { type: String, required: true },
	},

	emits: ['pick'],

	computed: {
		/** @return {Array<object>} The date-range presets for the menu. */
		presets() {
			return RANGE_PRESETS
		},

		/** @return {string} Compact label for the active range. */
		rangeText() {
			return t('openregister', presetOf(this.preset).label)
		},
	},

	methods: { t },
}
</script>

<style scoped>
.kpi-chip-label {
	display: inline-block;
	padding: 2px 10px;
	border-radius: var(--border-radius-pill, 16px);
	background-color: var(--color-background-hover, #f5f5f5);
	color: var(--color-text-maxcontrast, #767676);
	font-size: 11px;
	font-weight: 500;
	white-space: nowrap;
	cursor: pointer;
}
</style>

<!--
  Unscoped, namespaced override: NcActions renders its trigger as a
  fixed-size icon button (overflow: hidden), which clips the wide date
  pill. Confined to .kpi-chip, this lets the trigger shrink-wrap the pill.
-->
<style>
.kpi-chip .button-vue,
.kpi-chip .action-item__menutoggle {
	width: auto !important;
	min-width: 0 !important;
	height: auto !important;
	min-height: 0 !important;
	padding: 0 !important;
	overflow: visible !important;
}

.kpi-chip .button-vue__wrapper,
.kpi-chip .button-vue__icon {
	width: auto !important;
	min-width: 0 !important;
	height: auto !important;
}
</style>

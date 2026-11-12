<!--
  One option of a settings select: an optional icon, its label and its
  description, stacked.

  @spec exclude Presentational select option of the file settings.
  SPDX-License-Identifier: EUPL-1.2
  SPDX-FileCopyrightText: 2026 Conduction B.V.
-->
<template>
	<div v-if="withIcon" class="option-item">
		<span class="option-icon">{{ icon }}</span>
		<span class="option-label">{{ label }}</span>
		<span class="option-description">{{ description }}</span>
	</div>
	<div v-else class="option-item">
		<span class="option-label">{{ label }}</span>
		<span class="option-description">{{ description }}</span>
	</div>
</template>

<script>
/**
 * One option of a settings select: an optional icon, its label and its
 * description.
 *
 * @spec exclude Presentational select option of the file settings.
 */
export default {
	name: 'LabeledOption',

	props: {
		label: { type: String, default: '' },
		description: { type: String, default: '' },
		icon: { type: String, default: '' },
		/** Render the icon slot, even when an option has no icon. */
		withIcon: { type: Boolean, default: false },
	},
}
</script>

<style scoped lang="scss">
.option-item {
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.option-icon {
	font-size: 18px;
	margin-right: 8px;
}

.option-label {
	font-weight: 500;
}

.option-description {
	font-size: 12px;
	color: var(--color-text-maxcontrast);
}
</style>

<!--
  The tab strip of a view modal and the panel area below it. The modal
  keeps the active tab (v-model) and renders the panels in the slot.

  @spec exclude Presentational tabs shared by the view modals.
  SPDX-License-Identifier: EUPL-1.2
  SPDX-FileCopyrightText: 2026 Conduction B.V.
-->
<template>
	<div class="tabContainer">
		<div class="tabHeaders">
			<button
				v-for="(tab, index) in tabs"
				:key="tab"
				class="tabHeader"
				:class="{ active: modelValue === index }"
				@click="$emit('update:modelValue', index)">
				{{ tab }}
			</button>
		</div>

		<div class="tabContent">
			<slot />
		</div>
	</div>
</template>

<script>
/**
 * The tab strip of a view modal.
 *
 * @spec exclude Presentational tabs shared by the view modals.
 */
export default {
	name: 'ViewDialogTabs',

	props: {
		/** The tab labels, already translated. */
		tabs: { type: Array, required: true },
		/** The index of the active tab. */
		modelValue: { type: Number, required: true },
	},

	emits: ['update:modelValue'],
}
</script>

<style scoped lang="scss">
@use '../../styles/modals.scss' as *;

@include tab-headers-rules;

.tabContent {
	min-height: 200px;
}
</style>

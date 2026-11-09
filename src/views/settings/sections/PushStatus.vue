<!--
  - SPDX-License-Identifier: EUPL-1.2
  - SPDX-FileCopyrightText: 2026 Conduction B.V.
  -->
<template>
	<div class="push-status" :class="'push-status--' + tone">
		<div class="push-status__badge">
			<component :is="icon" :size="20" />
			<span>{{ label }}</span>
		</div>
		<slot />
	</div>
</template>

<script>
import AlertCircle from 'vue-material-design-icons/AlertCircle.vue'
import AlertOutline from 'vue-material-design-icons/AlertOutline.vue'
import CheckCircle from 'vue-material-design-icons/CheckCircle.vue'

const ICONS = { error: AlertCircle, warning: AlertOutline, success: CheckCircle }

/**
 * One push notification status callout: a badge line with the tone's icon and
 * a label, and the explanation below it in the default slot.
 *
 * @spec exclude UI plumbing — presentational callout for the push notifications section
 */
export default {
	name: 'PushStatus',

	props: {
		tone: {
			type: String,
			required: true,
			validator: (value) => value in ICONS,
		},

		label: { type: String, required: true },
	},

	computed: {
		/**
		 * @spec exclude UI plumbing — the icon that goes with the tone
		 * @return {object} The icon component
		 */
		icon() {
			return ICONS[this.tone]
		},
	},
}
</script>

<style scoped lang="scss">
@use './sectionStyles.scss' as *;

@include status-callout('.push-status', '.push-status__badge');
</style>

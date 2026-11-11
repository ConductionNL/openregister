<!--
SPDX-FileCopyrightText: 2026 Conduction <info@conduction.nl>
SPDX-License-Identifier: EUPL-1.2
-->
<template>
	<PagerFrame
		v-slot="{ from, to }"
		:total="total"
		@previous="$emit('previous')"
		@next="$emit('next')">
		<span class="paginationInfo">
			{{ t('openregister', 'Showing {from}-{to} of {total}', { from, to, total }) }}
		</span>
	</PagerFrame>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import PagerFrame from '../../components/shared/PagerFrame.vue'

/**
 * Previous and next over a quality list read by offset, with the range shown;
 * hidden on a single page. Takes `offset` and `limit` and emits `previous`
 * and `next` through PagerFrame. The page keeps the `.pagination` styles.
 *
 * @spec exclude Presentational pager shared by the quality pages.
 */
export default {
	name: 'RangePager',
	components: { PagerFrame },
	props: {
		total: { type: Number, required: true },
	},

	emits: ['previous', 'next'],
	methods: { t },
}
</script>

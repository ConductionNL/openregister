<!--
SPDX-FileCopyrightText: 2026 Conduction <info@conduction.nl>
SPDX-License-Identifier: EUPL-1.2
-->
<template>
	<div v-if="total > limit" class="pagination">
		<NcButton :disabled="offset === 0" @click="$emit('previous')">
			{{ t('openregister', 'Previous') }}
		</NcButton>
		<span class="paginationInfo">
			{{
				t('openregister', 'Showing {from}-{to} of {total}', {
					from: offset + 1,
					to: Math.min(offset + limit, total),
					total,
				})
			}}
		</span>
		<NcButton :disabled="offset + limit >= total" @click="$emit('next')">
			{{ t('openregister', 'Next') }}
		</NcButton>
	</div>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import { NcButton } from '@nextcloud/vue'

/**
 * Previous and next over a quality list read by offset, with the range shown;
 * hidden on a single page. The page keeps the `.pagination` styles.
 *
 * @spec exclude Presentational pager shared by the quality pages.
 */
export default {
	name: 'RangePager',
	components: { NcButton },
	props: {
		offset: { type: Number, required: true },
		limit: { type: Number, required: true },
		total: { type: Number, required: true },
	},

	emits: ['previous', 'next'],
	methods: { t },
}
</script>

<template>
	<div v-if="total > limit" class="pagination">
		<NcButton :disabled="offset === 0" @click="$emit('previous')">
			{{ t('openregister', 'Previous') }}
		</NcButton>
		<slot
			:currentPage="Math.floor(offset / limit) + 1"
			:totalPages="Math.ceil(total / limit)"
			:from="offset + 1"
			:to="Math.min(offset + limit, total)" />
		<NcButton :disabled="offset + limit >= total" @click="$emit('next')">
			{{ t('openregister', 'Next') }}
		</NcButton>
	</div>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import { NcButton } from '@nextcloud/vue'

/**
 * Previous and next over a list read by offset, hidden on a single page;
 * unstyled. The default slot renders the position between the buttons and
 * receives `currentPage`, `totalPages`, `from` and `to`.
 *
 * @spec exclude Presentational pager frame shared by OffsetPager and RangePager.
 */
export default {
	name: 'PagerFrame',
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

<template>
	<PagerFrame
		v-slot="{ currentPage, totalPages }"
		@previous="$emit('previous')"
		@next="$emit('next')">
		<span class="pagination-info">
			{{ t('openregister', 'Page {current} of {total}', { current: currentPage, total: totalPages }) }}
		</span>
	</PagerFrame>
</template>

<script>
import { t } from '@nextcloud/l10n'
import PagerFrame from './PagerFrame.vue'

/**
 * Previous and next over a list read by offset, with the page number shown;
 * hidden on a single page. Takes `offset`, `limit` and `total` and emits
 * `previous` and `next`, all through PagerFrame.
 *
 * @spec exclude Presentational pager shared by the list pages.
 */
export default {
	name: 'OffsetPager',
	components: { PagerFrame },
	emits: ['previous', 'next'],
	methods: { t },
}
</script>

<style scoped lang="scss">
@use '../../styles/views.scss' as *;

@include pagination-rules;
</style>

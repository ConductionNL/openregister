<template>
	<div v-if="total > limit" class="pagination">
		<NcButton :disabled="offset === 0" @click="$emit('previous')">
			{{ t('openregister', 'Previous') }}
		</NcButton>
		<span class="pagination-info">
			{{
				t('openregister', 'Page {current} of {total}', {
					current: currentPage,
					total: totalPages,
				})
			}}
		</span>
		<NcButton :disabled="offset + limit >= total" @click="$emit('next')">
			{{ t('openregister', 'Next') }}
		</NcButton>
	</div>
</template>

<script>
import { t } from '@nextcloud/l10n'
import { NcButton } from '@nextcloud/vue'

/**
 * Previous and next over a list read by offset; hidden on a single page.
 *
 * @spec exclude Presentational pager shared by the list pages.
 */
export default {
	name: 'OffsetPager',
	components: { NcButton },
	props: {
		offset: { type: Number, required: true },
		limit: { type: Number, required: true },
		total: { type: Number, required: true },
	},

	emits: ['previous', 'next'],
	computed: {
		/**
		 * @spec exclude page number from the offset, UI plumbing
		 */
		currentPage() {
			return Math.floor(this.offset / this.limit) + 1
		},

		/**
		 * @spec exclude page count from the total, UI plumbing
		 */
		totalPages() {
			return Math.ceil(this.total / this.limit)
		},
	},

	methods: { t },
}
</script>

<style scoped lang="scss">
@use '../../styles/views.scss' as *;

@include pagination-rules;
</style>

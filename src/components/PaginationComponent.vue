<template>
	<div v-if="totalPages > 1 || totalItems > minItemsToShow" class="viewPagination">
		<!-- Page info first -->
		<div class="viewPaginationInfo">
			<span class="viewPageInfo">
				{{ t('openregister', 'Page {current} of {total}', { current: currentPage, total: totalPages }) }}
			</span>
		</div>

		<!-- Page navigation in middle -->
		<div v-if="totalPages > 1" class="viewPaginationNav">
			<!-- First page button -->
			<NcButton
				:disabled="currentPage === 1"
				@click="changePage(1)">
				{{ t('openregister', 'First') }}
			</NcButton>

			<!-- Previous page button -->
			<NcButton
				:disabled="currentPage === 1"
				@click="changePage(currentPage - 1)">
				{{ t('openregister', 'Previous') }}
			</NcButton>

			<!-- Page number buttons -->
			<div class="viewPaginationNumbers">
				<template v-for="page in visiblePages">
					<span v-if="page === '...'" :key="'ellipsis-' + page" class="viewPaginationEllipsis">...</span>
					<NcButton
						v-else
						:key="page"
						:type="page === currentPage ? 'primary' : 'secondary'"
						:disabled="page === currentPage"
						@click="changePage(page)">
						{{ page }}
					</NcButton>
				</template>
			</div>

			<!-- Next page button -->
			<NcButton
				:disabled="currentPage === totalPages"
				@click="changePage(currentPage + 1)">
				{{ t('openregister', 'Next') }}
			</NcButton>

			<!-- Last page button -->
			<NcButton
				:disabled="currentPage === totalPages"
				@click="changePage(totalPages)">
				{{ t('openregister', 'Last') }}
			</NcButton>
		</div>

		<!-- Page size selector last -->
		<div class="viewPaginationPageSize">
			<label for="pageSize">{{ t('openregister', 'Items per page:') }}</label>
			<NcSelect
				id="pageSize"
				class="pagination-page-size-select"
				:value="currentPageSizeOption"
				:options="pageSizeOptions"
				:clearable="false"
				input-label="Items per page"
				@option:selected="changePageSize" />
		</div>
	</div>
</template>

<script>
import { NcButton, NcSelect } from '@nextcloud/vue'

/**
 * Reusable pagination component for OpenRegister views
 *
 * @package
 * @author Ruben Linde <ruben@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license EUPL-1.2
 * @version 1.0.0
 */
export default {
	name: 'PaginationComponent',
	components: {
		NcButton,
		NcSelect,
	},
	props: {
		/**
		 * Current page number
		 * @type {number}
		 * @default 1
		 */
		currentPage: {
			type: Number,
			default: 1,
		},
		/**
		 * Total number of pages
		 * @type {number}
		 * @default 1
		 */
		totalPages: {
			type: Number,
			default: 1,
		},
		/**
		 * Total number of items
		 * @type {number}
		 * @default 0
		 */
		totalItems: {
			type: Number,
			default: 0,
		},
		/**
		 * Current page size/limit
		 * @type {number}
		 * @default 20
		 */
		currentPageSize: {
			type: Number,
			default: 20,
		},
		/**
		 * Available page size options
		 * @type {Array<object>}
		 * @default Standard options array
		 */
		pageSizeOptions: {
			type: Array,
			default: () => [
				{ value: 10, label: '10' },
				{ value: 20, label: '20' },
				{ value: 50, label: '50' },
				{ value: 100, label: '100' },
				{ value: 250, label: '250' },
				{ value: 500, label: '500' },
				{ value: 1000, label: '1000' },
			],
		},
		/**
		 * Minimum items needed to show pagination
		 * @type {number}
		 * @default 10
		 */
		minItemsToShow: {
			type: Number,
			default: 10,
		},
	},
	computed: {
		/**
		 * Get current page size option object
		 * @return {object} Current page size option object
		 */
		currentPageSizeOption() {
			return this.pageSizeOptions.find(option => option.value === this.currentPageSize) || this.pageSizeOptions[1]
		},
		/**
		 * Calculate visible page numbers for pagination
		 * @return {Array} Array of page numbers and ellipsis
		 */
		visiblePages() {
			const current = this.currentPage
			const total = this.totalPages
			const pages = []

			if (total <= 7) {
				// Show all pages if 7 or fewer
				for (let i = 1; i <= total; i++) {
					pages.push(i)
				}
			} else {
				// Always show first page
				pages.push(1)

				if (current <= 4) {
					// Current page is near the beginning
					for (let i = 2; i <= 5; i++) {
						pages.push(i)
					}
					pages.push('...')
					pages.push(total)
				} else if (current >= total - 3) {
					// Current page is near the end
					pages.push('...')
					for (let i = total - 4; i <= total; i++) {
						pages.push(i)
					}
				} else {
					// Current page is in the middle
					pages.push('...')
					for (let i = current - 1; i <= current + 1; i++) {
						pages.push(i)
					}
					pages.push('...')
					pages.push(total)
				}
			}

			return pages
		},
	},
	methods: {
		/**
		 * Change to a specific page
		 * @param {number} page - The page number to change to
		 * @return {void}
		 */
		changePage(page) {
			if (page !== this.currentPage && page >= 1 && page <= this.totalPages) {
				/**
				 * Emitted when page changes
				 * @event page-changed
				 * @type {number} The new page number
				 */
				this.$emit('page-changed', page)
			}
		},
		/**
		 * Change page size
		 * @param {object} option - Selected page size option
		 * @return {void}
		 */
		changePageSize(option) {
			if (option.value !== this.currentPageSize) {
				/**
				 * Emitted when page size changes
				 * @event page-size-changed
				 * @type {number} The new page size
				 */
				this.$emit('page-size-changed', option.value)
			}
		},
	},
}
</script>

<style scoped>
/* All pagination styles are in main.css - no component-specific styles needed */
</style>

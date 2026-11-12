/**
 * Paging over a list read by offset.
 *
 * @spec exclude Client-side paging shared by the list pages.
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */

/**
 * A mixin holding previous and next. The page keeps `offset` and `limit`.
 *
 * @param {string} total The member that holds the total
 * @param {string} [load] The method that reads the new page, if it must be read
 * @return {object} The mixin
 */
export function offsetPaging(total, load) {
	return {
		methods: {
			/**
			 * @spec exclude paging handler, UI plumbing
			 */
			previousPage() {
				if (this.offset > 0) {
					this.offset = Math.max(0, this.offset - this.limit)
					if (load) this[load]()
				}
			},

			/**
			 * @spec exclude paging handler, UI plumbing
			 */
			nextPage() {
				if (this.offset + this.limit < this[total]) {
					this.offset += this.limit
					if (load) this[load]()
				}
			},
		},
	}
}

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 */
import { qualityStore } from '../../store/store.js'

/**
 * A mixin for a data quality page that pages one store list: `previousPage()`
 * and `nextPage()` refetch the list for the current register and schema one
 * page back or forward. The page supplies the `<prefix>Limit` and
 * `<prefix>Offset` computeds it already shows in its pager.
 *
 * @param {string} fetchAction The store action that fetches the list
 * @param {string} prefix The prefix of the page's limit and offset computeds
 * @return {object} The mixin
 * @spec exclude UI pagination plumbing shared by the quality pages; the store actions carry the @spec tags
 */
export function qualityPaging(fetchAction, prefix) {
	return {
		methods: {
			/**
			 * @spec exclude UI pagination handler — delegates to the store's fetch action
			 */
			async previousPage() {
				const limit = this[`${prefix}Limit`]
				await this.fetchPage(Math.max(0, this[`${prefix}Offset`] - limit))
			},

			/**
			 * @spec exclude UI pagination handler — delegates to the store's fetch action
			 */
			async nextPage() {
				await this.fetchPage(this[`${prefix}Offset`] + this[`${prefix}Limit`])
			},

			/**
			 * @param {number} offset The first row of the page
			 * @return {Promise} The store's fetch
			 * @spec exclude UI pagination handler — delegates to the store's fetch action
			 */
			fetchPage(offset) {
				return qualityStore[fetchAction](
					qualityStore.selectedRegister,
					qualityStore.selectedSchema,
					{ limit: this[`${prefix}Limit`], offset },
				)
			},
		},
	}
}

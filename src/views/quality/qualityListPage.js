/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 */
import QualityPageFrame from './QualityPageFrame.vue'
import RangePager from './RangePager.vue'
import { qualityPaging } from './qualityPaging.js'
import { qualitySelection } from './qualitySelection.js'

export { QualityPageFrame, RangePager }

/**
 * A mixin for a data quality page that reads one register and schema: the
 * selection handling of `qualitySelection` and, given a store action, the
 * pager of `qualityPaging` over that list. The page registers the frame and
 * pager it draws itself (exported here), so its template stays checkable.
 *
 * @param {string} [fetchAction] The store action that fetches the paged list
 * @param {string} [prefix] The prefix of the page's limit and offset computeds
 * @return {object} The mixin
 * @spec exclude UI plumbing shared by the quality pages; the store actions carry the @spec tags
 */
export function qualityListPage(fetchAction, prefix) {
	if (!fetchAction) {
		return { mixins: [qualitySelection] }
	}
	return { mixins: [qualitySelection, qualityPaging(fetchAction, prefix)] }
}

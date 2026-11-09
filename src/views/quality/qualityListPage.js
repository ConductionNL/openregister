/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 */
import QualityPageFrame from './QualityPageFrame.vue'
import RangePager from './RangePager.vue'
import { qualityPaging } from './qualityPaging.js'
import { qualitySelection } from './qualitySelection.js'

/**
 * A mixin for a data quality page that reads one register and schema: its
 * frame, the selection handling of `qualitySelection` and, given a store
 * action, the pager of `qualityPaging` over that list.
 *
 * @param {string} [fetchAction] The store action that fetches the paged list
 * @param {string} [prefix] The prefix of the page's limit and offset computeds
 * @return {object} The mixin
 * @spec exclude UI plumbing shared by the quality pages; the store actions carry the @spec tags
 */
export function qualityListPage(fetchAction, prefix) {
	if (!fetchAction) {
		return { components: { QualityPageFrame }, mixins: [qualitySelection] }
	}
	return {
		components: { QualityPageFrame, RangePager },
		mixins: [qualitySelection, qualityPaging(fetchAction, prefix)],
	}
}

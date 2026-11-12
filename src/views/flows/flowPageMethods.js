/**
 * The two methods the flow overview and the run page share: a GET whose
 * failure leaves a panel empty, and an action wrapper that shows the server's
 * refusal in its own words.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Spread into a view's `methods`; `act` reads and writes the view's `busy`
 * and `actionError`.
 *
 * @spec openspec/specs/flow-and-run-detail-pages/spec.md
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
import axios from '@nextcloud/axios'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'

export const flowPageMethods = {
	/**
	 * A GET whose failure leaves its panel empty.
	 *
	 * @param {string} path The app path.
	 * @param {object} params Query parameters.
	 * @return {Promise<object|null>} The response body, or null.
	 * @spec openspec/specs/flow-and-run-detail-pages/spec.md#requirement-a-flow-has-an-overview-page-that-the-index-opens
	 * @spec openspec/specs/flow-and-run-detail-pages/spec.md#requirement-a-run-has-its-own-page
	 */
	async quietGet(path, params = undefined) {
		try {
			const response = await axios.get(
				generateUrl(path),
				params ? { params } : undefined,
			)
			return response?.data ?? null
		} catch {
			return null
		}
	},

	/**
	 * Run one action, and show the server's refusal in its own words.
	 *
	 * @param {() => Promise<void>} action The action.
	 * @return {Promise<void>}
	 * @spec openspec/specs/flow-and-run-detail-pages/spec.md#requirement-a-flow-has-an-overview-page-that-the-index-opens
	 * @spec openspec/specs/flow-and-run-detail-pages/spec.md#requirement-run-actions-follow-what-the-api-accepts
	 */
	async act(action) {
		this.busy = true
		this.actionError = ''
		try {
			await action()
		} catch (error) {
			this.actionError =
				error?.response?.data?.error
				|| t('openregister', 'That did not work. Try again.')
		} finally {
			this.busy = false
		}
	},
}

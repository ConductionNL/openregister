/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

import { getRequestToken } from '@nextcloud/auth'

/**
 * Fetch options with Nextcloud's request token added to the headers.
 *
 * OpenRegister's write routes run Nextcloud's request-token check, so a raw
 * fetch() from the UI sends the `requesttoken` header, as @nextcloud/axios does.
 *
 * @param {object} [init] The fetch options.
 * @return {object} A copy of the options with the `requesttoken` header.
 * @spec exclude Shared request helper that adds Nextcloud's own token; no feature behaviour.
 */
export function withRequestToken(init = {}) {
	return {
		...init,
		headers: {
			...(init.headers || {}),
			requesttoken: getRequestToken() ?? '',
		},
	}
}

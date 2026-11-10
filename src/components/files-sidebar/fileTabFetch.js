/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * How the Files sidebar tabs load their data for one file.
 *
 * @spec exclude Client-side loading shared by the Files sidebar tabs.
 */
import axios from '@nextcloud/axios'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'

/**
 * Load a file endpoint into a tab with `loading`, `error`, `errorMessage`
 * and `fileId`: hand a successful payload to `keep`, show the error otherwise.
 *
 * @param {object} tab The tab component instance
 * @param {string} endpoint The API path, with `{fileId}` in it
 * @param {Function} keep Receives the response's data on success
 * @return {Promise<Error|null>} The failure of the request, for the tab to log
 */
export async function fetchFileTab(tab, endpoint, keep) {
	let failure = null
	tab.loading = true
	tab.error = false
	tab.errorMessage = ''

	try {
		const url = generateUrl(endpoint, { fileId: tab.fileId })
		const response = await axios.get(url)

		if (response.data?.success) {
			keep(response.data.data)
		} else {
			tab.error = true
			tab.errorMessage =
				response.data?.error || t('openregister', 'Unknown error')
		}
	} catch (err) {
		tab.error = true
		tab.errorMessage = err.response?.data?.error || err.message
		failure = err
	} finally {
		tab.loading = false
	}
	return failure
}

/**
 * A mixin for a Files sidebar tab: the `fileId` prop, the `loading`,
 * `error` and `errorMessage` state, and a reload through the tab's method
 * `load` whenever the file changes (and on creation).
 *
 * @param {string} load The name of the tab's method that loads the file's data
 * @return {object} The mixin
 */
export function fileTab(load) {
	return {
		props: {
			fileId: {
				type: Number,
				required: true,
			},
		},

		data() {
			return {
				loading: false,
				error: false,
				errorMessage: '',
			}
		},

		watch: {
			fileId: {
				/**
				 * @param {number} newVal The id of the file now shown
				 * @spec exclude watcher reloading the tab on fileId change, UI plumbing
				 */
				handler(newVal) {
					if (newVal) {
						this[load]()
					}
				},

				immediate: true,
			},
		},
	}
}

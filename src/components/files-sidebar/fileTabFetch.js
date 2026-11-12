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
	startLoading(tab)
	try {
		const url = generateUrl(endpoint, { fileId: tab.fileId })
		const response = await axios.get(url)
		if (answered(tab, response.data)) {
			keep(response.data.data)
		}
		return null
	} catch (err) {
		return failed(tab, err)
	} finally {
		tab.loading = false
	}
}

/**
 * Mark a tab loading, without an error.
 *
 * @param {object} tab The tab component instance
 * @return {void}
 */
function startLoading(tab) {
	tab.loading = true
	tab.error = false
	tab.errorMessage = ''
}

/**
 * Whether the endpoint answered with success; otherwise the tab shows the
 * error the answer names.
 *
 * @param {object} tab The tab component instance
 * @param {object|undefined} data The response's data
 * @return {boolean} True for a successful answer
 */
function answered(tab, data) {
	if (data?.success) {
		return true
	}
	tab.error = true
	tab.errorMessage = data?.error || t('openregister', 'Unknown error')
	return false
}

/**
 * Show a failed request on the tab.
 *
 * @param {object} tab The tab component instance
 * @param {Error} err The failure
 * @return {Error} The failure, for the tab to log
 */
function failed(tab, err) {
	tab.error = true
	tab.errorMessage = err.response?.data?.error || err.message
	return err
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

		data: fileTabState,

		watch: {
			fileId: reloadOnFileChange(load),
		},
	}
}

/**
 * The state a Files sidebar tab starts from: not loading, no error.
 *
 * @return {{loading: boolean, error: boolean, errorMessage: string}} The state
 */
function fileTabState() {
	return {
		loading: false,
		error: false,
		errorMessage: '',
	}
}

/**
 * A watcher that reloads the tab through its method `load` whenever it is
 * given a file, and on creation.
 *
 * @param {string} load The name of the tab's method that loads the file's data
 * @return {object} The watcher
 */
function reloadOnFileChange(load) {
	return {
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
	}
}

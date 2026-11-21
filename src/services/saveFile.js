/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Let the browser save content as a file, through a temporary download link.
 */

/**
 * Offer a blob as a download under the given name.
 *
 * @spec exclude browser file-download UI plumbing
 * @param {Blob} blob The file
 * @param {string} filename The name to offer
 * @return {void}
 */
export function saveBlob(blob, filename) {
	const url = URL.createObjectURL(blob)
	const link = document.createElement('a')
	link.href = url
	link.download = filename
	document.body.appendChild(link)
	link.click()
	document.body.removeChild(link)
	URL.revokeObjectURL(url)
}

/**
 * Offer data as a pretty-printed JSON file.
 *
 * @spec exclude browser file-download UI plumbing
 * @param {unknown} data The data to save
 * @param {string} filename The name to offer
 * @return {void}
 */
export function saveJson(data, filename) {
	saveBlob(new Blob([JSON.stringify(data, null, 2)], { type: 'application/json' }), filename)
}

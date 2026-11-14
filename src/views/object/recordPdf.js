/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * "Export PDF" on the record page: fetch the record's PDF and hand it to the
 * browser as a download, or answer the server's refusal.
 *
 * @spec openspec/changes/export-pdf-house-style/specs/export-pdf-format/spec.md#requirement-a-single-record-exports-as-a-pdf
 */

/**
 * The record PDF endpoint of a record address.
 *
 * @param {{register: string, schema: string, id: string}} address The record's address
 * @return {string} The URL
 * @spec openspec/changes/export-pdf-house-style/specs/export-pdf-format/spec.md#requirement-a-single-record-exports-as-a-pdf
 */
export function recordPdfUrl(address) {
	const parts = [address.register, address.schema, address.id].map(encodeURIComponent)
	return `/index.php/apps/openregister/api/objects/${parts.join('/')}/pdf`
}

/**
 * The file name the server gave, or the record id.
 *
 * @param {string|null} disposition The Content-Disposition header
 * @param {string} id The record id
 * @return {string} The file name
 */
function filenameOf(disposition, id) {
	const match = /filename="?([^";]+)"?/.exec(disposition || '')
	return match ? match[1] : `${id}.pdf`
}

/**
 * Fetch the PDF and save it.
 *
 * A refusal (no export right, no office server, a record out of reach) is
 * answered with the server's message instead of a download; nothing throws.
 *
 * @param {{register: string, schema: string, id: string}} address The record's address
 * @param {Function} [fetchFn] The fetch to use
 * @param {Function} [saveFn] Saves a blob under a file name
 * @return {Promise<{ok: boolean, message: string|null}>} Whether the PDF was saved, else the server's message
 * @spec openspec/changes/export-pdf-house-style/specs/export-pdf-format/spec.md#requirement-a-single-record-exports-as-a-pdf
 */
export async function downloadRecordPdf(address, fetchFn = globalThis.fetch, saveFn = saveBlob) {
	try {
		const response = await fetchFn(recordPdfUrl(address), {
			headers: { requesttoken: globalThis.OC?.requestToken },
		})
		if (!response.ok) {
			const body = await response.json().catch(() => ({}))
			return { ok: false, message: body.message || body.error || `HTTP ${response.status}` }
		}

		saveFn(await response.blob(), filenameOf(response.headers?.get?.('Content-Disposition'), address.id))
		return { ok: true, message: null }
	} catch (error) {
		return { ok: false, message: error?.message || String(error) }
	}
}

/**
 * Save a blob through a temporary link.
 *
 * @param {Blob} blob The file
 * @param {string} filename Its name
 * @return {void}
 */
function saveBlob(blob, filename) {
	const url = URL.createObjectURL(blob)
	const link = document.createElement('a')
	link.href = url
	link.download = filename
	document.body.appendChild(link)
	link.click()
	link.remove()
	URL.revokeObjectURL(url)
}

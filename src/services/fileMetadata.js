/**
 * File metadata API client helpers.
 *
 * Thin wrappers over the OpenRegister file-metadata endpoints registered
 * by FilesController:
 *
 *   PUT /api/objects/{register}/{schema}/{id}/files/{fileId}/labels
 *   PUT /api/objects/{register}/{schema}/{id}/files/{fileId}
 *
 * Used by ViewObject.vue for the inline label editor + the
 * description/category editor. Extracted into a standalone module so
 * the API-call shape can be unit-tested without mounting the modal.
 *
 * Closes file-actions tasks 163, 164, 165.
 */

const BASE = '/index.php/apps/openregister/api/objects'

/**
 * Build the file metadata endpoint URL.
 *
 * @param {string|number} registerId The register id
 * @param {string|number} schemaId The schema id
 * @param {string} objectId The parent object id
 * @param {string|number} fileId The file id
 * @param {string} [suffix=''] Optional suffix (e.g. '/labels')
 * @return {string} The full endpoint URL
 */
function buildFileUrl(registerId, schemaId, objectId, fileId, suffix = '') {
	return `${BASE}/${registerId}/${schemaId}/${objectId}/files/${fileId}${suffix}`
}

/**
 * Update the labels of a file. PUT to /files/{fileId}/labels with `{ labels: [...] }`.
 *
 * @param {object} params Request parameters
 * @param {string|number} params.registerId Register id
 * @param {string|number} params.schemaId Schema id
 * @param {string} params.objectId Parent object id
 * @param {string|number} params.fileId File id
 * @param {string[]} params.labels New labels (use [] to clear all)
 * @return {Promise<object>} The formatted file response
 * @throws {Error} On HTTP error or network failure
 */
export async function updateFileLabels({ registerId, schemaId, objectId, fileId, labels }) {
	const url = buildFileUrl(registerId, schemaId, objectId, fileId, '/labels')
	const response = await fetch(url, {
		method: 'PUT',
		headers: { 'Content-Type': 'application/json' },
		body: JSON.stringify({ labels }),
	})
	if (!response.ok) {
		throw new Error(`Failed to update file labels: HTTP ${response.status}`)
	}
	return response.json()
}

/**
 * Update description/category metadata of a file. PUT to /files/{fileId}
 * with the standard metadata payload. Pass `null` for any field to leave
 * it unchanged; pass an empty string / empty array to explicitly clear it.
 *
 * @param {object} params Request parameters
 * @param {string|number} params.registerId Register id
 * @param {string|number} params.schemaId Schema id
 * @param {string} params.objectId Parent object id
 * @param {string|number} params.fileId File id
 * @param {string|null} [params.description] Description (or null to skip)
 * @param {string|null} [params.category] Category (or null to skip)
 * @param {string[]|null} [params.labels] Labels (or null to skip)
 * @return {Promise<object>} The formatted file response
 * @throws {Error} On HTTP error or network failure
 */
export async function updateFileMetadata({
	registerId,
	schemaId,
	objectId,
	fileId,
	description = null,
	category = null,
	labels = null,
}) {
	const url = buildFileUrl(registerId, schemaId, objectId, fileId)
	const payload = {}
	if (description !== null) payload.description = description
	if (category !== null) payload.category = category
	if (labels !== null) payload.labels = labels

	const response = await fetch(url, {
		method: 'PUT',
		headers: { 'Content-Type': 'application/json' },
		body: JSON.stringify(payload),
	})
	if (!response.ok) {
		throw new Error(`Failed to update file metadata: HTTP ${response.status}`)
	}
	return response.json()
}

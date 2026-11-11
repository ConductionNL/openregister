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
 * @param {object} file Which file: its register, schema, object and file ids
 * @param {string|number} file.registerId The register id
 * @param {string|number} file.schemaId The schema id
 * @param {string} file.objectId The parent object id
 * @param {string|number} file.fileId The file id
 * @param {string} suffix Suffix (e.g. '/labels', or '' for the file itself)
 * @return {string} The full endpoint URL
 */
function buildFileUrl({ registerId, schemaId, objectId, fileId }, suffix) {
	return `${BASE}/${registerId}/${schemaId}/${objectId}/files/${fileId}${suffix}`
}

/**
 * PUT a JSON body to a file endpoint.
 *
 * @param {string} url The endpoint
 * @param {object} body The body
 * @return {Promise<Response>} The response
 */
function putJson(url, body) {
	return fetch(url, {
		method: 'PUT',
		headers: { 'Content-Type': 'application/json' },
		body: JSON.stringify(body),
	})
}

/**
 * The updated file a response carries.
 *
 * @param {Response} response The response
 * @param {string} what What was updated, for the error (e.g. 'file labels')
 * @return {Promise<object>} The formatted file response
 * @throws {Error} When the response is not OK
 */
function updatedFile(response, what) {
	if (!response.ok) {
		throw new Error(`Failed to update ${what}: HTTP ${response.status}`)
	}
	return response.json()
}

/**
 * The metadata fields to send: description, category and labels, leaving
 * out those that are null or undefined.
 *
 * @param {object} params The request parameters
 * @param {string|null} [params.description] Description (or null to skip)
 * @param {string|null} [params.category] Category (or null to skip)
 * @param {string[]|null} [params.labels] Labels (or null to skip)
 * @return {object} The payload
 */
function metadataPayload({ description, category, labels }) {
	return Object.fromEntries(
		Object.entries({ description, category, labels }).filter(
			([, value]) => value !== null && value !== undefined,
		),
	)
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
 * @spec openspec/specs/frontend-app-bootstrap/spec.md
 */
export async function updateFileLabels(params) {
	const url = buildFileUrl(params, '/labels')
	const response = await putJson(url, { labels: params.labels })
	return updatedFile(response, 'file labels')
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
 * @spec openspec/specs/frontend-app-bootstrap/spec.md
 */
export async function updateFileMetadata(params) {
	const url = buildFileUrl(params, '')
	const response = await putJson(url, metadataPayload(params))
	return updatedFile(response, 'file metadata')
}

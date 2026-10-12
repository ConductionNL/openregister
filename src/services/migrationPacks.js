/**
 * Migration packs over `/api/migration-packs`: list, read, save, delete,
 * import, export and the sample-row preview.
 *
 * Every write goes through @nextcloud/axios, which sends the request token, so
 * the write routes can require CSRF. The admin flag here decides what the
 * screens OFFER; the server refuses a write from anyone else regardless.
 *
 * @spec openspec/changes/migration-pack-editor-ui/specs/migration-mapping-packs/spec.md#requirement-pack-changes-must-be-limited-to-administrators-and-logged
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */
import { getCurrentUser } from '@nextcloud/auth'
import axios from '@nextcloud/axios'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'

/** The largest number of sample rows the preview endpoint takes. */
export const PREVIEW_ROW_LIMIT = 20

/**
 * The URL of a migration-pack route.
 *
 * @param {string} [suffix] The part after `/api/migration-packs`.
 * @return {string} The URL.
 */
function packUrl(suffix = '') {
	return generateUrl('/apps/openregister/api/migration-packs' + suffix)
}

/**
 * Whether the signed-in user may change migration packs (a Nextcloud admin).
 *
 * Presentation only: it hides the controls; the server decides.
 *
 * @return {boolean} True for a Nextcloud administrator.
 *
 * @spec openspec/changes/migration-pack-editor-ui/specs/migration-mapping-packs/spec.md#requirement-pack-changes-must-be-limited-to-administrators-and-logged
 */
export function canEditPacks() {
	try {
		return getCurrentUser()?.isAdmin === true
	} catch {
		return false
	}
}

/**
 * Every migration pack.
 *
 * @return {Promise<Array<object>>} The packs as `GET /api/migration-packs` lists them.
 *
 * @spec openspec/changes/migration-pack-editor-ui/specs/migration-mapping-packs/spec.md#requirement-administrators-must-be-able-to-list-and-edit-migration-packs-in-the-openregister-ui
 */
export async function listPacks() {
	const response = await axios.get(packUrl())
	return response.data?.results || []
}

/**
 * One migration pack.
 *
 * @param {number|string} id The pack's row id.
 * @return {Promise<object>} The pack.
 *
 * @spec openspec/changes/migration-pack-editor-ui/specs/migration-mapping-packs/spec.md#requirement-administrators-must-be-able-to-list-and-edit-migration-packs-in-the-openregister-ui
 */
export async function getPack(id) {
	const response = await axios.get(packUrl('/' + encodeURIComponent(id)))
	return response.data
}

/**
 * Save a pack's definition.
 *
 * The definition travels in an envelope: its own `id` is the pack slug, and a
 * bare body would put that slug over the route's row id.
 *
 * @param {number|string} id The pack's row id.
 * @param {object} definition The full definition.
 * @return {Promise<object>} The saved pack.
 *
 * @spec openspec/changes/migration-pack-editor-ui/specs/migration-mapping-packs/spec.md#requirement-create-and-update-must-accept-a-definition-envelope-and-keep-the-route-id
 */
export async function updatePack(id, definition) {
	const response = await axios.put(packUrl('/' + encodeURIComponent(id)), {
		definition,
	})
	return response.data
}

/**
 * Delete a pack.
 *
 * @param {number|string} id The pack's row id.
 * @return {Promise<void>}
 *
 * @spec openspec/changes/migration-pack-editor-ui/specs/migration-mapping-packs/spec.md#requirement-administrators-must-be-able-to-list-and-edit-migration-packs-in-the-openregister-ui
 */
export async function deletePack(id) {
	await axios.delete(packUrl('/' + encodeURIComponent(id)))
}

/**
 * Import a pack from a JSON file. A pack with the same slug is updated.
 *
 * @param {File} file The uploaded file.
 * @return {Promise<object>} The created or updated pack.
 *
 * @spec openspec/changes/migration-pack-editor-ui/specs/migration-mapping-packs/spec.md#requirement-administrators-must-be-able-to-list-and-edit-migration-packs-in-the-openregister-ui
 */
export async function importPack(file) {
	const form = new FormData()
	form.append('file', file)
	const response = await axios.post(packUrl('/import'), form)
	return response.data
}

/**
 * The download URL of a pack's definition as a JSON file.
 *
 * @param {number|string} id The pack's row id.
 * @return {string} The URL.
 *
 * @spec openspec/changes/migration-pack-editor-ui/specs/migration-mapping-packs/spec.md#requirement-administrators-must-be-able-to-list-and-edit-migration-packs-in-the-openregister-ui
 */
export function exportUrl(id) {
	return packUrl('/' + encodeURIComponent(id) + '/export')
}

/**
 * Map sample rows with an unsaved definition.
 *
 * @param {object} definition The definition as the editor holds it.
 * @param {Array<object>} rows Sample rows, column name to value.
 * @return {Promise<Array<{rowNumber: number, data: object, errors: Array<object>}>>} One result per row.
 *
 * @spec openspec/changes/migration-pack-editor-ui/specs/migration-mapping-packs/spec.md#requirement-a-pack-must-be-testable-against-sample-rows-without-saving-it
 */
export async function previewPack(definition, rows) {
	const response = await axios.post(packUrl('/preview'), {
		definition,
		rows: rows.slice(0, PREVIEW_ROW_LIMIT),
	})
	return response.data?.rows || []
}

/**
 * A readable explanation of a failed request.
 *
 * The API answers `{message, error, details}`; older responses carry only
 * `error`. A validator refusal joins its findings with "; ", which reads badly
 * as one sentence, so it is split into a list.
 *
 * @param {unknown} error The rejected axios error.
 * @param {string} fallback The message when the server gave none.
 * @return {{message: string, details: Array<string>, code: string, status: number}} The explanation.
 *
 * @spec openspec/changes/migration-pack-editor-ui/specs/migration-mapping-packs/spec.md#requirement-administrators-must-be-able-to-list-and-edit-migration-packs-in-the-openregister-ui
 */
export function explainError(error, fallback) {
	const status = error?.response?.status || 0
	const body = error?.response?.data || {}
	const code = typeof body.error === 'string' ? body.error : ''
	let message = typeof body.message === 'string' ? body.message : code
	let details = Array.isArray(body.details?.errors)
		? body.details.errors.map(String)
		: []
	const prefix = 'Invalid migration pack definition: '
	if (details.length === 0 && message.startsWith(prefix)) {
		details = message.slice(prefix.length).split('; ')
		message = t('openregister', 'The pack is not valid.')
	}
	if (status === 403) {
		message = t(
			'openregister',
			'Only administrators can change migration packs.',
		)
	}
	if (!message) {
		message = fallback
	}
	return { message, details, code, status }
}

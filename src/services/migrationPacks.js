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
import { generateUrl } from '@nextcloud/router'
import { t } from '../views/migrationPack/l10n.js'

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
 * bare body would put that slug over the route's row id. With
 * `expectedUpdatedAt`, the server refuses the save with 409 `pack-changed`
 * when the pack was saved by someone else since it was read.
 *
 * @param {number|string} id The pack's row id.
 * @param {object} definition The full definition.
 * @param {string} [expectedUpdatedAt] The `updatedAt` of the pack as it was read.
 * @return {Promise<object>} The saved pack.
 *
 * @spec openspec/changes/migration-pack-editor-ui/specs/migration-mapping-packs/spec.md#requirement-create-and-update-must-accept-a-definition-envelope-and-keep-the-route-id
 */
export async function updatePack(id, definition, expectedUpdatedAt) {
	const body = { definition }
	if (typeof expectedUpdatedAt === 'string' && expectedUpdatedAt !== '') {
		body.expectedUpdatedAt = expectedUpdatedAt
	}
	const response = await axios.put(packUrl('/' + encodeURIComponent(id)), body)
	return response.data
}

/**
 * Create a pack from a definition.
 *
 * @param {object} definition The full definition.
 * @return {Promise<object>} The created pack.
 *
 * @spec openspec/changes/migration-pack-editor-ui/specs/migration-mapping-packs/spec.md#requirement-create-and-update-must-accept-a-definition-envelope-and-keep-the-route-id
 */
export async function createPack(definition) {
	const response = await axios.post(packUrl(), { definition })
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
 * The server answers 201 for a pack it created and 200 for one it replaced.
 *
 * @param {File} file The uploaded file.
 * @return {Promise<{pack: object, replaced: boolean}>} The created or updated pack, and whether it replaced a stored one.
 *
 * @spec openspec/changes/migration-pack-editor-ui/specs/migration-mapping-packs/spec.md#requirement-administrators-must-be-able-to-list-and-edit-migration-packs-in-the-openregister-ui
 */
export async function importPack(file) {
	const form = new FormData()
	form.append('file', file)
	const response = await axios.post(packUrl('/import'), form)
	return { pack: response.data, replaced: response.status === 200 }
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
 * The text this page shows for each error slug of the migration-pack API.
 *
 * The API answers every error as `{message, error, details?}` (ADR-050), with
 * `error` a kebab-case slug. The screens show their own translation of the
 * slug; they never read meaning out of the English wording of `message`.
 * `migration-pack-error` (an unexpected failure) is left out on purpose: the
 * caller's own fallback says what could not be done.
 *
 * @return {object} Slug to text.
 *
 * @spec openspec/changes/migration-pack-editor-ui/specs/migration-mapping-packs/spec.md#requirement-administrators-must-be-able-to-list-and-edit-migration-packs-in-the-openregister-ui
 */
function slugTexts() {
	return {
		'invalid-pack': t('openregister', 'The pack is not valid.'),
		'builtin-slug-locked': t(
			'openregister',
			'The slug of a built-in pack cannot change: the app that installed it finds the pack by this slug.',
		),
		'pack-slug-exists': t(
			'openregister',
			'Another pack already has this slug. Choose a different slug.',
		),
		'pack-not-found': t('openregister', 'This migration pack does not exist.'),
		'not-authenticated': t(
			'openregister',
			'You are not signed in. Sign in and try again.',
		),
		'admin-required': t(
			'openregister',
			'Only administrators can change migration packs.',
		),
		'too-many-rows': t(
			'openregister',
			'There are too many sample rows to test at once.',
		),
	}
}

/** The shape of an API error slug: lowercase words joined by hyphens. */
const SLUG_PATTERN = /^[a-z0-9]+(-[a-z0-9]+)*$/

/**
 * A readable explanation of a failed request.
 *
 * Reads the slug and `details.errors` of the API's error body. A known slug
 * gets this page's own text; an unknown one gets the server's message, which
 * the API localises; anything else gets the caller's fallback. A 403 always
 * means the user is not an administrator.
 *
 * @param {unknown} error The rejected axios error.
 * @param {string} fallback The message when the slug is not one this page knows.
 * @param {object} [texts] Slug to text, for the caller's own wording of a slug.
 * @return {{message: string, details: Array<string>, code: string, status: number}} The explanation.
 *
 * @spec openspec/changes/migration-pack-editor-ui/specs/migration-mapping-packs/spec.md#requirement-administrators-must-be-able-to-list-and-edit-migration-packs-in-the-openregister-ui
 */
export function explainError(error, fallback, texts = {}) {
	const status = error?.response?.status || 0
	const body = error?.response?.data || {}
	const code =
		typeof body.error === 'string' && SLUG_PATTERN.test(body.error)
			? body.error
			: ''
	const details = Array.isArray(body.details?.errors)
		? body.details.errors.map(String)
		: []
	const known = { ...slugTexts(), ...texts }
	let message = fallback
	if (status === 403) {
		message = known['admin-required']
	} else if (Object.hasOwn(known, code)) {
		message = known[code]
	} else if (code !== '' && code !== 'migration-pack-error') {
		message =
			typeof body.message === 'string' && body.message !== ''
				? body.message
				: fallback
	}
	return { message, details, code, status }
}

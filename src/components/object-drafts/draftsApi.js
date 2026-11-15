/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The calls the Drafts tab makes: the versions routes of one object and the
 * schema's `previewUrl` (records-draft-versions 5.1, 5.2).
 */
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

/**
 * The versions route of one object, optionally of one draft and an action on it.
 *
 * @param {{register: (string|number), schema: (string|number), id: string}} target The object.
 * @param {string} [key] A draft key.
 * @param {string} [action] `promote` or `preview`.
 * @spec openspec/changes/records-draft-versions
 * @return {string} The URL.
 */
export function versionsUrl(target, key = '', action = '') {
	let path = '/apps/openregister/api/objects/{register}/{schema}/{id}/versions'
	const params = { register: target.register, schema: target.schema, id: target.id }
	if (key) {
		path += '/{key}'
		params.key = key
	}

	if (key && action) {
		path += '/' + action
	}

	return generateUrl(path, params)
}

/**
 * The drafts of an object the caller may see.
 *
 * @param {object} target The object.
 * @spec openspec/changes/records-draft-versions/specs/content-versioning/spec.md#requirement-drafts-are-managed-on-the-object-page
 * @return {Promise<Array<object>>} The draft summaries.
 */
export async function listDrafts(target) {
	const response = await axios.get(versionsUrl(target))
	return Array.isArray(response.data?.results) ? response.data.results : []
}

/**
 * One draft merged onto the published object.
 *
 * @param {object} target The object.
 * @param {string} key The draft key.
 * @spec openspec/changes/records-draft-versions
 * @return {Promise<object>} The merged object.
 */
export async function readDraft(target, key) {
	const response = await axios.get(versionsUrl(target, key))
	return response.data || {}
}

/**
 * Create a draft holding `changes`.
 *
 * @param {object} target The object.
 * @param {{key: string, name: string, changes: object}} draft The new draft.
 * @spec openspec/changes/records-draft-versions
 * @return {Promise<object>} The created draft's summary.
 */
export async function createDraft(target, draft) {
	const response = await axios.post(versionsUrl(target), { key: draft.key, name: draft.name, object: draft.changes })
	return response.data
}

/**
 * Add changes to an existing draft.
 *
 * @param {object} target The object.
 * @param {string} key The draft key.
 * @param {object} changes The changed fields.
 * @spec openspec/changes/records-draft-versions
 * @return {Promise<object>} The saved draft's summary.
 */
export async function updateDraft(target, key, changes) {
	const response = await axios.put(versionsUrl(target, key), { object: changes })
	return response.data
}

/**
 * Discard a draft; the published object is not touched.
 *
 * @param {object} target The object.
 * @param {string} key The draft key.
 * @spec openspec/changes/records-draft-versions
 * @return {Promise<void>}
 */
export async function discardDraft(target, key) {
	await axios.delete(versionsUrl(target, key))
}

/**
 * Promote a draft to the published version. A 409 rejects with the conflicts
 * on `error.response.data.conflicts`.
 *
 * @param {object} target The object.
 * @param {string} key The draft key.
 * @param {boolean} [force] Overwrite conflicting fields (administrators only).
 * @spec openspec/changes/records-draft-versions
 * @return {Promise<object>} The published object.
 */
export async function promoteDraft(target, key, force = false) {
	const response = await axios.post(versionsUrl(target, key, 'promote'), force ? { force: true } : {})
	return response.data
}

/**
 * Make a preview link for a draft; the answer carries the filled `previewUrl`.
 *
 * @param {object} target The object.
 * @param {string} key The draft key.
 * @spec openspec/changes/records-draft-versions/specs/content-versioning/spec.md#requirement-a-draft-can-be-previewed-on-an-outside-site-through-an-expiring-link
 * @return {Promise<object>} The link.
 */
export async function previewDraft(target, key) {
	const response = await axios.post(versionsUrl(target, key, 'preview'), {})
	return response.data || {}
}

/**
 * The schema's preview URL template, or null when it declares none or cannot be read.
 *
 * @param {string|number} schema The schema id or slug.
 * @spec openspec/changes/records-draft-versions
 * @return {Promise<string|null>} The template.
 */
export async function schemaPreviewUrl(schema) {
	try {
		const response = await axios.get(generateUrl('/apps/openregister/api/schemas/{schema}', { schema }))
		const template = response.data?.configuration?.previewUrl
		return typeof template === 'string' && template !== '' ? template : null
	} catch {
		return null
	}
}

/**
 * The rows of a compare: each changed field with its published and draft value.
 *
 * @param {Array<string>} fields The draft's changed fields.
 * @param {object} published The published object.
 * @param {object} merged The draft merged onto it.
 * @spec openspec/changes/records-draft-versions
 * @return {Array<{field: string, published: string, draft: string}>} The rows.
 */
export function compareRows(fields, published, merged) {
	return (fields || []).map((field) => ({
		field,
		published: displayValue(published?.[field]),
		draft: displayValue(merged?.[field]),
	}))
}

/**
 * A value as it reads in the compare table.
 *
 * @param {(string|number|boolean|object|null|undefined)} value The value.
 * @spec exclude display formatting, UI plumbing
 * @return {string} The text.
 */
export function displayValue(value) {
	if (value === undefined || value === null) {
		return '—'
	}

	return typeof value === 'object' ? JSON.stringify(value) : String(value)
}

/**
 * Parse the changes typed in the draft form: a JSON object, or null when it is not one.
 *
 * @param {string} text The typed text.
 * @spec openspec/changes/records-draft-versions
 * @return {object|null} The changes.
 */
export function parseChanges(text) {
	try {
		const value = JSON.parse(text)
		return value !== null && typeof value === 'object' && !Array.isArray(value) ? value : null
	} catch {
		return null
	}
}

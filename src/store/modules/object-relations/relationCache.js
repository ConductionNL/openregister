/**
 * SPDX-FileCopyrightText: 2026 Conduction <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

/**
 * Shared request and cache steps of the object-relation stores (Deck cards,
 * contacts, emails, calendar events). Each store keeps a per-object list in
 * `byObject`, per-object `loading` and `errors`, and one flag that is set
 * when the backing Nextcloud app is not installed (HTTP 501).
 *
 * @spec openspec/changes/retrofit-2026-05-24-data-integrity-relations/tasks.md#task-4
 */

/**
 * The relation endpoint URL of an object.
 *
 * @param {string} kind The relation path segment, e.g. `deck` or `emails`.
 * @param {{register: string, schema: string, id: string, suffix: string}} ref
 *     The object and the path suffix after the relation segment.
 * @return {string} The URL.
 *
 * @spec openspec/changes/retrofit-2026-05-24-data-integrity-relations/tasks.md#task-4
 */
export function relationUrl(kind, ref) {
	return generateUrl(
		'/apps/openregister/api/objects/{register}/{schema}/{id}/'
			+ kind
			+ ref.suffix,
		{ register: ref.register, schema: ref.schema, id: ref.id },
	)
}

/**
 * Fetch and cache the relations of one object. A 501 answer means the backing
 * app is missing: the flag is set and the list is empty. Other failures are
 * stored per object and rethrown.
 *
 * @param {object} store The relation store.
 * @param {{url: string, key: string, flag: string, arraysOnly?: boolean}} request
 *     The endpoint, the cache key, the name of the unavailable flag and
 *     whether a non-list answer becomes an empty list.
 * @return {Promise<Array>} The cached list.
 *
 * @spec openspec/changes/retrofit-2026-05-24-data-integrity-relations/tasks.md#task-4
 */
export async function loadRelations(store, request) {
	const k = request.key
	store.loading = { ...store.loading, [k]: true }
	store.errors = { ...store.errors, [k]: null }
	store[request.flag] = false

	try {
		const response = await axios.get(request.url)
		const list = relationList(response.data, request.arraysOnly)
		store.byObject = { ...store.byObject, [k]: list }
		return list
	} catch (err) {
		if (err.response?.status === 501) {
			store[request.flag] = true
			store.byObject = { ...store.byObject, [k]: [] }
			return []
		}

		store.errors = {
			...store.errors,
			[k]: err.response?.data?.error || err.message || '',
		}
		throw err
	} finally {
		store.loading = { ...store.loading, [k]: false }
	}
}

/**
 * The relation list in an answer: its `results`, or the answer itself.
 *
 * @param {unknown} data The answer body.
 * @param {boolean} arraysOnly Whether anything but a list becomes an empty list.
 * @return {Array|object} The list.
 */
function relationList(data, arraysOnly) {
	const list = data?.results || data || []
	return arraysOnly && !Array.isArray(list) ? [] : list
}

/**
 * Post to a relation endpoint, then refetch the object's relations.
 *
 * @param {object} store The relation store.
 * @param {{url: string, payload: object, object: Array}} request The endpoint,
 *     the body and the register, schema and id to refetch.
 * @return {Promise<object>} The answer body of the post.
 *
 * @spec openspec/changes/retrofit-2026-05-24-data-integrity-relations/tasks.md#task-4
 */
export async function postAndRefetch(store, request) {
	const response = await axios.post(request.url, request.payload)
	await store.fetch(...request.object)
	return response.data
}

/**
 * Drop relations from an object's cached list.
 *
 * @param {object} store The relation store.
 * @param {{key: string, keep: (relation: object) => boolean}} request The cache key and the test a
 *     relation must pass to stay.
 * @return {Array} The remaining list.
 *
 * @spec openspec/changes/retrofit-2026-05-24-data-integrity-relations/tasks.md#task-4
 */
export function pruneRelations(store, request) {
	const next = (store.byObject[request.key] || []).filter(request.keep)
	store.byObject = { ...store.byObject, [request.key]: next }
	return next
}

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
	startLoading(store, request)

	try {
		const response = await axios.get(request.url)
		const list = relationList(response.data, request.arraysOnly)
		store.byObject = { ...store.byObject, [k]: list }
		return list
	} catch (err) {
		if (err.response?.status === 501) {
			return markUnavailable(store, request)
		}

		store.errors = { ...store.errors, [k]: relationErrorText(err) }
		throw err
	} finally {
		store.loading = { ...store.loading, [k]: false }
	}
}

/**
 * Mark an object's relations as loading: no error yet, and the backing app
 * assumed present.
 *
 * @param {object} store The relation store.
 * @param {{key: string, flag: string}} request The cache key and the name of the unavailable flag.
 * @return {void}
 */
function startLoading(store, request) {
	const k = request.key
	store.loading = { ...store.loading, [k]: true }
	store.errors = { ...store.errors, [k]: null }
	store[request.flag] = false
}

/**
 * Mark the backing app as missing and cache an empty list for the object.
 *
 * @param {object} store The relation store.
 * @param {{key: string, flag: string}} request The cache key and the name of the unavailable flag.
 * @return {Array} The empty list.
 */
function markUnavailable(store, request) {
	store[request.flag] = true
	store.byObject = { ...store.byObject, [request.key]: [] }
	return []
}

/**
 * What a failed relation read stores as the object's error.
 *
 * @param {object} err The failure.
 * @return {string} The server's error, else the failure's message, else empty.
 */
function relationErrorText(err) {
	return err.response?.data?.error || err.message || ''
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

/**
 * The state of a relation store, with its unavailable flag cleared.
 *
 * @param {string} flag The name of the flag set when the backing app is missing.
 * @return {object} The state.
 *
 * @spec openspec/changes/retrofit-2026-05-24-data-integrity-relations/tasks.md#task-4
 */
export function relationState(flag) {
	return { byObject: {}, loading: {}, errors: {}, [flag]: false }
}

/**
 * A store action that posts a payload to an object's relation endpoint and
 * refetches the object's relations.
 *
 * @param {string} suffix The path after the relation segment, e.g. `/link`.
 * @return {function(string, string, string, object): Promise<Array>} The action.
 *
 * @spec openspec/changes/retrofit-2026-05-24-data-integrity-relations/tasks.md#task-4
 */
export function postAction(suffix) {
	return async function (register, schema, id, payload) {
		return postAndRefetch(this, {
			url: this._url(register, schema, id, suffix),
			payload,
			object: [register, schema, id],
		})
	}
}

/**
 * The actions every relation store shares: the endpoint URL, fetching the
 * relations of an object, unlinking one, and reading the cached list.
 *
 * @param {{kind: string, flag: string, arraysOnly?: boolean, refOf: function(object): string}} relation
 *     The relation path segment, the unavailable flag, whether a non-list
 *     answer becomes an empty list, and the reference an unlink matches.
 * @return {object} The actions.
 *
 * @spec openspec/changes/retrofit-2026-05-24-data-integrity-relations/tasks.md#task-4
 */
export function relationActions(relation) {
	return {
		/**
		 * The relation endpoint URL of an object, with an optional path suffix.
		 *
		 * @param {string} register The register.
		 * @param {string} schema The schema.
		 * @param {string|number} id The object id.
		 * @param {string} suffix The path after the relation segment.
		 * @return {string} The URL.
		 * @spec openspec/changes/retrofit-2026-05-24-data-integrity-relations/tasks.md#task-4
		 */
		_url(register, schema, id, suffix = '') {
			return relationUrl(relation.kind, { register, schema, id, suffix })
		},
		/**
		 * Fetch and cache the relations of an object.
		 *
		 * @param {string} register The register.
		 * @param {string} schema The schema.
		 * @param {string|number} id The object id.
		 * @return {Promise<Array>} The cached list.
		 * @spec openspec/changes/retrofit-2026-05-24-data-integrity-relations/tasks.md#task-4
		 */
		async fetch(register, schema, id) {
			return loadRelations(this, {
				url: this._url(register, schema, id),
				key: `${register}:${schema}:${id}`,
				flag: relation.flag,
				arraysOnly: relation.arraysOnly,
			})
		},
		/**
		 * Unlink one relation and prune it from the object's cached list.
		 *
		 * @param {string} register The register.
		 * @param {string} schema The schema.
		 * @param {string|number} id The object id.
		 * @param {string} ref The reference of the relation to unlink.
		 * @return {Array} The pruned list.
		 * @spec openspec/changes/retrofit-2026-05-24-data-integrity-relations/tasks.md#task-4
		 */
		async unlink(register, schema, id, ref) {
			await axios.delete(
				this._url(register, schema, id, '/' + encodeURIComponent(ref)),
			)
			return pruneRelations(this, {
				key: `${register}:${schema}:${id}`,
				keep: (item) => relation.refOf(item) !== ref,
			})
		},
		/**
		 * The cached relations of an object.
		 *
		 * @param {string} register The register.
		 * @param {string} schema The schema.
		 * @param {string|number} id The object id.
		 * @return {Array} The cached list, or an empty list.
		 * @spec openspec/changes/retrofit-2026-05-24-data-integrity-relations/tasks.md#task-4
		 */
		get(register, schema, id) {
			return this.byObject[`${register}:${schema}:${id}`] || []
		},
	}
}

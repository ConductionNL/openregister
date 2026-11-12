/**
 * Paged list reads for the audit trail, search trail and deleted-objects
 * stores. Each store keeps its own search, filters and sort; these helpers
 * turn them into the query the list endpoint reads, fetch the page, and turn
 * the answer back into store pagination. `requestJson` and `objectAnswer`
 * check single answers for any store.
 *
 * @spec openspec/specs/audit-trail-immutable/spec.md
 */

import { withRequestToken } from './requestToken.js'

/**
 * Append each `{ field: direction }` pair as a `sort` and an `order` parameter.
 *
 * @param {URLSearchParams} params The query being built.
 * @param {object|undefined} sort The sort map, or nothing.
 */
function appendSort(params, sort) {
	if (!sort) {
		return
	}
	Object.entries(sort).forEach(([field, direction]) => {
		params.append('sort', field)
		params.append('order', direction)
	})
}

/**
 * The query string of one list page.
 *
 * Paging (`limit`, `offset`, `page`) comes from the options only. Search and
 * sort come from the options, else from the store. Filters are the store's,
 * overlaid with the options' ones; null, undefined and empty values are left out.
 *
 * @param {object} options The caller's `{ limit, offset, page, search, filters, sort }`.
 * @param {object} current The store's `{ search, filters, sort }`.
 * @return {string} The encoded query, without a leading `?`.
 *
 * @spec openspec/specs/audit-trail-immutable/spec.md
 */
export function listPageQuery(options, current) {
	const params = new URLSearchParams()
	;['limit', 'offset', 'page'].forEach((key) => {
		if (options[key]) params.append(key, options[key])
	})
	const search = options.search || current.search
	if (search) {
		params.append('search', search)
	}
	const filters = { ...current.filters, ...options.filters }
	Object.entries(filters).forEach(([key, value]) => {
		if (value !== null && value !== undefined && value !== '') {
			params.append(key, value)
		}
	})
	appendSort(params, options.sort || current.sort)
	return params.toString()
}

/**
 * GET a list endpoint and return its JSON body.
 *
 * @param {string} url The endpoint with its query.
 * @param {string} failure The error message when the body names none.
 * @return {Promise<object>} The response body.
 * @throws {Error} When the response is not ok.
 *
 * @spec openspec/specs/audit-trail-immutable/spec.md
 */
export async function getListPage(url, failure) {
	return requestJson(url, { method: 'GET', failure })
}

/**
 * Send a JSON request with the Nextcloud request token and read the answer.
 *
 * @param {string} url The request URL.
 * @param {{method: string, body?: object, failure: string}} request The
 *     method, an optional body to send as JSON, and the error text used when
 *     the server gives none.
 * @return {Promise<object>} The parsed answer.
 * @throws {Error} The server's error text, or `failure`, when the answer is not ok.
 *
 * @spec openspec/specs/audit-trail-immutable/spec.md
 */
export async function requestJson(url, request) {
	const response = await fetch(url, {
		method: request.method,
		headers: {
			'Content-Type': 'application/json',
			requesttoken: OC.requestToken,
		},
		...(request.body === undefined
			? {}
			: { body: JSON.stringify(request.body) }),
	})
	const data = await response.json()
	if (!response.ok) {
		throw new Error(data.error || request.failure)
	}
	return data
}

/**
 * The store pagination of one list answer.
 *
 * @param {object} data The response body.
 * @param {number} defaultLimit The page size when the body names none.
 * @return {{total: number, page: number, pages: number, limit: number, offset: number}} The pagination.
 *
 * @spec openspec/specs/audit-trail-immutable/spec.md
 */
export function listPagination(data, defaultLimit) {
	const fallback = { total: 0, page: 1, pages: 1, limit: defaultLimit, offset: 0 }
	return Object.fromEntries(
		Object.entries(fallback).map(([key, value]) => [key, data[key] || value]),
	)
}

/**
 * The object a JSON answer holds, once the answer is OK.
 *
 * @param {Response} response The fetch response.
 * @return {Promise<object>} The parsed body.
 * @throws {Error} The HTTP status when the answer is not OK, or a note that the body is not an object.
 * @spec exclude Shared response check of thin API passthroughs.
 */
export async function objectAnswer(response) {
	if (!response.ok) {
		throw new Error(`HTTP error! status: ${response.status}`)
	}
	const data = await response.json()
	if (!data || typeof data !== 'object') {
		throw new Error('Invalid response data')
	}
	return data
}

/**
 * The parsed body of an answer, once the answer is OK.
 *
 * @param {Response} response The fetch response.
 * @return {Promise<object>} The parsed body.
 * @throws {Error} The HTTP status when the answer is not OK.
 * @spec exclude Shared response check of thin API passthroughs.
 */
export async function okJson(response) {
	if (!response.ok) {
		throw new Error(`HTTP error! status: ${response.status}`)
	}
	return response.json()
}

/**
 * The object an import answer holds, once the answer is OK.
 *
 * @param {Response} response The fetch response.
 * @return {Promise<object>} The parsed body.
 * @throws {Error} The answer's own error or the HTTP status when the answer is not OK, or a note that the body is not an object.
 * @spec exclude Shared response check of the import passthroughs.
 */
export async function importAnswer(response) {
	const responseData = await response.json()
	if (!response.ok) {
		throw new Error(
			responseData?.error || `HTTP error! status: ${response.status}`,
		)
	}
	if (!responseData || typeof responseData !== 'object') {
		throw new Error('Invalid response data')
	}
	return responseData
}

/**
 * Send a body as JSON.
 *
 * @param {string} endpoint The URL.
 * @param {{method: string, body: object}} request The method and the body.
 * @return {Promise<Response>} The fetch response.
 * @spec exclude Shared request step of thin API passthroughs.
 */
export function sendJson(endpoint, request) {
	return fetch(endpoint, withRequestToken({
		method: request.method,
		headers: {
			'Content-Type': 'application/json',
		},
		body: JSON.stringify(request.body),
	}))
}

/**
 * A copy of an item without the fields the server sets (id, uuid, created, updated).
 *
 * @param {object} item The item.
 * @return {object} The copy to send.
 * @spec exclude Client-side payload sanitiser — strips read-only fields before save. No standalone backend contract.
 */
export function withoutReadOnly(item) {
	const cleaned = { ...item }
	delete cleaned.id
	delete cleaned.uuid
	delete cleaned.created
	delete cleaned.updated
	return cleaned
}

/**
 * GET an endpoint and read its JSON body.
 *
 * @param {string} endpoint The URL.
 * @return {Promise<object>} The parsed body.
 * @spec exclude Shared request step of thin API passthroughs.
 */
export async function getJson(endpoint) {
	const response = await fetch(endpoint, {
		method: 'GET',
	})
	return response.json()
}

/**
 * Send one JSON request for a store: raise the store's loading flag while it
 * runs, hand the answer to `keep`, and log a failure before rethrowing it.
 *
 * @param {object} store The store the request is for.
 * @param {{url: string, request: object, log: function(Error): void, flag?: string, keep?: function(object): void}} job
 *     The URL and request (as `requestJson` takes them), what logs a
 *     failure, and optionally the flag to raise and what to do with the answer.
 * @return {Promise<object>} The parsed answer.
 * @throws {Error} The request's failure.
 *
 * @spec openspec/specs/audit-trail-immutable/spec.md
 */
export async function storeRequest(store, job) {
	if (job.flag) {
		store[job.flag] = true
	}
	try {
		const data = await requestJson(job.url, job.request)
		job.keep?.(data)
		return data
	} catch (error) {
		job.log(error)
		throw error
	} finally {
		if (job.flag) {
			store[job.flag] = false
		}
	}
}

/**
 * Read through a store action; a failure is logged and answered with the fallback.
 *
 * @param {function(): Promise<object|Array>} read The read, returning the value.
 * @param {{log: function(Error): void, fallback: function(): (object|Array)}} job What logs a failure, and the fallback value's factory.
 * @return {Promise<object|Array>} What the read or the fallback returns.
 *
 * @spec openspec/specs/audit-trail-immutable/spec.md
 */
export async function readOrFallback(read, job) {
	try {
		return await read()
	} catch (error) {
		job.log(error)
		return job.fallback()
	}
}

/**
 * The list without the items whose id is listed.
 *
 * @param {Array<object>} list The list.
 * @param {Array} ids The ids to leave out.
 * @return {Array<object>} The remaining items.
 *
 * @spec openspec/specs/audit-trail-immutable/spec.md
 */
export function withoutIds(list, ids) {
	return list.filter((item) => !ids.includes(item.id))
}

/**
 * A store's statistics after fetching them, or the empty statistics when the
 * fetch fails.
 *
 * @param {object} store A store with `fetchStatistics()` and `statistics`.
 * @param {{empty: function(): object, log: function(Error): void}} job The empty statistics' factory, and what logs a failure.
 * @return {Promise<object>} The statistics.
 *
 * @spec openspec/specs/audit-trail-immutable/spec.md
 */
export function statisticsOrEmpty(store, job) {
	return readOrFallback(
		async () => {
			await store.fetchStatistics()
			return store.statistics
		},
		{ log: job.log, fallback: job.empty },
	)
}

/**
 * A deep copy of a list answer's results, or an empty list when it has none.
 *
 * @param {object} data The list answer.
 * @return {Array<object>} The results.
 * @spec openspec/specs/audit-trail-immutable/spec.md
 */
export function copiedResults(data) {
	return data.results ? JSON.parse(JSON.stringify(data.results)) : []
}

/**
 * A GET request for `requestJson`, with the error text used when the server gives none.
 *
 * @param {string} failure The error text.
 * @return {{method: string, failure: string}} The request.
 * @spec openspec/specs/audit-trail-immutable/spec.md
 */
export function getRequest(failure) {
	return { method: 'GET', failure }
}

/**
 * Run a request; a failure is logged and rethrown, wrapped in the `failure`
 * text when one is given.
 *
 * @param {{run: function(): Promise<object>, log: function(Error): void, failure?: string}} job
 *     The request, what logs a failure, and the text a failure is wrapped in.
 * @return {Promise<object>} What the request returns.
 * @spec exclude Shared failure handling of thin API passthroughs.
 */
export async function loggedRequest(job) {
	try {
		return await job.run()
	} catch (error) {
		job.log(error)
		throw job.failure
			? new Error(`${job.failure}: ${error.message}`, { cause: error })
			: error
	}
}

/**
 * The parsed body of an answer; when the answer is not OK, its own error, else the HTTP status.
 *
 * @param {Response} response The fetch response.
 * @return {Promise<object>} The parsed body.
 * @throws {Error} The answer's error or the HTTP status when the answer is not OK.
 * @spec exclude Shared response check of thin API passthroughs.
 */
export async function answerOrError(response) {
	const data = await response.json()
	if (!response.ok) {
		throw new Error(data.error || `HTTP error! status: ${response.status}`)
	}
	return data
}

/**
 * Where and how to send an item: PUT to its own URL when it exists, else POST to the collection.
 *
 * @param {string} collection The collection URL.
 * @param {object|null|false} existing The existing item, if any.
 * @return {{endpoint: string, method: string}} The endpoint and method.
 * @spec exclude Shared request step of thin API passthroughs.
 */
export function itemTarget(collection, existing) {
	return existing
		? { endpoint: `${collection}/${existing.id}`, method: 'PUT' }
		: { endpoint: collection, method: 'POST' }
}

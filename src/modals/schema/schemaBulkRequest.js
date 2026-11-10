/**
 * The bulk requests the schema object modals send: a JSON POST, and the
 * answer read back or turned into the error it reports.
 *
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */

/**
 * POST to a bulk endpoint, with a JSON body when there is a payload.
 *
 * @param {string} url The endpoint
 * @param {object} [payload] The body, left out when undefined
 * @return {Promise<Response>} The fetch response
 */
export function postJson(url, payload) {
	const init = {
		method: 'POST',
		headers: {
			'Content-Type': 'application/json',
		},
	}
	if (payload !== undefined) {
		init.body = JSON.stringify(payload)
	}
	return fetch(url, init)
}

/**
 * The JSON body of a bulk answer, or the error it reports: a failed HTTP
 * status as "<action> failed: <status text>", or an `error` in the body.
 *
 * @param {Response} response The fetch response
 * @param {string} action The action that failed, e.g. 'Deletion'
 * @return {Promise<object>} The body
 */
export async function bulkResultOf(response, action) {
	if (!response.ok) {
		throw new Error(`${action} failed: ${response.statusText}`)
	}

	const data = await response.json()

	if (data.error) {
		throw new Error(data.error)
	}

	return data
}

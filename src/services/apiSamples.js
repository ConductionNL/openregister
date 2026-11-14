/**
 * Code samples for one API operation (api-explorer-in-the-app, REQ-APIX-001).
 *
 * A pure function from an operation, its parameters and the instance URL to a
 * curl command and a `fetch` snippet that make the same call. The samples
 * authenticate with a Nextcloud app password, shown as a placeholder: a sample
 * never carries the signed-in user's session or a real secret.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @spec openspec/changes/api-explorer-in-the-app/tasks.md
 */

/** The user name a sample shows in place of the reader's own. */
export const USER_PLACEHOLDER = 'USERNAME'

/** The app password a sample shows; the reader creates one under Personal settings, Security. */
export const APP_PASSWORD_PLACEHOLDER = 'APP_PASSWORD'

/** The methods whose call carries a JSON body. */
const BODY_METHODS = ['POST', 'PUT', 'PATCH']

/**
 * Quote a value for a POSIX shell: single quotes, with each single quote closed, escaped and reopened.
 *
 * @param {string} value The value.
 * @return {string} The quoted value.
 */
export function shellQuote(value) {
	return "'" + String(value).replace(/'/g, "'\\''") + "'"
}

/**
 * Quote a value as a JavaScript single-quoted string literal.
 *
 * @param {string} value The value.
 * @return {string} The literal.
 */
function jsString(value) {
	return "'" + String(value).replace(/\\/g, '\\\\').replace(/'/g, "\\'").replace(/\n/g, '\\n') + "'"
}

/**
 * The full URL of a call: the instance URL, the path with its parameters filled in, and the query.
 *
 * A path parameter without a value stays as `{name}`, so the sample shows what is still to fill in.
 *
 * @param {string} instanceUrl The instance's base URL, such as `https://cloud.example.org/index.php/apps/openregister`.
 * @param {string} path The operation's path, such as `/api/objects/{register}/{schema}`.
 * @param {object} pathParams Values for the path's `{name}` parameters.
 * @param {object} query Query parameters; empty, null and undefined values are left out.
 * @return {string} The URL.
 */
export function operationUrl(instanceUrl, path, pathParams = {}, query = {}) {
	const filled = String(path).replace(/{(\w+)}/g, (whole, name) => {
		const value = pathParams[name]
		return value === undefined || value === null || value === '' ? whole : encodeURIComponent(String(value))
	})
	const search = new URLSearchParams()
	for (const [key, value] of Object.entries(query ?? {})) {
		if (value !== undefined && value !== null && value !== '') {
			search.append(key, String(value))
		}
	}
	const queryString = search.toString()
	return String(instanceUrl).replace(/\/+$/, '') + '/' + filled.replace(/^\/+/, '') + (queryString === '' ? '' : '?' + queryString)
}

/**
 * The curl and fetch samples for one call.
 *
 * @param {object} operation The operation.
 * @param {string} operation.method The HTTP method, any case.
 * @param {string} operation.path The path, with `{name}` parameters.
 * @param {object} [operation.pathParams] Values for the path parameters.
 * @param {object} [operation.query] Query parameters.
 * @param {object|Array|string|number|boolean|null} [operation.body] The JSON body; used for POST, PUT and PATCH only.
 * @param {string} instanceUrl The instance's base URL.
 * @return {{url: string, curl: string, fetch: string}} The URL and both samples.
 */
export function apiSamples({ method, path, pathParams = {}, query = {}, body }, instanceUrl) {
	const verb = String(method).toUpperCase()
	const url = operationUrl(instanceUrl, path, pathParams, query)
	const hasBody = BODY_METHODS.includes(verb) && body !== undefined
	const json = hasBody ? JSON.stringify(body, null, 2) : null
	const credentials = USER_PLACEHOLDER + ':' + APP_PASSWORD_PLACEHOLDER

	const curl = ['curl -u ' + shellQuote(credentials)]
	if (verb !== 'GET') {
		curl.push('-X ' + verb)
	}
	curl.push(shellQuote(url))
	curl.push('-H ' + shellQuote('Accept: application/json'))
	if (hasBody) {
		curl.push('-H ' + shellQuote('Content-Type: application/json'))
		curl.push('--data ' + shellQuote(json))
	}

	const headers = [
		"\t\tAccept: 'application/json',",
		"\t\tAuthorization: 'Basic ' + btoa(" + jsString(credentials) + '),',
	]
	if (hasBody) {
		headers.push("\t\t'Content-Type': 'application/json',")
	}
	const options = ['\tmethod: ' + jsString(verb) + ',', '\theaders: {', ...headers, '\t},']
	if (hasBody) {
		options.push('\tbody: JSON.stringify(' + json.replace(/\n/g, '\n\t') + '),')
	}
	const fetchSample = [
		'const response = await fetch(' + jsString(url) + ', {',
		...options,
		'})',
		'const data = await response.json()',
	].join('\n')

	return { url, curl: curl.join(' \\\n  '), fetch: fetchSample }
}

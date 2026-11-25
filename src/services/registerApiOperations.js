/**
 * The schema operations of a register, read from its OpenAPI description
 * (api-explorer-in-the-app, REQ-APIX-001).
 *
 * Pure functions: from the document `GET /api/registers/{id}/oas` answers to
 * the list the API page shows, and from an operation and the values a reader
 * typed to the request the page sends.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @spec openspec/changes/api-explorer-in-the-app/specs/graphql-api/spec.md#requirement-req-apix-001-the-api-can-be-tried-from-inside-the-app
 */

/** The methods the page lists, in the order it lists them. */
export const LISTED_METHODS = ['get', 'post', 'put', 'patch', 'delete']

/** The methods whose call carries a JSON body. */
export const BODY_METHODS = ['post', 'put', 'patch']

/** Where a schema's object paths start in the register's document. */
const OBJECT_PATH_PREFIX = '/objects/'

/**
 * The `{name}` parameters of a path, in order.
 *
 * @spec openspec/changes/api-explorer-in-the-app/specs/graphql-api/spec.md#requirement-req-apix-001-the-api-can-be-tried-from-inside-the-app
 * @param {string} path The path, such as `/objects/{register}/{schema}/{id}`.
 * @return {Array<string>} The parameter names.
 */
export function pathParameters(path) {
	return [...String(path).matchAll(/{(\w+)}/g)].map((match) => match[1])
}

/**
 * The schema operations of a register's OpenAPI document, grouped by the
 * schema (the operation's first tag) in document order.
 *
 * Only paths under `/objects/` are listed: the list, read, create, update,
 * patch and delete operations of each schema and the schema's own object
 * routes. The JSON-LD contexts and the like are left out.
 *
 * @spec openspec/changes/api-explorer-in-the-app/specs/graphql-api/spec.md#requirement-req-apix-001-the-api-can-be-tried-from-inside-the-app
 * @param {object} oas The OpenAPI document.
 * @return {Array<{tag: string, operations: Array<object>}>} The groups.
 */
export function operationsFromOas(oas) {
	const groups = new Map()
	for (const [path, item] of Object.entries(oas?.paths ?? {})) {
		if (
			!path.startsWith(OBJECT_PATH_PREFIX)
			|| item === null
			|| typeof item !== 'object'
		) {
			continue
		}
		for (const method of LISTED_METHODS) {
			const operation = item[method]
			if (!operation) {
				continue
			}
			const tag = operation.tags?.[0] ?? path
			if (!groups.has(tag)) {
				groups.set(tag, [])
			}
			groups.get(tag).push({
				key: method + ' ' + path,
				method: method.toUpperCase(),
				path,
				summary: operation.summary ?? '',
				operationId: operation.operationId ?? '',
				pathParams: pathParameters(path),
				hasBody: BODY_METHODS.includes(method),
			})
		}
	}
	return [...groups.entries()].map(([tag, operations]) => ({ tag, operations }))
}

/**
 * The base URL the samples address, from the document's first server, or
 * the given fallback when the document names none.
 *
 * @spec openspec/changes/api-explorer-in-the-app/specs/graphql-api/spec.md#requirement-req-apix-001-the-api-can-be-tried-from-inside-the-app
 * @param {object} oas The OpenAPI document.
 * @param {string} fallback The URL to use without a server.
 * @return {string} The base URL.
 */
export function serverUrl(oas, fallback) {
	const url = oas?.servers?.[0]?.url
	return typeof url === 'string' && url !== '' ? url : fallback
}

/**
 * The body a reader typed, parsed; `undefined` for an empty field.
 *
 * @spec openspec/changes/api-explorer-in-the-app/specs/graphql-api/spec.md#requirement-req-apix-001-the-api-can-be-tried-from-inside-the-app
 * @param {string} text The typed JSON.
 * @return {{ok: boolean, value: *}} The parsed value, or ok false when it is not JSON.
 */
export function parseBody(text) {
	if (String(text ?? '').trim() === '') {
		return { ok: true, value: undefined }
	}
	try {
		return { ok: true, value: JSON.parse(text) }
	} catch {
		return { ok: false, value: undefined }
	}
}

/**
 * Whether every path parameter of an operation has a value.
 *
 * @spec openspec/changes/api-explorer-in-the-app/specs/graphql-api/spec.md#requirement-req-apix-001-the-api-can-be-tried-from-inside-the-app
 * @param {object} operation The operation.
 * @param {object} values The typed values per parameter.
 * @return {boolean} True when the call can be made.
 */
export function isComplete(operation, values) {
	return operation.pathParams.every(
		(name) => String(values?.[name] ?? '').trim() !== '',
	)
}

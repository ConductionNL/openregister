/**
 * The fetcher the bundled GraphQL explorer runs queries through (api-explorer-in-the-app, REQ-APIX-001).
 *
 * The queries go through the app's own HTTP client, so they carry the signed-in
 * session and the request token like every other call the app makes. A query
 * the server refuses still answers with a GraphQL error body, so every status
 * is handed back to the explorer as the result instead of thrown.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @spec openspec/changes/api-explorer-in-the-app/tasks.md
 */

/**
 * A GraphiQL fetcher that posts each query to the GraphQL endpoint.
 *
 * @param {{post: function(string, object, object): Promise<{data: object}>}} client The HTTP client (`@nextcloud/axios`).
 * @param {string} url The GraphQL endpoint.
 * @return {function(object): Promise<object>} The fetcher.
 */
export function createExplorerFetcher(client, url) {
	return async (graphQLParams) => {
		const response = await client.post(url, graphQLParams, {
			headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
			validateStatus: () => true,
		})

		return response.data
	}
}

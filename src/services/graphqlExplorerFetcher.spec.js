/**
 * The bundled GraphQL explorer's fetcher (api-explorer-in-the-app, REQ-APIX-001).
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @spec openspec/changes/api-explorer-in-the-app/tasks.md
 */
import { createExplorerFetcher } from './graphqlExplorerFetcher.js'

describe('createExplorerFetcher', () => {
	it('posts the query to the endpoint as JSON and returns the response body', async () => {
		const client = { post: jest.fn().mockResolvedValue({ status: 200, data: { data: { __typename: 'Query' } } }) }
		const fetcher = createExplorerFetcher(client, '/index.php/apps/openregister/api/graphql')

		const result = await fetcher({ query: '{ __typename }', operationName: null })

		expect(result).toEqual({ data: { __typename: 'Query' } })
		expect(client.post).toHaveBeenCalledTimes(1)
		const [url, body, config] = client.post.mock.calls[0]
		expect(url).toBe('/index.php/apps/openregister/api/graphql')
		expect(body).toEqual({ query: '{ __typename }', operationName: null })
		expect(config.headers).toEqual({ 'Content-Type': 'application/json', Accept: 'application/json' })
	})

	it('hands a refused query back as its error body instead of throwing', async () => {
		const errors = { errors: [{ message: 'Cannot query field "nope" on type "Query".' }] }
		const client = { post: jest.fn().mockResolvedValue({ status: 400, data: errors }) }

		const result = await createExplorerFetcher(client, '/graphql')({ query: '{ nope }' })

		expect(result).toEqual(errors)
		const { validateStatus } = client.post.mock.calls[0][2]
		expect(validateStatus(400)).toBe(true)
		expect(validateStatus(500)).toBe(true)
	})
})

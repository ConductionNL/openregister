/**
 * The bulk request helpers of the schema object modals: the POST they send
 * and how they read the answer back.
 *
 * @spec exclude Test of a client-side request helper.
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
import { bulkResultOf, postJson } from './schemaBulkRequest.js'

jest.mock('@nextcloud/auth', () => ({
	getRequestToken: jest.fn(() => 'token-1'),
}))

const { getRequestToken } = jest.requireMock('@nextcloud/auth')

/**
 * A fetch response stand-in.
 *
 * @param {boolean} ok Whether the status is a success
 * @param {object} body The JSON body
 * @param {string} statusText The status text
 * @return {object} The response
 */
function response(ok, body = {}, statusText = '') {
	return { ok, statusText, json: jest.fn(async () => body) }
}

describe('postJson', () => {
	beforeEach(() => {
		global.fetch = jest.fn(async () => 'answer')
		getRequestToken.mockReturnValue('token-1')
	})

	afterEach(() => {
		delete global.fetch
	})

	it('posts the payload as JSON with the request token', async () => {
		await expect(postJson('/api/bulk/delete', { ids: [1, 2] })).resolves.toBe('answer')

		expect(global.fetch).toHaveBeenCalledTimes(1)
		expect(global.fetch).toHaveBeenCalledWith('/api/bulk/delete', {
			method: 'POST',
			headers: { 'Content-Type': 'application/json', requesttoken: 'token-1' },
			body: '{"ids":[1,2]}',
		})
	})

	it('leaves the body out without a payload', async () => {
		await postJson('/api/bulk/validate')

		const init = global.fetch.mock.calls[0][1]
		expect(init).not.toHaveProperty('body')
		expect(init.method).toBe('POST')
	})

	it('sends a falsy payload that is not undefined', async () => {
		await postJson('/api/x', null)

		expect(global.fetch.mock.calls[0][1].body).toBe('null')
	})

	it('sends an empty token when there is none', async () => {
		getRequestToken.mockReturnValue(null)

		await postJson('/api/x', {})

		expect(global.fetch.mock.calls[0][1].headers.requesttoken).toBe('')
	})
})

describe('bulkResultOf', () => {
	it('returns the body of a successful answer', async () => {
		await expect(bulkResultOf(response(true, { deleted: 3 }), 'Deletion')).resolves.toEqual({ deleted: 3 })
	})

	it('reports a failed status with the action and status text, without reading the body', async () => {
		const failed = response(false, {}, 'Forbidden')

		await expect(bulkResultOf(failed, 'Deletion')).rejects.toThrow('Deletion failed: Forbidden')
		expect(failed.json).not.toHaveBeenCalled()
	})

	it('reports the error a successful answer carries', async () => {
		await expect(bulkResultOf(response(true, { error: 'Schema is locked' }), 'Validation'))
			.rejects.toThrow('Schema is locked')
	})

	it('ignores an empty error', async () => {
		await expect(bulkResultOf(response(true, { error: '', done: true }), 'Validation'))
			.resolves.toEqual({ error: '', done: true })
	})
})

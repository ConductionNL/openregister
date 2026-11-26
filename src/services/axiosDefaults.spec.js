/**
 * Requests are sent at once: the shared `@nextcloud/axios` client does not
 * wait for the browser's Cookie Store before `send()`.
 *
 * @spec openspec/specs/frontend-store-client-state/spec.md
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
import axios from '@nextcloud/axios'
import { withoutXsrfCookieRead } from './axiosDefaults.js'

describe('axiosDefaults', () => {
	afterEach(() => {
		delete window.cookieStore
	})

	it('turns the XSRF cookie read off on the shared client when imported', () => {
		expect(axios.defaults.withXSRFToken).toBe(false)
	})

	it('returns the client it changed', () => {
		const client = { defaults: {} }
		expect(withoutXsrfCookieRead(client)).toBe(client)
		expect(client.defaults.withXSRFToken).toBe(false)
	})

	it('sends a same-origin request without asking the Cookie Store first', async () => {
		const get = jest.fn(() => new Promise(() => {}))
		window.cookieStore = { get }
		const sent = []
		const open = jest.spyOn(XMLHttpRequest.prototype, 'open').mockImplementation(function() {})
		const send = jest.spyOn(XMLHttpRequest.prototype, 'send').mockImplementation(function() {
			sent.push('send')
		})
		jest.spyOn(XMLHttpRequest.prototype, 'setRequestHeader').mockImplementation(() => {})

		axios.get('/apps/openregister/api/schemas', { adapter: 'xhr' }).catch(() => {})
		await new Promise((done) => setTimeout(done, 0))

		expect(get).not.toHaveBeenCalled()
		expect(sent).toEqual(['send'])
		open.mockRestore()
		send.mockRestore()
	})

	it('without it, the same client waits on the Cookie Store (what this module prevents)', async () => {
		const get = jest.fn(() => new Promise(() => {}))
		window.cookieStore = { get }
		const sent = []
		const open = jest.spyOn(XMLHttpRequest.prototype, 'open').mockImplementation(function() {})
		const send = jest.spyOn(XMLHttpRequest.prototype, 'send').mockImplementation(function() {
			sent.push('send')
		})
		jest.spyOn(XMLHttpRequest.prototype, 'setRequestHeader').mockImplementation(() => {})

		axios.get('/apps/openregister/api/schemas', { adapter: 'xhr', withXSRFToken: null }).catch(() => {})
		await new Promise((done) => setTimeout(done, 0))

		expect(get).toHaveBeenCalledWith('XSRF-TOKEN')
		expect(sent).toEqual([])
		open.mockRestore()
		send.mockRestore()
	})
})

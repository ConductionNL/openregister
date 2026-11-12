/**
 * The methods the flow overview and run page share behave as each page's
 * own copy did.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/flow-and-run-detail-pages/spec.md
 */
import axios from '@nextcloud/axios'
import { flowPageMethods } from './flowPageMethods.js'

jest.mock('@nextcloud/axios', () => ({ get: jest.fn() }))
jest.mock('@nextcloud/l10n', () => ({ translate: (app, text) => text }))
jest.mock('@nextcloud/router', () => ({ generateUrl: (path) => '/index.php' + path }))

/**
 * A stand-in view holding the state `act` reads and writes.
 *
 * @return {object} The view
 */
function makeView() {
	const view = { busy: false, actionError: 'old' }
	view.act = flowPageMethods.act.bind(view)
	view.quietGet = flowPageMethods.quietGet.bind(view)
	return view
}

describe('flowPageMethods.quietGet', () => {
	beforeEach(() => jest.clearAllMocks())

	it('returns the body and passes params only when given', async () => {
		axios.get.mockResolvedValue({ data: { id: 1 } })
		const view = makeView()
		await expect(view.quietGet('/apps/x')).resolves.toEqual({ id: 1 })
		expect(axios.get).toHaveBeenLastCalledWith('/index.php/apps/x', undefined)
		await view.quietGet('/apps/x', { limit: 5 })
		expect(axios.get).toHaveBeenLastCalledWith('/index.php/apps/x', {
			params: { limit: 5 },
		})
	})

	it('returns null without a body and on failure', async () => {
		const view = makeView()
		axios.get.mockResolvedValue({})
		await expect(view.quietGet('/apps/x')).resolves.toBeNull()
		axios.get.mockRejectedValue(new Error('down'))
		await expect(view.quietGet('/apps/x')).resolves.toBeNull()
	})
})

describe('flowPageMethods.act', () => {
	it('clears the error, runs the action and ends not busy', async () => {
		const view = makeView()
		let busyDuring = null
		await view.act(async () => {
			busyDuring = view.busy
		})
		expect(busyDuring).toBe(true)
		expect(view.busy).toBe(false)
		expect(view.actionError).toBe('')
	})

	it("shows the server's refusal, or a generic message", async () => {
		const view = makeView()
		await view.act(() =>
			Promise.reject({ response: { data: { error: 'Refused.' } } }),
		)
		expect(view.actionError).toBe('Refused.')
		expect(view.busy).toBe(false)
		await view.act(() => Promise.reject(new Error('x')))
		expect(view.actionError).toBe('That did not work. Try again.')
	})
})

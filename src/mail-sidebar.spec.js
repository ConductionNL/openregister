/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The mail sidebar entry: mount once, onto the body, only on a Mail page.
 *
 * @spec exclude Unit test of the client-side mail sidebar entry point.
 */

const mockMount = jest.fn()
const mockMixin = jest.fn()
const mockCreateApp = jest.fn(() => ({ mixin: mockMixin, mount: mockMount }))
jest.mock('vue', () => ({ createApp: (...a) => mockCreateApp(...a) }))
jest.mock('./mail-sidebar/MailSidebar.vue', () => ({ name: 'MailSidebar' }))
jest.mock('@nextcloud/l10n', () => ({ translate: jest.fn(), translatePlural: jest.fn() }))
const mockEnsure = jest.fn()
jest.mock('./integrations/bootstrap.js', () => ({
	ensureIntegrationRegistry: () => mockEnsure(),
}))
const mockRegister = jest.fn()
jest.mock('./services/libraryTranslations.js', () => ({
	registerLibraryTranslations: () => mockRegister(),
}))

/**
 * Load the entry afresh.
 *
 * @return {void}
 */
function load() {
	jest.isolateModules(() => {
		require('./mail-sidebar.js')
	})
}

/**
 * Mark the page as the Mail app.
 *
 * @return {void}
 */
function mailPage() {
	const marker = document.createElement('input')
	marker.id = 'initial-state-mail-accounts'
	document.body.appendChild(marker)
}

beforeEach(() => {
	jest.clearAllMocks()
	jest.useFakeTimers()
	document.body.innerHTML = ''
})
afterEach(() => {
	jest.useRealTimers()
})

describe('mail-sidebar entry', () => {
	it('registers translations and the integration registry, then mounts on a Mail page', () => {
		mailPage()
		load()
		expect(mockRegister).toHaveBeenCalledTimes(1)
		expect(mockEnsure).toHaveBeenCalledTimes(1)
		expect(mockCreateApp).toHaveBeenCalledWith({ name: 'MailSidebar' })
		const host = document.getElementById('openregister-mail-sidebar')
		expect(host.parentNode).toBe(document.body)
		expect(mockMount).toHaveBeenCalledWith(host)
		expect(Object.keys(mockMixin.mock.calls[0][0].methods)).toEqual(['t', 'n'])
	})

	it('does not mount a second sidebar', () => {
		mailPage()
		const existing = document.createElement('div')
		existing.id = 'openregister-mail-sidebar'
		document.body.appendChild(existing)
		load()
		expect(mockCreateApp).not.toHaveBeenCalled()
	})

	it('retries every second until the Mail page appears', () => {
		load()
		expect(mockCreateApp).not.toHaveBeenCalled()
		jest.advanceTimersByTime(1000)
		expect(mockCreateApp).not.toHaveBeenCalled()
		mailPage()
		jest.advanceTimersByTime(1000)
		expect(mockCreateApp).toHaveBeenCalledTimes(1)
	})

	it('gives up after 30 attempts', () => {
		load()
		jest.advanceTimersByTime(29000)
		expect(jest.getTimerCount()).toBe(0)
		mailPage()
		jest.advanceTimersByTime(5000)
		expect(mockCreateApp).not.toHaveBeenCalled()
	})

	it('logs a failed mount instead of throwing', () => {
		mailPage()
		const error = jest.spyOn(console, 'error').mockImplementation(() => {})
		mockMount.mockImplementationOnce(() => {
			throw new Error('boom')
		})
		load()
		expect(error).toHaveBeenCalledWith(
			'[OpenRegister] Mail sidebar mount failed:',
			expect.any(Error),
		)
	})

	it('waits for DOMContentLoaded while the document is loading', () => {
		mailPage()
		const state = jest.spyOn(document, 'readyState', 'get').mockReturnValue('loading')
		load()
		expect(mockCreateApp).not.toHaveBeenCalled()
		state.mockRestore()
		document.dispatchEvent(new Event('DOMContentLoaded'))
		expect(mockCreateApp).toHaveBeenCalledTimes(1)
	})
})

import AccessLinkPage from '../../views/accessLink/AccessLinkPage.vue'
import ObjectAccessLinks from './ObjectAccessLinks.vue'
import { mintAccessLink } from '../../services/accessLinks.js'

/**
 * Golden record of how the access-link panel and the holder page word a
 * refused request: the server's own message when it sends one, the generic
 * retry line otherwise, and never left busy.
 *
 * @spec exclude Golden record of client-side error wording.
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */

jest.mock('@nextcloud/vue', () => ({ __esModule: true }))
jest.mock('@nextcloud/axios', () => ({ __esModule: true, default: { get: jest.fn(), post: jest.fn() } }))
jest.mock('@nextcloud/router', () => ({ generateUrl: (path) => path }))
jest.mock('@nextcloud/dialogs', () => ({ showError: jest.fn(), showSuccess: jest.fn() }))
jest.mock('@nextcloud/l10n', () => ({
	translate: (app, text) => `t:${text}`,
	translatePlural: (app, one) => one,
}))
jest.mock('../../services/accessLinks.js', () => ({
	CAPABILITIES: [],
	fileAsBase64: jest.fn(),
	linksForSubject: jest.fn(),
	linkState: jest.fn(),
	listAccessLinks: jest.fn(),
	mintAccessLink: jest.fn(),
	mintPayload: jest.fn((payload) => payload),
	passwordOptions: jest.fn(),
	publicLinkUrl: jest.fn(),
	revokeAccessLink: jest.fn(),
	setAccessLinkDisabled: jest.fn(),
}))

const SERVER = { response: { data: { message: 'Link expired' } } }

/**
 * Bind every method of a component to a plain context.
 *
 * @param {object} component The component
 * @param {object} state The starting data
 * @return {object} The context
 */
function bound(component, state) {
	const ctx = { busy: false, error: '', notice: '', ...state }
	for (const [name, method] of Object.entries(component.methods)) {
		ctx[name] = method.bind(ctx)
	}
	return ctx
}

describe.each([
	['the server message', SERVER, 'Link expired'],
	['the retry line without one', new Error('offline'), 't:That did not work. Try again later.'],
])('a refused request shows %s', (label, failure, shown) => {
	it('on the holder page', async () => {
		const page = bound(AccessLinkPage, {})
		page.open = jest.fn()
		await page.act(() => Promise.reject(failure))
		expect([page.error, page.busy, page.open]).toEqual([shown, false, page.open])
		expect(page.open).not.toHaveBeenCalled()
	})

	it('in the object panel', async () => {
		mintAccessLink.mockRejectedValueOnce(failure)
		const panel = bound(ObjectAccessLinks, { objectId: 'o-1', form: { password: 'p', label: 'l' }, canCreate: true })
		await panel.create()
		expect([panel.error, panel.busy, panel.form]).toEqual([shown, false, { password: 'p', label: 'l' }])
	})
})

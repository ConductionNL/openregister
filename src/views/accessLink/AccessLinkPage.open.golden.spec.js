import axios from '@nextcloud/axios'
import AccessLinkPage from './AccessLinkPage.vue'

/**
 * Golden record of opening a public access link: the request with or
 * without a password, the body shown when it is allowed, the password prompt
 * a 401 brings (with the wrong-password message on a second try), and the
 * gone page for any other refusal.
 *
 * @spec exclude Golden record of the client-side public access link page.
 */

jest.mock('@nextcloud/axios', () => ({ __esModule: true, default: { get: jest.fn() } }))
jest.mock('@nextcloud/l10n', () => ({ __esModule: true, translate: (app, text) => `t:${text}`, translatePlural: (app, one, many) => many }))
jest.mock('@nextcloud/vue', () => ({ __esModule: true }))
jest.mock('../../components/access-links/refusalMessage.js', () => ({ __esModule: true, refusalMessage: () => '' }))
jest.mock('../../services/accessLinks.js', () => ({
	__esModule: true,
	fileAsBase64: jest.fn(),
	passwordOptions: (password) => (password ? { headers: { 'X-Password': password } } : {}),
	publicLinkUrl: (anchor) => `/public/${anchor}`,
	readableFields: jest.fn(),
}))

/**
 * Open the link on a bare page carrying the page's methods.
 *
 * @param {object} state The page data
 * @param {Function} answer The request's answer
 * @return {Promise<object>} The page data afterwards and the request
 */
async function open(state, answer) {
	const page = { anchor: 'abc', password: '', error: 'old', state: 'loading', body: null, ...state }
	for (const [name, method] of Object.entries(AccessLinkPage.methods)) {
		page[name] = method.bind(page)
	}
	const seen = []
	axios.get.mockImplementation((...args) => {
		seen.push([args, page.error])
		return answer()
	})
	await page.open()
	const data = Object.fromEntries(Object.entries(page).filter(([, value]) => typeof value !== 'function'))
	return { data, seen }
}

beforeEach(() => axios.get.mockReset())

describe('AccessLinkPage.open', () => {
	it.each([
		['allowed', {}, () => Promise.resolve({ data: { title: 'Shared' } })],
		['allowed with a password', { state: 'password', password: 'secret' }, () => Promise.resolve({ data: { title: 'Shared' } })],
		['asking for a password', {}, () => Promise.reject({ response: { status: 401 } })],
		['refusing a wrong password', { state: 'password', password: 'wrong' }, () => Promise.reject({ response: { status: 401 } })],
		['gone', {}, () => Promise.reject({ response: { status: 404 } })],
		['forbidden after a password', { state: 'password', password: 'secret' }, () => Promise.reject({ response: { status: 403 } })],
		['unreachable', {}, () => Promise.reject(new Error('offline'))],
	])('opens when %s', async (label, state, answer) => {
		expect(await open(state, answer)).toMatchSnapshot()
	})
})

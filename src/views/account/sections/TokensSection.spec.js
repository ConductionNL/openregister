import axios from '@nextcloud/axios'
import TokensSection from './TokensSection.vue'
import { sectionStatus } from '../../../composables/sectionStatus.js'

/**
 * Creating a personal API token: what is sent, the one-time secret that is
 * revealed, and the form reset and list reload that follow.
 *
 * @spec exclude UI plumbing — token list contract owned by account-self-service.
 */

jest.mock('@nextcloud/axios', () => ({ __esModule: true, default: { get: jest.fn(), post: jest.fn() } }))
jest.mock('@nextcloud/router', () => ({ __esModule: true, generateUrl: (path) => `/gen${path}` }))
jest.mock('@nextcloud/l10n', () => ({ __esModule: true, translate: (app, text) => `t:${text}` }))
jest.mock('@nextcloud/vue', () => ({ __esModule: true }))
jest.mock('../../../components/shared/SectionStatus.vue', () => ({ __esModule: true, default: {} }))
jest.mock('../../../modals/account/CreatedTokenModal.vue', () => ({ __esModule: true, default: {} }))
jest.mock('../../../modals/account/CreateTokenModal.vue', () => ({ __esModule: true, default: {} }))

/**
 * Create a token from a bare section state carrying the section's methods.
 *
 * @param {object} form The name and expiry typed in
 * @param {Function} answer The create request's answer
 * @return {Promise<object>} The data afterwards, and the calls made
 */
async function createToken(form, answer) {
	const calls = []
	const section = {
		showCreateModal: true,
		createdToken: null,
		message: '',
		isError: false,
		...form,
	}
	const methods = { ...sectionStatus.methods, ...TokensSection.methods }
	for (const [name, method] of Object.entries(methods)) {
		section[name] = method.bind(section)
	}
	section.loadTokens = jest.fn(async () => calls.push(['loadTokens']))
	axios.post.mockImplementation((...args) => {
		calls.push(['post', ...args])
		return answer()
	})
	await section.createToken()
	for (const name of [...Object.keys(methods), 'loadTokens']) {
		delete section[name]
	}
	return { section, calls }
}

describe('TokensSection.createToken', () => {
	beforeEach(() => axios.post.mockReset())

	it.each([
		['with an expiry', { newTokenName: 'CI', newTokenExpires: '30d' }],
		['without an expiry', { newTokenName: 'CI', newTokenExpires: '' }],
	])('creates a token %s, reveals it and reloads the list', async (label, form) => {
		expect(await createToken(form, () => Promise.resolve({ data: { token: 'secret-1' } }))).toMatchSnapshot()
	})

	it.each([
		['the API error', { response: { data: { error: 'Too many tokens' } } }],
		['the fallback', new Error('offline')],
	])('keeps the form open and reports %s', async (label, failure) => {
		expect(await createToken({ newTokenName: 'CI', newTokenExpires: '7d' }, () => Promise.reject(failure))).toMatchSnapshot()
	})
})

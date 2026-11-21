/**
 * Golden record of creating an access link from the object panel: what is
 * minted, what the form keeps, and what a refused or impossible create does.
 * The expiry is compared as a local date, so the record does not depend on
 * the clock or the timezone.
 *
 * @spec exclude Golden record of client-side access link creation.
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
import axios from '@nextcloud/axios'
import SubjectAccessLinks from './SubjectAccessLinks.vue'
import { mintPayload } from '../../services/accessLinks.js'

jest.mock('@nextcloud/vue', () => ({ __esModule: true }))
jest.mock('@nextcloud/dialogs', () => ({ showError: jest.fn(), showSuccess: jest.fn() }))
jest.mock('@nextcloud/axios', () => ({
	__esModule: true,
	default: { get: jest.fn(), post: jest.fn() },
}))
jest.mock('@nextcloud/router', () => ({ generateUrl: (path) => path }))

/**
 * A panel context with the panel's methods bound to it.
 *
 * @param {object} state The state to start from
 * @return {object} The context
 */
function context(state) {
	const ctx = { ...SubjectAccessLinks.data.call({}), subjectType: 'object', subjectId: 'uuid-1', ...state }
	for (const [name, method] of Object.entries(SubjectAccessLinks.methods)) {
		ctx[name] = method.bind(ctx)
	}
	ctx.load = jest.fn()
	return ctx
}

/**
 * A posted mint body, with the expiry as a local date.
 *
 * @param {object} body The body
 * @return {object} The body
 */
function local(body) {
	const expiry = new Date(body.expiresAt)
	return {
		...body,
		expiresAt: [expiry.getFullYear(), expiry.getMonth() + 1, expiry.getDate(), expiry.getHours(), expiry.getMinutes()],
	}
}

beforeEach(() => jest.clearAllMocks())

describe('ObjectAccessLinks.create (golden)', () => {
	it('starts with a read link form', () => {
		expect(SubjectAccessLinks.data.call({})).toMatchSnapshot()
	})

	it.each([
		['mints a link', () => Promise.resolve({ data: { id: 9, url: 'u', pageUrl: 'p' } })],
		['shows a refusal', () => Promise.reject({ response: { status: 403, data: { error: 'Not yours' } } })],
		['shows a failure', () => Promise.reject(new Error('offline'))],
	])('%s', async (name, answer) => {
		axios.post.mockImplementation(answer)
		const ctx = context({
			canCreate: true,
			form: { label: 'Notary', capabilities: ['upload', 'read'], expiresOn: '2026-12-31', password: 'secret' },
			error: 'old',
		})
		const pending = ctx.create()
		const during = { busy: ctx.busy, error: ctx.error }
		await pending
		expect({
			posted: axios.post.mock.calls.map(([url, body]) => [url, local(body)]),
			during,
			created: ctx.created,
			form: ctx.form,
			error: ctx.error,
			busy: ctx.busy,
			reloads: ctx.load.mock.calls.length,
		}).toMatchSnapshot()
	})

	it('creates nothing it may not create', async () => {
		const ctx = context({ canCreate: false })
		await ctx.create()
		expect(axios.post).not.toHaveBeenCalled()
		expect(ctx.busy).toBe(false)
	})
})

describe('mintPayload edges (golden)', () => {
	it.each([
		['no capabilities and no expiry', { subjectType: 'view', subjectId: 12 }],
		['a partial expiry', { subjectType: 'file', subjectId: 'f', capabilities: ['comment'], expiresOn: '2027-05' }],
		['an empty password and label', { subjectType: 'object', subjectId: 'o', capabilities: CAPS(), expiresOn: '2026-01-02', password: '', label: '' }],
	])('builds the body for %s', (name, form) => {
		expect(local(mintPayload(form))).toMatchSnapshot()
	})
})

/**
 * Every capability, in a scrambled order.
 *
 * @return {Array<string>} The capabilities
 */
function CAPS() {
	return ['upload', 'comment', 'read']
}

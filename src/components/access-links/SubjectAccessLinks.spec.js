/**
 * The access-link panel over each subject type, and the holder page.
 *
 * @spec openspec/changes/access-by-link-not-by-account/specs/public-access-links/spec.md
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
import axios from '@nextcloud/axios'
import AccessLinkPage from '../../views/accessLink/AccessLinkPage.vue'
import ObjectAccessLinks from './ObjectAccessLinks.vue'
import SubjectAccessLinks, { SUBJECT_TYPES, subjectTexts } from './SubjectAccessLinks.vue'

jest.mock('@nextcloud/vue', () => ({
	__esModule: true,
	NcButton: {},
	NcCheckboxRadioSwitch: {},
	NcEmptyContent: {},
	NcLoadingIcon: {},
	NcNoteCard: {},
	NcPasswordField: {},
	NcTextArea: {},
	NcTextField: {},
}))
jest.mock('@nextcloud/dialogs', () => ({
	showError: jest.fn(),
	showSuccess: jest.fn(),
}))
jest.mock('@nextcloud/axios', () => ({
	__esModule: true,
	default: { get: jest.fn(), post: jest.fn() },
}))
jest.mock('@nextcloud/router', () => ({ generateUrl: (path) => path }))

/**
 * A panel context over one subject, with the panel's methods bound to it.
 *
 * @param {string} subjectType The subject type
 * @param {string} subjectId The subject id
 * @return {object} The context
 */
function panel(subjectType, subjectId) {
	const ctx = {
		...SubjectAccessLinks.data.call({}),
		subjectType,
		subjectId,
		canCreate: true,
	}
	ctx.form.expiresOn = '2026-12-31'
	for (const [name, method] of Object.entries(SubjectAccessLinks.methods)) {
		ctx[name] = method.bind(ctx)
	}
	ctx.load = jest.fn()
	return ctx
}

describe('SubjectAccessLinks (#4061)', () => {
	beforeEach(() => jest.clearAllMocks())

	it("lists only this object's links and needs an expiry and a capability to create one", () => {
		const ctx = {
			subjectType: 'object',
			subjectId: 'uuid-1',
			allLinks: [
				{
					id: 1,
					subjectType: 'object',
					subjectId: 'uuid-1',
					created: '2026-09-01',
				},
				{
					id: 2,
					subjectType: 'object',
					subjectId: 'uuid-2',
					created: '2026-09-02',
				},
			],
			form: { capabilities: ['read'], expiresOn: '' },
		}
		expect(
			SubjectAccessLinks.computed.links.call(ctx).map((link) => link.id),
		).toEqual([1])
		expect(SubjectAccessLinks.computed.canCreate.call(ctx)).toBe(false)
		ctx.form.expiresOn = '2026-12-31'
		expect(SubjectAccessLinks.computed.canCreate.call(ctx)).toBe(true)
		ctx.form.capabilities = []
		expect(SubjectAccessLinks.computed.canCreate.call(ctx)).toBe(false)
	})

	it('lists only the links of its own subject type, so a view id never shows an object link', () => {
		const ctx = {
			subjectType: 'view',
			subjectId: 12,
			allLinks: [
				{ id: 1, subjectType: 'object', subjectId: '12' },
				{ id: 2, subjectType: 'view', subjectId: '12' },
				{ id: 3, subjectType: 'view', subjectId: '12', revokedAt: '2026-09-01' },
			],
		}
		expect(
			SubjectAccessLinks.computed.links.call(ctx).map((link) => link.id),
		).toEqual([2])
	})

	it.each([
		['object', 'uuid-1', 'uuid-1'],
		['view', 12, '12'],
		['file', 'uuid-1/345', 'uuid-1/345'],
	])('mints a link over a %s with that subject', async (subjectType, subjectId, sent) => {
		axios.post.mockResolvedValueOnce({ data: { id: 9, url: 'u', pageUrl: 'p' } })
		const ctx = panel(subjectType, subjectId)
		await ctx.create()
		expect(axios.post).toHaveBeenCalledTimes(1)
		const [url, body] = axios.post.mock.calls[0]
		expect(url).toBe('/apps/openregister/api/access-links')
		expect([body.subjectType, body.subjectId, body.capabilities]).toEqual([subjectType, sent, ['read']])
		expect(ctx.created).toEqual({ id: 9, url: 'u', pageUrl: 'p' })
		expect(ctx.load).toHaveBeenCalledTimes(1)
	})

	it('accepts exactly the subject types the server knows', () => {
		const { validator } = SubjectAccessLinks.props.subjectType
		expect(SUBJECT_TYPES.map(validator)).toEqual([true, true, true])
		expect(validator('register')).toBe(false)
	})

	it('names the subject in its intro and empty list', () => {
		expect(subjectTexts('view').empty).toBe('No links to this view yet.')
		expect(subjectTexts('file').empty).toBe('No links to this file yet.')
		expect(subjectTexts('object').empty).toBe('No links to this object yet.')
		expect(subjectTexts('view').intro).toContain('access to this view.')
		expect(subjectTexts('file').intro).toContain('access to this file.')
	})
})

describe('ObjectAccessLinks (#4061)', () => {
	it('stays the object tab: one object uuid, handed on as an object subject', () => {
		expect(ObjectAccessLinks.props.objectId).toEqual({ type: String, required: true })
		expect(ObjectAccessLinks.components.SubjectAccessLinks).toBe(SubjectAccessLinks)
	})
})

describe('AccessLinkPage (#4061)', () => {
	it('renders the record as text fields, not JSON', () => {
		const ctx = {
			body: {
				subject: {
					'@self': { id: 'x' },
					naam: 'Jan',
					adres: { straat: 'A' },
				},
			},
		}
		expect(AccessLinkPage.computed.fields.call(ctx)).toEqual([
			{ key: 'naam', value: 'Jan' },
			{ key: 'adres', value: 'straat: A' },
		])
	})

	it('offers comment and upload only when the link declares them', () => {
		const ctx = { body: { link: { capabilities: ['read', 'comment'] } } }
		expect(AccessLinkPage.methods.may.call(ctx, 'comment')).toBe(true)
		expect(AccessLinkPage.methods.may.call(ctx, 'upload')).toBe(false)
	})
})

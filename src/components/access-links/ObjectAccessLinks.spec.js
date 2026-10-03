import AccessLinkPage from '../../views/accessLink/AccessLinkPage.vue'
import ObjectAccessLinks from './ObjectAccessLinks.vue'

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

describe('ObjectAccessLinks (#4061)', () => {
	it("lists only this object's links and needs an expiry and a capability to create one", () => {
		const ctx = {
			objectId: 'uuid-1',
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
			ObjectAccessLinks.computed.links.call(ctx).map((link) => link.id),
		).toEqual([1])
		expect(ObjectAccessLinks.computed.canCreate.call(ctx)).toBe(false)
		ctx.form.expiresOn = '2026-12-31'
		expect(ObjectAccessLinks.computed.canCreate.call(ctx)).toBe(true)
		ctx.form.capabilities = []
		expect(ObjectAccessLinks.computed.canCreate.call(ctx)).toBe(false)
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

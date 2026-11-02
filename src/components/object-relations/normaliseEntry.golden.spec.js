/**
 * Golden record of RelationsTab.normaliseEntry: the row it makes of a raw
 * relation (emails, contacts, events and others), recorded in call order so
 * the fallback id counter is pinned too.
 */
import RelationsTab from './RelationsTab.vue'

jest.mock('@nextcloud/axios', () => ({ __esModule: true, default: {} }))
jest.mock('@nextcloud/router', () => ({ generateUrl: (path) => path }))
jest.mock('@nextcloud/vue', () => ({}))
jest.mock('@nextcloud/l10n', () => ({
	translate: (app, text) => text,
	translatePlural: (app, one) => one,
	t: (app, text) => text,
	n: (app, one) => one,
}))
jest.mock('vue-material-design-icons/AccountOutline.vue', () => ({}))
jest.mock('vue-material-design-icons/AlertCircleOutline.vue', () => ({}))
jest.mock('vue-material-design-icons/CalendarOutline.vue', () => ({}))
jest.mock('vue-material-design-icons/EmailOutline.vue', () => ({}))
jest.mock('vue-material-design-icons/LinkVariant.vue', () => ({}))
jest.mock('vue-material-design-icons/LinkVariantOff.vue', () => ({}))
jest.mock('vue-material-design-icons/TableLargePlus.vue', () => ({}))

const RAW = [
	{},
	{ id: 0, uid: '', uuid: null },
	{
		type: 'email',
		subject: 'Hello',
		fromEmail: 'a@b',
		from: 'A',
		receivedAt: 't1',
		id: 'e1',
	},
	{ type: 'email', subtitle: 'Kept', from: 'A' },
	{ type: 'email', from: 'Only from' },
	{ type: 'email' },
	{ type: 'contact', fullName: 'Ann', email: 'ann@x', uid: 'c1' },
	{ type: 'contact', email: 'ann@x' },
	{ type: 'contact', name: 'Named', fullName: 'Ignored' },
	{ type: 'contact' },
	{
		entityType: 'event',
		summary: 'Meet',
		startsAt: 's',
		createdAt: 'c',
		uuid: 'v1',
	},
	{ type: '', entityType: 'deck', displayName: 'Card', updatedAt: 'u' },
	{
		title: 'T',
		subject: 'S',
		summary: 'M',
		displayName: 'D',
		name: 'N',
		timestamp: 0,
		createdAt: 'c',
	},
	{ displayName: 'D', name: 'N' },
	{ subject: 'S', summary: 'M' },
	{ receivedAt: 'r', startsAt: 's' },
	{ type: 'constructor', title: 'odd' },
	{ type: 'toString' },
	{ type: 'email', fromEmail: 'x@y', fullName: 'not used' },
	{ type: 'contact', fromEmail: 'not used' },
]

describe('RelationsTab.normaliseEntry (golden)', () => {
	it('matches the recorded rows', () => {
		const normalise = RelationsTab.methods.normaliseEntry
		const rows = RAW.map((raw) => JSON.stringify(normalise({ ...raw })))
		expect(rows).toMatchSnapshot()
	})
})

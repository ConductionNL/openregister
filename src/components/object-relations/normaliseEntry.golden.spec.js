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

describe('RelationsTab.normaliseResponse (golden)', () => {
	const ctx = { normaliseEntry: RelationsTab.methods.normaliseEntry }
	const normalise = (data) =>
		JSON.stringify(RelationsTab.methods.normaliseResponse.call(ctx, data))

	it.each([
		['a timeline', [{ id: 'a', type: 'email', subject: 'S' }, { id: 'b', type: 'event' }]],
		['a results envelope', { results: [{ id: 'r', type: 'contact', fullName: 'R' }] }],
		[
			'typed buckets, newest first',
			{
				emails: [
					{ id: 'e1', subject: 'Old', receivedAt: '2020-01-01T00:00:00Z' },
					{ id: 'e2', subject: 'Undated' },
				],
				events: [{ id: 'v1', summary: 'New', startDate: '2024-01-01T00:00:00Z', timestamp: '2024-01-01T00:00:00Z' }],
				contacts: [{ id: 'c1', fullName: 'C', timestamp: '2022-06-01T00:00:00Z' }],
				deckCards: [{ id: 'd1', title: 'Card', timestamp: '2021-01-01T00:00:00Z' }],
			},
		],
		['deck before deckCards', { deck: [{ id: 'd2', title: 'Deck' }], deckCards: [{ id: 'd3' }] }],
		['nothing', null],
		['an empty envelope', {}],
	])('normalises %s', (name, data) => {
		expect(normalise(data)).toMatchSnapshot()
	})
})

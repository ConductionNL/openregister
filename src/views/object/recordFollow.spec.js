/**
 * The record page's Follow props and read-state requests.
 *
 * @spec openspec/changes/record-star-follow-and-unread-on-screen/tasks.md
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
import {
	fetchRecordCan,
	followProps,
	readStateUrl,
	recordAddress,
	schemaMatches,
	schemaNotifiesWatchers,
	sendReadState,
	unreadBadges,
} from './recordFollow.js'

describe('recordAddress', () => {
	it('reads the address from @self, then from the record', () => {
		expect(recordAddress({ '@self': { id: 'o1', register: 5, schema: 'zaken' } })).toEqual({ register: '5', schema: 'zaken', id: 'o1' })
		expect(recordAddress({ uuid: 'o2', register: 'r', schema: 's' })).toEqual({ register: 'r', schema: 's', id: 'o2' })
	})

	it('has none without a record, a part, or with an expanded register', () => {
		expect(recordAddress(null)).toBeNull()
		expect(recordAddress({ '@self': { id: 'o1', register: 'r' } })).toBeNull()
		expect(recordAddress({ '@self': { id: 'o1', register: { id: 1 }, schema: 's' } })).toBeNull()
	})
})

describe('followProps', () => {
	it('passes the follow state, the count and manage from @self', () => {
		const item = { '@self': { id: 'o1', register: 'r', schema: 's', watching: true, watchNotify: false, watcherCount: 3, can: { update: true, manage: true } } }

		expect(followProps(item)).toEqual({
			register: 'r',
			schema: 's',
			objectId: 'o1',
			watching: true,
			notify: false,
			watcherCount: 3,
			canManage: true,
			notifies: true,
		})
	})

	it('reads missing answers as not following, no count and no manage', () => {
		expect(followProps({ '@self': { id: 'o1', register: 'r', schema: 's', can: { manage: 'yes' } } })).toEqual({
			register: 'r',
			schema: 's',
			objectId: 'o1',
			watching: false,
			notify: null,
			watcherCount: null,
			canManage: false,
			notifies: true,
		})
	})

	it('gives no props for a record without an address', () => {
		expect(followProps({ '@self': { id: 'o1' } })).toBeNull()
	})

	it('takes manage from the fetched context only when the record carries no can', () => {
		const row = { '@self': { id: 'o1', register: 'r', schema: 's' } }

		expect(followProps(row, { can: { manage: true } }).canManage).toBe(true)
		expect(followProps({ '@self': { ...row['@self'], can: { manage: false } } }, { can: { manage: true } }).canManage).toBe(false)
	})

	it('says the record notifies nobody only when the schema is known not to', () => {
		const row = { '@self': { id: 'o1', register: 'r', schema: 's' } }

		expect(followProps(row, { notifies: false }).notifies).toBe(false)
		expect(followProps(row, { notifies: null }).notifies).toBe(true)
		expect(followProps(row, { notifies: true }).notifies).toBe(true)
	})
})

describe('schemaNotifiesWatchers', () => {
	const schema = (rules) => ({ id: 4, configuration: { 'x-openregister-notifications': rules } })

	it('is true when a rule sends to the followers', () => {
		expect(schemaNotifiesWatchers(schema({
			assigned: { recipients: [{ kind: 'user', value: 'admin' }] },
			changed: { recipients: [{ kind: 'group', value: 'staff' }, { watchers: true }] },
		}))).toBe(true)
		expect(schemaNotifiesWatchers(schema([{ recipients: [{ watchers: true }] }]))).toBe(true)
	})

	it('is false when no rule sends to the followers', () => {
		expect(schemaNotifiesWatchers(schema({ changed: { recipients: [{ kind: 'user', value: 'admin' }] } }))).toBe(false)
		expect(schemaNotifiesWatchers(schema({ changed: { recipients: [{ watchers: 'yes' }] } }))).toBe(false)
		expect(schemaNotifiesWatchers(schema({ changed: { recipients: 'all' } }))).toBe(false)
		expect(schemaNotifiesWatchers({ id: 4, configuration: {} })).toBe(false)
		expect(schemaNotifiesWatchers({ id: 4 })).toBe(false)
	})

	it('does not know without a schema', () => {
		expect(schemaNotifiesWatchers(null)).toBeNull()
	})
})

describe('schemaMatches', () => {
	const schema = { id: 4, uuid: 'u-4', slug: 'zaken' }

	it('matches by id, uuid or slug', () => {
		expect(schemaMatches(schema, '4')).toBe(true)
		expect(schemaMatches(schema, 4)).toBe(true)
		expect(schemaMatches(schema, 'u-4')).toBe(true)
		expect(schemaMatches(schema, 'zaken')).toBe(true)
	})

	it('does not match another schema, no schema or no reference', () => {
		expect(schemaMatches(schema, '5')).toBe(false)
		expect(schemaMatches(null, '4')).toBe(false)
		expect(schemaMatches(schema, undefined)).toBe(false)
	})
})

describe('fetchRecordCan', () => {
	const address = { register: 'r 1', schema: 's', id: 'o1' }

	it('reads @self.can from the record extended with it', async () => {
		const fetchFn = jest.fn().mockResolvedValue({ ok: true, json: async () => ({ '@self': { can: { update: true, manage: true } } }) })

		expect(await fetchRecordCan(address, fetchFn)).toEqual({ update: true, manage: true })
		expect(fetchFn.mock.calls[0][0]).toBe('/index.php/apps/openregister/api/objects/r%201/s/o1?_extend=@self.can')
	})

	it('is null when the read fails, throws or carries no can', async () => {
		expect(await fetchRecordCan(address, jest.fn().mockResolvedValue({ ok: false }))).toBeNull()
		expect(await fetchRecordCan(address, jest.fn().mockRejectedValue(new Error('offline')))).toBeNull()
		expect(await fetchRecordCan(address, jest.fn().mockResolvedValue({ ok: true, json: async () => ({ '@self': {} }) }))).toBeNull()
	})
})

describe('read state', () => {
	const address = { register: 'r 1', schema: 's/2', id: 'o?3' }

	it('builds the encoded read-state URL', () => {
		expect(readStateUrl(address)).toBe('/index.php/apps/openregister/api/objects/r%201/s%2F2/o%3F3/read-state')
	})

	it('sends the verb to that URL and reports whether it was accepted', async () => {
		const fetchFn = jest.fn().mockResolvedValue({ ok: true })

		await expect(sendReadState(address, 'DELETE', fetchFn)).resolves.toBe(true)
		expect(fetchFn).toHaveBeenCalledWith(readStateUrl(address), expect.objectContaining({ method: 'DELETE' }))
	})

	it('reports a refusal and a network failure as false', async () => {
		await expect(sendReadState(address, 'PUT', jest.fn().mockResolvedValue({ ok: false }))).resolves.toBe(false)
		await expect(sendReadState(address, 'PUT', jest.fn().mockRejectedValue(new Error('offline')))).resolves.toBe(false)
	})
})

describe('unreadBadges', () => {
	it('gives each tab the unread count of its sub-resources', () => {
		const item = { '@self': { unreadCounts: { files: 2, messages: 1, emails: 3, events: 0, deck: 4, syncs: 1, contacts: 5 } } }

		expect(unreadBadges(item)).toEqual({ files: 2, syncs: 1, emails: 4, deck: 4, contacts: 5 })
	})

	it('shows no badge for a count that is not a positive whole number', () => {
		const item = { '@self': { unreadCounts: { files: -1, emails: 1.5, events: '3', contacts: null, deck: 1 } } }

		expect(unreadBadges(item)).toEqual({ deck: 1 })
	})

	it('shows no badges without counts', () => {
		expect(unreadBadges(null)).toEqual({})
		expect(unreadBadges({})).toEqual({})
		expect(unreadBadges({ '@self': {} })).toEqual({})
		expect(unreadBadges({ '@self': { unreadCounts: [2] } })).toEqual({})
		expect(unreadBadges({ '@self': { unreadCounts: 'files' } })).toEqual({})
	})
})

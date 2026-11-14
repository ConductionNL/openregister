/**
 * The record page's Follow props and read-state requests.
 *
 * @spec openspec/changes/record-star-follow-and-unread-on-screen/tasks.md
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
import { followProps, readStateUrl, recordAddress, sendReadState } from './recordFollow.js'

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
		})
	})

	it('gives no props for a record without an address', () => {
		expect(followProps({ '@self': { id: 'o1' } })).toBeNull()
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

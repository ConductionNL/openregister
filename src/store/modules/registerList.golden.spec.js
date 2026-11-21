import { createPinia, setActivePinia } from 'pinia'
import { useRegisterStore } from './register.js'

/**
 * Golden record of the register list refresh: the URL with and without a
 * search, that plain refreshes in flight share one request, that searches do
 * not, and the list it keeps.
 *
 * @spec exclude Golden record of the client-side register list read.
 */

describe('register store refreshRegisterList', () => {
	let store
	let calls

	beforeEach(() => {
		setActivePinia(createPinia())
		store = useRegisterStore()
		calls = []
		global.fetch = jest.fn(async (...args) => {
			calls.push(args)
			return { json: async () => ({ results: [{ id: calls.length, title: `R${calls.length}` }] }) }
		})
	})

	it('shares one request between plain refreshes in flight', async () => {
		const [answer, shared] = await Promise.all([store.refreshRegisterList(), store.refreshRegisterList()])
		expect(shared.data).toBe(answer.data)
		expect({ calls, data: answer.data, list: store.registerList.map((r) => r.title) }).toMatchSnapshot()
		await store.refreshRegisterList()
		expect(calls).toHaveLength(2)
	})

	it.each([
		['an empty search', ''],
		['a search', 'a b&c'],
	])('asks with %s, without sharing', async (label, search) => {
		await Promise.all([store.refreshRegisterList(search), store.refreshRegisterList(search)])
		expect(calls).toMatchSnapshot()
	})
})

/**
 * Golden record of the endpoint store's shape: the state and actions it
 * exposes, in order, and its initial state.
 */
import { createPinia, setActivePinia } from 'pinia'
import { useEndpointStore } from './endpoints.ts'

describe('the endpoint store', () => {
	it('exposes the same state, in the same order, and the same actions', () => {
		setActivePinia(createPinia())
		const store = useEndpointStore()
		expect(Object.keys(store.$state)).toMatchSnapshot('state')
		expect(Object.keys(store).filter((key) => typeof store[key] === 'function' && !key.startsWith('$'))).toMatchSnapshot('actions')
		expect(store.$state).toMatchSnapshot('initial state')
	})

	it('keeps the item and list it is given', () => {
		setActivePinia(createPinia())
		const store = useEndpointStore()
		store.setEndpointItem({ id: 1 })
		store.setEndpointList([{ id: 2 }])
		expect([store.getEndpointItem(), store.getEndpointList(), store.endpointItem, store.endpointList]).toEqual([{ id: 1 }, [{ id: 2 }], { id: 1 }, [{ id: 2 }]])
	})
})

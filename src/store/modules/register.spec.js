import { createPinia, setActivePinia } from 'pinia'
import { isReactive, watch } from 'vue'
import { mockRegister, Register } from '../../entities/index.js'
import { useRegisterStore } from './register.js'

describe('Register Store', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
	})

	it('wraps the active register in a Register entity', () => {
		const store = useRegisterStore()
		const item = mockRegister()[0]

		store.setRegisterItem(item)

		expect(store.registerItem).toBeInstanceOf(Register)
		expect(store.registerItem.id).toBe(item.id)
		expect(store.registerItem.title).toBe(item.title)
		expect(store.registerItem.validate().success).toBe(true)
	})

	it('clears the active register when given null', () => {
		const store = useRegisterStore()
		store.setRegisterItem(mockRegister()[0])
		store.setRegisterItem(null)
		expect(store.registerItem).toBeNull()
	})

	it('wraps every entry of the list in a Register entity', () => {
		const store = useRegisterStore()
		const items = mockRegister()

		store.setRegisterList(items)

		expect(store.registerList).toHaveLength(items.length)
		store.registerList.forEach((item, index) => {
			expect(item).toBeInstanceOf(Register)
			expect(item.id).toBe(items[index].id)
			expect(item.validate().success).toBe(true)
		})
	})

	it('holds the list rows raw, so a render does not proxy every embedded schema', () => {
		const store = useRegisterStore()
		const items = mockRegister().map((item) => ({
			...item,
			schemas: [{ id: 1, title: 'Schema', properties: { name: { type: 'string' } } }],
		}))

		store.setRegisterList(items)

		expect(store.registerList).toHaveLength(items.length)
		store.registerList.forEach((item) => {
			expect(item).toBeInstanceOf(Register)
			expect(isReactive(item)).toBe(false)
			expect(isReactive(item.schemas[0].properties)).toBe(false)
		})
	})

	it('still notifies watchers when the list is replaced', async () => {
		const store = useRegisterStore()
		const seen = []
		watch(() => store.registerList, (list) => seen.push(list.length))

		store.setRegisterList(mockRegister())
		await Promise.resolve()
		store.setRegisterList([])
		await Promise.resolve()

		expect(isReactive(store.registerList)).toBe(true)
		expect(seen).toEqual([mockRegister().length, 0])
	})
})

import { createPinia, setActivePinia } from 'pinia'
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
})

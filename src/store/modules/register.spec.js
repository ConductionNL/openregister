/* eslint-disable no-console */
import { setActivePinia, createPinia } from 'pinia'

import { useRegisterStore } from './register.js'
import { Register, mockRegister } from '../../entities/index.js'

describe('Register Store', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
	})

	it('sets register item correctly', () => {
		const store = useRegisterStore()

		store.setRegisterItem(mockRegister()[0])

		expect(store.registerItem).toBeInstanceOf(Register)
		expect(store.registerItem).toEqual(mockRegister()[0])

		expect(store.registerItem.validate().success).toBe(true)
	})

	it('sets register list correctly', () => {
		const store = useRegisterStore()

		store.setRegisterList(mockRegister())

		expect(store.registerList).toHaveLength(mockRegister().length)

		store.registerList.forEach((item, index) => {
			expect(item).toBeInstanceOf(Register)
			expect(item).toEqual(mockRegister()[index])
			expect(item.validate().success).toBe(true)
		})
	})

	// ... other tests ...
})

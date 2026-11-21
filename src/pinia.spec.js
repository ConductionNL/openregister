import { createPinia } from 'pinia'
import pinia from './pinia.js'

jest.mock('pinia', () => {
	const actual = jest.requireActual('pinia')
	return { __esModule: true, ...actual, createPinia: jest.fn(actual.createPinia) }
})

describe('pinia singleton', () => {
	it('exports the one pinia instance created at import time', () => {
		expect(createPinia).toHaveBeenCalledTimes(1)
		expect(pinia).toBe(createPinia.mock.results[0].value)
		expect(typeof pinia.install).toBe('function')
	})

	it('returns the same instance on a second import', async () => {
		const again = (await import('./pinia.js')).default
		expect(again).toBe(pinia)
		expect(createPinia).toHaveBeenCalledTimes(1)
	})
})

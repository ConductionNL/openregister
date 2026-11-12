/**
 * The two-way binding helper reads the named prop and emits its update.
 *
 * @spec exclude Unit test of client-side binding plumbing.
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
import { modelProxy } from './modelProxy.js'

describe('modelProxy', () => {
	it.each([
		[undefined, 'modelValue'],
		['batchSize', 'batchSize'],
	])('binds %s', (name, prop) => {
		const emitted = []
		const vm = { [prop]: 7, $emit: (...args) => emitted.push(args) }
		const binding = modelProxy(name)
		expect(binding.get.call(vm)).toBe(7)
		binding.set.call(vm, 9)
		expect(emitted).toEqual([[`update:${prop}`, 9]])
	})
})

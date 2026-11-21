import { objectName } from './objectName.js'

// The name the object modals show, in the order they always looked for it.

describe('objectName', () => {
	it.each([
		[{ '@self': { name: 'Self name', title: 'T' }, name: 'Own', id: 'i' }, 'Self name'],
		[{ '@self': { title: 'T' }, name: 'Own', id: 'i' }, 'Own'],
		[{ '@self': { title: 'T' }, id: 'i' }, 'T'],
		[{ id: 'i' }, 'i'],
		[{}, undefined],
		[null, undefined],
		[{ id: 0 }, 0],
	])('%j is %j', (item, name) => {
		expect(objectName(item)).toBe(name)
	})
})

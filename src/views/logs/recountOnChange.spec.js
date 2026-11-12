import { recountOnChange } from './recountOnChange.js'

// The shared log-list watcher: counts are re-emitted on the next tick, never at once.

describe('recountOnChange', () => {
	it('re-emits the counts on the next tick of the view it watches for', () => {
		const ticks = []
		const view = {
			updateCounts: jest.fn(),
			$nextTick: (callback) => ticks.push(callback),
		}
		recountOnChange.handler.call(view)
		expect(view.updateCounts).not.toHaveBeenCalled()
		ticks.forEach((callback) => callback())
		expect(view.updateCounts).toHaveBeenCalledTimes(1)
	})

	it('watches the list shallowly', () => {
		expect(recountOnChange.deep).toBe(false)
	})
})

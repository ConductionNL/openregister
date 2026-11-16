/**
 * The shared selection of the bulk object dialogs: taking objects out writes
 * the remaining ids back to the store and closes the dialog once none remain.
 *
 * @spec openspec/specs/entity-management-modals/spec.md
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
import selectedObjectsDialog from './selectedObjectsDialog.js'

jest.mock('../../store/store.js', () => ({
	objectStore: { selectedObjects: [], searchCollection: [] },
}))

const { objectStore } = jest.requireMock('../../store/store.js')

/**
 * A dialog instance using the mixin.
 *
 * @param {Array<object>} selectedObjects The rows it shows
 * @return {object} The instance
 */
function dialog(selectedObjects) {
	const vm = { selectedObjects, closeDialog: jest.fn() }
	for (const [name, fn] of Object.entries(selectedObjectsDialog.methods)) {
		vm[name] = fn.bind(vm)
	}
	return vm
}

beforeEach(() => {
	objectStore.selectedObjects = []
	objectStore.searchCollection = []
})

describe('selectedObjectsDialog removeObject', () => {
	it('keeps the dialog open while objects remain', () => {
		const vm = dialog([{ id: 'a' }, { id: 'b' }, { id: 'c' }])
		vm.removeObject('b')
		expect(vm.selectedObjects).toEqual([{ id: 'a' }, { id: 'c' }])
		expect(objectStore.selectedObjects).toEqual(['a', 'c'])
		expect(vm.closeDialog).not.toHaveBeenCalled()
	})

	it('closes the dialog when the last object is taken out', () => {
		const vm = dialog([{ id: 'a' }])
		vm.removeObject('a')
		expect(vm.selectedObjects).toEqual([])
		expect(objectStore.selectedObjects).toEqual([])
		expect(vm.closeDialog).toHaveBeenCalledTimes(1)
	})

	it('ignores an id that is not selected', () => {
		const vm = dialog([{ id: 'a' }])
		vm.removeObject('z')
		expect(objectStore.selectedObjects).toEqual(['a'])
		expect(vm.closeDialog).not.toHaveBeenCalled()
	})
})

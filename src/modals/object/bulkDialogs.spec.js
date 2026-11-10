/**
 * The object dialogs reached from the search list: a selection of ids becomes
 * rows, and each dialog calls the store and endpoint the way they answer.
 *
 * @spec openspec/specs/entity-management-modals/spec.md
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
import MassValidateObjects from './MassValidateObjects.vue'
import MergeObject from './MergeObject.vue'
import MigrationObject from './MigrationObject.vue'
import { refreshObjectLists, resolveSelection } from './selectedObjectsDialog.js'

jest.mock('@nextcloud/l10n', () => ({
	translate: (app, text) => text,
	translatePlural: (app, one, many, count) =>
		(count === 1 ? one : many).replace('{count}', count),
}))
jest.mock('@nextcloud/vue', () => ({}))
jest.mock('vue-material-design-icons/ArrowLeft.vue', () => ({}))
jest.mock('vue-material-design-icons/ArrowRight.vue', () => ({}))
jest.mock('vue-material-design-icons/Cancel.vue', () => ({}))
jest.mock('vue-material-design-icons/CheckCircle.vue', () => ({}))
jest.mock('vue-material-design-icons/ChevronDown.vue', () => ({}))
jest.mock('vue-material-design-icons/ChevronUp.vue', () => ({}))
jest.mock('vue-material-design-icons/Close.vue', () => ({}))
jest.mock('vue-material-design-icons/DatabaseExport.vue', () => ({}))
jest.mock('vue-material-design-icons/DatabaseOutline.vue', () => ({}))
jest.mock('vue-material-design-icons/Eye.vue', () => ({}))
jest.mock('vue-material-design-icons/FileTreeOutline.vue', () => ({}))
jest.mock('vue-material-design-icons/Merge.vue', () => ({}))
jest.mock('../../store/store.js', () => ({
	navigationStore: { setDialog: jest.fn(), setModal: jest.fn() },
	objectStore: {
		currentType: '1-2',
		lifecycleError: null,
		selectedObjects: [],
		searchCollection: [],
		fetchObject: jest.fn(),
		saveObject: jest.fn(),
		mergeObjects: jest.fn(),
		refetchSearchCollection: jest.fn(),
		refreshObjectList: jest.fn(),
	},
	registerStore: { registerItem: { id: 1 } },
	schemaStore: { schemaItem: { id: 2 } },
}))

const { navigationStore, objectStore } = jest.requireMock('../../store/store.js')

const ROW_A = { '@self': { id: 'a' }, id: 'a', title: 'One' }
const ROW_B = { '@self': { id: 'b' }, title: 'Two' }

/**
 * A stand-in for a dialog instance, with the methods of the component and the
 * mixin it uses.
 *
 * @param {object} component The dialog
 * @param {object} state Its data
 * @return {object} The instance
 */
function instance(component, state) {
	const mixins = (component.mixins || []).map((mixin) => mixin.methods)
	return Object.assign(
		Object.create({ ...Object.assign({}, ...mixins), ...component.methods }),
		state,
	)
}

beforeEach(() => {
	jest.clearAllMocks()
	jest.spyOn(console, 'error').mockImplementation(() => {})
	objectStore.selectedObjects = []
	objectStore.searchCollection = [ROW_A, ROW_B]
	objectStore.lifecycleError = null
	objectStore.refreshObjectList.mockResolvedValue({})
})

describe('resolveSelection', () => {
	it('turns ids into their rows, with a top-level id', () => {
		expect(resolveSelection(['b'], [ROW_A, ROW_B])).toEqual([
			{ ...ROW_B, id: 'b' },
		])
	})

	it('keeps an id whose row is not loaded as a bare id', () => {
		expect(resolveSelection(['x'], [ROW_A])).toEqual([{ id: 'x' }])
	})

	it('keeps rows and drops entries without an id', () => {
		expect(resolveSelection([ROW_B, ''], undefined)).toEqual([
			{ ...ROW_B, id: 'b' },
		])
	})

	it('reads an empty selection as no rows', () => {
		expect(resolveSelection(undefined, [ROW_A])).toEqual([])
	})
})

describe('refreshObjectLists', () => {
	it('clears the selection and refreshes both lists', () => {
		objectStore.selectedObjects = ['a']
		refreshObjectLists()
		expect(objectStore.selectedObjects).toEqual([])
		expect(objectStore.refetchSearchCollection).toHaveBeenCalledTimes(1)
		expect(objectStore.refreshObjectList).toHaveBeenCalledTimes(1)
	})

	it('accepts a view without a register and schema pair', async () => {
		objectStore.refreshObjectList.mockRejectedValue(new Error('no pair'))
		expect(() => refreshObjectLists()).not.toThrow()
		await Promise.resolve()
	})
})

describe('MassValidateObjects', () => {
	const dialog = (state = {}) =>
		instance(MassValidateObjects, {
			selectedObjects: [],
			validatedCount: 0,
			success: null,
			error: false,
			closeModalTimeout: null,
			...state,
		})

	it('resolves the ids in the store to rows', () => {
		objectStore.selectedObjects = ['a']
		const vm = dialog()
		vm.initializeSelection()
		expect(vm.selectedObjects).toEqual([ROW_A])
		expect(navigationStore.setDialog).not.toHaveBeenCalled()
	})

	it('writes ids back when an object is removed', () => {
		const vm = dialog({ selectedObjects: [ROW_A, { ...ROW_B, id: 'b' }] })
		vm.removeObject('a')
		expect(objectStore.selectedObjects).toEqual(['b'])
	})

	it('saves a loaded row under the current type', async () => {
		objectStore.saveObject.mockResolvedValue({ id: 'a' })
		await expect(dialog().validateObject(ROW_A)).resolves.toBe(true)
		expect(objectStore.saveObject).toHaveBeenCalledWith('1-2', ROW_A)
		expect(objectStore.fetchObject).not.toHaveBeenCalled()
	})

	it('fetches a row that is not loaded instead of saving a bare id', async () => {
		const fetched = { '@self': { id: 'x' }, id: 'x', title: 'Far away' }
		objectStore.fetchObject.mockResolvedValue(fetched)
		objectStore.saveObject.mockResolvedValue(fetched)
		await expect(dialog().validateObject({ id: 'x' })).resolves.toBe(true)
		expect(objectStore.fetchObject).toHaveBeenCalledWith('1-2', 'x')
		expect(objectStore.saveObject).toHaveBeenCalledWith('1-2', fetched)
	})

	it('does not save an object it cannot fetch', async () => {
		objectStore.fetchObject.mockResolvedValue(null)
		await expect(dialog().validateObject({ id: 'x' })).resolves.toBe(false)
		expect(objectStore.saveObject).not.toHaveBeenCalled()
	})

	it('counts a refused save as failed', async () => {
		objectStore.saveObject.mockResolvedValue(null)
		await expect(dialog().validateObject(ROW_A)).resolves.toBe(false)
	})

	it('counts a thrown save as failed', async () => {
		objectStore.saveObject.mockRejectedValue(new Error('down'))
		await expect(dialog().validateObject(ROW_A)).resolves.toBe(false)
	})

	it('closes by itself when every object was saved', () => {
		jest.useFakeTimers()
		const vm = dialog()
		vm.showOutcome([true, true])
		jest.runOnlyPendingTimers()
		jest.useRealTimers()
		expect(vm.validatedCount).toBe(2)
		expect(vm.success).toBe(true)
		expect(vm.error).toBe(false)
		expect(navigationStore.setDialog).toHaveBeenCalledWith(false)
	})

	it('reports both counts and stays open on a partial result', () => {
		jest.useFakeTimers()
		const vm = dialog()
		vm.showOutcome([true, false, false])
		jest.runOnlyPendingTimers()
		jest.useRealTimers()
		expect(vm.validatedCount).toBe(1)
		expect(vm.success).toBe(true)
		expect(vm.error).toBe('2 objects could not be validated')
		expect(navigationStore.setDialog).not.toHaveBeenCalled()
	})

	it('keeps the selection on screen when nothing was saved', () => {
		objectStore.selectedObjects = ['a']
		const vm = dialog()
		vm.showOutcome([false])
		expect(vm.success).toBe(null)
		expect(objectStore.selectedObjects).toEqual(['a'])
		expect(objectStore.refetchSearchCollection).not.toHaveBeenCalled()
	})
})

describe('MigrationObject', () => {
	const dialog = () =>
		instance(MigrationObject, {
			selectedObjects: [{ ...ROW_B, id: 'b' }, { id: 'x' }],
			mapping: { title: 'title' },
			uiMappings: {},
			targetRegister: { id: 3 },
			targetSchema: { id: 4 },
			canMigrate: true,
			step: 3,
			loading: false,
			migrationResult: null,
		})

	/**
	 * Answer the next fetch.
	 *
	 * @param {boolean} ok Whether the request succeeds
	 * @param {object} body The JSON body
	 * @return {void}
	 */
	function answer(ok, body) {
		globalThis.fetch = jest.fn(() =>
			Promise.resolve({ ok, json: () => Promise.resolve(body) }),
		)
	}

	it('resolves the ids in the store and loads the registers', async () => {
		objectStore.selectedObjects = ['b']
		answer(true, { results: [] })
		const vm = dialog()
		vm.selectedObjects = []
		vm.initializeMigration()
		expect(vm.selectedObjects).toEqual([{ ...ROW_B, id: 'b' }])
		expect(globalThis.fetch).toHaveBeenCalledTimes(1)
	})

	it('offers only the schemas of the chosen register', () => {
		const vm = dialog()
		vm.targetRegister = { id: 3, schemas: [{ id: 4 }] }
		vm.onRegisterChange()
		expect(vm.availableSchemas).toEqual([{ id: 4 }])
		expect(vm.targetSchema).toBe(null)
	})

	it('offers no schemas without a register', () => {
		const vm = dialog()
		vm.availableSchemas = [{ id: 4 }]
		vm.targetRegister = null
		vm.onRegisterChange()
		expect(vm.availableSchemas).toEqual([])
	})

	it('closes without loading anything on an empty selection', () => {
		answer(true, { results: [] })
		const vm = dialog()
		vm.initializeMigration()
		expect(navigationStore.setModal).toHaveBeenCalledWith(false)
		expect(globalThis.fetch).not.toHaveBeenCalled()
	})

	it('posts the ids and refreshes both lists', async () => {
		objectStore.selectedObjects = ['b', 'x']
		answer(true, { success: true })
		const vm = dialog()
		await vm.performMigration()
		const body = JSON.parse(globalThis.fetch.mock.calls[0][1].body)
		expect(body.objects).toEqual(['b', 'x'])
		expect(vm.migrationResult).toEqual({ success: true })
		expect(vm.step).toBe(4)
		expect(objectStore.selectedObjects).toEqual([])
		expect(objectStore.refetchSearchCollection).toHaveBeenCalledTimes(1)
	})

	it('reports the reason the server gives', async () => {
		objectStore.selectedObjects = ['b']
		answer(false, { error: 'Not allowed here' })
		const vm = dialog()
		await vm.performMigration()
		expect(vm.migrationResult).toEqual({
			success: false,
			errors: ['Not allowed here'],
		})
		expect(objectStore.selectedObjects).toEqual(['b'])
	})
})

describe('MergeObject', () => {
	const dialog = () =>
		instance(MergeObject, {
			canMerge: true,
			loading: false,
			step: 2,
			mergedData: { title: 'Kept', id: 'ignored' },
			customValues: {},
			sourceObject: ROW_A,
			selectedTargetObject: { ...ROW_B, id: 'b' },
			fileAction: 'transfer',
			relationAction: 'transfer',
			referenceAction: 'keep',
			mergeResult: null,
		})

	it('calls the store with the type, the source and the options', async () => {
		objectStore.mergeObjects.mockResolvedValue({ success: true })
		const vm = dialog()
		await vm.performMerge()
		expect(objectStore.mergeObjects).toHaveBeenCalledWith('1-2', 'a', {
			target: 'b',
			object: { title: 'Kept' },
			fileAction: 'transfer',
			relationAction: 'transfer',
			referenceAction: 'keep',
		})
		expect(vm.mergeResult).toEqual({ success: true })
		expect(vm.step).toBe(3)
		expect(objectStore.refetchSearchCollection).toHaveBeenCalledTimes(1)
	})

	it('reports the failure the store recorded', async () => {
		objectStore.mergeObjects.mockResolvedValue(null)
		objectStore.lifecycleError = { message: 'Locked' }
		const vm = dialog()
		await vm.performMerge()
		expect(vm.mergeResult).toEqual({ success: false, errors: ['Locked'] })
		expect(objectStore.refetchSearchCollection).not.toHaveBeenCalled()
	})
})

/**
 * The view-source modal: it lists the registers of the selected source,
 * loads them on mount and navigates to edit, delete or a register while
 * closing itself. Hooks and methods run against a plain `this` with stub
 * stores and router.
 *
 * @spec exclude Client-side view-source modal.
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
import ViewSource from './ViewSource.vue'

jest.mock('@nextcloud/vue', () => ({}))
jest.mock('@nextcloud/l10n', () => ({ translate: (app, s) => s, translatePlural: (app, s) => s }))
jest.mock('vue-material-design-icons/DatabaseOutline.vue', () => ({}))
jest.mock('vue-material-design-icons/Eye.vue', () => ({}))
jest.mock('vue-material-design-icons/Pencil.vue', () => ({}))
jest.mock('vue-material-design-icons/TrashCanOutline.vue', () => ({}))
jest.mock('../../components/shared/CloseCancelButton.vue', () => ({}))
jest.mock('../register/EditRegisterFormDialog.vue', () => ({}))
jest.mock('../shared/EmptyLogsTab.vue', () => ({}))
jest.mock('../shared/TabEmptyContent.vue', () => ({}))
jest.mock('../shared/TimestampProperties.vue', () => ({}))
jest.mock('../shared/ViewDialogTabs.vue', () => ({}))
jest.mock('../../store/store.js', () => ({
	navigationStore: { setModal: jest.fn(), setDialog: jest.fn() },
	registerStore: { registerList: [], refreshRegisterList: jest.fn(), setRegisterItem: jest.fn() },
	sourceStore: { sourceItem: null },
}))

const { navigationStore, registerStore, sourceStore } = jest.requireMock('../../store/store.js')

/**
 * A plain modal instance with its data and bound methods.
 *
 * @return {object} The instance
 */
function modal() {
	const vm = { t: (app, s) => s, $router: { push: jest.fn() } }
	Object.assign(vm, ViewSource.data.call(vm))
	for (const [name, fn] of Object.entries(ViewSource.methods)) vm[name] = fn.bind(vm)
	return vm
}

const flush = () => new Promise((resolve) => setTimeout(resolve, 0))

beforeEach(() => {
	jest.clearAllMocks()
	sourceStore.sourceItem = { id: 5, title: 'Main DB' }
	registerStore.registerList = []
	registerStore.refreshRegisterList.mockResolvedValue()
})

describe('ViewSource', () => {
	it('starts on the registers tab with both tabs named', () => {
		const vm = modal()
		expect(vm.activeTab).toBe(0)
		expect(vm.tabs).toEqual(['Registers', 'Logs'])
		expect(vm.showEditRegisterDialog).toBe(false)
		expect(vm.editingRegister).toBeNull()
	})

	it('lists only the registers whose source matches the selected source, by string id', () => {
		registerStore.registerList = [
			{ id: 1, source: 5 },
			{ id: 2, source: '5' },
			{ id: 3, source: 6 },
			{ id: 4, source: null },
			{ id: 5 },
			{ id: 6, source: '55' },
		]
		expect(ViewSource.computed.filterRegisters.call({}).map((r) => r.id)).toEqual([1, 2])
	})

	it.each([
		['no register list', null, { id: 5 }],
		['no source', [{ id: 1, source: 5 }], null],
		['a source without id', [{ id: 1, source: 5 }], { title: 'x' }],
	])('lists no registers with %s', (name, list, source) => {
		registerStore.registerList = list
		sourceStore.sourceItem = source
		expect(ViewSource.computed.filterRegisters.call({})).toEqual([])
	})

	it('loads the registers on mount and clears the loading flag', async () => {
		const vm = modal()
		ViewSource.mounted.call(vm)
		expect(vm.registersLoading).toBe(true)
		expect(registerStore.refreshRegisterList).toHaveBeenCalledTimes(1)
		await flush()
		expect(vm.registersLoading).toBe(false)
	})

	it('clears the loading flag when loading the registers fails', async () => {
		registerStore.refreshRegisterList.mockRejectedValue(new Error('down'))
		const vm = modal()
		vm.fetchRegisters()
		expect(vm.registersLoading).toBe(true)
		await flush()
		expect(vm.registersLoading).toBe(false)
	})

	it('closes the modal', () => {
		modal().closeModal()
		expect(navigationStore.setModal).toHaveBeenCalledWith(false)
		expect(navigationStore.setDialog).not.toHaveBeenCalled()
	})

	it('opens the edit-source modal', () => {
		modal().editSource()
		expect(navigationStore.setModal).toHaveBeenCalledWith('editSource')
	})

	it('swaps the modal for the delete-source dialog', () => {
		modal().deleteSource()
		expect(navigationStore.setModal).toHaveBeenCalledWith(false)
		expect(navigationStore.setDialog).toHaveBeenCalledWith('deleteSource')
	})

	it('selects a register, closes and routes to the registers view', () => {
		const vm = modal()
		const register = { id: 9, title: 'Personen' }
		vm.viewRegister(register)
		expect(registerStore.setRegisterItem).toHaveBeenCalledWith(register)
		expect(navigationStore.setModal).toHaveBeenCalledWith(false)
		expect(vm.$router.push).toHaveBeenCalledWith('/registers')
	})

	it('opens the inline edit dialog for a register without leaving the modal', () => {
		const vm = modal()
		const register = { id: 9 }
		vm.editRegister(register)
		expect(vm.editingRegister).toBe(register)
		expect(vm.showEditRegisterDialog).toBe(true)
		expect(navigationStore.setModal).not.toHaveBeenCalled()
	})
})

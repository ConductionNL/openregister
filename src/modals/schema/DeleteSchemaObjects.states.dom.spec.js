import { flushPromises, mount } from '@vue/test-utils'
/**
 * The delete-all-objects dialog in each of its states: the soft and the
 * permanent warning, the hard-delete option, deleting, done and failed, and
 * what closing resets.
 *
 * @spec exclude Test of client-side modal display states.
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
import DeleteSchemaObjects from './DeleteSchemaObjects.vue'

jest.mock('@nextcloud/l10n', () => ({
	t: (app, text) => text,
	translate: (app, text, vars = {}) => text.replace(/{(\w+)}/g, (whole, key) => (key in vars ? String(vars[key]) : whole)),
	translatePlural: (app, one) => one,
}))
jest.mock('@nextcloud/vue', () => mockLibrary())
jest.mock('vue', () => ({ ...jest.requireActual('vue'), ...mockLibrary() }))
jest.mock('vue-material-design-icons/AlertCircle.vue', () => ({
	__esModule: true,
	default: {},
}))
jest.mock('vue-material-design-icons/Cancel.vue', () => ({
	__esModule: true,
	default: {},
}))
jest.mock('vue-material-design-icons/CheckCircle.vue', () => ({
	__esModule: true,
	default: {},
}))
jest.mock('vue-material-design-icons/DatabaseOutline.vue', () => ({
	__esModule: true,
	default: {},
}))
jest.mock('vue-material-design-icons/DeleteSweep.vue', () => ({
	__esModule: true,
	default: {},
}))
jest.mock('vue-material-design-icons/Eye.vue', () => ({
	__esModule: true,
	default: {},
}))
jest.mock('vue-material-design-icons/TrashCanOutline.vue', () => ({
	__esModule: true,
	default: {},
}))
jest.mock('../../store/store.js', () => ({
	navigationStore: { dialog: null, setDialog: () => {} },
	registerStore: {},
	schemaStore: { schemaItem: { id: 147, title: 'Project' }, getSchemaStats: null },
}))

const { navigationStore, schemaStore } = jest.requireMock('../../store/store.js')

/**
 * The library components these modals use, as stubs rendering their slots.
 *
 * @return {object} The module
 */
function mockLibrary() {
	const { h: render } = jest.requireActual('vue')
	const stub = (name) => ({
		name,
		render() {
			return render(
				'div',
				Object.values(this.$slots).map((slot) => slot({})),
			)
		},
	})
	return {
		__esModule: true,
		NcButton: stub('NcButton'),
		NcCheckboxRadioSwitch: stub('NcCheckboxRadioSwitch'),
		NcDialog: stub('NcDialog'),
		NcEmptyContent: stub('NcEmptyContent'),
		NcLoadingIcon: stub('NcLoadingIcon'),
		NcNoteCard: stub('NcNoteCard'),
	}
}

/**
 * Mounts the dialog with stats that answer at once.
 *
 * @param {object} objects The object stats the schema answers with
 * @return {Promise<object>} The wrapper
 */
async function open(objects) {
	navigationStore.dialog = 'deleteSchemaObjects'
	schemaStore.getSchemaStats = () => Promise.resolve({ objects })
	const wrapper = mount(DeleteSchemaObjects, {
		global: { mocks: { t: (app, text) => text } },
	})
	await flushPromises()
	return wrapper
}

describe('DeleteSchemaObjects states', () => {
	beforeEach(() => jest.spyOn(console, 'error').mockImplementation(() => {}))
	afterEach(() => jest.restoreAllMocks())

	it('warns about a soft delete and offers no hard delete without deleted objects', async () => {
		const wrapper = await open({ total: 3, invalid: 0, deleted: 0 })

		expect(wrapper.text()).toContain('Are you sure you want to delete all objects in the schema Project?')
		expect(wrapper.find('.deletion-warning').exists()).toBe(true)
		expect(wrapper.text()).toContain('Soft Delete Mode')
		expect(wrapper.find('.deletion-danger').exists()).toBe(false)
		expect(wrapper.find('.hard-delete-option').exists()).toBe(false)
	})

	it('offers the hard delete when soft-deleted objects exist', async () => {
		const wrapper = await open({ total: 3, invalid: 0, deleted: 2 })

		expect(wrapper.find('.hard-delete-option').exists()).toBe(true)
		expect(wrapper.find('.hard-delete-description').text()).toContain('This will permanently remove 2 already soft-deleted objects')
	})

	it('turns the warning into a permanent-delete danger when hard delete is ticked', async () => {
		const wrapper = await open({ total: 3, invalid: 0, deleted: 2 })

		wrapper.vm.$.proxy.hardDelete = ['hardDelete']
		await flushPromises()

		expect(wrapper.find('.deletion-warning').exists()).toBe(false)
		expect(wrapper.find('.deletion-danger').text()).toContain('Permanent Delete Mode')
		expect(wrapper.find('.deletion-danger').text()).toContain('All 3 objects will be PERMANENTLY DELETED')
	})

	it('shows the progress and hides the question while deleting', async () => {
		const wrapper = await open({ total: 3, invalid: 0, deleted: 0 })

		wrapper.vm.$.proxy.loading = true
		await flushPromises()

		expect(wrapper.find('.loading-container').text()).toContain("Deleting objects from schema 'Project'...")
		expect(wrapper.text()).not.toContain('Are you sure you want to delete')
	})

	it('shows how many objects were deleted once done', async () => {
		const wrapper = await open({ total: 3, invalid: 0, deleted: 0 })

		wrapper.vm.$.proxy.success = true
		wrapper.vm.$.proxy.deletionResult = { deleted_count: 5 }
		await flushPromises()

		const done = wrapper.find('.success-container').text()
		expect(done).toContain('Deletion Completed Successfully')
		expect(done).toContain('Objects deleted: 5')
		expect(done).toContain('Schema: Project')
		expect(wrapper.text()).not.toContain('Are you sure you want to delete')
	})

	it('reports zero deleted when the result has no count', async () => {
		const wrapper = await open({ total: 3, invalid: 0, deleted: 0 })

		wrapper.vm.$.proxy.success = true
		await flushPromises()

		expect(wrapper.find('.success-container').text()).toContain('Objects deleted: 0')
	})

	it('shows the failure', async () => {
		const wrapper = await open({ total: 3, invalid: 0, deleted: 0 })

		wrapper.vm.$.proxy.error = 'Could not find register containing this schema'
		await flushPromises()

		expect(wrapper.text()).toContain('Deletion Failed')
		expect(wrapper.text()).toContain('Could not find register containing this schema')
	})

	it('resets every state on close', async () => {
		const setDialog = jest.fn()
		navigationStore.setDialog = setDialog
		const wrapper = await open({ total: 3, invalid: 0, deleted: 2 })
		const vm = wrapper.vm.$.proxy
		Object.assign(vm, { loading: true, error: 'x', success: true, deletionResult: { deleted_count: 1 }, hardDelete: ['hardDelete'] })

		vm.closeDialog()

		expect(setDialog).toHaveBeenCalledWith(false)
		expect({
			loading: vm.loading,
			error: vm.error,
			success: vm.success,
			deletionResult: vm.deletionResult,
			objectCount: vm.objectCount,
			objectStats: vm.objectStats,
			hardDelete: vm.hardDelete,
		}).toEqual({
			loading: false,
			error: false,
			success: false,
			deletionResult: null,
			objectCount: 0,
			objectStats: null,
			hardDelete: [],
		})
	})
})

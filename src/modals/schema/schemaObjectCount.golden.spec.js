/**
 * Golden record of how the schema validate and delete-objects dialogs count
 * the objects of the active schema: on mount, again when the schema changes
 * before any were counted, and what each shows after a failed count.
 *
 * @spec exclude Golden record of client-side dialog state and markup.
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
import { flushPromises, mount } from '@vue/test-utils'
import { nextTick } from 'vue'
import DeleteSchemaObjects from './DeleteSchemaObjects.vue'
import ValidateSchema from './ValidateSchema.vue'

jest.mock('@nextcloud/l10n', () => ({
	translate: (app, text) => `t:${text}`,
	translatePlural: (app, one) => `n:${one}`,
}))
jest.mock('@nextcloud/vue', () =>
	require('../../views/__mocks__/goldenDom.js').library(),
)
jest.mock('../../components/SchemaStatsBlock.vue', () =>
	require('../../views/__mocks__/goldenDom.js').stubModule('SchemaStatsBlock'),
)
jest.mock('../../store/store.js', () => {
	const { reactive } = require('vue')
	return {
		navigationStore: reactive({ dialog: '' }),
		schemaStore: reactive({ schemaItem: null, getSchemaStats: jest.fn() }),
	}
})
jest.mock('vue-material-design-icons/AlertCircle.vue', () =>
	require('../../views/__mocks__/goldenDom.js').stubModule('AlertCircle'),
)
jest.mock('vue-material-design-icons/Cancel.vue', () =>
	require('../../views/__mocks__/goldenDom.js').stubModule('Cancel'),
)
jest.mock('vue-material-design-icons/CheckCircle.vue', () =>
	require('../../views/__mocks__/goldenDom.js').stubModule('CheckCircle'),
)
jest.mock('vue-material-design-icons/DatabaseOutline.vue', () =>
	require('../../views/__mocks__/goldenDom.js').stubModule('DatabaseOutline'),
)
jest.mock('vue-material-design-icons/DeleteSweep.vue', () =>
	require('../../views/__mocks__/goldenDom.js').stubModule('DeleteSweep'),
)
jest.mock('vue-material-design-icons/Eye.vue', () =>
	require('../../views/__mocks__/goldenDom.js').stubModule('Eye'),
)

const { normalise } = require('../../views/__mocks__/goldenDom.js')
const { navigationStore, schemaStore } = jest.requireMock('../../store/store.js')

const mounted = []

const DIALOGS = [
	[
		'ValidateSchema',
		ValidateSchema,
		'validateSchema',
		'Error in loadObjectCount:',
	],
	[
		'DeleteSchemaObjects',
		DeleteSchemaObjects,
		'deleteSchemaObjects',
		'DeleteSchemaObjects error in loadObjectCount:',
	],
]

/**
 * Mounts a dialog once its pending loads settle.
 *
 * @param {object} component The dialog
 * @param {string} dialog The navigation dialog that opens it
 * @return {Promise<object>} The wrapper
 */
async function open(component, dialog) {
	navigationStore.dialog = dialog
	const wrapper = mount(component, {
		global: { config: { warnHandler: () => {} } },
	})
	mounted.push(wrapper)
	await flushPromises()
	return wrapper
}

let consoleError

beforeEach(() => {
	schemaStore.schemaItem = { id: 7, title: 'Books' }
	schemaStore.getSchemaStats.mockReset()
	consoleError = jest.spyOn(console, 'error').mockImplementation(() => {})
})

afterEach(() => {
	mounted.splice(0).forEach((wrapper) => wrapper.unmount())
	consoleError.mockRestore()
})

describe.each(DIALOGS)('%s object count', (name, component, dialog, label) => {
	it('counts the objects of the schema on mount', async () => {
		schemaStore.getSchemaStats.mockResolvedValue({
			objects: { total: 12, deleted: 2, invalid: 1 },
		})
		const wrapper = await open(component, dialog)
		expect(schemaStore.getSchemaStats).toHaveBeenCalledWith(7)
		expect(wrapper.vm.objectCount).toBe(12)
		expect(wrapper.vm.objectStats).toEqual({ total: 12, deleted: 2, invalid: 1 })
		expect(wrapper.vm.countLoading).toBe(false)
		expect(wrapper.vm.countFailed).toBe(false)
		expect(normalise(wrapper)).toMatchSnapshot()
	})

	it('counts nothing without a total', async () => {
		schemaStore.getSchemaStats.mockResolvedValue({ objects: null })
		const wrapper = await open(component, dialog)
		expect(wrapper.vm.objectCount).toBe(0)
		expect(wrapper.vm.objectStats).toBeNull()
		expect(wrapper.vm.countFailed).toBe(false)
	})

	it('marks a failed count and logs it', async () => {
		const error = new Error('down')
		schemaStore.getSchemaStats.mockRejectedValue(error)
		const wrapper = await open(component, dialog)
		expect(wrapper.vm.objectCount).toBe(0)
		expect(wrapper.vm.objectStats).toBeNull()
		expect(wrapper.vm.countFailed).toBe(true)
		expect(wrapper.vm.countLoading).toBe(false)
		expect(consoleError).toHaveBeenCalledWith(label, error)
		expect(normalise(wrapper)).toMatchSnapshot()
	})

	it('does not count without a schema, and counts once one is chosen', async () => {
		schemaStore.schemaItem = null
		schemaStore.getSchemaStats.mockResolvedValue({ objects: { total: 3 } })
		const wrapper = await open(component, dialog)
		expect(schemaStore.getSchemaStats).not.toHaveBeenCalled()
		expect(wrapper.vm.countLoading).toBe(false)
		schemaStore.schemaItem = { id: 9, title: 'Films' }
		await nextTick()
		await flushPromises()
		expect(schemaStore.getSchemaStats).toHaveBeenCalledWith(9)
		expect(wrapper.vm.objectCount).toBe(3)
	})

	it('does not count again when the schema changes after a count', async () => {
		schemaStore.getSchemaStats.mockResolvedValue({ objects: { total: 4 } })
		await open(component, dialog)
		schemaStore.schemaItem = { id: 9, title: 'Films' }
		await nextTick()
		await flushPromises()
		expect(schemaStore.getSchemaStats).toHaveBeenCalledTimes(1)
	})
})

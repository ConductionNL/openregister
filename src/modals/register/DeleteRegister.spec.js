import { flushPromises, mount } from '@vue/test-utils'
import DeleteRegister from './DeleteRegister.vue'
import { navigationStore, registerStore } from '../../store/store.js'

jest.mock('@nextcloud/l10n', () => ({ __esModule: true, translate: (app, s) => s }))
jest.mock('../../store/store.js', () => {
	const { reactive } = require('vue')
	return {
		__esModule: true,
		registerStore: reactive({ registerItem: null, deleteRegister: jest.fn() }),
		navigationStore: reactive({ dialog: 'deleteRegister', setDialog: jest.fn() }),
	}
})
// The shared frame is presentational; a stand-in exposes its props, renders
// the slot and lets the spec raise its cancel / confirm events.
jest.mock('../shared/ConfirmDialogFrame.vue', () => ({
	__esModule: true,
	default: {
		name: 'ConfirmDialogFrame',
		inheritAttrs: false,
		emits: ['cancel', 'confirm'],
		template: '<div class="frame-stub"><slot /></div>',
	},
}))

const frame = (wrapper) => wrapper.findComponent({ name: 'ConfirmDialogFrame' })
const attrs = (wrapper) => frame(wrapper).vm.$attrs

describe('DeleteRegister', () => {
	beforeEach(() => {
		jest.useFakeTimers()
		jest.clearAllMocks()
		navigationStore.dialog = 'deleteRegister'
		registerStore.registerItem = { id: 2, title: 'Personen', schemas: [] }
	})

	afterEach(() => {
		jest.useRealTimers()
	})

	it('renders only while its dialog is open', () => {
		navigationStore.dialog = 'deleteSchema'
		expect(mount(DeleteRegister).find('.frame-stub').exists()).toBe(false)
	})

	it('offers the delete for a register without schemas', () => {
		const wrapper = mount(DeleteRegister)
		expect(attrs(wrapper).showConfirm).toBe(true)
		expect(attrs(wrapper).closeLabel).toBe('Annuleer')
		expect(wrapper.find('b').text()).toBe('Personen')
	})

	it.each([
		[['s1'], 'is', '1', 'schema in'],
		[['s1', 's2'], 'zijn', '2', "schema's"],
	])('refuses a register that still holds schemas %j', (schemas, verb, count, noun) => {
		registerStore.registerItem = { id: 2, title: 'Personen', schemas }
		const wrapper = mount(DeleteRegister)
		expect(attrs(wrapper).showConfirm).toBe(false)
		expect(attrs(wrapper).closeLabel).toBe('Sluiten')
		const text = wrapper.text().replace(/\s+/g, ' ')
		expect(text).toContain(`Er ${verb} nog ${count} ${noun}`)
	})

	it('confirm on a register with schemas does not delete', async () => {
		registerStore.registerItem = { id: 2, schemas: ['s1'] }
		const wrapper = mount(DeleteRegister)
		frame(wrapper).vm.$emit('confirm')
		await flushPromises()
		expect(registerStore.deleteRegister).not.toHaveBeenCalled()
	})

	it('confirm deletes a copy of the register, reports success and closes after two seconds', async () => {
		registerStore.deleteRegister.mockResolvedValue({ response: { ok: true } })
		const wrapper = mount(DeleteRegister)
		frame(wrapper).vm.$emit('confirm')
		expect(wrapper.vm.loading).toBe(true)
		await flushPromises()
		expect(registerStore.deleteRegister).toHaveBeenCalledWith({ id: 2, title: 'Personen', schemas: [] })
		expect(registerStore.deleteRegister.mock.calls[0][0]).not.toBe(registerStore.registerItem)
		expect(attrs(wrapper).success).toBe(true)
		expect(attrs(wrapper).loading).toBe(false)
		expect(navigationStore.setDialog).not.toHaveBeenCalled()
		jest.advanceTimersByTime(2000)
		expect(navigationStore.setDialog).toHaveBeenCalledWith(false)
	})

	it('shows the failure message when the delete is rejected', async () => {
		registerStore.deleteRegister.mockRejectedValue({})
		const wrapper = mount(DeleteRegister)
		frame(wrapper).vm.$emit('confirm')
		await flushPromises()
		expect(attrs(wrapper).error).toBe('Er is een fout opgetreden bij het verwijderen van het register')
		expect(attrs(wrapper).success).toBe(false)
	})

	it('cancel closes and resets the dialog', async () => {
		const wrapper = mount(DeleteRegister)
		frame(wrapper).vm.$emit('cancel')
		expect(navigationStore.setDialog).toHaveBeenCalledWith(false)
	})
})

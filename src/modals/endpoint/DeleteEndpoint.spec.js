import { flushPromises, mount } from '@vue/test-utils'
import DeleteEndpoint from './DeleteEndpoint.vue'
import { endpointStore, navigationStore } from '../../store/store.js'

jest.mock('@nextcloud/l10n', () => ({ __esModule: true, translate: (app, s) => s }))
jest.mock('../../store/store.js', () => {
	const { reactive } = require('vue')
	return {
		__esModule: true,
		endpointStore: reactive({ endpointItem: null, deleteEndpoint: jest.fn() }),
		navigationStore: reactive({ dialog: 'deleteEndpoint', setDialog: jest.fn() }),
	}
})
// The shared frame is presentational; a stand-in renders the slot and lets
// the spec raise its cancel / confirm events.
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

describe('DeleteEndpoint', () => {
	beforeEach(() => {
		jest.clearAllMocks()
		navigationStore.dialog = 'deleteEndpoint'
		endpointStore.endpointItem = { id: 4, name: 'Zaken API' }
		global.OCP = { Toast: { success: jest.fn(), error: jest.fn() } }
	})

	afterEach(() => {
		delete global.OCP
	})

	it('renders only while its dialog is open', () => {
		navigationStore.dialog = 'other'
		expect(mount(DeleteEndpoint).find('.frame-stub').exists()).toBe(false)
		navigationStore.dialog = 'deleteEndpoint'
		expect(mount(DeleteEndpoint).find('.frame-stub').exists()).toBe(true)
	})

	it('names the endpoint it is about to delete', () => {
		const wrapper = mount(DeleteEndpoint)
		expect(wrapper.find('b').text()).toBe('Zaken API')
		expect(wrapper.text()).toContain('This action cannot be undone.')
	})

	it('cancel closes the dialog without deleting', () => {
		const wrapper = mount(DeleteEndpoint)
		frame(wrapper).vm.$emit('cancel')
		expect(navigationStore.setDialog).toHaveBeenCalledWith(false)
		expect(endpointStore.deleteEndpoint).not.toHaveBeenCalled()
	})

	it('confirm deletes the endpoint, closes and toasts success', async () => {
		endpointStore.deleteEndpoint.mockResolvedValue()
		const wrapper = mount(DeleteEndpoint)
		frame(wrapper).vm.$emit('confirm')
		await flushPromises()
		expect(endpointStore.deleteEndpoint).toHaveBeenCalledWith({ id: 4, name: 'Zaken API' })
		expect(navigationStore.setDialog).toHaveBeenCalledWith(false)
		expect(OCP.Toast.success).toHaveBeenCalledWith('Endpoint deleted successfully')
	})

	it('a failed delete stays open and toasts the error', async () => {
		endpointStore.deleteEndpoint.mockRejectedValue(new Error('in use'))
		const wrapper = mount(DeleteEndpoint)
		frame(wrapper).vm.$emit('confirm')
		await flushPromises()
		expect(OCP.Toast.error).toHaveBeenCalledWith('Error deleting endpoint: in use')
		expect(navigationStore.setDialog).not.toHaveBeenCalled()
	})
})

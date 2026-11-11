import { flushPromises, mount } from '@vue/test-utils'
import DeleteApplication from './DeleteApplication.vue'
import { applicationStore, navigationStore } from '../../store/store.js'

jest.mock('@nextcloud/l10n', () => ({
	__esModule: true,
	translate: (app, s, vars = {}) => s.replace(/{(\w+)}/g, (m, k) => vars[k] ?? m),
	translatePlural: (app, s, p, c) => (c === 1 ? s : p),
}))
jest.mock('../../store/store.js', () => ({
	__esModule: true,
	applicationStore: { applicationItem: null, deleteApplication: jest.fn() },
	navigationStore: { setDialog: jest.fn() },
}))
// The shared frame is presentational; a stand-in exposes its props and lets
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

describe('DeleteApplication', () => {
	beforeEach(() => {
		jest.clearAllMocks()
		applicationStore.applicationItem = { id: 3, name: 'Zaken' }
	})

	it('asks about the application by name', () => {
		const wrapper = mount(DeleteApplication)
		expect(frame(wrapper).vm.$attrs.message).toBe('Are you sure you want to delete the application "Zaken"? This action cannot be undone.')
		expect(frame(wrapper).vm.$attrs.name).toBe('Delete Application')
	})

	it('falls back to a generic name when the application has none', () => {
		applicationStore.applicationItem = null
		const wrapper = mount(DeleteApplication)
		expect(frame(wrapper).vm.$attrs.message).toContain('"this application"')
	})

	it('cancel closes the dialog without deleting', () => {
		const wrapper = mount(DeleteApplication)
		frame(wrapper).vm.$emit('cancel')
		expect(navigationStore.setDialog).toHaveBeenCalledWith(false)
		expect(applicationStore.deleteApplication).not.toHaveBeenCalled()
	})

	it('confirm deletes the active application and then closes', async () => {
		applicationStore.deleteApplication.mockResolvedValue({})
		const wrapper = mount(DeleteApplication)
		frame(wrapper).vm.$emit('confirm')
		await flushPromises()
		expect(applicationStore.deleteApplication).toHaveBeenCalledWith({ id: 3, name: 'Zaken' })
		expect(navigationStore.setDialog).toHaveBeenCalledWith(false)
	})

	it('keeps the dialog open and logs when the delete fails', async () => {
		const err = new Error('in use')
		applicationStore.deleteApplication.mockRejectedValue(err)
		const spy = jest.spyOn(console, 'error').mockImplementation(() => {})
		const wrapper = mount(DeleteApplication)
		frame(wrapper).vm.$emit('confirm')
		await flushPromises()
		expect(spy).toHaveBeenCalledWith('Error deleting application:', err)
		expect(navigationStore.setDialog).not.toHaveBeenCalled()
		spy.mockRestore()
	})
})

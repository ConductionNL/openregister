/**
 * The delete-source confirm dialog: it renders only while open, deletes a copy
 * of the selected source, shows the outcome and closes after success.
 *
 * @spec exclude Client-side delete-source dialog.
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
import { flushPromises, mount } from '@vue/test-utils'
import DeleteSource from './DeleteSource.vue'
import { navigationStore, sourceStore } from '../../store/store.js'

jest.mock('@nextcloud/l10n', () => ({ __esModule: true, translate: (app, s) => s }))
jest.mock('../../store/store.js', () => {
	const { reactive } = require('vue')
	return {
		__esModule: true,
		sourceStore: reactive({ sourceItem: null, deleteSource: jest.fn() }),
		navigationStore: reactive({ dialog: 'deleteSource', setDialog: jest.fn() }),
	}
})
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

describe('DeleteSource', () => {
	beforeEach(() => {
		jest.useFakeTimers()
		jest.clearAllMocks()
		navigationStore.dialog = 'deleteSource'
		sourceStore.sourceItem = { id: 7, title: 'Main DB' }
	})

	afterEach(() => {
		jest.useRealTimers()
	})

	it('renders only while its dialog is open', () => {
		navigationStore.dialog = 'deleteRegister'
		expect(mount(DeleteSource).find('.frame-stub').exists()).toBe(false)
	})

	it('asks to confirm the delete of the selected source', () => {
		const wrapper = mount(DeleteSource)
		expect(attrs(wrapper).showConfirm).toBe(true)
		expect(attrs(wrapper).closeLabel).toBe('Cancel')
		expect(attrs(wrapper).success).toBe(false)
		expect(wrapper.find('b').text()).toBe('Main DB')
	})

	it('confirm deletes a copy of the source, reports success and closes after two seconds', async () => {
		sourceStore.deleteSource.mockResolvedValue({ response: { ok: true } })
		const wrapper = mount(DeleteSource)
		frame(wrapper).vm.$emit('confirm')
		expect(wrapper.vm.loading).toBe(true)
		await flushPromises()
		expect(sourceStore.deleteSource).toHaveBeenCalledWith({ id: 7, title: 'Main DB' })
		expect(sourceStore.deleteSource.mock.calls[0][0]).not.toBe(sourceStore.sourceItem)
		expect(attrs(wrapper).success).toBe(true)
		expect(attrs(wrapper).showConfirm).toBe(false)
		expect(attrs(wrapper).closeLabel).toBe('Close')
		expect(attrs(wrapper).loading).toBe(false)
		expect(wrapper.find('p').exists()).toBe(false)
		expect(navigationStore.setDialog).not.toHaveBeenCalled()
		jest.advanceTimersByTime(2000)
		expect(navigationStore.setDialog).toHaveBeenCalledWith(false)
	})

	it('keeps the dialog open when the response is not ok', async () => {
		sourceStore.deleteSource.mockResolvedValue({ response: { ok: false } })
		const wrapper = mount(DeleteSource)
		frame(wrapper).vm.$emit('confirm')
		await flushPromises()
		expect(attrs(wrapper).success).toBe(false)
		jest.advanceTimersByTime(2000)
		expect(navigationStore.setDialog).not.toHaveBeenCalled()
	})

	it.each([
		[{ message: 'Source is in use' }, 'Source is in use'],
		[{}, 'An error occurred while deleting the source'],
	])('shows the failure message when the delete is rejected with %j', async (error, message) => {
		sourceStore.deleteSource.mockRejectedValue(error)
		const wrapper = mount(DeleteSource)
		frame(wrapper).vm.$emit('confirm')
		await flushPromises()
		expect(attrs(wrapper).error).toBe(message)
		expect(attrs(wrapper).success).toBe(false)
		expect(attrs(wrapper).loading).toBe(false)
	})

	it('cancel closes and clears an earlier failure', async () => {
		sourceStore.deleteSource.mockRejectedValue({ message: 'old' })
		const wrapper = mount(DeleteSource)
		frame(wrapper).vm.$emit('confirm')
		await flushPromises()
		expect(attrs(wrapper).error).toBe('old')
		frame(wrapper).vm.$emit('cancel')
		await flushPromises()
		expect(navigationStore.setDialog).toHaveBeenCalledWith(false)
		expect(attrs(wrapper).error).toBe(false)
		expect(attrs(wrapper).success).toBe(false)
	})
})

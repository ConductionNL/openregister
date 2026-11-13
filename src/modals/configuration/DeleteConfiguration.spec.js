/**
 * The delete-configuration dialog: when it shows, what it names, and what
 * confirming and cancelling do to the store and its own state.
 *
 * @spec exclude Client-side modal orchestration around configurationStore.
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
import { mount } from '@vue/test-utils'
import DeleteConfiguration from './DeleteConfiguration.vue'

jest.mock('vue-material-design-icons/Delete.vue', () => ({ name: 'Delete', render: () => null }))
jest.mock('../shared/ConfirmDialogFrame.vue', () => ({
	name: 'ConfirmDialogFrame',
	props: ['name', 'error', 'loading', 'closeLabel', 'confirmLabel', 'showConfirm'],
	emits: ['cancel', 'confirm'],
	render() {
		const { h } = require('vue')
		return h('x-frame', this.$slots.default?.())
	},
}))
jest.mock('../../store/store.js', () => ({
	configurationStore: { configurationItem: null, deleteConfiguration: jest.fn() },
	navigationStore: { dialog: null, setDialog: jest.fn() },
}))

const { configurationStore, navigationStore } = jest.requireMock('../../store/store.js')

/**
 * Mount the dialog.
 *
 * @return {object} The wrapper
 */
function dialog() {
	return mount(DeleteConfiguration, {
		global: { mocks: { t: (app, text) => text } },
	})
}

/**
 * The frame the dialog renders in.
 *
 * @param {object} wrapper The dialog
 * @return {object} The frame
 */
function frame(wrapper) {
	return wrapper.findComponent({ name: 'ConfirmDialogFrame' })
}

/**
 * Let pending promise callbacks run.
 *
 * @return {Promise<void>}
 */
function settle() {
	return new Promise((resolve) => setTimeout(resolve, 0))
}

beforeEach(() => {
	jest.clearAllMocks()
	navigationStore.dialog = 'deleteConfiguration'
	configurationStore.configurationItem = { id: 3, title: 'Base config' }
})

describe('DeleteConfiguration', () => {
	it('renders nothing when another dialog is open', () => {
		navigationStore.dialog = 'deleteRegister'

		expect(frame(dialog()).exists()).toBe(false)
	})

	it('names the configuration it is about to delete', () => {
		const wrapper = dialog()

		expect(frame(wrapper).props()).toMatchObject({
			name: 'Delete Configuration',
			confirmLabel: 'Delete',
			closeLabel: 'Cancel',
			error: null,
			loading: false,
		})
		expect(wrapper.text()).toContain(
			'Are you sure you want to delete the configuration "Base config"?',
		)
		expect(wrapper.text()).toContain('This action cannot be undone.')
	})

	it('deletes the selected configuration and closes on confirm', async () => {
		configurationStore.deleteConfiguration.mockResolvedValue(undefined)
		const wrapper = dialog()

		frame(wrapper).vm.$emit('confirm')
		await settle()

		expect(configurationStore.deleteConfiguration).toHaveBeenCalledWith({
			id: 3,
			title: 'Base config',
		})
		expect(navigationStore.setDialog).toHaveBeenCalledWith(false)
		expect(wrapper.vm.loading).toBe(false)
		expect(wrapper.vm.error).toBe(null)
	})

	it('stays open with the error when the delete fails', async () => {
		configurationStore.deleteConfiguration.mockRejectedValue(new Error('Locked'))
		const wrapper = dialog()

		frame(wrapper).vm.$emit('confirm')
		await settle()

		expect(navigationStore.setDialog).not.toHaveBeenCalled()
		expect(wrapper.vm.error).toBe('Locked')
		expect(wrapper.vm.loading).toBe(false)
		expect(frame(wrapper).props('error')).toBe('Locked')
	})

	it('falls back to a generic error without a message', async () => {
		configurationStore.deleteConfiguration.mockRejectedValue({})
		const wrapper = dialog()

		frame(wrapper).vm.$emit('confirm')
		await settle()

		expect(wrapper.vm.error).toBe('Failed to delete configuration')
	})

	it('closes and clears its state on cancel', async () => {
		const wrapper = dialog()
		await wrapper.setData({ error: 'old', loading: true })

		frame(wrapper).vm.$emit('cancel')

		expect(navigationStore.setDialog).toHaveBeenCalledWith(false)
		expect(configurationStore.deleteConfiguration).not.toHaveBeenCalled()
		expect(wrapper.vm.error).toBe(null)
		expect(wrapper.vm.loading).toBe(false)
	})
})

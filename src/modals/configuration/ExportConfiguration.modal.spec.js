/**
 * The rendered export-configuration modal: what it shows for the selected
 * configuration, when the export button is enabled, and what cancelling and
 * exporting do.
 *
 * @spec exclude Client-side modal markup and its export download.
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
import axios from '@nextcloud/axios'
import { mount } from '@vue/test-utils'
import ExportConfiguration from './ExportConfiguration.vue'

jest.mock('@nextcloud/axios', () => ({ __esModule: true, default: jest.fn() }))
jest.mock('@nextcloud/l10n', () => ({ translate: (app, text) => text }))
jest.mock('@nextcloud/router', () => ({ generateUrl: (path) => `/index.php${path}` }))
// The compiled component binds `@nextcloud/vue` and `vue` to one variable, so
// the library stand-ins have to be reachable through `vue` as well.
jest.mock('@nextcloud/vue', () => mockLibrary())
jest.mock('vue', () => ({ ...jest.requireActual('vue'), ...mockLibrary() }))
jest.mock('vue-material-design-icons/Export.vue', () => ({
	__esModule: true,
	default: { render: () => null },
}))
jest.mock('../../components/shared/BusyButton.vue', () => ({
	__esModule: true,
	default: mockButton('BusyButton', ['busy', 'disabled']),
}))
jest.mock('../../components/shared/CloseCancelButton.vue', () => ({
	__esModule: true,
	default: mockButton('CloseCancelButton', ['label']),
}))
jest.mock('../../store/store.js', () => ({
	configurationStore: { configurationItem: null },
	navigationStore: { modal: null, setModal: jest.fn() },
}))

const { configurationStore, navigationStore } = jest.requireMock('../../store/store.js')

/**
 * Stand-ins for the dialog, the note card and the switch.
 *
 * @return {object} The components
 */
function mockLibrary() {
	const { h } = jest.requireActual('vue')
	return {
		NcDialog: {
			name: 'NcDialog',
			props: ['name', 'title'],
			render() {
				return h('x-dialog', [this.$slots.default?.(), this.$slots.actions?.()])
			},
		},
		NcNoteCard: {
			name: 'NcNoteCard',
			props: ['type'],
			render() {
				return h('x-note', this.$slots.default?.())
			},
		},
		NcCheckboxRadioSwitch: {
			name: 'NcCheckboxRadioSwitch',
			props: ['modelValue'],
			emits: ['update:modelValue'],
			render() {
				return h('x-switch', this.$slots.default?.())
			},
		},
	}
}

/**
 * A stand-in button that clicks through to its listener.
 *
 * @param {string} name The component name
 * @param {Array<string>} props The props it takes
 * @return {object} The stub
 */
function mockButton(name, props) {
	const { h } = jest.requireActual('vue')
	return {
		name,
		props,
		emits: ['click'],
		render() {
			return h('button', { class: name, onClick: () => this.$emit('click') }, this.$slots.default?.())
		},
	}
}

/**
 * Mount the modal.
 *
 * @return {object} The wrapper
 */
function modal() {
	return mount(ExportConfiguration)
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
	navigationStore.modal = 'exportConfiguration'
	configurationStore.configurationItem = { id: 5, title: 'Base' }
	window.URL.createObjectURL = jest.fn(() => 'blob:x')
	window.URL.revokeObjectURL = jest.fn()
	jest.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(() => {})
})

describe('ExportConfiguration modal', () => {
	it('renders nothing when another modal is open', () => {
		navigationStore.modal = 'viewConfiguration'

		expect(modal().findComponent({ name: 'NcDialog' }).exists()).toBe(false)
	})

	it('asks to export the selected configuration', () => {
		const wrapper = modal()

		expect(wrapper.findComponent({ name: 'NcDialog' }).props('title')).toBe(
			'Export Configuration',
		)
		expect(wrapper.find('.formContainer p').text()).toBe('Export configuration "Base"?')
		expect(wrapper.findComponent({ name: 'NcNoteCard' }).exists()).toBe(false)
		expect(wrapper.findComponent({ name: 'BusyButton' }).props()).toEqual({
			busy: false,
			disabled: false,
		})
	})

	it('disables the export and explains why without a selected configuration', () => {
		configurationStore.configurationItem = { title: '' }
		const wrapper = modal()

		expect(wrapper.find('.formContainer p').exists()).toBe(false)
		expect(wrapper.findComponent({ name: 'NcNoteCard' }).text()).toBe(
			'No configuration selected for export',
		)
		expect(wrapper.findComponent({ name: 'BusyButton' }).props('disabled')).toBe(true)
	})

	it('tracks the include-objects switch', async () => {
		const wrapper = modal()

		wrapper.findComponent({ name: 'NcCheckboxRadioSwitch' }).vm.$emit('update:modelValue', true)
		await wrapper.vm.$nextTick()

		expect(wrapper.vm.includeObjects).toBe(true)
		expect(wrapper.findComponent({ name: 'NcCheckboxRadioSwitch' }).props('modelValue')).toBe(true)
	})

	it('exports with the switch setting, downloads and closes', async () => {
		axios.mockResolvedValue({
			data: '{}',
			headers: { 'content-disposition': 'attachment; filename="base.json"' },
		})
		const wrapper = modal()
		wrapper.findComponent({ name: 'NcCheckboxRadioSwitch' }).vm.$emit('update:modelValue', true)

		await wrapper.find('button.BusyButton').trigger('click')
		await settle()

		expect(axios).toHaveBeenCalledWith({
			url: '/index.php/apps/openregister/api/configurations/5/export',
			method: 'GET',
			params: { includeObjects: true },
			responseType: 'blob',
		})
		expect(window.URL.createObjectURL).toHaveBeenCalledTimes(1)
		expect(HTMLAnchorElement.prototype.click).toHaveBeenCalledTimes(1)
		expect(window.URL.revokeObjectURL).toHaveBeenCalledWith('blob:x')
		expect(navigationStore.setModal).toHaveBeenCalledWith(false)
		expect(wrapper.vm.includeObjects).toBe(false)
		expect(wrapper.vm.loading).toBe(false)
	})

	it('shows the server error when the export fails', async () => {
		axios.mockRejectedValue({ response: { data: { error: 'No access' } } })
		const wrapper = modal()

		await wrapper.find('button.BusyButton').trigger('click')
		await settle()

		expect(navigationStore.setModal).not.toHaveBeenCalled()
		expect(wrapper.findComponent({ name: 'NcNoteCard' }).text()).toBe('No access')
	})

	it('closes and resets its state on cancel', async () => {
		axios.mockRejectedValue(new Error('Timed out'))
		const wrapper = modal()
		wrapper.findComponent({ name: 'NcCheckboxRadioSwitch' }).vm.$emit('update:modelValue', true)
		await wrapper.find('button.BusyButton').trigger('click')
		await settle()
		expect(wrapper.vm.error).toBe('Timed out')
		axios.mockClear()

		await wrapper.find('button.CloseCancelButton').trigger('click')

		expect(navigationStore.setModal).toHaveBeenCalledWith(false)
		expect(axios).not.toHaveBeenCalled()
		expect(wrapper.vm.error).toBe(null)
		expect(wrapper.vm.includeObjects).toBe(false)
	})
})

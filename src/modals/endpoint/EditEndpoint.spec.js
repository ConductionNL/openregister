import { flushPromises, mount } from '@vue/test-utils'
import EditEndpoint from './EditEndpoint.vue'
import { endpointStore, navigationStore } from '../../store/store.js'

jest.mock('@nextcloud/l10n', () => ({ __esModule: true, translate: (app, s) => s }))
jest.mock('../../store/store.js', () => {
	const { reactive } = require('vue')
	return {
		__esModule: true,
		endpointStore: reactive({ endpointItem: {}, updateEndpoint: jest.fn(), createEndpoint: jest.fn() }),
		navigationStore: reactive({ modal: 'editEndpoint', setModal: jest.fn() }),
	}
})
jest.mock('vue-material-design-icons/ContentSaveOutline.vue', () => ({ __esModule: true, default: { template: '<i />' } }))
jest.mock('../../components/shared/CloseCancelButton.vue', () => ({
	__esModule: true,
	default: { name: 'CloseCancelButton', props: ['label'], emits: ['click'], template: '<button class="cancel" @click="$emit(\'click\')">{{ label }}</button>' },
}))
// vue3-jest merges this SFC's two <script> blocks so that both `vue` and
// `@nextcloud/vue` land on one `_vue` binding and the template reads the Nc*
// components off the `vue` module; the stand-ins are exposed on both.
jest.mock('@nextcloud/vue', () => ({ __esModule: true, ...mockNcStubs() }))
jest.mock('vue', () => ({ ...jest.requireActual('vue'), ...mockNcStubs() }))

/**
 * Stand-ins for the Nextcloud form components (hoisted for the factories).
 *
 * @return {object}
 */
function mockNcStubs() {
	const field = (name) => ({
		name,
		props: ['modelValue', 'label'],
		emits: ['update:modelValue'],
		template: '<input :aria-label="label" :value="modelValue" @input="$emit(\'update:modelValue\', $event.target.value)">',
	})
	return {
		NcDialog: { name: 'NcDialog', props: ['name'], emits: ['update:open'], template: '<section class="dialog"><h2>{{ name }}</h2><slot /></section>' },
		NcTextField: field('NcTextField'),
		NcTextArea: field('NcTextArea'),
		NcSelect: { name: 'NcSelect', props: ['modelValue', 'label', 'options'], emits: ['update:modelValue'], template: '<select :aria-label="label" />' },
		NcButton: { name: 'NcButton', props: ['disabled'], emits: ['click'], template: '<button class="save" :disabled="disabled" @click="$emit(\'click\')"><slot /></button>' },
	}
}

const COMPLETE = { name: 'Zaken', endpoint: '/api/zaken', method: 'GET', targetType: 'register' }

describe('EditEndpoint', () => {
	beforeEach(() => {
		jest.clearAllMocks()
		navigationStore.modal = 'editEndpoint'
		endpointStore.endpointItem = { ...COMPLETE }
		global.OCP = { Toast: { success: jest.fn(), error: jest.fn() } }
	})

	afterEach(() => {
		delete global.OCP
	})

	it('renders only while its modal is open', () => {
		navigationStore.modal = 'editSchema'
		expect(mount(EditEndpoint).find('.dialog').exists()).toBe(false)
	})

	it('titles a new endpoint Add and an existing one Edit', () => {
		expect(mount(EditEndpoint).find('h2').text()).toBe('Add Endpoint')
		endpointStore.endpointItem = { ...COMPLETE, id: 5 }
		expect(mount(EditEndpoint).find('h2').text()).toBe('Edit Endpoint')
	})

	it('offers the HTTP methods and target types', () => {
		const selects = mount(EditEndpoint).findAllComponents({ name: 'NcSelect' })
		expect(selects[0].props('options')).toEqual(['GET', 'POST', 'PUT', 'DELETE', 'PATCH', 'OPTIONS', 'HEAD'])
		expect(selects[1].props('options')).toEqual(['view', 'agent', 'webhook', 'register', 'schema'])
	})

	it('writes typed fields into the endpoint item', async () => {
		const wrapper = mount(EditEndpoint)
		await wrapper.find('input[aria-label="Name*"]').setValue('Renamed')
		expect(endpointStore.endpointItem.name).toBe('Renamed')
	})

	it.each(['name', 'endpoint', 'method', 'targetType'])('disables Save while %s is empty', (field) => {
		endpointStore.endpointItem = { ...COMPLETE, [field]: '' }
		expect(mount(EditEndpoint).find('button.save').attributes('disabled')).toBeDefined()
	})

	it('enables Save when every required field is filled', () => {
		expect(mount(EditEndpoint).find('button.save').attributes('disabled')).toBeUndefined()
	})

	it('creates a new endpoint, closes and toasts', async () => {
		endpointStore.createEndpoint.mockResolvedValue({})
		const wrapper = mount(EditEndpoint)
		await wrapper.find('button.save').trigger('click')
		await flushPromises()
		expect(endpointStore.createEndpoint).toHaveBeenCalledWith(COMPLETE)
		expect(endpointStore.updateEndpoint).not.toHaveBeenCalled()
		expect(navigationStore.setModal).toHaveBeenCalledWith(false)
		expect(OCP.Toast.success).toHaveBeenCalledWith('Endpoint created successfully')
	})

	it('updates an existing endpoint, closes and toasts', async () => {
		endpointStore.endpointItem = { ...COMPLETE, id: 5 }
		endpointStore.updateEndpoint.mockResolvedValue({})
		const wrapper = mount(EditEndpoint)
		await wrapper.find('button.save').trigger('click')
		await flushPromises()
		expect(endpointStore.updateEndpoint).toHaveBeenCalledWith({ ...COMPLETE, id: 5 })
		expect(endpointStore.createEndpoint).not.toHaveBeenCalled()
		expect(OCP.Toast.success).toHaveBeenCalledWith('Endpoint updated successfully')
	})

	it.each([
		[undefined, 'createEndpoint', 'Error creating endpoint: dup'],
		[5, 'updateEndpoint', 'Error updating endpoint: dup'],
	])('keeps the modal open and toasts a failed save (id %s)', async (id, action, message) => {
		endpointStore.endpointItem = { ...COMPLETE, id }
		endpointStore[action].mockRejectedValue(new Error('dup'))
		const wrapper = mount(EditEndpoint)
		await wrapper.find('button.save').trigger('click')
		await flushPromises()
		expect(OCP.Toast.error).toHaveBeenCalledWith(message)
		expect(navigationStore.setModal).not.toHaveBeenCalled()
	})

	it('cancel and the dialog close both close the modal', async () => {
		const wrapper = mount(EditEndpoint)
		await wrapper.find('button.cancel').trigger('click')
		expect(navigationStore.setModal).toHaveBeenCalledWith(false)
		navigationStore.setModal.mockClear()
		wrapper.findComponent({ name: 'NcDialog' }).vm.$emit('update:open', false)
		expect(navigationStore.setModal).toHaveBeenCalledWith(false)
	})
})

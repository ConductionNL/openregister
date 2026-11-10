/**
 * EndpointDetails: shows the selected endpoint and wires edit, test and delete.
 *
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
import { flushPromises, mount } from '@vue/test-utils'
import EndpointDetails from './EndpointDetails.vue'
import { endpointStore, navigationStore } from '../../store/store.js'

jest.mock('@nextcloud/l10n', () => ({ translate: (app, text) => text }))
jest.mock('../../store/store.js', () => {
	const { reactive } = require('vue')
	return {
		endpointStore: reactive({ endpointItem: {}, testEndpoint: jest.fn() }),
		navigationStore: { setModal: jest.fn(), setDialog: jest.fn() },
	}
})
jest.mock('vue-material-design-icons/DotsHorizontal.vue', () => ({ __esModule: true, default: { name: 'DotsHorizontal', render: () => null } }))
jest.mock('vue-material-design-icons/Pencil.vue', () => ({ __esModule: true, default: { name: 'Pencil', render: () => null } }))
jest.mock('vue-material-design-icons/PlayCircle.vue', () => ({ __esModule: true, default: { name: 'PlayCircle', render: () => null } }))
jest.mock('vue-material-design-icons/TrashCanOutline.vue', () => ({ __esModule: true, default: { name: 'TrashCanOutline', render: () => null } }))
jest.mock('@nextcloud/vue', () => ({ __esModule: true, NcActions: mockStub('NcActions'), NcActionButton: mockStub('NcActionButton') }))
// The page mixes <script setup> and an options block; its compiled template
// reads the library components through 'vue'.
jest.mock('vue', () => ({ ...jest.requireActual('vue'), NcActions: mockStub('NcActions'), NcActionButton: mockStub('NcActionButton') }))
jest.mock('@conduction/nextcloud-vue', () => mockLibrary())

/**
 * A stand-in component that renders its attributes and every slot.
 *
 * @param {string} name The component name.
 * @return {object} The component.
 */
function mockStub(name) {
	const { h } = jest.requireActual('vue')
	return {
		name,
		inheritAttrs: false,
		render() {
			const slots = Object.values(this.$slots).map((s) => s())
			return h('div', { ...this.$attrs, class: [`stub-${name}`, this.$attrs.class] }, slots)
		},
	}
}

/**
 * A module whose every named export is a stand-in component.
 *
 * @return {object} The module.
 */
function mockLibrary() {
	const made = {}
	return new Proxy({ __esModule: true }, {
		get(target, key) {
			if (key in target || typeof key !== 'string') return target[key]
			made[key] = made[key] || mockStub(key)
			return made[key]
		},
		has: () => true,
	})
}

const ITEM = {
	name: 'Search API', description: 'Finds things', uuid: 'u-1', endpoint: '/api/search',
	method: 'GET', targetType: 'register', targetId: '', version: '1.2.0', groups: ['admin', 'staff'],
}

describe('EndpointDetails', () => {
	beforeEach(() => {
		jest.clearAllMocks()
		endpointStore.endpointItem = { ...ITEM }
		global.OCP = { Toast: { success: jest.fn(), error: jest.fn() } }
	})
	afterEach(() => delete global.OCP)

	it('shows the endpoint fields, N/A for a missing target and the allowed groups', () => {
		const wrapper = mount(EndpointDetails)
		expect(wrapper.find('h1').text()).toBe('Search API')
		const text = wrapper.text()
		expect(text).toContain('Finds things')
		expect(text).toContain('/api/search')
		expect(text).toContain('N/A')
		expect(text).toContain('Allowed Groups:')
		expect(text).toContain('admin, staff')
	})

	it('hides the groups row without groups and shows a set target', () => {
		endpointStore.endpointItem = { ...ITEM, groups: [], targetId: '42' }
		const wrapper = mount(EndpointDetails)
		expect(wrapper.text()).not.toContain('Allowed Groups:')
		expect(wrapper.text()).toContain('42')
		expect(wrapper.text()).not.toContain('N/A')
	})

	it('opens the edit modal and the delete dialog', async () => {
		const wrapper = mount(EndpointDetails)
		const buttons = wrapper.findAll('.stub-NcActionButton')
		await buttons[0].trigger('click')
		expect(navigationStore.setModal).toHaveBeenCalledWith('editEndpoint')
		await buttons[2].trigger('click')
		expect(navigationStore.setDialog).toHaveBeenCalledWith('deleteEndpoint')
	})

	it('tests the endpoint and toasts success', async () => {
		endpointStore.testEndpoint.mockResolvedValue({ success: true })
		const wrapper = mount(EndpointDetails)
		await wrapper.findAll('.stub-NcActionButton')[1].trigger('click')
		await flushPromises()
		expect(endpointStore.testEndpoint).toHaveBeenCalledWith(endpointStore.endpointItem)
		expect(OCP.Toast.success).toHaveBeenCalledWith('Endpoint tested successfully')
		expect(OCP.Toast.error).not.toHaveBeenCalled()
	})

	it('toasts the error, else the message, of a failed test', async () => {
		const wrapper = mount(EndpointDetails)
		endpointStore.testEndpoint.mockResolvedValueOnce({ success: false, error: 'Timeout' })
		wrapper.vm.testEndpoint()
		await flushPromises()
		expect(OCP.Toast.error).toHaveBeenLastCalledWith('Endpoint test failed: Timeout')
		endpointStore.testEndpoint.mockResolvedValueOnce({ success: false, message: 'No target' })
		wrapper.vm.testEndpoint()
		await flushPromises()
		expect(OCP.Toast.error).toHaveBeenLastCalledWith('Endpoint test failed: No target')
	})

	it('toasts a rejected test', async () => {
		endpointStore.testEndpoint.mockRejectedValue(new Error('500'))
		const wrapper = mount(EndpointDetails)
		wrapper.vm.testEndpoint()
		await flushPromises()
		expect(OCP.Toast.error).toHaveBeenCalledWith('Error testing endpoint: 500')
	})
})

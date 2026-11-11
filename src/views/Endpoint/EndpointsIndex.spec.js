/**
 * EndpointsIndex: the empty state until an endpoint is selected, and the add action.
 *
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
import { mount } from '@vue/test-utils'
import EndpointsIndex from './EndpointsIndex.vue'
import { endpointStore, navigationStore } from '../../store/store.js'

jest.mock('../../store/store.js', () => {
	const { reactive } = require('vue')
	return {
		endpointStore: reactive({ endpointItem: null, setEndpointItem: jest.fn() }),
		navigationStore: reactive({ selected: 'endpoints', setModal: jest.fn() }),
	}
})
jest.mock('./EndpointsList.vue', () => ({ __esModule: true, default: { name: 'EndpointsList', render: () => null } }))
jest.mock('./EndpointDetails.vue', () => {
	const { h } = require('vue')
	return { __esModule: true, default: { name: 'EndpointDetails', render: () => h('div', { class: 'details' }) } }
})
jest.mock('vue-material-design-icons/Api.vue', () => ({ __esModule: true, default: { name: 'Api', render: () => null } }))
jest.mock('@nextcloud/vue', () => ({ __esModule: true, NcAppContent: mockStub('NcAppContent'), NcEmptyContent: mockStub('NcEmptyContent'), NcButton: mockStub('NcButton') }))
// The page mixes <script setup> and an options block; its compiled template
// reads the library components through 'vue'.
jest.mock('vue', () => ({ ...jest.requireActual('vue'), NcAppContent: mockStub('NcAppContent'), NcEmptyContent: mockStub('NcEmptyContent'), NcButton: mockStub('NcButton') }))
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

describe('EndpointsIndex', () => {
	beforeEach(() => {
		jest.clearAllMocks()
		endpointStore.endpointItem = null
		navigationStore.selected = 'endpoints'
	})

	it('shows the empty state without a selected endpoint', () => {
		const wrapper = mount(EndpointsIndex)
		expect(wrapper.find('.stub-NcEmptyContent').exists()).toBe(true)
		expect(wrapper.find('.details').exists()).toBe(false)
	})

	it('shows the details for a selected endpoint on the endpoints page', () => {
		endpointStore.endpointItem = { id: 1 }
		const wrapper = mount(EndpointsIndex)
		expect(wrapper.find('.stub-NcEmptyContent').exists()).toBe(false)
		expect(wrapper.find('.details').exists()).toBe(true)
	})

	it('shows the empty state on another page even with an endpoint', () => {
		endpointStore.endpointItem = { id: 1 }
		navigationStore.selected = 'registers'
		const wrapper = mount(EndpointsIndex)
		expect(wrapper.find('.stub-NcEmptyContent').exists()).toBe(true)
		expect(wrapper.find('.details').exists()).toBe(false)
	})

	it('add starts a blank endpoint and opens the edit modal', async () => {
		const wrapper = mount(EndpointsIndex)
		await wrapper.find('.stub-NcButton').trigger('click')
		expect(endpointStore.setEndpointItem).toHaveBeenCalledWith({})
		expect(navigationStore.setModal).toHaveBeenCalledWith('editEndpoint')
	})
})

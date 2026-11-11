/**
 * IntegrationsView: waits for providers and all three route params before rendering the widget.
 *
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
import { mount } from '@vue/test-utils'
import { ref } from 'vue'
import IntegrationsView from './IntegrationsView.vue'

const mockIntegrations = ref([])
jest.mock('@nextcloud/vue', () => mockLibrary())
jest.mock('@conduction/nextcloud-vue', () => {
	const lib = mockLibrary()
	return new Proxy({}, {
		get(target, key) {
			if (key === 'useIntegrationRegistry') return () => ({ integrations: mockIntegrations })
			return lib[key]
		},
		has: () => true,
	})
})

/**
 * A stand-in component that renders its attributes and every slot.
 *
 * @param {string} name The component name.
 * @return {object} The component.
 */
function mockStub(name) {
	const { h } = require('vue')
	return {
		name,
		inheritAttrs: false,
		render() {
			const slots = Object.values(this.$slots).map((s) => s())
			return h('div', { class: `stub-${name}`, ...this.$attrs }, slots)
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

/**
 * Mount with route params.
 *
 * @param {object} params The route params.
 * @return {object} The wrapper.
 */
function mountWith(params) {
	return mount(IntegrationsView, {
		global: { mocks: { $route: { params }, t: (app, text) => text } },
	})
}

describe('IntegrationsView', () => {
	beforeEach(() => { mockIntegrations.value = [] })

	it('waits for the registry while there are no providers', () => {
		const wrapper = mountWith({ register: 'r', schema: 's', objectId: 'o' })
		expect(wrapper.find('.integrations-view__empty').text()).toContain('Waiting for the integration registry')
		expect(wrapper.find('.stub-CnIntegrationWidget').exists()).toBe(false)
	})

	it('asks for all three params when one is missing', () => {
		mockIntegrations.value = [{ id: 'mail' }]
		const wrapper = mountWith({ register: 'r', schema: 's' })
		expect(wrapper.find('.integrations-view__empty').text()).toContain('Provide all three params')
	})

	it('renders the widget for the object with the provider count', () => {
		mockIntegrations.value = [{ id: 'mail' }, { id: 'talk' }]
		const wrapper = mountWith({ register: 5, schema: 6, objectId: 'abc' })
		const widget = wrapper.find('.stub-CnIntegrationWidget')
		expect(widget.attributes('register')).toBe('5')
		expect(widget.attributes('schema')).toBe('6')
		expect(widget.attributes('objectid')).toBe('abc')
		expect(widget.attributes('surface')).toBe('detail-page')
		expect(wrapper.find('.integrations-view__subtitle').text()).toContain('2 providers')
	})

	it('treats a null registry list as empty', () => {
		mockIntegrations.value = null
		const wrapper = mountWith({})
		expect(wrapper.vm.providers).toEqual([])
		expect(wrapper.vm.register).toBe('')
	})
})

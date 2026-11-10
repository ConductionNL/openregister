/**
 * FeaturesRoadmapIndex: passes the initial-state repo, features and disabled flag to the roadmap view.
 *
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
import { loadState } from '@nextcloud/initial-state'
import { mount } from '@vue/test-utils'
import FeaturesRoadmapIndex from './FeaturesRoadmapIndex.vue'

jest.mock('@nextcloud/initial-state', () => ({ loadState: jest.fn((app, key, fallback) => fallback) }))
jest.mock('@nextcloud/vue', () => mockLibrary())
jest.mock('@conduction/nextcloud-vue', () => mockLibrary())

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

describe('FeaturesRoadmapIndex', () => {
	it('falls back to the openregister repo, no features and enabled', () => {
		const view = mount(FeaturesRoadmapIndex).findComponent({ name: 'CnFeaturesAndRoadmapView' })
		expect(view.vm.$attrs.repo).toBe('ConductionNL/openregister')
		expect(view.vm.$attrs.features).toEqual([])
		expect(view.vm.$attrs.disabled).toBe(false)
		expect(loadState).toHaveBeenCalledWith('openregister', 'features_roadmap_repo', 'ConductionNL/openregister')
	})

	it('passes the initial state it was given', () => {
		const state = { features_roadmap_repo: 'acme/x', features_roadmap_features: [{ id: 'f' }], features_roadmap_disabled: true }
		loadState.mockImplementation((app, key) => state[key])
		const view = mount(FeaturesRoadmapIndex).findComponent({ name: 'CnFeaturesAndRoadmapView' })
		expect(view.vm.$attrs.repo).toBe('acme/x')
		expect(view.vm.$attrs.features).toEqual([{ id: 'f' }])
		expect(view.vm.$attrs.disabled).toBe(true)
	})
})

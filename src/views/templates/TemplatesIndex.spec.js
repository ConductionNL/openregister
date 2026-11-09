/**
 * TemplatesIndex: the sidebar toggle, the refresh action and the row menu.
 *
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
import { flushPromises, mount } from '@vue/test-utils'
import TemplatesIndex from './TemplatesIndex.vue'

jest.mock('@nextcloud/l10n', () => ({ __esModule: true, t: (app, text) => text }))
jest.mock('vue-material-design-icons/EyeOutline.vue', () => ({ __esModule: true, default: { name: 'EyeOutline', render: () => null } }))
jest.mock('vue-material-design-icons/FileOutline.vue', () => ({ __esModule: true, default: { render: () => null } }))
jest.mock('../../services/menuActions.js', () => ({ refreshAction: (run) => ({ label: 'Refresh', run }) }))
jest.mock('../../components/shared/FilterToggleHeader.vue', () => ({
	__esModule: true,
	default: { name: 'FilterToggleHeader', props: ['sidebarOpen'], emits: ['toggle'], render: () => null },
}))
jest.mock('../../components/shared/ListActionsBar.vue', () => ({
	__esModule: true,
	default: { name: 'ListActionsBar', props: ['actions', 'summary'], render: () => null },
}))
jest.mock('../../components/shared/ActionMenu.vue', () => ({ __esModule: true, default: { render: () => null } }))
jest.mock('../../components/shared/OffsetPager.vue', () => ({ __esModule: true, default: { render: () => null } }))
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
			const slots = Object.values(this.$slots).map((s) => s({}))
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

describe('TemplatesIndex behaviour', () => {
	it('toggles the sidebar from the header and from the app content', async () => {
		const wrapper = mount(TemplatesIndex)
		await flushPromises()
		const header = wrapper.findComponent({ name: 'FilterToggleHeader' })
		const before = header.props('sidebarOpen')
		header.vm.$emit('toggle')
		await wrapper.vm.$nextTick()
		expect(header.props('sidebarOpen')).toBe(!before)
		wrapper.findComponent({ name: 'NcAppContent' }).vm.$emit('update:showDetails')
		await wrapper.vm.$nextTick()
		expect(header.props('sidebarOpen')).toBe(before)
	})

	it('the refresh action reloads the list, which is empty until the API exists', async () => {
		const wrapper = mount(TemplatesIndex)
		await flushPromises()
		await wrapper.setData({ templatesList: [{ id: 1, name: 'Letter' }], totalTemplates: 1, loading: false })
		const [refresh] = wrapper.findComponent({ name: 'ListActionsBar' }).props('actions')
		expect(refresh.label).toBe('Refresh')
		refresh.run()
		await flushPromises()
		expect(wrapper.vm.templatesList).toEqual([])
		expect(wrapper.vm.totalTemplates).toBe(0)
		expect(wrapper.vm.loading).toBe(false)
	})

	it('offers a View Details entry per row that opens the template', async () => {
		const view = jest.spyOn(TemplatesIndex.methods, 'viewTemplate')
		const wrapper = mount(TemplatesIndex)
		await flushPromises()
		const [entry] = wrapper.vm.templateActions({ id: 7 })
		expect(entry.label).toBe('View Details')
		expect(entry.icon.name).toBe('EyeOutline')
		entry.run()
		expect(view).toHaveBeenCalledWith({ id: 7 })
		view.mockRestore()
	})
})

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The settings card and its optional collapsing.
 *
 * @spec exclude Unit test of the client-side settings card.
 */
import { mount } from '@vue/test-utils'
import SettingsCard from './SettingsCard.vue'

jest.mock('vue-material-design-icons/ChevronDown.vue', () => ({
	name: 'ChevronDown',
	template: '<i class="down" />',
}))
jest.mock('vue-material-design-icons/ChevronUp.vue', () => ({
	name: 'ChevronUp',
	template: '<i class="up" />',
}))

describe('SettingsCard', () => {
	it('shows its title and content, and does not collapse by default', async () => {
		const wrapper = mount(SettingsCard, {
			props: { title: 'Cache', icon: '🗄' },
			slots: { default: 'body' },
		})
		expect(wrapper.find('h4').text()).toBe('🗄 Cache')
		expect(wrapper.find('.card-content').text()).toBe('body')
		await wrapper.find('h4').trigger('click')
		wrapper.vm.toggleCollapsed()
		expect(wrapper.emitted('toggle')).toBeUndefined()
		expect(wrapper.find('.down').exists()).toBe(false)
	})

	it('has no header without a title', () => {
		const wrapper = mount(SettingsCard, { slots: { default: 'body' } })
		expect(wrapper.find('h4').exists()).toBe(false)
	})

	it('collapses and expands on a header click when collapsible', async () => {
		const wrapper = mount(SettingsCard, {
			props: { title: 'Cache', collapsible: true },
			slots: { default: 'body' },
		})
		const content = wrapper.find('.collapsible-content')
		expect(content.isVisible()).toBe(true)
		expect(wrapper.find('.down').exists()).toBe(true)
		await wrapper.find('h4').trigger('click')
		expect(wrapper.emitted('toggle')).toEqual([[true]])
		expect(content.element.style.display).toBe('none')
		expect(wrapper.find('.up').exists()).toBe(true)
		await wrapper.find('h4').trigger('click')
		expect(wrapper.emitted('toggle')).toEqual([[true], [false]])
	})

	it('can start collapsed', () => {
		const wrapper = mount(SettingsCard, {
			props: { title: 'Cache', collapsible: true, defaultCollapsed: true },
		})
		expect(wrapper.find('.up').exists()).toBe(true)
		expect(wrapper.find('.collapsible-content').element.style.display).toBe('none')
	})
})

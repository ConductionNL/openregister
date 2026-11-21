/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The Settings tab registers with the strip like a plain AppTab.
 *
 * @spec exclude Unit test of a presentational tab wrapper.
 */
import { mount } from '@vue/test-utils'
import { h } from 'vue'
import AppTab from './AppTab.vue'
import AppTabs from './AppTabs.vue'
import SettingsTab from './SettingsTab.vue'

afterEach(() => {
	document.body.innerHTML = ''
})

describe('SettingsTab', () => {
	it('opens the strip as the active "Settings" tab and shows its content', async () => {
		const wrapper = mount(AppTabs, {
			attachTo: document.body,
			slots: {
				default: () => [
					h(AppTab, { title: 'Other' }, () => 'other panel'),
					h(SettingsTab, null, () => h('div', { class: 'form-editor' }, 'settings panel')),
				],
			},
		})
		await wrapper.vm.$nextTick()

		const tabs = wrapper.findAll('[role="tab"]')
		expect(tabs.map((tab) => tab.text())).toEqual(['Other', 'Settings'])
		expect(tabs[1].attributes('aria-selected')).toBe('true')
		expect(wrapper.find('[role="tabpanel"]').text()).toBe('settings panel')
		expect(wrapper.find('.form-editor').exists()).toBe(true)
	})
})

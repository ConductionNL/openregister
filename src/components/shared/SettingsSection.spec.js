/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The settings section wrapper: its loading, error and empty states and the
 * sanitising of its detailed description.
 *
 * @spec exclude Unit test of the client-side settings section wrapper.
 */
import { mount } from '@vue/test-utils'
import SettingsSection from './SettingsSection.vue'

jest.mock('@nextcloud/vue', () => ({
	NcSettingsSection: {
		name: 'NcSettingsSection',
		props: ['name', 'description', 'docUrl'],
		template: '<section><slot /></section>',
	},
	NcLoadingIcon: { name: 'NcLoadingIcon', render: () => null },
	NcButton: {
		name: 'NcButton',
		emits: ['click'],
		template: '<button class="retry" @click="$emit(\'click\')"><slot /></button>',
	},
}))
jest.mock('vue-material-design-icons/Refresh.vue', () => ({ name: 'Refresh', render: () => null }))
jest.mock('vue-material-design-icons/InformationOutline.vue', () => ({
	name: 'InformationOutline',
	render: () => null,
}))

/**
 * Mount the section.
 *
 * @param {object} props Extra props
 * @param {object} slots Slots
 * @return {object} The wrapper
 */
function mountSection(props = {}, slots = {}) {
	return mount(SettingsSection, { props: { name: 'Cache', ...props }, slots })
}

describe('SettingsSection', () => {
	it('passes its name, description, doc link and attributes to the section', () => {
		const wrapper = mount(SettingsSection, {
			props: { name: 'Cache', description: 'd', docUrl: 'https://docs' },
			attrs: { id: 'cache' },
			slots: { default: 'body' },
		})
		const section = wrapper.findComponent({ name: 'NcSettingsSection' })
		expect(section.props()).toEqual({ name: 'Cache', description: 'd', docUrl: 'https://docs' })
		expect(section.attributes('id')).toBe('cache')
		expect(wrapper.find('.section-content').text()).toBe('body')
		expect(wrapper.find('.action-buttons').exists()).toBe(false)
		expect(wrapper.find('.section-footer').exists()).toBe(false)
	})

	it('renders the action and footer slots', () => {
		const wrapper = mountSection({}, { actions: 'act', footer: 'foot' })
		expect(wrapper.find('.action-buttons').text()).toBe('act')
		expect(wrapper.find('.section-footer').text()).toBe('foot')
	})

	it('keeps only the allowed markup of the detailed description', () => {
		const wrapper = mountSection({
			detailedDescription:
				'<strong>Bold</strong><script>alert(1)</script><a href="/x" onclick="x()">l</a><img src=x>',
		})
		const html = wrapper.find('.main-description').html()
		expect(html).toContain('<strong>Bold</strong>')
		expect(html).toContain('<a href="/x">l</a>')
		expect(html).not.toContain('<script')
		expect(html).not.toContain('alert')
		expect(html).not.toContain('onclick')
		expect(html).not.toContain('img')
	})

	it('shows the loading message and hides error and empty while loading', () => {
		const wrapper = mountSection({ loading: true, error: true, empty: true })
		expect(wrapper.find('.loading-section').text()).toBe('Loading...')
		expect(wrapper.find('.error-section').exists()).toBe(false)
		expect(wrapper.find('.empty-section').exists()).toBe(false)
	})

	it('shows the error with a retry that calls back', async () => {
		const onRetry = jest.fn()
		const wrapper = mountSection({ error: true, errorMessage: 'Boom', onRetry, empty: true })
		expect(wrapper.find('.error-message').text()).toBe('❌ Boom')
		expect(wrapper.find('.empty-section').exists()).toBe(false)
		await wrapper.find('.retry').trigger('click')
		expect(onRetry).toHaveBeenCalledTimes(1)
	})

	it('shows the error without a retry when there is no callback', () => {
		const wrapper = mountSection({ error: true })
		expect(wrapper.find('.error-message').text()).toBe('❌ An error occurred')
		expect(wrapper.find('.retry').exists()).toBe(false)
	})

	it('shows the empty message, or the empty slot', () => {
		expect(mountSection({ empty: true }).find('.empty-content').text()).toBe(
			'No data available',
		)
		const slotted = mountSection({ empty: true }, { empty: 'Nothing here' })
		expect(slotted.find('.empty-section').text()).toBe('Nothing here')
	})
})

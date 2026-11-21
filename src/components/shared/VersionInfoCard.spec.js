/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The version card: the facts it lists and its update button.
 *
 * @spec exclude Unit test of the client-side version card.
 */
import { mount } from '@vue/test-utils'
import VersionInfoCard from './VersionInfoCard.vue'

jest.mock('@nextcloud/vue', () => ({
	NcSettingsSection: {
		name: 'NcSettingsSection',
		props: ['name', 'description', 'docUrl'],
		template: '<section><h2>{{ name }}</h2><slot /></section>',
	},
	NcLoadingIcon: { name: 'NcLoadingIcon', template: '<i class="spinner" />' },
	NcButton: {
		name: 'NcButton',
		props: ['variant', 'disabled'],
		emits: ['click'],
		template: '<button class="nc-button" :data-variant="variant" :disabled="disabled" @click="$emit(\'click\')"><slot name="icon" /><slot /></button>',
	},
}))
jest.mock('vue-material-design-icons/Check.vue', () => ({
	name: 'CheckIcon',
	template: '<i class="icon-Check" />',
}))
jest.mock('vue-material-design-icons/Update.vue', () => ({
	name: 'UpdateIcon',
	template: '<i class="icon-Update" />',
}))
jest.mock('vue-material-design-icons/Refresh.vue', () => ({
	name: 'RefreshIcon',
	template: '<i class="icon-Refresh" />',
}))
jest.mock('vue-material-design-icons/InformationOutline.vue', () => ({
	name: 'InformationOutlineIcon',
	template: '<i class="icon-InformationOutline" />',
}))

/**
 * Mount the card.
 *
 * @param {object} props Extra props
 * @return {object} The wrapper
 */
function mountCard(props = {}) {
	return mount(VersionInfoCard, {
		props: { appName: 'OpenRegister', appVersion: '1.2.3', ...props },
	})
}

/**
 * The texts of the version items.
 *
 * @param {object} wrapper The wrapper
 * @return {Array<string>} The texts, whitespace collapsed
 */
function items(wrapper) {
	return wrapper.findAll('.version-item').map((i) => i.text().replace(/\s+/g, ' '))
}

describe('VersionInfoCard', () => {
	it('lists the name and version under the default labels', () => {
		const wrapper = mountCard()
		expect(wrapper.find('h2').text()).toBe('Version Information')
		expect(wrapper.find('h4').text()).toBe('📦 Application Information')
		expect(items(wrapper)).toEqual([
			'Application Name:OpenRegister',
			'Version:1.2.3',
		])
		expect(wrapper.find('.nc-button').exists()).toBe(false)
	})

	it('adds the configured version and extra items under custom labels', () => {
		const wrapper = mountCard({
			configuredVersion: '1.2.0',
			labels: { appName: 'App', version: 'V', configuredVersion: 'Conf' },
			additionalItems: [{ label: 'Solr', value: 'down', statusClass: 'bad' }],
		})
		expect(items(wrapper)).toEqual(['App:OpenRegister', 'V:1.2.3', 'Conf:1.2.0', 'Solr:down'])
		expect(wrapper.find('.bad').text()).toBe('down')
	})

	it('hides the facts and shows the loading message while loading', () => {
		const wrapper = mountCard({ loading: true })
		expect(wrapper.find('.version-info').exists()).toBe(false)
		expect(wrapper.find('.loading-section').text()).toBe(
			'Loading version information...',
		)
	})

	it('offers an update when the app is behind, and emits it', async () => {
		const wrapper = mountCard({ showUpdateButton: true, isUpToDate: false })
		const button = wrapper.find('.nc-button')
		expect(button.text()).toBe('Update')
		expect(button.attributes('data-variant')).toBe('error')
		expect(button.find('.icon-Update').exists()).toBe(true)
		await button.trigger('click')
		expect(wrapper.emitted('update')).toHaveLength(1)
	})

	it('shows up to date as a disabled success, and emits nothing', () => {
		const wrapper = mountCard({ showUpdateButton: true })
		const button = wrapper.find('.nc-button')
		expect(button.text()).toBe('Up to date')
		expect(button.attributes('data-variant')).toBe('success')
		expect(button.attributes('disabled')).toBeDefined()
		expect(button.find('.icon-Check').exists()).toBe(true)
		wrapper.vm.handleUpdateClick()
		expect(wrapper.emitted('update')).toBeUndefined()
	})

	it('does not emit again while updating', () => {
		const wrapper = mountCard({ showUpdateButton: true, isUpToDate: false, updating: true })
		const button = wrapper.find('.nc-button')
		expect(button.text()).toBe('Updating...')
		expect(button.find('.spinner').exists()).toBe(true)
		wrapper.vm.handleUpdateClick()
		expect(wrapper.emitted('update')).toBeUndefined()
	})
})

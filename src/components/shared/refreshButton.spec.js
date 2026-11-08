/**
 * RefreshButton renders the reload button AvgIndex and ReportsIndex wrote
 * inline: spinner and disabled while loading, "Refresh" unless relabelled.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec exclude Unit test of a presentational reload button.
 */
import { mount } from '@vue/test-utils'
import RefreshButton from './RefreshButton.vue'

jest.mock('@nextcloud/vue', () => ({
	NcButton: {
		name: 'NcButton',
		props: ['variant', 'disabled'],
		emits: ['click'],
		template:
			'<button :data-variant="variant" :disabled="disabled" @click="$emit(\'click\')"><slot name="icon" /><slot /></button>',
	},
	NcLoadingIcon: { name: 'NcLoadingIcon', template: '<i class="spinner" />' },
}))
jest.mock('vue-material-design-icons/Refresh.vue', () => ({
	name: 'Refresh',
	template: '<i class="refresh" />',
}))

describe('RefreshButton', () => {
	it('is a tertiary "Refresh" button with the refresh icon when idle', () => {
		const w = mount(RefreshButton)
		const button = w.find('button')
		expect(button.attributes('data-variant')).toBe('tertiary')
		expect(button.attributes('disabled')).toBeUndefined()
		expect(w.find('.refresh').exists()).toBe(true)
		expect(button.text()).toBe('Refresh')
	})

	it('spins and disables while loading', () => {
		const w = mount(RefreshButton, { props: { loading: true } })
		expect(w.find('button').attributes('disabled')).toBeDefined()
		expect(w.find('.spinner').exists()).toBe(true)
		expect(w.find('.refresh').exists()).toBe(false)
	})

	it('takes a variant and a label, and emits click', async () => {
		const w = mount(RefreshButton, {
			props: { variant: 'primary' },
			slots: { default: 'Run compliance scan' },
		})
		expect(w.find('button').attributes('data-variant')).toBe('primary')
		expect(w.find('button').text()).toBe('Run compliance scan')
		await w.find('button').trigger('click')
		expect(w.emitted('click')).toHaveLength(1)
	})
})

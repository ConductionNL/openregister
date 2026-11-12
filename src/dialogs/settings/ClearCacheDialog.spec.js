/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The clear-cache dialog: the cache type it reports and its buttons.
 *
 * @spec exclude Unit test of the client-side clear-cache dialog.
 */
import { mount } from '@vue/test-utils'
import ClearCacheDialog from './ClearCacheDialog.vue'

jest.mock('@nextcloud/vue', () => ({
	NcDialog: {
		name: 'NcDialog',
		props: ['open', 'name', 'noClose'],
		emits: ['closing'],
		template: '<div class="dialog"><slot /></div>',
	},
	NcCheckboxRadioSwitch: {
		name: 'NcCheckboxRadioSwitch',
		props: ['modelValue', 'value'],
		emits: ['update:modelValue'],
		template: '<label :data-checked="modelValue === value" @click="$emit(\'update:modelValue\', value)"><slot /></label>',
	},
}))
jest.mock('vue-material-design-icons/Delete.vue', () => ({ name: 'Delete', render: () => null }))
jest.mock('./SettingsDialogActions.vue', () => ({
	name: 'SettingsDialogActions',
	props: ['busy', 'cancelDisabled', 'label'],
	emits: ['cancel', 'confirm'],
	render: () => null,
}))

describe('ClearCacheDialog', () => {
	it('checks the cache type it is given and reports a new one', async () => {
		const wrapper = mount(ClearCacheDialog, { props: { open: true, cacheType: 'schema' } })
		const radios = wrapper.findAll('label')
		expect(radios.map((r) => r.attributes('data-checked'))).toEqual([
			'false',
			'false',
			'true',
			'false',
			'false',
		])
		await radios[4].trigger('click')
		expect(wrapper.emitted('update:cacheType')).toEqual([['distributed']])
	})

	it('passes confirm, cancel and closing to its parent', () => {
		const wrapper = mount(ClearCacheDialog, { props: { open: true } })
		const actions = wrapper.findComponent({ name: 'SettingsDialogActions' })
		expect(actions.props('label')).toBe('Clear Cache')
		actions.vm.$emit('confirm')
		actions.vm.$emit('cancel')
		wrapper.findComponent({ name: 'NcDialog' }).vm.$emit('closing')
		expect(wrapper.emitted('confirm')).toHaveLength(1)
		expect(wrapper.emitted('closing')).toHaveLength(2)
	})

	it('locks the dialog while clearing', () => {
		const wrapper = mount(ClearCacheDialog, { props: { open: true, clearing: true } })
		expect(wrapper.findComponent({ name: 'NcDialog' }).props('noClose')).toBe(true)
		expect(wrapper.findComponent({ name: 'SettingsDialogActions' }).props()).toEqual({
			busy: true,
			cancelDisabled: true,
			label: 'Clearing...',
		})
	})
})

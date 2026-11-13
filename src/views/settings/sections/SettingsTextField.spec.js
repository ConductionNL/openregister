/**
 * The settings text field: its label points at the field, the hint sits
 * under it, and the value, attributes and listeners reach the NcTextField.
 *
 * @spec exclude Unit test of a presentational settings field.
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
import { mount } from '@vue/test-utils'
import { h } from 'vue'
import SettingsTextField from './SettingsTextField.vue'

jest.mock('@nextcloud/vue', () => {
	const { h: render } = require('vue')
	return {
		__esModule: true,
		NcTextField: {
			name: 'NcTextField',
			props: ['modelValue'],
			emits: ['update:modelValue'],
			setup(props, { attrs, slots }) {
				return () => render('div', { class: 'nc-text-field' }, [
					render('input', {
						...attrs,
						value: props.modelValue,
					}),
					slots['trailing-button-icon']
						? render('span', { class: 'trailing' }, slots['trailing-button-icon']())
						: null,
				])
			},
		},
	}
})

/**
 * Mount the field.
 *
 * @param {object} attrs Extra attributes and listeners
 * @param {object} slots The slots
 * @return {object} The wrapper
 */
function mountField(attrs = {}, slots = {}) {
	return mount(SettingsTextField, {
		props: { id: 'presidio-endpoint', label: 'Endpoint', hint: 'The URL', ...attrs },
		slots,
	})
}

describe('SettingsTextField', () => {
	it('labels the field and shows the hint under it', () => {
		const wrapper = mountField()

		expect(wrapper.find('label').attributes('for')).toBe('presidio-endpoint')
		expect(wrapper.find('label').text()).toBe('Endpoint')
		expect(wrapper.find('input').attributes('id')).toBe('presidio-endpoint')
		expect(wrapper.find('.field-hint').text()).toBe('The URL')
		expect(wrapper.attributes('placeholder')).toBeUndefined()
	})

	it('passes the value, the other attributes and the listeners to the field', async () => {
		const onUpdate = jest.fn()
		const onBlur = jest.fn()
		const wrapper = mountField({
			modelValue: 'http://presidio',
			placeholder: 'http://example',
			type: 'password',
			'onUpdate:modelValue': onUpdate,
			onBlur,
		})
		const field = wrapper.findComponent({ name: 'NcTextField' })

		expect(field.props('modelValue')).toBe('http://presidio')
		expect(wrapper.find('input').attributes()).toMatchObject({
			placeholder: 'http://example',
			type: 'password',
		})

		await field.vm.$emit('update:modelValue', 'http://other')
		await wrapper.find('input').trigger('blur')

		expect(onUpdate).toHaveBeenCalledWith('http://other')
		expect(onBlur).toHaveBeenCalledTimes(1)
	})

	it('fills the trailing button only when an icon is given', () => {
		expect(mountField().find('.trailing').exists()).toBe(false)

		const withIcon = mountField({}, { icon: () => h('i', { class: 'icon' }) })

		expect(withIcon.find('.trailing .icon').exists()).toBe(true)
	})
})

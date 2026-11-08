/**
 * A @nextcloud/vue stand-in for DOM golden tests: buttons that click, empty
 * contents that print their name and description, switches that toggle, and
 * every other component as a plain element with its default slot.
 *
 * @spec exclude Test support for DOM golden records.
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
import { plainStub } from './tableGolden.js'

const { h } = jest.requireActual('vue')

/**
 * The stand-in module.
 *
 * @return {object} The module
 */
export function ncLibrary() {
	const known = {
		NcButton: {
			name: 'NcButton',
			props: ['disabled'],
			emits: ['click'],
			render() {
				return h('button', { disabled: !!this.disabled, onClick: () => this.$emit('click') }, this.$slots.default?.())
			},
		},
		NcCheckboxRadioSwitch: {
			name: 'NcCheckboxRadioSwitch',
			props: ['modelValue', 'disabled', 'indeterminate'],
			emits: ['update:modelValue'],
			render() {
				return h('label', { 'data-stub': 'switch', 'data-checked': String(this.modelValue), 'data-disabled': String(!!this.disabled) }, [
					h('input', { type: 'checkbox', onClick: () => this.$emit('update:modelValue', !this.modelValue) }),
					this.$slots.default?.(),
				])
			},
		},
		NcEmptyContent: {
			name: 'NcEmptyContent',
			props: ['name', 'description'],
			render() {
				return h('div', { 'data-stub': 'empty' }, `${this.name} | ${this.description}`)
			},
		},
	}
	return new Proxy({ __esModule: true, ...known }, {
		get(target, key) {
			if (key in target || typeof key !== 'string') return target[key]
			target[key] = plainStub(key)
			return target[key]
		},
	})
}

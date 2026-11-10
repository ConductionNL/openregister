/**
 * CloseCancelButton renders what the modals' inline cancel buttons rendered:
 * the cancel icon and Cancel/Close, or the label a modal gives it, with the
 * modal's other attributes on the NcButton and its click passed on.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec exclude Unit test of a presentational dialog action.
 */
import { mount } from '@vue/test-utils'
import CloseCancelButton from './CloseCancelButton.vue'

jest.mock('@nextcloud/l10n', () => ({ translate: (app, text) => `t:${text}` }))
jest.mock('@nextcloud/vue', () => ({
	NcButton: {
		name: 'NcButton',
		inheritAttrs: false,
		emits: ['click'],
		template: `<button :disabled="$attrs.disabled" :data-variant="$attrs.variant" @click="$emit('click', $event)"><slot name="icon" /><slot /></button>`,
	},
}))
jest.mock('vue-material-design-icons/Cancel.vue', () => ({
	name: 'Cancel',
	props: ['size'],
	template: '<i class="cancel" :data-size="size" />',
}))

const { NcButton } = jest.requireMock('@nextcloud/vue')
const Cancel = jest.requireMock('vue-material-design-icons/Cancel.vue')

const OLD = {
	components: { NcButton, Cancel },
	props: ['label'],
	template: `<NcButton disabled variant="primary">
		<template #icon>
			<Cancel :size="20" />
		</template>
		{{ label }}
	</NcButton>`,
}

/**
 * The markup with the whitespace HTML collapses removed.
 *
 * @param {object} wrapper A mounted component
 * @return {string} Its HTML
 */
function html(wrapper) {
	return wrapper.html().replace(/>\s+/g, '>').replace(/\s+</g, '<')
}

describe('CloseCancelButton', () => {
	it('reads Cancel, and Close after a success', () => {
		expect(mount(CloseCancelButton).text()).toBe('t:Cancel')
		expect(mount(CloseCancelButton, { props: { success: true } }).text()).toBe('t:Close')
	})

	it('renders a given label as the inline button did', () => {
		const old = mount(OLD, { props: { label: 'Annuleren' } })
		const now = mount(CloseCancelButton, {
			props: { label: 'Annuleren', success: true },
			attrs: { disabled: true, variant: 'primary' },
		})
		expect(html(now)).toBe(html(old))
	})

	it('passes the click and its event on', async () => {
		const onClick = jest.fn()
		const wrapper = mount(CloseCancelButton, { attrs: { onClick } })
		await wrapper.find('button').trigger('click')
		expect(onClick).toHaveBeenCalledTimes(1)
		expect(onClick.mock.calls[0][0]).toBeInstanceOf(Event)
	})
})

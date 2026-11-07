/**
 * LabeledOption renders the markup FileConfiguration's select options wrote
 * inline before: with and without the icon span.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec exclude Unit test of a presentational select option.
 */
import { mount } from '@vue/test-utils'
import LabeledOption from './LabeledOption.vue'

const OLD_PLAIN = {
	props: ['label', 'description'],
	template: `<div class="option-item">
		<span class="option-label">{{ label }}</span>
		<span class="option-description">{{
			description
		}}</span>
	</div>`,
}

const OLD_WITH_ICON = {
	props: ['label', 'description', 'icon'],
	template: `<div class="option-item">
		<span class="option-icon">{{ icon }}</span>
		<span class="option-label">{{ label }}</span>
		<span class="option-description">{{
			description
		}}</span>
	</div>`,
}

/**
 * The markup without the scope attributes each component adds.
 *
 * @param {object} wrapper A mounted component
 * @return {string} Its HTML
 */
function html(wrapper) {
	return wrapper.html().replace(/ data-v-[a-z0-9]+(="")?/g, '')
}

describe('LabeledOption', () => {
	const option = {
		label: 'LLPhant',
		description: 'PHP-based extraction',
		icon: '🐘',
	}

	it('matches the old option markup without an icon', () => {
		const props = { label: option.label, description: option.description }
		expect(html(mount(LabeledOption, { props }))).toBe(
			html(mount(OLD_PLAIN, { props })),
		)
		expect(mount(LabeledOption, { props }).find('.option-icon').exists()).toBe(
			false,
		)
	})

	it('matches the old option markup with an icon', () => {
		const rendered = mount(LabeledOption, {
			props: { ...option, withIcon: true },
		})
		expect(html(rendered)).toBe(html(mount(OLD_WITH_ICON, { props: option })))
		expect(rendered.find('.option-icon').text()).toBe('🐘')
		expect(rendered.find('.option-label').text()).toBe('LLPhant')
		expect(rendered.find('.option-description').text()).toBe(
			'PHP-based extraction',
		)
	})

	it('keeps an empty icon span for an option without an icon', () => {
		const props = { label: 'None', description: 'Off' }
		expect(html(mount(LabeledOption, { props: { ...props, withIcon: true } }))).toBe(
			html(mount(OLD_WITH_ICON, { props })),
		)
	})
})

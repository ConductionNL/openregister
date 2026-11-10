/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The register and schema pickers of the search sidebar render each option
 * through SelectOption; it must produce the markup the inline copies did.
 *
 * @spec exclude Unit test of client-side select option markup.
 */
import { mount } from '@vue/test-utils'
import SelectOption from '../../components/shared/SelectOption.vue'

/**
 * The option markup the search sidebar wrote inline before.
 */
const InlineOption = {
	props: ['title', 'description'],
	template: `<div class="option-content">
		<span class="option-title">{{ title }}</span>
		<span
			v-if="description"
			class="option-description"
			>{{ description }}</span
		>
	</div>`,
}

/**
 * The element markup without comment and empty text nodes.
 *
 * @param {object} wrapper A mounted option
 * @return {string} The markup
 */
function markup(wrapper) {
	return wrapper
		.html()
		.replace(/<!--.*?-->/g, '')
		.replace(/>\s+</g, '><')
}

describe('search sidebar select option', () => {
	it.each([
		['with a description', { title: 'Petstore', description: 'All pets' }],
		['without a description', { title: 'Petstore', description: '' }],
		['with an undefined description', { title: 'Petstore' }],
	])('renders the inline markup %s', (label, props) => {
		const shared = mount(SelectOption, { props })
		const inline = mount(InlineOption, { props })
		expect(markup(shared)).toBe(markup(inline))
	})

	it('shows the title and description text', () => {
		const wrapper = mount(SelectOption, {
			props: { title: 'Petstore', description: 'All pets' },
		})
		expect(wrapper.find('.option-title').text()).toBe('Petstore')
		expect(wrapper.find('.option-description').text()).toBe('All pets')
	})
})

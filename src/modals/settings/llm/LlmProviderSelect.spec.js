/**
 * The provider column of the LLM configuration modal: its heading, its
 * description, and a select that passes the picked provider on.
 *
 * @spec exclude Unit test of a presentational form field.
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
import { mount } from '@vue/test-utils'
import LlmProviderSelect from './LlmProviderSelect.vue'

jest.mock('@nextcloud/vue', () => {
	const { h: render } = require('vue')
	return {
		__esModule: true,
		NcSelect: {
			name: 'NcSelect',
			props: ['modelValue', 'options', 'inputLabel', 'placeholder', 'label'],
			emits: ['update:modelValue'],
			setup(props, { slots }) {
				return () => render(
					'div',
					{ class: 'nc-select' },
					props.options.map((option) => slots.option(option)),
				)
			},
		},
	}
})

const providers = [
	{ id: 'openai', name: 'OpenAI', description: 'GPT models' },
	{ id: 'ollama', name: 'Ollama', description: 'Local models' },
]

/**
 * Mount the column with the two providers.
 *
 * @param {object} modelValue The selected provider
 * @return {object} The wrapper
 */
function mountColumn(modelValue = null) {
	return mount(LlmProviderSelect, {
		props: {
			modelValue,
			options: providers,
			title: 'Embedding Provider',
			description: 'For vector embeddings and semantic search',
			inputLabel: 'Embedding Provider',
		},
		global: { mocks: { t: (app, text) => text } },
	})
}

describe('LlmProviderSelect', () => {
	it('renders the heading, the description and each provider option', () => {
		const wrapper = mountColumn()

		expect(wrapper.find('h3').text()).toBe('Embedding Provider')
		expect(wrapper.find('.section-description').text()).toBe(
			'For vector embeddings and semantic search',
		)
		expect(
			wrapper.findAll('.provider-option').map((option) => [
				option.find('strong').text(),
				option.find('small').text(),
			]),
		).toEqual([
			['OpenAI', 'GPT models'],
			['Ollama', 'Local models'],
		])
	})

	it('hands the select its value, options, labels and placeholder', () => {
		const select = mountColumn(providers[1]).findComponent({ name: 'NcSelect' })

		expect(select.props()).toEqual({
			modelValue: providers[1],
			options: providers,
			inputLabel: 'Embedding Provider',
			placeholder: 'Select provider',
			label: 'name',
		})
	})

	it('emits the provider the select picks', async () => {
		const wrapper = mountColumn(providers[0])

		await wrapper
			.findComponent({ name: 'NcSelect' })
			.vm.$emit('update:modelValue', providers[1])

		expect(wrapper.emitted('update:modelValue')).toEqual([[providers[1]]])
	})
})

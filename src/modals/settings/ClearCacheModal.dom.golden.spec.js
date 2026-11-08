/**
 * Golden record of the clear-cache modal: its cache type radios in each
 * selection and clearing state, and the cache type each radio selects.
 *
 * @spec exclude Golden record of client-side modal markup.
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
import { mount } from '@vue/test-utils'
import ClearCacheModal from './ClearCacheModal.vue'

jest.mock('@nextcloud/vue', () => require('../../views/__mocks__/goldenDom.js').library())
jest.mock('vue-material-design-icons/Cancel.vue', () =>
	require('../../views/__mocks__/goldenDom.js').stubModule('Cancel'),
)
jest.mock('vue-material-design-icons/Delete.vue', () =>
	require('../../views/__mocks__/goldenDom.js').stubModule('Delete'),
)

const { normalise } = require('../../views/__mocks__/goldenDom.js')

/**
 * Mounts the modal with these props.
 *
 * @param {object} props The props
 * @return {object} The wrapper
 */
function modal(props) {
	return mount(ClearCacheModal, { props: { show: true, ...props }, global: { config: { warnHandler: () => {} } } })
}

describe('clear cache modal', () => {
	it.each([
		['with all selected', {}],
		['with the facet cache selected while clearing', { cacheType: 'facet', clearing: true }],
		['when hidden', { show: false }],
	])('renders %s', (name, props) => {
		expect(normalise(modal(props))).toMatchSnapshot()
	})

	it('selects the cache type of each radio', async () => {
		const wrapper = modal({})
		const radios = wrapper.findAllComponents({ name: 'NcCheckboxRadioSwitch' })
		expect(radios.map((radio) => radio.attributes('value'))).toEqual([
			'all',
			'object',
			'schema',
			'facet',
			'distributed',
		])
		radios[3].vm.$attrs['onUpdate:modelValue']('facet')
		await wrapper.vm.$nextTick()
		expect(wrapper.vm.localCacheType).toBe('facet')
		expect(wrapper.emitted('cache-type-changed')).toEqual([['facet']])
	})
})

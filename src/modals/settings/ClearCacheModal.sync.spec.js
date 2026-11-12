import { mount } from '@vue/test-utils'
import ClearCacheModal from './ClearCacheModal.vue'

jest.mock('@nextcloud/vue', () => require('../../views/__mocks__/goldenDom.js').library())
jest.mock('vue-material-design-icons/Cancel.vue', () =>
	require('../../views/__mocks__/goldenDom.js').stubModule('Cancel'),
)
jest.mock('vue-material-design-icons/Delete.vue', () =>
	require('../../views/__mocks__/goldenDom.js').stubModule('Delete'),
)
jest.mock('vue-material-design-icons/TrashCanOutline.vue', () =>
	require('../../views/__mocks__/goldenDom.js').stubModule('TrashCanOutline'),
)

/**
 * Mount the visible modal.
 *
 * @param {object} props Extra props.
 * @return {object} The wrapper.
 */
function modal(props = {}) {
	return mount(ClearCacheModal, { props: { show: true, ...props }, global: { config: { warnHandler: () => {} } } })
}

describe('clear cache modal — cache type sync', () => {
	it('follows a new cacheType prop and reports the change upward', async () => {
		const wrapper = modal({ cacheType: 'all' })
		await wrapper.setProps({ cacheType: 'schema' })
		expect(wrapper.vm.localCacheType).toBe('schema')
		expect(wrapper.emitted('cache-type-changed')).toEqual([['schema']])
	})

	it('confirms with the currently selected cache type', async () => {
		const wrapper = modal({ cacheType: 'facet' })
		wrapper.vm.confirmClear()
		expect(wrapper.emitted('confirm')).toEqual([['facet']])
	})
})

/**
 * Golden record of the explore-schema dialog before an analysis: the info
 * card and the analysis steps it lists.
 *
 * @spec exclude Golden record of client-side dialog markup.
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
import { flushPromises, mount } from '@vue/test-utils'
import ExploreSchema from './ExploreSchema.vue'

jest.mock('@nextcloud/l10n', () => ({
	translate: (app, text) => `t:${text}`,
	translatePlural: (app, one) => `n:${one}`,
}))
jest.mock('@nextcloud/vue', () =>
	require('../../views/__mocks__/goldenDom.js').library(),
)
jest.mock('../../components/SchemaStatsBlock.vue', () =>
	require('../../views/__mocks__/goldenDom.js').stubModule('SchemaStatsBlock'),
)
jest.mock('../../components/PaginationComponent.vue', () =>
	require('../../views/__mocks__/goldenDom.js').stubModule('PaginationComponent'),
)
jest.mock('../../store/store.js', () => ({
	navigationStore: { modal: 'exploreSchema' },
	schemaStore: { schemaItem: null },
}))
jest.mock('vue-material-design-icons/Check.vue', () =>
	require('../../views/__mocks__/goldenDom.js').stubModule('Check'),
)
jest.mock('vue-material-design-icons/DatabaseSearch.vue', () =>
	require('../../views/__mocks__/goldenDom.js').stubModule('DatabaseSearch'),
)
jest.mock('vue-material-design-icons/Refresh.vue', () =>
	require('../../views/__mocks__/goldenDom.js').stubModule('Refresh'),
)

const { normalise } = require('../../views/__mocks__/goldenDom.js')

/**
 * What the template reads from the setup script, as the test compiler does not
 * expose the imports of a script-setup block.
 *
 * @return {object} The bindings
 */
function bindings() {
	const { translate: t, translatePlural: n } = jest.requireMock('@nextcloud/l10n')
	const stores = jest.requireMock('../../store/store.js')
	const SchemaStatsBlock = jest.requireMock(
		'../../components/SchemaStatsBlock.vue',
	).default
	return { ...ExploreSchema.components, ...stores, SchemaStatsBlock, t, n }
}

describe('explore schema before an analysis', () => {
	it('lists the analysis steps', async () => {
		const wrapper = mount(
			{ ...ExploreSchema, setup: () => bindings() },
			{
				global: { config: { warnHandler: () => {} } },
			},
		)
		await flushPromises()
		const steps = wrapper.find('.steps-section')
		expect(steps.exists()).toBe(true)
		expect(wrapper.findAll('.steps-list li')).toHaveLength(7)
		expect(normalise(steps)).toMatchSnapshot()
		expect(normalise(wrapper)).toMatchSnapshot()
	})
})

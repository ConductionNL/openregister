import { flushPromises, mount } from '@vue/test-utils'
import AgentsIndex from './AgentsIndex.vue'
import { tableText } from '../../../tests/unit/support/tableGolden.js'

/**
 * Golden record of the agents table: what it shows (header labels and cell
 * texts, independent of the markup that draws it), its empty state, and the
 * edit button that opens the limits dialog.
 *
 * @spec exclude Golden record of the client-side agents table.
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */

jest.mock('@nextcloud/axios', () => ({ __esModule: true, default: { get: jest.fn() } }))
jest.mock('@nextcloud/auth', () => ({ __esModule: true, getCurrentUser: () => ({ uid: 'bob' }) }))
jest.mock('@nextcloud/l10n', () => ({
	__esModule: true,
	translate: (app, text, vars) => `t:${text}${vars ? JSON.stringify(vars) : ''}`,
}))
jest.mock('@nextcloud/router', () => ({ generateUrl: (path) => path }))
jest.mock('@nextcloud/vue', () => jest.requireActual('../../../tests/unit/support/ncGolden.js').ncLibrary())
jest.mock('@conduction/nextcloud-vue', () => ({
	__esModule: true,
	CnDataTable: jest.requireActual('../../../tests/unit/support/tableGolden.js').CnDataTableStub,
}))
jest.mock('../../modals/agent/EditAgentLimits.vue', () => ({
	__esModule: true,
	default: { name: 'EditAgentLimits', props: ['agent'], render() { return null } },
}))

const AGENTS = [
	{ id: 1, name: 'Mine', owner: 'bob', tools: ['t1', 'gone'], views: ['v1'] },
	{ id: 2, name: 'Theirs', owner: 'eve', tools: [], views: [] },
	{ id: 3, name: 'Per app', owner: 'bob', tools: { app: ['t1'] }, views: [] },
]

/**
 * Mount the screen over these API answers.
 *
 * @param {Array} agents The agents the API returns
 * @return {Promise<object>} The wrapper
 */
async function mounted(agents) {
	const axios = jest.requireMock('@nextcloud/axios').default
	axios.get.mockImplementation((url) => Promise.resolve({
		data: {
			results: url.endsWith('/agents')
				? agents
				: url.endsWith('/tools')
					? { t1: { name: 'Search' } }
					: [{ uuid: 'v1', name: 'Open cases' }],
		},
	}))
	const wrapper = mount(AgentsIndex)
	await flushPromises()
	return wrapper
}

describe('AgentsIndex table', () => {
	it('shows every agent with its tools, views and what may be done', async () => {
		const wrapper = await mounted(AGENTS)
		expect(tableText(wrapper)).toMatchSnapshot()
	})

	it('lets the name, tools and views wrap and leaves a missing name blank', async () => {
		const wrapper = await mounted([{ id: 1, name: null, owner: 'bob', tools: [], views: [] }])
		const wrapping = wrapper.findComponent({ name: 'CnDataTable' }).props('columns')
			.filter((column) => column.cellClass === 'cn-cell--wrap')
			.map((column) => column.key)
		expect(wrapping).toEqual(['name', 'tools', 'views'])
		expect(wrapper.findAll('tbody td')[0].text()).toBe('')
	})

	it('shows the empty state without agents', async () => {
		const wrapper = await mounted([])
		expect(wrapper.findAll('table')).toHaveLength(0)
		expect(wrapper.find('[data-stub="empty"]').text()).toMatchSnapshot()
	})

	it('opens the limits dialog for the agent whose button is clicked', async () => {
		const wrapper = await mounted(AGENTS)
		await wrapper.find('button').trigger('click')
		expect(wrapper.findComponent({ name: 'EditAgentLimits' }).props('agent')).toEqual(AGENTS[0])
	})
})

import { flushPromises, mount } from '@vue/test-utils'
import FlowOverview from './FlowOverview.vue'
import { tableText } from '../../../tests/unit/support/tableGolden.js'

/**
 * Golden record of the flow overview's versions and runs tables: what each
 * shows (header labels and cell texts, independent of the markup that draws
 * them), the run links, the test id the e2e journey reads, and the empty notes.
 *
 * @spec exclude Golden record of the client-side flow overview tables.
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */

jest.mock('@nextcloud/axios', () => ({ __esModule: true, default: { get: jest.fn(() => Promise.reject(new Error('offline'))) } }))
jest.mock('@nextcloud/router', () => ({ __esModule: true, generateUrl: (path) => path }))
jest.mock('@nextcloud/l10n', () => ({
	__esModule: true,
	t: (app, text, vars) => `t:${text}${vars ? JSON.stringify(vars) : ''}`,
	translate: (app, text, vars) => `t:${text}${vars ? JSON.stringify(vars) : ''}`,
	n: (app, one, many, count) => `n:${count}:${many}`,
	translatePlural: (app, one, many, count) => `n:${count}:${many}`,
	getCanonicalLocale: () => 'en-GB',
	getLanguage: () => 'en',
}))
jest.mock('@nextcloud/vue', () => jest.requireActual('../../../tests/unit/support/ncGolden.js').ncLibrary())
jest.mock('@conduction/nextcloud-vue', () => {
	const { h } = jest.requireActual('vue')
	return {
		__esModule: true,
		CnDataTable: jest.requireActual('../../../tests/unit/support/tableGolden.js').CnDataTableStub,
		CnStatusBadge: {
			name: 'CnStatusBadge',
			props: ['label', 'variant'],
			render() {
				return h('span', `[${this.variant}] ${this.label}`)
			},
		},
	}
})
jest.mock('vue-material-design-icons/SitemapOutline.vue', () => ({ __esModule: true, default: { render: () => null } }))

const FLOW = { id: 'flow-1', uuid: 'flow-1', name: 'Enquiry', enabled: true, lifecycleStatus: 'published', nodes: [], edges: [] }
const VERSIONS = [
	{ id: 1, version: 1, semver: '1.0.0', status: 'published', publishedAt: '2026-11-01T10:00:00Z', publishedBy: 'admin' },
	{ id: 2, version: 2, status: 'draft' },
]
const RUNS = [
	{ uuid: 'run-1', status: 'failed', created: '2026-11-02T10:00:00Z', subjectUuid: 'e2236ef7-0000-4000-8000-000000000000', error: 'Boom', flowVersion: 1 },
	{ uuid: 'run-2', status: 'completed', created: '2026-11-01T09:00:00Z', flowVersion: 2 },
]

/**
 * Mount the overview, let its (failing) load settle, then give it a flow.
 *
 * @param {object} state The data to show
 * @return {Promise<object>} The wrapper
 */
async function mounted(state) {
	const wrapper = mount(FlowOverview, {
		props: { id: 'flow-1' },
		global: {
			stubs: { 'router-link': { props: ['to'], template: '<a :data-to="JSON.stringify(to)"><slot /></a>' } },
			mocks: { $router: { push: jest.fn() } },
			config: { globalProperties: { t: (app, text, vars) => `t:${text}${vars ? JSON.stringify(vars) : ''}` } },
		},
	})
	await flushPromises()
	await wrapper.setData({ loading: false, flow: FLOW, ...state })
	return wrapper
}

describe('FlowOverview tables', () => {
	it('shows the versions and the runs', async () => {
		const wrapper = await mounted({ versions: VERSIONS, runs: RUNS })
		expect(tableText(wrapper)).toMatchSnapshot()
		const runs = wrapper.find('[data-testid="flow-overview-runs"]')
		expect(runs.findAll('a').map((a) => a.attributes('data-to'))).toMatchSnapshot()
		expect(runs.find('.mono').attributes('title')).toBe(RUNS[0].subjectUuid)
	})

	it('shows the notes without versions or runs', async () => {
		const wrapper = await mounted({ versions: [], runs: [] })
		expect(wrapper.findAll('table')).toHaveLength(0)
		expect(wrapper.findAll('.flowCard__note').map((p) => p.text())).toMatchSnapshot()
	})
})

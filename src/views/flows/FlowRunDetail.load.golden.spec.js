import axios from '@nextcloud/axios'
import FlowRunDetail from './FlowRunDetail.vue'

/**
 * Golden record of the run page's load: which requests it makes in which
 * order, and the state it leaves for a found run, a run without a flow,
 * panels that cannot load, and a run that cannot be read.
 *
 * @spec exclude Golden record of the client-side run page load.
 */

jest.mock('@nextcloud/axios', () => ({ __esModule: true, default: { get: jest.fn() } }))
jest.mock('@nextcloud/router', () => ({ __esModule: true, generateUrl: (path) => `/gen${path}` }))
jest.mock('@nextcloud/l10n', () => ({
	__esModule: true,
	t: (app, text) => text,
	translate: (app, text) => text,
	n: (app, one, many) => many,
	translatePlural: (app, one, many) => many,
	getCanonicalLocale: () => 'en-GB',
	getLanguage: () => 'en',
}))
jest.mock('@nextcloud/vue', () => ({}))
jest.mock('@conduction/nextcloud-vue', () => ({}))
jest.mock('vue-material-design-icons/PlayCircleOutline.vue', () => ({ __esModule: true, default: {} }))

const UUID = 'run-1'

const ANSWERS = {
	'/gen/apps/openregister/api/flow-runs/run-1': { uuid: UUID, flowId: 'flow-9', status: 'done' },
	'/gen/apps/openregister/api/flows/flow-9': { id: 'flow-9', name: 'Enquiry' },
	'/gen/apps/openregister/api/flows/flow-9/versions': { results: [{ version: 1 }] },
	'/gen/apps/openregister/api/flow/node-catalog': { results: [{ id: 'n.a', displayName: 'Node A' }] },
	'/gen/apps/openregister/api/flow-runs/run-1/objects': { nodes: [{ uuid: 'o1' }] },
	'/gen/apps/openregister/api/flow-tasks': {
		results: [{ id: 1, runUuid: UUID }, { id: 2, runUuid: 'other' }, { id: 3 }],
	},
}

/**
 * Answer every GET from a table; a missing entry fails the request.
 *
 * @param {object} answers Response data by URL
 * @return {void}
 */
function answerWith(answers) {
	axios.get.mockImplementation((url) => (url in answers
		? Promise.resolve({ data: answers[url] })
		: Promise.reject(new Error(`404 ${url}`))))
}

/**
 * Run the page's load on a bare component state.
 *
 * @return {Promise<object>} The state after loading
 */
async function load() {
	const page = {
		uuid: UUID,
		loading: false,
		actionError: 'old error',
		activeTab: 'log',
		run: { stale: true },
	}
	page.quietGet = FlowRunDetail.methods.quietGet.bind(page)
	await FlowRunDetail.methods.load.call(page)
	delete page.quietGet
	return page
}

describe('FlowRunDetail.load', () => {
	beforeEach(() => axios.get.mockReset())

	it('reads the run, then its flow, versions, catalog, objects and tasks', async () => {
		answerWith(ANSWERS)
		expect(await load()).toMatchSnapshot()
		expect(axios.get.mock.calls).toMatchSnapshot()
	})

	it('skips the flow and versions for a run without a flow', async () => {
		answerWith({ ...ANSWERS, '/gen/apps/openregister/api/flow-runs/run-1': { uuid: UUID } })
		expect(await load()).toMatchSnapshot()
		expect(axios.get.mock.calls.map(([url]) => url)).toMatchSnapshot()
	})

	it('leaves the panels empty when only the run can be read', async () => {
		answerWith({ '/gen/apps/openregister/api/flow-runs/run-1': ANSWERS['/gen/apps/openregister/api/flow-runs/run-1'] })
		expect(await load()).toMatchSnapshot()
	})

	it('goes on with an empty run answer', async () => {
		answerWith({ ...ANSWERS, '/gen/apps/openregister/api/flow-runs/run-1': '' })
		expect(await load()).toMatchSnapshot()
	})

	it('stops with no run when the run cannot be read', async () => {
		answerWith({})
		expect(await load()).toEqual({
			uuid: UUID,
			loading: false,
			actionError: '',
			activeTab: 'steps',
			run: null,
		})
		expect(axios.get).toHaveBeenCalledTimes(1)
	})

	it('marks the page loading until the run answers', async () => {
		let seen
		const page = { uuid: UUID, loading: false, actionError: 'x', activeTab: 'log' }
		axios.get.mockImplementation(() => {
			seen = { ...page }
			return Promise.reject(new Error('offline'))
		})
		await FlowRunDetail.methods.load.call(page)
		expect(seen).toEqual({ uuid: UUID, loading: true, actionError: '', activeTab: 'steps' })
	})
})

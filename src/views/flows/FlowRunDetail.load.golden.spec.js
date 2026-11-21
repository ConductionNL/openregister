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
	t: (app, text, vars) => (vars ? `${text} ${JSON.stringify(vars)}` : text),
	translate: (app, text, vars) => (vars ? `${text} ${JSON.stringify(vars)}` : text),
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
 * Run the page's load on a bare component state that carries the page's
 * methods, and return that state without them.
 *
 * @param {object} state The data before loading
 * @return {Promise<object>} The data after loading
 */
async function loadInto(state) {
	const page = { ...state }
	const methods = Object.keys(FlowRunDetail.methods)
	for (const name of methods) {
		page[name] = FlowRunDetail.methods[name].bind(page)
	}
	await page.load()
	for (const name of methods) {
		delete page[name]
	}
	return page
}

/**
 * Load a page that showed another tab, an error and a stale run.
 *
 * @return {Promise<object>} The data after loading
 */
function load() {
	return loadInto({
		uuid: UUID,
		loading: false,
		actionError: 'old error',
		activeTab: 'log',
		run: { stale: true },
	})
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

	it('resets the page, then clears the run and the loading flag in that order', async () => {
		axios.get.mockRejectedValue(new Error('offline'))
		const states = []
		const page = { uuid: UUID, loading: false, actionError: 'x', activeTab: 'log' }
		const watched = new Proxy(page, {
			set(target, key, value) {
				states.push([key, value])
				target[key] = value
				return true
			},
		})
		await FlowRunDetail.methods.load.call(new Proxy(watched, {
			get(target, key) {
				return key in FlowRunDetail.methods
					? FlowRunDetail.methods[key].bind(watched)
					: target[key]
			},
		}))
		expect(axios.get).toHaveBeenCalledTimes(1)
		expect(states).toEqual([
			['loading', true],
			['actionError', ''],
			['activeTab', 'steps'],
			['run', null],
			['loading', false],
		])
	})
})

describe('FlowRunDetail.failure', () => {
	const ordered = [{ id: 'n1', type: 'a' }, { id: 'n2', type: 'b' }]
	const failure = (run, nodes = ordered) =>
		FlowRunDetail.computed.failure.call({ run, ordered: nodes, names: { b: 'Step B' } })
	const failedLog = [{ status: 'done', transition: 'n1' }, { status: 'failed', transition: 'n2', error: 'Boom' }]

	it.each([
		['no run', null, ordered],
		['a failed step in the flow', { status: 'failed', log: failedLog }, ordered],
		['a failed step the flow no longer has', { status: 'failed', log: failedLog }, []],
		['a failed step without its own error', { status: 'failed', error: 'Run error', log: [{ status: 'failed', transition: 'n2' }] }, ordered],
		['a failed run without a failed step', { status: 'failed', log: [] }, ordered],
		['a dead-lettered run', { status: 'dead_letter' }, ordered],
		['a finished run with an error', { status: 'completed', error: 'Late' }, ordered],
		['a finished run', { status: 'completed' }, ordered],
	])('%s', (label, run, nodes) => {
		expect(failure(run, nodes)).toMatchSnapshot()
	})
})

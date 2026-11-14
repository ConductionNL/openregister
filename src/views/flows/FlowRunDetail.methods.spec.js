import FlowRunDetail from './FlowRunDetail.vue'

/**
 * The run page's derived names, routes and counts, the step dots, the panel
 * requests it makes for a run with and without a flow, and how it files the
 * panels' answers.
 *
 * @spec exclude Unit tests of the client-side run page.
 */

jest.mock('@nextcloud/axios', () => ({ __esModule: true, default: { get: jest.fn(), post: jest.fn() } }))
jest.mock('@nextcloud/router', () => ({ __esModule: true, generateUrl: (path) => path }))
jest.mock('@nextcloud/l10n', () => ({
	__esModule: true,
	t: (app, text) => `t:${text}`,
	translate: (app, text) => `t:${text}`,
	n: (app, one, many, count) => `n:${count}:${many}`,
	translatePlural: (app, one, many, count) => `n:${count}:${many}`,
	getCanonicalLocale: () => 'en-GB',
	getLanguage: () => 'en',
}))
jest.mock('@nextcloud/vue', () => ({ __esModule: true }))
jest.mock('@conduction/nextcloud-vue', () => ({ __esModule: true, CnStatusBadge: {} }))
jest.mock('vue-material-design-icons/PlayCircleOutline.vue', () => ({ __esModule: true, default: {} }))

/**
 * A bare page state carrying the page's methods and computed getters, with
 * every quiet GET recorded and answered by its path.
 *
 * @param {object} data The page data
 * @return {object} The page
 */
function page(data = {}) {
	const state = { ...FlowRunDetail.data(), uuid: 'run-1', ...data }
	for (const [name, fn] of Object.entries(FlowRunDetail.methods)) {
		state[name] = fn.bind(state)
	}
	state.quietGet = jest.fn((path, params) => Promise.resolve({ path, params }))
	for (const [name, getter] of Object.entries(FlowRunDetail.computed)) {
		Object.defineProperty(state, name, { get: getter.bind(state), configurable: true })
	}
	return state
}

describe('FlowRunDetail derived state', () => {
	it('names the flow, or the short run flow id without a flow', () => {
		expect(page({ flow: { name: 'Intake' }, run: { flowId: 'abcdef123456' } }).flowName).toBe('Intake')
		expect(page({ run: { flowId: 'abcdef123456' } }).flowName).toBe('abcdef12')
		expect(page().flowName).toBe('')
	})

	it('routes to the flow overview and the canvas for the run', () => {
		const state = page({ run: { flowId: 7 } })
		expect(state.overviewRoute).toEqual({ name: 'flow-overview', params: { id: '7' } })
		expect(state.canvasRoute).toEqual({ path: '/flows/7', query: { run: 'run-1' } })
	})

	it('counts the changed objects across every step group', () => {
		expect(page({ changed: [{ objects: [1, 2] }, {}, { objects: [3] }] }).changedCount).toBe(3)
		expect(page().changedCount).toBe(0)
	})

	it('offers resume only for a suspended run', () => {
		expect(page({ run: { status: 'suspended' } }).canResume).toBe(true)
		expect(page({ run: { status: 'running' } }).canResume).toBe(false)
	})

	it('lists one subject per uuid, with where it lives when known', () => {
		const run = {
			subjectUuid: 's1',
			subjectRegister: 'Zaken',
			subjectSchema: 'Zaak',
			subjects: { owner: { uuid: 's2' }, again: { uuid: 's1', register: 'x', schema: 'y' }, none: null },
		}
		expect(page({ run }).subjects).toEqual([
			{ key: 'subject', uuid: 's1', where: 'Zaken › Zaak' },
			{ key: 'owner', uuid: 's2', where: 'owner' },
		])
	})

	it('has no failure alert without a run', () => {
		expect(page().failure).toBeNull()
	})
})

describe('FlowRunDetail step dots and names', () => {
	it.each([
		[{ reached: false, status: 'completed' }, 'skipped', '·'],
		[{ reached: true, status: 'failed' }, 'failed', '!'],
		[{ reached: true, status: 'completed' }, 'ok', '✓'],
		[{ reached: true, status: 'running' }, 'waiting', '…'],
	])('marks %j as %s', (step, kind, symbol) => {
		const state = page()
		expect(state.dotKind(step)).toBe(kind)
		expect(state.dotSymbol(step)).toBe(symbol)
	})

	it('names a step by its node label, else by the catalog name of its type, else by its id', () => {
		const flow = { nodes: [{ id: 'a', label: 'Check' }, { id: 'b', type: 'mail' }], edges: [] }
		const state = page({ flow, names: { mail: 'Send mail' } })
		expect(state.stepNameFor('a')).toBe('Check')
		expect(state.stepNameFor('b')).toBe('Send mail')
		expect(state.stepNameFor('gone')).toBe('gone')
	})

	it('pretty-prints values as indented JSON, and a value JSON cannot hold as text', () => {
		const state = page()
		expect(state.pretty({ a: 1 })).toBe('{\n  "a": 1\n}')
		expect(state.pretty(undefined)).toBe('null')
		const loop = {}
		loop.self = loop
		expect(state.pretty(loop)).toBe('[object Object]')
	})
})

describe('FlowRunDetail panels', () => {
	it('asks for the flow and its versions only when the run has a flow', async () => {
		const state = page()
		expect(state.fetchFlowPanels(null)).toEqual([null, null])
		const [flow, versions] = await Promise.all(state.fetchFlowPanels(9))
		expect(flow.path).toBe('/apps/openregister/api/flows/9')
		expect(versions.path).toBe('/apps/openregister/api/flows/9/versions')
	})

	it('asks for the catalog, the changed objects and the run\'s tasks', async () => {
		const answers = await page().fetchRunPanels(null)
		expect(answers.slice(0, 2)).toEqual([null, null])
		expect(answers.slice(2)).toEqual([
			{ path: '/apps/openregister/api/flow/node-catalog', params: undefined },
			{ path: '/apps/openregister/api/flow-runs/run-1/objects', params: undefined },
			{ path: '/apps/openregister/api/flow-tasks', params: { runUuid: 'run-1', scope: 'all' } },
		])
	})

	it('files the answers, keeping only this run\'s tasks', () => {
		const state = page()
		state.showRunPanels([
			{ name: 'F' },
			{ results: [{ version: 1 }] },
			{ results: [{ id: 'mail', displayName: 'Send mail' }] },
			{ nodes: [{ objects: [1] }] },
			{ results: [{ runUuid: 'run-1', id: 1 }, { runUuid: 'other', id: 2 }, { id: 3 }] },
		])
		expect(state.flow).toEqual({ name: 'F' })
		expect(state.versions).toEqual([{ version: 1 }])
		expect(state.names).toEqual({ mail: 'Send mail' })
		expect(state.changed).toEqual([{ objects: [1] }])
		expect(state.tasks).toEqual([{ runUuid: 'run-1', id: 1 }])
	})

	it('files empty panels when every answer is missing', () => {
		const state = page()
		state.showRunPanels([null, null, null, null, null])
		expect([state.flow, state.versions, state.names, state.changed, state.tasks]).toEqual([null, [], {}, [], []])
	})
})

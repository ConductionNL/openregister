/**
 * The flow overview and the run page (flow-and-run-detail-pages).
 *
 * The derivations are plain functions. The two pages are exercised as
 * options objects against a synthetic `this`, as the other view specs here
 * do: computed properties become getters on that object, so a test reads
 * the page the way its template does.
 *
 * @spec openspec/changes/flow-and-run-detail-pages/specs/flow-and-run-detail-pages/spec.md
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */

import axios from '@nextcloud/axios'
import fs from 'fs'
import path from 'path'
import FlowOverview from './FlowOverview.vue'
import FlowRunDetail from './FlowRunDetail.vue'
import {
	averageDuration,
	failedStep,
	matchesRunFilter,
	orderedNodes,
	runCounts,
	runDuration,
	stepRows,
} from './flowDetail.js'

jest.mock('@nextcloud/axios', () => ({
	__esModule: true,
	default: { get: jest.fn(), post: jest.fn(), put: jest.fn() },
}))
jest.mock('@nextcloud/router', () => ({
	__esModule: true,
	generateUrl: jest.fn((p) => p),
}))
jest.mock('vue-material-design-icons/SitemapOutline.vue', () => ({
	__esModule: true,
	default: { name: 'SitemapOutline', render: () => null },
}))
jest.mock('vue-material-design-icons/PlayCircleOutline.vue', () => ({
	__esModule: true,
	default: { name: 'PlayCircleOutline', render: () => null },
}))
jest.mock(
	'@nextcloud/vue',
	() =>
		new Proxy(
			{ __esModule: true },
			{
				get: (target, prop) =>
					prop in target
						? target[prop]
						: { name: String(prop), render: () => null },
			},
		),
)

const ROOT = path.resolve(__dirname, '..', '..', '..')

/**
 * An options-API component as a plain object: data, props, bound methods
 * and computed getters, plus a router spy.
 *
 * @param {object} component The component options.
 * @param {object} props The props.
 * @return {object} The synthetic `this`.
 */
function makeVm(component, props) {
	const vm = {
		...component.data(),
		...props,
		$router: { push: jest.fn(), replace: jest.fn() },
		$nextTick: (fn) => fn?.(),
	}
	for (const [key, method] of Object.entries(component.methods)) {
		vm[key] = method.bind(vm)
	}
	for (const [key, getter] of Object.entries(component.computed)) {
		Object.defineProperty(vm, key, {
			get: () => getter.call(vm),
			configurable: true,
		})
	}
	return vm
}

const FLOW = {
	id: 'flow-1',
	uuid: 'flow-1',
	name: 'Enquiry to lead',
	enabled: true,
	trigger: 'object.updated',
	nodes: [
		{
			id: 'write',
			type: 'openregister.object-write',
			config: { register: 'pipelinq', schema: 'lead' },
		},
		{
			id: 'read',
			type: 'openregister.object-read',
			config: { register: 'pipelinq', schema: 'pipeline' },
		},
		{ id: 'trigger', type: 'openregister.trigger-object' },
		{ id: 'filter', type: 'openregister.filter' },
	],
	edges: [
		{ id: 'e0', from: 'trigger', to: 'filter' },
		{ id: 'e1', from: 'filter', to: 'read' },
		{ id: 'e2', from: 'read', to: 'write' },
	],
}

const CATALOG = {
	results: [
		{ id: 'openregister.trigger-object', displayName: 'When an object changes' },
		{ id: 'openregister.filter', displayName: 'Filter' },
		{ id: 'openregister.object-read', displayName: 'Read objects' },
		{ id: 'openregister.object-write', displayName: 'Write an object' },
	],
}

const FAILED_RUN = {
	uuid: 'run-1',
	flowId: 'flow-1',
	flowVersion: 1,
	status: 'failed',
	error: 'Subject "e2236ef7" no longer exists.',
	created: '2026-09-19T15:02:22+00:00',
	updated: '2026-09-19T15:02:22+00:00',
	log: [
		{
			transition: 'trigger',
			type: 'openregister.trigger-object',
			status: 'completed',
			itemsIn: 1,
			itemsOut: 1,
			durationMs: 4,
		},
		{
			transition: 'filter',
			type: 'openregister.filter',
			status: 'completed',
			itemsIn: 1,
			itemsOut: 1,
			durationMs: 1,
		},
		{
			transition: 'read',
			type: 'openregister.object-read',
			status: 'failed',
			input: { subject: 'e2236ef7' },
			error: 'Subject "e2236ef7" no longer exists.',
			durationMs: 7,
		},
	],
}

describe('the flow overview route', () => {
	it('opens from an index row instead of the editor', () => {
		const manifest = JSON.parse(
			fs.readFileSync(path.join(ROOT, 'src', 'manifest.json'), 'utf8'),
		)
		const flows = manifest.pages.find((page) => page.id === 'flows')
		expect(flows.config.rowRoute).toBe('flow-overview')
		// The editor keeps its address: /flows/new and ?run= links land there.
		expect(manifest.pages.find((page) => page.id === 'flowDetail').route).toBe(
			'/flows/:id',
		)
	})

	it('is registered as a named route in main.js', () => {
		const main = fs.readFileSync(path.join(ROOT, 'src', 'main.js'), 'utf8')
		expect(main).toMatch(
			/name: 'flow-overview',\s+path: '\/flows\/:id\/overview',\s+component: \(\) => import\('\.\/views\/flows\/FlowOverview\.vue'\)/,
		)
	})
})

describe('the derivations', () => {
	it('orders steps along the edges, not the storage order', () => {
		expect(orderedNodes(FLOW.nodes, FLOW.edges).map((node) => node.id)).toEqual([
			'trigger',
			'filter',
			'read',
			'write',
		])
	})

	it('keeps a node the edges never reach, at the end', () => {
		const nodes = [...FLOW.nodes, { id: 'orphan', type: 'openregister.end' }]
		const ids = orderedNodes(nodes, FLOW.edges).map((node) => node.id)
		expect(ids).toContain('orphan')
	})

	it('counts the loaded runs by outcome', () => {
		const runs = [
			{ status: 'completed' },
			...Array.from({ length: 9 }, () => ({ status: 'failed' })),
			{ status: 'stopped' },
		]
		expect(runCounts(runs)).toEqual({
			total: 11,
			completed: 1,
			failed: 9,
			stopped: 1,
			active: 0,
		})
	})

	it('has no average when no step recorded a duration', () => {
		expect(
			averageDuration([{ log: [] }, { log: [{ status: 'completed' }] }]),
		).toBeNull()
		expect(averageDuration([FAILED_RUN, { log: [{ durationMs: 8 }] }])).toBe(10)
	})

	it('sums the step durations of one run', () => {
		expect(runDuration(FAILED_RUN)).toBe(12)
	})

	it('treats queued, running and suspended as running', () => {
		expect(
			['queued', 'running', 'suspended', 'completed'].map((status) =>
				matchesRunFilter({ status }, 'running'),
			),
		).toEqual([true, true, true, false])
	})

	it('names the failed step and its place in the graph', () => {
		const ordered = orderedNodes(FLOW.nodes, FLOW.edges)
		const names = { 'openregister.object-read': 'Read objects' }
		expect(failedStep(FAILED_RUN, ordered, names)).toEqual({
			nodeId: 'read',
			position: 3,
			label: 'Read objects',
			error: 'Subject "e2236ef7" no longer exists.',
		})
	})

	it('merges a node logged many times and lists unreached nodes', () => {
		const run = {
			log: [
				{ transition: 'trigger', status: 'completed', durationMs: 2 },
				{ transition: 'filter', status: 'suspended', reason: 'waiting' },
				{ transition: 'filter', status: 'suspended', reason: 'waiting' },
			],
		}
		const rows = stepRows(run, orderedNodes(FLOW.nodes, FLOW.edges))
		expect(rows.map((row) => [row.nodeId, row.times, row.reached])).toEqual([
			['trigger', 1, true],
			['filter', 2, true],
			['read', 0, false],
			['write', 0, false],
		])
	})
})

describe('the run page', () => {
	beforeEach(() => jest.clearAllMocks())

	/**
	 * Answer the run page's reads.
	 *
	 * @param {object} run The run the API serves.
	 * @return {void}
	 */
	function serve(run) {
		axios.get.mockImplementation((url) => {
			if (url === `/apps/openregister/api/flow-runs/${run.uuid}`) {
				return Promise.resolve({ data: run })
			}
			if (url === '/apps/openregister/api/flows/flow-1') {
				return Promise.resolve({ data: FLOW })
			}
			if (url === '/apps/openregister/api/flows/flow-1/versions') {
				return Promise.resolve({
					data: {
						results: [
							{ version: 1, semver: '1.0.0', status: 'published' },
						],
					},
				})
			}
			if (url === '/apps/openregister/api/flow/node-catalog') {
				return Promise.resolve({ data: CATALOG })
			}
			if (url.endsWith('/objects')) {
				return Promise.resolve({ data: { nodes: [] } })
			}
			return Promise.resolve({ data: { results: [] } })
		})
	}

	it('renders the run at its own address instead of handing over to the editor', async () => {
		serve(FAILED_RUN)
		const vm = makeVm(FlowRunDetail, { uuid: 'run-1' })
		await vm.load()
		expect(vm.$router.replace).not.toHaveBeenCalled()
		expect(vm.run.uuid).toBe('run-1')
		expect(vm.loading).toBe(false)
		expect(vm.versionLabel).toBe('v1.0.0')
		expect(vm.canvasRoute).toEqual({
			path: '/flows/flow-1',
			query: { run: 'run-1' },
		})
	})

	it('names the failed step in the alert', async () => {
		serve(FAILED_RUN)
		const vm = makeVm(FlowRunDetail, { uuid: 'run-1' })
		await vm.load()
		expect(vm.failure).toEqual({
			title: 'Stopped at step 3, Read objects',
			error: 'Subject "e2236ef7" no longer exists.',
		})
		expect(vm.steps.map((step) => step.reached)).toEqual([
			true,
			true,
			true,
			false,
		])
	})

	it('still shows the error of a failed run without a step log', async () => {
		serve({ ...FAILED_RUN, log: [] })
		const vm = makeVm(FlowRunDetail, { uuid: 'run-1' })
		await vm.load()
		expect(vm.failure).toEqual({
			title: 'This run failed',
			error: FAILED_RUN.error,
		})
	})

	it('says there is no such run and stays put', async () => {
		axios.get.mockRejectedValue({ response: { status: 404 } })
		const vm = makeVm(FlowRunDetail, { uuid: 'gone' })
		await vm.load()
		expect(vm.run).toBeNull()
		expect(vm.loading).toBe(false)
		expect(vm.$router.replace).not.toHaveBeenCalled()
		expect(vm.$router.push).not.toHaveBeenCalled()
	})

	it('offers retry on a finished run and opens the new run', async () => {
		serve(FAILED_RUN)
		axios.post.mockResolvedValue({ data: { uuid: 'run-2' } })
		const vm = makeVm(FlowRunDetail, { uuid: 'run-1' })
		await vm.load()
		expect(vm.canRetry).toBe(true)
		expect(vm.canResume).toBe(false)
		await vm.retry()
		expect(axios.post).toHaveBeenCalledWith(
			'/apps/openregister/api/flow-runs/run-1/retry',
			{},
		)
		expect(vm.$router.push).toHaveBeenCalledWith({
			name: 'flow-run-detail',
			params: { uuid: 'run-2' },
		})
	})

	it('offers resume only on a suspended run', async () => {
		serve({ ...FAILED_RUN, status: 'completed', error: null })
		const vm = makeVm(FlowRunDetail, { uuid: 'run-1' })
		await vm.load()
		expect(vm.canResume).toBe(false)
		vm.run = { ...vm.run, status: 'suspended' }
		expect(vm.canResume).toBe(true)
		expect(vm.canRetry).toBe(false)
	})

	it("shows a refusal in the server's own words", async () => {
		serve(FAILED_RUN)
		axios.post.mockRejectedValue({
			response: {
				data: {
					error: 'Only a finished run can be retried; this one is running.',
				},
			},
		})
		const vm = makeVm(FlowRunDetail, { uuid: 'run-1' })
		await vm.load()
		await vm.retry()
		expect(vm.actionError).toBe(
			'Only a finished run can be retried; this one is running.',
		)
		expect(vm.busy).toBe(false)
	})
})

describe('the flow overview', () => {
	beforeEach(() => jest.clearAllMocks())

	it('loads the flow, its versions, step names and runs', async () => {
		axios.get.mockImplementation((url, options) => {
			if (url === '/apps/openregister/api/flows/flow-1') {
				return Promise.resolve({ data: FLOW })
			}
			if (url === '/apps/openregister/api/flow/node-catalog') {
				return Promise.resolve({ data: CATALOG })
			}
			if (url === '/apps/openregister/api/flow-runs') {
				expect(options.params.flowId).toBe('flow-1')
				return Promise.resolve({
					data: {
						results: [
							FAILED_RUN,
							{
								uuid: 'run-0',
								status: 'completed',
								log: [{ durationMs: 8 }],
							},
						],
					},
				})
			}
			return Promise.resolve({
				data: {
					results: [{ version: 1, semver: '1.0.0', status: 'published' }],
				},
			})
		})
		const vm = makeVm(FlowOverview, { id: 'flow-1' })
		await vm.load()
		expect(vm.flow.name).toBe('Enquiry to lead')
		expect(vm.steps.map((node) => node.id)).toEqual([
			'trigger',
			'filter',
			'read',
			'write',
		])
		expect(vm.lastRun.uuid).toBe('run-1')
		expect(vm.counts).toMatchObject({ total: 2, completed: 1, failed: 1 })
		expect(vm.averageMs).toBe(10)
		expect(vm.stoppedAt(FAILED_RUN)).toBe(
			'Read objects: Subject "e2236ef7" no longer exists.',
		)
		expect(vm.versionLabel(1)).toBe('v1.0.0')
		vm.setFilter('failed')
		expect(vm.filteredRuns.map((run) => run.uuid)).toEqual(['run-1'])
	})

	it('says there is no such flow', async () => {
		axios.get.mockRejectedValue({ response: { status: 404 } })
		const vm = makeVm(FlowOverview, { id: 'gone' })
		await vm.load()
		expect(vm.flow).toBeNull()
		expect(vm.loading).toBe(false)
	})

	it('switches the flow off by sending only enabled', async () => {
		axios.put.mockResolvedValue({ data: { ...FLOW, enabled: false } })
		const vm = makeVm(FlowOverview, { id: 'flow-1' })
		vm.flow = { ...FLOW }
		await vm.toggleEnabled()
		expect(axios.put).toHaveBeenCalledWith(
			'/apps/openregister/api/flows/flow-1',
			{ enabled: false },
		)
		expect(vm.flow.enabled).toBe(false)
	})

	it('opens the run that run now started', async () => {
		axios.post.mockResolvedValue({ data: { uuid: 'run-9' } })
		const vm = makeVm(FlowOverview, { id: 'flow-1' })
		vm.flow = { ...FLOW }
		await vm.runNow()
		expect(vm.$router.push).toHaveBeenCalledWith({
			name: 'flow-run-detail',
			params: { uuid: 'run-9' },
		})
	})

	it('shows why run now was refused', async () => {
		axios.post.mockRejectedValue({
			response: {
				status: 403,
				data: {
					error: 'This flow has no owner, so it cannot run. Adopt it first.',
					verdict: 'no-owner',
				},
			},
		})
		const vm = makeVm(FlowOverview, { id: 'flow-1' })
		vm.flow = { ...FLOW, owner: null }
		await vm.runNow()
		expect(vm.actionError).toBe(
			'This flow has no owner, so it cannot run. Adopt it first.',
		)
	})

	it('adopts a flow that has no owner', async () => {
		axios.post.mockResolvedValue({ data: { ...FLOW, owner: 'admin' } })
		const vm = makeVm(FlowOverview, { id: 'flow-1' })
		vm.flow = { ...FLOW, owner: null }
		await vm.adopt()
		expect(axios.post).toHaveBeenCalledWith(
			'/apps/openregister/api/flows/flow-1/adopt',
			{},
		)
		expect(vm.flow.owner).toBe('admin')
	})
})

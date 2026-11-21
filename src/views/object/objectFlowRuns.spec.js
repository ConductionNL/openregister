/**
 * The flow runs that changed an object, from its audit rows.
 *
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
import { flowRunsOf } from './objectFlowRuns.js'

describe('flowRunsOf', () => {
	it('groups rows by run, newest run first, nodes in step order', () => {
		const rows = [
			{ flowRun: 'run-a', flowNode: 'enrich', flowStep: 2, created: '2026-11-01T10:02:00Z' },
			{ flowRun: 'run-a', flowNode: 'create', flowStep: 1, created: '2026-11-01T10:01:00Z' },
			{ flowRun: 'run-b', flowNode: 'update', flowStep: 3, created: '2026-11-03T09:00:00Z' },
			{ flowRun: 'run-a', flowNode: 'enrich', flowStep: 2, created: '2026-11-01T10:03:00Z' },
			{ flowRun: null, flowNode: null, created: '2026-11-04T00:00:00Z' },
			{ created: '2026-11-05T00:00:00Z' },
		]

		expect(flowRunsOf(rows)).toEqual([
			{ run: 'run-b', nodes: ['update'], lastAt: '2026-11-03T09:00:00Z', changes: 1 },
			{ run: 'run-a', nodes: ['create', 'enrich'], lastAt: '2026-11-01T10:03:00Z', changes: 3 },
		])
	})

	it('lists a run from its identifiers alone, without node or step', () => {
		expect(flowRunsOf([{ flowRun: ' pruned-run ' }])).toEqual([
			{ run: 'pruned-run', nodes: [], lastAt: '', changes: 1 },
		])
	})

	it('answers no runs for no rows or rows outside any run', () => {
		expect(flowRunsOf(undefined)).toEqual([])
		expect(flowRunsOf([{ flowRun: '' }, { action: 'update' }])).toEqual([])
	})
})

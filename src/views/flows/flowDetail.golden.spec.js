/**
 * Golden record of the flow and run derivations: trigger and node labels,
 * graph order, run counts, run filters, the failed step and step rows.
 *
 * @spec openspec/specs/flow-and-run-detail-pages/spec.md
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */

import {
	failedStep,
	matchesRunFilter,
	nodeLabel,
	orderedNodes,
	runCounts,
	statusLabel,
	statusVariant,
	stepRows,
	triggerLabel,
} from './flowDetail.js'

jest.mock('@nextcloud/l10n', () => ({
	__esModule: true,
	translate: jest.fn((app, text) => `[${text}]`),
	getCanonicalLocale: jest.fn(() => 'en-US'),
}))

const TRIGGERS = [
	'object.created',
	'object.updated',
	'object.deleted',
	'schedule',
	'manual',
	'test',
	'webhook',
	'',
	null,
	undefined,
	0,
	42,
	'toString',
	'constructor',
]

const NAMES = { 'openregister.save': 'Save object', 'core.log': 'Log' }

const NODES = [
	{ label: 'L', name: 'N', type: 'openregister.save', id: 'a' },
	{ label: '', name: 'N', type: 'openregister.save', id: 'a' },
	{ name: '', type: 'openregister.save', id: 'a' },
	{ type: 'core.unknown', id: 'a' },
	{ type: '', id: 'a' },
	{ id: 'only-id' },
	{ id: 0 },
	{},
	null,
	undefined,
]

const GRAPHS = [
	['nothing', undefined, undefined],
	['not lists', 'x', 5],
	['nodes without ids', [{ id: '' }, null, { id: 'b' }], []],
	[
		'from/to edges',
		[{ id: 'c' }, { id: 'b' }, { id: 'a' }],
		[
			{ from: 'a', to: 'b' },
			{ from: 'b', to: 'c' },
		],
	],
	[
		'source/target edges',
		[{ id: 'c' }, { id: 'b' }, { id: 'a' }],
		[
			{ source: 'a', target: 'c' },
			{ source: 'c', target: 'b' },
		],
	],
	[
		'mixed and broken edges',
		[{ id: 'a' }, { id: 'b' }, { id: 'c' }, { id: 'd' }],
		[
			{ from: 'a', target: 'c' },
			{ from: 'a' },
			{ to: 'b' },
			null,
			{ from: 'c', to: 'ghost' },
			{ from: 'c', to: 'd' },
		],
	],
	[
		'cycle with no start',
		[{ id: 'a' }, { id: 'b' }],
		[
			{ from: 'a', to: 'b' },
			{ from: 'b', to: 'a' },
		],
	],
	[
		'branch and join',
		[{ id: 'd' }, { id: 'c' }, { id: 'b' }, { id: 'a' }, { id: 'e' }],
		[
			{ from: 'a', to: 'b' },
			{ from: 'a', to: 'c' },
			{ from: 'b', to: 'd' },
			{ from: 'c', to: 'd' },
			{ from: 'd', to: 'a' },
		],
	],
	[
		'join without a back edge',
		[{ id: 'd' }, { id: 'c' }, { id: 'b' }, { id: 'a' }],
		[
			{ from: 'a', to: 'b' },
			{ from: 'a', to: 'c' },
			{ from: 'b', to: 'd' },
			{ from: 'c', to: 'd' },
		],
	],
	[
		'two starts',
		[{ id: 'x' }, { id: 'y' }, { id: 'z' }],
		[{ from: 'y', to: 'z' }],
	],
]

const STATUSES = [
	'completed',
	'failed',
	'dead_letter',
	'stopped',
	'queued',
	'running',
	'suspended',
	'awaiting_consent',
	'published',
	'',
	undefined,
]

const RUN_LISTS = [
	['nothing', undefined],
	['not a list', { status: 'completed' }],
	['empty', []],
	['every status', STATUSES.map((status) => ({ status }))],
	['null entries', [null, undefined, { status: 'failed' }]],
]

const FILTERS = ['all', 'failed', 'completed', 'running', 'other', '', undefined]

const ORDERED = [
	{ id: 'n1', type: 'openregister.save' },
	{ id: 'n2', type: 'core.log', label: 'Write log' },
	{ id: 'n3', type: 'core.unknown' },
]

const RUNS = [
	['no run', undefined],
	['no log', { error: 'run error' }],
	['log not a list', { log: 'x', error: 'run error' }],
	['nothing failed', { log: [{ transition: 'n1', status: 'completed' }] }],
	[
		'known node failed',
		{
			log: [
				{ transition: 'n1', status: 'completed', durationMs: 5 },
				{ transition: 'n2', status: 'failed', error: 'boom', durationMs: 7 },
			],
		},
	],
	[
		'last failure wins',
		{
			error: 'run error',
			log: [
				{ transition: 'n1', status: 'failed', error: 'first' },
				{ transition: 'n3', status: 'failed' },
			],
		},
	],
	[
		'unknown node failed',
		{
			log: [
				{
					transition: 'gone',
					type: 'core.log',
					status: 'failed',
					reason: 'r',
				},
			],
		},
	],
	[
		'failure without transition',
		{ log: [{ status: 'failed', type: 'core.log', error: 'e' }] },
	],
	[
		'loop with counts',
		{
			log: [
				null,
				{ status: 'completed' },
				{ transition: '', status: 'failed', error: 'empty' },
				{ transition: null, status: 'completed' },
				{ transition: 0, status: 'completed' },
				{
					transition: 'n1',
					status: 'completed',
					durationMs: 3,
					itemsIn: 1,
					itemsOut: 2,
				},
				{ transition: 'n1', status: 'running', input: { a: 1 }, output: 0 },
				{ transition: 'n1', status: '', durationMs: 4, reason: 'parked' },
				{ transition: 'n9', type: 'core.log', error: 'x', durationMs: 'no' },
			],
		},
	],
]

const ALL_STATUSES = [
	...STATUSES,
	'enabled',
	'draft',
	'deprecated',
	'archived',
	null,
	0,
	7,
	'toString',
	'constructor',
]

describe('flow detail golden', () => {
	it('names and colours statuses', () => {
		expect(
			ALL_STATUSES.map((status) => [
				status,
				statusVariant(status),
				statusLabel(status),
			]),
		).toMatchSnapshot()
	})

	it('labels triggers', () => {
		expect(
			TRIGGERS.map((trigger) => [trigger, triggerLabel(trigger)]),
		).toMatchSnapshot()
	})

	it('labels nodes', () => {
		expect(
			NODES.map((node) => [
				nodeLabel(node, NAMES),
				nodeLabel(node),
				nodeLabel(node, {}),
			]),
		).toMatchSnapshot()
	})

	it('orders nodes along the edges', () => {
		expect(
			GRAPHS.map(([name, nodes, edges]) => [
				name,
				orderedNodes(nodes, edges).map((node) => node.id),
			]),
		).toMatchSnapshot()
	})

	it('counts runs by outcome', () => {
		expect(
			RUN_LISTS.map(([name, runs]) => [name, runCounts(runs)]),
		).toMatchSnapshot()
	})

	it('filters runs', () => {
		const runs = [...STATUSES.map((status) => ({ status })), null, undefined]
		expect(
			FILTERS.map((filter) => [
				filter,
				runs.map((run) => matchesRunFilter(run, filter)),
			]),
		).toMatchSnapshot()
	})

	it('finds the failed step and the step rows', () => {
		expect(
			RUNS.map(([name, run]) => [
				name,
				failedStep(run, ORDERED, NAMES),
				failedStep(run),
				stepRows(run, ORDERED, NAMES),
				stepRows(run),
			]),
		).toMatchSnapshot()
	})
})

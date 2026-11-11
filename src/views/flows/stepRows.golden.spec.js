/**
 * Golden record of stepRows: the rows, with their keys in order, for runs
 * with no log, a broken log, repeated nodes, durations that are and are not
 * numbers, and nodes the run never reached.
 */
import { stepRows } from './flowDetail.js'

const ORDERED = [
	{ id: 'start', type: 'trigger' },
	{ id: 'fetch', type: 'http', name: 'Fetch it' },
	{ id: 'loop', type: 'loop' },
	{ id: 'end', type: 'end' },
	{ id: 'end', type: 'end' },
]

const NAMES = { trigger: 'Trigger', http: 'HTTP call', loop: 'Loop' }

const RUNS = {
	none: undefined,
	nullRun: null,
	noLog: {},
	logNotArray: { log: 'x' },
	emptyLog: { log: [] },
	full: {
		log: [
			null,
			{},
			{ transition: '' },
			{
				transition: 'start',
				type: 'trigger',
				status: 'done',
				durationMs: 5,
				itemsIn: 0,
				itemsOut: 1,
			},
			{
				transition: 'fetch',
				status: '',
				durationMs: 'slow',
				input: { a: 1 },
				output: 0,
				error: '',
				reason: 'timeout',
			},
			{
				transition: 'loop',
				status: 'running',
				durationMs: 3,
				itemsIn: null,
				error: 'first',
			},
			{
				transition: 'loop',
				status: 'done',
				durationMs: 4,
				itemsOut: 2,
				reason: 'second',
			},
			{ transition: 'loop', durationMs: 'n/a', input: false },
			{
				transition: 'ghost',
				type: 'mystery',
				status: 'failed',
				error: 'gone',
				reason: 'hidden',
			},
		],
	},
	durationsOnlyLater: {
		log: [
			{ transition: 'fetch', durationMs: null },
			{ transition: 'fetch', durationMs: 0 },
			{ transition: 'fetch', durationMs: 7 },
		],
	},
}

describe('stepRows (golden)', () => {
	it('matches the recorded rows', () => {
		const records = {}
		for (const [name, run] of Object.entries(RUNS)) {
			records[`${name}/ordered`] = JSON.stringify(
				stepRows(run, ORDERED, NAMES),
			)
			records[`${name}/defaults`] = JSON.stringify(stepRows(run))
		}
		expect(records).toMatchSnapshot()
	})
})

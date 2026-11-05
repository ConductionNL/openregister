/**
 * Golden record of the fetch-based actions of the views store: the request
 * each one sends, what it returns or throws, the state it leaves and what it
 * logs, for a good answer, an answer without a `view` wrapper, a failure with
 * a server error text, a failure without one and a network error.
 *
 * @spec openspec/specs/saved-search-views/spec.md
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
import { createPinia, setActivePinia } from 'pinia'
import { useViewsStore } from './views.js'

const VIEW = { id: 7, uuid: 'u-7', name: 'Seven', created: 'c', updated: 'u' }

const ACTIONS = [
	['fetchViews', []],
	['fetchView', [7]],
	['createView', [{ name: 'New', id: 1, uuid: 'x', created: 'c' }]],
	['updateView', [7, { ...VIEW, name: 'Changed' }]],
	['updateView', ['u-7', { name: 'By uuid' }]],
	['updateView', [99, { name: 'Unknown' }]],
	['deleteView', [7]],
	['deleteView', ['u-7']],
	['deleteView', [99]],
	['fetchKanbanBoard', [7]],
	['fetchKanbanBoard', [7, { _limit: 40, _offset: 20 }]],
	['fetchKanbanBoard', [7, { _limit: 0, _offset: null }]],
	['fetchKanbanBoard', [7, { _offset: 5 }]],
	['fetchKanbanBoard', [7, { _offset: 0 }]],
	['fetchKanbanBoard', [7, null]],
	['fetchCalendarObjects', [7, '2026-01-01', '2026-01-31T23:59:59Z']],
]

const ANSWERS = [
	[
		'wrapped',
		{
			ok: true,
			body: { view: { id: 7, name: 'Server' }, results: [{ id: 1 }] },
		},
	],
	['bare', { ok: true, body: { id: 8, name: 'Bare' } }],
	['empty', { ok: true, body: {} }],
	['error text', { ok: false, status: 400, body: { error: 'Bad view' } }],
	['no error text', { ok: false, status: 500, body: { message: 'x' } }],
	['no json', { ok: false, status: 502, body: null, badJson: true }],
	['network', { network: true }],
]

const ACTIVE = [
	['no active view', null],
	['active by id', { id: 7, uuid: 'other' }],
	['active by uuid', { id: 1, uuid: 'u-7' }],
	['other active', { id: 2, uuid: 'u-2' }],
]

/**
 * A fetch double answering once as the case says.
 *
 * @param {object} answer The scripted answer.
 * @return {Function} The double.
 */
function fakeFetch(answer) {
	return jest.fn(async () => {
		if (answer.network) {
			throw new TypeError('Failed to fetch')
		}
		return {
			ok: answer.ok,
			status: answer.status ?? 200,
			json: async () => {
				if (answer.badJson) {
					throw new SyntaxError('Unexpected token')
				}
				return answer.body
			},
		}
	})
}

/**
 * Run one action against a fresh store and record everything it does.
 *
 * @param {Array} action The action name and its arguments.
 * @param {Array} answer The answer name and the scripted answer.
 * @param {Array} active The active-view name and value.
 * @return {Promise<object>} The record.
 */
async function record([name, args], [, answer], [, activeView]) {
	setActivePinia(createPinia())
	const store = useViewsStore()
	store.viewsList = [
		{ id: 7, uuid: 'u-7', name: 'Seven' },
		{ id: 2, uuid: 'u-2', name: 'Two' },
	]
	store.activeView = activeView ? { ...activeView } : null
	store.error = 'previous'
	global.fetch = fakeFetch(answer)
	const logged = []
	jest.spyOn(console, 'error').mockImplementation((...parts) => {
		logged.push(
			parts.map((part) =>
				part instanceof Error ? `${part.name}: ${part.message}` : part,
			),
		)
	})
	const promise = store[name](...args)
	const loadingWhileRunning = store.loading
	const errorWhileRunning = store.error
	let outcome
	try {
		outcome = { returned: await promise }
	} catch (error) {
		outcome = { threw: `${error.name}: ${error.message}` }
	}
	console.error.mockRestore()
	return {
		calls: global.fetch.mock.calls,
		loadingWhileRunning,
		errorWhileRunning,
		outcome,
		state: {
			viewsList: JSON.stringify(store.viewsList),
			activeView: JSON.stringify(store.activeView),
			loading: store.loading,
			error: store.error,
		},
		logged,
	}
}

describe('views store requests golden', () => {
	for (const action of ACTIONS) {
		it(`${action[0]}(${JSON.stringify(action[1])})`, async () => {
			const records = []
			for (const answer of ANSWERS) {
				const actives = ['updateView', 'deleteView'].includes(action[0])
					? ACTIVE
					: ACTIVE.slice(0, 2)
				for (const active of actives) {
					records.push([
						answer[0],
						active[0],
						await record(action, answer, active),
					])
				}
			}
			expect(records).toMatchSnapshot()
		})
	}

	it('strips read-only fields before a save', () => {
		setActivePinia(createPinia())
		const store = useViewsStore()
		const input = { ...VIEW, configuration: { a: 1 } }
		expect([store.cleanViewForSave(input), input]).toMatchSnapshot()
	})
})

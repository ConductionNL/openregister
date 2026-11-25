/**
 * The query console runs a read-only query, shows the first list as a table with its
 * row count, truncation and duration, and downloads through the export route.
 *
 * @spec openspec/changes/operate-admin-query-console/specs/admin-query-console/spec.md
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
import axios from '@nextcloud/axios'
import QueryConsoleSection, { EXPORT_ROUTE, RUN_ROUTE } from './QueryConsoleSection.vue'
import { firstList, flatten, tableOf } from './queryConsoleTable.js'

jest.mock('@nextcloud/axios', () => ({
	__esModule: true,
	default: { post: jest.fn() },
}))
jest.mock('@nextcloud/l10n', () => ({
	t: (app, text, vars = {}) => text.replace(/{(\w+)}/g, (m, k) => (k in vars ? String(vars[k]) : m)),
}))
jest.mock('@nextcloud/router', () => ({ generateUrl: (url) => `/gen${url}` }))
jest.mock('vue-codemirror6', () => ({ name: 'CodeMirror' }))
jest.mock('@nextcloud/vue', () => ({ NcButton: {}, NcCheckboxRadioSwitch: {}, NcNoteCard: {} }))
jest.mock('@codemirror/lang-json', () => ({ json: () => ({}) }))
jest.mock('../../services/getTheme.js', () => ({ getTheme: () => 'light' }))

/**
 * A section instance over plain state, its computed values bound.
 *
 * @param {object} state Its state
 * @return {object} The instance
 */
function section(state = {}) {
	const vm = { ...QueryConsoleSection.data(), ...state }
	for (const [name, method] of Object.entries(QueryConsoleSection.methods)) {
		vm[name] = method.bind(vm)
	}
	for (const [name, getter] of Object.entries(QueryConsoleSection.computed)) {
		Object.defineProperty(vm, name, { get: getter.bind(vm) })
	}
	return vm
}

/** A connection result of `count` records, cut or not. */
function connection(count, truncated = false) {
	return {
		data: {
			objects: {
				edges: Array.from({ length: count }, (_, index) => ({ node: { id: index, status: { label: 'open' }, tags: ['a'] } })),
			},
		},
		extensions: { rows: count, truncated, durationMs: 42 },
	}
}

beforeEach(() => {
	jest.clearAllMocks()
})

describe('queryConsoleTable', () => {
	it('takes the first connection or plain list in data', () => {
		expect(firstList({ total: 3, items: [{ id: 1 }] })).toEqual([{ id: 1 }])
		expect(firstList({ objects: { edges: [{ node: { id: 2 } }] } })).toEqual([{ id: 2 }])
		expect(firstList({ total: 3 })).toBeNull()
		expect(firstList(null)).toBeNull()
	})

	it('flattens to dotted paths and writes a list as its JSON', () => {
		expect(flatten({ id: 1, status: { label: 'open' }, tags: ['a', 'b'] })).toEqual({ id: 1, 'status.label': 'open', tags: '["a","b"]' })
	})

	it('collects the columns in first-seen order', () => {
		const table = tableOf({ data: { items: [{ id: 1 }, { id: 2, title: 'x' }] } })
		expect(table.columns).toEqual(['id', 'title'])
		expect(table.rows).toEqual([{ id: 1 }, { id: 2, title: 'x' }])
		expect(tableOf({ data: { total: 1 } })).toBeNull()
	})
})

describe('QueryConsoleSection', () => {
	it('posts the query and parsed variables to the run route and renders a fixture result', async () => {
		axios.post.mockResolvedValue({ data: connection(2) })
		const vm = section({ query: '{ objects { edges { node { id } } } }', variablesText: '{"schema": "zaak"}' })

		await vm.run()

		expect(axios.post).toHaveBeenCalledWith(`/gen${RUN_ROUTE}`, { query: '{ objects { edges { node { id } } } }', variables: { schema: 'zaak' } })
		expect(vm.error).toBe('')
		expect(vm.table.columns).toEqual(['id', 'status.label', 'tags'])
		expect(vm.table.rows).toHaveLength(2)
		expect(vm.rowCount).toBe(2)
		expect(vm.durationMs).toBe(42)
		expect(vm.truncated).toBe(false)
		expect(vm.cell(vm.table.rows[0], 'status.label')).toBe('open')
		expect(vm.running).toBe(false)
	})

	it('shows the truncation notice at 1,000 rows', async () => {
		axios.post.mockResolvedValue({ data: connection(1000, true) })
		const vm = section({ query: '{ objects { edges { node { id } } } }' })

		await vm.run()

		expect(vm.rowCount).toBe(1000)
		expect(vm.truncated).toBe(true)
	})

	it('refuses variables that are not a JSON object without calling the server', async () => {
		const vm = section({ query: '{ a }', variablesText: '[1, 2' })

		await vm.run()
		expect(vm.error).toBe('The variables are not valid JSON.')

		vm.variablesText = '[1, 2]'
		await vm.run()
		expect(vm.error).toBe('The variables are not valid JSON.')
		expect(axios.post).not.toHaveBeenCalled()
	})

	it('names the server refusal of a mutation', async () => {
		axios.post.mockRejectedValue({ response: { status: 400, data: { error: 'READ_ONLY', message: 'Only queries can run here.' } } })
		const vm = section({ query: 'mutation { x }' })

		await vm.run()

		expect(vm.result).toBeNull()
		expect(vm.error).toBe('The query could not run: Only queries can run here.')
	})

	it('names a GraphQL error the run carried', async () => {
		axios.post.mockResolvedValue({ data: { data: null, errors: [{ message: 'Unknown field' }], extensions: { rows: 0, truncated: false, durationMs: 1 } } })
		const vm = section({ query: '{ nope }' })

		await vm.run()

		expect(vm.error).toBe('The query could not run: Unknown field')
		expect(vm.table).toBeNull()
	})

	it('calls the export route with format=csv and saves the file under its served name', async () => {
		const blob = { size: 3 }
		axios.post.mockResolvedValue({ data: blob, headers: { 'content-disposition': 'attachment; filename="query-20261125-1800.csv"' } })
		const vm = section({ query: '{ objects { edges { node { id } } } }', variablesText: '' })
		vm.save = jest.fn()

		await vm.download('csv')

		expect(axios.post).toHaveBeenCalledWith(`/gen${EXPORT_ROUTE}`, { query: '{ objects { edges { node { id } } } }', variables: {}, format: 'csv' }, { responseType: 'blob' })
		expect(vm.save).toHaveBeenCalledWith(blob, 'query-20261125-1800.csv')
		expect(vm.error).toBe('')
	})

	it('reads the refusal out of a blob error body on a download', async () => {
		const body = { text: () => Promise.resolve(JSON.stringify({ error: 'NO_LIST', message: 'The result has no list to download.' })) }
		axios.post.mockRejectedValue({ response: { status: 422, data: body } })
		const vm = section({ query: '{ total }' })
		vm.save = jest.fn()

		await vm.download('json')

		expect(vm.save).not.toHaveBeenCalled()
		expect(vm.error).toBe('The query could not run: The result has no list to download.')
	})
})

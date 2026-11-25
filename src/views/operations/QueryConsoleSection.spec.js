/**
 * QueryConsoleSection: runs a read-only GraphQL query, shows the first list
 * as a table with its row count, truncation and duration, and downloads it
 * through the export route.
 *
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 * @spec openspec/changes/operate-admin-query-console/specs/admin-query-console/spec.md#requirement-an-administrator-runs-a-bounded-read-only-query
 */
import axios from '@nextcloud/axios'
import { flushPromises, mount } from '@vue/test-utils'
import QueryConsoleSection, {
	firstList,
	flatten,
	parseVariables,
	STARTER_QUERY,
	tableOf,
} from './QueryConsoleSection.vue'

jest.mock('@codemirror/lang-json', () => ({
	json: jest.fn(() => ({ lang: 'json' })),
}))
jest.mock('vue-codemirror6', () => ({
	__esModule: true,
	default: { name: 'CodeMirror', props: ['modelValue'], render: () => null },
}))
jest.mock('../../services/getTheme.js', () => ({ getTheme: () => 'light' }))
jest.mock('@nextcloud/axios', () => ({
	__esModule: true,
	default: { post: jest.fn() },
}))
jest.mock('@nextcloud/router', () => ({ generateUrl: (path) => path }))
jest.mock('@nextcloud/l10n', () => ({
	t: (app, text, vars = {}) =>
		text.replace(/{(\w+)}/g, (match, key) => (key in vars ? vars[key] : match)),
}))
jest.mock('@nextcloud/vue', () => {
	const { h } = require('vue')
	const stub = (name) => ({
		name,
		inheritAttrs: false,
		render() {
			const slots = Object.values(this.$slots).map((s) => s({}))
			return h('div', { ...this.$attrs, class: `stub-${name}` }, slots)
		},
	})
	return {
		__esModule: true,
		NcButton: stub('NcButton'),
		NcCheckboxRadioSwitch: stub('NcCheckboxRadioSwitch'),
	}
})

/**
 * The template's translator, with placeholders filled.
 *
 * @param {string} app The app id.
 * @param {string} text The English text.
 * @param {object} [vars] The placeholder values.
 * @return {string} The text.
 */
function tMock(app, text, vars = {}) {
	return text.replace(/{(\w+)}/g, (match, key) =>
		key in vars ? vars[key] : match,
	)
}

const RESULT = {
	data: {
		objects: {
			edges: [
				{
					node: {
						title: 'Ada',
						owner: { name: 'Team A' },
						tags: ['a', 'b'],
					},
				},
				{ node: { title: 'Grace', status: 'open' } },
			],
		},
	},
	extensions: { rows: 2, truncated: false, durationMs: 41 },
}

/**
 * Mount the section.
 *
 * @return {object} The wrapper.
 */
function mountIt() {
	return mount(QueryConsoleSection, { global: { mocks: { t: tMock } } })
}

describe('QueryConsoleSection', () => {
	beforeEach(() => {
		axios.post.mockReset()
	})

	it('runs the query and renders the first list as a table with its row count and duration', async () => {
		axios.post.mockResolvedValue({ data: RESULT })
		const wrapper = mountIt()
		wrapper.vm.query = '{ objects { edges { node { title } } } }'
		wrapper.vm.variables = '{"first": 2}'

		await wrapper.vm.run()
		await flushPromises()

		expect(axios.post).toHaveBeenCalledWith(
			'/apps/openregister/api/operations/query',
			{
				query: '{ objects { edges { node { title } } } }',
				variables: { first: 2 },
			},
		)
		expect(wrapper.findAll('th').map((th) => th.text())).toEqual([
			'title',
			'owner.name',
			'tags',
			'status',
		])
		expect(
			wrapper
				.findAll('tbody tr')
				.map((tr) => tr.findAll('td').map((td) => td.text())),
		).toEqual([
			['Ada', 'Team A', '["a","b"]', ''],
			['Grace', '', '', 'open'],
		])
		expect(wrapper.text()).toContain('Rows: 2')
		expect(wrapper.text()).toContain('Duration: 41 ms')
		expect(wrapper.find('.queryConsole__truncated').exists()).toBe(false)
	})

	it('shows the truncation notice when the server cut the result at 1,000 rows', async () => {
		const rows = Array.from({ length: 1000 }, (_, index) => ({ id: index }))
		axios.post.mockResolvedValue({
			data: {
				data: { objects: rows },
				extensions: { rows: 1000, truncated: true, durationMs: 900 },
			},
		})
		const wrapper = mountIt()

		await wrapper.vm.run()
		await flushPromises()

		expect(wrapper.find('.queryConsole__truncated').text()).toBe(
			'The result was cut off at 1,000 rows.',
		)
		expect(wrapper.findAll('tbody tr')).toHaveLength(1000)
		expect(wrapper.text()).toContain('Rows: 1000')
	})

	it('downloads the list as CSV through the export route', async () => {
		const blob = new Blob(['title\nAda\n'], { type: 'text/csv' })
		axios.post.mockResolvedValue({
			data: blob,
			headers: {
				'content-disposition':
					'attachment; filename="query-20261125-0100.csv"',
			},
		})
		const wrapper = mountIt()
		const save = jest.spyOn(wrapper.vm, 'save').mockImplementation(() => {})

		await wrapper.vm.download('csv')

		expect(axios.post).toHaveBeenCalledWith(
			'/apps/openregister/api/operations/query/export',
			{ query: STARTER_QUERY, variables: {}, format: 'csv' },
			{ responseType: 'blob' },
		)
		expect(save).toHaveBeenCalledWith(blob, 'query-20261125-0100.csv')
		expect(wrapper.vm.error).toBeNull()
	})

	it("downloads JSON under a fallback name and shows a refused download's message", async () => {
		axios.post.mockResolvedValueOnce({ data: new Blob(['[]']), headers: {} })
		const wrapper = mountIt()
		const save = jest.spyOn(wrapper.vm, 'save').mockImplementation(() => {})

		await wrapper.vm.download('json')
		expect(save).toHaveBeenCalledWith(expect.any(Blob), 'query.json')
		expect(axios.post.mock.calls[0][1].format).toBe('json')

		const refusal = new Blob([
			JSON.stringify({
				error: 'NO_LIST',
				message: 'The result has no list to download.',
			}),
		])
		axios.post.mockRejectedValueOnce({
			response: { status: 422, data: refusal },
		})
		await wrapper.vm.download('csv')

		expect(wrapper.vm.error).toBe('The result has no list to download.')
	})

	it('shows the read-only refusal and the errors a query answers with', async () => {
		axios.post.mockRejectedValueOnce({
			response: {
				status: 400,
				data: { error: 'READ_ONLY', message: 'Only queries can run here.' },
			},
		})
		const wrapper = mountIt()

		await wrapper.vm.run()
		expect(wrapper.vm.error).toBe('Only queries can run here.')
		expect(wrapper.vm.result).toBeNull()

		axios.post.mockResolvedValueOnce({
			data: { errors: [{ message: 'Cannot query field "x".' }] },
		})
		await wrapper.vm.run()
		await flushPromises()

		expect(wrapper.vm.error).toBeNull()
		expect(wrapper.find('ul.queryConsole__error').text()).toBe(
			'Cannot query field "x".',
		)
		expect(wrapper.text()).toContain(
			'The result has no list to show as a table.',
		)
	})

	it('refuses variables that are not a JSON object without calling the server', async () => {
		const wrapper = mountIt()
		wrapper.vm.variables = '[1, 2]'

		await wrapper.vm.run()
		await wrapper.vm.download('csv')

		expect(axios.post).not.toHaveBeenCalled()
		expect(wrapper.vm.error).toBe('The variables are not valid JSON.')
	})

	it('says so when the server cannot be reached', async () => {
		axios.post.mockRejectedValueOnce(new Error('network'))
		const wrapper = mountIt()

		await wrapper.vm.run()
		expect(wrapper.vm.error).toBe('The query could not be run.')

		axios.post.mockRejectedValueOnce(new Error('network'))
		await wrapper.vm.download('csv')
		expect(wrapper.vm.error).toBe('The download failed.')
		expect(wrapper.vm.busy).toBe(false)
	})
})

describe('QueryConsoleSection helpers', () => {
	it("finds the first list: a connection's nodes or a plain list", () => {
		expect(firstList({ count: 3, items: [{ a: 1 }] })).toEqual([{ a: 1 }])
		expect(firstList({ objects: { edges: [{ node: { a: 1 } }, 'x'] } })).toEqual(
			[{ a: 1 }, 'x'],
		)
		expect(firstList({ count: 3 })).toBeNull()
		expect(firstList(null)).toBeNull()
	})

	it('flattens a row to dotted columns with lists as JSON', () => {
		expect(flatten({ a: { b: 1, c: [1] }, d: null })).toEqual({
			'a.b': 1,
			'a.c': '[1]',
			d: null,
		})
	})

	it('builds no table for a result without a list', () => {
		expect(tableOf({ data: { __typename: 'Query' } })).toBeNull()
		expect(tableOf({ data: { items: [{ a: 1 }, { b: 2 }] } })).toEqual({
			columns: ['a', 'b'],
			rows: [{ a: 1 }, { b: 2 }],
		})
	})

	it('reads empty variables as none and refuses a list or broken JSON', () => {
		expect(parseVariables('')).toEqual({ ok: true, value: {} })
		expect(parseVariables('{"id": 1}')).toEqual({ ok: true, value: { id: 1 } })
		expect(parseVariables('[1]').ok).toBe(false)
		expect(parseVariables('{').ok).toBe(false)
	})
})

/**
 * QueryConsoleSection: runs a read-only query, shows the first list as a
 * table with its row count, truncation and duration, and downloads it.
 *
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 * @spec openspec/changes/operate-admin-query-console/specs/admin-query-console/spec.md#requirement-an-administrator-runs-a-bounded-read-only-query
 */
import { getCurrentUser } from '@nextcloud/auth'
import axios from '@nextcloud/axios'
import { flushPromises, mount } from '@vue/test-utils'
import QueryConsoleSection, { flatten, tableOf } from './QueryConsoleSection.vue'

jest.mock('@nextcloud/auth', () => ({ getCurrentUser: jest.fn() }))
jest.mock('@nextcloud/axios', () => ({ __esModule: true, default: { post: jest.fn() } }))
jest.mock('@nextcloud/router', () => ({ generateUrl: (path) => path }))
jest.mock('@nextcloud/l10n', () => ({
	t: (app, text, vars) => text.replace(/\{(\w+)\}/g, (m, key) => (vars && key in vars ? String(vars[key]) : m)),
}))
jest.mock('@codemirror/lang-json', () => ({ json: jest.fn(() => ({})) }))
jest.mock('vue-codemirror6', () => ({
	__esModule: true,
	default: { name: 'CodeMirror', props: ['modelValue'], render: () => null },
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
		NcNoteCard: stub('NcNoteCard'),
	}
})

const FIXTURE = {
	data: {
		objects: {
			edges: [
				{ node: { id: 'a1', title: 'First', owner: { name: 'Anna' }, tags: ['x', 'y'] } },
				{ node: { id: 'b2', title: 'Second', owner: { name: 'Bram' }, tags: [] } },
			],
		},
	},
	extensions: { rows: 2, truncated: false, durationMs: 41 },
}

/**
 * Mount the section as an administrator, with a query typed in.
 *
 * @return {object} The wrapper.
 */
function mountIt() {
	const wrapper = mount(QueryConsoleSection)
	wrapper.vm.query = '{ objects { edges { node { id title } } } }'
	return wrapper
}

describe('QueryConsoleSection', () => {
	beforeEach(() => {
		jest.clearAllMocks()
		getCurrentUser.mockReturnValue({ uid: 'admin', isAdmin: true })
	})

	it('renders nothing for someone who is not an administrator', () => {
		getCurrentUser.mockReturnValue({ uid: 'jan', isAdmin: false })
		const wrapper = mount(QueryConsoleSection)

		expect(wrapper.find('section').exists()).toBe(false)
	})

	it('runs the query with its variables and renders the first list as a table', async () => {
		axios.post.mockResolvedValue({ data: FIXTURE })
		const wrapper = mountIt()
		wrapper.vm.variablesText = '{"register": "5"}'

		await wrapper.vm.run()
		await flushPromises()

		expect(axios.post).toHaveBeenCalledWith('/apps/openregister/api/operations/query', {
			query: '{ objects { edges { node { id title } } } }',
			variables: { register: '5' },
		})
		const headers = wrapper.findAll('thead th').map((cell) => cell.text())
		expect(headers).toEqual(['id', 'title', 'owner.name', 'tags'])
		const first = wrapper.findAll('tbody tr')[0].findAll('td').map((cell) => cell.text())
		expect(first).toEqual(['a1', 'First', 'Anna', '["x","y"]'])
		expect(wrapper.find('.queryConsoleSummary').text()).toBe('Rows: 2. Took 41 ms.')
		expect(wrapper.find('.stub-NcNoteCard').exists()).toBe(false)
	})

	it('says the result was cut off at 1,000 rows', async () => {
		const edges = Array.from({ length: 1000 }, (unused, index) => ({ node: { id: String(index) } }))
		axios.post.mockResolvedValue({ data: { data: { objects: { edges } }, extensions: { rows: 1000, truncated: true, durationMs: 900 } } })
		const wrapper = mountIt()

		await wrapper.vm.run()
		await flushPromises()

		expect(wrapper.find('.queryConsoleSummary').text()).toBe('Rows: 1000. Took 900 ms.')
		expect(wrapper.find('.stub-NcNoteCard').text()).toContain('The result was cut off at 1,000 rows.')
		expect(wrapper.findAll('tbody tr')).toHaveLength(1000)
	})

	it('downloads CSV through the export route with format=csv', async () => {
		axios.post.mockResolvedValue({ data: new Blob(['id\n1']) })
		const wrapper = mountIt()

		await wrapper.vm.download('csv')

		expect(axios.post).toHaveBeenCalledWith(
			'/apps/openregister/api/operations/query/export',
			{ query: '{ objects { edges { node { id title } } } }', variables: {}, format: 'csv' },
			{ responseType: 'blob' },
		)
		expect(wrapper.vm.error).toBe('')
	})

	it('shows the server\'s refusal of a mutation', async () => {
		axios.post.mockRejectedValue({ response: { status: 400, data: { error: 'READ_ONLY', message: 'The console only runs queries.' } } })
		const wrapper = mountIt()

		await wrapper.vm.run()
		await flushPromises()

		expect(wrapper.find('.queryConsoleError').text()).toBe('The console only runs queries.')
		expect(wrapper.vm.result).toBeNull()
	})

	it('reads the refusal of a download out of its blob body', async () => {
		const body = new Blob([JSON.stringify({ error: 'NO_LIST', message: 'The result has no list to download.' })])
		axios.post.mockRejectedValue({ response: { status: 422, data: body } })
		const wrapper = mountIt()

		await wrapper.vm.download('json')

		expect(wrapper.vm.error).toBe('The result has no list to download.')
	})

	it('refuses variables that are not a JSON object without calling the server', async () => {
		const wrapper = mountIt()
		wrapper.vm.variablesText = '[1, 2]'

		await wrapper.vm.run()

		expect(axios.post).not.toHaveBeenCalled()
		expect(wrapper.vm.error).toBe('The variables must be a JSON object.')
	})

	it('shows the raw JSON behind the toggle', async () => {
		axios.post.mockResolvedValue({ data: FIXTURE })
		const wrapper = mountIt()
		await wrapper.vm.run()
		wrapper.vm.showRaw = true
		await flushPromises()

		expect(JSON.parse(wrapper.find('.queryConsoleRaw').text())).toEqual(FIXTURE)
		expect(wrapper.find('table').exists()).toBe(false)
	})

	it('flattens and picks the first list the way the export does', () => {
		expect(flatten({ a: { b: 1 }, c: [1] })).toEqual({ 'a.b': 1, c: '[1]' })
		expect(tableOf({ data: { count: 3, items: [{ id: 1 }] } })).toEqual({ columns: ['id'], rows: [{ id: 1 }] })
		expect(tableOf({ data: { count: 3 } })).toBeNull()
	})
})

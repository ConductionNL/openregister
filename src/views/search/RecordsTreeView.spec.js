/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * RecordsTreeView, exercised through its options object against a plain
 * context: the roots load first, a branch loads when it opens and only then,
 * choosing a record opens it, and going back reopens what was open.
 *
 * @spec openspec/changes/records-tree-view/specs/saved-search-views/spec.md#requirement-a-saved-view-can-present-hierarchical-records-as-a-tree
 */
import axios from '@nextcloud/axios'
import RecordsTreeView from './RecordsTreeView.vue'
import { rememberBranches } from '../../services/recordsTree.js'

jest.mock('@nextcloud/axios', () => ({ __esModule: true, default: { get: jest.fn() } }))
jest.mock('@nextcloud/router', () => ({
	__esModule: true,
	generateUrl: (url, params = {}) => '/index.php' + url.replace(/\{(\w+)\}/g, (m, key) => String(params[key])),
}))
jest.mock('@nextcloud/l10n', () => ({
	__esModule: true,
	translate: (_app, text) => text,
	translatePlural: (_app, one, many, count) => (count === 1 ? one : many),
}))
jest.mock(
	'@nextcloud/vue',
	() => new Proxy({ __esModule: true }, { get: (target, prop) => (prop in target ? target[prop] : { name: String(prop), render: () => null }) }),
)

const SCHEMA = { id: 7, configuration: { 'x-openregister-hierarchy': { parent: 'parentDepartment' } } }

/**
 * A plain context with the component's props, data, computed getters and methods.
 *
 * @param {object} props The props.
 * @return {object} The context.
 */
function context(props = {}) {
	const ctx = { register: 3, schema: SCHEMA, config: { labelField: 'name' }, viewKey: 'v1', ...props, $emit: jest.fn() }
	Object.assign(ctx, RecordsTreeView.data.call(ctx))
	for (const [name, fn] of Object.entries(RecordsTreeView.methods)) {
		ctx[name] = fn.bind(ctx)
	}
	for (const [name, getter] of Object.entries(RecordsTreeView.computed)) {
		Object.defineProperty(ctx, name, { get: () => getter.call(ctx) })
	}
	return ctx
}

/**
 * Answer the list call with these records for the given params.
 *
 * @param {Function} pick (params) => records.
 * @return {void}
 */
function answer(pick) {
	axios.get.mockImplementation((url, { params }) => Promise.resolve({ data: { results: pick(params) } }))
}

const ROOTS = [{ '@self': { id: 'd1', childCount: 1 }, name: 'Gemeente' }]
const CHILDREN = [{ '@self': { id: 'd2', childCount: 0 }, name: 'Sociaal domein' }]

beforeEach(() => {
	jest.clearAllMocks()
	rememberBranches('v1', [])
})

describe('RecordsTreeView', () => {
	it('loads the roots, one level, with child counts', async () => {
		answer(() => ROOTS)
		const ctx = context()

		await ctx.start()

		expect(axios.get).toHaveBeenCalledTimes(1)
		expect(axios.get).toHaveBeenCalledWith('/index.php/apps/openregister/api/objects/3/7', {
			params: { _roots: true, _childCount: true, _limit: 50, _page: 1 },
		})
		expect(ctx.nodes.map((node) => node.label)).toEqual(['Gemeente'])
	})

	it('loads a branch when it opens, and not again when it opens a second time', async () => {
		answer((params) => (params._roots ? ROOTS : CHILDREN))
		const ctx = context()
		await ctx.start()

		await ctx.onExpandedIds(['d1'])
		expect(axios.get).toHaveBeenLastCalledWith('/index.php/apps/openregister/api/objects/3/7', {
			params: { parentDepartment: 'd1', _childCount: true, _limit: 50, _page: 1 },
		})
		expect(ctx.nodes[0].children.map((node) => node.label)).toEqual(['Sociaal domein'])

		await ctx.onExpandedIds([])
		await ctx.onExpandedIds(['d1'])
		expect(axios.get).toHaveBeenCalledTimes(2)
	})

	it('opens the chosen record and ignores a placeholder', () => {
		const ctx = context()
		ctx.onSelect({ id: '__loading__:d1', placeholder: true })
		expect(ctx.$emit).not.toHaveBeenCalled()

		ctx.onSelect({ id: 'd1', object: ROOTS[0] })
		expect(ctx.$emit).toHaveBeenCalledWith('open', ROOTS[0])
		expect(ctx.selectedId).toBe('d1')
	})

	it('reopens the branches that were open when going back to the view', async () => {
		answer((params) => (params._roots ? ROOTS : CHILDREN))
		const first = context()
		await first.start()
		await first.onExpandedIds(['d1'])

		const back = context()
		await back.start()

		expect(back.expandedIds).toEqual(['d1'])
		expect(back.nodes[0].children.map((node) => node.label)).toEqual(['Sociaal domein'])
	})

	it('asks nothing for a schema without a hierarchy', async () => {
		const ctx = context({ schema: { id: 7, configuration: {} } })
		await ctx.start()
		expect(ctx.parentField).toBeNull()
		expect(axios.get).not.toHaveBeenCalled()
	})

	it('says so when the roots cannot be loaded', async () => {
		axios.get.mockRejectedValue(new Error('500'))
		const ctx = context()
		await ctx.start()
		expect(ctx.error).toBe('Part of the tree could not be loaded.')
		expect(ctx.loadingRoots).toBe(false)
	})
})

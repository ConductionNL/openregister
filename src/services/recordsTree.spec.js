/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The records tree helpers: the parent property, one level per request, the
 * nodes a page becomes, and putting a loaded branch in place.
 *
 * @spec openspec/changes/records-tree-view/specs/saved-search-views/spec.md#requirement-a-saved-view-can-present-hierarchical-records-as-a-tree
 */
import {
	branchParams,
	findNode,
	hierarchyParentField,
	needsBranch,
	newlyOpened,
	PLACEHOLDER_PREFIX,
	rememberBranches,
	rememberedBranches,
	rootParams,
	treeNodes,
	withChildren,
} from './recordsTree.js'

const DEPARTMENTS = [
	{ '@self': { id: 'd1', childCount: 2 }, name: 'Gemeente', code: 'GEM' },
	{ '@self': { id: 'd2', childCount: 0, parentHidden: true }, name: 'Losse afdeling', code: '' },
	{ '@self': {}, name: 'zonder id' },
]

describe('hierarchyParentField', () => {
	it('reads parent, and parentField as the older spelling', () => {
		expect(hierarchyParentField({ configuration: { 'x-openregister-hierarchy': { parent: 'parentDepartment' } } })).toBe('parentDepartment')
		expect(hierarchyParentField({ configuration: { 'x-openregister-hierarchy': { parentField: 'parent' } } })).toBe('parent')
	})

	it('is null for a schema without a hierarchy or with an empty one', () => {
		expect(hierarchyParentField({ configuration: {} })).toBeNull()
		expect(hierarchyParentField({ configuration: { 'x-openregister-hierarchy': { parent: '  ' } } })).toBeNull()
		expect(hierarchyParentField(null)).toBeNull()
	})
})

describe('one level per request', () => {
	it('asks for the roots with child counts, paged', () => {
		expect(rootParams()).toEqual({ _roots: true, _childCount: true, _limit: 50, _page: 1 })
		expect(rootParams(3)._page).toBe(3)
	})

	it('asks for a branch by its parent uuid, paged', () => {
		expect(branchParams('parentDepartment', 'd1', 2)).toEqual({ parentDepartment: 'd1', _childCount: true, _limit: 50, _page: 2 })
	})
})

describe('treeNodes', () => {
	it('labels each record, counts its children and marks a hidden parent', () => {
		const nodes = treeNodes(DEPARTMENTS, { labelField: 'name', fields: ['code'] })

		expect(nodes.map((node) => node.id)).toEqual(['d1', 'd2'])
		expect(nodes[0]).toMatchObject({ label: 'Gemeente', childCount: 2, parentHidden: false, details: [{ field: 'code', value: 'GEM' }] })
		expect(nodes[1]).toMatchObject({ label: 'Losse afdeling', childCount: 0, parentHidden: true, details: [], children: [] })
	})

	it('gives a record with children one placeholder child until its branch loads', () => {
		const [node] = treeNodes(DEPARTMENTS, { labelField: 'name' })

		expect(node.children).toEqual([{ id: PLACEHOLDER_PREFIX + 'd1', label: '', placeholder: true, children: [] }])
		expect(needsBranch(node)).toBe(true)
	})

	it('falls back to the record name, then its id, when the label field is empty', () => {
		const nodes = treeNodes([{ '@self': { id: 'x1', name: 'Uit self' } }, { '@self': { id: 'x2' } }], { labelField: 'title' })
		expect(nodes.map((node) => node.label)).toEqual(['Uit self', 'x2'])
	})
})

describe('putting a branch in place', () => {
	const tree = treeNodes(DEPARTMENTS, { labelField: 'name' })
	const children = treeNodes([{ '@self': { id: 'd3', childCount: 1 }, name: 'Sociaal domein' }], { labelField: 'name' })

	it('replaces the placeholder of the opened node and leaves the rest shared', () => {
		const next = withChildren(tree, 'd1', children)

		expect(next[0].children.map((node) => node.id)).toEqual(['d3'])
		expect(needsBranch(next[0])).toBe(false)
		expect(next[1]).toBe(tree[1])
		expect(tree[0].children[0].placeholder).toBe(true)
	})

	it('finds and fills a node at the second level', () => {
		const level2 = withChildren(tree, 'd1', children)
		const grand = treeNodes([{ '@self': { id: 'd4' }, name: 'Wmo' }], { labelField: 'name' })
		const next = withChildren(level2, 'd3', grand)

		expect(findNode(next, 'd4').label).toBe('Wmo')
		expect(findNode(next, 'nope')).toBeNull()
	})
})

describe('open branches', () => {
	it('names the ids opened since the last change', () => {
		expect(newlyOpened(['a'], ['a', 'b', 'c'])).toEqual(['b', 'c'])
		expect(newlyOpened(['a', 'b'], ['a'])).toEqual([])
	})

	it('remembers the open branches of a view without placeholders', () => {
		rememberBranches('view-1', ['d1', PLACEHOLDER_PREFIX + 'd1', 'd3'])
		expect(rememberedBranches('view-1')).toEqual(['d1', 'd3'])
		expect(rememberedBranches('other')).toEqual([])
	})
})

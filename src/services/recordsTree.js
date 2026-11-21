/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The records tree of a saved view (records-tree-view D-2, D-4, D-5): which
 * property is the parent, what one level of the tree asks the list for, and
 * how a page of records becomes tree nodes. A node with children shows a
 * single placeholder child until its branch is loaded, so the tree can be
 * opened without loading more than one level at a time.
 *
 * @spec openspec/changes/records-tree-view/specs/saved-search-views/spec.md#requirement-a-saved-view-can-present-hierarchical-records-as-a-tree
 */

/** The schema annotation that declares the hierarchy. */
export const HIERARCHY_ANNOTATION = 'x-openregister-hierarchy'

/** The id prefix of the placeholder child shown before a branch is loaded. */
export const PLACEHOLDER_PREFIX = '__loading__:'

/** How many records one level asks for at a time. */
export const LEVEL_LIMIT = 50

/** The open branches of each view, kept while the app is open so going back restores them. */
const openBranches = new Map()

/**
 * The parent property a schema's hierarchy declares, or null.
 *
 * @param {object|null} schema The schema.
 * @return {string|null} The property name.
 * @spec openspec/changes/records-tree-view/specs/saved-search-views/spec.md#requirement-a-saved-view-can-present-hierarchical-records-as-a-tree
 */
export function hierarchyParentField(schema) {
	const block = schema?.configuration?.[HIERARCHY_ANNOTATION]
	if (!block || typeof block !== 'object') {
		return null
	}
	const parent = String(block.parent ?? block.parentField ?? '').trim()
	return parent === '' ? null : parent
}

/**
 * The list parameters for the top level: the roots, with readable records
 * whose parent the viewer cannot read, each with its child count.
 *
 * @param {number} page The page, from 1.
 * @return {object} The query parameters.
 * @spec openspec/changes/records-tree-view/specs/saved-search-views/spec.md#requirement-a-saved-view-can-present-hierarchical-records-as-a-tree
 */
export function rootParams(page = 1) {
	return { _roots: true, _childCount: true, _limit: LEVEL_LIMIT, _page: page }
}

/**
 * The list parameters for one branch: the children of a record.
 *
 * @param {string} parentField The parent property.
 * @param {string} parentId The parent record's uuid.
 * @param {number} page The page, from 1.
 * @return {object} The query parameters.
 * @spec openspec/changes/records-tree-view/specs/saved-search-views/spec.md#requirement-a-saved-view-can-present-hierarchical-records-as-a-tree
 */
export function branchParams(parentField, parentId, page = 1) {
	return { [parentField]: parentId, _childCount: true, _limit: LEVEL_LIMIT, _page: page }
}

/**
 * The uuid of a listed record.
 *
 * @param {object} object The record.
 * @return {string} The uuid, or '' when it names none.
 */
export function recordId(object) {
	return String(object?.['@self']?.id ?? object?.id ?? '')
}

/**
 * A page of records as tree nodes.
 *
 * The label is the view's label field, else the record's name. A record with
 * children gets one placeholder child, so the node can be opened; opening it
 * loads the branch. A record whose parent the viewer cannot read is marked.
 *
 * @param {Array<object>} objects The records.
 * @param {object} config The view's tree presentation: `labelField` and optional `fields`.
 * @return {Array<object>} The nodes.
 * @spec openspec/changes/records-tree-view/specs/saved-search-views/spec.md#requirement-a-saved-view-can-present-hierarchical-records-as-a-tree
 */
export function treeNodes(objects, config = {}) {
	const labelField = config.labelField || ''
	const fields = Array.isArray(config.fields) ? config.fields : []

	return (objects || [])
		.map((object) => {
			const id = recordId(object)
			const childCount = Number(object?.['@self']?.childCount ?? 0) || 0
			const label = labelOf(object, labelField) || id
			return {
				id,
				label,
				childCount,
				parentHidden: object?.['@self']?.parentHidden === true,
				details: fields
					.filter((field) => object?.[field] !== undefined && object?.[field] !== null && object?.[field] !== '')
					.map((field) => ({ field, value: displayValue(object[field]) })),
				object,
				children: childCount > 0 ? [placeholder(id)] : [],
			}
		})
		.filter((node) => node.id !== '')
}

/**
 * Whether a node is the placeholder of a branch not loaded yet.
 *
 * @param {object} node The node.
 * @return {boolean}
 */
export function isPlaceholder(node) {
	return String(node?.id ?? '').startsWith(PLACEHOLDER_PREFIX)
}

/**
 * Whether a node's branch is still to be loaded.
 *
 * @param {object} node The node.
 * @return {boolean}
 */
export function needsBranch(node) {
	return Array.isArray(node?.children) && node.children.length === 1 && isPlaceholder(node.children[0])
}

/**
 * The tree with one node's children put in place.
 *
 * @param {Array<object>} nodes The tree.
 * @param {string} id The node whose branch was loaded.
 * @param {Array<object>} children Its children.
 * @return {Array<object>} A new tree; the other nodes are shared.
 * @spec openspec/changes/records-tree-view/specs/saved-search-views/spec.md#requirement-a-saved-view-can-present-hierarchical-records-as-a-tree
 */
export function withChildren(nodes, id, children) {
	return (nodes || []).map((node) => {
		if (node.id === id) {
			return { ...node, children }
		}
		if (Array.isArray(node.children) && node.children.length > 0 && !needsBranch(node)) {
			const next = withChildren(node.children, id, children)
			return next === node.children ? node : { ...node, children: next }
		}
		return node
	})
}

/**
 * A node of the tree by id, at any depth.
 *
 * @param {Array<object>} nodes The tree.
 * @param {string} id The id.
 * @return {object|null} The node.
 */
export function findNode(nodes, id) {
	for (const node of nodes || []) {
		if (node.id === id) {
			return node
		}
		const found = findNode(node.children, id)
		if (found) {
			return found
		}
	}
	return null
}

/**
 * The ids opened since the last change of the open set.
 *
 * @param {Array<string>} before The open ids before.
 * @param {Array<string>} after The open ids now.
 * @return {Array<string>} The newly opened ids.
 */
export function newlyOpened(before, after) {
	const was = new Set(before || [])
	return (after || []).filter((id) => !was.has(id))
}

/**
 * The branches that were open on a view, for going back to it.
 *
 * @param {string} viewKey The view.
 * @return {Array<string>} The open ids, in the order they were opened.
 * @spec openspec/changes/records-tree-view/specs/saved-search-views/spec.md#requirement-a-saved-view-can-present-hierarchical-records-as-a-tree
 */
export function rememberedBranches(viewKey) {
	return [...(openBranches.get(String(viewKey)) || [])]
}

/**
 * Remember the open branches of a view.
 *
 * @param {string} viewKey The view.
 * @param {Array<string>} ids The open ids.
 * @return {void}
 */
export function rememberBranches(viewKey, ids) {
	openBranches.set(String(viewKey), [...(ids || [])].filter((id) => !String(id).startsWith(PLACEHOLDER_PREFIX)))
}

/**
 * The placeholder child of a branch not loaded yet.
 *
 * @param {string} parentId The parent node.
 * @return {object} The placeholder.
 */
function placeholder(parentId) {
	return { id: PLACEHOLDER_PREFIX + parentId, label: '', placeholder: true, children: [] }
}

/**
 * The label of a record.
 *
 * @param {object} object The record.
 * @param {string} labelField The view's label field.
 * @return {string} The label.
 */
function labelOf(object, labelField) {
	const value = labelField !== '' ? object?.[labelField] : undefined
	const label = value ?? object?.['@self']?.name ?? object?.name ?? ''
	return displayValue(label)
}

/**
 * A value as text.
 *
 * @param {string|number|boolean|object|Array|null|undefined} value The value.
 * @return {string} The text.
 */
function displayValue(value) {
	if (value === undefined || value === null) {
		return ''
	}
	if (Array.isArray(value)) {
		return value.map(displayValue).join(', ')
	}
	if (typeof value === 'object') {
		return String(value.name ?? value.title ?? value.id ?? JSON.stringify(value))
	}
	return String(value)
}

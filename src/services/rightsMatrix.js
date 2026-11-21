/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The department by role matrix of a schema, as the Rights tab edits it.
 *
 * The tab edits a grid of field values by role groups with an action set per
 * cell; the schema stores `authorization.matrix.rows`. These functions turn
 * one into the other. They are pure so the one thing the tab must never get
 * wrong, that a save changes `authorization.matrix` and nothing else, can be
 * tested without mounting anything.
 *
 * A row the grid cannot show (a `where`, an `until`, a `values` list, any key
 * besides `value`, `group` and `actions`) is kept exactly as declared and
 * written back first, so editing the grid never drops a condition the grid
 * does not know about.
 */

import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

/**
 * The actions the grid offers. The engine's verbs a department matrix is
 * declared with; `handle` resolves through custom-verb voting and falls back
 * to `update`.
 */
export const MATRIX_ACTIONS = ['read', 'update', 'handle', 'delete']

/** The row value that resolves to the user's own values at query time. */
export const SELF_VALUE = '$self'

const GRID_ROW_KEYS = ['value', 'group', 'actions']

/**
 * Whether the grid can show a row without losing anything it declares.
 *
 * @param {unknown} row A declared row.
 * @return {boolean} True for a plain `{value, group, actions}` row.
 * @spec openspec/changes/rbac-department-role-matrix/specs/rbac-scopes/spec.md
 */
export function isGridRow(row) {
	if (row === null || typeof row !== 'object' || Array.isArray(row)) {
		return false
	}
	if (Object.keys(row).some((key) => !GRID_ROW_KEYS.includes(key))) {
		return false
	}
	return typeof row.value === 'string'
		&& typeof row.group === 'string'
		&& Array.isArray(row.actions)
}

/**
 * The key of one grid cell.
 *
 * @param {string} value The field value.
 * @param {string} group The role group.
 * @return {string} The cell key.
 * @spec openspec/changes/rbac-department-role-matrix/specs/rbac-scopes/spec.md
 */
export function cellKey(value, group) {
	return JSON.stringify([value, group])
}

/**
 * The grid for a schema: its values, groups, actions, cells and the rows it
 * keeps untouched.
 *
 * Values are the matrix field's `enum` when the schema declares one, followed
 * by any other value the rows already use; groups are those the rows name.
 *
 * @param {object} schema The schema.
 * @return {{field: string, userSource: object|null, values: string[], groups: string[], actions: string[], cells: object, kept: Array}} The grid.
 * @spec openspec/changes/rbac-department-role-matrix/specs/rbac-scopes/spec.md
 */
export function gridFromSchema(schema) {
	const matrix = schema?.authorization?.matrix ?? {}
	const field = typeof matrix.field === 'string' ? matrix.field : ''
	const declared = Array.isArray(matrix.rows) ? matrix.rows : []
	const enumValues = schema?.properties?.[field]?.enum
	const values = Array.isArray(enumValues) ? enumValues.map(String) : []
	const groups = []
	const actions = [...MATRIX_ACTIONS]
	const cells = {}
	const kept = []

	for (const row of declared) {
		if (!isGridRow(row)) {
			kept.push(row)
			continue
		}
		if (!values.includes(row.value)) {
			values.push(row.value)
		}
		if (!groups.includes(row.group)) {
			groups.push(row.group)
		}
		const key = cellKey(row.value, row.group)
		const cell = cells[key] ?? []
		for (const action of row.actions.map(String)) {
			if (!actions.includes(action)) {
				actions.push(action)
			}
			if (!cell.includes(action)) {
				cell.push(action)
			}
		}
		cells[key] = cell
	}

	return {
		field,
		userSource: matrix.userSource ?? null,
		values,
		groups,
		actions,
		cells,
		kept,
	}
}

/**
 * The rows a grid writes: the kept rows first, as declared, then one row per
 * cell that grants anything, in value then group order.
 *
 * @param {object} grid The grid from gridFromSchema(), as edited.
 * @return {{rows: Array, cellsByRow: Array<string|null>}} The rows, and per row index the cell it came from.
 * @spec openspec/changes/rbac-department-role-matrix/specs/rbac-scopes/spec.md
 */
export function rowsFromGrid(grid) {
	const rows = [...grid.kept]
	const cellsByRow = grid.kept.map(() => null)
	for (const value of grid.values) {
		for (const group of grid.groups) {
			const key = cellKey(value, group)
			const granted = grid.actions.filter((action) => (grid.cells[key] ?? []).includes(action))
			if (granted.length > 0) {
				rows.push({ value, group, actions: granted })
				cellsByRow.push(key)
			}
		}
	}
	return { rows, cellsByRow }
}

/**
 * The schema to save: the schema as loaded with only `authorization.matrix`
 * replaced. Every other key of the matrix (`rowsFrom`, `fileVerbs`, ...) and
 * of the authorization block stays as it was.
 *
 * @param {object} schema The schema as loaded.
 * @param {object} grid The edited grid.
 * @return {object} The schema to hand to schemaStore.saveSchema().
 * @spec openspec/changes/rbac-department-role-matrix/specs/rbac-scopes/spec.md
 */
export function schemaWithMatrix(schema, grid) {
	const authorization = schema?.authorization ?? {}
	const matrix = {
		...(authorization.matrix ?? {}),
		field: grid.field,
		rows: rowsFromGrid(grid).rows,
	}
	if (grid.userSource !== null) {
		matrix.userSource = grid.userSource
	}
	return {
		...schema,
		authorization: { ...authorization, matrix },
	}
}

/**
 * The text of a refused save, from whatever the save threw.
 *
 * @param {unknown} error The thrown value.
 * @return {string} The refusal, or an empty string when there is none to show.
 * @spec openspec/changes/rbac-department-role-matrix/specs/rbac-scopes/spec.md
 */
export function refusalText(error) {
	const data = error?.response?.data
	const text = data?.error ?? data?.message ?? error?.message ?? ''
	return typeof text === 'string' ? text : ''
}

/**
 * The save-time refusal split over the cells it names.
 *
 * The validator names every row by index ("Row 3 grants no action."); a
 * finding on a row the grid wrote lands on that row's cell, every other
 * finding stays general.
 *
 * @param {string} text The refusal.
 * @param {Array<string|null>} cellsByRow Per row index, the cell it came from.
 * @return {{general: string[], cells: object}} The findings.
 * @spec openspec/changes/rbac-department-role-matrix/specs/rbac-scopes/spec.md
 */
export function findingsByCell(text, cellsByRow) {
	const body = text.replace(/^authorization\.matrix:\s*/, '')
	const parts = body.split(/\s+(?=Row \d+ )/).map((part) => part.trim()).filter(Boolean)
	const general = []
	const cells = {}
	for (const part of parts) {
		const match = part.match(/^Row (\d+) /)
		const key = match ? cellsByRow[Number(match[1])] : null
		if (key) {
			cells[key] = [...(cells[key] ?? []), part]
		} else {
			general.push(part)
		}
	}
	return { general, cells }
}

/**
 * The scopes a schema's matrix compiles to for one user.
 *
 * @param {string|number} schema Schema id, uuid or slug.
 * @param {string} user The user's id.
 * @return {Promise<object>} `{schema, user, values, scopes}`.
 * @spec openspec/changes/rbac-department-role-matrix/specs/rbac-scopes/spec.md
 */
export async function fetchMatrixPreview(schema, user) {
	const url = generateUrl('/apps/openregister/api/schemas/{schema}/authorization/matrix/preview', { schema })
	const { data } = await axios.get(url, { params: { user } })
	return data
}

/**
 * The Nextcloud users matching a search, as picker options.
 *
 * @param {string} search The search text.
 * @return {Promise<Array<{id: string, label: string}>>} The users.
 * @spec openspec/changes/rbac-department-role-matrix/specs/rbac-scopes/spec.md
 */
export async function searchUsers(search) {
	const { data } = await axios.get('/ocs/v2.php/cloud/users/details', {
		params: { search, limit: 20, format: 'json' },
		headers: { 'OCS-APIRequest': 'true' },
	})
	const users = data?.ocs?.data?.users ?? {}
	return Object.values(users).map((user) => ({
		id: user.id,
		label: user.displayname ? `${user.displayname} (${user.id})` : user.id,
	}))
}

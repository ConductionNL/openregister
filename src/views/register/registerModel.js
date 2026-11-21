/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

/**
 * The register model (`GET /api/registers/{id}/model`) turned into what the
 * diagram draws: canvas nodes and edges, the rows of the table beside it, the
 * per-user saved positions and the SVG download.
 *
 * Everything here is PURE. The canvas never mutates positions (CnGraphCanvas
 * reports changes and the host decides), so the host keeps one positions map,
 * feeds it back through {@link toCanvasNodes} and persists it on drag end.
 *
 * @spec openspec/changes/modelling-schema-diagram/specs/schema-diagram/spec.md#requirement-the-register-page-draws-the-model
 */
import { layoutFlowNodes } from '@conduction/nextcloud-vue'

/**
 * Above this many schemas a box shows its title only (design: Risks).
 *
 * @type {number}
 */
export const COMPACT_ABOVE = 50

/**
 * Width of a box in the SVG download, in pixels.
 *
 * @type {number}
 */
export const SVG_NODE_WIDTH = 220

/**
 * Height of one text line in the SVG download, in pixels.
 *
 * @type {number}
 */
export const SVG_LINE_HEIGHT = 18

/**
 * The per-user preference key the positions of one register are kept under.
 *
 * The preferences endpoint keeps only `[a-z0-9-]` and 64 characters, so the
 * key is reduced the same way here: a key the server would rewrite is a key
 * the next read would not find.
 *
 * @spec openspec/changes/modelling-schema-diagram/specs/schema-diagram/spec.md#requirement-the-register-page-draws-the-model
 * @param {string|number} registerId The register id, uuid or slug.
 * @return {string} The preference key.
 */
export function modelLayoutKey(registerId) {
	return `register-model-layout-${String(registerId ?? '').toLowerCase()}`
		.replace(/[^a-z0-9-]/g, '')
		.slice(0, 64)
}

/**
 * Read a stored positions value; anything unreadable is no positions.
 *
 * @spec openspec/changes/modelling-schema-diagram/specs/schema-diagram/spec.md#requirement-the-register-page-draws-the-model
 * @param {string|null} value The stored JSON.
 * @return {Object<string, {x: number, y: number}>} Positions by node id.
 */
export function parsePositions(value) {
	if (typeof value !== 'string' || value === '') {
		return {}
	}

	let parsed
	try {
		parsed = JSON.parse(value)
	} catch {
		return {}
	}

	const positions = {}
	if (parsed === null || typeof parsed !== 'object' || Array.isArray(parsed)) {
		return positions
	}

	for (const [id, point] of Object.entries(parsed)) {
		if (point !== null && Number.isFinite(point?.x) && Number.isFinite(point?.y)) {
			positions[id] = { x: point.x, y: point.y }
		}
	}

	return positions
}

/**
 * The id of the stand-in node a broken link points at.
 *
 * @param {number} index The edge's index in the model.
 * @return {string} The node id.
 */
function danglingId(index) {
	return `dangling-${index}`
}

/**
 * The `1` or `n` marker of an edge.
 *
 * @param {object} edge A model edge.
 * @return {string} `n` for many, `1` otherwise.
 */
export function cardinalityMarker(edge) {
	return edge?.cardinality === 'many' ? 'n' : '1'
}

/**
 * Canvas nodes: one per schema, one stand-in per broken link, laid out left to
 * right on first open, with every saved position winning over the layout.
 *
 * @spec openspec/changes/modelling-schema-diagram/specs/schema-diagram/spec.md#requirement-the-register-page-draws-the-model
 * @param {{nodes: Array<object>, edges: Array<object>}} model The register model.
 * @param {Object<string, {x: number, y: number}>} positions Saved positions by node id.
 * @return {Array<object>} Nodes in Vue Flow's shape.
 */
export function toCanvasNodes(model, positions = {}) {
	const schemaNodes = Array.isArray(model?.nodes) ? model.nodes : []
	const edges = Array.isArray(model?.edges) ? model.edges : []
	const compact = schemaNodes.length > COMPACT_ABOVE

	const nodes = schemaNodes.map((node) => ({
		id: String(node.id),
		data: {
			kind: 'schema',
			title: node.title ?? null,
			slug: node.slug ?? null,
			external: node.external === true,
			readable: node.readable !== false,
			properties: compact ? [] : (Array.isArray(node.properties) ? node.properties : []),
		},
	}))

	edges.forEach((edge, index) => {
		if (edge.dangling === true) {
			nodes.push({
				id: danglingId(index),
				data: { kind: 'dangling', title: null, target: edge.target ?? null, properties: [] },
			})
		}
	})

	// The layout layers from its start ids: the schemas no link points at.
	// Without them every box lands in the first column.
	const lines = toCanvasEdges(model)
	const pointedAt = new Set(lines.map((line) => line.target))
	const startIds = nodes.map((node) => node.id).filter((id) => !pointedAt.has(id))
	const laidOut = layoutFlowNodes(nodes, lines, startIds)

	return laidOut.map((node) => {
		const saved = positions[node.id]
		const position = saved ? { x: saved.x, y: saved.y } : node.position
		return { id: node.id, data: node.data, position }
	})
}

/**
 * Canvas edges: one per link, a broken one ending at its stand-in node.
 *
 * @spec openspec/changes/modelling-schema-diagram/specs/schema-diagram/spec.md#requirement-the-register-page-draws-the-model
 * @param {{edges: Array<object>}} model The register model.
 * @return {Array<object>} Edges in Vue Flow's shape.
 */
export function toCanvasEdges(model) {
	const edges = Array.isArray(model?.edges) ? model.edges : []

	return edges.map((edge, index) => ({
		id: `edge-${index}`,
		source: String(edge.from),
		target: edge.dangling === true ? danglingId(index) : String(edge.to),
		data: {
			property: edge.property,
			inverse: edge.inverse ?? null,
			marker: cardinalityMarker(edge),
			declared: edge.source === 'declared',
			dangling: edge.dangling === true,
			animated: false,
		},
	}))
}

/**
 * The table rows: one per link, in model order.
 *
 * `to` is null for a broken link and for a schema the reader may not open;
 * `toState` says which, so the view can name it.
 *
 * @spec openspec/changes/modelling-schema-diagram/specs/schema-diagram/spec.md#requirement-the-register-page-draws-the-model
 * @param {{nodes: Array<object>, edges: Array<object>}} model The register model.
 * @return {Array<object>} The rows.
 */
export function edgeRows(model) {
	const byId = new Map((Array.isArray(model?.nodes) ? model.nodes : []).map((node) => [String(node.id), node]))
	const edges = Array.isArray(model?.edges) ? model.edges : []

	return edges.map((edge, index) => {
		const from = byId.get(String(edge.from)) ?? null
		const to = edge.dangling === true ? null : (byId.get(String(edge.to)) ?? null)
		let toState = 'schema'
		if (edge.dangling === true) {
			toState = 'dangling'
		} else if (to === null || to.readable === false) {
			toState = 'hidden'
		} else if (to.external === true) {
			toState = 'external'
		}

		return {
			id: `edge-${index}`,
			fromId: String(edge.from),
			fromTitle: from?.title ?? String(edge.from),
			property: edge.property,
			marker: cardinalityMarker(edge),
			inverse: edge.inverse ?? null,
			toId: to !== null && to.readable !== false ? String(to.id) : null,
			toTitle: to !== null && to.readable !== false ? (to.title ?? to.slug ?? String(to.id)) : null,
			toState,
			target: edge.target ?? null,
		}
	})
}

/**
 * Apply Vue Flow position changes to the positions map.
 *
 * Every position frame moves the box (the canvas never does), and the map is
 * worth saving only when a drag ended: `save` is true then.
 *
 * @spec openspec/changes/modelling-schema-diagram/specs/schema-diagram/spec.md#requirement-the-register-page-draws-the-model
 * @param {Object<string, {x: number, y: number}>} positions The current positions.
 * @param {Array<object>} changes Vue Flow's node changes.
 * @return {{positions: Object<string, {x: number, y: number}>, save: boolean}} The new map, and whether to persist it.
 */
export function applyPositionChanges(positions, changes) {
	const next = { ...positions }
	let save = false
	for (const change of (Array.isArray(changes) ? changes : [])) {
		if (change?.type !== 'position') {
			continue
		}

		if (change.position && Number.isFinite(change.position.x) && Number.isFinite(change.position.y)) {
			next[change.id] = { x: change.position.x, y: change.position.y }
		}

		if (change.dragging === false) {
			save = true
		}
	}

	return { positions: next, save }
}

/**
 * Escape text for an SVG text node or attribute.
 *
 * @param {string|number|null|undefined} value The text.
 * @return {string} The escaped text.
 */
function xml(value) {
	return String(value ?? '')
		.replace(/&/g, '&amp;')
		.replace(/</g, '&lt;')
		.replace(/>/g, '&gt;')
		.replace(/"/g, '&quot;')
		.replace(/'/g, '&apos;')
}

/**
 * The height of a box in the SVG download.
 *
 * @param {object} node A canvas node.
 * @return {number} The height.
 */
function boxHeight(node) {
	return SVG_LINE_HEIGHT * (2 + node.data.properties.length)
}

/**
 * The drawn model as a standalone SVG document.
 *
 * Built from the nodes and edges as they are placed, not by serialising the
 * page: Vue Flow draws its boxes as HTML, so the rendered canvas holds only the
 * lines. Colours are fixed (black on white) because a downloaded file has no
 * theme to read CSS variables from.
 *
 * @spec openspec/changes/modelling-schema-diagram/specs/schema-diagram/spec.md#requirement-the-register-page-draws-the-model
 * @param {Array<object>} nodes Canvas nodes, with positions.
 * @param {Array<object>} edges Canvas edges.
 * @param {{hidden: string, dangling: string}} labels The names of a schema the reader cannot open and of a broken link.
 * @return {string} The SVG document.
 */
export function modelToSvg(nodes, edges, labels) {
	const placed = new Map(nodes.map((node) => [node.id, node]))
	const width = SVG_NODE_WIDTH
	let maxX = 0
	let maxY = 0
	const parts = []

	for (const edge of edges) {
		const from = placed.get(edge.source)
		const to = placed.get(edge.target)
		if (!from || !to) {
			continue
		}

		const x1 = from.position.x + width
		const y1 = from.position.y + SVG_LINE_HEIGHT
		const x2 = to.position.x
		const y2 = to.position.y + SVG_LINE_HEIGHT
		const dash = edge.data.dangling ? ' stroke-dasharray="4 4"' : ''
		parts.push(`<line x1="${x1}" y1="${y1}" x2="${x2}" y2="${y2}" stroke="#555"${dash}/>`)
		parts.push(`<text x="${(x1 + x2) / 2}" y="${(y1 + y2) / 2 - 4}" font-size="11" text-anchor="middle">${xml(edge.data.property)} (${xml(edge.data.marker)})</text>`)
	}

	for (const node of nodes) {
		const { x, y } = node.position
		const height = boxHeight(node)
		maxX = Math.max(maxX, x + width)
		maxY = Math.max(maxY, y + height)
		let title = node.data.title ?? node.data.slug
		if (node.data.kind === 'dangling') {
			title = `${labels.dangling}: ${node.data.target ?? ''}`
		} else if (node.data.readable === false) {
			title = labels.hidden
		}

		const dash = node.data.external || node.data.kind === 'dangling' ? ' stroke-dasharray="4 4"' : ''
		parts.push(`<g transform="translate(${x},${y})">`)
		parts.push(`<rect width="${width}" height="${height}" rx="6" fill="#fff" stroke="#222"${dash}/>`)
		parts.push(`<text x="8" y="${SVG_LINE_HEIGHT}" font-size="13" font-weight="bold">${xml(title)}</text>`)
		node.data.properties.forEach((property, index) => {
			const type = property.type ? `: ${property.type}` : ''
			parts.push(`<text x="8" y="${SVG_LINE_HEIGHT * (index + 2)}" font-size="11">${xml(property.name)}${xml(type)}</text>`)
		})
		parts.push('</g>')
	}

	const minX = Math.min(0, ...nodes.map((node) => node.position.x))
	const minY = Math.min(0, ...nodes.map((node) => node.position.y))
	const viewWidth = maxX - minX + 20
	const viewHeight = maxY - minY + 20

	return `<svg xmlns="http://www.w3.org/2000/svg" viewBox="${minX - 10} ${minY - 10} ${viewWidth} ${viewHeight}" width="${viewWidth}" height="${viewHeight}" font-family="sans-serif">`
		+ `<rect x="${minX - 10}" y="${minY - 10}" width="${viewWidth}" height="${viewHeight}" fill="#fff"/>`
		+ parts.join('')
		+ '</svg>'
}

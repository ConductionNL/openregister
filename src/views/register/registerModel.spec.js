/**
 * The register model turned into the diagram: nodes laid out with saved
 * positions winning, edges with their markers, broken links drawn rather than
 * hidden, the table rows, the per-user layout key and the SVG download.
 *
 * Runs against the library's REAL layout, so a change there that piles boxes
 * onto one point shows up here.
 *
 * @spec openspec/changes/modelling-schema-diagram/specs/schema-diagram/spec.md#requirement-the-register-page-draws-the-model
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
import {
	applyPositionChanges,
	COMPACT_ABOVE,
	edgeRows,
	modelLayoutKey,
	modelToSvg,
	parsePositions,
	toCanvasEdges,
	toCanvasNodes,
} from './registerModel.js'

jest.mock('@conduction/nextcloud-vue', () => {
	const path = require('path')
	return jest.requireActual(path.resolve(__dirname, '../../../node_modules/@conduction/nextcloud-vue/src/composables/flowGraphLayout.js'))
})

/** zaak -> document (many, inverse zaak), zaak -> contact in another register the reader may not open, one broken ref. */
const MODEL = {
	nodes: [
		{ id: '1', slug: 'zaak', title: 'Zaak', external: false, readable: true, properties: [{ name: 'documenten', type: 'array' }, { name: 'titel', type: 'string' }] },
		{ id: '2', slug: 'document', title: 'Document', external: false, readable: true, properties: [{ name: 'zaak', type: 'string' }] },
		{ id: '9', slug: null, title: null, external: true, readable: false, properties: [] },
	],
	edges: [
		{ from: '1', to: '2', property: 'documenten', cardinality: 'many', inverse: 'zaak', source: 'property', target: 'document', dangling: false },
		{ from: '1', to: '9', property: 'contact', cardinality: 'one', inverse: null, source: 'declared', target: 'contact', dangling: false },
		{ from: '2', to: null, property: 'bijlage', cardinality: 'one', inverse: null, source: 'property', target: 'deleted-schema', dangling: true },
	],
}

describe('registerModel', () => {
	it('lays the schemas out left to right, one cell each, with a stand-in node for the broken link', () => {
		const nodes = toCanvasNodes(MODEL)

		expect(nodes.map((node) => node.id)).toEqual(['1', '2', '9', 'dangling-2'])
		const points = nodes.map((node) => `${node.position.x},${node.position.y}`)
		expect(new Set(points).size).toBe(4)
		const byId = Object.fromEntries(nodes.map((node) => [node.id, node]))
		expect(byId['2'].position.x).toBeGreaterThan(byId['1'].position.x)
		expect(byId['dangling-2'].data).toEqual({ kind: 'dangling', title: null, target: 'deleted-schema', properties: [] })
		expect(byId['9'].data.readable).toBe(false)
		expect(byId['1'].data.properties).toHaveLength(2)
	})

	it('keeps a saved position over the layout, and lays out the rest', () => {
		const laidOut = toCanvasNodes(MODEL)
		const nodes = toCanvasNodes(MODEL, { 2: { x: 999, y: -5 } })

		expect(nodes.find((node) => node.id === '2').position).toEqual({ x: 999, y: -5 })
		expect(nodes.find((node) => node.id === '1').position).toEqual(laidOut.find((node) => node.id === '1').position)
	})

	it('draws titles only above the compact threshold', () => {
		const many = { nodes: Array.from({ length: COMPACT_ABOVE + 1 }, (_, i) => ({ id: String(i), title: `S${i}`, properties: [{ name: 'p', type: 'string' }] })), edges: [] }

		expect(toCanvasNodes(many).every((node) => node.data.properties.length === 0)).toBe(true)
		expect(toCanvasNodes({ nodes: many.nodes.slice(0, COMPACT_ABOVE), edges: [] })[0].data.properties).toHaveLength(1)
	})

	it('turns links into edges with a 1 or n marker, a broken one ending at its stand-in', () => {
		expect(toCanvasEdges(MODEL)).toEqual([
			{ id: 'edge-0', source: '1', target: '2', data: { property: 'documenten', inverse: 'zaak', marker: 'n', declared: false, dangling: false, animated: false } },
			{ id: 'edge-1', source: '1', target: '9', data: { property: 'contact', inverse: null, marker: '1', declared: true, dangling: false, animated: false } },
			{ id: 'edge-2', source: '2', target: 'dangling-2', data: { property: 'bijlage', inverse: null, marker: '1', declared: false, dangling: true, animated: false } },
		])
	})

	it('a register without links draws its schemas with no lines', () => {
		const model = { nodes: MODEL.nodes.slice(0, 2), edges: [] }

		expect(toCanvasNodes(model)).toHaveLength(2)
		expect(toCanvasEdges(model)).toEqual([])
		expect(edgeRows(model)).toEqual([])
	})

	it('names each table row, and never names a schema the reader may not open', () => {
		const rows = edgeRows(MODEL)

		expect(rows[0]).toMatchObject({ fromTitle: 'Zaak', property: 'documenten', marker: 'n', inverse: 'zaak', toId: '2', toTitle: 'Document', toState: 'schema' })
		expect(rows[1]).toMatchObject({ fromTitle: 'Zaak', toId: null, toTitle: null, toState: 'hidden' })
		expect(rows[2]).toMatchObject({ fromTitle: 'Document', toId: null, toState: 'dangling', target: 'deleted-schema' })
	})

	it('marks a readable schema in another register as external', () => {
		const model = {
			nodes: [MODEL.nodes[0], { id: '5', slug: 'klant', title: 'Klant', external: true, readable: true, properties: [] }],
			edges: [{ from: '1', to: '5', property: 'klant', cardinality: 'one', dangling: false }],
		}

		expect(edgeRows(model)[0]).toMatchObject({ toId: '5', toTitle: 'Klant', toState: 'external' })
	})

	it('moves a box on every frame and saves only when the drag ends', () => {
		const during = applyPositionChanges({ 1: { x: 0, y: 0 } }, [{ type: 'position', id: '2', position: { x: 10, y: 20 }, dragging: true }, { type: 'select', id: '1' }])
		expect(during).toEqual({ positions: { 1: { x: 0, y: 0 }, 2: { x: 10, y: 20 } }, save: false })

		const end = applyPositionChanges(during.positions, [{ type: 'position', id: '2', dragging: false }])
		expect(end).toEqual({ positions: { 1: { x: 0, y: 0 }, 2: { x: 10, y: 20 } }, save: true })
	})

	it('keys the saved layout per register, in the charset the preferences endpoint keeps', () => {
		expect(modelLayoutKey(7)).toBe('register-model-layout-7')
		expect(modelLayoutKey('Zaken_Reg!')).toBe('register-model-layout-zakenreg')
		expect(modelLayoutKey('x'.repeat(80))).toHaveLength(64)
	})

	it('reads stored positions, dropping anything that is not a point', () => {
		expect(parsePositions('{"1":{"x":1,"y":2},"2":{"x":"a","y":2},"3":null}')).toEqual({ 1: { x: 1, y: 2 } })
		expect(parsePositions('not json')).toEqual({})
		expect(parsePositions('[1,2]')).toEqual({})
		expect(parsePositions(null)).toEqual({})
	})

	it('downloads an SVG document with every box, line and label, escaped', () => {
		const model = {
			nodes: [{ id: '1', title: 'A & <B>', properties: [{ name: 'x', type: 'string' }] }, MODEL.nodes[2]],
			edges: [{ from: '1', to: '9', property: 'p"q', cardinality: 'many', dangling: false }, { from: '1', to: null, property: 'gone', cardinality: 'one', target: 'old', dangling: true }],
		}
		const svg = modelToSvg(toCanvasNodes(model), toCanvasEdges(model), { hidden: 'A schema you cannot open', dangling: 'Broken link' })

		expect(svg.startsWith('<svg')).toBe(true)
		expect(svg.endsWith('</svg>')).toBe(true)
		expect(svg).toContain('A &amp; &lt;B&gt;')
		expect(svg).toContain('x: string')
		expect(svg).toContain('A schema you cannot open')
		expect(svg).toContain('Broken link: old')
		expect(svg).toContain('p&quot;q (n)')
		expect((svg.match(/<line /g) || []).length).toBe(2)
		expect((svg.match(/<g /g) || []).length).toBe(3)
	})
})

import axios from '@nextcloud/axios'
/**
 * The register's model drawn as a diagram: what it fetches, what it hands the
 * canvas, the table beside it, opening a schema from a box, a line or a row,
 * keeping a moved box per user, and the SVG download.
 *
 * @spec openspec/changes/modelling-schema-diagram/specs/schema-diagram/spec.md#requirement-the-register-page-draws-the-model
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
import { flushPromises, mount } from '@vue/test-utils'
import RegisterModelDiagram from './RegisterModelDiagram.vue'

jest.mock('@nextcloud/l10n', () => ({ translate: (app, s) => s, translatePlural: (app, s) => s }))
jest.mock('@nextcloud/axios', () => ({ __esModule: true, default: { get: jest.fn(), put: jest.fn() } }))
jest.mock('@nextcloud/router', () => ({ generateUrl: (path) => `/index.php${path}` }))
jest.mock('@conduction/nextcloud-vue', () => {
	const path = require('path')
	const layout = jest.requireActual(path.resolve(__dirname, '../../../node_modules/@conduction/nextcloud-vue/src/composables/flowGraphLayout.js'))
	const { h } = require('vue')
	return {
		...layout,
		CnGraphCanvas: {
			name: 'CnGraphCanvas',
			props: ['nodes', 'edges', 'fitMaxZoom'],
			emits: ['nodes-change', 'node-select', 'node-activate', 'edge-select', 'edge-label-click'],
			render() {
				return h('x-CnGraphCanvas', [
					...this.nodes.map((node) => h('x-node', { 'data-id': node.id }, this.$slots.node({ node }))),
					...this.edges.map((edge) => h('x-edge', { 'data-id': edge.id }, this.$slots['edge-label']({ edge }))),
				])
			},
		},
	}
})
jest.mock('@nextcloud/vue', () => {
	const { h } = require('vue')
	return {
		NcLoadingIcon: { name: 'NcLoadingIcon', render: () => h('x-NcLoadingIcon') },
		NcNoteCard: { name: 'NcNoteCard', render() { return h('x-NcNoteCard', {}, this.$slots.default ? this.$slots.default() : []) } },
		NcButton: { name: 'NcButton', emits: ['click'], render() { return h('button', { onClick: () => this.$emit('click') }, this.$slots.default ? this.$slots.default() : []) } },
	}
})
jest.mock('vue-material-design-icons/Download.vue', () => ({ __esModule: true, default: { name: 'Download', render: () => null } }))

const MODEL = {
	nodes: [
		{ id: '1', slug: 'zaak', title: 'Zaak', external: false, readable: true, properties: [{ name: 'documenten', type: 'array' }] },
		{ id: '2', slug: 'document', title: 'Document', external: false, readable: true, properties: [] },
		{ id: '9', slug: null, title: null, external: true, readable: false, properties: [] },
	],
	edges: [
		{ from: '1', to: '2', property: 'documenten', cardinality: 'many', inverse: 'zaak', source: 'property', target: 'document', dangling: false },
		{ from: '1', to: '9', property: 'contact', cardinality: 'one', inverse: null, source: 'property', target: 'contact', dangling: false },
		{ from: '2', to: null, property: 'bijlage', cardinality: 'one', inverse: null, source: 'property', target: 'gone', dangling: true },
	],
}

/**
 * Install a router double the way the app's router is: as a global property.
 *
 * @param {object} router The double.
 * @return {object} The mount's `global` option.
 */
function withRouter(router) {
	return { plugins: [{ install: (app) => { app.config.globalProperties.$router = router } }] }
}

/**
 * Mount the diagram for register 7 with a router double.
 *
 * @param {object} layout The stored preference response.
 * @return {Promise<{wrapper: object, router: object}>} The mounted diagram and its router.
 */
async function mountDiagram(layout = { value: null }) {
	axios.get.mockImplementation((url) => Promise.resolve({ data: url.includes('/preferences/') ? layout : MODEL }))
	const router = { push: jest.fn(), resolve: (to) => ({ href: `#${to}` }) }
	const wrapper = mount(RegisterModelDiagram, { props: { registerId: 7 }, global: withRouter(router) })
	await flushPromises()
	return { wrapper, router }
}

describe('RegisterModelDiagram', () => {
	beforeEach(() => {
		jest.clearAllMocks()
		axios.put.mockResolvedValue({ data: {} })
	})

	it('fetches the model and the saved layout of its register', async () => {
		await mountDiagram()

		expect(axios.get).toHaveBeenCalledWith('/index.php/apps/openregister/api/registers/7/model')
		expect(axios.get).toHaveBeenCalledWith('/index.php/apps/openregister/api/preferences/register-model-layout-7')
	})

	it('draws every schema, a nameless box for one the reader cannot open, and the broken link', async () => {
		const { wrapper } = await mountDiagram()
		const boxes = wrapper.findAll('x-node').map((node) => node.text())

		expect(boxes[0]).toContain('Zaak')
		expect(boxes[0]).toContain('documenten: array')
		expect(boxes[2]).toBe('A schema you cannot open')
		expect(boxes[3]).toBe('Broken link: gone')
		expect(wrapper.findAll('x-edge').map((edge) => edge.text())).toEqual(['documenten (n) ↔ zaak', 'contact (1)', 'bijlage (1)'])
	})

	it('renders the same links as a table', async () => {
		const { wrapper } = await mountDiagram()
		const rows = wrapper.findAll('[data-testid="register-model-table"] tbody tr').map((row) => row.text())

		expect(rows).toEqual([
			'Zaakdocumenten (n) ↔ zaakDocument',
			'Zaakcontact (1)A schema you cannot open',
			'Documentbijlage (1)Broken link: gone',
		])
	})

	it('says a register without links has none, rather than drawing an empty table', async () => {
		axios.get.mockImplementation((url) => Promise.resolve({ data: url.includes('/preferences/') ? { value: null } : { nodes: MODEL.nodes.slice(0, 2), edges: [] } }))
		const wrapper = mount(RegisterModelDiagram, { props: { registerId: 7 }, global: withRouter({ push: jest.fn(), resolve: () => ({ href: '#' }) }) })
		await flushPromises()

		expect(wrapper.findAll('x-node')).toHaveLength(2)
		expect(wrapper.findAll('x-edge')).toHaveLength(0)
		expect(wrapper.find('tbody').text()).toBe('This register has no links between its schemas')
	})

	it('opens a schema from a box, a key press, a line or a row, and never a box the reader cannot open', async () => {
		const { wrapper, router } = await mountDiagram()
		const canvas = wrapper.findComponent({ name: 'CnGraphCanvas' })

		canvas.vm.$emit('node-select', { node: { id: '2' } })
		canvas.vm.$emit('node-activate', '1')
		canvas.vm.$emit('edge-label-click', 'edge-2')
		canvas.vm.$emit('node-select', { node: { id: '9' } })
		canvas.vm.$emit('node-activate', 'dangling-2')
		await wrapper.findAll('tbody a')[1].trigger('click')

		expect(router.push.mock.calls.map((call) => call[0])).toEqual(['/schemas/2', '/schemas/1', '/schemas/2', '/schemas/2'])
	})

	it('moves a box while dragged and saves the layout once the drag ends', async () => {
		const { wrapper } = await mountDiagram({ value: '{"1":{"x":5,"y":6}}' })
		const canvas = wrapper.findComponent({ name: 'CnGraphCanvas' })
		expect(canvas.props('nodes').find((node) => node.id === '1').position).toEqual({ x: 5, y: 6 })

		canvas.vm.$emit('nodes-change', [{ type: 'position', id: '2', position: { x: 40, y: 50 }, dragging: true }])
		await flushPromises()
		expect(canvas.props('nodes').find((node) => node.id === '2').position).toEqual({ x: 40, y: 50 })
		expect(axios.put).not.toHaveBeenCalled()

		canvas.vm.$emit('nodes-change', [{ type: 'position', id: '2', dragging: false }])
		await flushPromises()
		expect(axios.put).toHaveBeenCalledWith(
			'/index.php/apps/openregister/api/preferences/register-model-layout-7',
			{ value: JSON.stringify({ 1: { x: 5, y: 6 }, 2: { x: 40, y: 50 } }) },
		)
	})

	it('says so when the model cannot be loaded', async () => {
		axios.get.mockImplementation((url) => (url.includes('/model') ? Promise.reject(new Error('403')) : Promise.resolve({ data: { value: null } })))
		const wrapper = mount(RegisterModelDiagram, { props: { registerId: 7 }, global: withRouter({ push: jest.fn(), resolve: () => ({ href: '#' }) }) })
		await flushPromises()

		expect(wrapper.find('x-NcNoteCard').text()).toBe('Could not load the register model')
		expect(wrapper.findComponent({ name: 'CnGraphCanvas' }).exists()).toBe(false)
	})

	it('downloads the drawn model as an SVG file', async () => {
		const { wrapper } = await mountDiagram()
		const blobs = []
		global.URL.createObjectURL = jest.fn((blob) => {
			blobs.push(blob)
			return 'blob:x'
		})
		global.URL.revokeObjectURL = jest.fn()
		const RealBlob = global.Blob
		global.Blob = jest.fn((parts, options) => ({ parts, type: options.type }))
		const click = jest.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(() => {})

		await wrapper.find('[data-testid="register-model-download"]').trigger('click')

		expect(click).toHaveBeenCalled()
		expect(blobs[0].type).toBe('image/svg+xml')
		expect(blobs[0].parts[0].startsWith('<svg')).toBe(true)
		expect(blobs[0].parts[0]).toContain('A schema you cannot open')
		global.Blob = RealBlob
		expect(global.URL.revokeObjectURL).toHaveBeenCalledWith('blob:x')
		click.mockRestore()
	})
})

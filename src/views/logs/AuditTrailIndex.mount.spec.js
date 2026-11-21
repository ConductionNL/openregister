import { showError } from '@nextcloud/dialogs'
/**
 * AuditTrailIndex mounted as shipped, with its own setup bindings: what mounting
 * loads and reports, and how each audit row's cells render.
 *
 * The stores, the dialogs and the library components are mocked; the index
 * page stand-in renders every column slot for every row, the way the real
 * table does.
 *
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
import { flushPromises, mount } from '@vue/test-utils'
import AuditTrailIndex from './AuditTrailIndex.vue'
import eventBus from '../../eventBus.js'
import { auditTrailStore } from '../../store/store.js'

jest.mock('@nextcloud/l10n', () => ({ translate: (app, text) => text, translatePlural: (app, one) => one }))
jest.mock('@nextcloud/dialogs', () => ({ showError: jest.fn(), showSuccess: jest.fn() }))
jest.mock('../../store/store.js', () => {
	const { reactive } = require('vue')
	return {
		auditTrailStore: reactive({}),
		navigationStore: { setModal: jest.fn(), setDialog: jest.fn() },
	}
})
jest.mock('../../components/shared/ActionMenu.vue', () => ({ __esModule: true, default: { name: 'ActionMenu', props: ['actions'], render: () => null } }))
jest.mock('@nextcloud/vue', () => mockLibrary())
jest.mock('@conduction/nextcloud-vue', () => mockLibrary())
// The page mixes <script setup> and an options block; its compiled template
// reads the library components through 'vue'.
jest.mock('vue', () => ({ ...jest.requireActual('vue'), ...mockLibrary() }))

/**
 * A stand-in component that renders its attributes and every slot.
 *
 * @param {string} name The component name.
 * @return {object} The component.
 */
function mockStub(name) {
	const { h } = jest.requireActual('vue')
	return {
		name,
		inheritAttrs: false,
		render() {
			const slots = Object.values(this.$slots).map((s) => s({}))
			const shown = this.$attrs.label ?? this.$attrs.name
			const label = shown === undefined ? [] : [String(shown)]
			return h('div', { class: `stub-${name}` }, [...label, ...slots])
		},
	}
}

/**
 * The library components the page uses, as stand-ins. The index page renders
 * every column slot and the row actions for each row, or the empty slot.
 *
 * @return {object} The module.
 */
function mockLibrary() {
	const { h } = jest.requireActual('vue')
	const cells = (slots, row) => Object.entries(slots)
		.filter(([key]) => key.startsWith('column-') || key === 'row-actions')
		.map(([key, fill]) => h('span', { 'data-cell': key }, fill({ row })))
	return {
		__esModule: true,
		NcAppContent: mockStub('NcAppContent'),
		NcActionButton: mockStub('NcActionButton'),
		NcDateTime: mockStub('NcDateTime'),
		NcEmptyContent: mockStub('NcEmptyContent'),
		CnStatusBadge: mockStub('CnStatusBadge'),
		CnIndexPage: {
			name: 'CnIndexPage',
			props: ['objects'],
			render() {
				const rows = (this.objects || []).map((row) => h('div', { class: 'row', 'data-id': row.id }, cells(this.$slots, row)))
				return h('section', {}, [this.$slots['action-items']?.(), ...(rows.length > 0 ? rows : [this.$slots.empty?.()])])
			},
		},
	}
}

/**
 * Reset the store: a list, its pagination and resolving actions.
 *
 * @param {Array<object>} list The audit trails.
 * @return {void}
 */
function store(list) {
	Object.assign(auditTrailStore, {
		auditTrailList: list,
		auditTrailLoading: false,
		auditTrailPagination: { total: list.length, page: 1, pages: 1, limit: 20 },
		auditTrailFilters: {},
		refreshAuditTrailList: jest.fn().mockResolvedValue(),
		fetchAuditTrails: jest.fn().mockResolvedValue(),
		setAuditTrailSort: jest.fn(),
		setAuditTrailFilters: jest.fn(),
		setAuditTrailItem: jest.fn(),
	})
}

/**
 * The text of one cell of one row.
 *
 * @param {object} wrapper The page.
 * @param {string} id The row id.
 * @param {string} column The column slot name.
 * @return {string} The text.
 */
function cell(wrapper, id, column) {
	return wrapper.find(`.row[data-id="${id}"] [data-cell="${column}"]`).text()
}

let mounted = []

/**
 * Mount the page and let its load settle.
 *
 * @return {Promise<object>} The wrapper.
 */
async function mountPage() {
	const wrapper = mount(AuditTrailIndex)
	mounted.push(wrapper)
	await flushPromises()
	return wrapper
}

beforeEach(() => {
	jest.clearAllMocks()
})

afterEach(() => {
	mounted.forEach((w) => w.unmount())
	mounted = []
})

describe('AuditTrailIndex — mounted with its own setup', () => {
	it('loads the list and reports how many rows it shows', async () => {
		store([{ id: 1 }, { id: 2 }])
		const counts = []
		eventBus.on('audit-trail-filtered-count', (count) => counts.push(count))

		await mountPage()

		expect(auditTrailStore.refreshAuditTrailList).toHaveBeenCalledTimes(1)
		expect(counts).toContain(2)
	})

	it('says when the list failed to load', async () => {
		store([])
		auditTrailStore.refreshAuditTrailList.mockRejectedValue(new Error('down'))
		jest.spyOn(console, 'error').mockImplementation(() => {})

		await mountPage()

		expect(showError).toHaveBeenCalledWith('Error loading audit trails')
		console.error.mockRestore()
	})

	it('renders each cell, with a dash for what the entry does not carry', async () => {
		store([
			{ id: 1, action: 'update', created: '2026-01-02T03:04:05Z', object: 'o-1', register: 'r-1', schema: 's-1', userName: 'Anna', size: 2048 },
			{ id: 2, user: 'bob', size: 0 },
		])

		const wrapper = await mountPage()

		expect(cell(wrapper, 1, 'column-action')).toBe('UPDATE')
		expect(cell(wrapper, 1, 'column-object')).toBe('o-1')
		expect(cell(wrapper, 1, 'column-register')).toBe('r-1')
		expect(cell(wrapper, 1, 'column-schema')).toBe('s-1')
		expect(cell(wrapper, 1, 'column-userName')).toBe('Anna')
		expect(cell(wrapper, 1, 'column-size')).toBe('2 KB')

		expect(cell(wrapper, 2, 'column-action')).toBe('NO ACTION')
		expect(cell(wrapper, 2, 'column-object')).toBe('-')
		expect(cell(wrapper, 2, 'column-register')).toBe('-')
		expect(cell(wrapper, 2, 'column-schema')).toBe('-')
		expect(cell(wrapper, 2, 'column-userName')).toBe('bob')
		expect(wrapper.findAll('.row').map((row) => row.findComponent({ name: 'ActionMenu' }).exists())).toEqual([true, true])
	})

	it('shows the empty state without entries', async () => {
		store([])

		const wrapper = await mountPage()

		expect(wrapper.find('.stub-NcEmptyContent').text()).toContain('No audit trail entries found')
		expect(wrapper.text()).toContain('Export')
	})
})

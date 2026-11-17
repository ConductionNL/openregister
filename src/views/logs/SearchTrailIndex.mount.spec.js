/**
 * SearchTrailIndex mounted as shipped, with its own setup bindings: the
 * loading and empty states, each row's cells, row selection, and the filter
 * indicator.
 *
 * The stores and the library components are mocked; the shared list bar,
 * pagination and action menu are stand-ins.
 *
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
import { flushPromises, mount } from '@vue/test-utils'
import SearchTrailIndex from './SearchTrailIndex.vue'
import { searchTrailStore } from '../../store/store.js'

jest.mock('@nextcloud/l10n', () => ({
	translate: (app, text, vars) => (vars ? text.replace(/\{(\w+)\}/g, (m, key) => String(vars[key])) : text),
	translatePlural: (app, one) => one,
}))
jest.mock('../../store/store.js', () => {
	const { reactive } = require('vue')
	return {
		searchTrailStore: reactive({}),
		navigationStore: { setModal: jest.fn(), setDialog: jest.fn() },
	}
})
jest.mock('../../components/shared/ActionMenu.vue', () => ({ __esModule: true, default: mockStub('ActionMenu') }))
jest.mock('../../components/shared/ListActionsBar.vue', () => ({ __esModule: true, default: mockStub('ListActionsBar') }))
jest.mock('../../components/PaginationComponent.vue', () => ({ __esModule: true, default: mockStub('PaginationComponent') }))
jest.mock('@nextcloud/vue', () => mockLibrary())
// The page mixes <script setup> and an options block; its compiled template
// reads the library components through 'vue'.
jest.mock('vue', () => ({ ...jest.requireActual('vue'), ...mockLibrary() }))

/**
 * A stand-in component that renders its text-like attributes and every slot.
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
			const shown = this.$attrs.summary ?? this.$attrs.name
			const slots = Object.values(this.$slots).map((s) => s({}))
			return h('div', { class: `stub-${name}`, 'data-model': String(this.$attrs.modelValue) }, [shown === undefined ? '' : String(shown), ...slots])
		},
	}
}

/**
 * The @nextcloud/vue components the page uses, as stand-ins.
 *
 * @return {object} The module.
 */
function mockLibrary() {
	return {
		__esModule: true,
		NcAppContent: mockStub('NcAppContent'),
		NcCheckboxRadioSwitch: mockStub('NcCheckboxRadioSwitch'),
		NcDateTime: mockStub('NcDateTime'),
		NcEmptyContent: mockStub('NcEmptyContent'),
		NcLoadingIcon: mockStub('NcLoadingIcon'),
	}
}

/**
 * Reset the store with a list.
 *
 * @param {Array<object>} list The search trails.
 * @param {object} state More store state.
 * @return {void}
 */
function store(list, state = {}) {
	Object.assign(searchTrailStore, {
		searchTrailList: list,
		searchTrailLoading: false,
		searchTrailPagination: { total: list.length, page: 1, pages: 1, limit: 50 },
		searchTrailFilters: {},
		refreshSearchTrailList: jest.fn().mockResolvedValue(),
		setSearchTrailFilters: jest.fn(),
		setSearchTrailItem: jest.fn(),
	}, state)
}

let mounted = []

/**
 * Mount the page and let its load settle.
 *
 * @return {Promise<object>} The wrapper.
 */
async function mountPage() {
	const wrapper = mount(SearchTrailIndex)
	mounted.push(wrapper)
	await flushPromises()
	return wrapper
}

/**
 * The text of each cell of a row.
 *
 * @param {object} row The row wrapper.
 * @return {Array<string>} The cell texts.
 */
function cells(row) {
	return row.findAll('td').map((td) => td.text())
}

afterEach(() => {
	mounted.forEach((w) => w.unmount())
	mounted = []
})

describe('SearchTrailIndex — mounted with its own setup', () => {
	it('loads the list on mount and summarises it', async () => {
		store([{ id: 1, totalResults: 2 }], { searchTrailPagination: { total: 9, page: 1, pages: 1, limit: 50 } })

		const wrapper = await mountPage()

		expect(searchTrailStore.refreshSearchTrailList).toHaveBeenCalledTimes(1)
		expect(wrapper.find('.stub-ListActionsBar').text()).toContain('Showing 1 of 9 search trail entries')
		expect(wrapper.text()).not.toContain('(Filtered)')
	})

	it('shows the loader while loading and the empty state without entries', async () => {
		store([], { searchTrailLoading: true })
		expect((await mountPage()).text()).toContain('Loading search trails...')

		store([])
		expect((await mountPage()).find('.stub-NcEmptyContent').text()).toContain('No search trail entries found')
	})

	it('renders each row, with fallbacks for what an entry does not carry', async () => {
		store([
			{ id: 1, searchTerm: 'water', totalResults: 3, registerName: 'Reg', schema: 's-1', userName: 'Anna', responseTime: 1500 },
			{ id: 2, totalResults: 0, user: 'bob', responseTime: 40 },
			{ id: 3 },
		])

		const rows = (await mountPage()).findAll('tr.searchTrailRow')

		expect(cells(rows[0]).slice(1)).toEqual(['water3 results', '', 'Reg', 's-1', 'Anna', '3', '1.50s', ''])
		expect(cells(rows[1]).slice(1)).toEqual(['-', '', '-', '-', 'bob', '0', '40ms', ''])
		expect(cells(rows[2])[7]).toBe('-')
		expect(rows.map((row) => row.classes().includes('success'))).toEqual([true, false, false])
		expect(rows.map((row) => row.classes().includes('failed'))).toEqual([false, true, false])
	})

	it('selects and deselects a row from its checkbox', async () => {
		store([{ id: 1 }, { id: 2 }])
		const wrapper = await mountPage()
		const box = () => wrapper.findAll('tr.searchTrailRow')[1].findComponent({ name: 'NcCheckboxRadioSwitch' })

		box().vm.$emit('update:modelValue', true)
		await wrapper.vm.$nextTick()
		expect(box().attributes('data-model')).toBe('true')

		box().vm.$emit('update:modelValue', false)
		await wrapper.vm.$nextTick()
		expect(box().attributes('data-model')).toBe('false')
	})

	it('marks the list as filtered when a filter has a value', async () => {
		store([{ id: 1 }], { searchTrailFilters: { register: null, term: 'water' } })

		expect((await mountPage()).text()).toContain('(Filtered)')
	})
})

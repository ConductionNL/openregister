/**
 * The rendered Views tab and active facet filters of the search sidebar:
 * which saved views it lists, in what order and with which badges, what its
 * row buttons do, and how the active facet filters are offered and reset.
 *
 * @spec exclude Client-side sidebar markup and its click handlers.
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
import { mount } from '@vue/test-utils'
import SearchSideBar from './SearchSideBar.vue'

jest.mock('@conduction/nextcloud-vue', () => ({
	CnIndexSidebar: {
		name: 'CnIndexSidebar',
		render() {
			const { h } = require('vue')
			return h('x-cnindexsidebar', [
				h('x-above', this.$slots['search-above']?.({})),
				h('x-tabs', this.$slots.tabs?.({})),
			])
		},
	},
}))
jest.mock('@nextcloud/l10n', () => ({ translate: (app, text) => text }))
jest.mock('@nextcloud/router', () => ({ generateUrl: (path) => path }))
jest.mock('@nextcloud/vue', () => mockLibrary())
jest.mock('vue-material-design-icons/CogOutline.vue', () => mockStub('CogOutline'))
jest.mock('vue-material-design-icons/ContentSaveOutline.vue', () => mockStub('ContentSaveOutline'))
jest.mock('vue-material-design-icons/Delete.vue', () => mockStub('Delete'))
jest.mock('vue-material-design-icons/Filter.vue', () => mockStub('Filter'))
jest.mock('vue-material-design-icons/Magnify.vue', () => mockStub('Magnify'))
jest.mock('vue-material-design-icons/Pencil.vue', () => mockStub('Pencil'))
jest.mock('vue-material-design-icons/Star.vue', () => mockStub('Star'))
jest.mock('vue-material-design-icons/StarOutline.vue', () => mockStub('StarOutline'))
jest.mock('vue-material-design-icons/ViewDashboardOutline.vue', () => mockStub('ViewDashboardOutline'))
jest.mock('../../modals/view/DeleteView.vue', () => mockStub('DeleteView'))
jest.mock('../../modals/view/EditView.vue', () => mockStub('EditView'))
jest.mock('../../store/store.js', () => ({}))

const stores = jest.requireMock('../../store/store.js')

/**
 * Library stand-ins: every named export is a stub of that name.
 *
 * @return {object} The module
 */
function mockLibrary() {
	globalThis.viewsTabStubs = globalThis.viewsTabStubs || {}
	const made = globalThis.viewsTabStubs
	return new Proxy(
		{ __esModule: true },
		{
			get(target, key) {
				if (key in target || typeof key !== 'string') return target[key]
				made[key] = made[key] || mockStub(key)
				return made[key]
			},
			has: () => true,
		},
	)
}

/**
 * A stand-in component that renders its slots, keeps its click listener and
 * shows its plain attributes.
 *
 * @param {string} name The component name
 * @return {object} The stub
 */
function mockStub(name) {
	const { h } = require('vue')
	return {
		name,
		inheritAttrs: false,
		render() {
			const attrs = {}
			for (const [key, value] of Object.entries(this.$attrs)) {
				if (key === 'onClick') attrs.onClick = value
				else if (!/^on[A-Z]/.test(key) && typeof value !== 'object') attrs[key] = value
			}
			return h(
				`x-${name}`,
				attrs,
				Object.keys(this.$slots).map((slot) => this.$slots[slot]({ title: 'Pets', description: 'All pets' })),
			)
		},
	}
}

/**
 * Anything at all: the store parts these tests do not read.
 */
const anything = new Proxy(function() {}, {
	get(target, key) {
		if (key === Symbol.toPrimitive) return () => ''
		if (key === Symbol.iterator) return [][Symbol.iterator].bind([])
		if (key === 'then' || key === 'toJSON') return undefined
		if (key === 'length') return 0
		return anything
	},
	apply: () => anything,
})

/**
 * A store that answers every unknown field with `anything`.
 *
 * @param {object} fields The known fields
 * @return {object} The store
 */
function lenient(fields) {
	return new Proxy(fields, {
		get: (target, prop) => (prop in target ? target[prop] : anything),
		set: (target, prop, value) => {
			target[prop] = value
			return true
		},
	})
}

/**
 * Mount the sidebar on the given views and data, without its mount hooks.
 *
 * @param {object} options The saved views, views store fields and data
 * @param {Array<object>} options.views The saved views
 * @param {object} options.store Extra views store fields
 * @param {object} options.state Data to set on the sidebar
 * @return {Promise<object>} The wrapper
 */
async function sidebar({ views = [], store = {}, state = {} } = {}) {
	stores.navigationStore = lenient({ sidebarState: {} })
	stores.objectStore = lenient({
		searchParams: { filters: {} },
		updateSearchParams: jest.fn(),
		refetchSearchCollection: jest.fn(),
	})
	stores.registerStore = lenient({ registerList: [] })
	stores.schemaStore = lenient({ schemaList: [] })
	stores.viewsStore = lenient({
		activeView: null,
		isLoading: false,
		getAllViews: views,
		fetchView: jest.fn(),
		fetchViews: jest.fn().mockResolvedValue(undefined),
		...store,
	})
	const quiet = { ...SearchSideBar, mounted: undefined, watch: {} }
	const wrapper = mount(quiet, {
		global: {
			mocks: { $route: { path: '/search', query: {} }, $router: {} },
		},
	})
	await wrapper.setData(state)
	return wrapper
}

/**
 * The titles of the listed view rows, in order.
 *
 * @param {object} wrapper The sidebar
 * @return {Array<string>} The titles
 */
function titles(wrapper) {
	return wrapper.findAll('.viewRowTitle strong').map((node) => node.text())
}

/**
 * The button with the given aria label in a view row.
 *
 * @param {object} row The row
 * @param {string} label The aria label
 * @return {object} The button
 */
function button(row, label) {
	return row.find(`x-NcButton[aria-label="${label}"]`)
}

beforeEach(() => {
	jest.clearAllMocks()
	jest.spyOn(console, 'error').mockImplementation(() => {})
	globalThis.OC = {
		getCurrentUser: () => ({ uid: 'me' }),
		requestToken: 'tok',
		Notification: { showTemporary: jest.fn() },
	}
	globalThis.fetch = jest.fn()
})

describe('search sidebar views tab', () => {
	it('shows a loader while the views load', async () => {
		const wrapper = await sidebar({ store: { isLoading: true } })

		expect(wrapper.find('.viewsLoading').text()).toBe('Loading views...')
		expect(wrapper.find('.viewsTable').exists()).toBe(false)
	})

	it('says there are no saved views yet', async () => {
		const wrapper = await sidebar()

		expect(wrapper.find('.noViews').text()).toBe(
			'No saved views yet. create one in the search tab!',
		)
	})

	it('says no view matches a search that finds nothing', async () => {
		const wrapper = await sidebar({
			views: [{ id: 1, name: 'Pets' }],
			state: { viewSearchQuery: 'zzz' },
		})

		expect(wrapper.find('.noViews').text()).toBe('No views match your search')
	})

	it('lists favourites first, then by name, with their badges', async () => {
		const wrapper = await sidebar({
			views: [
				{ id: 1, name: 'Zebra', description: 'Stripes' },
				{ id: 2, name: 'Apple', isPublic: true },
				{ id: 3, name: 'Mango', favoredBy: ['me'], isDefault: true },
			],
		})

		expect(titles(wrapper)).toEqual(['Mango', 'Apple', 'Zebra'])
		const rows = wrapper.findAll('.viewRow')
		expect(rows[0].find('.viewBadge--default').text()).toBe('Default')
		expect(rows[0].find('.viewBadge--public').exists()).toBe(false)
		expect(rows[1].find('.viewBadge--public').text()).toBe('Public')
		expect(rows[2].find('.viewRowDescription').text()).toBe('Stripes')
		expect(rows[1].find('.viewRowDescription--empty').text()).toBe(
			'No description provided',
		)
		expect(button(rows[0], 'Remove from favorites').attributes('variant')).toBe('primary')
		expect(rows[0].find('x-Star').exists()).toBe(true)
		expect(button(rows[1], 'Add to favorites').attributes('variant')).toBe('secondary')
		expect(rows[1].find('x-StarOutline').exists()).toBe(true)
	})

	it('names an untitled view and filters by name or description', async () => {
		const wrapper = await sidebar({
			views: [
				{ id: 1, name: '' },
				{ id: 2, name: 'Cats', description: 'Feline friends' },
				{ id: 3, name: 'Dogs' },
			],
		})
		expect(titles(wrapper)).toEqual(['Untitled View', 'Cats', 'Dogs'])

		await wrapper.setData({ viewSearchQuery: 'FELINE' })

		expect(titles(wrapper)).toEqual(['Cats'])
	})

	it('marks the active view', async () => {
		const wrapper = await sidebar({
			views: [
				{ uuid: 'a', name: 'A' },
				{ uuid: 'b', name: 'B' },
			],
			store: { activeView: { uuid: 'b', name: 'B' } },
		})
		const rows = wrapper.findAll('.viewRow')

		expect(rows[0].classes()).not.toContain('viewRow--active')
		expect(rows[1].classes()).toContain('viewRow--active')
		expect(button(rows[1], 'Load view').attributes('variant')).toBe('primary')
		expect(button(rows[0], 'Load view').attributes('variant')).toBe('secondary')
	})

	it('opens the edit dialog for a view and closes it again', async () => {
		const view = { id: 4, name: 'Edit me' }
		const wrapper = await sidebar({ views: [view] })

		await button(wrapper.find('.viewRow'), 'Edit view').trigger('click')

		expect(wrapper.vm.showEditDialog).toBe(true)
		expect(wrapper.vm.editingView).toEqual(view)
		expect(wrapper.findComponent({ name: 'EditView' }).exists()).toBe(true)

		wrapper.vm.cancelEditView()
		await wrapper.vm.$nextTick()

		expect(wrapper.findComponent({ name: 'EditView' }).exists()).toBe(false)
		expect(wrapper.vm.editingView).toBe(null)
	})

	it('asks to delete a view and clears it when it was the active one', async () => {
		const view = { id: 6, name: 'Gone' }
		const wrapper = await sidebar({
			views: [view],
			store: { activeView: { id: 6, name: 'Gone' } },
			state: { activeViewName: 'Gone' },
		})

		await button(wrapper.find('.viewRow'), 'Delete view').trigger('click')

		expect(wrapper.vm.viewToDelete).toEqual(view)
		expect(wrapper.findComponent({ name: 'DeleteView' }).exists()).toBe(true)

		await wrapper.vm.handleDeleteClose()
		await wrapper.vm.$nextTick()

		expect(stores.viewsStore.fetchViews).toHaveBeenCalledTimes(1)
		expect(stores.viewsStore.activeView).toBe(null)
		expect(wrapper.vm.activeViewName).toBe('')
		expect(wrapper.findComponent({ name: 'DeleteView' }).exists()).toBe(false)
	})

	it('loads a view from its row', async () => {
		const wrapper = await sidebar({ views: [{ uuid: 'v-9', name: 'Short' }] })
		stores.viewsStore.fetchView.mockResolvedValue({
			uuid: 'v-9',
			name: 'Full',
			configuration: {},
		})
		await wrapper.setData({ activeTab: 'views-tab' })

		await button(wrapper.find('.viewRow'), 'Load view').trigger('click')
		await new Promise((resolve) => setTimeout(resolve, 0))

		expect(stores.viewsStore.fetchView).toHaveBeenCalledWith('v-9')
		expect(stores.viewsStore.activeView).toEqual({
			uuid: 'v-9',
			name: 'Full',
			configuration: {},
		})
		expect(wrapper.vm.activeViewName).toBe('Full')
		expect(wrapper.vm.activeTab).toBe('search-tab')
	})

	it('removes the user from a favourite view through PATCH', async () => {
		globalThis.fetch.mockResolvedValue({ ok: true })
		const wrapper = await sidebar({
			views: [{ id: 7, name: 'Fav', favoredBy: ['other', 'me'] }],
		})

		await button(wrapper.find('.viewRow'), 'Remove from favorites').trigger('click')
		await new Promise((resolve) => setTimeout(resolve, 0))

		expect(globalThis.fetch).toHaveBeenCalledWith('/apps/openregister/api/views/7', {
			method: 'PATCH',
			headers: { 'Content-Type': 'application/json', requesttoken: 'tok' },
			body: JSON.stringify({ favoredBy: ['other'] }),
		})
		expect(stores.viewsStore.fetchViews).toHaveBeenCalledTimes(1)
		expect(OC.Notification.showTemporary).toHaveBeenCalledWith('Removed from favorites')
	})
})

describe('search sidebar active facet filters', () => {
	const FACETS = {
		'@self': {
			register: { values: [{ value: 'r1', count: 3 }] },
		},
		object_fields: {
			colour: { values: [{ value: 'red', count: 2 }, { value: 'blue' }] },
		},
	}

	/**
	 * The facet group labels, in order.
	 *
	 * @param {object} wrapper The sidebar
	 * @return {Array<string>} The labels
	 */
	function labels(wrapper) {
		return wrapper.findAll('.facet-group .facet-label').map((node) => node.text())
	}

	it('shows a loader while the facet data loads', async () => {
		const wrapper = await sidebar({
			state: { facetableFields: {}, facetDataLoading: true, facetData: FACETS },
		})

		expect(wrapper.find('.loading-container').text()).toBe('Loading filter data...')
		expect(wrapper.find('.active-facets-container').exists()).toBe(false)
	})

	it('offers a select per metadata and object facet, labelled from the facetable fields', async () => {
		const wrapper = await sidebar({
			state: {
				facetableFields: {
					'@self': { register: { description: 'Register', facet_types: ['terms'] } },
					object_fields: { colour: { title: 'Colour of thing', facet_types: ['terms'] } },
				},
				facetData: FACETS,
			},
		})

		expect(wrapper.find('.active-facets-title').text()).toBe('Active Filters')
		expect(labels(wrapper)).toEqual(['Register', 'Colour of thing'])
		const selects = wrapper.findAllComponents({ name: 'NcSelect' })
		const colour = selects[selects.length - 1]
		expect(colour.vm.$attrs.options).toEqual([
			{ value: 'red', label: 'red (2)' },
			{ value: 'blue', label: 'blue (0)' },
		])
		expect(colour.vm.$attrs.inputLabel).toBe('Colour of thing')
		expect(colour.vm.$attrs.modelValue).toEqual([])
	})

	it('records a picked facet value under its field', async () => {
		const wrapper = await sidebar({
			state: { facetableFields: {}, facetData: FACETS, facetFilters: {} },
		})
		const selects = wrapper.findAllComponents({ name: 'NcSelect' })
		const register = selects[selects.length - 2]
		const colour = selects[selects.length - 1]

		register.vm.$attrs['onUpdate:modelValue'](['r1'])
		colour.vm.$attrs['onUpdate:modelValue'](['red'])

		expect(wrapper.vm.facetFilters).toEqual({
			'@self.register': ['r1'],
			colour: ['red'],
		})
	})

	it('resets every facet from the reset button', async () => {
		const wrapper = await sidebar({
			state: {
				facetableFields: {},
				facetData: FACETS,
				facetFilters: { colour: ['red'] },
				enabledFacets: { colour: true },
			},
		})

		await wrapper.find('.facets-reset-container x-NcButton').trigger('click')

		expect(wrapper.vm.facetFilters).toEqual({})
		expect(wrapper.vm.enabledFacets).toEqual({})
		expect(wrapper.vm.facetData).toBe(null)
		expect(stores.objectStore.updateSearchParams).toHaveBeenCalledWith({ filters: {} })
		expect(stores.objectStore.refetchSearchCollection).not.toHaveBeenCalled()
		expect(wrapper.find('.active-facets-container').exists()).toBe(false)
	})
})

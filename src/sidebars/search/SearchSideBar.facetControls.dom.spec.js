/**
 * The facet controls and favourite toggle of the search sidebar: what
 * picking a facet value, toggling an available facet, resetting the filters
 * and adding a view to the favourites do.
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
	globalThis.facetControlsStubs = globalThis.facetControlsStubs || {}
	const made = globalThis.facetControlsStubs
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

/**
 * Let pending promises settle.
 *
 * @return {Promise<void>}
 */
function settle() {
	return new Promise((resolve) => setTimeout(resolve, 0))
}

const FACETABLE = {
	'@self': { register: { description: 'Register', facet_types: ['terms'] } },
	object_fields: { colour: { title: 'Colour of thing', facet_types: ['terms'] } },
}

const FACETS = {
	'@self': { register: { values: [{ value: 'r1', count: 3 }] } },
	object_fields: { colour: { values: [{ value: 'red', count: 2 }] } },
}

describe('search sidebar facet controls', () => {
	it('stores a picked metadata facet value under its @self key and refreshes the search', async () => {
		const wrapper = await sidebar({ state: { facetableFields: FACETABLE, facetData: FACETS } })
		const selects = wrapper.findAllComponents({ name: 'NcSelect' })
		const register = selects[selects.length - 2]

		register.vm.$emit('update:modelValue', [{ value: 'r1', label: 'r1 (3)' }])
		await settle()

		expect(wrapper.vm.facetFilters).toEqual({ '@self.register': [{ value: 'r1', label: 'r1 (3)' }] })
		expect(stores.objectStore.loading).toBe(false)
	})

	it('stores a picked object facet value under its field name', async () => {
		const wrapper = await sidebar({
			state: {
				facetableFields: FACETABLE,
				facetData: FACETS,
				facetFilters: { '@self.register': ['r1'] },
			},
		})
		const selects = wrapper.findAllComponents({ name: 'NcSelect' })
		const colour = selects[selects.length - 1]

		colour.vm.$emit('update:modelValue', ['red'])
		await settle()

		expect(wrapper.vm.facetFilters).toEqual({ '@self.register': ['r1'], colour: ['red'] })
	})

	it('runs the faceted search once a register and a schema are picked', async () => {
		const wrapper = await sidebar({
			state: {
				facetableFields: FACETABLE,
				facetData: FACETS,
				selectedRegisters: [1],
				selectedSchemas: [2],
			},
		})
		const selects = wrapper.findAllComponents({ name: 'NcSelect' })

		selects[selects.length - 1].vm.$emit('update:modelValue', ['red'])
		await settle()

		expect(stores.objectStore.refetchSearchCollection).toHaveBeenCalled()
		expect(stores.objectStore.loading).toBe(false)
	})

	it('enables an available facet without touching the picked filters', async () => {
		const wrapper = await sidebar({
			state: {
				facetableFields: FACETABLE,
				enabledFacets: {},
				facetFilters: { '@self.register': ['r1'] },
			},
		})
		const checkbox = wrapper.find('input#facet-colour')
		expect(checkbox.element.checked).toBe(false)

		checkbox.element.checked = true
		await checkbox.trigger('change')

		expect(wrapper.vm.enabledFacets).toEqual({ colour: true })
		expect(wrapper.vm.facetFilters).toEqual({ '@self.register': ['r1'] })
	})

	it('resets every facet and clears the store filters', async () => {
		const wrapper = await sidebar({
			state: {
				facetableFields: FACETABLE,
				facetData: FACETS,
				enabledFacets: { colour: true },
				facetFilters: { colour: ['red'] },
			},
		})
		const reset = wrapper.findAll('.facets-reset-container x-NcButton')

		await reset[0].trigger('click')

		expect(wrapper.vm.enabledFacets).toEqual({})
		expect(wrapper.vm.facetFilters).toEqual({})
		expect(wrapper.vm.facetData).toBe(null)
		expect(stores.objectStore.updateSearchParams).toHaveBeenCalledWith({ filters: {} })
		expect(stores.objectStore.refetchSearchCollection).not.toHaveBeenCalled()
		expect(wrapper.find('.active-facets-container').exists()).toBe(false)
	})
})

describe('search sidebar favourite toggle', () => {
	it('adds the user to a view\'s favourites through PATCH', async () => {
		globalThis.fetch.mockResolvedValue({ ok: true })
		const wrapper = await sidebar({ views: [{ uuid: 'v-3', name: 'Plain', favoredBy: ['other'] }] })

		await button(wrapper.find('.viewRow'), 'Add to favorites').trigger('click')
		await settle()

		expect(globalThis.fetch).toHaveBeenCalledWith('/apps/openregister/api/views/v-3', {
			method: 'PATCH',
			headers: { 'Content-Type': 'application/json', requesttoken: 'tok' },
			body: JSON.stringify({ favoredBy: ['other', 'me'] }),
		})
		expect(stores.viewsStore.fetchViews).toHaveBeenCalledTimes(1)
		expect(OC.Notification.showTemporary).toHaveBeenCalledWith('Added to favorites')
	})

	it('refuses a favourite without a signed-in user', async () => {
		globalThis.OC.getCurrentUser = () => null
		const wrapper = await sidebar({ views: [{ id: 5, name: 'Plain' }] })

		await button(wrapper.find('.viewRow'), 'Add to favorites').trigger('click')
		await settle()

		expect(globalThis.fetch).not.toHaveBeenCalled()
		expect(OC.Notification.showTemporary).toHaveBeenCalledWith('You must be logged in to favorite views')
	})

	it('reports a favourite the server refuses', async () => {
		globalThis.fetch.mockResolvedValue({ ok: false })
		const wrapper = await sidebar({ views: [{ id: 5, name: 'Plain' }] })

		await button(wrapper.find('.viewRow'), 'Add to favorites').trigger('click')
		await settle()

		expect(stores.viewsStore.fetchViews).not.toHaveBeenCalled()
		expect(OC.Notification.showTemporary).toHaveBeenCalledWith('Failed to update favorite status')
	})
})

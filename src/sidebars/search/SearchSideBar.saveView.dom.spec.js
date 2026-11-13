/**
 * The rendered save-view section of the search sidebar and the sidebar's own
 * events: saving the current search as a new view, renaming, saving and
 * deleting the active view, and what the sidebar's open, search, filter and
 * column events and its view and schema watchers change.
 *
 * @spec exclude Client-side sidebar markup and its event wiring.
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
import { flushPromises, mount } from '@vue/test-utils'
import SearchSideBar from './SearchSideBar.vue'

jest.mock('@conduction/nextcloud-vue', () => ({
	CnIndexSidebar: {
		name: 'CnIndexSidebar',
		emits: ['update:open', 'search', 'filterChange', 'columnsChange'],
		render() {
			const { h } = require('vue')
			return h('x-cnindexsidebar', [
				h('x-above', this.$slots['search-above']?.({})),
				h('x-tabs', this.$slots.tabs?.({})),
			])
		},
	},
}))
jest.mock('@nextcloud/l10n', () => ({
	translate: (app, text, vars) => (vars ? text.replace('{error}', vars.error) : text),
}))
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
	globalThis.saveViewStubs = globalThis.saveViewStubs || {}
	const made = globalThis.saveViewStubs
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

const ACTIVE = { id: 7, name: 'My pets', description: 'Mine', isPublic: true, isDefault: false }

/**
 * Mount the sidebar without its mount hook, watching only the active view and
 * the selected schemas.
 *
 * @param {object} options The views store fields and data
 * @param {object} options.store Extra views store fields
 * @param {object} options.state Data to set on the sidebar
 * @return {Promise<object>} The wrapper
 */
async function sidebar({ store = {}, state = {} } = {}) {
	stores.navigationStore = lenient({ sidebarState: { search: true }, setSidebarState: jest.fn() })
	stores.objectStore = lenient({
		searchParams: { search: 'cats', filters: { colour: ['black'] } },
		updateSearchParams: jest.fn(),
		refetchSearchCollection: jest.fn(),
		setSearchVisibleColumns: jest.fn(),
	})
	stores.registerStore = lenient({ registerList: [] })
	stores.schemaStore = lenient({ schemaList: [] })
	// Plain, not lenient: the sidebar watches the active view, and Vue cannot
	// observe a proxy that answers its own internal flags.
	stores.viewsStore = {
		activeView: null,
		isLoading: false,
		getAllViews: [],
		createView: jest.fn(),
		updateView: jest.fn(),
		fetchViews: jest.fn().mockResolvedValue(undefined),
		...store,
	}
	const watch = {
		selectedSchemas: SearchSideBar.watch.selectedSchemas,
		'viewsStore.activeView': SearchSideBar.watch['viewsStore.activeView'],
	}
	const quiet = { ...SearchSideBar, mounted: undefined, watch }
	const wrapper = mount(quiet, {
		global: {
			mocks: { $route: { path: '/search', query: {} }, $router: {} },
		},
	})
	await wrapper.setData(state)
	return wrapper
}

/**
 * The button in the save-view section with the given text.
 *
 * @param {object} wrapper The sidebar
 * @param {string} text The button text
 * @return {object} The button
 */
function saveButton(wrapper, text) {
	return wrapper.findAll('.saveViewSection x-NcButton').find((node) => node.text() === text)
}

/**
 * The view-name field of the save-view section.
 *
 * @param {object} wrapper The sidebar
 * @return {object} The field's component wrapper
 */
function nameField(wrapper) {
	return wrapper.findAllComponents({ name: 'NcTextField' })[0]
}

const SCOPE = { selectedRegisters: [1], selectedSchemas: [2] }

beforeEach(() => {
	jest.clearAllMocks()
	jest.spyOn(console, 'error').mockImplementation(() => {})
	globalThis.OC = {
		getCurrentUser: () => ({ uid: 'me' }),
		requestToken: 'tok',
		Notification: { showTemporary: jest.fn() },
	}
})

describe('search sidebar save-view section', () => {
	it('locks saving and explains why until a register and schema are picked', async () => {
		const wrapper = await sidebar()

		expect(saveButton(wrapper, 'Save current search as view').attributes('disabled')).toBe('true')
		expect(wrapper.find('.saveViewHint').text()).toBe('Select registers and schemas to save a view')
	})

	it('opens the form, requires a name once touched and cancels', async () => {
		const wrapper = await sidebar({ state: SCOPE })

		expect(wrapper.find('.saveViewHint').exists()).toBe(false)
		expect(saveButton(wrapper, 'Save current search as view').attributes('disabled')).toBe('false')

		await saveButton(wrapper, 'Save current search as view').trigger('click')

		expect(wrapper.find('.saveViewForm').exists()).toBe(true)
		expect(saveButton(wrapper, 'Save').attributes('disabled')).toBe('true')

		nameField(wrapper).vm.$emit('blur')
		await flushPromises()

		expect(nameField(wrapper).attributes('helpertext')).toBe('View name is required')
		expect(nameField(wrapper).attributes('error')).toBe('true')

		await saveButton(wrapper, 'Cancel').trigger('click')

		expect(wrapper.find('.saveViewForm').exists()).toBe(false)
		expect(wrapper.vm.viewNameTouched).toBe(false)
	})

	it('saves the current search as a view on enter and makes it the active one', async () => {
		const created = { id: 9, name: 'Black cats' }
		const wrapper = await sidebar({ state: { ...SCOPE, showSaveForm: true } })
		wrapper.vm.viewsStore.createView.mockResolvedValue(created)

		nameField(wrapper).vm.$emit('update:modelValue', '  Black cats ')
		await flushPromises()
		expect(saveButton(wrapper, 'Save').attributes('disabled')).toBe('false')

		nameField(wrapper).vm.$emit('keyup', new KeyboardEvent('keyup', { key: 'Enter' }))
		await flushPromises()

		expect(wrapper.vm.viewsStore.createView).toHaveBeenCalledWith({
			name: 'Black cats',
			description: '',
			isPublic: false,
			isDefault: false,
			configuration: expect.objectContaining({ registers: [1], schemas: [2] }),
		})
		expect(wrapper.vm.viewsStore.fetchViews).toHaveBeenCalled()
		expect(OC.Notification.showTemporary).toHaveBeenCalledWith('View saved successfully!')
		expect(wrapper.find('.saveViewForm').exists()).toBe(false)
		expect(wrapper.find('.activeViewActions').exists()).toBe(true)
		expect(nameField(wrapper).attributes('modelvalue')).toBe('Black cats')
	})

	it('renames and saves the active view', async () => {
		const wrapper = await sidebar({ state: SCOPE })
		wrapper.vm.viewsStore.activeView = ACTIVE
		await flushPromises()
		wrapper.vm.viewsStore.updateView.mockResolvedValue({ ...ACTIVE, name: 'Our pets' })

		expect(nameField(wrapper).attributes('modelvalue')).toBe('My pets')

		nameField(wrapper).vm.$emit('update:modelValue', 'Our pets')
		await flushPromises()
		await saveButton(wrapper, 'Save').trigger('click')
		await flushPromises()

		expect(wrapper.vm.viewsStore.updateView).toHaveBeenCalledWith(7, expect.objectContaining({
			name: 'Our pets',
			description: 'Mine',
			isPublic: true,
		}))
		expect(OC.Notification.showTemporary).toHaveBeenCalledWith('View updated successfully!')
	})

	it('locks saving an active view whose name is blank', async () => {
		const wrapper = await sidebar({ state: SCOPE })
		wrapper.vm.viewsStore.activeView = ACTIVE
		await flushPromises()

		nameField(wrapper).vm.$emit('update:modelValue', '   ')
		await flushPromises()

		expect(saveButton(wrapper, 'Save').attributes('disabled')).toBe('true')
	})

	it('opens the edit and delete dialogs for the active view', async () => {
		const wrapper = await sidebar({ state: SCOPE })
		wrapper.vm.viewsStore.activeView = ACTIVE
		await flushPromises()

		const edit = wrapper.find('x-NcButton[aria-label="Edit view details"]')
		expect(edit.exists()).toBe(true)
		await edit.trigger('click')

		expect(wrapper.vm.showEditDialog).toBe(true)
		expect(wrapper.vm.editingView).toEqual(ACTIVE)
		expect(wrapper.findComponent({ name: 'EditView' }).exists()).toBe(true)

		await saveButton(wrapper, 'Delete').trigger('click')

		expect(wrapper.vm.showDeleteDialog).toBe(true)
		expect(wrapper.vm.viewToDelete).toEqual(ACTIVE)
	})

	it('clears the name field when the active view goes away', async () => {
		const wrapper = await sidebar()
		wrapper.vm.viewsStore.activeView = ACTIVE
		await flushPromises()
		expect(wrapper.vm.activeViewName).toBe('My pets')

		wrapper.vm.viewsStore.activeView = null
		await flushPromises()

		expect(wrapper.vm.activeViewName).toBe('')
		expect(wrapper.find('.activeViewActions').exists()).toBe(false)
	})
})

describe('search sidebar events', () => {
	it('records the open state, search terms, filters and columns the sidebar reports', async () => {
		const wrapper = await sidebar()
		const index = wrapper.findComponent({ name: 'CnIndexSidebar' })

		index.vm.$emit('update:open', false)
		index.vm.$emit('search', '  black   cat ')
		index.vm.$emit('filterChange', { key: 'size', values: ['small'] })
		index.vm.$emit('columnsChange', ['name', 'size'])
		await flushPromises()

		expect(stores.navigationStore.setSidebarState).toHaveBeenCalledWith('search', false)
		expect(stores.objectStore.updateSearchParams).toHaveBeenNthCalledWith(1, { search: '  black   cat ' })
		expect(wrapper.vm.searchTerms).toEqual(['black', 'cat'])
		expect(stores.objectStore.updateSearchParams).toHaveBeenNthCalledWith(2, {
			filters: { colour: ['black'], size: ['small'] },
		})
		expect(wrapper.vm.facetFilters).toEqual({ size: ['small'] })
		expect(stores.objectStore.refetchSearchCollection).toHaveBeenCalledTimes(2)
		expect(stores.objectStore.setSearchVisibleColumns).toHaveBeenCalledWith(['name', 'size'])
	})

	it('expands each schema the first time it is selected', async () => {
		const wrapper = await sidebar()

		await wrapper.setData({ selectedSchemas: [2], expandedSchemas: { 3: false } })
		await flushPromises()

		expect(wrapper.vm.expandedSchemas).toEqual({ 2: true, 3: false })

		await wrapper.setData({ expandedSchemas: { 2: false } })
		wrapper.vm.selectedSchemas = [2, 3]
		await flushPromises()

		expect(wrapper.vm.expandedSchemas).toEqual({ 2: false, 3: true })
	})
})

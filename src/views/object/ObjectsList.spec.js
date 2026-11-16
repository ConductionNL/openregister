/**
 * ObjectsList: loading the scoped list, the per-object actions, paging and the debounced search.
 *
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
import { flushPromises, mount } from '@vue/test-utils'
import ObjectsList from './ObjectsList.vue'
import { mockSync } from '../../composables/liveCollectionSubscription.js'
import { openAddObjectModal } from '../../composables/openAddObject.js'
import { navigationStore, objectStore } from '../../store/store.js'

jest.mock('@nextcloud/l10n', () => ({ translate: (app, text) => text }))
jest.mock('../../store/store.js', () => {
	const { reactive } = require('vue')
	return {
		objectStore: reactive({
			currentType: 'r-s',
			filters: {},
			objectItem: null,
			collection: [],
			pagination: { total: 0, pages: 1 },
			getCollection(type) { return type === 'r-s' ? this.collection : [] },
			getPagination() { return this.pagination },
			refreshObjectList: jest.fn(),
			setObjectItem: jest.fn(),
			unlockObject: jest.fn(),
		}),
		navigationStore: { setModal: jest.fn(), setDialog: jest.fn() },
	}
})
jest.mock('../../composables/openAddObject.js', () => ({ openAddObjectModal: jest.fn() }))
jest.mock('../../composables/liveCollectionSubscription.js', () => {
	const sync = jest.fn()
	return { liveCollectionLifecycle: {}, liveCollectionMethods: () => ({ syncLiveSubscription: sync }), mockSync: sync }
})
jest.mock('@conduction/nextcloud-vue', () => ({ __esModule: true, CnPagination: mockStub('CnPagination') }))
jest.mock('@nextcloud/vue', () => mockLibrary())
// The page mixes <script setup> and an options block; its compiled template
// reads the library components through 'vue'.
jest.mock('vue', () => ({ ...jest.requireActual('vue'), ...mockLibrary() }))

/**
 * A stand-in component that renders its attributes and every slot.
 *
 * @param {string} name The component name.
 * @param {string} tag The element to render.
 * @return {object} The component.
 */
function mockStub(name, tag = 'div') {
	const { h } = jest.requireActual('vue')
	return {
		name,
		inheritAttrs: false,
		render() {
			const slots = Object.values(this.$slots).map((s) => s())
			return h(tag, { ...this.$attrs, class: [`stub-${name}`, this.$attrs.class] }, slots)
		},
	}
}

/**
 * The @nextcloud/vue components the list uses, as stand-ins.
 *
 * @return {object} The module.
 */
function mockLibrary() {
	return {
		__esModule: true,
		NcAppContentList: mockStub('NcAppContentList'),
		NcTextField: mockStub('NcTextField'),
		NcActions: mockStub('NcActions'),
		NcActionButton: mockStub('NcActionButton', 'button'),
		NcListItem: mockStub('NcListItem', 'li'),
		NcLoadingIcon: mockStub('NcLoadingIcon'),
	}
}

/**
 * The action button whose text is the given label.
 *
 * @param {object} root The wrapper or element wrapper to search.
 * @param {string} label The button text.
 * @return {object} The button.
 */
function button(root, label) {
	return root.findAll('button.stub-NcActionButton').find((b) => b.text() === label)
}

/**
 * Type a search term into the search field.
 *
 * @param {object} wrapper The list wrapper.
 * @param {string} text The term.
 * @return {Promise<void>}
 */
async function type(wrapper, text) {
	wrapper.findComponent({ name: 'NcTextField' }).vm.$emit('update:modelValue', text)
	await wrapper.vm.$nextTick()
}

beforeEach(() => {
	jest.clearAllMocks()
	jest.useRealTimers()
	Object.assign(objectStore, { filters: {}, objectItem: null, collection: [], pagination: { total: 0, pages: 1 } })
	objectStore.refreshObjectList.mockResolvedValue()
})

describe('ObjectsList — loading', () => {
	it('subscribes but loads nothing until a register and schema are scoped', () => {
		const wrapper = mount(ObjectsList)

		expect(mockSync).toHaveBeenCalled()
		expect(objectStore.refreshObjectList).not.toHaveBeenCalled()
		expect(wrapper.text()).toContain('No objects have been defined yet.')
	})

	it('loads the first page of the scoped list, showing the loader meanwhile', async () => {
		objectStore.filters = { register: 'r', schema: 's' }
		let finish
		objectStore.refreshObjectList.mockReturnValue(new Promise((resolve) => { finish = resolve }))

		const wrapper = mount(ObjectsList)

		expect(objectStore.refreshObjectList).toHaveBeenCalledWith({ limit: 200, page: 1, search: '' })
		expect(wrapper.vm.loading).toBe(true)
		finish()
		await flushPromises()
		expect(wrapper.vm.loading).toBe(false)
	})
})

describe('ObjectsList — the objects', () => {
	beforeEach(() => {
		objectStore.collection = [
			{ id: 1, uuid: 'u-1', locked: false },
			{ id: 2, uuid: 'u-2', locked: true },
		]
		objectStore.objectItem = { id: 2 }
	})

	it('lists each object with its uuid, the selected one active', () => {
		const items = mount(ObjectsList).findAll('li.stub-NcListItem')

		expect(items.map((i) => i.attributes('name'))).toEqual(['1', '2'])
		expect(items[0].text()).toContain('u-1')
		expect(items.map((i) => i.attributes('active'))).toEqual(['false', 'true'])
	})

	it('offers Lock on an unlocked object and Unlock on a locked one', () => {
		const [open, locked] = mount(ObjectsList).findAll('li.stub-NcListItem')

		expect(button(open, 'Lock')).toBeDefined()
		expect(button(open, 'Unlock')).toBeUndefined()
		expect(button(locked, 'Unlock')).toBeDefined()
		expect(button(locked, 'Lock')).toBeUndefined()
	})

	it('wires every action to the object it belongs to', async () => {
		const wrapper = mount(ObjectsList)
		const [open, locked] = wrapper.findAll('li.stub-NcListItem')

		await open.trigger('click')
		expect(objectStore.setObjectItem).toHaveBeenLastCalledWith(objectStore.collection[0])

		await button(open, 'Edit').trigger('click')
		expect(navigationStore.setModal).toHaveBeenLastCalledWith('viewObject')
		await button(open, 'Lock').trigger('click')
		expect(navigationStore.setModal).toHaveBeenLastCalledWith('lockObject')
		await button(open, 'Delete').trigger('click')
		expect(navigationStore.setDialog).toHaveBeenLastCalledWith('deleteObject')
		expect(objectStore.setObjectItem).toHaveBeenLastCalledWith(objectStore.collection[0])

		await button(locked, 'Unlock').trigger('click')
		expect(objectStore.unlockObject).toHaveBeenCalledWith(2)
	})
})

describe('ObjectsList — header actions', () => {
	it('refreshes with the search term, uploads and adds', async () => {
		const wrapper = mount(ObjectsList)
		await type(wrapper, 'needle')

		await button(wrapper, 'Refresh').trigger('click')
		expect(objectStore.refreshObjectList).toHaveBeenCalledWith({ search: 'needle', page: 1 })

		await button(wrapper, 'Upload').trigger('click')
		expect(objectStore.setObjectItem).toHaveBeenCalledWith(null)
		expect(navigationStore.setModal).toHaveBeenCalledWith('uploadObject')

		await button(wrapper, 'Add Object').trigger('click')
		expect(openAddObjectModal).toHaveBeenCalledTimes(1)
	})

	it('reloads 700 ms after the last keystroke, not before', async () => {
		jest.useFakeTimers()
		const wrapper = mount(ObjectsList)

		await type(wrapper, 'ab')
		jest.advanceTimersByTime(500)
		await type(wrapper, 'abc')
		jest.advanceTimersByTime(699)
		expect(objectStore.refreshObjectList).not.toHaveBeenCalled()

		jest.advanceTimersByTime(1)
		expect(objectStore.refreshObjectList).toHaveBeenCalledTimes(1)
		expect(objectStore.refreshObjectList).toHaveBeenCalledWith({ limit: 200, page: 1, search: 'abc' })
	})

	it('clears the search from the field\'s trailing button', async () => {
		const wrapper = mount(ObjectsList)
		await type(wrapper, 'abc')
		expect(wrapper.find('.stub-NcTextField').attributes('showtrailingbutton')).toBe('true')

		wrapper.findComponent({ name: 'NcTextField' }).vm.$emit('trailingButtonClick')
		await wrapper.vm.$nextTick()

		expect(wrapper.find('.stub-NcTextField').attributes('showtrailingbutton')).toBe('false')
	})
})

describe('ObjectsList — paging', () => {
	beforeEach(() => {
		objectStore.collection = [{ id: 1, uuid: 'u-1' }]
		objectStore.pagination = { total: 450, pages: 3 }
	})

	it('shows the pager only when there are more objects than one page holds', () => {
		expect(mount(ObjectsList).text()).toContain('Page 1 of')

		objectStore.pagination = { total: 200, pages: 1 }
		expect(mount(ObjectsList).findComponent({ name: 'CnPagination' }).exists()).toBe(false)
	})

	it('loads a page when the pager moves', async () => {
		const wrapper = mount(ObjectsList)

		wrapper.findComponent({ name: 'CnPagination' }).vm.$emit('pageChanged', 3)
		await flushPromises()

		expect(objectStore.refreshObjectList).toHaveBeenLastCalledWith({ limit: 200, page: 3, search: '' })
	})

	it('a new page size refetches page 1 once, from page 1 or from a later page', async () => {
		const wrapper = mount(ObjectsList)

		wrapper.findComponent({ name: 'CnPagination' }).vm.$emit('pageSizeChanged', 50)
		await flushPromises()
		expect(objectStore.refreshObjectList).toHaveBeenCalledTimes(1)
		expect(objectStore.refreshObjectList).toHaveBeenLastCalledWith({ limit: 50, page: 1, search: '' })

		wrapper.findComponent({ name: 'CnPagination' }).vm.$emit('pageChanged', 2)
		await flushPromises()
		objectStore.refreshObjectList.mockClear()
		wrapper.findComponent({ name: 'CnPagination' }).vm.$emit('pageSizeChanged', 100)
		await flushPromises()
		expect(objectStore.refreshObjectList).toHaveBeenCalledTimes(1)
		expect(objectStore.refreshObjectList).toHaveBeenLastCalledWith({ limit: 100, page: 1, search: '' })
	})
})

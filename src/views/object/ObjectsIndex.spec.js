/**
 * ObjectsIndex: the empty state, the detail panel, and opening a deep-linked object.
 *
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
import { flushPromises, mount } from '@vue/test-utils'
import ObjectsIndex from './ObjectsIndex.vue'
import { openAddObjectModal } from '../../composables/openAddObject.js'
import { objectStore, registerStore, schemaStore } from '../../store/store.js'

jest.mock('@nextcloud/l10n', () => ({ translate: (app, text) => text }))
jest.mock('../../store/store.js', () => {
	const { reactive } = require('vue')
	return {
		objectStore: reactive({ objectItem: null, setFilters: jest.fn(), refreshObjectList: jest.fn(), setObjectItem: jest.fn() }),
		registerStore: { getRegister: jest.fn() },
		schemaStore: { getSchema: jest.fn() },
	}
})
jest.mock('../../composables/openAddObject.js', () => ({ openAddObjectModal: jest.fn() }))
jest.mock('./ObjectsList.vue', () => ({ __esModule: true, default: { name: 'ObjectsList', render: () => null } }))
jest.mock('./ObjectDetails.vue', () => ({ __esModule: true, default: { name: 'ObjectDetails', render: () => null } }))
jest.mock('vue-material-design-icons/DatabaseOutline.vue', () => ({ __esModule: true, default: { name: 'DatabaseOutline', render: () => null } }))
jest.mock('@nextcloud/vue', () => ({
	__esModule: true,
	NcAppContent: mockStub('NcAppContent'),
	NcEmptyContent: mockStub('NcEmptyContent'),
	NcButton: mockStub('NcButton', 'button'),
}))
// The page mixes <script setup> and an options block; its compiled template
// reads the library components through 'vue'.
jest.mock('vue', () => ({
	...jest.requireActual('vue'),
	NcAppContent: mockStub('NcAppContent'),
	NcEmptyContent: mockStub('NcEmptyContent'),
	NcButton: mockStub('NcButton', 'button'),
}))

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
 * Mount the view on a route.
 *
 * @param {object} route The route ({path, name, params}).
 * @return {object} The wrapper.
 */
function mountOn(route) {
	return mount(ObjectsIndex, { global: { config: { globalProperties: { $route: route } } } })
}

const deepLink = { path: '/objects/reg one/sch/o-1', name: 'objectDetail', params: { register: 'reg one', schema: 'sch', id: 'o/1' } }

beforeEach(() => {
	jest.clearAllMocks()
	objectStore.objectItem = null
	objectStore.refreshObjectList.mockResolvedValue()
	registerStore.getRegister.mockResolvedValue()
	schemaStore.getSchema.mockResolvedValue()
	globalThis.fetch = jest.fn()
	jest.spyOn(console, 'error').mockImplementation(() => {})
})

afterEach(() => {
	console.error.mockRestore()
})

describe('ObjectsIndex — what the panel shows', () => {
	it('shows the empty state while no object is selected, and its button opens the add modal', async () => {
		const wrapper = mountOn({ path: '/objects', params: {} })

		expect(wrapper.find('.stub-NcEmptyContent').exists()).toBe(true)
		expect(wrapper.findComponent({ name: 'ObjectDetails' }).exists()).toBe(false)

		await wrapper.find('button.stub-NcButton').trigger('click')
		expect(openAddObjectModal).toHaveBeenCalledTimes(1)
	})

	it('shows the details of the selected object on the objects route', () => {
		objectStore.objectItem = { id: 'o-1' }
		const wrapper = mountOn({ path: '/objects', params: {} })

		expect(wrapper.findComponent({ name: 'ObjectDetails' }).exists()).toBe(true)
		expect(wrapper.find('.stub-NcEmptyContent').exists()).toBe(false)
	})

	it('falls back to the empty state on another route that shares the view', () => {
		objectStore.objectItem = { id: 'o-1' }
		const wrapper = mountOn({ path: '/registers/5', name: 'registerDetail', params: {} })

		expect(wrapper.findComponent({ name: 'ObjectDetails' }).exists()).toBe(false)
		expect(wrapper.find('.stub-NcEmptyContent').exists()).toBe(true)
	})
})

describe('ObjectsIndex — opening a deep link', () => {
	it('does nothing without a register, schema and id', async () => {
		mountOn({ path: '/objects', params: { register: 'r' } })
		await flushPromises()

		expect(objectStore.setFilters).not.toHaveBeenCalled()
		expect(globalThis.fetch).not.toHaveBeenCalled()
	})

	it('primes the list, loads its context and opens the fetched object', async () => {
		globalThis.fetch.mockResolvedValue({ ok: true, json: async () => ({ id: 'o/1', title: 'Deep' }) })

		mountOn(deepLink)
		await flushPromises()

		expect(objectStore.setFilters).toHaveBeenCalledWith({ register: 'reg one', schema: 'sch' })
		expect(registerStore.getRegister).toHaveBeenCalledWith('reg one')
		expect(schemaStore.getSchema).toHaveBeenCalledWith('sch', { setItem: true })
		expect(objectStore.refreshObjectList).toHaveBeenCalled()
		const [url, init] = globalThis.fetch.mock.calls[0]
		expect(url).toBe('/index.php/apps/openregister/api/objects/reg%20one/sch/o%2F1?_extend=@self.can')
		expect(init.headers).toEqual({ Accept: 'application/json', 'OCS-APIRequest': 'true', requesttoken: 'test-request-token' })
		expect(objectStore.setObjectItem).toHaveBeenCalledWith({ id: 'o/1', title: 'Deep' })
	})

	it('still opens the object when its context fails to load', async () => {
		registerStore.getRegister.mockRejectedValue(new Error('gone'))
		objectStore.refreshObjectList.mockRejectedValue(new Error('down'))
		globalThis.fetch.mockResolvedValue({ ok: true, json: async () => ({ id: 'o/1' }) })

		mountOn(deepLink)
		await flushPromises()

		expect(objectStore.setObjectItem).toHaveBeenCalledWith({ id: 'o/1' })
	})

	it('logs a refused fetch and opens nothing', async () => {
		globalThis.fetch.mockResolvedValue({ ok: false, status: 403 })

		mountOn(deepLink)
		await flushPromises()

		expect(objectStore.setObjectItem).not.toHaveBeenCalled()
		expect(console.error).toHaveBeenCalledWith(expect.stringContaining('returned 403'))
	})

	it('logs a fetch that throws', async () => {
		globalThis.fetch.mockRejectedValue(new Error('offline'))

		mountOn(deepLink)
		await flushPromises()

		expect(objectStore.setObjectItem).not.toHaveBeenCalled()
		expect(console.error).toHaveBeenCalledWith('[ObjectsIndex] deep-link fetch failed', expect.any(Error))
	})

	it('reloads when the route id changes', async () => {
		globalThis.fetch.mockResolvedValue({ ok: true, json: async () => ({}) })
		const wrapper = mountOn(deepLink)
		await flushPromises()

		wrapper.vm.$options.watch['$route.params.id'].handler.call(wrapper.vm)
		await flushPromises()

		expect(globalThis.fetch).toHaveBeenCalledTimes(2)
	})
})

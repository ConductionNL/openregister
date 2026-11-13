import axios from '@nextcloud/axios'
/**
 * The export dialog mounted: the register and schema it names, the format it
 * offers, the download it starts with the server's or a built file name, and
 * the errors it shows when the selection is incomplete or the export fails.
 *
 * @spec exclude Client-side export dialog.
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
import { flushPromises, mount } from '@vue/test-utils'
import ExportRegister from './ExportRegister.vue'

jest.mock('@nextcloud/axios', () => ({ __esModule: true, default: jest.fn() }))
jest.mock('@nextcloud/l10n', () => ({
	translate: (app, text, vars) => `t:${text}${vars ? JSON.stringify(vars) : ''}`,
}))
jest.mock('@nextcloud/router', () => ({ generateUrl: (path) => `/index.php${path}` }))
jest.mock('@nextcloud/vue', () => mockLibrary())
// The dialog mixes <script setup> with <script>; under vue3-jest the template
// reads the library components through the `vue` module.
jest.mock('vue', () => ({
	...jest.requireActual('vue'),
	NcDialog: mockLibrary().NcDialog,
	NcNoteCard: mockLibrary().NcNoteCard,
	NcSelect: mockLibrary().NcSelect,
}))
jest.mock('vue-material-design-icons/Export.vue', () => mockModule('Export'))
jest.mock('../../components/shared/BusyButton.vue', () => mockModule('BusyButton'))
jest.mock('../../components/shared/CloseCancelButton.vue', () => mockModule('CloseCancelButton'))
jest.mock('../../store/store.js', () => {
	const { reactive } = jest.requireActual('vue')
	return {
		navigationStore: reactive({ modal: 'exportRegister', setModal: jest.fn() }),
		registerStore: reactive({ registerItem: null }),
		schemaStore: reactive({ schemaItem: null }),
	}
})

const { navigationStore, registerStore, schemaStore } = jest.requireMock('../../store/store.js')

/**
 * Library stand-ins: every named export is a stub of that name.
 *
 * @return {object} The module
 */
function mockLibrary() {
	const made = {}
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
 * A stand-in component module.
 *
 * @param {string} name The component
 * @return {object} The module
 */
function mockModule(name) {
	return { __esModule: true, default: mockStub(name) }
}

/**
 * A stand-in for a component: its tag, plain attributes and every slot.
 *
 * @param {string} name The component name
 * @return {object} The stub
 */
function mockStub(name) {
	const { h: render } = jest.requireActual('vue')
	return {
		name,
		inheritAttrs: false,
		render() {
			const attrs = Object.fromEntries(
				Object.entries(this.$attrs)
					.filter(([key, value]) => !/^on[A-Z]/.test(key) && typeof value !== 'function')
					.map(([key, value]) => [key, typeof value === 'object' ? JSON.stringify(value) : value]),
			)
			const slots = Object.entries(this.$slots).map(([slot, fn]) =>
				render('section', { 'data-slot': slot }, fn()),
			)
			return render(`x-${name}`, attrs, slots)
		},
	}
}

/**
 * Mount the dialog.
 *
 * @return {object} The wrapper
 */
function open() {
	return mount(ExportRegister, {
		global: { config: { globalProperties: { t: (app, text) => `t:${text}` } } },
	})
}

const exportButton = (wrapper) => wrapper.findComponent({ name: 'BusyButton' })

let links
beforeEach(() => {
	jest.clearAllMocks()
	navigationStore.modal = 'exportRegister'
	registerStore.registerItem = { id: 3, slug: 'people', title: 'People' }
	schemaStore.schemaItem = { id: 8, title: 'Person' }
	window.URL.createObjectURL = jest.fn(() => 'blob:export')
	window.URL.revokeObjectURL = jest.fn()
	links = []
	jest.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(function () {
		links.push({ href: this.href, download: this.download, attached: document.body.contains(this) })
	})
})

afterEach(() => {
	jest.restoreAllMocks()
})

describe('ExportRegister mounted', () => {
	it('renders nothing while another modal is open', () => {
		navigationStore.modal = 'importRegister'
		expect(open().findComponent({ name: 'NcDialog' }).exists()).toBe(false)
	})

	it('names the schema and register and offers Excel and CSV, Excel first', () => {
		const wrapper = open()
		expect(wrapper.findComponent({ name: 'NcDialog' }).attributes('title')).toBe('t:Export Objects')
		expect(wrapper.get('.formContainer p').text()).toBe(
			't:Export "{schema}" objects from "{register}"{"schema":"Person","register":"People"}',
		)
		const select = wrapper.findComponent({ name: 'NcSelect' })
		expect(select.vm.$attrs.options).toEqual([
			{ label: 'Excel', value: 'excel' },
			{ label: 'CSV', value: 'csv' },
		])
		expect(select.vm.$attrs.modelValue).toBe('excel')
		expect(select.vm.$attrs.reduce({ label: 'CSV', value: 'csv' })).toBe('csv')
		expect(wrapper.findComponent({ name: 'NcNoteCard' }).exists()).toBe(false)
	})

	it('says Unknown for a missing register or schema', () => {
		registerStore.registerItem = null
		schemaStore.schemaItem = {}
		expect(open().get('.formContainer p').text()).toContain('{"schema":"Unknown","register":"Unknown"}')
	})

	it('refuses to export without both ids and shows why', async () => {
		schemaStore.schemaItem = { title: 'Person' }
		const wrapper = open()
		await exportButton(wrapper).vm.$emit('click')
		await flushPromises()
		expect(axios).not.toHaveBeenCalled()
		expect(wrapper.findComponent({ name: 'NcNoteCard' }).text()).toBe('t:Register and schema are required')
	})

	it('downloads the chosen format under the server file name and closes', async () => {
		axios.mockResolvedValue({
			data: 'a;b',
			headers: { 'content-type': 'text/csv', 'content-disposition': 'attachment; filename="people.csv"' },
		})
		const wrapper = open()
		await wrapper.findComponent({ name: 'NcSelect' }).vm.$emit('update:modelValue', 'csv')
		await exportButton(wrapper).vm.$emit('click')
		await flushPromises()

		expect(axios).toHaveBeenCalledWith({
			url: '/index.php/apps/openregister/api/objects/people/8/export',
			method: 'GET',
			params: { type: 'csv' },
			responseType: 'blob',
		})
		const blob = window.URL.createObjectURL.mock.calls[0][0]
		expect(blob.type).toBe('text/csv')
		expect(links).toEqual([{ href: 'blob:export', download: 'people.csv', attached: true }])
		expect(document.querySelector('a[download]')).toBeNull()
		expect(window.URL.revokeObjectURL).toHaveBeenCalledWith('blob:export')
		expect(navigationStore.setModal).toHaveBeenCalledWith(false)
		expect(wrapper.vm.exportFormat).toBe('excel')
		expect(exportButton(wrapper).vm.$attrs.busy).toBe(false)
	})

	it('builds a file name from the slugs, date and format when the server gives none', async () => {
		jest.useFakeTimers().setSystemTime(new Date('2026-03-04T10:00:00Z'))
		axios.mockResolvedValue({ data: 'x', headers: { 'content-type': 'application/vnd.ms-excel' } })
		const wrapper = open()
		await exportButton(wrapper).vm.$emit('click')
		await flushPromises()
		jest.useRealTimers()
		expect(links).toHaveLength(1)
		expect(links[0].download).toBe('people_8_2026-03-04.xlsx')
	})

	it.each([
		[{ response: { data: { error: 'Schema has no objects' } }, message: 'ignored' }, 'Schema has no objects'],
		[{ message: 'Network Error' }, 'Network Error'],
		[{}, 't:Failed to export objects'],
	])('shows why the export failed %#', async (failure, message) => {
		axios.mockRejectedValue(Object.assign(new Error(), failure))
		const wrapper = open()
		await exportButton(wrapper).vm.$emit('click')
		await flushPromises()
		expect(wrapper.findComponent({ name: 'NcNoteCard' }).text()).toBe(message)
		expect(navigationStore.setModal).not.toHaveBeenCalled()
		expect(exportButton(wrapper).vm.$attrs.busy).toBe(false)
	})

	it('cancel closes the dialog and resets the format', async () => {
		const wrapper = open()
		await wrapper.findComponent({ name: 'NcSelect' }).vm.$emit('update:modelValue', 'csv')
		wrapper.findComponent({ name: 'CloseCancelButton' }).vm.$emit('click')
		expect(navigationStore.setModal).toHaveBeenCalledWith(false)
		expect(wrapper.vm.exportFormat).toBe('excel')
	})
})

/**
 * Golden record of how the Files sidebar tabs load their data for a file:
 * the state they move through on success, on an API refusal and on a
 * network failure.
 *
 * @spec exclude Golden record of client-side Files sidebar loading.
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
import axios from '@nextcloud/axios'
import ExtractionTab from './ExtractionTab.vue'
import RegisterObjectsTab from './RegisterObjectsTab.vue'

jest.mock('@nextcloud/axios', () => ({
	__esModule: true,
	default: { get: jest.fn(), post: jest.fn() },
}))
jest.mock('@nextcloud/router', () => ({
	generateUrl: (path, params) =>
		path.replace(/\{(\w+)\}/g, (_m, key) => String(params[key])),
}))
jest.mock('@nextcloud/l10n', () => ({
	translate: (app, text) => `t:${text}`,
	translatePlural: (app, one) => one,
}))
jest.mock('@nextcloud/vue', () => ({}))
jest.mock('vue-material-design-icons/DatabaseOffOutline.vue', () => ({}))
jest.mock('vue-material-design-icons/FileSearchOutline.vue', () => ({}))
jest.mock('../shared/TabErrorState.vue', () => ({}))

const TABS = [
	[
		'RegisterObjectsTab',
		RegisterObjectsTab,
		'fetchObjects',
		'/apps/openregister/api/files/7/objects',
		(vm) => vm.objects,
	],
	[
		'ExtractionTab',
		ExtractionTab,
		'fetchExtractionStatus',
		'/apps/openregister/api/files/7/extraction-status',
		(vm) => vm.status,
	],
]

/**
 * A bare instance of a tab for file 7.
 *
 * @param {object} component The component options
 * @return {object} The instance
 */
function tabFor(component) {
	return { ...component.methods, ...component.data(), fileId: 7 }
}

describe.each(TABS)('%s loading', (_name, component, fetch, url, loaded) => {
	let logged

	beforeEach(() => {
		axios.get.mockReset()
		logged = jest.spyOn(console, 'error').mockImplementation(() => {})
	})

	afterEach(() => {
		logged.mockRestore()
	})

	it('keeps the data the API returns', async () => {
		const data = [{ uuid: 'a' }]
		axios.get.mockResolvedValue({ data: { success: true, data } })
		const vm = tabFor(component)
		vm.error = true
		vm.errorMessage = 'old'

		const pending = vm[fetch]()
		expect(vm.loading).toBe(true)
		expect(vm.error).toBe(false)
		expect(vm.errorMessage).toBe('')
		await pending

		expect(axios.get).toHaveBeenCalledWith(url)
		expect(loaded(vm)).toEqual(data)
		expect(vm.loading).toBe(false)
		expect(vm.error).toBe(false)
		expect(logged).not.toHaveBeenCalled()
	})

	it('shows the error the API names', async () => {
		axios.get.mockResolvedValue({ data: { success: false, error: 'nope' } })
		const vm = tabFor(component)

		await vm[fetch]()

		expect(vm.error).toBe(true)
		expect(vm.errorMessage).toBe('nope')
		expect(loaded(vm)).toEqual(loaded(tabFor(component)))
		expect(vm.loading).toBe(false)
	})

	it('falls back to an unknown error', async () => {
		axios.get.mockResolvedValue({ data: {} })
		const vm = tabFor(component)

		await vm[fetch]()

		expect(vm.error).toBe(true)
		expect(vm.errorMessage).toBe('t:Unknown error')
	})

	it('shows the response error of a failed request and logs it', async () => {
		const err = Object.assign(new Error('boom'), {
			response: { data: { error: 'denied' } },
		})
		axios.get.mockRejectedValue(err)
		const vm = tabFor(component)

		await vm[fetch]()

		expect(vm.error).toBe(true)
		expect(vm.errorMessage).toBe('denied')
		expect(vm.loading).toBe(false)
		expect(logged).toHaveBeenCalledTimes(1)
		expect(logged.mock.calls[0][0]).toMatch(`[${_name}] Failed to fetch`)
		expect(logged.mock.calls[0][1]).toBe(err)
	})

	it('shows the message of a request without a response', async () => {
		axios.get.mockRejectedValue(new Error('offline'))
		const vm = tabFor(component)

		await vm[fetch]()

		expect(vm.errorMessage).toBe('offline')
	})
})

describe('RegisterObjectsTab', () => {
	it('drops the objects of the previous file before loading', async () => {
		let resolve
		axios.get.mockReturnValue(new Promise((r) => (resolve = r)))
		const vm = tabFor(RegisterObjectsTab)
		vm.objects = [{ uuid: 'old' }]

		const pending = vm.fetchObjects()
		expect(vm.objects).toEqual([])
		resolve({ data: { success: true } })
		await pending

		expect(vm.objects).toEqual([])
	})
})

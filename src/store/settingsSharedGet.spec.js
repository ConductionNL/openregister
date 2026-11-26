/**
 * Settings sections that mount together share an in-flight GET: the admin
 * settings page read GET /api/settings and GET /api/files/stats twice each.
 *
 * @spec openspec/specs/frontend-store-client-state/spec.md
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
import axios from '@nextcloud/axios'
import { createPinia, setActivePinia } from 'pinia'
import { sharedGet, useSettingsStore } from './settings.js'

jest.mock('@nextcloud/axios', () => ({
	__esModule: true,
	default: { get: jest.fn(), post: jest.fn(), put: jest.fn(), patch: jest.fn() },
}))
jest.mock('@nextcloud/dialogs', () => ({ __esModule: true, showError: jest.fn(), showSuccess: jest.fn() }))
jest.mock('@nextcloud/router', () => ({ generateUrl: (url) => `/gen${url}` }))

/**
 * A promise with its resolve function, to answer a request when the test says so.
 *
 * @return {{promise: Promise, resolve: Function}}
 */
function deferred() {
	let resolve
	const promise = new Promise((done) => {
		resolve = done
	})
	return { promise, resolve }
}

/**
 * Let pending promise callbacks run.
 *
 * @return {Promise<void>}
 */
function settle() {
	return new Promise((done) => setTimeout(done, 0))
}

/**
 * An Options-API component instance over plain state, its methods bound to it.
 *
 * @param {object} component The component definition
 * @param {object} state Extra state
 * @return {object} The instance
 */
function instance(component, state = {}) {
	const vm = { ...state }
	for (const [name, method] of Object.entries(component.methods)) {
		vm[name] = method.bind(vm)
	}
	return vm
}

beforeEach(() => {
	jest.clearAllMocks()
	setActivePinia(createPinia())
})

describe('sharedGet', () => {
	it('sends one GET for callers while it is in flight, and a new one after it settled', async () => {
		const first = deferred()
		axios.get.mockReturnValueOnce(first.promise).mockResolvedValueOnce({ data: 'second' })

		const a = sharedGet('settings')
		const b = sharedGet('settings')
		expect(axios.get).toHaveBeenCalledTimes(1)
		expect(axios.get).toHaveBeenCalledWith('/gen/apps/openregister/api/settings')

		first.resolve({ data: 'first' })
		await expect(a).resolves.toEqual({ data: 'first' })
		await expect(b).resolves.toEqual({ data: 'first' })

		await expect(sharedGet('settings')).resolves.toEqual({ data: 'second' })
		expect(axios.get).toHaveBeenCalledTimes(2)
	})

	it('keeps different paths apart and lets a failed read be asked again', async () => {
		axios.get.mockRejectedValueOnce(new Error('500')).mockResolvedValue({ data: {} })

		await expect(sharedGet('files/stats')).rejects.toThrow('500')
		await sharedGet('files/stats')
		await sharedGet('settings')

		expect(axios.get.mock.calls.map(([url]) => url)).toEqual([
			'/gen/apps/openregister/api/files/stats',
			'/gen/apps/openregister/api/files/stats',
			'/gen/apps/openregister/api/settings',
		])
	})

	it('serves the flow and audit sections, which load together, from one GET /api/settings', async () => {
		axios.get.mockResolvedValue({ data: { flow: { keepDays: 30 }, audit: { readEventsVisibleTo: ['admin'] }, availableGroups: {} } })
		const store = useSettingsStore()

		await Promise.all([store.loadFlowSettings(), store.loadAuditSettings()])

		expect(axios.get).toHaveBeenCalledTimes(1)
		expect(store.flowOptions.keepDays).toBe(30)
	})

	it('serves two sections asking for the extraction stats at once from one GET /api/files/stats', async () => {
		axios.get.mockResolvedValue({ data: { data: { totalFiles: 3 } } })
		const store = useSettingsStore()

		const [a, b] = await Promise.all([store.getExtractionStats(), store.getExtractionStats()])

		expect(axios.get).toHaveBeenCalledTimes(1)
		expect(a).toEqual({ totalFiles: 3 })
		expect(b).toEqual({ totalFiles: 3 })
	})
})

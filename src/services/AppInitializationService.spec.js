import * as stores from '../store/store.js'
import { initializeAppData, isAppDataLoaded, reloadAppData } from './AppInitializationService.js'
import defaultExport from './AppInitializationService.js'

// Only the store edge is replaced: plain objects with the lists and the
// refresh actions the service reads and calls.
jest.mock('../store/store.js', () => ({
	__esModule: true,
	registerStore: { registerList: [], refreshRegisterList: jest.fn() },
	schemaStore: { schemaList: [], refreshSchemaList: jest.fn() },
	organisationStore: {
		organisationList: [],
		activeOrganisation: null,
		refreshOrganisationList: jest.fn(),
		getActiveOrganisation: jest.fn(),
		setActiveOrganisation: jest.fn(),
	},
	applicationStore: { applicationList: [], refreshApplicationList: jest.fn() },
	viewsStore: { fetchViews: jest.fn() },
	sourceStore: { sourceList: [], refreshSourceList: jest.fn() },
}))

const { registerStore, schemaStore, organisationStore, applicationStore, viewsStore, sourceStore } = stores

/**
 * Every refresh action the service may call.
 *
 * @return {Array<Function>}
 */
function allRefreshes () {
  return [
	registerStore.refreshRegisterList,
	schemaStore.refreshSchemaList,
	organisationStore.refreshOrganisationList,
	organisationStore.getActiveOrganisation,
	applicationStore.refreshApplicationList,
	viewsStore.fetchViews,
	sourceStore.refreshSourceList,
]
}

describe('AppInitializationService', () => {
	let errorSpy

	beforeEach(() => {
		jest.clearAllMocks()
		allRefreshes().forEach((fn) => fn.mockResolvedValue(undefined))
		registerStore.registerList = []
		schemaStore.schemaList = []
		organisationStore.organisationList = []
		organisationStore.activeOrganisation = null
		applicationStore.applicationList = []
		sourceStore.sourceList = []
		errorSpy = jest.spyOn(console, 'error').mockImplementation(() => {})
	})

	afterEach(() => {
		errorSpy.mockRestore()
	})

	describe('initializeAppData', () => {
		it('loads the shell stores and the active organisation, not applications, views or sources', async () => {
			await initializeAppData()
			expect(registerStore.refreshRegisterList).toHaveBeenCalledTimes(1)
			expect(schemaStore.refreshSchemaList).toHaveBeenCalledTimes(1)
			expect(organisationStore.refreshOrganisationList).toHaveBeenCalledTimes(1)
			expect(organisationStore.getActiveOrganisation).toHaveBeenCalledTimes(1)
			expect(applicationStore.refreshApplicationList).not.toHaveBeenCalled()
			expect(viewsStore.fetchViews).not.toHaveBeenCalled()
			expect(sourceStore.refreshSourceList).not.toHaveBeenCalled()
			expect(errorSpy).not.toHaveBeenCalled()
		})

		it('treats a missing organisation list as empty', async () => {
			organisationStore.organisationList = undefined
			await initializeAppData()
			expect(organisationStore.refreshOrganisationList).toHaveBeenCalledTimes(1)
		})

		it('skips stores that are already loaded', async () => {
			registerStore.registerList = [{ id: 1 }]
			schemaStore.schemaList = [{ id: 1 }]
			organisationStore.organisationList = [{ id: 1 }]
			organisationStore.activeOrganisation = { id: 1 }
			await initializeAppData()
			allRefreshes().forEach((fn) => expect(fn).not.toHaveBeenCalled())
		})

		it('takes the active organisation from the list it just read, without a second request', async () => {
			organisationStore.refreshOrganisationList.mockResolvedValue({ data: { total: 1, active: { id: 7 }, results: [{ id: 7 }] } })
			await initializeAppData()
			expect(organisationStore.refreshOrganisationList).toHaveBeenCalledTimes(1)
			expect(organisationStore.setActiveOrganisation).toHaveBeenCalledWith({ id: 7 })
			expect(organisationStore.getActiveOrganisation).not.toHaveBeenCalled()
		})

		it('sends no second request when the list says no organisation is active', async () => {
			organisationStore.refreshOrganisationList.mockResolvedValue({ data: { total: 0, active: null, results: [] } })
			await initializeAppData()
			expect(organisationStore.setActiveOrganisation).not.toHaveBeenCalled()
			expect(organisationStore.getActiveOrganisation).not.toHaveBeenCalled()
		})

		it('asks for the active organisation when the list answer does not carry it', async () => {
			organisationStore.refreshOrganisationList.mockResolvedValue({ data: { total: 0, results: [] } })
			await initializeAppData()
			expect(organisationStore.getActiveOrganisation).toHaveBeenCalledTimes(1)
		})

		it('fetches the active organisation when the list is loaded but none is active', async () => {
			organisationStore.organisationList = [{ id: 1 }]
			await initializeAppData()
			expect(organisationStore.refreshOrganisationList).not.toHaveBeenCalled()
			expect(organisationStore.getActiveOrganisation).toHaveBeenCalledTimes(1)
		})

		it('swallows a views failure without logging an init error', async () => {
			viewsStore.fetchViews.mockRejectedValue(new Error('views off'))
			await expect(initializeAppData()).resolves.toBeUndefined()
			expect(errorSpy).not.toHaveBeenCalled()
		})

		it('logs, and does not rethrow, a failing store load', async () => {
			const boom = new Error('registers down')
			registerStore.refreshRegisterList.mockRejectedValue(boom)
			await expect(initializeAppData()).resolves.toBeUndefined()
			expect(errorSpy).toHaveBeenCalledWith('[AppInit] ✗ Error during initialization:', boom)
		})
	})

	describe('reloadAppData', () => {
		it('force-refreshes every store even when already loaded', async () => {
			registerStore.registerList = [{ id: 1 }]
			schemaStore.schemaList = [{ id: 1 }]
			organisationStore.organisationList = [{ id: 1 }]
			organisationStore.activeOrganisation = { id: 1 }
			applicationStore.applicationList = [{ id: 1 }]
			sourceStore.sourceList = [{ id: 1 }]
			await reloadAppData()
			allRefreshes().forEach((fn) => expect(fn).toHaveBeenCalledTimes(1))
		})

		it('swallows a views failure during reload', async () => {
			viewsStore.fetchViews.mockRejectedValue(new Error('views off'))
			await reloadAppData()
			expect(errorSpy).not.toHaveBeenCalled()
		})

		it('logs, and does not rethrow, a failing reload', async () => {
			const boom = new Error('sources down')
			sourceStore.refreshSourceList.mockRejectedValue(boom)
			await expect(reloadAppData()).resolves.toBeUndefined()
			expect(errorSpy).toHaveBeenCalledWith('[AppInit] ✗ Error during reload:', boom)
		})
	})

	describe('isAppDataLoaded', () => {
		it('is false while registers or schemas are empty', () => {
			expect(isAppDataLoaded()).toBe(false)
			registerStore.registerList = [{ id: 1 }]
			expect(isAppDataLoaded()).toBe(false)
			registerStore.registerList = []
			schemaStore.schemaList = [{ id: 1 }]
			expect(isAppDataLoaded()).toBe(false)
		})

		it('is true with registers and schemas, allowing zero organisations and applications', () => {
			registerStore.registerList = [{ id: 1 }]
			schemaStore.schemaList = [{ id: 1 }]
			expect(isAppDataLoaded()).toBe(true)
		})

		it('is false when the organisation or application list is missing', () => {
			registerStore.registerList = [{ id: 1 }]
			schemaStore.schemaList = [{ id: 1 }]
			organisationStore.organisationList = undefined
			expect(isAppDataLoaded()).toBe(false)
			organisationStore.organisationList = []
			applicationStore.applicationList = undefined
			expect(isAppDataLoaded()).toBe(false)
		})
	})

	it('default export exposes the same three functions', () => {
		expect(defaultExport).toEqual({ initializeAppData, reloadAppData, isAppDataLoaded })
	})
})

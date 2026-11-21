import axios from '@nextcloud/axios'
import OrganisationConfiguration from './OrganisationConfiguration.vue'

/**
 * The organisation settings section's change detection, loads, save, reset
 * and refresh.
 *
 * @spec exclude Client-side behaviour of the organisation settings section.
 */

jest.mock('@nextcloud/axios', () => ({ __esModule: true, default: { get: jest.fn(), put: jest.fn() } }))
jest.mock('@nextcloud/router', () => ({ __esModule: true, generateUrl: (url) => `/gen${url}` }))
jest.mock('@nextcloud/l10n', () => ({ __esModule: true, translate: (app, text) => `t:${text}` }))
jest.mock('@nextcloud/vue', () => ({ __esModule: true }))

/**
 * A bare section state carrying the section's methods and computed getters.
 *
 * @param {object} data Overrides of the section data
 * @return {object} The section
 */
function section(data = {}) {
	const state = { t: (app, text) => `this.t:${text}`, ...OrganisationConfiguration.data(), ...data }
	for (const [name, fn] of Object.entries(OrganisationConfiguration.methods)) {
		state[name] = fn.bind(state)
	}
	for (const [name, getter] of Object.entries(OrganisationConfiguration.computed)) {
		Object.defineProperty(state, name, { get: getter.bind(state), configurable: true })
	}
	return state
}

/**
 * Answer each GET by its path.
 *
 * @param {object} answers Path to answer data, or an Error to reject with
 */
function answer(answers) {
	axios.get.mockImplementation((url) => {
		const value = answers[url]
		return value instanceof Error ? Promise.reject(value) : Promise.resolve({ data: value })
	})
}

const ORGS = [{ uuid: 'u1', name: 'One' }, { uuid: 'u2', name: 'Two' }]

let logged

beforeEach(() => {
	jest.clearAllMocks()
	jest.useRealTimers()
	logged = jest.spyOn(console, 'error').mockImplementation(() => {})
})

afterEach(() => {
	logged.mockRestore()
})

describe('OrganisationConfiguration state', () => {
	it('offers each organisation by uuid and name', () => {
		expect(section({ organisations: ORGS }).organisationOptions).toEqual([
			{ uuid: 'u1', name: 'One', id: 'u1', label: 'One' },
			{ uuid: 'u2', name: 'Two', id: 'u2', label: 'Two' },
		])
	})

	it('sees a change in the default organisation or the auto-create switch', () => {
		const state = section({ selectedDefaultOrganisation: { uuid: 'u1' }, originalDefaultOrganisation: { uuid: 'u1' } })
		expect(state.hasChanges).toBe(false)
		state.selectedDefaultOrganisation = { uuid: 'u2' }
		expect(state.hasChanges).toBe(true)
		state.selectedDefaultOrganisation = { uuid: 'u1' }
		state.autoCreateDefault = false
		expect(state.hasChanges).toBe(true)
	})

	it('clears the save state when a value changes', () => {
		const state = section({ saveSuccess: true, saveError: 'x' })
		state.handleDefaultOrganisationChange({ uuid: 'u2' })
		expect([state.selectedDefaultOrganisation, state.saveSuccess, state.saveError]).toEqual([{ uuid: 'u2' }, false, null])
		state.saveSuccess = true
		state.saveError = 'y'
		state.handleAutoCreateDefaultChange(false)
		expect([state.autoCreateDefault, state.saveSuccess, state.saveError]).toEqual([false, false, null])
	})
})

describe('OrganisationConfiguration loads', () => {
	it('loads the organisations, then selects the stored default and keeps it as the original', async () => {
		answer({
			'/gen/apps/openregister/api/organisations': { results: ORGS },
			'/gen/apps/openregister/api/settings/organisation': { organisation: { default_organisation: 'u2', auto_create_default_organisation: false } },
			'/gen/apps/openregister/api/organisations/statistics': { total: 2, active: 1, totalMembers: 5, avgMembers: 2.5 },
		})
		const state = section()
		await state.loadOrganisations()
		await state.loadSettings()
		await state.loadStatistics()
		expect(state.organisations).toEqual(ORGS)
		expect(state.loadingOrganisations).toBe(false)
		expect(state.selectedDefaultOrganisation).toEqual({ uuid: 'u2', name: 'Two', id: 'u2', label: 'Two' })
		expect(state.originalDefaultOrganisation).toEqual(state.selectedDefaultOrganisation)
		expect(state.originalDefaultOrganisation).not.toBe(state.selectedDefaultOrganisation)
		expect([state.autoCreateDefault, state.originalAutoCreateDefault]).toEqual([false, false])
		expect(state.stats).toEqual({ totalOrganisations: 2, activeOrganisations: 1, totalMembers: 5, averageMembersPerOrg: 2.5 })
	})

	it('keeps auto-create on unless the setting is explicitly false, and selects nothing unknown', async () => {
		answer({ '/gen/apps/openregister/api/settings/organisation': { organisation: { default_organisation: 'gone' } } })
		const state = section({ organisations: ORGS, autoCreateDefault: false })
		await state.loadSettings()
		expect(state.autoCreateDefault).toBe(true)
		expect(state.selectedDefaultOrganisation).toBeNull()
	})

	it('zeroes missing statistics', async () => {
		answer({ '/gen/apps/openregister/api/organisations/statistics': {} })
		const state = section()
		await state.loadStatistics()
		expect(state.stats).toEqual({ totalOrganisations: 0, activeOrganisations: 0, totalMembers: 0, averageMembersPerOrg: 0 })
	})

	it('keeps the list without results, and shows an error when the organisations fail to load', async () => {
		answer({ '/gen/apps/openregister/api/organisations': {} })
		const state = section({ organisations: ['kept'] })
		await state.loadOrganisations()
		expect(state.organisations).toEqual(['kept'])
		expect(state.saveError).toBeNull()
		answer({ '/gen/apps/openregister/api/organisations': new Error('down') })
		await state.loadOrganisations()
		expect(state.saveError).toBe('this.t:Failed to load organisations')
		expect(state.loadingOrganisations).toBe(false)
	})

	it('only logs failed settings and statistics loads', async () => {
		answer({
			'/gen/apps/openregister/api/settings/organisation': new Error('a'),
			'/gen/apps/openregister/api/organisations/statistics': new Error('b'),
		})
		const state = section()
		await state.loadSettings()
		await state.loadStatistics()
		expect(state.saveError).toBeNull()
		expect(logged.mock.calls.map(([text]) => text)).toEqual(['Error loading settings:', 'Error loading statistics:'])
	})

	it('refreshes by clearing the save state and loading all three', async () => {
		answer({})
		const state = section({ saveSuccess: true, saveError: 'x' })
		await state.refreshData()
		expect([state.saveSuccess, state.saveError]).toEqual([false, null])
		expect(axios.get.mock.calls.map(([url]) => url).sort()).toEqual([
			'/gen/apps/openregister/api/organisations',
			'/gen/apps/openregister/api/organisations/statistics',
			'/gen/apps/openregister/api/settings/organisation',
		])
	})
})

describe('OrganisationConfiguration save and reset', () => {
	it('puts the default uuid and the switch, then marks them as the saved values for three seconds', async () => {
		jest.useFakeTimers()
		axios.put.mockResolvedValue({})
		const state = section({ selectedDefaultOrganisation: { uuid: 'u2' }, autoCreateDefault: false })
		await state.saveSettings()
		expect(axios.put).toHaveBeenCalledWith('/gen/apps/openregister/api/settings/organisation', {
			default_organisation: 'u2',
			auto_create_default_organisation: false,
		})
		expect(state.saveSuccess).toBe(true)
		expect(state.saving).toBe(false)
		expect(state.originalDefaultOrganisation).toEqual({ uuid: 'u2' })
		expect(state.originalAutoCreateDefault).toBe(false)
		jest.advanceTimersByTime(2999)
		expect(state.saveSuccess).toBe(true)
		jest.advanceTimersByTime(1)
		expect(state.saveSuccess).toBe(false)
	})

	it('sends no default organisation when none is selected', () => {
		expect(section().settingsPayload()).toEqual({ default_organisation: null, auto_create_default_organisation: true })
	})

	it.each([
		[{ response: { data: { message: 'Not allowed' } } }, 'Not allowed'],
		[new Error('down'), 'this.t:Failed to save settings'],
	])('shows why a save failed: %j', async (error, message) => {
		axios.put.mockRejectedValue(error)
		const state = section({ selectedDefaultOrganisation: { uuid: 'u2' } })
		await state.saveSettings()
		expect(state.saveError).toBe(message)
		expect(state.saveSuccess).toBe(false)
		expect(state.saving).toBe(false)
		expect(state.originalDefaultOrganisation).toBeNull()
	})

	it('resets to the original values and clears the save state', () => {
		const state = section({
			selectedDefaultOrganisation: { uuid: 'u2' },
			originalDefaultOrganisation: { uuid: 'u1' },
			autoCreateDefault: false,
			originalAutoCreateDefault: true,
			saveSuccess: true,
			saveError: 'x',
		})
		state.resetSettings()
		expect(state.selectedDefaultOrganisation).toEqual({ uuid: 'u1' })
		expect(state.selectedDefaultOrganisation).not.toBe(state.originalDefaultOrganisation)
		expect([state.autoCreateDefault, state.saveSuccess, state.saveError]).toEqual([true, false, null])
	})
})

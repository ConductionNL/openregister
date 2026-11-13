import axios from '@nextcloud/axios'
import OrganisationConfiguration from './OrganisationConfiguration.vue'

/**
 * Golden record of the default-organisation settings: the organisations,
 * settings and statistics it reads, the default it selects from them, what a
 * save sends and keeps, the success flag that hides after three seconds, and
 * the messages for each failure.
 *
 * @spec exclude Golden record of the client-side organisation settings section.
 */

jest.mock('@nextcloud/axios', () => ({ __esModule: true, default: { get: jest.fn(), put: jest.fn() } }))
jest.mock('@nextcloud/router', () => ({ __esModule: true, generateUrl: (path) => `/gen${path}` }))
jest.mock('@nextcloud/l10n', () => ({ __esModule: true, translate: (app, text) => `t:${text}` }))
jest.mock('@nextcloud/vue', () => ({ __esModule: true }))
jest.mock('vue-material-design-icons/ContentSaveOutline.vue', () => ({ __esModule: true, default: {} }))
jest.mock('vue-material-design-icons/OfficeBuilding.vue', () => ({ __esModule: true, default: {} }))
jest.mock('vue-material-design-icons/Refresh.vue', () => ({ __esModule: true, default: {} }))
jest.mock('vue-material-design-icons/UndoVariant.vue', () => ({ __esModule: true, default: {} }))

const ORGS = [{ uuid: 'o1', name: 'One' }, { uuid: 'o2', name: 'Two' }]

/**
 * A bare section carrying the section's data and methods.
 *
 * @param {object} state Data to set over the initial data
 * @return {object} The section
 */
function section(state = {}) {
	const ctx = { ...OrganisationConfiguration.data.call({}), ...state }
	for (const [name, method] of Object.entries(OrganisationConfiguration.methods)) {
		ctx[name] = method.bind(ctx)
	}
	ctx.t = (app, text) => `t:${text}`
	return ctx
}

/**
 * The section's data, without its methods.
 *
 * @param {object} ctx The section
 * @return {object} The data
 */
function dataOf(ctx) {
	return Object.fromEntries(Object.entries(ctx).filter(([key, value]) => typeof value !== 'function' && key !== 'saveSuccessTimeout'))
}

let logged

beforeEach(() => {
	axios.get.mockReset()
	axios.put.mockReset()
	logged = jest.spyOn(console, 'error').mockImplementation(() => {})
})

afterEach(() => {
	logged.mockRestore()
	jest.useRealTimers()
})

describe('OrganisationConfiguration', () => {
	it('starts from these values', () => {
		expect(OrganisationConfiguration.data.call({})).toMatchSnapshot()
	})

	it.each([
		['a list', () => Promise.resolve({ data: { results: ORGS } })],
		['an answer without results', () => Promise.resolve({ data: {} })],
		['a failure', () => Promise.reject(new Error('offline'))],
	])('loads the organisations from %s', async (label, answer) => {
		const ctx = section({ organisations: ['old'] })
		const seen = []
		axios.get.mockImplementation((...args) => {
			seen.push([args, ctx.loadingOrganisations])
			return answer()
		})
		await ctx.loadOrganisations()
		expect({ data: dataOf(ctx), seen, logged: logged.mock.calls.map(([text]) => text) }).toMatchSnapshot()
	})

	it.each([
		['a default that is listed', ORGS, { default_organisation: 'o2', auto_create_default_organisation: false }],
		['a default that is not listed', ORGS, { default_organisation: 'o9' }],
		['a default before any organisation is listed', [], { default_organisation: 'o1' }],
		['no default', ORGS, { auto_create_default_organisation: true }],
		['no settings at all', ORGS, undefined],
	])('loads the settings with %s', async (label, organisations, settings) => {
		const ctx = section({ organisations })
		axios.get.mockResolvedValue({ data: settings === undefined ? {} : { organisation: settings } })
		await ctx.loadSettings()
		expect({ data: dataOf(ctx), calls: axios.get.mock.calls }).toMatchSnapshot()
	})

	it('keeps the settings when they cannot be read', async () => {
		const ctx = section({ organisations: ORGS, autoCreateDefault: 'kept' })
		axios.get.mockRejectedValue(new Error('offline'))
		await ctx.loadSettings()
		expect({ data: dataOf(ctx), logged: logged.mock.calls.map(([text]) => text) }).toMatchSnapshot()
	})

	it.each([
		['a selected default', { selectedDefaultOrganisation: { uuid: 'o1', id: 'o1', label: 'One' }, autoCreateDefault: false }],
		['no default', { selectedDefaultOrganisation: null, autoCreateDefault: true }],
	])('saves %s and hides the success after three seconds', async (label, state) => {
		jest.useFakeTimers()
		const ctx = section({ ...state, saveError: 'old', saveSuccess: false })
		const seen = []
		axios.put.mockImplementation((...args) => {
			seen.push([args, ctx.saving, ctx.saveSuccess, ctx.saveError])
			return Promise.resolve({})
		})
		await ctx.saveSettings()
		const afterSave = dataOf(ctx)
		jest.advanceTimersByTime(2999)
		const before = ctx.saveSuccess
		jest.advanceTimersByTime(1)
		expect({ seen, afterSave, beforeThreeSeconds: before, afterThreeSeconds: ctx.saveSuccess }).toMatchSnapshot()
	})

	it.each([
		['the server message', { response: { data: { message: 'Not allowed' } } }],
		['the fallback', new Error('offline')],
	])('reports a failed save with %s', async (label, failure) => {
		const ctx = section({ selectedDefaultOrganisation: { uuid: 'o1' }, originalDefaultOrganisation: null })
		axios.put.mockRejectedValue(failure)
		await ctx.saveSettings()
		expect({ data: dataOf(ctx), logged: logged.mock.calls.map(([text]) => text) }).toMatchSnapshot()
	})

	it.each([
		['full statistics', () => Promise.resolve({ data: { total: 4, active: 3, totalMembers: 9, avgMembers: 2.25 } })],
		['empty statistics', () => Promise.resolve({ data: {} })],
		['no answer body', () => Promise.resolve({ data: null })],
		['a failure', () => Promise.reject(new Error('offline'))],
	])('loads %s', async (label, answer) => {
		const ctx = section({ stats: { kept: true } })
		axios.get.mockImplementation(answer)
		await ctx.loadStatistics()
		expect({ stats: ctx.stats, calls: axios.get.mock.calls, logged: logged.mock.calls.map(([text]) => text) }).toMatchSnapshot()
	})

	it('lists organisation options and detects unsaved changes', () => {
		const computed = OrganisationConfiguration.computed
		const ctx = section({
			organisations: ORGS,
			selectedDefaultOrganisation: { uuid: 'o1' },
			originalDefaultOrganisation: { uuid: 'o1' },
			autoCreateDefault: true,
			originalAutoCreateDefault: true,
		})

		expect(computed.organisationOptions.call(ctx)).toEqual([
			{ uuid: 'o1', name: 'One', id: 'o1', label: 'One' },
			{ uuid: 'o2', name: 'Two', id: 'o2', label: 'Two' },
		])
		expect(computed.hasChanges.call(ctx)).toBe(false)

		ctx.selectedDefaultOrganisation = { uuid: 'o2' }
		expect(computed.hasChanges.call(ctx)).toBe(true)

		ctx.selectedDefaultOrganisation = { uuid: 'o1' }
		ctx.autoCreateDefault = false
		expect(computed.hasChanges.call(ctx)).toBe(true)
	})

	it('selects the listed default organisation and remembers it', () => {
		const ctx = section({ organisations: ORGS })

		ctx.selectDefaultOrganisation('o2')

		expect(ctx.selectedDefaultOrganisation).toMatchObject({ uuid: 'o2', id: 'o2', label: 'Two' })
		expect(ctx.originalDefaultOrganisation).toMatchObject({ uuid: 'o2', label: 'Two' })
	})

	it('selects nothing for an absent uuid, an empty list or an unknown organisation', () => {
		const empty = section({ organisations: [] })
		empty.selectDefaultOrganisation('o1')
		expect(empty.selectedDefaultOrganisation).toBe(null)

		const listed = section({ organisations: ORGS })
		listed.selectDefaultOrganisation(undefined)
		expect(listed.selectedDefaultOrganisation).toBe(null)

		listed.selectDefaultOrganisation('nope')
		expect(listed.selectedDefaultOrganisation).toBe(null)
	})
})

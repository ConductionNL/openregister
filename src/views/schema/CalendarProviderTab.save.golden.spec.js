import CalendarProviderTab from './CalendarProviderTab.vue'
import { schemaStore } from '../../store/store.js'

/**
 * Golden record of the calendar provider tab's state and save: the initial
 * configuration, and the schema it saves with the provider configuration
 * merged into the existing configuration, its saving flag, and the logged
 * failure.
 *
 * @spec exclude Golden record of the client-side calendar provider tab.
 */

jest.mock('@nextcloud/l10n', () => ({ __esModule: true, translate: (app, text) => text }))
jest.mock('@nextcloud/vue', () => ({ __esModule: true }))
jest.mock('vue-material-design-icons/Circle.vue', () => ({ __esModule: true, default: {} }))
jest.mock('../../components/shared/SaveButton.vue', () => ({ __esModule: true, default: {} }))
jest.mock('../../store/store.js', () => ({ __esModule: true, schemaStore: { saveSchema: jest.fn() } }))

describe('CalendarProviderTab', () => {
	let logged

	beforeEach(() => {
		schemaStore.saveSchema.mockReset()
		logged = jest.spyOn(console, 'error').mockImplementation(() => {})
	})

	afterEach(() => logged.mockRestore())

	it('starts from this configuration', () => {
		const state = CalendarProviderTab.data.call({})
		expect({ state, keys: Object.keys(state.localConfig) }).toMatchSnapshot()
		expect(CalendarProviderTab.data.call({}).localConfig).not.toBe(state.localConfig)
	})

	it.each([
		['a schema with a configuration', { id: 4, configuration: { other: 1, calendarProvider: { old: true } } }, () => Promise.resolve()],
		['a schema without one', { id: 5 }, () => Promise.resolve()],
		['a refused save', { id: 6, configuration: {} }, () => Promise.reject(new Error('403'))],
	])('saves %s', async (label, schema, answer) => {
		const tab = { schema, saving: false, localConfig: { enabled: true, displayName: 'Cal', color: '#000' } }
		for (const [name, method] of Object.entries(CalendarProviderTab.methods)) {
			tab[name] = method.bind(tab)
		}
		const seen = []
		schemaStore.saveSchema.mockImplementation((updated) => {
			seen.push([updated, tab.saving, updated.configuration.calendarProvider === tab.localConfig])
			return answer()
		})
		await tab.save()
		expect({ seen, saving: tab.saving, logged: logged.mock.calls.map(([text]) => text) }).toMatchSnapshot()
	})
})

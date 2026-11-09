/**
 * Golden record of the configuration modal's option searches: what each one
 * fetches after the debounce, the options it keeps, its loading flag and
 * what it logs when the fetch fails.
 *
 * @spec exclude Golden record of client-side select-option searches.
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
import EditConfiguration from './EditConfiguration.vue'

jest.mock('../../store/store.js', () => ({
	applicationStore: { applicationList: [] },
	configurationStore: { configurationItem: null },
	navigationStore: {},
	organisationStore: { organisationList: [] },
}))
jest.mock('@nextcloud/vue', () => ({}))
jest.mock('@nextcloud/l10n', () => ({ translate: (app, s) => s }))
jest.mock('vue-material-design-icons/Cancel.vue', () => ({}))
jest.mock('vue-material-design-icons/CloudSync.vue', () => ({}))
jest.mock('vue-material-design-icons/Cog.vue', () => ({}))
jest.mock('vue-material-design-icons/ContentSave.vue', () => ({}))
jest.mock('vue-material-design-icons/Database.vue', () => ({}))
jest.mock('../../components/tabs/AppTab.vue', () => ({}))
jest.mock('../../components/tabs/AppTabs.vue', () => ({}))

// method, options field, loading field, selected field
const SEARCHES = [
	['searchRegisters', 'registerOptions', 'loadingRegisters', 'selectedRegisters'],
	['searchSchemas', 'schemaOptions', 'loadingSchemas', 'selectedSchemas'],
	['searchObjects', 'objectOptions', 'loadingObjects', 'selectedObjects'],
	['searchSources', 'sourceOptions', 'loadingSources', 'selectedSources'],
	['searchAgents', 'agentOptions', 'loadingAgents', 'selectedAgents'],
	['searchViews', 'viewOptions', 'loadingViews', 'selectedViews'],
	[
		'searchApplications',
		'applicationOptions',
		'loadingApplications',
		'selectedManagedApplications',
	],
]

const ANSWERS = {
	results: () => ({ json: async () => ({ results: [{ id: 1 }, { id: 2 }] }) }),
	bareList: () => ({ json: async () => [{ id: 3 }] }),
	empty: () => ({ json: async () => null }),
	failed: () => Promise.reject(new Error('down')),
}

/**
 * A bare component context with the modal's data and methods.
 *
 * @return {object} The context
 */
function context() {
	const ctx = { ...EditConfiguration.data.call({ t: (app, s) => s }) }
	ctx.t = (app, s) => s
	for (const [name, method] of Object.entries(EditConfiguration.methods)) {
		ctx[name] = method.bind(ctx)
	}
	return ctx
}

beforeEach(() => {
	jest.useFakeTimers()
	jest.spyOn(console, 'error').mockImplementation(() => {})
})

afterEach(() => {
	jest.useRealTimers()
	jest.restoreAllMocks()
})

describe('EditConfiguration option searches', () => {
	for (const [method, options, loading, selected] of SEARCHES) {
		for (const [answer, respond] of Object.entries(ANSWERS)) {
			it(`${method} with ${answer}`, async () => {
				const ctx = context()
				ctx[selected] = [{ id: 2 }, { id: 9 }]
				ctx.selectedRegisters = ctx.selectedRegisters.length
					? ctx.selectedRegisters
					: [{ id: 'r1' }]
				ctx.selectedSchemas = ctx.selectedSchemas.length
					? ctx.selectedSchemas
					: [{ id: 's1' }]
				const urls = []
				global.fetch = jest.fn((url) => {
					urls.push(url)
					return respond()
				})

				ctx[method]('first')
				ctx[method]('a b&c')
				const beforeTimer = { fetched: urls.length, loading: ctx[loading] }
				jest.advanceTimersByTime(299)
				const justBefore = { fetched: urls.length }
				jest.advanceTimersByTime(1)
				const during = ctx[loading]
				await Promise.resolve()
				await jest.runAllTimersAsync()

				expect({
					beforeTimer,
					justBefore,
					during,
					urls,
					options: ctx[options],
					loading: ctx[loading],
					logged: console.error.mock.calls.map(([text, error]) => [
						text,
						error?.message,
					]),
				}).toMatchSnapshot()
			})
		}
	}
})

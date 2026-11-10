/**
 * Golden record of JoinOrganisation's option loading: the organisation and
 * user options the initial loads and the debounced searches build, the
 * requests they send, and what a failure leaves behind.
 *
 * @spec exclude Golden record of client-side select-option loading.
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
import JoinOrganisation from './JoinOrganisation.vue'

jest.mock('@nextcloud/l10n', () => ({ translate: (app, text) => text }))
jest.mock('@nextcloud/vue', () => ({}))
jest.mock('vue-material-design-icons/AccountPlus.vue', () => ({}))
jest.mock('vue-material-design-icons/Cancel.vue', () => ({}))
jest.mock('../../store/store.js', () => ({
	__esModule: true,
	navigationStore: {
		setModal: jest.fn(),
		getTransferData: jest.fn(),
		clearTransferData: jest.fn(),
	},
	organisationStore: {
		searchOrganisations: jest.fn(),
		getOrganisation: jest.fn(),
		joinOrganisation: jest.fn(),
	},
}))

const { organisationStore } = jest.requireMock('../../store/store.js')

const ORGANISATIONS = [
	{ uuid: 'u-1', id: 1, name: 'One', description: 'First', users: ['a'], isDefault: true },
	{ id: 2, name: 'Two' },
]

/**
 * A component context with the methods bound to it.
 *
 * @return {object} The context
 */
function context() {
	const ctx = { ...JoinOrganisation.data() }
	for (const [name, method] of Object.entries(JoinOrganisation.methods)) {
		ctx[name] = method.bind(ctx)
	}
	return ctx
}

/**
 * A fetch stub answering with the given users, or failing.
 *
 * @param {Array<string>|null} users The user ids, or null for a 500
 * @return {Function} The stub
 */
function fetchAnswering(users) {
	return jest.fn(() =>
		Promise.resolve({
			ok: users !== null,
			json: () => Promise.resolve({ ocs: { data: { users } } }),
		}),
	)
}

/**
 * The option state of a context.
 *
 * @param {object} ctx The context
 * @return {object} The state
 */
function state(ctx) {
	return {
		organisationOptions: ctx.organisationOptions,
		userOptions: ctx.userOptions,
		selectedUser: ctx.selectedUser,
		searchLoading: ctx.searchLoading,
		loadingUsers: ctx.loadingUsers,
		error: ctx.error,
	}
}

describe('JoinOrganisation option loading (golden)', () => {
	beforeEach(() => {
		jest.useFakeTimers()
		jest.spyOn(console, 'error').mockImplementation(() => {})
		window.OC = { getCurrentUser: () => ({ uid: 'me', displayName: 'Me' }) }
		organisationStore.searchOrganisations.mockReset()
	})

	afterEach(() => {
		jest.useRealTimers()
		jest.restoreAllMocks()
		delete window.OC
		delete global.fetch
	})

	it('loads and searches organisations', async () => {
		const ctx = context()
		const trace = []
		organisationStore.searchOrganisations.mockResolvedValue(ORGANISATIONS)
		await ctx.loadInitialOrganisations()
		trace.push(state(ctx))
		await ctx.handleOrganisationSearch('  tw  ')
		await jest.runAllTimersAsync()
		trace.push(state(ctx))
		organisationStore.searchOrganisations.mockRejectedValue(new Error('down'))
		await ctx.handleOrganisationSearch('two')
		await jest.runAllTimersAsync()
		trace.push(state(ctx))
		await ctx.loadInitialOrganisations()
		trace.push(state(ctx))
		expect(organisationStore.searchOrganisations.mock.calls).toMatchSnapshot()
		expect(trace).toMatchSnapshot()
	})

	it('loads and searches users', async () => {
		const ctx = context()
		const trace = []
		global.fetch = fetchAnswering(['ann', 'me'])
		await ctx.loadInitialUsers()
		trace.push(state(ctx))
		global.fetch = fetchAnswering(['bob'])
		await ctx.handleUserSearch(' bo ')
		await jest.runAllTimersAsync()
		trace.push(state(ctx))
		const searched = global.fetch.mock.calls
		global.fetch = fetchAnswering(null)
		await ctx.handleUserSearch('bob')
		await jest.runAllTimersAsync()
		trace.push(state(ctx))
		await ctx.loadInitialUsers()
		trace.push(state(ctx))
		global.fetch = fetchAnswering(undefined)
		await ctx.loadInitialUsers()
		trace.push(state(ctx))
		expect(searched).toMatchSnapshot()
		expect(trace).toMatchSnapshot()
	})
})

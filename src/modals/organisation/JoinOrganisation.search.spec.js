/**
 * The join-organisation modal's two pickers: the debounced organisation and
 * user searches, and clearing a pre-selection's transfer data once it is used.
 * Runs on the options object against a synthetic `this`.
 *
 * @spec exclude Client-side join-organisation pickers.
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

const { navigationStore } = jest.requireMock('../../store/store.js')
const { methods } = JoinOrganisation

/**
 * A modal context whose loaders and searches are spies.
 *
 * @return {object} The context
 */
function picker() {
	const ctx = { searchTimeout: null, userSearchTimeout: null }
	for (const name of ['setDefaultUser', 'loadInitialOrganisations', 'loadInitialUsers', 'loadPreselectedOrganisation', 'searchOrganisations', 'searchUsers']) {
		ctx[name] = jest.fn().mockResolvedValue()
	}
	return ctx
}

const SEARCHES = [
	{ kind: 'organisation', handler: 'handleOrganisationSearch', search: 'searchOrganisations', initial: 'loadInitialOrganisations' },
	{ kind: 'user', handler: 'handleUserSearch', search: 'searchUsers', initial: 'loadInitialUsers' },
]

beforeEach(() => {
	jest.clearAllMocks()
	jest.useFakeTimers()
})

afterEach(() => {
	jest.useRealTimers()
})

describe.each(SEARCHES)('JoinOrganisation — the $kind search', ({ handler, search, initial }) => {
	it('searches the trimmed term 500 ms after the last keystroke', async () => {
		const ctx = picker()

		await methods[handler].call(ctx, 'ac')
		jest.advanceTimersByTime(300)
		await methods[handler].call(ctx, '  acme ')
		jest.advanceTimersByTime(499)
		expect(ctx[search]).not.toHaveBeenCalled()

		jest.advanceTimersByTime(1)
		expect(ctx[search]).toHaveBeenCalledTimes(1)
		expect(ctx[search]).toHaveBeenCalledWith('acme')
	})

	it('does not search a single character', async () => {
		const ctx = picker()

		await methods[handler].call(ctx, ' a ')
		jest.advanceTimersByTime(500)

		expect(ctx[search]).not.toHaveBeenCalled()
	})

	it('reloads the initial list for an empty term, cancelling a pending search', async () => {
		const ctx = picker()
		await methods[handler].call(ctx, 'acme')

		await methods[handler].call(ctx, '   ')
		jest.advanceTimersByTime(1000)

		expect(ctx[initial]).toHaveBeenCalledTimes(1)
		expect(ctx[search]).not.toHaveBeenCalled()
	})
})

describe('JoinOrganisation — opening the modal', () => {
	it('selects the organisation handed over and then clears the hand-over', async () => {
		navigationStore.getTransferData.mockReturnValue({ organisationUuid: 'org-1' })
		const ctx = picker()

		await JoinOrganisation.mounted.call(ctx)

		expect(ctx.loadPreselectedOrganisation).toHaveBeenCalledWith('org-1')
		expect(navigationStore.clearTransferData).toHaveBeenCalledTimes(1)
	})

	it('keeps any hand-over data when it names no organisation', async () => {
		navigationStore.getTransferData.mockReturnValue({ other: true })
		const ctx = picker()

		await JoinOrganisation.mounted.call(ctx)

		expect(ctx.loadPreselectedOrganisation).not.toHaveBeenCalled()
		expect(navigationStore.clearTransferData).not.toHaveBeenCalled()
		expect(ctx.loadInitialOrganisations).toHaveBeenCalledTimes(1)
		expect(ctx.loadInitialUsers).toHaveBeenCalledTimes(1)
	})
})

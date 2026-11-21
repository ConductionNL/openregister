/**
 * Golden record of JoinOrganisation's preselected organisation: where the
 * option comes from, what is added to the options and what gets selected.
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

const LOADED = [
	{ id: 'u-1', uuid: 'u-1', name: 'One', label: 'One' },
	{ id: 7, uuid: 'u-7', name: 'Seven', label: 'Seven' },
	{ id: 'x', uuid: 'u-9', name: 'Nine loaded', label: 'Nine loaded' },
]

const ANSWERS = {
	full: () =>
		Promise.resolve({
			uuid: 'u-2',
			id: 2,
			name: 'Two',
			description: 'Second',
			users: ['b'],
			isDefault: false,
		}),
	bare: () => Promise.resolve({ uuid: 'u-3', name: 'Three' }),
	alreadyListed: () => Promise.resolve({ uuid: 'u-9', name: 'Nine fetched' }),
	none: () => Promise.resolve(null),
	thrown: () => Promise.reject(new Error('Not found')),
}

describe('JoinOrganisation.loadPreselectedOrganisation (golden)', () => {
	let logged
	beforeEach(() => {
		logged = []
		jest.spyOn(console, 'error').mockImplementation((...args) =>
			logged.push(
				args.map((arg) =>
					arg instanceof Error ? `Error(${arg.message})` : arg,
				),
			),
		)
	})
	afterEach(() => {
		jest.restoreAllMocks()
	})

	for (const requested of ['u-1', 7, 'u-2', 'u-9']) {
		for (const [answerName, answer] of Object.entries(ANSWERS)) {
			it(`preselects ${requested}, the store answering ${answerName}`, async () => {
				const calls = []
				organisationStore.getOrganisation.mockImplementation((...args) => {
					calls.push(args)
					return answer()
				})
				const ctx = context()
				ctx.organisationOptions = LOADED.map((option) => ({ ...option }))
				ctx.selectedOrganisation = 'kept'
				await ctx.loadPreselectedOrganisation(requested)
				expect({
					calls,
					options: ctx.organisationOptions,
					selected: ctx.selectedOrganisation,
					logged,
				}).toMatchSnapshot()
			})
		}
	}
})

/**
 * Golden record of the organisation modal's actions: loading the stored
 * organisation into the form, removing a member, saving, and toggling an
 * entity permission, for every branch they take.
 *
 * @spec exclude Golden record of client-side organisation form actions.
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
import EditOrganisation from './EditOrganisation.vue'
import { organisationStore } from '../../store/store.js'

const mockLog = []

jest.mock('../../store/store.js', () => ({
	navigationStore: {},
	organisationStore: {
		organisationItem: null,
		refreshOrganisations: () => mockLog.push(['refreshOrganisations']),
		refreshOrganisationList: () => mockLog.push(['refreshOrganisationList']),
		getActiveOrganisation: () => mockLog.push(['getActiveOrganisation']),
		saveOrganisation: jest.fn(),
	},
}))
jest.mock('@nextcloud/l10n', () => ({
	translate: (app, text) => `[${text}]`,
	translatePlural: (app, one) => one,
}))
jest.mock('@nextcloud/dialogs', () => ({
	showError: (text) => mockLog.push(['error', text]),
	showSuccess: (text) => mockLog.push(['success', text]),
}))
jest.mock('@nextcloud/vue', () => ({}))
jest.mock('vue-material-design-icons/AccountCircle.vue', () => ({}))
jest.mock('vue-material-design-icons/AccountMinus.vue', () => ({}))
jest.mock('vue-material-design-icons/AccountMultiple.vue', () => ({}))
jest.mock('vue-material-design-icons/AccountPlus.vue', () => ({}))
jest.mock('vue-material-design-icons/Cancel.vue', () => ({}))
jest.mock('vue-material-design-icons/Cog.vue', () => ({}))
jest.mock('vue-material-design-icons/ContentSaveOutline.vue', () => ({}))
jest.mock('vue-material-design-icons/Database.vue', () => ({}))
jest.mock('vue-material-design-icons/Plus.vue', () => ({}))
jest.mock('vue-material-design-icons/Shield.vue', () => ({}))
jest.mock('../../components/RbacTable.vue', () => ({}))
jest.mock('../../components/tabs/AppTab.vue', () => ({}))
jest.mock('../../components/tabs/AppTabs.vue', () => ({}))
jest.mock('./RemoveUserDialog.vue', () => ({}))
jest.mock('../../services/groupSearch.js', () => ({
	searchGroupsDebounced: jest.fn(),
}))

const STATE_KEYS = [
	'organisationItem',
	'selectedGroups',
	'organisationUsers',
	'userToRemove',
	'showRemoveUserDialog',
	'removingUser',
	'error',
	'success',
	'loading',
	'activeTab',
]

/**
 * A modal context with the modal's methods bound to it.
 *
 * @param {object} state The state to start from
 * @return {object} The context
 */
function modal(state) {
	const ctx = {
		...EditOrganisation.data.call({}),
		availableGroups: [{ id: 'g1', name: 'Group one', userCount: 3 }],
		createAnother: false,
		...JSON.parse(JSON.stringify(state)),
	}
	for (const [name, method] of Object.entries(EditOrganisation.methods)) {
		ctx[name] = method.bind(ctx)
	}
	ctx.initializeSpecialRights = () => mockLog.push(['initializeSpecialRights'])
	ctx.closeModal = () => mockLog.push(['closeModal'])
	return ctx
}

/**
 * The state the actions write.
 *
 * @param {object} ctx The context
 * @return {object} The state
 */
function written(ctx) {
	return Object.fromEntries(STATE_KEYS.map((key) => [key, ctx[key]]))
}

/**
 * A fetch answer.
 *
 * @param {boolean} ok Whether the request succeeded
 * @param {object} body The JSON body
 * @return {object} The response
 */
function answer(ok, body) {
	return { ok, json: () => Promise.resolve(body) }
}

describe('EditOrganisation actions golden', () => {
	beforeEach(() => {
		mockLog.length = 0
		jest.useFakeTimers()
		jest.spyOn(console, 'error').mockImplementation(() => {})
	})

	afterEach(() => {
		jest.useRealTimers()
		jest.restoreAllMocks()
	})

	const STORED = {
		none: null,
		noUuid: { name: 'x' },
		plain: { uuid: 'u1', name: 'Org' },
		inactive: { uuid: 'u1', name: 'Org', active: false, users: ['a', 'b'] },
		groups: {
			uuid: 'u1',
			name: 'Org',
			active: null,
			groups: [
				'g1',
				'g2',
				{ id: 'g1' },
				{ name: 'named' },
				{ id: '', name: 'fallback' },
			],
		},
		emptyGroups: { uuid: 'u1', groups: [], users: 'notalist' },
	}
	for (const [name, stored] of Object.entries(STORED)) {
		it(`loads the stored organisation: ${name}`, () => {
			organisationStore.organisationItem = stored
			const ctx = modal({})
			ctx.initializeOrganisationItem()
			expect({ log: mockLog, state: written(ctx) }).toMatchSnapshot()
		})
	}

	const REMOVALS = {
		noOrganisation: [{ organisationItem: {}, userToRemove: 'a' }, null],
		noUser: [{ organisationItem: { uuid: 'u1' }, userToRemove: null }, null],
		removed: [
			{
				organisationItem: { uuid: 'u1', users: ['a', 'b'] },
				organisationUsers: ['a', 'b'],
				userToRemove: 'a',
				showRemoveUserDialog: true,
			},
			answer(true, {}),
		],
		removedNoUsers: [
			{
				organisationItem: { uuid: 'u1' },
				organisationUsers: ['a'],
				userToRemove: 'a',
				showRemoveUserDialog: true,
			},
			answer(true, {}),
		],
		refused: [
			{
				organisationItem: { uuid: 'u1' },
				organisationUsers: ['a'],
				userToRemove: 'a',
			},
			answer(false, { error: 'last admin' }),
		],
		refusedQuietly: [
			{
				organisationItem: { uuid: 'u1' },
				organisationUsers: ['a'],
				userToRemove: 'a',
			},
			answer(false, {}),
		],
		networkDown: [
			{
				organisationItem: { uuid: 'u1' },
				organisationUsers: ['a'],
				userToRemove: 'a',
			},
			new Error('offline'),
		],
	}
	for (const [name, [state, reply]] of Object.entries(REMOVALS)) {
		it(`removes a member: ${name}`, async () => {
			globalThis.fetch = jest.fn((...args) => {
				mockLog.push(['fetch', ...args])
				return reply instanceof Error
					? Promise.reject(reply)
					: Promise.resolve(reply)
			})
			const ctx = modal(state)
			await ctx.confirmRemoveUser()
			expect({ log: mockLog, state: written(ctx) }).toMatchSnapshot()
		})
	}

	const SAVES = {
		blankName: [{ organisationItem: { name: '  ' } }, null],
		createdAndClose: [
			{ organisationItem: { name: 'New' } },
			{ response: { ok: true } },
		],
		updatedAndClose: [
			{ organisationItem: { uuid: 'u1', name: 'Org' } },
			{ response: { ok: true } },
		],
		createAnother: [
			{
				organisationItem: { name: 'New' },
				createAnother: true,
				selectedGroups: ['g1'],
				activeTab: 2,
			},
			{ response: { ok: true } },
		],
		notOk: [
			{ organisationItem: { uuid: 'u1', name: 'Org' } },
			{ response: { ok: false } },
		],
		failsWithMessage: [
			{ organisationItem: { name: 'Org' } },
			new Error('conflict'),
		],
		failsQuietly: [{ organisationItem: { name: 'Org' } }, new Error('')],
	}
	for (const [name, [state, reply]] of Object.entries(SAVES)) {
		it(`saves: ${name}`, async () => {
			organisationStore.saveOrganisation.mockImplementation((item) => {
				mockLog.push(['saveOrganisation', item])
				return reply instanceof Error
					? Promise.reject(reply)
					: Promise.resolve(reply)
			})
			const ctx = modal(state)
			await ctx.saveOrganisation()
			const before = written(ctx)
			jest.advanceTimersByTime(600)
			const after600 = written(ctx)
			jest.advanceTimersByTime(2000)
			expect({
				log: mockLog,
				before,
				after600,
				after: written(ctx),
				timer: Boolean(ctx.closeModalTimeout),
			}).toMatchSnapshot()
		})
	}

	it('toggles entity permissions', () => {
		const ctx = modal({ organisationItem: { name: 'Org' } })
		const steps = [
			{
				entityType: 'register',
				groupId: 'g1',
				action: 'read',
				hasPermission: true,
			},
			{
				entityType: 'register',
				groupId: 'g1',
				action: 'read',
				hasPermission: true,
			},
			{
				entityType: 'register',
				groupId: 'g2',
				action: 'read',
				hasPermission: true,
			},
			{
				entityType: 'register',
				groupId: 'g1',
				action: 'read',
				hasPermission: false,
			},
			{
				entityType: 'register',
				groupId: 'g3',
				action: 'read',
				hasPermission: false,
			},
			{
				entityType: 'schema',
				groupId: 'g1',
				action: 'update',
				hasPermission: false,
			},
			{
				entityType: 'schema',
				groupId: 'g1',
				action: 'create',
				hasPermission: true,
			},
		]
		const out = steps.map((step) => {
			ctx.updateEntityPermission(step)
			return JSON.parse(JSON.stringify(ctx.organisationItem.authorization))
		})
		expect(out).toMatchSnapshot()
	})
})

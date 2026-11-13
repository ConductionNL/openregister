/**
 * JoinOrganisation and the signed-in user: the user list always offers the
 * current user first (once), the initial load selects them, a search offers
 * but does not select them, and a failed load falls back to them alone.
 *
 * @spec exclude Unit spec of client-side select-option state.
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
	navigationStore: { setModal: jest.fn(), getTransferData: jest.fn(), clearTransferData: jest.fn() },
	organisationStore: { searchOrganisations: jest.fn(), getOrganisation: jest.fn(), joinOrganisation: jest.fn() },
}))

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
 * A fetch stub answering with the given user ids, or a 500 for null.
 *
 * @param {Array<string>|null} users The user ids
 * @return {Function} The stub
 */
function answering(users) {
	return jest.fn(() => Promise.resolve({
		ok: users !== null,
		json: () => Promise.resolve({ ocs: { data: { users } } }),
	}))
}

const option = (id, displayName = id) => ({ id, displayName, label: displayName })

describe('JoinOrganisation current user', () => {
	beforeEach(() => {
		window.OC = { getCurrentUser: () => ({ uid: 'me', displayName: 'Me Myself' }) }
		jest.spyOn(console, 'error').mockImplementation(() => {})
	})

	afterEach(() => {
		delete window.OC
		console.error.mockRestore()
		delete global.fetch
	})

	it('reads the signed-in user, falling back to the uid for a name', () => {
		expect(context().getCurrentUser()).toEqual({ id: 'me', displayName: 'Me Myself' })
		window.OC.getCurrentUser = () => ({ uid: 'anon' })
		expect(context().currentUserOption()).toEqual(option('anon'))
		delete window.OC
		expect(context().getCurrentUser()).toBeNull()
		expect(context().currentUserOption()).toBeNull()
	})

	it('the initial load puts the current user first and selects them', async () => {
		global.fetch = answering(['ann', 'bob'])
		const ctx = context()
		await ctx.loadInitialUsers()
		expect(ctx.userOptions).toEqual([option('me', 'Me Myself'), option('ann'), option('bob')])
		expect(ctx.selectedUser).toEqual(option('me', 'Me Myself'))
		expect(ctx.loadingUsers).toBe(false)
	})

	it('a current user already listed is not offered twice', async () => {
		global.fetch = answering(['ann', 'me'])
		const ctx = context()
		await ctx.loadInitialUsers()
		expect(ctx.userOptions.map((user) => user.id)).toEqual(['ann', 'me'])
		expect(ctx.selectedUser).toEqual(option('me', 'Me Myself'))
	})

	it('without a signed-in user nothing is offered or selected', async () => {
		delete window.OC
		global.fetch = answering(['ann'])
		const ctx = context()
		ctx.selectedUser = null
		await ctx.loadInitialUsers()
		expect(ctx.userOptions).toEqual([option('ann')])
		expect(ctx.selectedUser).toBeNull()
	})

	it('a failed initial load falls back to the current user alone', async () => {
		global.fetch = answering(null)
		const ctx = context()
		await ctx.loadInitialUsers()
		expect(console.error).toHaveBeenCalledWith('Error loading initial users:', new Error('Failed to load users'))
		expect(ctx.selectedUser).toEqual(option('me', 'Me Myself'))
		expect(ctx.loadingUsers).toBe(false)
	})

	it('a search offers the current user first without changing the selection', async () => {
		global.fetch = answering(['bob'])
		const ctx = context()
		ctx.selectedUser = option('bob')
		await ctx.searchUsers('bo b')
		expect(global.fetch.mock.calls[0][0]).toBe('/ocs/v2.php/cloud/users?search=bo%20b&limit=20')
		expect(ctx.userOptions).toEqual([option('me', 'Me Myself'), option('bob')])
		expect(ctx.selectedUser).toEqual(option('bob'))
	})

	it('a failed search keeps the options it had', async () => {
		global.fetch = answering(null)
		const ctx = context()
		ctx.userOptions = [option('ann')]
		await ctx.searchUsers('zz')
		expect(ctx.userOptions).toEqual([option('ann')])
		expect(console.error).toHaveBeenCalledWith('Error searching users:', new Error('Failed to search users'))
		expect(ctx.loadingUsers).toBe(false)
	})
})

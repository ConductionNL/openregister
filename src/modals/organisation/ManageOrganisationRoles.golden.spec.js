/**
 * Golden record of the organisation roles modal: its starting state, how it
 * loads the Nextcloud groups to pick roles from, and what saving the roles
 * stores, shows and closes, for an accepted and a refused save.
 *
 * @spec exclude Golden record of client-side organisation role editing.
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
import axios from '@nextcloud/axios'
import ManageOrganisationRoles from './ManageOrganisationRoles.vue'
import { organisationStore } from '../../store/store.js'

jest.mock('@nextcloud/axios', () => ({ __esModule: true, default: { get: jest.fn() } }))
jest.mock('@nextcloud/router', () => ({ generateUrl: (path) => `/gen${path}` }))
jest.mock('@nextcloud/vue', () => ({}))
jest.mock('@nextcloud/l10n', () => ({ translate: (app, text) => text }))
jest.mock('vue-material-design-icons/AccountGroup.vue', () => ({}))
jest.mock('vue-material-design-icons/Close.vue', () => ({}))
jest.mock('vue-material-design-icons/ContentSaveOutline.vue', () => ({}))
jest.mock('../../components/shared/CloseCancelButton.vue', () => ({}))
jest.mock('../../store/store.js', () => ({
	navigationStore: { setModal: jest.fn(), setDialog: jest.fn() },
	organisationStore: { saveOrganisation: jest.fn() },
}))

/**
 * A modal context with the modal's methods bound to it.
 *
 * @param {object} state The state to start from
 * @return {object} The context
 */
function context(state) {
	const ctx = {
		...ManageOrganisationRoles.data.call({}),
		t: (app, text) => `t:${text}`,
		...state,
	}
	for (const [name, method] of Object.entries(ManageOrganisationRoles.methods)) {
		ctx[name] = method.bind(ctx)
	}
	ctx.closeModal = jest.fn()
	return ctx
}

beforeEach(() => {
	jest.useFakeTimers()
	jest.clearAllMocks()
	jest.spyOn(console, 'error').mockImplementation(() => {})
})
afterEach(() => {
	jest.useRealTimers()
	jest.restoreAllMocks()
})

describe('ManageOrganisationRoles (golden)', () => {
	it('starts empty', () => {
		expect(ManageOrganisationRoles.data.call({})).toMatchSnapshot()
	})

	it.each([
		['lists the groups', () => Promise.resolve({ data: { ocs: { data: { groups: ['admin', 'staff'] } } } })],
		['keeps the groups on an answer without groups', () => Promise.resolve({ data: { ocs: {} } })],
		['reports a failed load', () => Promise.reject(new Error('offline'))],
	])('%s', async (name, answer) => {
		axios.get.mockImplementation(answer)
		const ctx = context({ availableGroups: [{ id: 'kept' }] })
		const pending = ctx.loadNextcloudGroups()
		const during = ctx.loadingGroups
		await pending
		expect({
			request: axios.get.mock.calls,
			during,
			loadingGroups: ctx.loadingGroups,
			availableGroups: ctx.availableGroups,
			error: ctx.error,
			logged: console.error.mock.calls.map(([msg, err]) => [msg, err?.message]),
		}).toMatchSnapshot()
	})

	it.each([
		['stores the roles and closes', () => Promise.resolve({})],
		['reports a refused save', () => Promise.reject(new Error('Forbidden'))],
		['reports a refusal without a message', () => Promise.reject({})],
	])('%s', async (name, answer) => {
		organisationStore.saveOrganisation.mockImplementation(answer)
		const ctx = context({
			organisationItem: { uuid: 'o1', name: 'Org', roles: ['old'] },
			selectedRoles: ['admin', 'staff'],
			originalRoles: ['old'],
			error: 'stale',
		})
		const pending = ctx.saveRoles()
		const during = { loading: ctx.loading, error: ctx.error }
		await pending
		const before = {
			loading: ctx.loading,
			success: ctx.success,
			error: ctx.error,
			originalRoles: ctx.originalRoles,
			closes: ctx.closeModal.mock.calls.length,
		}
		jest.advanceTimersByTime(2000)
		expect({
			saved: organisationStore.saveOrganisation.mock.calls,
			during,
			before,
			closes: ctx.closeModal.mock.calls.length,
			logged: console.error.mock.calls.length,
		}).toMatchSnapshot()
	})
})

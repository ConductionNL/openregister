/**
 * Golden record of the debounced group search the application, organisation
 * and view edit modals run from their group picker: what each one fetches,
 * the groups it offers, its loading flag and what it logs, for an empty
 * query, a found list, an answer without groups, a refused answer, a failed
 * request and a query typed twice.
 */
import EditApplication from './application/EditApplication.vue'
import EditOrganisation from './organisation/EditOrganisation.vue'
import EditView from './view/EditView.vue'

jest.mock('../components/RbacTable.vue', () => ({}))
jest.mock('../components/tabs/AppTab.vue', () => ({}))
jest.mock('../components/tabs/AppTabs.vue', () => ({}))
jest.mock('./organisation/RemoveUserDialog.vue', () => ({}))
jest.mock('@nextcloud/dialogs', () => ({}))
jest.mock('@nextcloud/vue', () => ({}))
jest.mock('vue-material-design-icons/AccountCircle.vue', () => ({}))
jest.mock('vue-material-design-icons/AccountMinus.vue', () => ({}))
jest.mock('vue-material-design-icons/AccountMultiple.vue', () => ({}))
jest.mock('vue-material-design-icons/AccountPlus.vue', () => ({}))
jest.mock('vue-material-design-icons/Cancel.vue', () => ({}))
jest.mock('vue-material-design-icons/Close.vue', () => ({}))
jest.mock('vue-material-design-icons/Cog.vue', () => ({}))
jest.mock('vue-material-design-icons/ContentSave.vue', () => ({}))
jest.mock('vue-material-design-icons/ContentSaveOutline.vue', () => ({}))
jest.mock('vue-material-design-icons/Database.vue', () => ({}))
jest.mock('vue-material-design-icons/Plus.vue', () => ({}))
jest.mock('vue-material-design-icons/ShareVariant.vue', () => ({}))
jest.mock('vue-material-design-icons/Shield.vue', () => ({}))
jest.mock('@nextcloud/l10n', () => ({
	translate: (app, text) => text,
	translatePlural: (app, one) => one,
	t: (app, text) => text,
	n: (app, one) => one,
}))
jest.mock('../store/store.js', () => ({
	applicationStore: {},
	navigationStore: {},
	organisationStore: {},
	viewsStore: {},
}))

const COMPONENTS = { EditApplication, EditOrganisation, EditView }

const ANSWERS = {
	found: () =>
		Promise.resolve({
			ok: true,
			json: async () => ({
				ocs: { data: { groups: ['admin', 'sel1', 'staff'] } },
			}),
		}),
	noGroups: () => Promise.resolve({ ok: true, json: async () => ({ ocs: {} }) }),
	refused: () =>
		Promise.resolve({
			ok: false,
			statusText: 'Forbidden',
			json: async () => ({}),
		}),
	failed: () => Promise.reject(new Error('offline')),
}

/**
 * A stand-in for the component instance the method runs on.
 *
 * @return {object} The instance state.
 */
function instance() {
	return {
		groupSearchDebounce: null,
		loadingGroups: false,
		availableGroups: [{ id: 'cached', name: 'cached' }],
		selectedGroups: [{ id: 'sel1', name: 'Selected one' }],
		loadNextcloudGroupsFromStore: jest.fn(),
	}
}

/**
 * Run one search scenario and describe what it did.
 *
 * @param {object} component The component options.
 * @param {Array<string|undefined>} queries The queries typed, in order.
 * @param {string} answer The fetch answer.
 * @return {Promise<object>} The record.
 */
async function runCase(component, queries, answer) {
	const vm = instance()
	fetch.mockReset()
	fetch.mockImplementation(ANSWERS[answer])
	console.warn.mockClear()
	console.error.mockClear()
	for (const query of queries) {
		component.methods.searchGroups.call(vm, query)
	}
	const pendingBefore = jest.getTimerCount()
	await jest.advanceTimersByTimeAsync(299)
	const fetchedBefore = fetch.mock.calls.length
	await jest.advanceTimersByTimeAsync(1)
	await jest.runAllTimersAsync()
	return {
		pendingBefore,
		fetchedBefore,
		fetch: fetch.mock.calls,
		availableGroups: vm.availableGroups,
		loadingGroups: vm.loadingGroups,
		storeLoads: vm.loadNextcloudGroupsFromStore.mock.calls.length,
		timerSet: vm.groupSearchDebounce !== null,
		warn: console.warn.mock.calls,
		error: console.error.mock.calls.map(([msg, err]) => [
			msg,
			err && err.message,
		]),
	}
}

describe('group search of the edit modals (golden)', () => {
	beforeEach(() => {
		jest.useFakeTimers()
		jest.spyOn(console, 'warn').mockImplementation(() => {})
		jest.spyOn(console, 'error').mockImplementation(() => {})
	})

	afterEach(() => {
		jest.useRealTimers()
		jest.restoreAllMocks()
	})

	Object.entries(COMPONENTS).forEach(([name, component]) => {
		it(`${name} matches the recorded behaviour`, async () => {
			const records = {}
			records.emptyString = await runCase(component, [''], 'found')
			records.blank = await runCase(component, ['   '], 'found')
			records.undefinedQuery = await runCase(component, [undefined], 'found')
			for (const answer of Object.keys(ANSWERS)) {
				records[answer] = await runCase(component, ['ad min&x'], answer)
			}
			records.typedTwice = await runCase(component, ['ad', 'adm'], 'found')
			records.typedThenCleared = await runCase(component, ['ad', ''], 'found')
			expect(records).toMatchSnapshot()
		})
	})
})

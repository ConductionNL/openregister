import EditConfiguration from './EditConfiguration.vue'
import {
	applicationStore,
	configurationStore,
	organisationStore,
} from '../../store/store.js'

// Opening the modal: the lists it loads, the searches it starts and the item it prepares.

jest.mock('../../store/store.js', () => ({
	applicationStore: { applicationList: [], refreshApplicationList: jest.fn() },
	configurationStore: { configurationItem: null },
	navigationStore: {},
	organisationStore: { organisationList: [], refreshOrganisationList: jest.fn() },
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

const SEARCHES = [
	'searchRegisters',
	'searchSchemas',
	'searchSources',
	'searchAgents',
	'searchViews',
	'searchApplications',
	'loadExistingSelections',
]

/**
 * A bare component context whose searches only record that they ran.
 *
 * @param {Array} calls Where the searches record themselves
 * @return {object} The context
 */
function context(calls) {
	const ctx = { ...EditConfiguration.data.call({ t: (app, s) => s }) }
	ctx.t = (app, s) => s
	for (const [name, method] of Object.entries(EditConfiguration.methods)) {
		ctx[name] = method.bind(ctx)
	}
	for (const name of SEARCHES) {
		ctx[name] = (...args) => calls.push([name, ...args])
	}
	for (const [name, getter] of Object.entries(EditConfiguration.computed)) {
		const fn = typeof getter === 'function' ? getter : getter.get
		Object.defineProperty(ctx, name, {
			get: () => fn.call(ctx),
			enumerable: false,
		})
	}
	return ctx
}

const LISTS = {
	empty: [],
	missing: null,
	filled: [{ id: 1 }],
}

describe('EditConfiguration.created', () => {
	beforeEach(() => jest.clearAllMocks())

	for (const [orgName, orgList] of Object.entries(LISTS)) {
		for (const [appName, appList] of Object.entries(LISTS)) {
			for (const existing of [false, true]) {
				it(`opens with organisations ${orgName}, applications ${appName}, existing ${existing}`, async () => {
					organisationStore.organisationList = orgList
					applicationStore.applicationList = appList
					const item = { id: 'c1', title: 'Existing' }
					configurationStore.configurationItem = existing ? item : null
					const calls = []
					const ctx = context(calls)
					ctx.selectedRegisters = ['stale']
					ctx.selectedApplication = 'stale'
					await EditConfiguration.created.call(ctx)
					expect({
						organisations:
							organisationStore.refreshOrganisationList.mock.calls
								.length,
						applications:
							applicationStore.refreshApplicationList.mock.calls
								.length,
						calls,
						item: JSON.stringify(configurationStore.configurationItem),
						same: configurationStore.configurationItem === item,
						state: JSON.stringify(ctx),
					}).toMatchSnapshot()
				})
			}
		}
	}

	it('gives every new configuration its own lists', async () => {
		configurationStore.configurationItem = null
		await EditConfiguration.created.call(context([]))
		const first = configurationStore.configurationItem
		configurationStore.configurationItem = null
		await EditConfiguration.created.call(context([]))
		expect(configurationStore.configurationItem.registers).not.toBe(
			first.registers,
		)
		expect(configurationStore.configurationItem.notificationGroups).not.toBe(
			first.notificationGroups,
		)
	})
})

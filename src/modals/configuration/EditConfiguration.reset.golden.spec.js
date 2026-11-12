/**
 * Golden record of EditConfiguration's close and reset: the selections and
 * modal state each leaves behind, and the store calls they make.
 *
 * @spec exclude Golden record of client-side modal plumbing.
 */
import EditConfiguration from './EditConfiguration.vue'
import { navigationStore } from '../../store/store.js'

jest.mock('../../store/store.js', () => ({
	applicationStore: { applicationList: [] },
	configurationStore: { configurationItem: null },
	navigationStore: { setModal: jest.fn() },
	organisationStore: {},
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

/**
 * A component context with every selection filled.
 *
 * @return {object} The context.
 */
function filled() {
	const ctx = { ...EditConfiguration.data.call({ t: (app, s) => s }) }
	ctx.t = (app, s) => s
	for (const [name, method] of Object.entries(EditConfiguration.methods)) {
		ctx[name] = method.bind(ctx)
	}
	for (const [name, getter] of Object.entries(EditConfiguration.computed)) {
		const fn = typeof getter === 'function' ? getter : getter.get
		Object.defineProperty(ctx, name, { get: () => fn.call(ctx), enumerable: false })
	}
	Object.assign(ctx, {
		loading: true,
		error: 'x',
		selectedRegisters: [1],
		selectedSchemas: [2],
		selectedObjects: [3],
		selectedSources: [4],
		selectedAgents: [5],
		selectedViews: [6],
		selectedManagedApplications: [7],
		selectedApplication: { value: 'a' },
		selectedType: { value: 'other' },
		selectedSourceType: { value: 'github' },
	})
	return ctx
}

/**
 * The state the golden records.
 *
 * @param {object} ctx The context.
 * @return {object} The recorded state.
 */
function state(ctx) {
	const keys = ['loading', 'error', 'selectedRegisters', 'selectedSchemas', 'selectedObjects',
		'selectedSources', 'selectedAgents', 'selectedViews', 'selectedManagedApplications',
		'selectedApplication', 'selectedType', 'selectedSourceType']
	return Object.fromEntries(keys.map((key) => [key, ctx[key]]))
}

describe('EditConfiguration close and reset (golden)', () => {
	beforeEach(() => navigationStore.setModal.mockClear())

	it.each(['closeModal', 'resetSelections'])('%s', (method) => {
		const ctx = filled()
		ctx[method]()
		expect({ state: state(ctx), setModal: navigationStore.setModal.mock.calls }).toMatchSnapshot()
	})
})

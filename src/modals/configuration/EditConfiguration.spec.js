import EditConfiguration from './EditConfiguration.vue'
import { applicationStore, configurationStore } from '../../store/store.js'

// Opening the modal on an existing configuration fills every selection from it.

jest.mock('../../store/store.js', () => ({
	applicationStore: { applicationList: [] },
	configurationStore: { configurationItem: null },
	navigationStore: {},
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

const LINKED = {
	registers: 'selectedRegisters',
	schemas: 'selectedSchemas',
	objects: 'selectedObjects',
	sources: 'selectedSources',
	agents: 'selectedAgents',
	views: 'selectedViews',
	applications: 'selectedManagedApplications',
}

/**
 * A bare component context: the options object's methods and data, without a mount.
 *
 * @return {object} The context
 */
function context() {
	const ctx = { ...EditConfiguration.data.call({ t: (app, s) => s }) }
	ctx.t = (app, s) => s
	for (const [name, method] of Object.entries(EditConfiguration.methods)) {
		ctx[name] = method.bind(ctx)
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

describe('EditConfiguration.loadExistingSelections', () => {
	let calls

	beforeEach(() => {
		calls = []
		global.fetch = jest.fn((url) => {
			calls.push(url)
			const id = url.split('/').pop()
			if (id === 'broken') {
				return Promise.reject(new Error('down'))
			}
			return Promise.resolve({ json: () => Promise.resolve({ url, id }) })
		})
		jest.spyOn(console, 'error').mockImplementation(() => {})
		applicationStore.applicationList = [{ uuid: 'app-1', title: 'App' }]
	})

	afterEach(() => jest.restoreAllMocks())

	it('leaves everything alone without a configuration', async () => {
		configurationStore.configurationItem = null
		const ctx = context()
		const before = JSON.stringify(ctx)
		await ctx.loadExistingSelections()
		expect(JSON.stringify(ctx)).toBe(before)
		expect(calls).toEqual([])
	})

	it('falls back to the first type and fetches nothing for an empty configuration', async () => {
		configurationStore.configurationItem = {}
		const ctx = context()
		await ctx.loadExistingSelections()
		expect(ctx.selectedType).toEqual(ctx.typeOptions[0])
		expect(ctx.selectedApplication).toBeNull()
		expect(calls).toEqual([])
	})

	it('fills the settings selections from the configuration', async () => {
		const ctx = context()
		configurationStore.configurationItem = {
			application: 'app-1',
			type: ctx.typeOptions[ctx.typeOptions.length - 1].value,
			sourceType: ctx.sourceTypeOptions[0]?.value,
			notificationGroups: [ctx.notificationGroupOptions[0]?.value, 'nope'],
		}
		await ctx.loadExistingSelections()
		expect(ctx.selectedApplication).toEqual({ uuid: 'app-1', title: 'App' })
		expect(ctx.selectedType).toEqual(ctx.typeOptions[ctx.typeOptions.length - 1])
		expect(ctx.selectedSourceType).toEqual(ctx.sourceTypeOptions[0] || null)
		expect(ctx.selectedNotificationGroups).toEqual(
			[ctx.notificationGroupOptions[0]].filter(Boolean),
		)
	})

	it('maps an unknown application and type to their fallbacks', async () => {
		configurationStore.configurationItem = {
			application: 'gone',
			type: 'nope',
			sourceType: 'nope',
		}
		const ctx = context()
		await ctx.loadExistingSelections()
		expect(ctx.selectedApplication).toBeNull()
		expect(ctx.selectedType).toEqual(ctx.typeOptions[0])
		expect(ctx.selectedSourceType).toBeNull()
	})

	it('fetches every linked entity in order and stores it on its selection', async () => {
		const item = {}
		for (const key of Object.keys(LINKED)) {
			item[key] = [`${key}-1`, `${key}-2`]
		}
		configurationStore.configurationItem = item
		const ctx = context()
		await ctx.loadExistingSelections()

		expect(calls).toEqual(
			Object.keys(LINKED).flatMap((key) => [
				`/index.php/apps/openregister/api/${key}/${key}-1`,
				`/index.php/apps/openregister/api/${key}/${key}-2`,
			]),
		)
		for (const [key, target] of Object.entries(LINKED)) {
			expect(ctx[target].map((e) => e.id)).toEqual([`${key}-1`, `${key}-2`])
		}
		for (const flag of [
			'loadingRegisters',
			'loadingSchemas',
			'loadingObjects',
			'loadingSources',
			'loadingAgents',
			'loadingViews',
			'loadingApplications',
		]) {
			expect(ctx[flag]).toBe(false)
		}
	})

	it('skips empty and non-list links', async () => {
		configurationStore.configurationItem = {
			registers: [],
			schemas: 'x',
			objects: null,
		}
		const ctx = context()
		const before = ctx.selectedRegisters
		await ctx.loadExistingSelections()
		expect(calls).toEqual([])
		expect(ctx.selectedRegisters).toBe(before)
	})

	it('logs a failed fetch, keeps the old selection and carries on', async () => {
		configurationStore.configurationItem = {
			registers: ['broken'],
			schemas: ['s-1'],
		}
		const ctx = context()
		const before = ctx.selectedRegisters
		await ctx.loadExistingSelections()
		expect(ctx.selectedRegisters).toBe(before)
		expect(ctx.loadingRegisters).toBe(false)
		expect(console.error).toHaveBeenCalledWith(
			'Error loading selected registers:',
			expect.any(Error),
		)
		expect(ctx.selectedSchemas.map((e) => e.id)).toEqual(['s-1'])
	})
})

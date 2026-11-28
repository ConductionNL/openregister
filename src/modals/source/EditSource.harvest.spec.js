/**
 * The source edit modal's harvest fields: the registered types join the type
 * list, and a new source's account is prefilled with the admin creating it.
 *
 * @spec openspec/changes/app-harvest-fetchers-and-flow-node/specs/data-sync-harvesting/spec.md
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
import EditSource from './EditSource.vue'

jest.mock('@nextcloud/router', () => ({ generateUrl: (path) => path }))
jest.mock('@nextcloud/auth', () => ({ getCurrentUser: () => ({ uid: 'admin' }) }))
jest.mock('@nextcloud/l10n', () => ({ translate: (app, text) => text }))
jest.mock('@nextcloud/vue', () => ({ __esModule: true }))
jest.mock('../../store/store.js', () => ({
	navigationStore: { modal: 'editSource', setModal: jest.fn() },
	sourceStore: { sourceItem: null, saveSource: jest.fn() },
}))

const { sourceStore } = jest.requireMock('../../store/store.js')

/**
 * A component instance stand-in: the modal's fresh state with its methods bound.
 *
 * @return {object} The instance
 */
function instance() {
	const state = EditSource.data()
	const self = { ...state }
	for (const [name, method] of Object.entries(EditSource.methods)) {
		self[name] = method.bind(self)
	}
	return self
}

describe('EditSource harvest fields', () => {
	beforeEach(() => {
		sourceStore.sourceItem = null
		global.fetch = jest.fn()
	})

	it('adds every registered type once, keeping the built-in ones', () => {
		const modal = instance()

		modal.addTypes([
			{ type: 'opencatalogi.dcat-jsonld', displayName: 'DCAT catalogue' },
			{ type: 'internal', displayName: 'Internal again' },
			{ type: 'opencatalogi.dcat-jsonld', displayName: 'Twice' },
			{ displayName: 'No type' },
		])

		expect(modal.typeOptions.options.map((option) => option.id)).toEqual([
			'internal',
			'mongodb',
			'database',
			'opencatalogi.dcat-jsonld',
		])
		expect(modal.typeOptions.options[3].label).toBe('DCAT catalogue')
	})

	it('selects the edited source\'s app type once the list knows it', () => {
		sourceStore.sourceItem = { id: 3, type: 'opencatalogi.dcat-jsonld' }
		const modal = instance()

		modal.addTypes([{ type: 'opencatalogi.dcat-jsonld', displayName: 'DCAT catalogue' }])

		expect(modal.typeOptions.value).toEqual({ label: 'DCAT catalogue', id: 'opencatalogi.dcat-jsonld' })
	})

	it('reads the types from the sources API and keeps the built-ins when that fails', async () => {
		global.fetch.mockResolvedValueOnce({
			ok: true,
			json: async () => ({ results: [{ type: 'app.csv', displayName: 'CSV file' }] }),
		})
		const modal = instance()

		await modal.loadTypes()

		expect(global.fetch).toHaveBeenCalledWith('/apps/openregister/api/sources/types', expect.any(Object))
		expect(modal.typeOptions.options.map((option) => option.id)).toContain('app.csv')

		global.fetch.mockRejectedValueOnce(new Error('offline'))
		const offline = instance()
		await offline.loadTypes()
		expect(offline.typeOptions.options).toHaveLength(3)
	})

	it('prefills a new source\'s account with the current admin and leaves an edited one alone', () => {
		const created = instance()
		created.initializeSourceItem()
		expect(created.sourceItem.runAs).toBe('admin')

		sourceStore.sourceItem = { id: 4, title: 'Feed', runAs: 'harvester', schedule: '0 * * * *', type: 'internal' }
		const edited = instance()
		edited.initializeSourceItem()
		expect(edited.sourceItem.runAs).toBe('harvester')
		expect(edited.sourceItem.schedule).toBe('0 * * * *')
	})

	it('sends the schedule and account with the source', () => {
		const modal = instance()
		modal.sourceItem = { title: 'Feed', schedule: '0 * * * *', runAs: 'admin', databaseUrl: '' }
		modal.isDatabaseType = false

		expect(modal.sourcePayload()).toMatchObject({ schedule: '0 * * * *', runAs: 'admin', type: 'internal' })
	})
})

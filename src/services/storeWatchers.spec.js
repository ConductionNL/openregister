import { flushPromises, mount } from '@vue/test-utils'
/**
 * A watch on a store path reads `this`, and a `<script setup>` binding is not
 * on `this`. These components run with their real setup here, so a watcher
 * that cannot reach its store stays silent and the test fails.
 *
 * @spec exclude Test of client-side watchers on stores.
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
import ClearAuditTrails from '../modals/logs/ClearAuditTrails.vue'
import CopyObject from '../modals/object/CopyObject.vue'
import EditOrganisation from '../modals/organisation/EditOrganisation.vue'
import DeleteSchemaObjects from '../modals/schema/DeleteSchemaObjects.vue'
import ValidateSchema from '../modals/schema/ValidateSchema.vue'
import AuditTrailSideBar from '../sidebars/logs/AuditTrailSideBar.vue'
import SearchTrailSideBar from '../sidebars/logs/SearchTrailSideBar.vue'

jest.mock('@nextcloud/l10n', () => ({
	t: (app, text) => text,
	translate: (app, text) => text,
	translatePlural: (app, one) => one,
}))
jest.mock('@nextcloud/axios', () => ({ __esModule: true, default: {} }))
jest.mock('@nextcloud/dialogs', () => ({ showError: jest.fn(), showSuccess: jest.fn() }))
jest.mock('@nextcloud/vue', () => ({ __esModule: true }))
jest.mock('vue3-apexcharts', () => ({ __esModule: true, default: {} }))
jest.mock('vue-material-design-icons/AlertCircle.vue', () => ({ __esModule: true, default: {} }))
jest.mock('vue-material-design-icons/Cancel.vue', () => ({ __esModule: true, default: {} }))
jest.mock('vue-material-design-icons/ChartLine.vue', () => ({ __esModule: true, default: {} }))
jest.mock('vue-material-design-icons/CheckCircle.vue', () => ({ __esModule: true, default: {} }))
jest.mock('vue-material-design-icons/Cog.vue', () => ({ __esModule: true, default: {} }))
jest.mock('vue-material-design-icons/CogOutline.vue', () => ({ __esModule: true, default: {} }))
jest.mock('vue-material-design-icons/Delete.vue', () => ({ __esModule: true, default: {} }))
jest.mock('vue-material-design-icons/ContentCopy.vue', () => ({ __esModule: true, default: {} }))
jest.mock('vue-material-design-icons/ContentSave.vue', () => ({ __esModule: true, default: {} }))
jest.mock('vue-material-design-icons/Database.vue', () => ({ __esModule: true, default: {} }))
jest.mock('vue-material-design-icons/DatabaseOutline.vue', () => ({ __esModule: true, default: {} }))
jest.mock('vue-material-design-icons/DeleteOutline.vue', () => ({ __esModule: true, default: {} }))
jest.mock('vue-material-design-icons/DeleteSweep.vue', () => ({ __esModule: true, default: {} }))
jest.mock('vue-material-design-icons/Eye.vue', () => ({ __esModule: true, default: {} }))
jest.mock('vue-material-design-icons/FilterOffOutline.vue', () => ({ __esModule: true, default: {} }))
jest.mock('vue-material-design-icons/FilterOutline.vue', () => ({ __esModule: true, default: {} }))
jest.mock('vue-material-design-icons/MagnifyPlus.vue', () => ({ __esModule: true, default: {} }))
jest.mock('vue-material-design-icons/Monitor.vue', () => ({ __esModule: true, default: {} }))
jest.mock('vue-material-design-icons/Pencil.vue', () => ({ __esModule: true, default: {} }))
jest.mock('vue-material-design-icons/Plus.vue', () => ({ __esModule: true, default: {} }))
jest.mock('vue-material-design-icons/TrashCanOutline.vue', () => ({ __esModule: true, default: {} }))
jest.mock('vue-material-design-icons/TrendingUp.vue', () => ({ __esModule: true, default: {} }))
jest.mock('vue-material-design-icons/Tune.vue', () => ({ __esModule: true, default: {} }))
jest.mock('vue-material-design-icons/Webhook.vue', () => ({ __esModule: true, default: {} }))
jest.mock('vue-material-design-icons/AccountMultiple.vue', () => ({ __esModule: true, default: {} }))
jest.mock('vue-material-design-icons/ContentSaveOutline.vue', () => ({ __esModule: true, default: {} }))
jest.mock('vue-material-design-icons/Shield.vue', () => ({ __esModule: true, default: {} }))
jest.mock('vue-material-design-icons/AccountCircle.vue', () => ({ __esModule: true, default: {} }))
jest.mock('vue-material-design-icons/AccountMinus.vue', () => ({ __esModule: true, default: {} }))
jest.mock('vue-material-design-icons/AccountPlus.vue', () => ({ __esModule: true, default: {} }))
jest.mock('../store/store.js', () => {
	const { reactive: shared } = require('vue')
	const lenient = (fields) => shared(fields)
	return {
		auditTrailStore: lenient({ auditTrailList: [], auditTrailPagination: { total: 3 } }),
		navigationStore: lenient({ dialog: null, modal: null, sidebarState: {} }),
		objectStore: lenient({ objectItem: null }),
		organisationStore: lenient({ organisationItem: null }),
		registerStore: lenient({ registerItem: null, registerList: [] }),
		schemaStore: lenient({ schemaItem: null, schemaList: [] }),
		searchTrailStore: lenient({ searchTrailList: [] }),
		webhookStore: lenient({ webhookItem: null }),
	}
})

const stores = jest.requireMock('../store/store.js')

/**
 * Mounts a component with its real setup, by default without its mounted hook.
 * Its render is left out: only its watchers matter here.
 *
 * @param {object} component The component
 * @param {string[]} names The methods to log
 * @param {Function} [mounted] The mounted hook to keep
 * @return {object} The instance and the log
 */
function watched(component, names, mounted) {
	const log = []
	const methods = { ...component.methods }
	names.forEach((name) => {
		methods[name] = () => log.push(name)
	})
	const wrapper = mount(
		{ ...component, methods, mounted, render: () => null },
		{
			global: {
				mocks: { $route: { path: '/', query: {} }, $router: { replace: jest.fn() } },
				config: { warnHandler: () => {} },
			},
		},
	)
	return { log, vm: wrapper.vm.$.proxy }
}

beforeEach(() => {
	Object.assign(stores.navigationStore, { dialog: null, modal: null })
	stores.schemaStore.schemaItem = null
})

describe('watchers on a store', () => {
	it('ClearAuditTrails takes the count when it opens', async () => {
		const { vm } = watched(ClearAuditTrails, [])
		stores.navigationStore.dialog = 'clearAuditTrails'
		await flushPromises()
		expect(vm.filteredCount).toBe(3)
	})

	it('CopyObject starts clean when it opens', async () => {
		const { vm } = watched(CopyObject, [])
		vm.error = 'old'
		stores.navigationStore.dialog = 'copyObject'
		await flushPromises()
		expect(vm.error).toBe(false)
	})

	it.each([
		['DeleteSchemaObjects', DeleteSchemaObjects, 'deleteSchemaObjects'],
		['ValidateSchema', ValidateSchema, 'validateSchema'],
	])('%s counts once when it opens', async (name, component, dialog) => {
		stores.schemaStore.schemaItem = { id: 1 }
		stores.navigationStore.dialog = dialog
		const { log } = watched(component, ['loadObjectCount'], component.mounted)
		await flushPromises()
		expect(log).toHaveLength(1)
	})

	it.each([
		['DeleteSchemaObjects', DeleteSchemaObjects],
		['ValidateSchema', ValidateSchema],
	])('%s counts on a schema that arrives later', async (name, component) => {
		const { log } = watched(component, ['loadObjectCount'])
		stores.schemaStore.schemaItem = { id: 1 }
		await flushPromises()
		expect(log).toHaveLength(1)
	})

	it('EditOrganisation takes another organisation', async () => {
		const { log } = watched(EditOrganisation, ['initializeOrganisationItem'])
		stores.organisationStore.organisationItem = { uuid: 'a' }
		await flushPromises()
		expect(log).toEqual(['initializeOrganisationItem'])
	})

	it('AuditTrailSideBar follows the list and the scope', async () => {
		const { log } = watched(AuditTrailSideBar, [
			'updateFilteredCount',
			'loadStatistics',
			'loadActionDistribution',
			'loadTopObjects',
			'applyFilters',
		])
		stores.auditTrailStore.auditTrailList = [{ id: 1 }]
		stores.schemaStore.schemaItem = { id: 2 }
		await flushPromises()
		expect(log.sort()).toEqual([
			'applyFilters',
			'loadActionDistribution',
			'loadStatistics',
			'loadTopObjects',
			'updateFilteredCount',
		])
	})

	it('SearchTrailSideBar follows the list', async () => {
		const { log } = watched(SearchTrailSideBar, ['updateFilteredCount'])
		stores.searchTrailStore.searchTrailList = [{ id: 1 }]
		await flushPromises()
		expect(log).toEqual(['updateFilteredCount'])
	})
})

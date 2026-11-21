/**
 * The audit trail page's export and table columns: the export request
 * carries the format, inclusions and every set filter, the returned file is
 * handed to the browser, and a failed export says why.
 *
 * @spec exclude Unit test of client-side audit trail export logic.
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
import { showError, showSuccess } from '@nextcloud/dialogs'
import AuditTrailIndex from './AuditTrailIndex.vue'
import eventBus from '../../eventBus.js'
import { auditTrailStore } from '../../store/store.js'

jest.mock('@nextcloud/dialogs', () => ({
	showError: jest.fn(),
	showSuccess: jest.fn(),
}))
jest.mock('@nextcloud/l10n', () => ({
	translate: (app, text, vars) =>
		vars ? text.replace(/{(\w+)}/g, (m, k) => (k in vars ? vars[k] : m)) : text,
	translatePlural: (app, s) => s,
}))
jest.mock('@nextcloud/vue', () => ({}))
jest.mock('@conduction/nextcloud-vue', () => ({}))
jest.mock('../../store/store.js', () => ({
	auditTrailStore: {
		auditTrailFilters: null,
		auditTrailSort: {},
		auditTrailList: [],
		auditTrailPagination: {},
		refreshAuditTrailList: jest.fn(),
		setAuditTrailFilters: jest.fn(),
		setAuditTrailItem: jest.fn(),
		setAuditTrailSort: jest.fn(),
		fetchAuditTrails: jest.fn(),
	},
	navigationStore: { setDialog: jest.fn() },
	searchTrailStore: {},
}))
jest.mock('../../eventBus.js', () => ({ emit: jest.fn(), on: jest.fn(), off: jest.fn() }))
jest.mock('../../components/PaginationComponent.vue', () => ({}))
jest.mock('../../components/shared/ActionMenu.vue', () => ({}))
jest.mock('vue-material-design-icons/Check.vue', () => ({ name: 'Check' }))
jest.mock('vue-material-design-icons/CompareHorizontal.vue', () => ({ name: 'CompareHorizontal' }))
jest.mock('vue-material-design-icons/ContentCopy.vue', () => ({ name: 'ContentCopy' }))
jest.mock('vue-material-design-icons/Delete.vue', () => ({ name: 'Delete' }))
jest.mock('vue-material-design-icons/Download.vue', () => ({}))
jest.mock('vue-material-design-icons/Eye.vue', () => ({ name: 'Eye' }))
jest.mock('vue-material-design-icons/Pencil.vue', () => ({}))
jest.mock('vue-material-design-icons/Plus.vue', () => ({}))
jest.mock('vue-material-design-icons/TextBoxOutline.vue', () => ({}))

const { methods, computed } = AuditTrailIndex

/**
 * Answer the next fetch with this JSON body.
 *
 * @param {object} body The JSON body
 * @return {void}
 */
function answer(body) {
	global.fetch = jest.fn().mockResolvedValue({ json: () => Promise.resolve(body) })
}

let consoleError
let createObjectURL
let revokeObjectURL
let clicked

beforeEach(() => {
	jest.clearAllMocks()
	auditTrailStore.auditTrailFilters = null
	auditTrailStore.auditTrailList = []
	consoleError = jest.spyOn(console, 'error').mockImplementation(() => {})
	createObjectURL = jest.fn(() => 'blob:export-1')
	revokeObjectURL = jest.fn()
	window.URL.createObjectURL = createObjectURL
	window.URL.revokeObjectURL = revokeObjectURL
	clicked = []
	jest.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(function() {
		clicked.push({ href: this.href, download: this.download, attached: document.body.contains(this) })
	})
})

afterEach(() => {
	consoleError.mockRestore()
	jest.restoreAllMocks()
	delete global.fetch
})

describe('exportFilteredAuditTrails', () => {
	it('asks for the format, inclusions and every set filter', async () => {
		auditTrailStore.auditTrailFilters = { register: '5', schema: '', action: null, user: undefined, dateFrom: '2026-01-01' }
		answer({ success: true, data: { content: 'a,b', contentType: 'text/csv', filename: 'audit.csv' } })

		await methods.exportFilteredAuditTrails.call({}, { format: 'json', includeMetadata: true })

		expect(global.fetch).toHaveBeenCalledWith(
			'/index.php/apps/openregister/api/audit-trails/export?format=json&includeChanges=false&includeMetadata=true&register=5&dateFrom=2026-01-01',
		)
	})

	it('defaults to csv without inclusions when no options are set', async () => {
		answer({ success: true, data: { content: '', contentType: 'text/csv', filename: 'audit.csv' } })

		await methods.exportFilteredAuditTrails.call({}, {})

		expect(global.fetch).toHaveBeenCalledWith(
			'/index.php/apps/openregister/api/audit-trails/export?format=csv&includeChanges=false&includeMetadata=false',
		)
	})

	it('hands the exported file to the browser and reports success', async () => {
		answer({ success: true, data: { content: 'a,b', contentType: 'text/csv', filename: 'audit.csv' } })

		await methods.exportFilteredAuditTrails.call({}, { format: 'csv' })

		expect(createObjectURL).toHaveBeenCalledTimes(1)
		const blob = createObjectURL.mock.calls[0][0]
		expect(blob).toBeInstanceOf(Blob)
		expect(blob.type).toBe('text/csv')
		expect(clicked).toEqual([{ href: 'blob:export-1', download: 'audit.csv', attached: true }])
		expect(revokeObjectURL).toHaveBeenCalledWith('blob:export-1')
		expect(document.querySelectorAll('a[download]')).toHaveLength(0)
		expect(showSuccess).toHaveBeenCalledWith('Export completed successfully')
		expect(showError).not.toHaveBeenCalled()
	})

	it('reports the server\'s reason when it exports nothing', async () => {
		answer({ success: false, error: 'No audit trails match' })

		await methods.exportFilteredAuditTrails.call({}, { format: 'csv' })

		expect(showError).toHaveBeenCalledWith('Export failed: No audit trails match')
		expect(showSuccess).not.toHaveBeenCalled()
		expect(clicked).toEqual([])
	})

	it('falls back to a generic reason, also for success without data', async () => {
		answer({ success: true })

		await methods.exportFilteredAuditTrails.call({}, { format: 'csv' })

		expect(showError).toHaveBeenCalledWith('Export failed: Export failed')
	})
})

describe('tableColumns', () => {
	it('lists the columns in order with the timestamp sortable at a fixed width', () => {
		const columns = computed.tableColumns.call({})

		expect(columns.map((column) => column.key)).toEqual(['action', 'created', 'object', 'register', 'userName', 'schema', 'size'])
		expect(columns[1]).toEqual({ key: 'created', label: 'Timestamp', width: '180px', sortable: true })
		expect(columns[6]).toEqual({ key: 'size', label: 'Size', width: '100px' })
	})
})

describe('updateCounts', () => {
	it('emits zero when the list cannot be read', () => {
		Object.defineProperty(auditTrailStore, 'auditTrailList', {
			configurable: true,
			get() {
				throw new Error('store gone')
			},
		})

		methods.updateCounts.call({})

		expect(eventBus.emit).toHaveBeenCalledWith('audit-trail-filtered-count', 0)
		expect(consoleError).toHaveBeenCalled()
		Object.defineProperty(auditTrailStore, 'auditTrailList', { configurable: true, writable: true, value: [] })
	})
})

/**
 * The audit page's switch between the record history and the administrative
 * changes (REQ-HSA-011).
 *
 * @spec openspec/changes/history-schema-and-settings-edits-audited/tasks.md
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
import AuditTrailIndex from './AuditTrailIndex.vue'
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
		auditTrailCategory: 'domain',
		setAuditTrailCategory: jest.fn(),
		setAuditTrailPagination: jest.fn(),
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

const { methods } = AuditTrailIndex

describe('AuditTrailIndex category switch', () => {
	beforeEach(() => {
		jest.clearAllMocks()
		auditTrailStore.auditTrailPagination = { page: 3, limit: 50, total: 120, pages: 3 }
	})

	it('switches the store to the administrative changes from the first page, clears the selection and reloads', async () => {
		const page = { selectedAuditTrails: ['a-1'], loadAuditTrails: jest.fn() }

		methods.setCategory.call(page, 'administrative')

		expect(auditTrailStore.setAuditTrailCategory).toHaveBeenCalledWith('administrative')
		expect(auditTrailStore.setAuditTrailPagination).toHaveBeenCalledWith({ page: 1, limit: 50, total: 120, pages: 3 })
		expect(page.selectedAuditTrails).toEqual([])
		expect(page.loadAuditTrails).toHaveBeenCalledTimes(1)
	})

	it('offers the two choices on the page', () => {
		const source = require('fs').readFileSync(require.resolve('./AuditTrailIndex.vue'), 'utf8')
		expect(source).toContain('value="domain"')
		expect(source).toContain('value="administrative"')
		expect(source).toContain("t('openregister', 'Administrative changes')")
		expect(source).toContain("t('openregister', 'Record history')")
		expect(source).toContain('@update:modelValue="setCategory"')
	})
})

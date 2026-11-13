/**
 * The audit trail page's own logic: the row menu, row styling, the store
 * calls behind loading, filtering, sorting and dialogs, copying a row,
 * counting rows for the sidebar, and mass deletion.
 *
 * @spec exclude Unit test of client-side audit trail page logic.
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
import { showError, showSuccess } from '@nextcloud/dialogs'
import AuditTrailIndex from './AuditTrailIndex.vue'
import eventBus from '../../eventBus.js'
import { auditTrailStore, navigationStore } from '../../store/store.js'

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
 * A bare page context with the page's methods bound to it.
 *
 * @param {object} extra Extra state
 * @return {object} The context
 */
function page(extra = {}) {
	const ctx = {
		...AuditTrailIndex.data.call({}),
		$refs: { indexPage: { setMassDeleteResult: jest.fn() } },
		...extra,
	}
	for (const [name, method] of Object.entries(methods)) {
		ctx[name] = method.bind(ctx)
	}
	return ctx
}

let consoleError

beforeEach(() => {
	jest.clearAllMocks()
	auditTrailStore.auditTrailList = []
	auditTrailStore.auditTrailPagination = {}
	auditTrailStore.auditTrailFilters = null
	consoleError = jest.spyOn(console, 'error').mockImplementation(() => {})
})

afterEach(() => {
	consoleError.mockRestore()
	jest.useRealTimers()
})

describe('computed', () => {
	it('defaults the pagination the store does not know', () => {
		expect(computed.paginationData.call({})).toEqual({ page: 1, pages: 1, total: 0, limit: 50 })
		auditTrailStore.auditTrailPagination = { page: 3, pages: 9, total: 410, limit: 25 }
		expect(computed.paginationData.call({})).toEqual({ page: 3, pages: 9, total: 410, limit: 25 })
	})

	it('passes the list through only when it is an array', () => {
		const list = [{ id: 1 }]
		auditTrailStore.auditTrailList = list
		expect(computed.paginatedAuditTrails.call({})).toBe(list)
		auditTrailStore.auditTrailList = { id: 1 }
		expect(computed.paginatedAuditTrails.call({})).toEqual([])
	})
})

describe('auditTrailActions', () => {
	it('offers details, changes, copy and delete, each running its handler', () => {
		const ctx = page()
		ctx.viewDetails = jest.fn()
		ctx.viewChanges = jest.fn()
		ctx.copyData = jest.fn()
		ctx.deleteAuditTrail = jest.fn()
		const row = { id: 4, changed: { a: 1 } }

		const entries = methods.auditTrailActions.call(ctx, row)
		expect(entries.map((e) => e.label)).toEqual(['View Details', 'View Changes', 'Copy Data', 'Delete'])
		expect(entries[1].hidden).toBe(false)
		expect(entries[2].icon.name).toBe('ContentCopy')
		expect(entries[2].iconClass).toBeUndefined()

		entries.forEach((e) => e.run())
		expect(ctx.viewDetails).toHaveBeenCalledWith(row)
		expect(ctx.viewChanges).toHaveBeenCalledWith(row)
		expect(ctx.copyData).toHaveBeenCalledWith(row)
		expect(ctx.deleteAuditTrail).toHaveBeenCalledWith(row)
	})

	it('hides changes without any and shows a copied row as copied', () => {
		const ctx = page()
		ctx.copyStates[4] = true
		const entries = methods.auditTrailActions.call(ctx, { id: 4, changed: [] })
		expect(entries[1].hidden).toBe(true)
		expect(entries[2].label).toBe('Copied!')
		expect(entries[2].icon.name).toBe('Check')
		expect(entries[2].iconClass).toBe('copySuccessIcon')
	})
})

describe('hasChanges', () => {
	it.each([
		[null, false],
		[{}, false],
		[{ changed: [] }, false],
		[{ changed: ['a'] }, true],
		[{ changed: {} }, false],
		[{ changed: { a: 1 } }, true],
		[{ changed: 'text' }, false],
	])('%j has changes: %s', (row, expected) => {
		expect(methods.hasChanges(row)).toBe(expected)
	})
})

describe('getRowClass', () => {
	it.each([
		['create', 'auditTrailRow--success'],
		['UPDATE', 'auditTrailRow--warning'],
		['delete', 'auditTrailRow--error'],
		['read', 'auditTrailRow--info'],
		['file.renamed', ''],
		[undefined, ''],
	])('maps %s to %j', (action, expected) => {
		expect(page().getRowClass({ action })).toBe(expected)
	})
})

describe('store and dialog calls', () => {
	it('loads the list and reports a failure', async () => {
		await page().loadAuditTrails()
		expect(auditTrailStore.refreshAuditTrailList).toHaveBeenCalledTimes(1)
		expect(showError).not.toHaveBeenCalled()

		auditTrailStore.refreshAuditTrailList.mockRejectedValueOnce(new Error('down'))
		await page().loadAuditTrails()
		expect(showError).toHaveBeenCalledWith('Error loading audit trails')
	})

	it('stores new filters and reloads', () => {
		const ctx = page()
		ctx.loadAuditTrails = jest.fn()
		methods.handleFiltersChanged.call(ctx, { action: 'create' })
		expect(auditTrailStore.setAuditTrailFilters).toHaveBeenCalledWith({ action: 'create' })
		expect(ctx.loadAuditTrails).toHaveBeenCalledTimes(1)
	})

	it('hands export options on and exports csv with changes by default', () => {
		const ctx = page()
		ctx.exportFilteredAuditTrails = jest.fn()
		methods.handleExport.call(ctx, { format: 'json' })
		methods.exportAuditTrails.call(ctx)
		expect(ctx.exportFilteredAuditTrails.mock.calls).toEqual([
			[{ format: 'json' }],
			[{ format: 'csv', includeChanges: true }],
		])
	})

	it('drives the refresh flag around a reload, even when it fails', async () => {
		const ctx = page()
		let during
		ctx.loadAuditTrails = jest.fn(() => {
			during = ctx.isRefreshing
			return Promise.reject(new Error('x'))
		})
		await expect(methods.handleRefresh.call(ctx)).rejects.toThrow('x')
		expect(during).toBe(true)
		expect(ctx.isRefreshing).toBe(false)
	})

	it('refreshes by reloading', async () => {
		const ctx = page()
		ctx.loadAuditTrails = jest.fn()
		await methods.refreshAuditTrails.call(ctx)
		expect(ctx.loadAuditTrails).toHaveBeenCalledTimes(1)
	})

	it.each([
		['viewDetails', 'auditTrailDetails'],
		['viewChanges', 'auditTrailChanges'],
		['deleteAuditTrail', 'deleteAuditTrail'],
	])('%s selects the row and opens %s', (method, dialog) => {
		const row = { id: 9 }
		page()[method](row)
		expect(auditTrailStore.setAuditTrailItem).toHaveBeenCalledWith(row)
		expect(navigationStore.setDialog).toHaveBeenCalledWith(dialog)
	})

	it('keeps the selected ids and names a row by its id', () => {
		const ctx = page()
		ctx.onSelect([1, 2])
		expect(ctx.selectedAuditTrails).toEqual([1, 2])
		expect(ctx.auditTrailName({ id: 12 })).toBe('Audit trail #12')
	})

	it('emits the row count for the sidebar, zero for a non-list', () => {
		auditTrailStore.auditTrailList = [{}, {}, {}]
		page().updateCounts()
		auditTrailStore.auditTrailList = null
		page().updateCounts()
		expect(eventBus.emit.mock.calls).toEqual([
			['audit-trail-filtered-count', 3],
			['audit-trail-filtered-count', 0],
		])
	})
})

describe('onSort', () => {
	it('asks the server for page one sorted by the mapped column', async () => {
		auditTrailStore.auditTrailPagination = { limit: 25 }
		const ctx = page()
		await methods.onSort.call(ctx, { key: 'userName', order: 'desc' })
		expect(ctx.sortKey).toBe('userName')
		expect(ctx.sortOrder).toBe('desc')
		expect(auditTrailStore.setAuditTrailSort).toHaveBeenCalledWith({ user_name: 'DESC' })
		expect(auditTrailStore.fetchAuditTrails).toHaveBeenCalledWith({ page: 1, limit: 25 })
	})

	it('clears the sort for an unknown column and defaults to ascending', async () => {
		const ctx = page()
		await methods.onSort.call(ctx, null)
		expect(ctx.sortKey).toBeNull()
		expect(ctx.sortOrder).toBe('asc')
		expect(auditTrailStore.setAuditTrailSort).toHaveBeenCalledWith({})
	})

	it('reports a failed reload', async () => {
		auditTrailStore.fetchAuditTrails.mockRejectedValueOnce(new Error('down'))
		await methods.onSort.call(page(), { key: 'created', order: 'asc' })
		expect(showError).toHaveBeenCalledWith('Error loading audit trails')
	})
})

describe('copyData', () => {
	const row = { id: 5, action: 'create' }
	const json = JSON.stringify(row, null, 2)

	afterEach(() => {
		delete navigator.clipboard
		delete document.execCommand
	})

	it('copies the row as JSON and flags it for two seconds', async () => {
		jest.useFakeTimers()
		const writeText = jest.fn().mockResolvedValue()
		Object.defineProperty(navigator, 'clipboard', { value: { writeText }, configurable: true })
		const ctx = page()

		await ctx.copyData(row)
		expect(writeText).toHaveBeenCalledWith(json)
		expect(ctx.copyStates[5]).toBe(true)
		expect(showSuccess).toHaveBeenCalledWith('Audit trail data copied to clipboard')

		jest.advanceTimersByTime(2000)
		expect(ctx.copyStates[5]).toBe(false)
	})

	it('falls back to a textarea copy without the clipboard API', async () => {
		let copied
		document.execCommand = jest.fn(() => {
			copied = document.querySelector('textarea').value
		})
		const ctx = page()

		await ctx.copyData(row)
		expect(document.execCommand).toHaveBeenCalledWith('copy')
		expect(copied).toBe(json)
		expect(document.querySelector('textarea')).toBeNull()
		expect(ctx.copyStates[5]).toBe(true)
		expect(showError).not.toHaveBeenCalled()
	})

	it('reports a copy that fails both ways', async () => {
		document.execCommand = jest.fn(() => {
			throw new Error('denied')
		})
		const ctx = page()
		await ctx.copyData(row)
		expect(showError).toHaveBeenCalledWith('Failed to copy data to clipboard')
		expect(ctx.copyStates[5]).toBeUndefined()
	})
})

describe('handleMassDelete', () => {
	beforeEach(() => {
		global.OC = { requestToken: 'tok' }
	})

	afterEach(() => {
		delete global.fetch
		delete global.OC
	})

	it('deletes the confirmed ids, clears the selection and reloads', async () => {
		global.fetch = jest.fn().mockResolvedValue({ json: () => Promise.resolve({ success: true }) })
		const ctx = page({ selectedAuditTrails: [1, 2, 3] })
		ctx.loadAuditTrails = jest.fn()

		await methods.handleMassDelete.call(ctx, [1, 3])
		expect(global.fetch).toHaveBeenCalledWith('/index.php/apps/openregister/api/audit-trails/bulk-delete', {
			method: 'DELETE',
			headers: { 'Content-Type': 'application/json', requesttoken: 'tok' },
			body: JSON.stringify({ ids: [1, 3] }),
		})
		expect(ctx.$refs.indexPage.setMassDeleteResult).toHaveBeenCalledWith({ success: true })
		expect(ctx.selectedAuditTrails).toEqual([])
		expect(ctx.loadAuditTrails).toHaveBeenCalledTimes(1)
	})

	it.each([
		[{ success: false, error: 'locked' }, 'locked'],
		[{ success: false }, 'Deletion failed'],
	])('reports a refusal %j to the dialog', async (answer, message) => {
		global.fetch = jest.fn().mockResolvedValue({ json: () => Promise.resolve(answer) })
		const ctx = page({ selectedAuditTrails: [1] })
		ctx.loadAuditTrails = jest.fn()

		await methods.handleMassDelete.call(ctx, [1])
		expect(ctx.$refs.indexPage.setMassDeleteResult).toHaveBeenCalledWith({ success: false, error: message })
		expect(ctx.selectedAuditTrails).toEqual([1])
		expect(ctx.loadAuditTrails).not.toHaveBeenCalled()
	})
})

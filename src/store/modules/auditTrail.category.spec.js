/**
 * The audit trail store reads one category at a time (REQ-HSA-011): record
 * history by default, the administrative changes on request, kept apart from
 * the sidebar filters, which replace theirs wholesale.
 *
 * @spec openspec/changes/history-schema-and-settings-edits-audited/tasks.md
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
import { createPinia, setActivePinia } from 'pinia'
import { useAuditTrailStore } from './auditTrail.js'

describe('AuditTrail store category', () => {
	let store

	beforeEach(() => {
		setActivePinia(createPinia())
		store = useAuditTrailStore()
		jest.spyOn(console, 'error').mockImplementation(() => {})
		global.OC = { requestToken: 'token' }
		global.fetch = jest.fn().mockResolvedValue({
			ok: true,
			json: async () => ({ results: [], total: 0 }),
		})
	})

	it('reads the record history by default and sends no category', async () => {
		expect(store.auditTrailCategory).toBe('domain')
		await store.fetchAuditTrails({ page: 1, limit: 50 })
		expect(global.fetch.mock.calls[0][0]).not.toContain('category=')
	})

	it('asks for the administrative changes, and keeps asking after the sidebar replaces its filters', async () => {
		store.setAuditTrailCategory('administrative')
		store.setAuditTrailFilters({ action: 'schema.updated' })
		await store.fetchAuditTrails({ page: 1, limit: 50 })

		const url = global.fetch.mock.calls[0][0]
		expect(url).toContain('category=administrative')
		expect(url).toContain('schema.updated')
		expect(store.auditTrailFilters).toEqual({ action: 'schema.updated' })
	})

	it('reads any other value as the record history', () => {
		store.setAuditTrailCategory('administrative')
		store.setAuditTrailCategory('everything')
		expect(store.auditTrailCategory).toBe('domain')
		expect(store.listFilters()).toEqual({})
	})

	it('does not share one list request between the two categories', async () => {
		const first = store.sharedListRequest()
		store.setAuditTrailCategory('administrative')
		const second = store.sharedListRequest()
		await Promise.all([first, second])
		expect(global.fetch).toHaveBeenCalledTimes(2)
	})
})

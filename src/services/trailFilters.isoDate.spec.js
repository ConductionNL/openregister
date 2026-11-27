/**
 * The audit page's date range as text the server parses.
 *
 * @spec openspec/changes/audit-and-dashboard-screens-stated/specs/enhanced-audit-trail/spec.md#requirement-the-audit-trail-page-browses-filters-and-exports-entries-req-ads-001
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
import { isoDate } from './trailFilters.js'

describe('isoDate', () => {
	it('writes a Date as ISO 8601 text', () => {
		expect(isoDate(new Date('2026-11-25T08:00:00.000Z'))).toBe('2026-11-25T08:00:00.000Z')
	})

	it('drops an invalid Date rather than sending "Invalid Date"', () => {
		expect(isoDate(new Date('not a date'))).toBeNull()
	})

	it('leaves text, null and undefined as they are', () => {
		expect(isoDate('2026-11-25')).toBe('2026-11-25')
		expect(isoDate(null)).toBeNull()
		expect(isoDate(undefined)).toBeUndefined()
	})
})

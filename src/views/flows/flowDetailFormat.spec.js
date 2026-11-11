/**
 * flowDetail formatters: the node detail line, durations in words and an unparseable timestamp.
 *
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
import { formatDateTime, formatDuration, nodeDetail } from './flowDetail.js'

jest.mock('@nextcloud/l10n', () => ({
	getCanonicalLocale: () => 'en-US',
	translate: (app, text) => text,
}))

describe('flowDetail formatters', () => {
	it('nodeDetail shows register › schema, else the node id, else nothing', () => {
		expect(nodeDetail({ id: 'n1', config: { register: 'zaken', schema: 'zaak' } })).toBe('zaken › zaak')
		expect(nodeDetail({ id: 'n1', config: { register: 'zaken' } })).toBe('n1')
		expect(nodeDetail({ id: 'n2' })).toBe('n2')
		expect(nodeDetail(null)).toBe('')
	})

	it('formatDuration picks milliseconds, seconds, or minutes and seconds', () => {
		expect(formatDuration(null)).toBe('')
		expect(formatDuration('5')).toBe('')
		expect(formatDuration(250)).toBe('250 ms')
		expect(formatDuration(1500)).toBe('1.5 sec')
		expect(formatDuration(59999)).toBe('60 sec')
		expect(formatDuration(125000)).toBe('2 min 5 sec')
		expect(formatDuration(60000)).toBe('1 min 0 sec')
	})

	it('formatDateTime returns an unparseable value as text', () => {
		expect(formatDateTime('')).toBe('')
		expect(formatDateTime('not a date')).toBe('not a date')
	})
})

import { dateToString } from './dateUtils.js'

// Built with the local-time constructor so the local getters dateToString
// reads give the same parts in every timezone.
const LOCAL = new Date(2026, 0, 2, 3, 4, 5)

describe('dateToString', () => {
	it('formats a date as YYYY-MM-DD', () => {
		expect(dateToString(LOCAL, 'date')).toBe('2026-01-02')
	})

	it('formats a time as HH:mm:ss', () => {
		expect(dateToString(LOCAL, 'time')).toBe('03:04:05')
	})

	it('formats a date-time without a zone suffix', () => {
		expect(dateToString(LOCAL, 'date-time')).toBe('2026-01-02T03:04:05')
	})

	it('falls back to the ISO string for any other format', () => {
		const utc = new Date(Date.UTC(2026, 5, 7, 8, 9, 10))
		expect(dateToString(utc, 'unknown')).toBe('2026-06-07T08:09:10.000Z')
	})
})

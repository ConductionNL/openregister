/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Property tests for the date-picker string conversions: arbitrary input never
 * throws, and every well-formed value survives a round trip unchanged.
 *
 * @spec exclude Property test of a presentation helper.
 */
import fc from 'fast-check'
import { dateToString, stringToDate } from './dateUtils.js'

const pad = (n, width = 2) => String(n).padStart(width, '0')

const calendarDay = fc
	.date({ min: new Date(1000, 0, 1), max: new Date(9999, 11, 31), noInvalidDate: true })
	.map((d) => `${pad(d.getFullYear(), 4)}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`)

const clockTime = fc
	.tuple(fc.integer({ min: 0, max: 23 }), fc.integer({ min: 0, max: 59 }), fc.integer({ min: 0, max: 59 }))
	.map(([h, m, s]) => `${pad(h)}:${pad(m)}:${pad(s)}`)

const format = fc.constantFrom('date', 'time', 'date-time', 'unknown')

describe('stringToDate', () => {
	it('returns a Date or null for any string and never throws', () => {
		fc.assert(fc.property(fc.string(), format, (value, fmt) => {
			const result = stringToDate(value, fmt)
			expect(result === null || result instanceof Date).toBe(true)
		}))
	})

	it('reads every calendar day back to the same date string', () => {
		fc.assert(fc.property(calendarDay, (day) => {
			expect(dateToString(stringToDate(day, 'date'), 'date')).toBe(day)
		}))
	})

	it('ignores a time part after a calendar day', () => {
		fc.assert(fc.property(calendarDay, clockTime, fc.constantFrom('T', ' '), (day, time, sep) => {
			expect(dateToString(stringToDate(`${day}${sep}${time}`, 'date'), 'date')).toBe(day)
		}))
	})

	it('reads every clock time back to the same time string', () => {
		fc.assert(fc.property(clockTime, (time) => {
			expect(dateToString(stringToDate(time, 'time'), 'time')).toBe(time)
		}))
	})
})

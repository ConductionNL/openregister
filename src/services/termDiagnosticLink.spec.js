/**
 * The deep link into the working calendar admin's "Try a date" panel.
 *
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 * @spec openspec/changes/term-engine-diagnostic/specs/flow-business-timers/spec.md#requirement-the-diagnostic-is-reachable-from-the-calendar-admin-page-and-by-deep-link
 */
import { deepLinkQuery, parseTerm, readDeepLink } from './termDiagnosticLink.js'

describe('termDiagnosticLink', () => {
	it("reads the spec's example link: nl-national with 42 calendar days rolled to the next working day", () => {
		expect(
			readDeepLink(
				'?section=calendars&calendar=nl-national&sla=42:calendarDays:next',
			),
		).toEqual({
			calendar: 'nl-national',
			sla: { value: 42, unit: 'calendarDays', rollToWorkingDay: 'next' },
		})
	})

	it('reads a link without a term, or with one it cannot read, as the calendar alone', () => {
		expect(readDeepLink('?section=calendars&calendar=nl-national')).toEqual({
			calendar: 'nl-national',
			sla: null,
		})
		expect(
			readDeepLink('?section=calendars&calendar=nl-national&sla=soon'),
		).toEqual({ calendar: 'nl-national', sla: null })
	})

	it('ignores a link to another section or without a calendar', () => {
		expect(readDeepLink('?section=files&calendar=nl-national')).toBeNull()
		expect(readDeepLink('?section=calendars&calendar=%20')).toBeNull()
		expect(readDeepLink('')).toBeNull()
	})

	it('reads a term with or without a roll and refuses an unknown unit, roll or length', () => {
		expect(parseTerm('2:businessDays')).toEqual({
			value: 2,
			unit: 'businessDays',
			rollToWorkingDay: 'none',
		})
		expect(parseTerm('1.5:hours:previous')).toEqual({
			value: 1.5,
			unit: 'hours',
			rollToWorkingDay: 'previous',
		})
		expect(parseTerm('2:weeks')).toBeNull()
		expect(parseTerm('2:businessDays:sideways')).toBeNull()
		expect(parseTerm('0:businessDays')).toBeNull()
		expect(parseTerm(':businessDays')).toBeNull()
		expect(parseTerm('1:hours:next:extra')).toBeNull()
		expect(parseTerm(null)).toBeNull()
	})

	it('writes the link it reads', () => {
		const query = deepLinkQuery('nl-national', {
			value: 42,
			unit: 'calendarDays',
			rollToWorkingDay: 'next',
		})

		expect(query).toBe(
			'?section=calendars&calendar=nl-national&sla=42%3AcalendarDays%3Anext',
		)
		expect(readDeepLink(query)).toEqual({
			calendar: 'nl-national',
			sla: { value: 42, unit: 'calendarDays', rollToWorkingDay: 'next' },
		})
		expect(deepLinkQuery('x')).toBe('?section=calendars&calendar=x')
	})
})

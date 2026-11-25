/**
 * The deep link into the working calendar admin's "Try a date" panel
 * (term-engine-diagnostic): `?section=calendars&calendar=<slug>&sla=<value>:<unit>[:<roll>]`
 * opens that calendar with the term filled in, waiting for a start moment.
 *
 * Pure functions, so a consuming app can build the same link this page reads.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @spec openspec/changes/term-engine-diagnostic/specs/flow-business-timers/spec.md#requirement-the-diagnostic-is-reachable-from-the-calendar-admin-page-and-by-deep-link
 */

/** The units a term is counted in, as the timer engine names them. */
export const TERM_UNITS = Object.freeze(['businessDays', 'calendarDays', 'hours'])

/** What happens to a term that ends on a day nobody works. */
export const TERM_ROLLS = Object.freeze(['none', 'next', 'previous'])

/** The `section` value that addresses the working calendars. */
export const CALENDAR_SECTION = 'calendars'

/**
 * A term written as `<value>:<unit>[:<roll>]`, read; null when it is not one.
 *
 * @spec openspec/changes/term-engine-diagnostic/specs/flow-business-timers/spec.md#requirement-the-diagnostic-is-reachable-from-the-calendar-admin-page-and-by-deep-link
 * @param {string|null|undefined} text The written term, e.g. `42:calendarDays:next`.
 * @return {{value: number, unit: string, rollToWorkingDay: string}|null} The term.
 */
export function parseTerm(text) {
	const parts = String(text ?? '').split(':')
	if (parts.length < 2 || parts.length > 3) {
		return null
	}
	const value = Number(parts[0])
	const unit = parts[1]
	const roll = parts[2] ?? 'none'
	if (parts[0].trim() === '' || !Number.isFinite(value) || value <= 0) {
		return null
	}
	if (!TERM_UNITS.includes(unit) || !TERM_ROLLS.includes(roll)) {
		return null
	}
	return { value, unit, rollToWorkingDay: roll }
}

/**
 * The calendar and term a page address asks for; null unless it addresses the calendars with a calendar slug.
 *
 * @spec openspec/changes/term-engine-diagnostic/specs/flow-business-timers/spec.md#requirement-the-diagnostic-is-reachable-from-the-calendar-admin-page-and-by-deep-link
 * @param {string} search The page's query string, e.g. `window.location.search`.
 * @return {{calendar: string, sla: object|null}|null} The request; `sla` is null when absent or unreadable.
 */
export function readDeepLink(search) {
	const params = new URLSearchParams(search ?? '')
	const calendar = (params.get('calendar') ?? '').trim()
	if (params.get('section') !== CALENDAR_SECTION || calendar === '') {
		return null
	}
	return { calendar, sla: parseTerm(params.get('sla')) }
}

/**
 * The query string that opens a calendar's panel with a term filled in.
 *
 * @spec openspec/changes/term-engine-diagnostic/specs/flow-business-timers/spec.md#requirement-the-diagnostic-is-reachable-from-the-calendar-admin-page-and-by-deep-link
 * @param {string} calendar The calendar slug.
 * @param {{value: number, unit: string, rollToWorkingDay?: string}} [sla] The term.
 * @return {string} The query string, starting with `?`.
 */
export function deepLinkQuery(calendar, sla) {
	const params = new URLSearchParams({ section: CALENDAR_SECTION, calendar })
	if (sla) {
		params.set(
			'sla',
			[sla.value, sla.unit, sla.rollToWorkingDay ?? 'none'].join(':'),
		)
	}
	return '?' + params.toString()
}

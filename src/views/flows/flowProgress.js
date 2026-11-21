/**
 * Helpers for the workflow progress page: durations in hours and days, the
 * run summary, and the task inbox route behind a step's counts.
 *
 * @spec openspec/changes/tasks-progress-report/specs/flow-progress-report/spec.md#requirement-a-person-sees-the-report-on-the-flow
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
import { getCanonicalLocale } from '@nextcloud/l10n'
import { formatDuration, statusLabel, statusVariant } from './flowDetail.js'

/**
 * The task inbox route of the SPA, where a step's counts lead.
 */
export const INBOX_PATH = '/flow-tasks'

/**
 * A duration in the largest unit that fits: seconds, minutes, hours or days.
 * The browser localises the unit, so no catalogue key is needed per language.
 *
 * @param {number|null} seconds The duration in seconds.
 * @return {string} The duration, or '' when there is none.
 */
export function longDuration(seconds) {
	if (typeof seconds !== 'number' || Number.isNaN(seconds)) {
		return ''
	}
	const steps = [
		['day', 86400],
		['hour', 3600],
		['minute', 60],
	]
	for (const [unit, size] of steps) {
		if (seconds >= size) {
			return unitFormat(unit).format(seconds / size)
		}
	}
	return unitFormat('second').format(seconds)
}

/**
 * A step's average duration: a person's time on a task in hours and days,
 * an automatic step's run time in milliseconds to minutes.
 *
 * @param {object} step A step row of the report.
 * @return {string} The duration, or '' when the step has none.
 */
export function stepDuration(step) {
	if (step.kind === 'human') {
		return longDuration(step.averageTaskSeconds)
	}
	return formatDuration(step.averageDurationMs)
}

/**
 * The runs by status, in a stable order, labelled the way the run pages label them.
 *
 * @param {object} byStatus Run count keyed by status.
 * @return {Array<{status: string, label: string, variant: string, count: number}>} The rows.
 */
export function runStatusRows(byStatus) {
	return Object.entries(byStatus || {})
		.sort(([a], [b]) => a.localeCompare(b))
		.map(([status, count]) => ({
			status,
			label: statusLabel(status),
			variant: statusVariant(status),
			count: Number(count) || 0,
		}))
}

/**
 * The inbox route for one of the report's task links: the API path becomes
 * the inbox page, the query (flow, node, overdue, isTerminal) is kept.
 *
 * @param {string} href A `tasksHref` of the report, e.g. `/api/flow-tasks?scope=all&flow=…`.
 * @return {{path: string, query: object}} The router location.
 */
export function inboxRoute(href) {
	const query = {}
	const search = String(href || '').split('?')[1] || ''
	for (const [key, value] of new URLSearchParams(search)) {
		query[key] = value
	}
	return { path: INBOX_PATH, query }
}

/**
 * A short unit formatter with at most one fraction digit.
 *
 * @param {string} unit An Intl unit identifier.
 * @return {Intl.NumberFormat} The formatter.
 */
function unitFormat(unit) {
	return new Intl.NumberFormat(getCanonicalLocale(), {
		style: 'unit',
		unit,
		unitDisplay: 'short',
		maximumFractionDigits: 1,
	})
}

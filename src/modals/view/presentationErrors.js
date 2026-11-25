/**
 * The presentation refusal of a view save, keyed by the picker path it names.
 *
 * A view save refuses an unrenderable presentation (REQ-VIEW-PRES-01) with a
 * message that names its path — `kanban.groupByField`, `calendar.dateField`
 * or `calendar.endDateField`. The picker shows the message under that field.
 * Shared by the view editor and the search sidebar's save form, so both put a
 * refusal under the same field.
 *
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */

/**
 * The paths a presentation refusal can name.
 *
 * @type {string[]}
 */
const PRESENTATION_PATHS = ['kanban.groupByField', 'calendar.dateField', 'calendar.endDateField']

/**
 * A map of picker path to message, empty when the message names none.
 *
 * @param {string} message The server's refusal message.
 * @return {object} The errors by picker path.
 * @spec openspec/changes/saved-view-presentation-picker/specs/saved-search-views/spec.md#requirement-the-view-editor-sets-a-views-presentation-req-view-pres-06
 */
export function presentationErrorsFrom(message) {
	const errors = {}
	for (const path of PRESENTATION_PATHS) {
		if (typeof message === 'string' && message.includes(path)) {
			errors[path] = message
		}
	}
	return errors
}

/**
 * The message of a failed view save: the server's error, the error's message, or null.
 *
 * @param {Error} error What went wrong.
 * @return {string|null} The message.
 * @spec openspec/changes/saved-view-presentation-picker/tasks.md#2.2
 */
export function saveFailureMessage(error) {
	return error?.body?.error || error?.response?.data?.error || error?.message || null
}

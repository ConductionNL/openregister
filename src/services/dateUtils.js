/**
 * Converts a backend date string to a local Date object for use with NcDateTimePickerNative.
 *
 * Handles ISO format ("YYYY-MM-DD") and MySQL space format ("YYYY-MM-DD HH:MM:SS").
 * For `date` format, creates a local-midnight Date to avoid timezone day-shift issues.
 *
 * @param {string} value - The date string from the backend
 * @param {string} format - Schema format: 'date', 'time', or 'date-time'
 * @return {Date|null}
 */
export function stringToDate(value, format) {
	if (!value) return null
	if (value instanceof Date) return value

	if (format === 'date') {
		const parts = value.split(/[T ]/)[0].split('-').map(Number)
		if (parts.length === 3 && parts.every(n => !isNaN(n))) {
			return new Date(parts[0], parts[1] - 1, parts[2])
		}
	} else if (format === 'time') {
		const parts = value.split(':').map(Number)
		if (parts.length >= 2 && parts.every(n => !isNaN(n))) {
			const d = new Date()
			d.setHours(parts[0], parts[1], parts[2] || 0, 0)
			return d
		}
	} else if (format === 'date-time') {
		const d = new Date(value.replace(' ', 'T'))
		if (!isNaN(d.getTime())) return d
	}

	return null
}

/**
 * Converts a Date object (as emitted by NcDateTimePickerNative) to a backend string.
 *
 * Uses local date/time getters to avoid UTC timezone drift.
 *
 * @param {Date} date
 * @param {string} format - Schema format: 'date', 'time', or 'date-time'
 * @return {string}
 */
export function dateToString(date, format) {
	const yyyy = date.getFullYear().toString().padStart(4, '0')
	const MM = (date.getMonth() + 1).toString().padStart(2, '0')
	const dd = date.getDate().toString().padStart(2, '0')
	const hh = date.getHours().toString().padStart(2, '0')
	const mm = date.getMinutes().toString().padStart(2, '0')
	const ss = date.getSeconds().toString().padStart(2, '0')

	if (format === 'date') return `${yyyy}-${MM}-${dd}`
	if (format === 'time') return `${hh}:${mm}:${ss}`
	if (format === 'date-time') return `${yyyy}-${MM}-${dd}T${hh}:${mm}:${ss}`
	return date.toISOString()
}

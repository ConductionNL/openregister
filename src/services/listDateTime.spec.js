/**
 * @spec exclude Unit test of a client-side display helper.
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
import { listDateTime } from './listDateTime.js'

/**
 * The expression the list views carried, options passed in the place of the
 * locale.
 *
 * @param {string} value The stored date
 * @return {string} The text
 */
function carried(value) {
	return (
		new Date(value).toLocaleDateString({
			day: '2-digit',
			month: '2-digit',
			year: 'numeric',
		})
		+ ', '
		+ new Date(value).toLocaleTimeString({
			hour: '2-digit',
			minute: '2-digit',
			second: '2-digit',
		})
	)
}

describe('listDateTime', () => {
	it.each(['2026-01-02T03:04:05Z', '2026-12-31T23:59:59+01:00', '2026-03-05'])(
		'shows %s as the list views did',
		(value) => {
			expect(listDateTime(value)).toBe(carried(value))
		},
	)

	it.each([null, undefined, ''])('shows a dash for %j', (value) => {
		expect(listDateTime(value)).toBe('-')
	})
})

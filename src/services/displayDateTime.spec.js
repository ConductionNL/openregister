/**
 * The shared list/detail date formatter returns what each view's own
 * `formatDate` copy returned.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec exclude Unit test of a client-side display helper.
 */
import { displayDateTime } from './displayDateTime.js'

describe('displayDateTime', () => {
	it.each([[null], [undefined], ['']])('shows a dash for %p', (value) => {
		expect(displayDateTime(value)).toBe('-')
	})

	it('formats a stored date with toLocaleString, as the views did', () => {
		const stored = '2026-03-14T09:26:53Z'
		expect(displayDateTime(stored)).toBe(new Date(stored).toLocaleString())
	})

	it('accepts a timestamp', () => {
		const stamp = Date.UTC(2026, 0, 2, 3, 4, 5)
		expect(displayDateTime(stamp)).toBe(new Date(stamp).toLocaleString())
	})
})

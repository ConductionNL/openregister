/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * A schema title inside a sentence.
 *
 * @spec exclude Unit test of a client-side text helper.
 */
import { sentenceName } from './sentenceName.js'

describe('sentenceName', () => {
	it.each([
		['Case', 'case'],
		['Complaint', 'complaint'],
		['Woo request', 'woo request'],
		['GDPR', 'GDPR'],
		['OpenProject task', 'OpenProject task'],
		['', ''],
		[null, ''],
	])('reads %p as %p', (title, expected) => {
		expect(sentenceName(title)).toBe(expected)
	})
})

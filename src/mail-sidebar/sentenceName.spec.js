/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * A schema title inside a sentence.
 *
 * @spec exclude Unit test of a client-side text helper.
 */
import { itemName, sentenceName } from './sentenceName.js'

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

describe('itemName', () => {
	it('uses the schema mailObjectName, else its title in sentence case', () => {
		expect(itemName({ title: 'Work queue', configuration: { mailObjectName: ' work queue item ' } })).toBe('work queue item')
		expect(itemName({ title: 'Case', configuration: { mailObjectName: '' } })).toBe('case')
		expect(itemName({ title: 'Case' })).toBe('case')
		expect(itemName(null)).toBe('')
	})
})

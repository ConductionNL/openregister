/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * What a row in the mail panel shows of an object.
 *
 * @spec exclude Unit test of client-side row helpers.
 */
import { objectIdentifier, objectSubline } from './objectRow.js'

describe('objectIdentifier', () => {
	it.each([
		[{ identifier: '2026-4833', number: 7 }, '2026-4833'],
		[{ number: 7 }, '7'],
		[{ reference: 'REF-1' }, 'REF-1'],
		[{ identifier: '', number: 3 }, '3'],
		[{ identifier: { nested: true } }, ''],
		[{}, ''],
		[null, ''],
	])('reads %j as %p', (obj, expected) => {
		expect(objectIdentifier(obj)).toBe(expected)
	})
})

describe('objectSubline', () => {
	it('prefers the object metadata description, then the field, else nothing', () => {
		expect(objectSubline({ '@self': { description: 'meta' }, description: 'field' })).toBe('meta')
		expect(objectSubline({ description: 'field' })).toBe('field')
		expect(objectSubline({ description: 42 })).toBe('')
		expect(objectSubline(null)).toBe('')
	})
})

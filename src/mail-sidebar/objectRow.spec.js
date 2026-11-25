/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * What a row in the mail panel shows of an object.
 *
 * @spec exclude Unit test of client-side row helpers.
 */
import { markParts, objectHandler, objectIdentifier, objectStatus, objectSubline } from './objectRow.js'

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

describe('objectStatus', () => {
	it('names the status by its label, never by a reference id', () => {
		expect(objectStatus({ status: { name: 'Received' }, statusPublicLabel: 'Ontvangen' })).toBe('Received')
		expect(objectStatus({ status: { title: 'Closed' } })).toBe('Closed')
		expect(objectStatus({ status: {}, statusLabel: 'in progress' })).toBe('in progress')
		expect(objectStatus({ statusLabel: 'in progress', status: 'x' })).toBe('in progress')
		expect(objectStatus({ statusPublicLabel: 'Received' })).toBe('Received')
		expect(objectStatus({ statusName: 'closed' })).toBe('closed')
		expect(objectStatus({ status: 'open' })).toBe('open')
		expect(objectStatus({ status: 'c19074a7-44e2-4aeb-88dc-ccd989e36cd7' })).toBe('')
		expect(objectStatus(null)).toBe('')
	})
})

describe('objectHandler', () => {
	it('reads the assignee, then the handler', () => {
		expect(objectHandler({ assignee: 'pieter', handler: 'anouk' })).toBe('pieter')
		expect(objectHandler({ handler: 'anouk' })).toBe('anouk')
		expect(objectHandler({ assignee: 3 })).toBe('')
		expect(objectHandler(null)).toBe('')
	})
})

describe('markParts', () => {
	it('splits around the first match of the typed words, ignoring case', () => {
		expect(markParts('Street lighting Lindelaan', 'lindelaan')).toEqual({ before: 'Street lighting ', match: 'Lindelaan', after: '' })
		expect(markParts('Tree felling permit', ' tree ')).toEqual({ before: '', match: 'Tree', after: ' felling permit' })
		expect(markParts('No match here', 'zebra')).toEqual({ before: 'No match here', match: '', after: '' })
		expect(markParts('Anything', '')).toEqual({ before: 'Anything', match: '', after: '' })
		expect(markParts(null, 'x')).toEqual({ before: '', match: '', after: '' })
	})
})

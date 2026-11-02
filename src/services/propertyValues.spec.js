/**
 * Type rules for one schema property in the record modal.
 */
import {
	convertInputValue,
	defaultValueFor,
	inputTypeFor,
	isValidDate,
	isValidPropertyValue,
} from './propertyValues.js'

describe('defaultValueFor', () => {
	it.each([
		[{ type: 'string' }, ''],
		[{ type: 'string', const: 'fixed' }, 'fixed'],
		[{ type: 'number' }, 0],
		[{ type: 'integer' }, 0],
		[{ type: 'boolean' }, false],
		[{ type: 'array' }, []],
		[{ type: 'object' }, {}],
		[{ type: 'file' }, ''],
		[{}, ''],
		[{ type: 'toString' }, ''],
	])('starts %j at %j', (property, expected) => {
		expect(defaultValueFor(property)).toEqual(expected)
	})

	it('gives every property its own array', () => {
		expect(defaultValueFor({ type: 'array' })).not.toBe(
			defaultValueFor({ type: 'array' }),
		)
	})

	it('throws for a missing property, like the inline switch did', () => {
		expect(() => defaultValueFor(undefined)).toThrow(TypeError)
	})
})

describe('isValidDate', () => {
	it.each([
		['2026-10-29T10:00:00Z', true],
		['2026-10-29', true],
		['not a date', false],
		['', false],
		[null, false],
		[0, false],
		[1700000000000, true],
	])('%j is %s', (value, expected) => {
		expect(isValidDate(value)).toBe(expected)
	})
})

describe('isValidPropertyValue', () => {
	it.each([
		[null, true],
		[undefined, true],
		['', true],
	])('accepts an empty %j when not required', (value, expected) => {
		expect(isValidPropertyValue(value, { type: 'string' }, false)).toBe(expected)
		expect(isValidPropertyValue(value, { type: 'string' }, undefined)).toBe(
			expected,
		)
	})

	it('rejects an empty value when required', () => {
		expect(isValidPropertyValue('', { type: 'number' }, true)).toBe(false)
	})

	it.each([
		['text', { type: 'string' }, true],
		[3, { type: 'string' }, false],
		['2026-10-29T10:00:00Z', { type: 'string', format: 'date-time' }, true],
		['yesterday', { type: 'string', format: 'date-time' }, false],
		['a', { type: 'string', const: 'a' }, true],
		['b', { type: 'string', const: 'a' }, false],
		['b', { type: 'string', format: 'date-time', const: 'a' }, false],
		[4, { type: 'number' }, true],
		[NaN, { type: 'number' }, false],
		['4', { type: 'number' }, false],
		['anything', { type: 'integer' }, true],
		[true, { type: 'boolean' }, true],
		['true', { type: 'boolean' }, false],
		[[], { type: 'array' }, true],
		[{}, { type: 'array' }, false],
		[{}, { type: 'object' }, true],
		[[], { type: 'object' }, false],
		['x', { type: 'object' }, false],
		['x', { type: 'unknown' }, true],
		['x', { type: 'constructor' }, true],
		['x', {}, true],
	])('%j against %j is %s', (value, property, expected) => {
		expect(isValidPropertyValue(value, property, false)).toBe(expected)
	})
})

describe('inputTypeFor', () => {
	it.each([
		[undefined, 'text'],
		[{ type: 'string' }, 'text'],
		[{ type: 'string', format: 'date' }, 'date'],
		[{ type: 'string', format: 'time' }, 'time'],
		[{ type: 'string', format: 'date-time' }, 'datetime-local'],
		[{ type: 'string', format: 'email' }, 'email'],
		[{ type: 'string', format: 'url' }, 'url'],
		[{ type: 'string', format: 'uri' }, 'url'],
		[{ type: 'string', format: 'password' }, 'password'],
		[{ type: 'string', format: 'uuid' }, 'text'],
		[{ type: 'number' }, 'number'],
		[{ type: 'integer' }, 'number'],
		[{ type: 'boolean' }, 'checkbox'],
		[{ type: 'array' }, 'text'],
		[{ type: 'toString' }, 'text'],
	])('%j gets %s', (property, expected) => {
		expect(inputTypeFor(property)).toBe(expected)
	})
})

describe('convertInputValue', () => {
	it.each([
		['4.5', undefined, '4.5'],
		['4.5', { type: 'number' }, 4.5],
		['', { type: 'number' }, null],
		['abc', { type: 'number' }, null],
		['4.5', { type: 'integer' }, 4],
		['', { type: 'integer' }, null],
		['abc', { type: 'integer' }, null],
		['x', { type: 'boolean' }, true],
		['', { type: 'boolean' }, false],
		['plain', { type: 'string' }, 'plain'],
		['plain', { type: 'unknown' }, 'plain'],
	])('%j as %j becomes %j', (input, property, expected) => {
		expect(convertInputValue(input, property)).toBe(expected)
	})

	it('formats a picked date for a formatted property', () => {
		const picked = new Date(2026, 9, 29, 12, 30)
		expect(convertInputValue(picked, { type: 'string', format: 'date' })).toBe(
			'2026-10-29',
		)
	})

	it('keeps a picked date when the property has no format', () => {
		const picked = new Date(2026, 9, 29)
		expect(convertInputValue(picked, { type: 'string' })).toBe(picked)
	})
})

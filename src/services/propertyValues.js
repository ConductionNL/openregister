/**
 * Type rules for a single schema property in the record modal: the value a new
 * field starts with, whether a value passes, the HTML input type, and how an
 * input value converts back to the property's type. Pure functions over the
 * schema property, so each rule is testable without mounting the modal.
 *
 * @spec exclude client-side helpers for the record modal's property table
 */

import { dateToString } from './dateUtils.js'

/**
 * Look a key up in a table of own properties only.
 *
 * @param {object} table The lookup table.
 * @param {string} key The key to look up.
 * @param {unknown} fallback The value when the table has no such key.
 * @return {unknown}
 */
function lookup(table, key, fallback) {
	return Object.hasOwn(table, key) ? table[key] : fallback
}

const DEFAULT_VALUES = {
	string: (property) => property.const || '',
	number: () => 0,
	integer: () => 0,
	boolean: () => false,
	array: () => [],
	object: () => ({}),
}

/**
 * The value a property starts with when the record does not have it yet.
 *
 * @param {object} property The schema property.
 * @return {unknown}
 * @spec exclude client-side helper for the record modal's property table
 */
export function defaultValueFor(property) {
	const make = lookup(DEFAULT_VALUES, property.type, () => '')
	return make(property)
}

/**
 * Whether a value parses as a date.
 *
 * @param {unknown} value The value to check.
 * @return {boolean}
 * @spec exclude client-side date validation helper
 */
export function isValidDate(value) {
	if (!value) return false
	const date = new Date(value)
	return date instanceof Date && !isNaN(date)
}

/**
 * Whether a value passes a string property's format and const.
 *
 * @param {unknown} value The value to check.
 * @param {object} property The schema property.
 * @return {boolean}
 */
function isValidString(value, property) {
	if (typeof value !== 'string') return false
	if (property.format === 'date-time') return isValidDate(value)
	return !property.const || value === property.const
}

const TYPE_CHECKS = {
	string: isValidString,
	number: (value) => typeof value === 'number' && !isNaN(value),
	boolean: (value) => typeof value === 'boolean',
	array: (value) => Array.isArray(value),
	object: (value) =>
		typeof value === 'object' && value !== null && !Array.isArray(value),
}

/**
 * Whether a value is valid for its schema property. An empty value is valid
 * unless the property is required; a type without a rule is always valid.
 *
 * @param {unknown} value The value to check.
 * @param {object} property The schema property.
 * @param {boolean} isRequired Whether the property is required.
 * @return {boolean}
 * @spec exclude client-side property-value validation helper
 */
export function isValidPropertyValue(value, property, isRequired) {
	if (value === null || value === undefined || value === '') {
		return !isRequired
	}
	const check = lookup(TYPE_CHECKS, property.type, () => true)
	return check(value, property)
}

const STRING_INPUT_TYPES = {
	date: 'date',
	time: 'time',
	'date-time': 'datetime-local',
	email: 'email',
	url: 'url',
	uri: 'url',
	password: 'password',
}

const INPUT_TYPES = {
	number: 'number',
	integer: 'number',
	boolean: 'checkbox',
}

/**
 * The HTML input type for a property; `text` when it has no schema property.
 *
 * @param {object|undefined} property The schema property.
 * @return {string}
 * @spec exclude display helper mapping schema type to input type
 */
export function inputTypeFor(property) {
	if (!property) return 'text'
	if (property.type === 'string') {
		return lookup(STRING_INPUT_TYPES, property.format, 'text')
	}
	return lookup(INPUT_TYPES, property.type, 'text')
}

/**
 * Parse a numeric input, giving null for an empty or unparsable one.
 *
 * @param {unknown} input The input value.
 * @param {function(string): number} parse The parser.
 * @return {number|null}
 */
function toNumber(input, parse) {
	if (input === '') return null
	const parsed = parse(input)
	return isNaN(parsed) ? null : parsed
}

const CONVERTERS = {
	number: (input) => toNumber(input, parseFloat),
	integer: (input) => toNumber(input, (raw) => parseInt(raw, 10)),
	boolean: (input) => Boolean(input),
}

/**
 * Convert an input value to the type its schema property declares. A date
 * picked for a formatted property becomes the formatted string.
 *
 * @param {unknown} input The input value.
 * @param {object|undefined} property The schema property.
 * @return {unknown}
 * @spec exclude form-state helper converting an input value to its property type
 */
export function convertInputValue(input, property) {
	if (!property) return input
	const convert = lookup(CONVERTERS, property.type, null)
	if (convert) return convert(input)
	if (input instanceof Date && property.format) {
		return dateToString(input, property.format)
	}
	return input
}

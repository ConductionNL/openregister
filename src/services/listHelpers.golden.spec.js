/**
 * Golden record of the list and record-form helpers: the editor a field
 * gets, whether a cell edits in place, schema header sorting and header
 * filters on loaded rows.
 *
 * @spec openspec/specs/admin-list-views/spec.md#requirement-openregister-s-schemas-and-registers-lists-must-filter-from-their-column-headers
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */

import { filterRows } from './listFilter.js'
import { sortSchemas } from './listSort.js'
import { editorFor, enumOptions, isInlineEditable } from './propertyEditor.js'

const PROPERTIES = [
	['nothing', undefined],
	['null', null],
	['empty', {}],
	['string', { type: 'string' }],
	['number', { type: 'number' }],
	['integer', { type: 'integer' }],
	['boolean', { type: 'boolean' }],
	['object', { type: 'object' }],
	['array', { type: 'array' }],
	['file', { type: 'file' }],
	['file with enum', { type: 'file', enum: ['a'] }],
	['date', { type: 'string', format: 'date' }],
	['time', { type: 'string', format: 'time' }],
	['date-time', { type: 'string', format: 'date-time' }],
	['email', { type: 'string', format: 'email' }],
	['number with date format', { type: 'number', format: 'date' }],
	['enum', { type: 'string', enum: ['a', 'b'] }],
	['empty enum', { type: 'string', enum: [] }],
	['boolean enum', { type: 'boolean', enum: [true] }],
	[
		'oneOf constants',
		{ type: 'integer', oneOf: [{ const: 1, title: 'One' }, { const: 2 }] },
	],
	['oneOf mixed', { type: 'string', oneOf: [{ const: 'a' }, { type: 'x' }] }],
	['date enum', { type: 'string', format: 'date', enum: ['2026-01-01'] }],
	['translatable', { type: 'string', translatable: true }],
	['translatable date', { type: 'string', format: 'date', translatable: true }],
	['translatable not true', { type: 'string', translatable: 'yes' }],
	['const', { type: 'string', const: 'x' }],
	['const undefined key', { type: 'string', const: undefined }],
	['const null', { type: 'string', const: null }],
	['readOnly', { type: 'string', readOnly: true }],
	['readOnly not true', { type: 'string', readOnly: 1 }],
	['immutable', { type: 'string', immutable: true }],
	['immutable not true', { type: 'number', immutable: 'true' }],
]

const LANGUAGE_SETS = [undefined, [], ['nl'], ['nl', 'en'], 'nl', null]

const CELL_VALUES = [undefined, null, '', 'x', 0, false, []]

const SCHEMAS = [
	{
		id: 3,
		title: 'beta',
		created: '2026-01-02T10:00:00Z',
		properties: { a: {}, b: {} },
	},
	{
		id: 10,
		title: 'Alpha',
		created: 'not a date',
		updated: '2026-03-01',
		properties: [],
	},
	{ id: '7', title: 'item 10', created: null, properties: null, version: 2 },
	{
		id: 1,
		title: 'item 9',
		created: '2025-12-31',
		updated: null,
		properties: 'x',
		version: '10',
	},
	{ id: 5, title: null, properties: { a: 1 }, version: undefined },
	{
		id: 2,
		title: 'alpha',
		updated: '2026-03-01T00:00:00Z',
		properties: {},
		version: 0,
	},
	null,
	{ title: 'no id', created: '', version: 1 },
	{ id: 11, title: 'epoch', created: '1970-01-01T00:00:00Z' },
	{ id: 12, title: 'zero', created: 0, updated: 0 },
]

const SORT_KEYS = [
	null,
	'',
	'created',
	'updated',
	'properties',
	'title',
	'version',
	'id',
	'missing',
	'constructor',
	'toString',
]

const ROWS = [
	{
		id: 1,
		title: 'Alpha one',
		count: 5,
		score: '12',
		created: '2026-01-02T10:00:00Z',
		tags: ['a', 'b'],
		owner: null,
	},
	{
		id: 2,
		title: 'beta',
		count: '7',
		score: 'x',
		created: '2026-01-02',
		tags: { a: 1 },
		owner: 'ann',
	},
	{
		id: 3,
		title: 'Gamma ALPHA',
		count: 0,
		score: ' ',
		created: 'yesterday',
		owner: '',
	},
	{
		id: 4,
		title: '',
		count: null,
		score: '3.5',
		created: '2026-01-03T00:00:00+02:00',
		owner: 'bob',
	},
	{ id: 5, count: '', score: 4, created: 20260102, owner: 0 },
	{
		id: 6,
		title: 10,
		count: true,
		score: '1e2',
		created: '2025-12-31T23:59:59Z',
		owner: false,
	},
	null,
]

const FILTERS = [
	['none', undefined],
	['empty map', {}],
	['empty list', { title: [] }],
	['like', { 'title[like]': ['alpha'] }],
	['like uses the first value', { 'title[like]': ['zzz', 'alpha'] }],
	['like on a list value', { 'tags[like]': ['"a"'] }],
	['like on a number', { 'title[like]': [1] }],
	['exact', { owner: ['ann'] }],
	['exact any of', { owner: ['ann', 'bob'] }],
	['exact empty', { owner: [''] }],
	['exact not a list', { owner: 'bob' }],
	['exact number', { count: [7] }],
	['exact false', { owner: ['false'] }],
	['exact object', { tags: ['{"a":1}'] }],
	['eq operator', { 'owner[eq]': ['ann'] }],
	['unknown operator', { 'owner[foo]': ['ann'] }],
	['gte number', { 'count[gte]': ['5'] }],
	['lte number', { 'count[lte]': ['5'] }],
	['gte numeric text', { 'score[gte]': ['4'] }],
	['lte numeric text', { 'score[lte]': ['4'] }],
	['gte text bound', { 'score[gte]': ['m'] }],
	['gte date-only bound', { 'created[gte]': ['2026-01-02'] }],
	['lte date-only bound', { 'created[lte]': ['2026-01-02'] }],
	['gte date-time bound', { 'created[gte]': ['2026-01-02T05:00:00Z'] }],
	['lte date-time bound', { 'created[lte]': ['2026-01-02T12:00:00Z'] }],
	['gte bad bound', { 'created[gte]': ['soon'] }],
	['lte bad bound', { 'created[lte]': ['soon'] }],
	['lte text bound', { 'score[lte]': ['m'] }],
	['gte negative bound', { 'score[gte]': ['-5'] }],
	['lte negative bound', { 'score[lte]': ['-5'] }],
	['range', { 'count[gte]': ['1'], 'count[lte]': ['7'] }],
	['range and like', { 'count[gte]': ['0'], 'title[like]': ['a'] }],
	['missing field', { 'nothing[gte]': ['1'] }],
	['bracket only', { '[like]': ['a'] }],
]

const ids = (rows) => rows.map((row) => (row ? row.id : row))

describe('list helpers golden', () => {
	it('picks an editor and inline editing per property', () => {
		expect(
			PROPERTIES.map(([name, property]) => [
				name,
				LANGUAGE_SETS.map((languages) => editorFor(property, languages)),
				editorFor(property),
				enumOptions(property),
				CELL_VALUES.map((value) => isInlineEditable(property, value)),
				isInlineEditable(property),
			]),
		).toMatchSnapshot()
	})

	it('sorts schemas by header', () => {
		expect(
			SORT_KEYS.map((key) => [
				key,
				ids(sortSchemas(SCHEMAS, key)),
				ids(sortSchemas(SCHEMAS, key, 'desc')),
				ids(sortSchemas(SCHEMAS, key, 'other')),
			]),
		).toMatchSnapshot()
		expect([
			sortSchemas(null, 'title'),
			sortSchemas(undefined),
		]).toMatchSnapshot()
	})

	it('filters rows by header', () => {
		const custom = (row, field) => (row ? row[field] : 'alpha')
		expect(
			FILTERS.map(([name, filters]) => [
				name,
				ids(filterRows(ROWS, filters)),
				ids(filterRows(ROWS, filters, custom)),
			]),
		).toMatchSnapshot()
		expect(filterRows(ROWS, {})).not.toBe(ROWS)
		expect([
			filterRows(null, { title: ['a'] }),
			filterRows(undefined, {}),
		]).toMatchSnapshot()
	})
})

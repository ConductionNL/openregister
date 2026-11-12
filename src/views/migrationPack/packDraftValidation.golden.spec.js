/**
 * Golden record of the migration-pack draft validation: the messages, in
 * order, that validateTransform and validateDraft give over a matrix of
 * transform and draft variants.
 */
import { validateDraft, validateTransform } from './packDraft.js'

const OK = { text: '"x"', json: true }
const BAD = { text: '{nope', json: true }
const YES = { text: 'true', json: true }
const ONE = { text: '1', json: true }
const PLAIN = { text: 'plain', json: false }

const MAP_ROWS = {
	none: [],
	one: [{ key: 'a', ...YES }],
	twice: [
		{ key: 'a', ...YES },
		{ key: 'a', ...ONE },
		{ key: 'b', ...YES },
	],
	badJson: [
		{ key: 'a', ...BAD },
		{ key: 'b', ...PLAIN },
	],
	notBoolean: [
		{ key: 'a', ...OK },
		{ key: 'b', ...PLAIN },
		{ key: 'b', ...BAD },
	],
}

const DEFAULTS = {
	none: { defaultMode: 'none', defaultCell: BAD },
	ok: { defaultMode: 'value', defaultCell: OK },
	yes: { defaultMode: 'value', defaultCell: YES },
	bad: { defaultMode: 'value', defaultCell: BAD },
	plain: { defaultMode: 'value', defaultCell: PLAIN },
}

const FIELDS = { none: [], blank: ['a', ''], ok: ['a', 'b'] }
const VALUES = { ok: OK, bad: BAD, plain: PLAIN }
const TYPES = ['lookup', 'bool-map', 'concat', 'const', 'other']

/**
 * Every transform variant, named.
 *
 * @return {Array<[string, object]>} The variants.
 */
function transforms() {
	const out = []
	for (const type of TYPES) {
		for (const [rowsName, mapRows] of Object.entries(MAP_ROWS)) {
			for (const [defaultName, def] of Object.entries(DEFAULTS)) {
				for (const [fieldsName, fields] of Object.entries(FIELDS)) {
					for (const [valueName, valueCell] of Object.entries(VALUES)) {
						out.push([
							`${type}/${rowsName}/${defaultName}/${fieldsName}/${valueName}`,
							{ type, mapRows, ...def, fields, valueCell },
						])
					}
				}
			}
		}
	}
	return out
}

const BASE_DRAFT = {
	slug: 'my-pack',
	name: 'My pack',
	version: '1.0.0',
	sourceFormat: 'csv',
	idStrategyType: 'generated',
	idStrategyField: '',
	rows: [{ source: 'a', target: 'b', transform: null }],
	defaults: [],
	skipRows: '',
}

const DRAFT_CHANGES = {
	valid: {},
	badSlug: { slug: 'My Pack' },
	blankName: { name: '   ' },
	badVersion: { version: '1.0' },
	badFormat: { sourceFormat: 'xml' },
	idFieldMissing: { idStrategyType: 'sourceField', idStrategyField: '' },
	idFieldGiven: { idStrategyType: 'sourceField', idStrategyField: 'id' },
	noRows: { rows: [] },
	rowGaps: {
		rows: [
			{ source: '', target: 'b', transform: null },
			{ source: 'a', target: '', transform: null },
			{ source: '', target: '', transform: { type: 'concat', fields: [] } },
			{
				source: 'c',
				target: 'd',
				transform: {
					type: 'bool-map',
					mapRows: MAP_ROWS.notBoolean,
					...DEFAULTS.plain,
				},
			},
		],
	},
	defaults: {
		defaults: [
			{ key: '', ...OK },
			{ key: 'x', ...OK },
			{ key: 'x', ...BAD },
			{ key: '', ...BAD },
			{ key: 'y', ...PLAIN },
		],
	},
	skips: { skipRows: '1, 0; x 3 -2' },
	skipsOk: { skipRows: '1 2,3' },
	everything: {
		slug: '-x',
		name: '',
		version: 'v1',
		sourceFormat: '',
		idStrategyType: 'sourceField',
		rows: [],
		defaults: [{ key: '', ...BAD }],
		skipRows: 'zero',
	},
}

describe('pack draft validation (golden)', () => {
	it('validateTransform matches the recorded messages', () => {
		const records = {}
		for (const [name, draft] of transforms()) {
			records[name] = validateTransform(draft)
		}
		expect(records).toMatchSnapshot()
	})

	it('validateDraft matches the recorded messages', () => {
		const records = {}
		for (const [name, change] of Object.entries(DRAFT_CHANGES)) {
			records[name] = validateDraft({ ...BASE_DRAFT, ...change })
		}
		expect(records).toMatchSnapshot()
	})
})

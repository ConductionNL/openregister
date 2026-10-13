/**
 * The migration-pack draft: the stored definition to an editable draft and back.
 *
 * The round trip is the contract. A pack saved without an edit must come back
 * exactly as stored, including keys the editor does not model, a lookup's
 * `default: null`, rows that share a source or a target, and the row order.
 *
 * @spec openspec/changes/migration-pack-editor-ui/specs/migration-mapping-packs/spec.md#requirement-the-pack-editor-must-save-a-definition-without-losing-what-it-does-not-show
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */

import fs from 'fs'
import path from 'path'
import {
	cellFromValue,
	draftFromStored,
	duplicateTargets,
	isBooleanCell,
	moveRow,
	newRow,
	newTransform,
	nextVersion,
	parseSkipRows,
	payloadFromDraft,
	sameDefinition,
	sampleColumns,
	sampleRowFrom,
	stableStringify,
	transformFromStored,
	transformPayload,
	transformSummary,
	unknownTransformKeys,
	validateDraft,
	validateTransform,
	valueFromCell,
	withTransformType,
} from './packDraft.js'

const FIXTURES = path.resolve(
	__dirname,
	'..',
	'..',
	'..',
	'tests',
	'fixtures',
	'migration-packs',
)

/**
 * A fixture pack, parsed.
 *
 * @param {...string} parts Path below the fixtures directory.
 * @return {object} The definition.
 */
function fixture(...parts) {
	return JSON.parse(fs.readFileSync(path.join(FIXTURES, ...parts), 'utf8'))
}

const STACKIQ_PACKS = [
	'topdesk-module.json',
	'topdesk-manufacturer.json',
	'topdesk-municipality.json',
	'topdesk-usage.json',
	'topdesk-business-owner.json',
]

/**
 * The transform draft of the first row writing a target.
 *
 * @param {object} draft The pack draft.
 * @param {string} target The target field.
 * @return {object} The transform draft.
 */
function transformFor(draft, target) {
	return draft.rows.find((row) => row.target === target).transform
}

describe('saving a pack without an edit', () => {
	it.each(STACKIQ_PACKS)('gives the stackiq pack %s back unchanged', (file) => {
		const stored = fixture('stackiq', file)
		const payload = payloadFromDraft(draftFromStored(stored))
		expect(payload).toEqual(stored)
		expect(stableStringify(payload)).toBe(stableStringify(stored))
	})

	it.each(STACKIQ_PACKS)('keeps the key order of %s', (file) => {
		const stored = fixture('stackiq', file)
		const payload = payloadFromDraft(draftFromStored(stored))
		expect(JSON.stringify(payload)).toBe(JSON.stringify(stored))
	})

	it("gives OpenRegister's own template pack back with its empty [] defaults and skipRows", () => {
		const stored = fixture('openregister', 'zgw-zaken-json.json')
		const payload = payloadFromDraft(draftFromStored(stored))
		expect(payload).toEqual(stored)
		expect(payload.defaults).toEqual([])
		expect(payload.skipRows).toEqual([])
	})

	it('keeps top-level, mapping and transform keys the editor does not know', () => {
		const stored = {
			id: 'x',
			name: 'X',
			sourceFormat: 'csv',
			version: '1.0.0',
			'x-stackiq': { shippedVersion: '2.0.0', shippedHash: 'abc' },
			fieldMappings: [
				{
					source: 'A',
					target: 'a',
					note: 'kept',
					transform: { type: 'trim', futureOption: true },
				},
			],
			idStrategy: { type: 'generate', seed: 4 },
		}
		const draft = draftFromStored(stored)
		expect(payloadFromDraft(draft)).toEqual(stored)
		expect(unknownTransformKeys(draft.rows[0].transform)).toEqual([
			'futureOption',
		])
	})

	it('keeps a transform of a type the editor does not know as stored', () => {
		const stored = {
			id: 'x',
			name: 'X',
			sourceFormat: 'csv',
			version: '1.0.0',
			fieldMappings: [
				{
					source: 'A',
					target: 'a',
					transform: { type: 'reverse', depth: 2 },
				},
			],
			idStrategy: { type: 'generate' },
		}
		expect(payloadFromDraft(draftFromStored(stored))).toEqual(stored)
	})
})

describe('the three ways a lookup treats a value that is not in its table', () => {
	const base = (transform) => ({
		id: 'x',
		name: 'X',
		sourceFormat: 'csv',
		version: '1.0.0',
		fieldMappings: [{ source: 'A', target: 'a', transform }],
		idStrategy: { type: 'generate' },
	})

	it('reads no default key as "report an error" and writes no default key', () => {
		const draft = draftFromStored(base({ type: 'lookup', map: { J: 'ja' } }))
		expect(draft.rows[0].transform.defaultMode).toBe('none')
		expect(
			payloadFromDraft(draft).fieldMappings[0].transform,
		).not.toHaveProperty('default')
	})

	it('reads default: null as "leave empty" and writes null back, never an empty string', () => {
		const draft = draftFromStored(
			base({ type: 'lookup', map: { J: 'ja' }, default: null }),
		)
		expect(draft.rows[0].transform.defaultMode).toBe('empty')
		const transform = payloadFromDraft(draft).fieldMappings[0].transform
		expect(transform).toHaveProperty('default')
		expect(transform.default).toBeNull()
	})

	it('reads a default value and keeps its type', () => {
		const draft = draftFromStored(
			base({ type: 'lookup', map: { J: 'ja' }, default: ['other'] }),
		)
		expect(draft.rows[0].transform.defaultMode).toBe('value')
		expect(payloadFromDraft(draft).fieldMappings[0].transform.default).toEqual([
			'other',
		])
	})

	it('switches between the three on edit', () => {
		const draft = draftFromStored(base({ type: 'lookup', map: { J: 'ja' } }))
		const transform = draft.rows[0].transform
		expect(
			transformPayload({ ...transform, defaultMode: 'empty' }).default,
		).toBeNull()
		expect(
			transformPayload({
				...transform,
				defaultMode: 'value',
				defaultCell: { text: 'nee', json: false },
			}).default,
		).toBe('nee')
		expect(
			transformPayload({ ...transform, defaultMode: 'none' }),
		).not.toHaveProperty('default')
	})

	it('reads the module pack: Applicatiesoort leaves the hosting model empty, BNN errors', () => {
		const draft = draftFromStored(fixture('stackiq', 'topdesk-module.json'))
		expect(transformFor(draft, 'cloudDienstverleningsmodel').defaultMode).toBe(
			'empty',
		)
		expect(transformFor(draft, 'bbnLevel').defaultMode).toBe('none')
	})
})

describe('rows that share a source or a target', () => {
	it('keeps both rows of a column that feeds two targets', () => {
		const stored = fixture('stackiq', 'topdesk-module.json')
		const draft = draftFromStored(stored)
		const fromSoort = draft.rows.filter(
			(row) => row.source === 'Applicatiesoort',
		)
		expect(fromSoort.map((row) => row.target)).toEqual([
			'applicationType',
			'cloudDienstverleningsmodel',
		])
		const saved = payloadFromDraft(draft).fieldMappings.filter(
			(m) => m.source === 'Applicatiesoort',
		)
		expect(saved).toHaveLength(2)
	})

	it('keeps both rows of a target two columns write, in order, and names them', () => {
		const draft = draftFromStored(fixture('stackiq', 'topdesk-module.json'))
		const shared = duplicateTargets(draft.rows)
		expect(shared.get('shortDescription')).toEqual([4, 5])
		expect(shared.has('name')).toBe(false)
		const saved = payloadFromDraft(draft).fieldMappings.filter(
			(m) => m.target === 'shortDescription',
		)
		expect(saved.map((m) => m.source)).toEqual(['Nickname', 'Roepnaam'])
	})

	it('saves a reordered pack in the new order', () => {
		const draft = draftFromStored(fixture('stackiq', 'topdesk-module.json'))
		draft.rows = moveRow(draft.rows, 4, -1)
		const sources = payloadFromDraft(draft).fieldMappings.map((m) => m.source)
		expect(sources.slice(3, 5)).toEqual(['Roepnaam', 'Nickname'])
	})

	it('leaves the order alone for a move out of range', () => {
		const rows = [{ uid: 'a' }, { uid: 'b' }]
		expect(moveRow(rows, 0, -1).map((r) => r.uid)).toEqual(['a', 'b'])
		expect(moveRow(rows, 1, 1).map((r) => r.uid)).toEqual(['a', 'b'])
		expect(moveRow(rows, 1, -1).map((r) => r.uid)).toEqual(['b', 'a'])
	})
})

describe('editing a pack', () => {
	it('adds, removes and changes rows, and drops settings of a transform type it left', () => {
		const draft = draftFromStored(
			fixture('stackiq', 'topdesk-business-owner.json'),
		)
		const row = newRow()
		row.source = 'Telefoon'
		row.target = 'phone'
		row.transform = newTransform('trim')
		draft.rows = [...draft.rows, row]
		draft.rows[0].transform = withTransformType(
			draft.rows[0].transform,
			'lookup',
		)
		draft.rows[0].transform.mapRows = [
			{ uid: 'k', key: 'Jan', text: 'Jan Jansen', json: false },
		]
		const payload = payloadFromDraft(draft)
		expect(payload.fieldMappings[2]).toEqual({
			source: 'Telefoon',
			target: 'phone',
			transform: { type: 'trim' },
		})
		expect(payload.fieldMappings[0].transform).toEqual({
			type: 'lookup',
			map: { Jan: 'Jan Jansen' },
		})
	})

	it('writes required only when it was stored or is switched on', () => {
		const draft = draftFromStored(
			fixture('stackiq', 'topdesk-business-owner.json'),
		)
		draft.rows[1].required = true
		draft.rows[0].required = false
		const [first, second] = payloadFromDraft(draft).fieldMappings
		expect(first.required).toBe(false)
		expect(second.required).toBe(true)
	})

	it('removes a transform', () => {
		const draft = draftFromStored(
			fixture('stackiq', 'topdesk-business-owner.json'),
		)
		draft.rows[1].transform = null
		expect(payloadFromDraft(draft).fieldMappings[1]).not.toHaveProperty(
			'transform',
		)
	})

	it('edits defaults and rows to skip', () => {
		const draft = draftFromStored(
			fixture('stackiq', 'topdesk-manufacturer.json'),
		)
		expect(draft.defaults.map((row) => row.key)).toEqual([
			'type',
			'status',
			'registeredBy',
		])
		draft.defaults = draft.defaults.filter((row) => row.key !== 'registeredBy')
		draft.skipRows = '2, 7'
		const payload = payloadFromDraft(draft)
		expect(payload.defaults).toEqual({ type: 'Supplier', status: 'Active' })
		expect(payload.skipRows).toEqual([2, 7])
	})

	it('writes the date formats exactly, keeping the leading !', () => {
		const draft = draftFromStored(fixture('stackiq', 'topdesk-usage.json'))
		const date = transformFor(draft, 'startDateOutPhased')
		expect(date.sourceFormat).toBe('!Y-m-d')
		expect(transformPayload({ ...date, sourceFormat: '!d-m-Y' })).toEqual({
			type: 'date',
			sourceFormat: '!d-m-Y',
			targetFormat: 'Y-m-d',
		})
	})

	it('writes a join with its columns and separator', () => {
		const draft = draftFromStored(fixture('stackiq', 'topdesk-usage.json'))
		const join = transformFor(draft, 'interneAnnotation')
		expect(join.fields).toEqual(['Cluster', 'Applicatie Eigenaar (Afdeling)'])
		expect(join.separator).toBe(' / ')
		expect(transformPayload({ ...join, fields: ['Cluster'] }).fields).toEqual([
			'Cluster',
		])
		expect(
			transformPayload({ ...newTransform('concat'), fields: ['B'] }),
		).toEqual({
			type: 'concat',
			fields: ['B'],
		})
	})

	it('writes a fixed value and a yes/no table with their JSON types', () => {
		const fixed = {
			...newTransform('const'),
			valueCell: { text: '42', json: true },
		}
		expect(transformPayload(fixed)).toEqual({ type: 'const', value: 42 })
		const yesNo = newTransform('bool-map')
		yesNo.mapRows = [
			{ uid: 'a', key: 'J', text: 'true', json: true },
			{ uid: 'b', key: 'N', text: 'false', json: true },
		]
		expect(transformPayload(yesNo)).toEqual({
			type: 'bool-map',
			map: { J: true, N: false },
		})
	})

	it('keeps lookup values that are lists as lists', () => {
		const draft = draftFromStored(fixture('stackiq', 'topdesk-module.json'))
		const lookup = transformFor(draft, 'cloudDienstverleningsmodel')
		const saas = lookup.mapRows.find((row) => row.key === 'SaaS')
		expect(saas).toMatchObject({ text: '["SaaS"]', json: true })
		expect(transformPayload(lookup).map.SaaS).toEqual(['SaaS'])
	})
})

describe('value cells', () => {
	it('edit a string as text and anything else as JSON', () => {
		expect(cellFromValue('a')).toEqual({ text: 'a', json: false })
		expect(cellFromValue(['a'])).toEqual({ text: '["a"]', json: true })
		expect(cellFromValue(null)).toEqual({ text: 'null', json: true })
		expect(valueFromCell({ text: '[1]', json: true })).toEqual([1])
		expect(valueFromCell({ text: '[1]', json: false })).toBe('[1]')
		expect(() => valueFromCell({ text: '[1', json: true })).toThrow(SyntaxError)
	})
})

describe('checking a draft before it is saved', () => {
	it('passes every shipped pack', () => {
		for (const file of STACKIQ_PACKS) {
			expect(validateDraft(draftFromStored(fixture('stackiq', file)))).toEqual(
				[],
			)
		}
		expect(
			validateDraft(
				draftFromStored(fixture('openregister', 'zgw-zaken-json.json')),
			),
		).toEqual([])
	})

	it('names what the server would refuse', () => {
		const draft = draftFromStored({})
		draft.idStrategyType = 'sourceField'
		draft.skipRows = '0, x, 3'
		const messages = validateDraft(draft).join('\n')
		expect(messages).toMatch(/slug/)
		expect(messages).toMatch(/name is required/)
		expect(messages).toMatch(/version/)
		expect(messages).toMatch(/source format/)
		expect(messages).toMatch(/column that holds the id/)
		expect(messages).toMatch(/at least one field mapping/)
		expect(messages).toMatch(/0, x/)
	})

	it('names a row without a source or a target and a broken transform', () => {
		const draft = draftFromStored(
			fixture('stackiq', 'topdesk-business-owner.json'),
		)
		draft.rows[1].source = ''
		draft.rows[0].transform = { ...newTransform('lookup') }
		const messages = validateDraft(draft)
		expect(messages).toContain('Row 2 has no source column.')
		expect(messages.some((m) => m.startsWith('Row 1:'))).toBe(true)
	})

	it('refuses a table with a value twice, broken JSON, or an empty join', () => {
		const lookup = newTransform('lookup')
		lookup.mapRows = [
			{ uid: 'a', key: 'J', text: 'x', json: false },
			{ uid: 'b', key: 'J', text: '[', json: true },
		]
		const messages = validateTransform(lookup)
		expect(messages.some((m) => m.includes('twice'))).toBe(true)
		expect(messages.some((m) => m.includes('not valid JSON'))).toBe(true)
		expect(validateTransform(newTransform('concat'))).toHaveLength(1)
		expect(validateTransform(newTransform('trim'))).toEqual([])
	})

	it('lets a stored yes/no table with 1 and 0 be saved unchanged', () => {
		const stored = { type: 'bool-map', map: { Ja: 1, Nee: 0 }, default: 0 }
		const table = transformFromStored(stored)
		expect(validateTransform(table)).toEqual([])
		expect(transformPayload(table)).toEqual(stored)
		expect(
			validateDraft(
				draftFromStored({
					id: 'yes-no',
					name: 'Yes/no',
					sourceFormat: 'csv',
					version: '1.0.0',
					fieldMappings: [
						{ source: 'Actief', target: 'active', transform: stored },
					],
				}),
			),
		).toEqual([])
	})

	it('accepts only yes or no in a yes/no table, its default included', () => {
		const table = newTransform('bool-map')
		table.mapRows = [
			{ uid: 'a', key: 'Ja', text: 'true', json: true },
			{ uid: 'b', key: 'Nee', text: 'false', json: false },
		]
		table.defaultMode = 'value'
		table.defaultCell = { text: 'false', json: false }
		const messages = validateTransform(table)
		// The text "false" is cast to true by the engine.
		expect(messages).toEqual([
			'Choose yes or no as the result for "Nee".',
			'Choose yes or no for values that are not in the table.',
		])
		table.mapRows[1] = { uid: 'b', key: 'Nee', text: 'false', json: true }
		table.defaultCell = { text: 'false', json: true }
		expect(validateTransform(table)).toEqual([])
		expect(transformPayload(table)).toEqual({
			type: 'bool-map',
			map: { Ja: true, Nee: false },
			default: false,
		})
		expect(isBooleanCell({ text: 'nee', json: false })).toBe(false)
		expect(isBooleanCell({ text: '[', json: true })).toBe(false)
		expect(isBooleanCell({ text: '2', json: true })).toBe(false)
		expect(isBooleanCell({ text: '"1"', json: true })).toBe(false)
		// A lookup stores any value, text included.
		expect(
			validateTransform({
				...table,
				type: 'lookup',
				defaultCell: { text: 'x', json: false },
			}),
		).toEqual([])
	})

	it('shows quotes and ampersands in a message as typed, not as HTML entities', () => {
		const draft = draftFromStored(
			fixture('stackiq', 'topdesk-business-owner.json'),
		)
		const lookup = newTransform('lookup')
		lookup.mapRows = [
			{ uid: 'a', key: 'R&D "x"', text: 'a', json: false },
			{ uid: 'b', key: 'R&D "x"', text: 'b', json: false },
		]
		draft.rows[0].transform = lookup
		expect(validateDraft(draft)).toContain(
			'Row 1: The value "R&D "x"" is in the table twice.',
		)
		expect(
			transformSummary({
				...newTransform('const'),
				valueCell: { text: '["SaaS"]', json: true },
			}),
		).toBe('Fixed value ["SaaS"]')
	})

	it('refuses a default set twice', () => {
		const draft = draftFromStored(
			fixture('stackiq', 'topdesk-manufacturer.json'),
		)
		draft.defaults = [
			...draft.defaults,
			{ uid: 'z', key: 'type', text: 'Other', json: false },
		]
		expect(validateDraft(draft).some((m) => m.includes('"type"'))).toBe(true)
	})
})

describe('helpers', () => {
	it('raises the patch version', () => {
		expect(nextVersion('2.0.0')).toBe('2.0.1')
		expect(nextVersion('1.9.9')).toBe('1.9.10')
		expect(nextVersion('')).toBe('1.0.0')
		expect(nextVersion('v1')).toBe('v1')
	})

	it('reads rows to skip', () => {
		expect(parseSkipRows('3, 5 7;9')).toEqual({
			rows: [3, 5, 7, 9],
			invalid: [],
		})
		expect(parseSkipRows('0 -1 a')).toEqual({
			rows: [],
			invalid: ['0', '-1', 'a'],
		})
	})

	it('compares definitions by content, not key order', () => {
		expect(
			sameDefinition(
				{ a: 1, b: [1, { c: 2, d: 3 }] },
				{ b: [1, { d: 3, c: 2 }], a: 1 },
			),
		).toBe(true)
		expect(sameDefinition({ a: 1 }, { a: 2 })).toBe(false)
		expect(sameDefinition({ a: null }, {})).toBe(false)
	})

	it('lists the columns a sample row needs, joins and the id column included', () => {
		const draft = draftFromStored(fixture('stackiq', 'topdesk-usage.json'))
		draft.idStrategyType = 'sourceField'
		draft.idStrategyField = 'APPID'
		expect(sampleColumns(draft)).toEqual([
			'Applicatie Status',
			'Classificatie',
			'End-of-Life Functioneel',
			'Beheer',
			'Cluster',
			'Applicatie Eigenaar (Afdeling)',
			'APPID',
		])
	})

	it('nests the values of JSON Pointer columns the way the engine reads them', () => {
		const draft = draftFromStored(fixture('openregister', 'zgw-zaken-json.json'))
		const columns = sampleColumns(draft)
		expect(columns[0]).toBe('/identificatie')
		expect(
			sampleRowFrom(columns, {
				'/identificatie': 'Z-1',
				'/omschrijving': 'Test',
			}),
		).toEqual({
			identificatie: 'Z-1',
			omschrijving: 'Test',
			startdatum: '',
			einddatum: '',
			status: '',
			zaaktype: '',
			vertrouwelijkheidaanduiding: '',
		})
		expect(
			sampleRowFrom(['/zaak/id', '/zaak/a~1b', '/m~0n', 'Flat /col'], {
				'/zaak/id': '7',
				'/zaak/a~1b': 'x',
				'/m~0n': 'y',
				'Flat /col': 'z',
			}),
		).toEqual({ zaak: { id: '7', 'a/b': 'x' }, 'm~n': 'y', 'Flat /col': 'z' })
	})

	it('keeps a column named __proto__ or constructor as a plain key', () => {
		const nested = sampleRowFrom(
			['/__proto__/polluted', '/constructor/prototype/x'],
			{ '/__proto__/polluted': 'yes', '/constructor/prototype/x': 'z' },
		)
		const flat = sampleRowFrom(['__proto__'], { ['__proto__']: 'flat' })
		expect({}.polluted).toBeUndefined()
		expect(Object.prototype.x).toBeUndefined()
		expect(Object.getPrototypeOf(nested)).toBe(Object.prototype)
		expect(Object.getPrototypeOf(flat)).toBe(Object.prototype)
		// The engine reads these as ordinary keys, so they are sent as such.
		expect(JSON.stringify(nested)).toBe(
			'{"__proto__":{"polluted":"yes"},"constructor":{"prototype":{"x":"z"}}}',
		)
		expect(JSON.stringify(flat)).toBe('{"__proto__":"flat"}')
	})

	it('leaves a cleared date format out, so the engine default applies', () => {
		const stored = {
			type: 'date',
			sourceFormat: '!Y-m-d',
			targetFormat: 'Y-m-d',
		}
		const cleared = {
			...transformFromStored(stored),
			sourceFormat: '',
			targetFormat: '',
		}
		expect(transformPayload(cleared)).toEqual({ type: 'date' })
		expect(transformPayload(transformFromStored(stored))).toEqual(stored)

		const storedEmpty = { type: 'date', targetFormat: '' }
		expect(transformPayload(transformFromStored(storedEmpty))).toEqual(
			storedEmpty,
		)
		expect(
			transformPayload({ ...newTransform('date'), targetFormat: 'd-m-Y' }),
		).toEqual({ type: 'date', targetFormat: 'd-m-Y' })
	})

	it('summarises each transform type', () => {
		const draft = draftFromStored(fixture('stackiq', 'topdesk-module.json'))
		expect(transformSummary(null)).toBe('Copied as is')
		expect(transformSummary(transformFor(draft, 'name'))).toBe('Trim spaces')
		expect(transformSummary(transformFor(draft, 'externalCreatedAt'))).toBe(
			'Date !Y-m-d to Y-m-d',
		)
		expect(
			transformSummary(transformFor(draft, 'cloudDienstverleningsmodel')),
		).toBe('Lookup table, values: 13, other values stay empty')
		expect(transformSummary(transformFor(draft, 'bbnLevel'))).toMatch(
			/other values are an error$/,
		)
		expect(transformSummary(newTransform('bool-map'))).toMatch(/^Yes\/no table/)
		expect(
			transformSummary({ ...newTransform('bool-map'), defaultMode: 'empty' }),
		).toBe('Yes/no table, values: 1, other values are stored as false')
		expect(
			transformSummary({ ...newTransform('concat'), fields: ['B', 'C'] }),
		).toBe('Joined with B, C, separated by " "')
		expect(
			transformSummary({
				...newTransform('const'),
				valueCell: { text: 'X', json: false },
			}),
		).toBe('Fixed value X')
		expect(
			transformSummary({ ...newTransform('trim'), type: 'reverse' }),
		).toMatch(/reverse/)
	})
})

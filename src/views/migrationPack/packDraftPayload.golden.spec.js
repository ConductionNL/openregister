/**
 * Golden record of what the migration-pack editor saves and shows: the
 * stored transform block (transformPayload) and its one-line description
 * (transformSummary) for stored and edited transforms of every type, and the
 * stored definition (payloadFromDraft) for stored and edited packs. Payloads
 * are recorded as JSON text so their key order is pinned too.
 */
import {
	draftFromStored,
	payloadFromDraft,
	transformFromStored,
	transformPayload,
	transformSummary,
} from './packDraft.js'

const STORED_TRANSFORMS = {
	none: null,
	untyped: {},
	trim: { type: 'trim', extra: 1 },
	dateFull: { type: 'date', sourceFormat: 'd-m-Y', targetFormat: 'Y-m-d' },
	dateClearedStored: { type: 'date', sourceFormat: '', targetFormat: '' },
	dateBare: { type: 'date' },
	lookup: { note: 'x', type: 'lookup', map: { a: 1, b: 'two' }, default: 'z' },
	lookupNullDefault: { type: 'lookup', map: { a: 1 }, default: null },
	lookupNoDefault: { type: 'lookup', map: [] },
	boolMap: { type: 'bool-map', map: { ja: true, nee: false }, default: false },
	concat: { type: 'concat', fields: ['a', 'b'], separator: '-' },
	concatDefaultSeparator: { type: 'concat', fields: ['a'] },
	concatStoredSpace: { type: 'concat', fields: ['a'], separator: ' ' },
	const: { type: 'const', value: { deep: [1, 2] } },
	constText: { type: 'const', value: 'text' },
	unknown: { type: 'regex', pattern: '^a', flags: 'i' },
}

const TRANSFORM_EDITS = {
	asStored: () => {},
	clearFormats: (d) => {
		d.sourceFormat = ''
		d.targetFormat = ''
	},
	emptyDefault: (d) => {
		d.defaultMode = 'empty'
	},
	valueDefault: (d) => {
		d.defaultMode = 'value'
		d.defaultCell = { text: '42', json: true }
	},
	noDefault: (d) => {
		d.defaultMode = 'none'
	},
	spaceSeparator: (d) => {
		d.separator = ' '
	},
	moreFields: (d) => {
		d.fields = ['x', 'y', 'z']
		d.separator = ', '
	},
	retype: (d) => {
		d.type = d.type === 'const' ? 'trim' : 'const'
		d.valueCell = { text: 'v', json: false }
	},
	clearType: (d) => {
		d.type = ''
	},
}

const STORED_PACKS = {
	none: null,
	minimal: {
		id: 'p',
		name: 'P',
		sourceFormat: 'csv',
		version: '1.0.0',
		fieldMappings: [],
	},
	full: {
		custom: { keep: true },
		id: 'pack',
		name: 'Pack',
		description: 'About',
		sourceFormat: 'json',
		version: '2.1.0',
		fieldMappings: [
			{ source: 'a', target: 'b' },
			{ source: 'c', target: 'd', transform: { type: 'trim' } },
		],
		defaults: { status: 'open', count: 0 },
		skipRows: [1, 3],
		idStrategy: { type: 'sourceField', field: 'id', extra: 'x' },
	},
	emptyDescription: {
		id: 'p',
		name: 'P',
		description: '',
		defaults: [],
		skipRows: [],
	},
	emptyObjectDefaults: { id: 'p', name: 'P', defaults: {} },
	oddDefaults: { id: 'p', name: 'P', defaults: 'weird', idStrategy: 'flat' },
	generatedWithField: {
		id: 'p',
		name: 'P',
		idStrategy: { type: 'generate', field: 'old' },
	},
}

const PACK_EDITS = {
	asStored: () => {},
	clearAll: (d) => {
		d.description = ''
		d.defaults = []
		d.skipRows = ''
		d.idStrategyType = 'generate'
	},
	addDefault: (d) => {
		d.defaults = [{ uid: 'u', key: 'k', text: 'v', json: false }]
	},
	skipRows: (d) => {
		d.skipRows = '2, 4 x'
	},
	sourceField: (d) => {
		d.idStrategyType = 'sourceField'
		d.idStrategyField = 'ref'
	},
	describe: (d) => {
		d.description = 'New text'
	},
}

describe('pack draft payloads (golden)', () => {
	it('transformPayload and transformSummary match the recorded output', () => {
		const records = {}
		for (const [storedName, stored] of Object.entries(STORED_TRANSFORMS)) {
			for (const [editName, edit] of Object.entries(TRANSFORM_EDITS)) {
				const draft = transformFromStored(stored)
				edit(draft)
				records[`${storedName}/${editName}`] = {
					payload: JSON.stringify(transformPayload(draft)),
					summary: transformSummary(draft),
				}
			}
		}
		records.nullSummary = transformSummary(null)
		expect(records).toMatchSnapshot()
	})

	it('payloadFromDraft matches the recorded output', () => {
		const records = {}
		for (const [storedName, stored] of Object.entries(STORED_PACKS)) {
			for (const [editName, edit] of Object.entries(PACK_EDITS)) {
				const draft = draftFromStored(stored)
				edit(draft)
				records[`${storedName}/${editName}`] = JSON.stringify(
					payloadFromDraft(draft),
				)
			}
		}
		expect(records).toMatchSnapshot()
	})
})

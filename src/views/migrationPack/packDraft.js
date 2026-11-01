/**
 * The editable draft of a migration pack, and the way back to a stored definition.
 *
 * A pack's field mappings are an ORDERED list, not a map keyed by source column:
 * one column may feed several targets (`Applicatiesoort` feeds `applicationType`
 * and `cloudDienstverleningsmodel`), and several columns may feed one target, in
 * which case the later row wins (`Roepnaam` over `Nickname`). The draft keeps
 * every row, in order, so nothing is merged away on save.
 *
 * Saving an unedited pack MUST give back the stored definition unchanged. The
 * draft therefore keeps the stored document (`base`) next to the fields it
 * edits, and the payload is rebuilt in the stored key order: keys the editor
 * does not model (`x-stackiq`, a transform option a later version adds) are
 * copied through untouched, and a key the stored document did not have is only
 * added when the editor gave it a value.
 *
 * A lookup's `default` has THREE states, and the mapping engine treats each one
 * differently (MappingEngine::applyMapTransform uses array_key_exists):
 *   - no `default` key: an unmapped value errors the row;
 *   - `default: null`: an unmapped value leaves the field empty;
 *   - `default: <value>`: an unmapped value becomes that value.
 *
 * Free of Vue on purpose, so the round trip can be tested on its own.
 *
 * @spec openspec/changes/migration-pack-editor-ui/specs/migration-mapping-packs/spec.md#requirement-the-pack-editor-must-save-a-definition-without-losing-what-it-does-not-show
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

import { t } from './l10n.js'

/** Source formats PackDefinitionValidator accepts. */
export const SOURCE_FORMATS = ['csv', 'json', 'excel']

/** Transform types PackDefinitionValidator accepts, in the order the editor offers them. */
export const TRANSFORM_TYPES = [
	'trim',
	'date',
	'lookup',
	'bool-map',
	'concat',
	'const',
]

/** idStrategy types PackDefinitionValidator accepts. */
export const ID_STRATEGY_TYPES = ['generate', 'sourceField']

/** How a lookup or yes/no map treats a value that is not in its table. */
export const DEFAULT_MODES = ['none', 'empty', 'value']

const TOP_LEVEL_KEYS = [
	'id',
	'name',
	'description',
	'sourceFormat',
	'version',
	'fieldMappings',
	'defaults',
	'skipRows',
	'idStrategy',
]

const MAPPING_KEYS = ['source', 'target', 'required', 'transform']

const TRANSFORM_KEYS = [
	'type',
	'sourceFormat',
	'targetFormat',
	'map',
	'default',
	'fields',
	'separator',
	'value',
]

const ID_STRATEGY_KEYS = ['type', 'field']

const SLUG_PATTERN = /^[a-z0-9][a-z0-9-]*$/
const SEMVER_PATTERN = /^(\d+)\.(\d+)\.(\d+)$/

let uidCounter = 0

/**
 * A key for a list row that stays the same while the row moves.
 *
 * @return {string} A new row key.
 */
function uid() {
	uidCounter += 1
	return `row-${uidCounter}`
}

/**
 * Whether a value is a plain JSON object (not an array, not null).
 *
 * @param {unknown} value The value.
 * @return {boolean} True for a plain object.
 */
function isPlainObject(value) {
	return value !== null && typeof value === 'object' && !Array.isArray(value)
}

/**
 * Whether a key is present on a plain object.
 *
 * @param {object} object The object.
 * @param {string} key The key.
 * @return {boolean} True when the object has the key itself.
 */
function has(object, key) {
	return isPlainObject(object) && Object.hasOwn(object, key)
}

/**
 * A deep copy of a JSON value.
 *
 * @param {unknown} value The value.
 * @return {unknown} The copy.
 */
function clone(value) {
	return value === undefined ? undefined : JSON.parse(JSON.stringify(value))
}

/**
 * A string field of the stored document, or an empty string.
 *
 * @param {unknown} value The stored value.
 * @return {string} The value when it is a string, else ''.
 */
function str(value) {
	return typeof value === 'string' ? value : ''
}

/**
 * Rebuild an object in the stored key order.
 *
 * Keys of `values` replace the stored ones in place. Stored keys the editor
 * does not know (not in `known`) are copied through. Known stored keys that
 * `values` leaves out are dropped: the editor removed them. Keys `values` adds
 * come last.
 *
 * @param {object} base The stored object.
 * @param {object} values The values the editor produced.
 * @param {Array<string>} known The keys the editor models.
 * @return {object} The rebuilt object.
 */
function ordered(base, values, known) {
	const out = {}
	for (const key of Object.keys(isPlainObject(base) ? base : {})) {
		if (has(values, key)) {
			out[key] = values[key]
		} else if (!known.includes(key)) {
			out[key] = clone(base[key])
		}
	}
	for (const key of Object.keys(values)) {
		if (!has(out, key)) {
			out[key] = values[key]
		}
	}
	return out
}

/**
 * An editable value cell: a string stays text, anything else is edited as JSON.
 *
 * Lookup values in the shipped packs are not all strings (`"Saas": ["SaaS"]`),
 * so a cell remembers which of the two it is and gives the same type back.
 *
 * @param {unknown} value The stored value.
 * @return {{text: string, json: boolean}} The cell.
 *
 * @spec openspec/changes/migration-pack-editor-ui/specs/migration-mapping-packs/spec.md#requirement-the-pack-editor-must-save-a-definition-without-losing-what-it-does-not-show
 */
export function cellFromValue(value) {
	if (typeof value === 'string') {
		return { text: value, json: false }
	}
	return { text: JSON.stringify(value === undefined ? null : value), json: true }
}

/**
 * The stored value of a cell.
 *
 * @param {{text: string, json: boolean}} cell The cell.
 * @return {unknown} The value.
 * @throws {SyntaxError} When a JSON cell does not parse.
 *
 * @spec openspec/changes/migration-pack-editor-ui/specs/migration-mapping-packs/spec.md#requirement-the-pack-editor-must-save-a-definition-without-losing-what-it-does-not-show
 */
export function valueFromCell(cell) {
	if (!cell || !cell.json) {
		return cell ? cell.text : ''
	}
	return JSON.parse(cell.text)
}

/**
 * Whether a cell holds a value that can be stored.
 *
 * @param {{text: string, json: boolean}} cell The cell.
 * @return {boolean} False for a JSON cell that does not parse.
 */
function cellIsValid(cell) {
	try {
		valueFromCell(cell)
		return true
	} catch {
		return false
	}
}

/**
 * Whether a cell holds true or false.
 *
 * A yes/no table casts what it stores to a boolean on import, so the text
 * `false` (or `nee`) would become true. Only real booleans are accepted, and
 * the numbers 1 and 0, which cast to true and false and which a pack written
 * through the API or imported from elsewhere may hold; an unedited 1 or 0 is
 * saved as it was.
 *
 * @param {{text: string, json: boolean}} cell The cell.
 * @return {boolean} True for a JSON cell holding true, false, 1 or 0.
 *
 * @spec openspec/changes/migration-pack-editor-ui/specs/migration-mapping-packs/spec.md#requirement-administrators-must-be-able-to-list-and-edit-migration-packs-in-the-openregister-ui
 */
export function isBooleanCell(cell) {
	try {
		const value = valueFromCell(cell)
		return typeof value === 'boolean' || value === 1 || value === 0
	} catch {
		return false
	}
}

/**
 * Key/value rows from a stored object (a lookup table, or the pack defaults).
 *
 * @param {object|Array} object The stored object. An empty PHP array comes back as `[]`.
 * @return {Array<{uid: string, key: string, text: string, json: boolean}>} The rows.
 *
 * @spec openspec/changes/migration-pack-editor-ui/specs/migration-mapping-packs/spec.md#requirement-the-pack-editor-must-save-a-definition-without-losing-what-it-does-not-show
 */
export function entryRowsFrom(object) {
	if (!isPlainObject(object) && !Array.isArray(object)) {
		return []
	}
	return Object.entries(object).map(([key, value]) => ({
		uid: uid(),
		key,
		...cellFromValue(value),
	}))
}

/**
 * A new, empty key/value row.
 *
 * @param {boolean} [json] Whether the value is edited as JSON.
 * @return {{uid: string, key: string, text: string, json: boolean}} The row.
 *
 * @spec openspec/changes/migration-pack-editor-ui/specs/migration-mapping-packs/spec.md#requirement-administrators-must-be-able-to-list-and-edit-migration-packs-in-the-openregister-ui
 */
export function newEntryRow(json = false) {
	return { uid: uid(), key: '', text: json ? 'true' : '', json }
}

/**
 * The object key/value rows stand for. A key that appears twice keeps the later value.
 *
 * @param {Array<{key: string, text: string, json: boolean}>} rows The rows.
 * @return {object} The object.
 * @throws {SyntaxError} When a JSON cell does not parse.
 */
function objectFromEntryRows(rows) {
	const out = {}
	for (const row of rows) {
		out[row.key] = valueFromCell(row)
	}
	return out
}

/**
 * The editable form of one transform block.
 *
 * @param {object} transform The stored transform.
 * @return {object} The transform draft.
 *
 * @spec openspec/changes/migration-pack-editor-ui/specs/migration-mapping-packs/spec.md#requirement-the-pack-editor-must-save-a-definition-without-losing-what-it-does-not-show
 */
export function transformFromStored(transform) {
	const base = isPlainObject(transform) ? clone(transform) : {}
	let defaultMode = 'none'
	if (has(base, 'default')) {
		defaultMode = base.default === null ? 'empty' : 'value'
	}
	return {
		base,
		type: str(base.type),
		sourceFormat: str(base.sourceFormat),
		targetFormat: str(base.targetFormat),
		mapRows: entryRowsFrom(base.map),
		defaultMode,
		defaultCell: cellFromValue(defaultMode === 'value' ? base.default : ''),
		fields: Array.isArray(base.fields) ? base.fields.map((f) => String(f)) : [],
		separator: typeof base.separator === 'string' ? base.separator : ' ',
		valueCell: cellFromValue(has(base, 'value') ? base.value : ''),
	}
}

/**
 * A new transform draft of a type.
 *
 * @param {string} type The transform type.
 * @return {object} The transform draft.
 *
 * @spec openspec/changes/migration-pack-editor-ui/specs/migration-mapping-packs/spec.md#requirement-administrators-must-be-able-to-list-and-edit-migration-packs-in-the-openregister-ui
 */
export function newTransform(type) {
	const draft = transformFromStored({ type })
	if (type === 'bool-map') {
		draft.mapRows = [newEntryRow(true)]
	}
	return draft
}

/**
 * Change a transform draft's type, keeping what the user already typed.
 *
 * Settings of the other types stay in the draft and are left out of the
 * payload, so switching back and forth loses nothing until the pack is saved.
 *
 * @param {object} draft The transform draft.
 * @param {string} type The new type.
 * @return {object} A new transform draft.
 *
 * @spec openspec/changes/migration-pack-editor-ui/specs/migration-mapping-packs/spec.md#requirement-administrators-must-be-able-to-list-and-edit-migration-packs-in-the-openregister-ui
 */
export function withTransformType(draft, type) {
	const next = { ...draft, type }
	if (type === 'bool-map' && next.mapRows.length === 0) {
		next.mapRows = [newEntryRow(true)]
	}
	return next
}

/**
 * The stored form of a transform draft.
 *
 * @param {object} draft The transform draft.
 * @return {object} The transform block.
 * @throws {SyntaxError} When a JSON cell does not parse.
 *
 * @spec openspec/changes/migration-pack-editor-ui/specs/migration-mapping-packs/spec.md#requirement-the-pack-editor-must-save-a-definition-without-losing-what-it-does-not-show
 */
export function transformPayload(draft) {
	const base = draft.base || {}
	const type = draft.type
	if (!TRANSFORM_TYPES.includes(type)) {
		// A type this editor does not know: keep the stored block as it is.
		return { ...clone(base), type }
	}
	const values = { type }
	if (type === 'date') {
		// A cleared format is left out, so the engine's default applies: it
		// would store every date as '' for an empty targetFormat. A format
		// stored as '' stays as it is.
		for (const key of ['sourceFormat', 'targetFormat']) {
			if (draft[key] !== '' || base[key] === '') {
				values[key] = draft[key]
			}
		}
	}
	if (type === 'lookup' || type === 'bool-map') {
		values.map = objectFromEntryRows(draft.mapRows)
		if (draft.defaultMode === 'empty') {
			values.default = null
		} else if (draft.defaultMode === 'value') {
			values.default = valueFromCell(draft.defaultCell)
		}
	}
	if (type === 'concat') {
		values.fields = draft.fields.slice()
		if (draft.separator !== ' ' || has(base, 'separator')) {
			values.separator = draft.separator
		}
	}
	if (type === 'const') {
		values.value = valueFromCell(draft.valueCell)
	}
	return ordered(base, values, TRANSFORM_KEYS)
}

/**
 * Keys of a transform block the editor does not show. They are kept on save.
 *
 * @param {object} draft The transform draft.
 * @return {Array<string>} The unknown keys.
 *
 * @spec openspec/changes/migration-pack-editor-ui/specs/migration-mapping-packs/spec.md#requirement-the-pack-editor-must-save-a-definition-without-losing-what-it-does-not-show
 */
export function unknownTransformKeys(draft) {
	return Object.keys(draft?.base || {}).filter(
		(key) => !TRANSFORM_KEYS.includes(key),
	)
}

/**
 * The editable form of one field mapping.
 *
 * @param {object} mapping The stored mapping.
 * @return {object} The row.
 *
 * @spec openspec/changes/migration-pack-editor-ui/specs/migration-mapping-packs/spec.md#requirement-the-pack-editor-must-save-a-definition-without-losing-what-it-does-not-show
 */
export function rowFromMapping(mapping) {
	const base = isPlainObject(mapping) ? clone(mapping) : {}
	return {
		uid: uid(),
		base,
		source: str(base.source),
		target: str(base.target),
		required: base.required === true,
		transform: isPlainObject(base.transform)
			? transformFromStored(base.transform)
			: null,
	}
}

/**
 * A new, empty field-mapping row.
 *
 * @return {object} The row.
 *
 * @spec openspec/changes/migration-pack-editor-ui/specs/migration-mapping-packs/spec.md#requirement-administrators-must-be-able-to-list-and-edit-migration-packs-in-the-openregister-ui
 */
export function newRow() {
	return rowFromMapping({})
}

/**
 * The stored form of a field-mapping row.
 *
 * @param {object} row The row.
 * @return {object} The mapping.
 * @throws {SyntaxError} When a JSON cell does not parse.
 */
function mappingPayload(row) {
	const base = row.base || {}
	const values = { source: row.source, target: row.target }
	if (row.required || has(base, 'required')) {
		values.required = row.required
	}
	if (row.transform) {
		values.transform = transformPayload(row.transform)
	}
	return ordered(base, values, MAPPING_KEYS)
}

/**
 * Move a row up or down. Order matters: the later row wins for the same target.
 *
 * @param {Array<object>} rows The rows.
 * @param {number} index The row to move.
 * @param {number} delta -1 for up, 1 for down.
 * @return {Array<object>} A new array; the same order when the move is out of range.
 *
 * @spec openspec/changes/migration-pack-editor-ui/specs/migration-mapping-packs/spec.md#requirement-administrators-must-be-able-to-list-and-edit-migration-packs-in-the-openregister-ui
 */
export function moveRow(rows, index, delta) {
	const to = index + delta
	if (index < 0 || index >= rows.length || to < 0 || to >= rows.length) {
		return rows.slice()
	}
	const next = rows.slice()
	const [row] = next.splice(index, 1)
	next.splice(to, 0, row)
	return next
}

/**
 * Row numbers from the "rows to skip" text.
 *
 * @param {string} text Comma- or space-separated row numbers.
 * @return {{rows: Array<number>, invalid: Array<string>}} The numbers, and the parts that are not positive whole numbers.
 *
 * @spec openspec/changes/migration-pack-editor-ui/specs/migration-mapping-packs/spec.md#requirement-administrators-must-be-able-to-list-and-edit-migration-packs-in-the-openregister-ui
 */
export function parseSkipRows(text) {
	const rows = []
	const invalid = []
	for (const part of String(text || '').split(/[\s,;]+/)) {
		if (part === '') {
			continue
		}
		if (/^\d+$/.test(part) && Number(part) >= 1) {
			rows.push(Number(part))
		} else {
			invalid.push(part)
		}
	}
	return { rows, invalid }
}

/**
 * The editable form of a stored pack definition.
 *
 * @param {object} definition The stored definition (`pack.definition`).
 * @return {object} The draft.
 *
 * @spec openspec/changes/migration-pack-editor-ui/specs/migration-mapping-packs/spec.md#requirement-the-pack-editor-must-save-a-definition-without-losing-what-it-does-not-show
 */
export function draftFromStored(definition) {
	const base = isPlainObject(definition) ? clone(definition) : {}
	const idStrategy = isPlainObject(base.idStrategy) ? base.idStrategy : {}
	return {
		base,
		slug: str(base.id),
		name: str(base.name),
		description: str(base.description),
		version: str(base.version),
		sourceFormat: str(base.sourceFormat),
		idStrategyType: str(idStrategy.type) || 'generate',
		idStrategyField: str(idStrategy.field),
		rows: Array.isArray(base.fieldMappings)
			? base.fieldMappings.map(rowFromMapping)
			: [],
		defaults: entryRowsFrom(base.defaults),
		skipRows: Array.isArray(base.skipRows) ? base.skipRows.join(', ') : '',
	}
}

/**
 * The stored definition a draft stands for, in the stored key order.
 *
 * @param {object} draft The draft.
 * @return {object} The definition.
 * @throws {SyntaxError} When a JSON cell does not parse; run validateDraft() first.
 *
 * @spec openspec/changes/migration-pack-editor-ui/specs/migration-mapping-packs/spec.md#requirement-the-pack-editor-must-save-a-definition-without-losing-what-it-does-not-show
 */
export function payloadFromDraft(draft) {
	const base = draft.base || {}
	const values = { id: draft.slug, name: draft.name }
	if (draft.description !== '' || has(base, 'description')) {
		values.description = draft.description
	}
	values.sourceFormat = draft.sourceFormat
	values.version = draft.version
	values.fieldMappings = draft.rows.map(mappingPayload)
	if (draft.defaults.length > 0) {
		values.defaults = objectFromEntryRows(draft.defaults)
	} else if (has(base, 'defaults')) {
		// Nothing to map, so keep the stored shape: PHP stores an empty object as [].
		values.defaults =
			isPlainObject(base.defaults) || Array.isArray(base.defaults)
				? Object.keys(base.defaults).length === 0
					? clone(base.defaults)
					: {}
				: {}
	}
	const skip = parseSkipRows(draft.skipRows).rows
	if (skip.length > 0 || has(base, 'skipRows')) {
		values.skipRows = skip
	}
	const idBase = isPlainObject(base.idStrategy) ? base.idStrategy : {}
	const idValues = { type: draft.idStrategyType }
	if (draft.idStrategyType === 'sourceField' || has(idBase, 'field')) {
		idValues.field = draft.idStrategyField
	}
	values.idStrategy = ordered(idBase, idValues, ID_STRATEGY_KEYS)
	return ordered(base, values, TOP_LEVEL_KEYS)
}

/**
 * JSON with object keys sorted, so two definitions compare by content alone.
 *
 * @param {unknown} value The value.
 * @return {string} The normalised JSON.
 *
 * @spec openspec/changes/migration-pack-editor-ui/specs/migration-mapping-packs/spec.md#requirement-the-pack-editor-must-save-a-definition-without-losing-what-it-does-not-show
 */
export function stableStringify(value) {
	if (Array.isArray(value)) {
		return `[${value.map(stableStringify).join(',')}]`
	}
	if (isPlainObject(value)) {
		return `{${Object.keys(value)
			.sort()
			.map((key) => `${JSON.stringify(key)}:${stableStringify(value[key])}`)
			.join(',')}}`
	}
	return JSON.stringify(value === undefined ? null : value)
}

/**
 * Whether two definitions have the same content.
 *
 * @param {object} a A definition.
 * @param {object} b Another definition.
 * @return {boolean} True when equal after key normalisation.
 *
 * @spec openspec/changes/migration-pack-editor-ui/specs/migration-mapping-packs/spec.md#requirement-the-pack-editor-must-save-a-definition-without-losing-what-it-does-not-show
 */
export function sameDefinition(a, b) {
	return stableStringify(a) === stableStringify(b)
}

/**
 * The next patch version: `2.0.0` becomes `2.0.1`.
 *
 * @param {string} version The current version.
 * @return {string} The next version; `1.0.0` when there is none, the input when it is not semver.
 *
 * @spec openspec/changes/migration-pack-editor-ui/specs/migration-mapping-packs/spec.md#requirement-administrators-must-be-able-to-list-and-edit-migration-packs-in-the-openregister-ui
 */
export function nextVersion(version) {
	if (!version) {
		return '1.0.0'
	}
	const match = SEMVER_PATTERN.exec(version)
	if (!match) {
		return version
	}
	return `${match[1]}.${match[2]}.${Number(match[3]) + 1}`
}

/**
 * Targets that more than one row writes, with the 1-based row numbers.
 *
 * Not an error: the engine writes the rows in order, so for a source row where
 * both columns have a value the later row wins.
 *
 * @param {Array<object>} rows The field-mapping rows.
 * @return {Map<string, Array<number>>} Target to row numbers, only for targets written more than once.
 *
 * @spec openspec/changes/migration-pack-editor-ui/specs/migration-mapping-packs/spec.md#requirement-the-pack-editor-must-save-a-definition-without-losing-what-it-does-not-show
 */
export function duplicateTargets(rows) {
	const seen = new Map()
	rows.forEach((row, index) => {
		if (row.target === '') {
			return
		}
		seen.set(row.target, [...(seen.get(row.target) || []), index + 1])
	})
	return new Map([...seen].filter(([, numbers]) => numbers.length > 1))
}

/**
 * The source columns a sample row needs, in first-use order.
 *
 * @param {object} draft The draft.
 * @return {Array<string>} The columns: mapping sources, concat fields and the id field.
 *
 * @spec openspec/changes/migration-pack-editor-ui/specs/migration-mapping-packs/spec.md#requirement-a-pack-must-be-testable-against-sample-rows-without-saving-it
 */
export function sampleColumns(draft) {
	const columns = []
	const add = (column) => {
		if (column && !columns.includes(column)) {
			columns.push(column)
		}
	}
	for (const row of draft.rows) {
		add(row.source)
		if (row.transform?.type === 'concat') {
			row.transform.fields.forEach(add)
		}
	}
	if (draft.idStrategyType === 'sourceField') {
		add(draft.idStrategyField)
	}
	return columns
}

/**
 * The sample row to send to the preview, shaped the way the engine reads it.
 *
 * A column that starts with `/` is a JSON Pointer: the mapping engine walks
 * the row's nested keys for it, so its value is placed at that path
 * (`/a/b` gives `{ a: { b: value } }`, with `~1` read as `/` and `~0` as
 * `~`). Any other column is a flat key. A column without a value is sent as
 * an empty string.
 *
 * @param {Array<string>} columns The source columns, as sampleColumns() gives them.
 * @param {object} sample The typed values by column.
 * @return {object} The row.
 *
 * @spec openspec/changes/migration-pack-editor-ui/specs/migration-mapping-packs/spec.md#requirement-a-pack-must-be-testable-against-sample-rows-without-saving-it
 */
export function sampleRowFrom(columns, sample) {
	const row = {}
	for (const column of columns) {
		const value = sample[column] ?? ''
		if (!column.startsWith('/')) {
			setOwn(row, column, value)
			continue
		}
		const segments = column
			.replace(/^\/+/, '')
			.split('/')
			.map((segment) => segment.replace(/~1/g, '/').replace(/~0/g, '~'))
		let cursor = row
		segments.slice(0, -1).forEach((segment) => {
			const next = Object.hasOwn(cursor, segment) ? cursor[segment] : null
			if (next === null || typeof next !== 'object') {
				setOwn(cursor, segment, {})
			}
			cursor = cursor[segment]
		})
		setOwn(cursor, segments[segments.length - 1], value)
	}
	return row
}

/**
 * Set a key as the object's own property, whatever its name.
 *
 * A column name comes from the pack, which an imported file supplies. Plain
 * assignment of `__proto__` would replace the object's prototype, and reading
 * `constructor` would walk into Object; an own property is just a key, which
 * is also how the server reads it.
 *
 * @param {object} target The object.
 * @param {string} key The key.
 * @param {unknown} value The value.
 *
 * @spec openspec/changes/migration-pack-editor-ui/specs/migration-mapping-packs/spec.md#requirement-a-pack-must-be-testable-against-sample-rows-without-saving-it
 */
function setOwn(target, key, value) {
	Object.defineProperty(target, key, {
		value,
		enumerable: true,
		writable: true,
		configurable: true,
	})
}

/**
 * The problem with one lookup or yes/no table row's result, if any.
 *
 * @param {object} row The table row.
 * @param {string} type The transform type.
 * @return {string|null} A readable message, or null.
 */
function mapCellError(row, type) {
	if (!cellIsValid(row)) {
		return t('openregister', 'The result for "{key}" is not valid JSON.', {
			key: row.key,
		})
	}
	if (type === 'bool-map' && !isBooleanCell(row)) {
		return t('openregister', 'Choose yes or no as the result for "{key}".', {
			key: row.key,
		})
	}
	return null
}

/**
 * The problem with a table's value for other values, if any.
 *
 * @param {object} draft The lookup or yes/no transform draft.
 * @return {string|null} A readable message, or null.
 */
function mapDefaultError(draft) {
	if (draft.defaultMode !== 'value') {
		return null
	}
	if (!cellIsValid(draft.defaultCell)) {
		return t('openregister', 'The value for other values is not valid JSON.')
	}
	if (draft.type === 'bool-map' && !isBooleanCell(draft.defaultCell)) {
		return t(
			'openregister',
			'Choose yes or no for values that are not in the table.',
		)
	}
	return null
}

/**
 * Problems in a lookup or yes/no table.
 *
 * @param {object} draft The transform draft.
 * @return {Array<string>} Readable messages.
 */
function mapTableErrors(draft) {
	const errors = []
	if (draft.mapRows.length === 0) {
		errors.push(t('openregister', 'Add at least one value to the table.'))
	}
	const keys = new Set()
	for (const row of draft.mapRows) {
		if (keys.has(row.key)) {
			errors.push(
				t('openregister', 'The value "{key}" is in the table twice.', {
					key: row.key,
				}),
			)
		}
		keys.add(row.key)
		const cellError = mapCellError(row, draft.type)
		if (cellError) {
			errors.push(cellError)
		}
	}
	const defaultError = mapDefaultError(draft)
	if (defaultError) {
		errors.push(defaultError)
	}
	return errors
}

/**
 * Problems in a join of columns.
 *
 * @param {object} draft The concat transform draft.
 * @return {Array<string>} Readable messages.
 */
function concatErrors(draft) {
	const errors = []
	if (draft.fields.length === 0) {
		errors.push(t('openregister', 'Add at least one column to join.'))
	}
	if (draft.fields.some((field) => field === '')) {
		errors.push(t('openregister', 'A column to join has no name.'))
	}
	return errors
}

/**
 * Problems in one transform draft that would make the server refuse the pack.
 *
 * @param {object} draft The transform draft.
 * @return {Array<string>} Readable messages; empty when the transform can be saved.
 *
 * @spec openspec/changes/migration-pack-editor-ui/specs/migration-mapping-packs/spec.md#requirement-administrators-must-be-able-to-list-and-edit-migration-packs-in-the-openregister-ui
 */
export function validateTransform(draft) {
	if (draft.type === 'lookup' || draft.type === 'bool-map') {
		return mapTableErrors(draft)
	}
	if (draft.type === 'concat') {
		return concatErrors(draft)
	}
	if (draft.type === 'const' && !cellIsValid(draft.valueCell)) {
		return [t('openregister', 'The fixed value is not valid JSON.')]
	}
	return []
}

/**
 * Problems in the pack's own fields: slug, name, version, source format,
 * id column and whether it maps anything.
 *
 * @param {object} draft The draft.
 * @return {Array<string>} Readable messages.
 */
function packFieldErrors(draft) {
	const errors = []
	if (!SLUG_PATTERN.test(draft.slug)) {
		errors.push(
			t(
				'openregister',
				'The slug must start with a lowercase letter or digit and contain only lowercase letters, digits and hyphens.',
			),
		)
	}
	if (draft.name.trim() === '') {
		errors.push(t('openregister', 'The name is required.'))
	}
	if (!SEMVER_PATTERN.test(draft.version)) {
		errors.push(
			t(
				'openregister',
				'The version must look like 1.0.0 (major.minor.patch).',
			),
		)
	}
	if (!SOURCE_FORMATS.includes(draft.sourceFormat)) {
		errors.push(t('openregister', 'Choose a source format.'))
	}
	if (draft.idStrategyType === 'sourceField' && draft.idStrategyField === '') {
		errors.push(t('openregister', 'Name the column that holds the id.'))
	}
	if (draft.rows.length === 0) {
		errors.push(t('openregister', 'Add at least one field mapping.'))
	}
	return errors
}

/**
 * Problems in one field mapping row.
 *
 * @param {object} row The mapping row.
 * @param {number} index Its position, from 0.
 * @return {Array<string>} Readable messages.
 */
function mappingRowErrors(row, index) {
	const errors = []
	const number = index + 1
	if (row.source === '') {
		errors.push(
			t('openregister', 'Row {number} has no source column.', { number }),
		)
	}
	if (row.target === '') {
		errors.push(
			t('openregister', 'Row {number} has no target field.', { number }),
		)
	}
	if (row.transform) {
		for (const message of validateTransform(row.transform)) {
			errors.push(
				t('openregister', 'Row {number}: {message}', {
					number,
					message,
				}),
			)
		}
	}
	return errors
}

/**
 * Problems in the pack defaults.
 *
 * @param {Array<object>} defaults The default rows.
 * @return {Array<string>} Readable messages.
 */
function defaultErrors(defaults) {
	const errors = []
	const defaultKeys = new Set()
	for (const row of defaults) {
		if (row.key === '') {
			errors.push(t('openregister', 'A default value has no target field.'))
		} else if (defaultKeys.has(row.key)) {
			errors.push(
				t('openregister', 'The default for "{key}" is set twice.', {
					key: row.key,
				}),
			)
		}
		defaultKeys.add(row.key)
		if (!cellIsValid(row)) {
			errors.push(
				t('openregister', 'The default for "{key}" is not valid JSON.', {
					key: row.key,
				}),
			)
		}
	}
	return errors
}

/**
 * Problems in the draft that would make the server refuse the pack.
 *
 * Mirrors PackDefinitionValidator so most mistakes show before a save; the
 * server stays the authority and its 422 is shown as well.
 *
 * @param {object} draft The draft.
 * @return {Array<string>} Readable messages; empty when the draft can be saved.
 *
 * @spec openspec/changes/migration-pack-editor-ui/specs/migration-mapping-packs/spec.md#requirement-administrators-must-be-able-to-list-and-edit-migration-packs-in-the-openregister-ui
 */
export function validateDraft(draft) {
	const errors = [
		...packFieldErrors(draft),
		...draft.rows.flatMap((row, index) => mappingRowErrors(row, index)),
		...defaultErrors(draft.defaults),
	]
	const invalidSkips = parseSkipRows(draft.skipRows).invalid
	if (invalidSkips.length > 0) {
		errors.push(
			t(
				'openregister',
				'Rows to skip must be whole numbers from 1 up: {values}',
				{
					values: invalidSkips.join(', '),
				},
			),
		)
	}
	return errors
}

/**
 * A short, readable description of a transform for the mapping table.
 *
 * @param {object|null} draft The transform draft, or null for none.
 * @return {string} The summary.
 *
 * @spec openspec/changes/migration-pack-editor-ui/specs/migration-mapping-packs/spec.md#requirement-administrators-must-be-able-to-list-and-edit-migration-packs-in-the-openregister-ui
 */
export function transformSummary(draft) {
	if (!draft || draft.type === '') {
		return t('openregister', 'Copied as is')
	}
	const unmapped = {
		none: t('openregister', 'other values are an error'),
		// A yes/no table stores its default as true or false.
		empty:
			draft.type === 'bool-map'
				? t('openregister', 'other values are stored as false')
				: t('openregister', 'other values stay empty'),
		value: t('openregister', 'other values get a default'),
	}[draft.defaultMode]
	switch (draft.type) {
		case 'trim':
			return t('openregister', 'Trim spaces')
		case 'date':
			return t('openregister', 'Date {from} to {to}', {
				from: draft.sourceFormat || t('openregister', 'any format'),
				to: draft.targetFormat || 'Y-m-d',
			})
		case 'lookup':
			return t('openregister', 'Lookup table, values: {count}, {unmapped}', {
				count: draft.mapRows.length,
				unmapped,
			})
		case 'bool-map':
			return t('openregister', 'Yes/no table, values: {count}, {unmapped}', {
				count: draft.mapRows.length,
				unmapped,
			})
		case 'concat':
			return t(
				'openregister',
				'Joined with {fields}, separated by "{separator}"',
				{
					fields: draft.fields.join(', '),
					separator: draft.separator,
				},
			)
		case 'const':
			return t('openregister', 'Fixed value {value}', {
				value: draft.valueCell.text,
			})
		default:
			return t('openregister', 'Transform "{type}" (kept as stored)', {
				type: draft.type,
			})
	}
}

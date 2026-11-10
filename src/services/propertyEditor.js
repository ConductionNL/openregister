/**
 * Which editor a declared field gets, in the record form and in a records-list
 * cell. Pure functions over the schema property, so the choice is testable
 * without mounting the 3,500-line record modal.
 *
 * @spec openspec/specs/objects-crud/spec.md#requirement-req-rfce-001-the-record-form-gives-each-declared-field-its-own-editor
 */

/**
 * The constants a `oneOf` declares, or null when it is not a list of constants.
 *
 * @param {object} property The schema property.
 * @return {Array<object>|null}
 */
function oneOfConstants(property) {
	const list = property?.oneOf
	if (!Array.isArray(list) || list.length === 0) return null
	return list.every((entry) => entry && entry.const !== undefined) ? list : null
}

/**
 * The choices a property declares, as `{ value, label }`.
 *
 * @param {object} property The schema property.
 * @return {Array<{value: *, label: string}>}
 * @spec openspec/specs/objects-crud/spec.md#requirement-req-rfce-001-the-record-form-gives-each-declared-field-its-own-editor
 */
export function enumOptions(property) {
	if (Array.isArray(property?.enum) && property.enum.length > 0) {
		return property.enum.map((value) => ({ value, label: String(value) }))
	}
	const constants = oneOfConstants(property)
	if (constants) {
		return constants.map((entry) => ({
			value: entry.const,
			label: entry.title || String(entry.const),
		}))
	}
	return []
}

// The editors a property without translations can get, first match wins.
const EDITOR_RULES = [
	['file', (property) => property.type === 'file'],
	['select', (property) => enumOptions(property).length > 0],
	['switch', (property) => property.type === 'boolean'],
	['date', isDateString],
]

/**
 * The editor kind for a property: `switch`, `date`, `select`, `file`,
 * `translation` or `text`.
 *
 * A translatable property only gets one input per language when the register
 * declares languages; without them there is nothing to put a tab on.
 *
 * @param {object} property The schema property.
 * @param {Array<string>} languages The register's languages.
 * @return {string}
 * @spec openspec/specs/objects-crud/spec.md#requirement-req-rfce-001-the-record-form-gives-each-declared-field-its-own-editor
 */
export function editorFor(property, languages = []) {
	if (!property) return 'text'
	if (isTranslated(property, languages)) return 'translation'
	const rule = EDITOR_RULES.find(([, applies]) => applies(property))
	return rule ? rule[0] : 'text'
}

/**
 * Whether a property gets one input per register language.
 *
 * @param {object} property The schema property.
 * @param {Array<string>} languages The register's languages.
 * @return {boolean}
 * @spec openspec/specs/objects-crud/spec.md#requirement-req-rfce-001-the-record-form-gives-each-declared-field-its-own-editor
 */
function isTranslated(property, languages) {
	return (
		property.translatable === true
		&& Array.isArray(languages)
		&& languages.length > 0
	)
}

/**
 * Whether a property is a string holding a date, a time or both.
 *
 * @param {object} property The schema property.
 * @return {boolean}
 * @spec openspec/specs/objects-crud/spec.md#requirement-req-rfce-001-the-record-form-gives-each-declared-field-its-own-editor
 */
function isDateString(property) {
	return (
		property.type === 'string'
		&& ['date', 'time', 'date-time'].includes(property.format)
	)
}

/**
 * Whether a records-list cell may edit this field in place: scalar text,
 * numbers and choice lists only. Dates, files, translations, objects and
 * lists stay with the record form, which has the room for them.
 *
 * @param {object} property The schema property.
 * @param {*} value The cell's current value.
 * @return {boolean}
 * @spec openspec/specs/objects-crud/spec.md#requirement-req-rfce-002-a-cell-in-the-records-list-can-be-edited-in-place
 */
export function isInlineEditable(property, value = null) {
	if (!property || isFixed(property)) return false
	return isInlineKind(property) && !lockedByValue(property, value)
}

/**
 * Whether a property is constant, read-only or translatable.
 *
 * @param {object} property The schema property.
 * @return {boolean}
 * @spec openspec/specs/objects-crud/spec.md#requirement-req-rfce-002-a-cell-in-the-records-list-can-be-edited-in-place
 */
function isFixed(property) {
	return (
		property.const !== undefined
		|| property.readOnly === true
		|| property.translatable === true
	)
}

/**
 * Whether a property is scalar text, a number or a choice list, and no date.
 *
 * @param {object} property The schema property.
 * @return {boolean}
 * @spec openspec/specs/objects-crud/spec.md#requirement-req-rfce-002-a-cell-in-the-records-list-can-be-edited-in-place
 */
function isInlineKind(property) {
	return (
		['string', 'number', 'integer'].includes(property.type)
		&& editorFor(property) !== 'date'
	)
}

/**
 * Whether an immutable property already holds a value.
 *
 * @param {object} property The schema property.
 * @param {unknown} value The cell's current value.
 * @return {boolean}
 * @spec openspec/specs/objects-crud/spec.md#requirement-req-rfce-002-a-cell-in-the-records-list-can-be-edited-in-place
 */
function lockedByValue(property, value) {
	return (
		property.immutable === true
		&& value !== null
		&& value !== undefined
		&& value !== ''
	)
}

/**
 * Read a chosen file as a data URI, the shape the save path stores as a file.
 *
 * @param {File} file The chosen file.
 * @return {Promise<string>}
 * @spec openspec/specs/objects-crud/spec.md#requirement-req-rfce-001-the-record-form-gives-each-declared-field-its-own-editor
 */
export function readFileAsDataUri(file) {
	return new Promise((resolve, reject) => {
		const reader = new FileReader()
		reader.onload = () => resolve(String(reader.result))
		reader.onerror = () => reject(reader.error)
		reader.readAsDataURL(file)
	})
}

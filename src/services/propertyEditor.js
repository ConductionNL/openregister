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
	if (property.translatable === true && Array.isArray(languages) && languages.length > 0) {
		return 'translation'
	}
	if (property.type === 'file') return 'file'
	if (enumOptions(property).length > 0) return 'select'
	if (property.type === 'boolean') return 'switch'
	if (
		property.type === 'string'
		&& ['date', 'time', 'date-time'].includes(property.format)
	) {
		return 'date'
	}
	return 'text'
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
	if (!property) return false
	if (property.const !== undefined || property.readOnly === true) return false
	if (property.translatable === true) return false
	if (!['string', 'number', 'integer'].includes(property.type)) return false
	if (editorFor(property) === 'date') return false
	if (property.immutable === true && value !== null && value !== undefined && value !== '') {
		return false
	}
	return true
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

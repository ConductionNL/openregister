/**
 * Record templates on the create dialog: the templates a user may start a
 * record from, and a template's values as they still fit the schema.
 *
 * @spec openspec/changes/records-saved-templates/specs/record-templates/spec.md
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

/** Keys a template never carries into a new record: they belong to one record. */
const RECORD_KEYS = ['@self', 'id', 'uuid']

/**
 * The templates the current user may start a record from in a register and schema.
 * The server applies the reach (owner, group share, public) and the create right.
 *
 * @param {object} scope The register and schema.
 * @param {string|number} scope.register The register id.
 * @param {string|number} scope.schema The schema id.
 * @return {Promise<Array<object>>} The templates; none when the list cannot be read.
 */
export async function fetchRecordTemplates({ register, schema }) {
	if (!register || !schema) return []
	try {
		const response = await axios.get(generateUrl('/apps/openregister/api/record-templates'), {
			params: { register, schema },
		})
		const results = response?.data?.results
		return Array.isArray(results) ? results : []
	} catch {
		return []
	}
}

/**
 * The JSON-schema type a value has.
 *
 * @param {*} value The value.
 * @return {string}
 */
export function valueType(value) {
	if (value === null) return 'null'
	if (Array.isArray(value)) return 'array'
	if (typeof value === 'number') return Number.isInteger(value) ? 'integer' : 'number'
	return typeof value
}

/**
 * Whether a value still fits a property's declared type. A property without a
 * type takes anything; null fits any property; an integer fits a number; a
 * file property takes what the file input stores (an id, an object or a list).
 *
 * @param {*} value The template value.
 * @param {object} property The schema property.
 * @return {boolean}
 */
export function fitsProperty(value, property) {
	const declared = property?.type
	if (!declared || value === null) return true
	const types = Array.isArray(declared) ? declared : [declared]
	const actual = valueType(value)
	return types.some((type) => {
		if (type === actual) return true
		if (type === 'number' && actual === 'integer') return true
		if (type === 'file') return ['integer', 'string', 'object', 'array'].includes(actual)
		return false
	})
}

/**
 * A template's values as they apply to a schema today: values whose property
 * no longer exists, or whose type no longer matches, are dropped and named.
 * The record is still saved through the normal save, so validation and RBAC
 * apply to what is kept.
 *
 * @param {object} template The template (`values` keyed by property name).
 * @param {object} schema The schema the record is created in.
 * @return {{values: object, dropped: Array<{field: string, reason: string}>}}
 */
export function applyTemplate(template, schema) {
	const properties = schema?.properties || {}
	const values = {}
	const dropped = []
	for (const [field, value] of Object.entries(template?.values || {})) {
		if (RECORD_KEYS.includes(field)) continue
		if (!Object.hasOwn(properties, field)) {
			dropped.push({ field, reason: 'missing' })
			continue
		}
		if (!fitsProperty(value, properties[field])) {
			dropped.push({ field, reason: 'type' })
			continue
		}
		values[field] = value
	}
	return { values, dropped }
}

/** Property formats whose value belongs to one record: its identity and its dates. */
const ONE_RECORD_FORMATS = ['uuid', 'date', 'date-time', 'time']

/** Property formats that point at another record. */
const RELATION_FORMATS = ['uri', 'url', 'relation', 'objectId']

/**
 * Whether a property's value belongs to one record and so starts unticked when
 * a record is saved as a template: its identity, its dates, its relations to
 * other records and its files (design D-3).
 *
 * @param {string} field The property name.
 * @param {object|undefined} property The schema property; undefined when the schema does not declare it.
 * @return {boolean} True when the field starts unticked.
 * @spec openspec/changes/records-saved-templates/specs/record-templates/spec.md#requirement-a-user-can-save-and-share-a-named-record-template
 */
export function belongsToOneRecord(field, property) {
	if (['id', 'uuid', 'identifier'].includes(field)) return true
	if (!property || typeof property !== 'object') return false
	if (property.$ref || property.items?.$ref || property.inversedBy) return true
	if (property.type === 'file' || property.items?.type === 'file') return true
	return ONE_RECORD_FORMATS.includes(property.format) || RELATION_FORMATS.includes(property.format)
}

/**
 * Whether a record holds a value worth offering: not null, not an empty string,
 * array or object.
 *
 * @param {*} value The value.
 * @return {boolean}
 */
function hasValue(value) {
	if (value === null || value === undefined || value === '') return false
	if (Array.isArray(value)) return value.length > 0
	if (typeof value === 'object') return Object.keys(value).length > 0
	return true
}

/**
 * The fields of a record a user can keep in a template, each ticked or not.
 * Only fields with a value are offered. With a schema, its declared properties
 * are offered in schema order and named by their title; without one, the
 * record's own keys. Metadata (`@self`, `_`-prefixed keys) is never offered.
 *
 * @param {object} record The record being saved as a template.
 * @param {object|null} schema The record's schema, with its properties.
 * @return {Array<{field: string, label: string, keep: boolean}>} The choices.
 * @spec openspec/changes/records-saved-templates/specs/record-templates/spec.md#requirement-a-user-can-save-and-share-a-named-record-template
 */
export function templateFieldChoices(record, schema) {
	const properties = schema?.properties || {}
	const declared = Object.keys(properties)
	const fields = declared.length > 0
		? declared
		: Object.keys(record || {}).filter((field) => !field.startsWith('@') && !field.startsWith('_'))
	return fields
		.filter((field) => !RECORD_KEYS.includes(field) && hasValue(record?.[field]))
		.map((field) => ({
			field,
			label: properties[field]?.title || field,
			keep: !belongsToOneRecord(field, properties[field]),
		}))
}

/**
 * The values a template keeps: the record's values of the chosen fields, as a
 * deep copy so later edits to the record do not reach the template.
 *
 * @param {object} record The record.
 * @param {Array<string>} fields The fields to keep.
 * @return {object} The values keyed by field.
 */
export function templateValues(record, fields) {
	const values = {}
	for (const field of fields) {
		if (RECORD_KEYS.includes(field) || !Object.hasOwn(record || {}, field)) continue
		values[field] = JSON.parse(JSON.stringify(record[field]))
	}
	return values
}

/**
 * Who may use a template, as the API stores it: only the owner, the members
 * of one group (to use it, not to change it), or everyone who may create in
 * the schema.
 *
 * @param {'private'|'group'|'public'} visibility The choice.
 * @param {string} group The group id, for `group`.
 * @return {{isPublic: boolean, sharedWith: Array<{group: string, mode: string}>}}
 */
export function templateSharing(visibility, group = '') {
	const name = String(group || '').trim()
	if (visibility === 'public') return { isPublic: true, sharedWith: [] }
	if (visibility === 'group' && name !== '') return { isPublic: false, sharedWith: [{ group: name, mode: 'read' }] }
	return { isPublic: false, sharedWith: [] }
}

/**
 * Store a record template owned by the current user. The server checks the
 * input and that the user may create in the schema.
 *
 * @param {object} template `{name, description, register, schema, values, isPublic, sharedWith}`.
 * @return {Promise<object>} The stored template.
 * @throws {Error} With the server's message when it refuses.
 * @spec openspec/changes/records-saved-templates/specs/record-templates/spec.md#scenario-a-caseworker-saves-a-melding-as-a-template
 */
export async function saveRecordTemplate(template) {
	try {
		const response = await axios.post(generateUrl('/apps/openregister/api/record-templates'), template)
		return response?.data
	} catch (e) {
		const message = e?.response?.data?.message || e?.response?.data?.error || e?.message
		throw new Error(message || '', { cause: e })
	}
}

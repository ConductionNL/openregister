/**
 * Golden record of what each entity constructor copies and defaults: the
 * resulting fields in their order, for full, empty and falsy input, and
 * whether object-valued defaults are fresh per instance.
 */
import { Application } from './application/application.ts'
import { AuditTrail } from './auditTrail/auditTrail.ts'
import { ConfigurationEntity } from './configuration/configuration.ts'
import { ObjectEntity } from './object/object.ts'
import { Organisation } from './organisation/organisation.ts'
import { Register } from './register/register.ts'
import { Schema } from './schema/schema.ts'
import { Source } from './source/source.ts'
import { View } from './view/view.ts'

const CLASSES = {
	Application,
	AuditTrail,
	ConfigurationEntity,
	ObjectEntity,
	Organisation,
	Register,
	Schema,
	Source,
	View,
}

/**
 * Every key an entity reads, taken from the fields an empty input produces,
 * plus the keys that only appear when given.
 *
 * @param {*} Entity The entity class.
 * @return {*} The keys.
 */
function keysOf(Entity) {
	return Object.keys(new Entity({}))
}

/**
 * An input where every key holds the same value.
 *
 * @param {*} keys The keys.
 * @param {*} value The value.
 * @return {*} The input.
 */
function filled(keys, value) {
	return Object.fromEntries(keys.map((key) => [key, value]))
}

/**
 * An input where every key holds a distinct truthy value.
 *
 * @param {*} keys The keys.
 * @return {*} The input.
 */
function distinct(keys) {
	return Object.fromEntries(keys.map((key, i) => [key, `${key}-${i}`]))
}

/**
 * The fields of an instance, in order, with undefined made visible.
 *
 * @param {*} instance The entity.
 * @return {*} The record.
 */
function fields(instance) {
	return Object.entries(instance).map(([key, value]) => [
		key,
		value === undefined ? '<undefined>' : value,
	])
}

/**
 * Which object-valued fields of two empty-input instances are the same object.
 *
 * @param {*} Entity The entity class.
 * @return {*} The shared field names.
 */
function sharedDefaults(Entity) {
	const a = new Entity({})
	const b = new Entity({})
	return Object.keys(a).filter(
		(key) => a[key] !== null && typeof a[key] === 'object' && a[key] === b[key],
	)
}

describe('entity constructors (golden)', () => {
	Object.entries(CLASSES).forEach(([name, Entity]) => {
		it(`${name} matches the recorded behaviour`, () => {
			const keys = keysOf(Entity)
			const extra = [
				'active',
				'isDefault',
				'version',
				'properties',
				'unknownExtra',
			]
			const all = keys.concat(extra.filter((key) => !keys.includes(key)))
			const record = {
				empty: fields(new Entity({})),
				distinct: fields(new Entity(distinct(all))),
				sharedDefaults: sharedDefaults(Entity),
			}
			const falsy = [
				['emptyString', ''],
				['zero', 0],
				['null', null],
				['false', false],
				['undefined', undefined],
			]
			falsy.forEach(([label, value]) => {
				record[label] = fields(new Entity(filled(all, value)))
			})
			record.arrayProperties = fields(new Entity({ properties: [] }))
			const blank = new Entity({})
			const selfKeys = Object.keys(blank['@self'] || {})
			record.self = fields(
				new Entity({ '@self': distinct(selfKeys), id: 'x' }),
			)
			falsy.forEach(([label, value]) => {
				record[`self-${label}`] = fields(
					new Entity({ '@self': filled(selfKeys, value) }),
				)
			})
			expect(record).toMatchSnapshot()
		})
	})
})

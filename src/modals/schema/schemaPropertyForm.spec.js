/**
 * The defaults and option lists of the schema property form.
 *
 * @spec exclude Test of client-side form defaults.
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
import {
	emptyCodedConfig,
	emptyFacetConfig,
	emptyPropertyDefinition,
	formatSelect,
	GEO_FORMATS,
	hasFormatChoice,
	itemsTypeSelect,
	propertyTypeSelect,
} from './schemaPropertyForm.js'

describe('schemaPropertyForm', () => {
	it('builds a fresh property definition each time', () => {
		const first = emptyPropertyDefinition()
		const second = emptyPropertyDefinition()

		first.items.objectConfiguration.schema = 'changed'
		first.fileConfiguration.allowedMimeTypes.push('image/png')
		first.oneOf.push({})

		expect(second.items.objectConfiguration.schema).toBe('')
		expect(second.fileConfiguration.allowedMimeTypes).toEqual([])
		expect(second.oneOf).toEqual([])
		expect(first.objectConfiguration).not.toBe(first.items.objectConfiguration)
	})

	it('defaults a new property to a visible, facetable, optional string', () => {
		const property = emptyPropertyDefinition()

		expect(property.type).toBe('string')
		expect(property.visible).toBe(true)
		expect(property.facetable).toBe(true)
		expect(property.required).toBe(false)
		expect(property.objectConfiguration.handling).toBe('nested-object')
		expect(property.fileConfiguration.handling).toBe('ignore')
	})

	it('builds fresh coded and facet configs', () => {
		const coded = emptyCodedConfig()
		coded.scheme = 'x'
		const facet = emptyFacetConfig()
		facet.title = 'x'

		expect(emptyCodedConfig().scheme).toBe('')
		expect(emptyCodedConfig().store).toBe('uri')
		expect(emptyFacetConfig()).toEqual({ aggregated: true, title: '', description: '', order: '' })
	})

	it('offers every type for a property but not array or oneOf for its items', () => {
		const types = propertyTypeSelect().options
		const itemTypes = itemsTypeSelect().options

		expect(types).toEqual(expect.arrayContaining(['string', 'array', 'oneOf', 'object']))
		expect(itemTypes).not.toContain('array')
		expect(itemTypes).not.toContain('oneOf')
		expect(itemTypes).toEqual(types.filter((type) => type !== 'array' && type !== 'oneOf'))
	})

	it('returns option lists the caller may change without touching the next one', () => {
		propertyTypeSelect().options.push('bogus')
		formatSelect().options.length = 0

		expect(propertyTypeSelect().options).not.toContain('bogus')
		expect(formatSelect().options.length).toBeGreaterThan(0)
		expect(formatSelect().options).toContain('color-hsla')
	})

	it('offers the five geometry formats for an object and the string formats otherwise', () => {
		expect(formatSelect('object').options).toEqual([
			'geo:point',
			'geo:linestring',
			'geo:polygon',
			'geo:multipolygon',
			'geo:geometry',
		])
		expect(formatSelect('string').options).toContain('date-time')
		expect(formatSelect('string').options).not.toContain('geo:point')
		expect(formatSelect().options).toEqual(formatSelect('string').options)
	})

	it('hands out a copy of the geometry formats', () => {
		formatSelect('object').options.length = 0

		expect(formatSelect('object').options).toHaveLength(5)
		expect(Object.isFrozen(GEO_FORMATS)).toBe(true)
	})

	it('offers a format choice for strings and objects only', () => {
		expect(hasFormatChoice('string')).toBe(true)
		expect(hasFormatChoice('object')).toBe(true)
		expect(hasFormatChoice('number')).toBe(false)
		expect(hasFormatChoice('array')).toBe(false)
		expect(hasFormatChoice(undefined)).toBe(false)
	})
})

/**
 * The configuration the schema explorer gives a suggested or selected property.
 */
import {
	selectedPropertyConfig,
	suggestedPropertyConfig,
} from './schemaExploration.js'

const SETTINGS = {
	required: false,
	immutable: false,
	deprecated: false,
	visible: true,
	hideOnCollection: false,
	hideOnForm: false,
	facetable: false,
	pattern: '',
	minimum: null,
	maximum: null,
	multipleOf: null,
	exclusiveMin: false,
	exclusiveMax: false,
}

describe('suggestedPropertyConfig', () => {
	it('seeds a measured suggestion', () => {
		expect(
			suggestedPropertyConfig({
				property_name: 'title',
				recommended_type: 'string',
				detected_format: 'email',
				max_length: 80,
				min_length: 3,
				description: 'The title',
			}),
		).toEqual({
			...SETTINGS,
			type: 'string',
			title: 'title',
			displayTitle: 'title',
			format: 'email',
			maxLength: 80,
			minLength: 3,
			description: 'The title',
			example: '',
			order: 0,
		})
	})

	it.each([
		[{ max_length: 0, min_length: null }, null, null],
		[{ max_length: -1, min_length: Number.MAX_SAFE_INTEGER }, null, null],
		[{ max_length: undefined, min_length: 0 }, null, 0],
		[{ max_length: 5, min_length: undefined }, 5, null],
	])('keeps unmeasured lengths null for %j', (lengths, maxLength, minLength) => {
		const config = suggestedPropertyConfig({ property_name: 'p', ...lengths })
		expect(config.maxLength).toBe(maxLength)
		expect(config.minLength).toBe(minLength)
		expect(config.format).toBe('')
		expect(config.description).toBe('')
	})
})

describe('selectedPropertyConfig', () => {
	it('builds from a suggestion', () => {
		expect(
			selectedPropertyConfig('age', {
				recommended_type: 'integer',
				description: 'Age',
				detected_format: 'int',
				max_length: 3,
				min_length: 1,
			}),
		).toEqual({
			...SETTINGS,
			selected: true,
			type: 'integer',
			title: 'age',
			description: 'Age',
			format: 'int',
			displayTitle: 'age',
			userDescription: '',
			example: '',
			order: 100,
			technicalDescription: '',
			maxLength: 3,
			minLength: 1,
		})
	})

	it('falls back to a plain string without a suggestion', () => {
		expect(selectedPropertyConfig('x', undefined)).toEqual({
			...SETTINGS,
			selected: true,
			type: 'string',
			title: 'x',
			description: '',
			format: '',
			displayTitle: 'x',
			userDescription: '',
			example: '',
			order: 100,
			technicalDescription: '',
			maxLength: null,
			minLength: null,
		})
	})

	it('gives every property its own settings object', () => {
		expect(selectedPropertyConfig('a')).not.toBe(selectedPropertyConfig('a'))
	})
})

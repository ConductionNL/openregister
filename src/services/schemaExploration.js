/**
 * The property configuration the schema explorer starts a suggested property
 * with. Pure functions over an exploration suggestion, so the defaults are
 * testable without mounting the explorer modal.
 *
 * @spec exclude client-side defaults for the schema explorer's property configuration
 */

/**
 * The display and constraint settings every new property configuration starts with.
 *
 * @return {object}
 */
function baseSettings() {
	return {
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
}

/**
 * The configuration an exploration seeds for a suggested property. Lengths
 * the analysis could not measure (zero, null or the integer sentinel) stay null.
 *
 * @param {object} suggestion The exploration suggestion.
 * @return {object}
 * @spec exclude client-side defaults for the schema explorer's property configuration
 */
export function suggestedPropertyConfig(suggestion) {
	return {
		...baseSettings(),
		type: suggestion.recommended_type,
		title: suggestion.property_name,
		displayTitle: suggestion.property_name,
		format: suggestion.detected_format || '',
		maxLength: suggestion.max_length > 0 ? suggestion.max_length : null,
		minLength:
			suggestion.min_length !== null
			&& suggestion.min_length < Number.MAX_SAFE_INTEGER
				? suggestion.min_length
				: null,
		description: suggestion.description || '',
		example: '',
		order: 0,
	}
}

/**
 * The configuration a property gets when the user selects it without one.
 *
 * @param {string} propertyName The property.
 * @param {object|undefined} suggestion The exploration suggestion, if any.
 * @return {object}
 * @spec exclude client-side defaults for the schema explorer's property configuration
 */
export function selectedPropertyConfig(propertyName, suggestion) {
	return {
		...baseSettings(),
		selected: true,
		type: suggestion?.recommended_type || 'string',
		title: propertyName,
		description: suggestion?.description || '',
		format: suggestion?.detected_format || '',
		displayTitle: propertyName,
		userDescription: '',
		example: '',
		order: 100,
		technicalDescription: '',
		maxLength: suggestion?.max_length || null,
		minLength: suggestion?.min_length || null,
	}
}

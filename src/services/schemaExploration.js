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
	return { ...behaviourFlags(), ...valueConstraints() }
}

/**
 * How a new property behaves: optional, editable, current, shown everywhere
 * and not a facet.
 *
 * @return {object} The flags.
 */
function behaviourFlags() {
	return {
		required: false,
		immutable: false,
		deprecated: false,
		visible: true,
		hideOnCollection: false,
		hideOnForm: false,
		facetable: false,
	}
}

/**
 * The value constraints of a new property: none.
 *
 * @return {object} The constraints.
 */
function valueConstraints() {
	return {
		pattern: '',
		minimum: null,
		maximum: null,
		multipleOf: null,
		exclusiveMin: false,
		exclusiveMax: false,
	}
}

/**
 * A maximum length the analysis measured, or null when it measured none (0 or less).
 *
 * @param {number|null|undefined} length The measured length.
 * @return {number|null}
 * @spec exclude client-side defaults for the schema explorer's property configuration
 */
function measuredMaxLength(length) {
	return length > 0 ? length : null
}

/**
 * A minimum length the analysis measured, or null when it measured none
 * (null or the integer sentinel).
 *
 * @param {number|null|undefined} length The measured length.
 * @return {number|null}
 * @spec exclude client-side defaults for the schema explorer's property configuration
 */
function measuredMinLength(length) {
	return length !== null && length < Number.MAX_SAFE_INTEGER ? length : null
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
		maxLength: measuredMaxLength(suggestion.max_length),
		minLength: measuredMinLength(suggestion.min_length),
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
		...suggestedDescription(propertyName, suggestion),
		displayTitle: propertyName,
		userDescription: '',
		example: '',
		order: 100,
		technicalDescription: '',
		...suggestedLengths(suggestion),
	}
}

/**
 * The type, title, description and format of a property, as the analysis
 * suggested them.
 *
 * @param {string} propertyName The property name, which is also its title.
 * @param {object|undefined} suggestion The analysis suggestion.
 * @return {object} The fields.
 */
function suggestedDescription(propertyName, suggestion) {
	return {
		type: suggestion?.recommended_type || 'string',
		title: propertyName,
		description: suggestion?.description || '',
		format: suggestion?.detected_format || '',
	}
}

/**
 * The length limits the analysis suggested, or none.
 *
 * @param {object|undefined} suggestion The analysis suggestion.
 * @return {object} The fields.
 */
function suggestedLengths(suggestion) {
	return {
		maxLength: suggestion?.max_length || null,
		minLength: suggestion?.min_length || null,
	}
}

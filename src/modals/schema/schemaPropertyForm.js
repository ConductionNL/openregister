/**
 * Initial state of the schema property form (EditSchemaProperty).
 *
 * Each function returns a fresh object, so one form never shares nested
 * state with the next.
 *
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */

const PROPERTY_TYPES = [
	'string',
	'number',
	'integer',
	'object',
	'array',
	'boolean',
	'dictionary',
	'file',
	'oneOf',
	'NcFile',
	'NcMail',
	'NcContact',
	'NcNote',
	'NcTodo',
	'NcCalendarEvent',
	'NcTalk',
	'NcDeck',
]

const STRING_FORMATS = [
	'text',
	'markdown',
	'html',
	'date-time',
	'date',
	'time',
	'duration',
	'email',
	'idn-email',
	'hostname',
	'idn-hostname',
	'ipv4',
	'ipv6',
	'uri',
	'uri-reference',
	'iri',
	'iri-reference',
	'uuid',
	'uri-template',
	'json-pointer',
	'relative-json-pointer',
	'regex',
	'url',
	'color',
	'color-hex',
	'color-hex-alpha',
	'color-rgb',
	'color-rgba',
	'color-hsl',
	'color-hsla',
]

/**
 * The code list, hierarchy and lifecycle settings of a property.
 *
 * Held apart from the property definition because the annotation is only
 * written when a scheme is named: an empty x-openregister-concepts block on
 * every property would read as "bound to nothing" rather than as "not a
 * code list".
 *
 * @spec openspec/specs/entity-management-modals/spec.md
 * @return {object}
 */
export function emptyCodedConfig() {
	return {
		scheme: '',
		store: 'uri',
		branch: '',
		maxDepth: '',
		leafOnly: false,
		allowDeprecated: false,
		contextProperty: '',
		scoreProperty: '',
	}
}

/**
 * The ways a coded value can be stored.
 *
 * @spec openspec/specs/entity-management-modals/spec.md
 * @return {Array<object>}
 */
export function codedStoreOptions() {
	return [
		{ value: 'uri', label: 'The concept URI' },
		{ value: 'notation', label: 'The notation' },
	]
}

/**
 * The semantic roles a property can play.
 *
 * @spec openspec/specs/entity-management-modals/spec.md
 * @return {Array<object>}
 */
export function semanticRoleOptions() {
	return [
		{ value: '', label: 'Nothing in particular' },
		{ value: 'title', label: 'The title' },
		{ value: 'status', label: 'The status' },
		{ value: 'assignee', label: 'The assignee' },
		{ value: 'term', label: 'The term' },
	]
}

/**
 * The facet settings of a property.
 *
 * @spec openspec/specs/entity-management-modals/spec.md
 * @return {object}
 */
export function emptyFacetConfig() {
	return {
		aggregated: true,
		title: '',
		description: '',
		order: '',
	}
}

/**
 * The object-reference settings of a property or its items.
 *
 * @spec openspec/specs/entity-management-modals/spec.md
 * @return {object}
 */
function emptyObjectConfiguration() {
	return {
		handling: 'nested-object',
		schema: '',
		queryParams: '', // Extra query parameters for $ref
	}
}

/**
 * The property definition a new property starts from.
 *
 * @spec openspec/specs/entity-management-modals/spec.md
 * @return {object}
 */
export function emptyPropertyDefinition() {
	return {
		description: '',
		title: '',
		type: 'string',
		format: '',
		pattern: '',
		default: '',
		behavior: '',
		required: false,
		deprecated: false,
		visible: true,
		hideOnCollection: false,
		facetable: true,
		order: 0,
		minLength: 0,
		maxLength: 0,
		example: '',
		immutable: false,
		minimum: 0,
		maximum: 0,
		multipleOf: 0,
		exclusiveMin: false,
		exclusiveMax: false,
		minItems: 0,
		maxItems: 0,
		cascadeDelete: false,
		inversedBy: '',
		$ref: '',
		register: '',
		writeBack: false,
		removeAfterWriteBack: false,
		items: {
			cascadeDelete: false,
			$ref: '',
			type: '',
			inversedBy: '',
			register: '',
			writeBack: false,
			removeAfterWriteBack: false,
			objectConfiguration: emptyObjectConfiguration(),
		},

		objectConfiguration: emptyObjectConfiguration(),
		fileConfiguration: {
			handling: 'ignore',
			allowedMimeTypes: [],
			location: '',
			maxSize: 0,
		},

		oneOf: [],
	}
}

/**
 * The select of a property's type.
 *
 * @spec openspec/specs/entity-management-modals/spec.md
 * @return {object}
 */
export function propertyTypeSelect() {
	return {
		inputLabel: 'Type*',
		multiple: false,
		options: [...PROPERTY_TYPES],
	}
}

/**
 * The select of an array's item type: every type except array and oneOf.
 *
 * @spec openspec/specs/entity-management-modals/spec.md
 * @return {object}
 */
export function itemsTypeSelect() {
	return {
		inputLabel: 'Sub type',
		multiple: false,
		options: PROPERTY_TYPES.filter(
			(type) => type !== 'array' && type !== 'oneOf',
		),
	}
}

/**
 * The select of a string's format.
 *
 * @spec openspec/specs/entity-management-modals/spec.md
 * @return {object}
 */
export function formatSelect() {
	return {
		inputLabel: 'Format',
		multiple: false,
		options: [...STRING_FORMATS],
	}
}

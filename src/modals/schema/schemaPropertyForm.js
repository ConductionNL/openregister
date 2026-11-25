/**
 * Initial state of the schema property form (EditSchemaProperty).
 *
 * Each function returns a fresh object, so one form never shares nested
 * state with the next.
 *
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */

import { translate as t } from '@nextcloud/l10n'

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
		...emptyPropertyText(),
		...emptyPropertyDisplay(),
		...emptyPropertyLimits(),
		...emptyPropertyRelation(),
		items: emptyItemsDefinition(),
		objectConfiguration: emptyObjectConfiguration(),
		fileConfiguration: emptyFileConfiguration(),
		oneOf: [],
	}
}

/**
 * The descriptive fields of a new property.
 *
 * @spec openspec/specs/entity-management-modals/spec.md
 * @return {object}
 */
function emptyPropertyText() {
	return {
		description: '',
		title: '',
		type: 'string',
		format: '',
		pattern: '',
		default: '',
	}
}

/**
 * The behaviour and display fields of a new property.
 *
 * @spec openspec/specs/entity-management-modals/spec.md
 * @return {object}
 */
function emptyPropertyDisplay() {
	return {
		behavior: '',
		required: false,
		deprecated: false,
		visible: true,
		hideOnCollection: false,
		facetable: true,
		indexed: false,
		searchable: false,
		order: 0,
	}
}

/**
 * The item definition of a new array property.
 *
 * @spec openspec/specs/entity-management-modals/spec.md
 * @return {object}
 */
function emptyItemsDefinition() {
	return {
		cascadeDelete: false,
		$ref: '',
		type: '',
		inversedBy: '',
		register: '',
		writeBack: false,
		removeAfterWriteBack: false,
		objectConfiguration: emptyObjectConfiguration(),
	}
}

/**
 * The length, number and array limits of a new property.
 *
 * @spec openspec/specs/entity-management-modals/spec.md
 * @return {object}
 */
function emptyPropertyLimits() {
	return {
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
	}
}

/**
 * The relation fields of a new property.
 *
 * @spec openspec/specs/entity-management-modals/spec.md
 * @return {object}
 */
function emptyPropertyRelation() {
	return {
		cascadeDelete: false,
		inversedBy: '',
		$ref: '',
		register: '',
		writeBack: false,
		removeAfterWriteBack: false,
	}
}

/**
 * The file configuration of a new property.
 *
 * @spec openspec/specs/entity-management-modals/spec.md
 * @return {object}
 */
function emptyFileConfiguration() {
	return {
		handling: 'ignore',
		allowedMimeTypes: [],
		location: '',
		maxSize: 0,
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
 * The geometry formats an object property can declare. The server validates a
 * value against them on save (GeoJsonGeometryValidator::declaredGeoType()).
 *
 * @spec openspec/changes/geometry-on-a-map/specs/geo-metadata-kaart/spec.md#requirement-req-geomap-001-a-geometry-is-validated-on-save
 */
export const GEO_FORMATS = Object.freeze([
	'geo:point',
	'geo:linestring',
	'geo:polygon',
	'geo:multipolygon',
	'geo:geometry',
])

/**
 * The label a functional administrator reads for each geometry format, so the
 * select names a shape instead of its `geo:` id.
 *
 * @spec openspec/changes/geometry-on-a-map/specs/geo-metadata-kaart/spec.md#requirement-req-geomap-001-a-geometry-is-validated-on-save
 */
const GEO_FORMAT_LABELS = Object.freeze({
	'geo:point': () => t('openregister', 'Point'),
	'geo:linestring': () => t('openregister', 'Line'),
	'geo:polygon': () => t('openregister', 'Polygon'),
	'geo:multipolygon': () => t('openregister', 'Multipolygon'),
	'geo:geometry': () => t('openregister', 'Any geometry'),
})

/**
 * The geometry formats as select options: the stored `geo:` id with its
 * translated label. A format without a label shows under its id.
 *
 * @spec openspec/changes/geometry-on-a-map/specs/geo-metadata-kaart/spec.md#requirement-req-geomap-001-a-geometry-is-validated-on-save
 * @return {Array<{id: string, label: string}>} One option per geometry format
 */
export function geoFormatOptions() {
	return GEO_FORMATS.map((id) => ({
		id,
		label: GEO_FORMAT_LABELS[id] ? GEO_FORMAT_LABELS[id]() : id,
	}))
}

/**
 * The select of a property's format: the geometry formats for an object, the
 * string formats otherwise. The geometry options carry a translated label and
 * reduce to their id, so the property still stores `geo:point` and friends.
 *
 * @spec openspec/specs/entity-management-modals/spec.md
 * @spec openspec/changes/geometry-on-a-map/specs/geo-metadata-kaart/spec.md#requirement-req-geomap-001-a-geometry-is-validated-on-save
 * @param {string} [type] The property type
 * @return {object}
 */
export function formatSelect(type = 'string') {
	if (type === 'object') {
		return {
			inputLabel: 'Format',
			multiple: false,
			options: geoFormatOptions(),
			reduce: (option) => option.id,
		}
	}

	return {
		inputLabel: 'Format',
		multiple: false,
		options: [...STRING_FORMATS],
	}
}

/**
 * Whether a property type offers a format choice.
 *
 * @spec openspec/changes/geometry-on-a-map/specs/geo-metadata-kaart/spec.md#requirement-req-geomap-001-a-geometry-is-validated-on-save
 * @param {string} type The property type
 * @return {boolean}
 */
export function hasFormatChoice(type) {
	return type === 'string' || type === 'object'
}

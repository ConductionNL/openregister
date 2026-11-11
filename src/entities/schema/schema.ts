import type { ZodSafeParseResult } from 'zod'
import type { TSchema } from './schema.types'

import { z } from 'zod'
import { COPY, withFallbacks } from '../withFallbacks.js'

const FALLBACKS = {
	id: '',
	title: '',
	version: '',
	description: '',
	summary: '',
	required: [],
	properties: COPY,
	archive: {},
	updated: '',
	created: '',
	slug: '',
	configuration: {
		objectNameField: '',
		objectDescriptionField: '',
		objectSummaryField: '',
		objectImageField: '',
		objectSlugField: '',
		objectSlugSuffix: false,
		allowFiles: false,
		allowedTags: [],
		linkedTypes: [],
		unique: false,
		facetCacheTtl: 0,
	},
	hardValidation: false,
	maxDepth: 0,
	authorization: {},
	allOf: COPY,
	oneOf: COPY,
	anyOf: COPY,
	stats: COPY,
	draft: null,
}

/**
 * The validation shape of a schema's identity and text fields. Built on each
 * call, so its version pattern is a fresh RegExp every time.
 *
 * @return The shape.
 */
function schemaTextShape() {
	return {
		id: z.string().min(1),
		title: z.string().min(1),
		version: z.string().regex(/^(?:\d+\.){2}\d+$/g, 'Invalid version format'),
		description: z.string(),
		summary: z.string(),
		required: z.array(z.string()),
		properties: z.object({}),
		archive: z.object({}),
		updated: z.string(),
		created: z.string(),
		slug: z.string().min(1),
	}
}

/**
 * The validation shape of a schema's configuration and rules.
 *
 * @return The shape.
 */
function schemaRulesShape() {
	return {
		configuration: z
			.object({
				objectNameField: z.string().optional(),
				objectDescriptionField: z.string().optional(),
				objectSlugField: z.string().optional(),
				objectSlugSuffix: z.boolean().optional(),
			})
			.optional(),
		hardValidation: z.boolean(),
		maxDepth: z.number().int().min(0),
		authorization: z.record(z.string(), z.array(z.string())).optional(),
	}
}

export class Schema implements TSchema {
	public id!: string
	public title!: string
	public version!: string
	public description!: string
	public summary!: string
	public required!: string[]
	public properties: Record<string, any>
	public archive!: Record<string, any>
	public updated!: string
	public created!: string
	public slug!: string
	public configuration?: {
		objectNameField?: string
		objectDescriptionField?: string
		objectSummaryField?: string
		objectImageField?: string
		objectSlugField?: string
		objectSlugSuffix?: boolean
		allowFiles?: boolean
		allowedTags?: string[]
		linkedTypes?: string[]
		unique?: boolean
		facetCacheTtl?: number
	}

	public hardValidation!: boolean
	public maxDepth!: number
	public authorization?: Record<string, string[]>
	public allOf?: string[]
	public oneOf?: string[]
	public anyOf?: string[]
	public stats?: TSchema['stats']
	public draft!: TSchema['draft']

	/**
	 * @param schema
	 * @spec exclude Entity model field-copy boilerplate: copies typed fields off the input with || defaults; no standalone behavioural contract.
	 */
	constructor(schema: TSchema) {
		Object.assign(this, withFallbacks(schema, FALLBACKS))
		// Convert properties array to object if needed (backend sometimes returns array when empty)
		this.properties = Array.isArray(schema.properties)
			? {}
			: schema.properties || {}
	}

	public validate(): ZodSafeParseResult<unknown> {
		const schema = z.object({ ...schemaTextShape(), ...schemaRulesShape() })
		return schema.safeParse(this)
	}
}

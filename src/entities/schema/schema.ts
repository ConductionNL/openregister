import { SafeParseReturnType, z } from 'zod'
import { TSchema } from './schema.types'

export class Schema implements TSchema {

	public id: string
	public title: string
	public version: string
	public description: string
	public summary: string
	public required: string[]
	public properties: Record<string, any>
	public archive: Record<string, any>
	public updated: string
	public created: string
	public slug: string
	public stats?: TSchema['stats']

	constructor(schema: TSchema) {
		this.id = schema.id || ''
		this.title = schema.title || ''
		this.version = schema.version || ''
		this.description = schema.description || ''
		this.summary = schema.summary || ''
		this.required = schema.required || []
		this.properties = schema.properties || {}
		this.archive = schema.archive || {}
		this.updated = schema.updated || ''
		this.created = schema.created || ''
		this.slug = schema.slug || ''
		this.stats = schema.stats
	}

	public validate(): SafeParseReturnType<TSchema, unknown> {
		const schema = z.object({
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
		})

		return schema.safeParse(this)
	}

}

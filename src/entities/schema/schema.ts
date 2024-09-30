import { SafeParseReturnType, z } from 'zod'
import { TSchema } from './schema.types'

export class Schema implements TSchema {

	public id: string
	public name: string
	public description: string
	public jsonSchema: object
	public version: string

	constructor(schema: TSchema) {
		this.id = schema.id || ''
		this.name = schema.name || ''
		this.description = schema.description || ''
		this.jsonSchema = schema.jsonSchema || {}
		this.version = schema.version || '1.0.0'
	}

	public validate(): SafeParseReturnType<TSchema, unknown> {
		const schema = z.object({
			id: z.string().min(1),
			name: z.string().min(1),
			description: z.string(),
			jsonSchema: z.object({}),
			version: z.string(),
		})

		return schema.safeParse(this)
	}

}

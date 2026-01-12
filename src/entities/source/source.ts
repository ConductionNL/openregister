import { SafeParseReturnType, z } from 'zod'
import { TSource } from './source.types'

export class Source implements TSource {

	public id: string | number
	public title: string
	public description: string
	public databaseUrl: string
	public type: 'internal' | 'mongodb'
	public updated: string
	public created: string

	constructor(source: TSource) {
		this.id = source.id || ''
		this.title = source.title || ''
		this.description = source.description || ''
		this.databaseUrl = source.databaseUrl || ''
		this.type = source.type || 'internal'
		this.updated = source.updated || ''
		this.created = source.created || ''
	}

	public validate(): SafeParseReturnType<TSource, unknown> {
		const schema = z.object({
			id: z.union([z.string(), z.number()]),
			title: z.string().min(1),
			description: z.string(),
			databaseUrl: z.string().url(),
			type: z.enum(['internal', 'mongodb']),
		})

		return schema.safeParse(this)
	}

}

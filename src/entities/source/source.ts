import { SafeParseReturnType, z } from 'zod'
import { TSource } from './source.types'

export class Source implements TSource {

	public id: string
	public name: string
	public description: string
	public databaseUrl: string

	constructor(source: TSource) {
		this.id = source.id || ''
		this.name = source.name || ''
		this.description = source.description || ''
		this.databaseUrl = source.databaseUrl || ''
	}

	public validate(): SafeParseReturnType<TSource, unknown> {
		const schema = z.object({
			id: z.string().min(1),
			name: z.string().min(1),
			description: z.string(),
			databaseUrl: z.string().url(),
		})

		return schema.safeParse(this)
	}

}

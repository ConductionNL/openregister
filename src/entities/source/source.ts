import { SafeParseReturnType, z } from 'zod'
import { TSource } from './source.types'

export class Source implements TSource {

	public id: string | number
	public title: string
	public description: string
	public databaseUrl: string
	public type: string
	public updated: {
        date: string;
        timezone_type: number;
        timezone: string;
    }

	public created: {
        date: string;
        timezone_type: number;
        timezone: string;
    }

	constructor(source: TSource) {
		this.id = source.id || ''
		this.title = source.title || ''
		this.description = source.description || ''
		this.databaseUrl = source.databaseUrl || ''
		this.type = source.type || ''
		this.updated = source.updated || {
			date: '',
			timezone_type: 0,
			timezone: '',
		}
		this.created = source.created || {
			date: '',
			timezone_type: 0,
			timezone: '',
		}
	}

	public validate(): SafeParseReturnType<TSource, unknown> {
		const schema = z.object({
			id: z.union([z.string(), z.number()]),
			title: z.string().min(1),
			description: z.string(),
			databaseUrl: z.string().url(),
			type: z.string(),
		})

		return schema.safeParse(this)
	}

}

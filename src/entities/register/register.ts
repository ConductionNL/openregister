import { SafeParseReturnType, z } from 'zod'
import { TRegister } from './register.types'

export class Register implements TRegister {

	public id: string
	public title: string
	public description: string
	public schemas: string[]
	public source: string
	public databaseId: string
	public tablePrefix: string
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

	constructor(register: TRegister) {
		this.id = register.id || ''
		this.title = register.title
		this.description = register.description
		this.schemas = register.schemas || []
		this.source = register.source || ''
		this.databaseId = register.databaseId
		this.tablePrefix = register.tablePrefix || ''
		this.updated = register.updated || {
			date: '',
			timezone_type: 0,
			timezone: '',
		}
		this.created = register.created || {
			date: '',
			timezone_type: 0,
			timezone: '',
		}
	}

	public validate(): SafeParseReturnType<TRegister, unknown> {
		const schema = z.object({
			id: z.string().min(1),
			title: z.string().min(1),
			description: z.string(),
			schemas: z.array(z.string()),
			source: z.string(),
			databaseId: z.string().min(1),
			tablePrefix: z.string(),
		})

		return schema.safeParse(this)
	}

	public getFullTablePrefix(databasePrefix: string): string {
		return `${databasePrefix}${this.tablePrefix}`.replace(/_{2,}/g, '_')
	}

}

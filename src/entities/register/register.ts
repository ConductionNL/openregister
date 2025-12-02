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
	public updated: string
	public created: string
	public slug: string
	public groups?: string[]
	public quota?: TRegister['quota']
	public usage?: TRegister['usage']
	public stats?: TRegister['stats']
	public published?: string | null
	public depublished?: string | null

	constructor(register: TRegister) {
		this.id = register.id || ''
		this.title = register.title
		this.description = register.description
		this.schemas = register.schemas || []
		this.source = register.source || ''
		this.databaseId = register.databaseId
		this.tablePrefix = register.tablePrefix || ''
		this.updated = register.updated || ''
		this.created = register.created || ''
		this.slug = register.slug || ''
		this.groups = register.groups || []
		this.quota = register.quota || {
			storage: null,
			bandwidth: null,
			requests: null,
			users: null,
			groups: null,
		}
		this.usage = register.usage || {
			storage: 0,
			bandwidth: 0,
			requests: 0,
			users: 0,
			groups: 0,
		}
		this.stats = register.stats
		this.published = register.published || null
		this.depublished = register.depublished || null
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
			slug: z.string().min(1),
			published: z.boolean().optional(),
			depublished: z.boolean().optional(),
		})

		return schema.safeParse(this)
	}

	public getFullTablePrefix(databasePrefix: string): string {
		return `${databasePrefix}${this.tablePrefix}`.replace(/_{2,}/g, '_')
	}

}

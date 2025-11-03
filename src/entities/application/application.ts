import { SafeParseReturnType, z } from 'zod'
import { TApplication } from './application.types'

export class Application implements TApplication {

	public id?: number
	public uuid?: string
	public name: string
	public description?: string
	public version?: string
	public organisation?: number
	public configurations?: number[]
	public registers?: number[]
	public schemas?: number[]
	public groups?: string[]
	public quota?: {
		storage?: number | null
		bandwidth?: number | null
		requests?: number | null
		users?: number | null
		groups?: number | null
	}
	public usage?: {
		storage?: number
		bandwidth?: number
		requests?: number
		users?: number
		groups?: number
	}
	public authorization?: TApplication['authorization']
	public owner?: string
	public active?: boolean
	public created?: string
	public updated?: string

	constructor(application: TApplication) {
		this.id = application.id
		this.uuid = application.uuid || ''
		this.name = application.name || ''
		this.description = application.description || ''
		this.version = application.version || '1.0.0'
		this.organisation = application.organisation
		this.configurations = application.configurations || []
		this.registers = application.registers || []
		this.schemas = application.schemas || []
		this.groups = application.groups || []
		this.quota = application.quota || {
			storage: null,
			bandwidth: null,
			requests: null,
			users: null,
			groups: null,
		}
		this.usage = application.usage || {
			storage: 0,
			bandwidth: 0,
			requests: 0,
			users: 0,
			groups: 0,
		}
		this.authorization = application.authorization || {
			create: [],
			read: [],
			update: [],
			delete: [],
		}
		this.owner = application.owner || ''
		this.active = application.active !== false
		this.created = application.created || ''
		this.updated = application.updated || ''
	}

	public validate(): SafeParseReturnType<TApplication, unknown> {
		const schema = z.object({
			id: z.number().optional(),
			uuid: z.string().optional(),
			name: z.string().min(1, 'Application name is required'),
			description: z.string().optional(),
			version: z.string().optional(),
			organisation: z.number().optional(),
			configurations: z.array(z.number()).optional(),
			registers: z.array(z.number()).optional(),
			schemas: z.array(z.number()).optional(),
			groups: z.array(z.string()).optional(),
			quota: z.object({
				storage: z.number().nullable().optional(),
				bandwidth: z.number().nullable().optional(),
				requests: z.number().nullable().optional(),
				users: z.number().nullable().optional(),
				groups: z.number().nullable().optional(),
			}).optional(),
			usage: z.object({
				storage: z.number().optional(),
				bandwidth: z.number().optional(),
				requests: z.number().optional(),
				users: z.number().optional(),
				groups: z.number().optional(),
			}).optional(),
			authorization: z.object({
				create: z.array(z.string()).optional(),
				read: z.array(z.string()).optional(),
				update: z.array(z.string()).optional(),
				delete: z.array(z.string()).optional(),
			}).optional(),
			owner: z.string().optional(),
			active: z.boolean().optional(),
			created: z.string().optional(),
			updated: z.string().optional(),
		})

		return schema.safeParse(this)
	}

}


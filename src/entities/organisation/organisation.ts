import { SafeParseReturnType, z } from 'zod'
import { TOrganisation } from './organisation.types'

export class Organisation implements TOrganisation {

	public id?: number
	public uuid?: string
	public name: string
	public slug?: string
	public description?: string
	public users?: string[]
	public groups?: string[]
	public isDefault?: boolean
	public active?: boolean
	public owner?: string
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

	public authorization?: TOrganisation['authorization']
	public created?: string
	public updated?: string

	constructor(organisation: TOrganisation) {
		this.id = organisation.id
		this.uuid = organisation.uuid || ''
		this.name = organisation.name || ''
		this.slug = organisation.slug || ''
		this.description = organisation.description || ''
		this.users = organisation.users || []
		this.groups = organisation.groups || []
		this.isDefault = organisation.isDefault || false
		this.active = organisation.active !== false
		this.owner = organisation.owner || ''
		this.quota = organisation.quota || {
			storage: null,
			bandwidth: null,
			requests: null,
			users: null,
			groups: null,
		}
		this.usage = organisation.usage || {
			storage: 0,
			bandwidth: 0,
			requests: 0,
			users: 0,
			groups: 0,
		}
		this.authorization = organisation.authorization || {
			register: { create: [], read: [], update: [], delete: [] },
			schema: { create: [], read: [], update: [], delete: [] },
			object: { create: [], read: [], update: [], delete: [] },
			view: { create: [], read: [], update: [], delete: [] },
			agent: { create: [], read: [], update: [], delete: [] },
			configuration: { create: [], read: [], update: [], delete: [] },
			application: { create: [], read: [], update: [], delete: [] },
			object_publish: [],
			agent_use: [],
			dashboard_view: [],
			llm_use: [],
		}
		this.created = organisation.created || ''
		this.updated = organisation.updated || ''
	}

	public validate(): SafeParseReturnType<TOrganisation, unknown> {
		const crudSchema = z.object({
			create: z.array(z.string()).optional(),
			read: z.array(z.string()).optional(),
			update: z.array(z.string()).optional(),
			delete: z.array(z.string()).optional(),
		})

		const schema = z.object({
			id: z.number().optional(),
			uuid: z.string().optional(),
			name: z.string().min(1, 'Organisation name is required'),
			slug: z.string().optional(),
			description: z.string().optional(),
			users: z.array(z.string()).optional(),
			groups: z.array(z.string()).optional(),
			isDefault: z.boolean().optional(),
			active: z.boolean().optional(),
			owner: z.string().optional(),
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
				register: crudSchema.optional(),
				schema: crudSchema.optional(),
				object: crudSchema.optional(),
				view: crudSchema.optional(),
				agent: crudSchema.optional(),
				configuration: crudSchema.optional(),
				application: crudSchema.optional(),
				object_publish: z.array(z.string()).optional(),
				agent_use: z.array(z.string()).optional(),
				dashboard_view: z.array(z.string()).optional(),
				llm_use: z.array(z.string()).optional(),
			}).optional(),
			created: z.string().optional(),
			updated: z.string().optional(),
		})

		return schema.safeParse(this)
	}

}

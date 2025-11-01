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
	public storageQuota?: number | null
	public bandwidthQuota?: number | null
	public requestQuota?: number | null
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
		this.storageQuota = application.storageQuota || null
		this.bandwidthQuota = application.bandwidthQuota || null
		this.requestQuota = application.requestQuota || null
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
			storageQuota: z.number().nullable().optional(),
			bandwidthQuota: z.number().nullable().optional(),
			requestQuota: z.number().nullable().optional(),
			owner: z.string().optional(),
			active: z.boolean().optional(),
			created: z.string().optional(),
			updated: z.string().optional(),
		})

		return schema.safeParse(this)
	}

}


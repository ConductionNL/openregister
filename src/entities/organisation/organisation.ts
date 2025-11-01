import { SafeParseReturnType, z } from 'zod'
import { TOrganisation } from './organisation.types'

export class Organisation implements TOrganisation {

	public id?: number
	public uuid?: string
	public name: string
	public slug?: string
	public description?: string
	public users?: string[]
	public userCount?: number
	public isDefault?: boolean
	public active?: boolean
	public owner?: string
	public storageQuota?: number | null
	public bandwidthQuota?: number | null
	public requestQuota?: number | null
	public created?: string
	public updated?: string

	constructor(organisation: TOrganisation) {
		this.id = organisation.id
		this.uuid = organisation.uuid || ''
		this.name = organisation.name || ''
		this.slug = organisation.slug || ''
		this.description = organisation.description || ''
		this.users = organisation.users || []
		this.userCount = organisation.userCount || 0
		this.isDefault = organisation.isDefault || false
		this.active = organisation.active !== false
		this.owner = organisation.owner || ''
		this.storageQuota = organisation.storageQuota || null
		this.bandwidthQuota = organisation.bandwidthQuota || null
		this.requestQuota = organisation.requestQuota || null
		this.created = organisation.created || ''
		this.updated = organisation.updated || ''
	}

	public validate(): SafeParseReturnType<TOrganisation, unknown> {
		const schema = z.object({
			id: z.number().optional(),
			uuid: z.string().optional(),
			name: z.string().min(1, 'Organisation name is required'),
			slug: z.string().optional(),
			description: z.string().optional(),
			users: z.array(z.string()).optional(),
			userCount: z.number().min(0).optional(),
			isDefault: z.boolean().optional(),
			active: z.boolean().optional(),
			owner: z.string().optional(),
			storageQuota: z.number().nullable().optional(),
			bandwidthQuota: z.number().nullable().optional(),
			requestQuota: z.number().nullable().optional(),
			created: z.string().optional(),
			updated: z.string().optional(),
		})

		return schema.safeParse(this)
	}

}

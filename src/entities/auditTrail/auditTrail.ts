import { SafeParseReturnType, z } from 'zod'
import { TAuditTrail } from './auditTrail.types'

export class AuditTrail implements TAuditTrail {

	public id: string
	public uuid: string
	public schema: number
	public register: number
	public object: number
	public action: string
	public changed: string
	public user: string
	public userName: string
	public session: string
	public request: string
	public ipAddress: string
	public version: string
	public created: string

	constructor(auditTrail: TAuditTrail) {
		this.id = auditTrail.id || null
		this.uuid = auditTrail.uuid || null
		this.schema = auditTrail.schema || 0
		this.register = auditTrail.register || 0
		this.object = auditTrail.object || 0
		this.action = auditTrail.action || ''
		this.changed = auditTrail.changed || ''
		this.user = auditTrail.user || ''
		this.userName = auditTrail.userName || ''
		this.session = auditTrail.session || ''
		this.request = auditTrail.request || ''
		this.ipAddress = auditTrail.ipAddress || ''
		this.version = auditTrail.version || ''
		this.created = auditTrail.created || ''
	}

	public validate(): SafeParseReturnType<TAuditTrail, unknown> {
		const schema = z.object({
			id: z.string().nullable(),
			uuid: z.string().uuid().nullable(),
			schema: z.number(),
			register: z.number(),
			object: z.number(),
			action: z.string(),
			changed: z.string(),
			user: z.string(),
			userName: z.string(),
			session: z.string(),
			request: z.string(),
			ipAddress: z.string(),
			version: z.string(),
			created: z.string(),
		})

		return schema.safeParse(this)
	}

}

import { SafeParseReturnType, z } from 'zod'
import { TAuditTrail } from './auditTrail.types'

export class AuditTrail implements TAuditTrail {

	public id: number
	public uuid: string
	public schema: number
	public register: number
	public object: number
	public objectUuid: string | null
	public registerUuid: string | null
	public schemaUuid: string | null
	public action: string
	public changed: object | array
	public user: string
	public userName: string
	public session: string
	public request: string
	public ipAddress: string
	public version: string | null
	public created: string
	public organisationId: string | null
	public organisationIdType: string | null
	public processingActivityId: string | null
	public processingActivityUrl: string | null
	public processingId: string | null
	public confidentiality: string | null
	public retentionPeriod: string | null
	public size: number

	constructor(auditTrail: TAuditTrail) {
		this.id = auditTrail.id || 0
		this.uuid = auditTrail.uuid || ''
		this.schema = auditTrail.schema || 0
		this.register = auditTrail.register || 0
		this.object = auditTrail.object || 0
		this.objectUuid = auditTrail.objectUuid || null
		this.registerUuid = auditTrail.registerUuid || null
		this.schemaUuid = auditTrail.schemaUuid || null
		this.action = auditTrail.action || ''
		this.changed = auditTrail.changed || []
		this.user = auditTrail.user || ''
		this.userName = auditTrail.userName || ''
		this.session = auditTrail.session || ''
		this.request = auditTrail.request || ''
		this.ipAddress = auditTrail.ipAddress || ''
		this.version = auditTrail.version || null
		this.created = auditTrail.created || ''
		this.organisationId = auditTrail.organisationId || null
		this.organisationIdType = auditTrail.organisationIdType || null
		this.processingActivityId = auditTrail.processingActivityId || null
		this.processingActivityUrl = auditTrail.processingActivityUrl || null
		this.processingId = auditTrail.processingId || null
		this.confidentiality = auditTrail.confidentiality || null
		this.retentionPeriod = auditTrail.retentionPeriod || null
		this.size = auditTrail.size || 0
	}

	public validate(): SafeParseReturnType<TAuditTrail, unknown> {
		const schema = z.object({
			id: z.number(),
			uuid: z.string().uuid(),
			schema: z.number(),
			register: z.number(),
			object: z.number(),
			objectUuid: z.string().nullable(),
			registerUuid: z.string().nullable(),
			schemaUuid: z.string().nullable(),
			action: z.string(),
			changed: z.union([z.object({}), z.array(z.any())]),
			user: z.string(),
			userName: z.string(),
			session: z.string(),
			request: z.string(),
			ipAddress: z.string(),
			version: z.string().nullable(),
			created: z.string(),
			organisationId: z.string().nullable(),
			organisationIdType: z.string().nullable(),
			processingActivityId: z.string().nullable(),
			processingActivityUrl: z.string().nullable(),
			processingId: z.string().nullable(),
			confidentiality: z.string().nullable(),
			retentionPeriod: z.string().nullable(),
			size: z.number(),
		})

		return schema.safeParse(this)
	}

}

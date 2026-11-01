import type { ZodSafeParseResult } from 'zod'
import type { TAuditTrail } from './auditTrail.types'

import { z } from 'zod'
import { withFallbacks } from '../withFallbacks.js'

const FALLBACKS = {
	id: 0,
	uuid: '',
	schema: 0,
	register: 0,
	object: 0,
	objectUuid: null,
	registerUuid: null,
	schemaUuid: null,
	action: '',
	changed: [],
	user: '',
	userName: '',
	session: '',
	request: '',
	ipAddress: '',
	version: null,
	created: '',
	organisationId: null,
	organisationIdType: null,
	processingActivityId: null,
	processingActivityUrl: null,
	processingId: null,
	confidentiality: null,
	retentionPeriod: null,
	size: 0,
}

export class AuditTrail implements TAuditTrail {
	public id!: number
	public uuid!: string
	public schema!: number
	public register!: number
	public object!: number
	public objectUuid!: string | null
	public registerUuid!: string | null
	public schemaUuid!: string | null
	public action!: string
	public changed!: object | unknown[]
	public user!: string
	public userName!: string
	public session!: string
	public request!: string
	public ipAddress!: string
	public version!: string | null
	public created!: string
	public organisationId!: string | null
	public organisationIdType!: string | null
	public processingActivityId!: string | null
	public processingActivityUrl!: string | null
	public processingId!: string | null
	public confidentiality!: string | null
	public retentionPeriod!: string | null
	public size!: number

	/**
	 * @param auditTrail
	 * @spec exclude Entity model field-copy boilerplate: copies typed fields off the input with || defaults; no standalone behavioural contract.
	 */
	constructor(auditTrail: TAuditTrail) {
		Object.assign(this, withFallbacks(auditTrail, FALLBACKS))
	}

	public validate(): ZodSafeParseResult<unknown> {
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

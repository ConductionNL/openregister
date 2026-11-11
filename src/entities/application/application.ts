import type { ZodSafeParseResult } from 'zod'
import type { TQuota, TUsage } from '../quotaUsage.types'
import type { TApplication } from './application.types'

import { z } from 'zod'
import { QUOTA_SCHEMA, USAGE_SCHEMA } from '../quotaUsage.js'
import { COPY, NO_QUOTA, NO_USAGE, withFallbacks } from '../withFallbacks.js'

const FALLBACKS = {
	id: COPY,
	uuid: '',
	name: '',
	description: '',
	version: '1.0.0',
	organisation: COPY,
	configurations: [],
	registers: [],
	schemas: [],
	groups: [],
	quota: NO_QUOTA,
	usage: NO_USAGE,
	authorization: {
		create: [],
		read: [],
		update: [],
		delete: [],
	},
	owner: '',
	active: COPY,
	created: '',
	updated: '',
}

const APPLICATION_SCHEMA = z.object({
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
	quota: QUOTA_SCHEMA,
	usage: USAGE_SCHEMA,
	authorization: z
		.object({
			create: z.array(z.string()).optional(),
			read: z.array(z.string()).optional(),
			update: z.array(z.string()).optional(),
			delete: z.array(z.string()).optional(),
		})
		.optional(),
	owner: z.string().optional(),
	active: z.boolean().optional(),
	created: z.string().optional(),
	updated: z.string().optional(),
})

export class Application implements TApplication {
	public id?: number
	public uuid?: string
	public name!: string
	public description?: string
	public version?: string
	public organisation?: number
	public configurations?: number[]
	public registers?: number[]
	public schemas?: number[]
	public groups?: string[]
	public quota?: TQuota
	public usage?: TUsage

	public authorization?: TApplication['authorization']
	public owner?: string
	public active?: boolean
	public created?: string
	public updated?: string

	/**
	 * @param application
	 * @spec exclude Entity model field-copy boilerplate: copies typed fields off the input with || defaults; no standalone behavioural contract.
	 */
	constructor(application: TApplication) {
		Object.assign(this, withFallbacks(application, FALLBACKS))
		this.active = application.active !== false
	}

	public validate(): ZodSafeParseResult<unknown> {
		return APPLICATION_SCHEMA.safeParse(this)
	}
}

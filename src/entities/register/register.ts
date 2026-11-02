import type { ZodSafeParseResult } from 'zod'
import type { TRegister } from './register.types'

import { z } from 'zod'
import { COPY, NO_QUOTA, NO_USAGE, withFallbacks } from '../withFallbacks.js'

const FALLBACKS = {
	id: '',
	title: COPY,
	description: COPY,
	schemas: [],
	source: '',
	databaseId: COPY,
	tablePrefix: '',
	updated: '',
	created: '',
	slug: '',
	groups: [],
	languages: [],
	quota: NO_QUOTA,
	usage: NO_USAGE,
	stats: COPY,
}

export class Register implements TRegister {
	public id!: string
	public title!: string
	public description!: string
	public schemas!: string[]
	public source!: string
	public databaseId!: string
	public tablePrefix!: string
	public updated!: string
	public created!: string
	public slug!: string
	public groups?: string[]
	public languages?: string[]
	public quota?: TRegister['quota']
	public usage?: TRegister['usage']
	public stats?: TRegister['stats']

	/**
	 * @param register
	 * @spec exclude Entity model field-copy boilerplate: copies typed fields off the input with || defaults; no standalone behavioural contract.
	 */
	constructor(register: TRegister) {
		Object.assign(this, withFallbacks(register, FALLBACKS))
	}

	public validate(): ZodSafeParseResult<unknown> {
		const schema = z.object({
			id: z.string().min(1),
			title: z.string().min(1),
			description: z.string(),
			schemas: z.array(z.string()),
			source: z.string(),
			databaseId: z.string().min(1),
			tablePrefix: z.string(),
			slug: z.string().min(1),
		})

		return schema.safeParse(this)
	}

	public getFullTablePrefix(databasePrefix: string): string {
		return `${databasePrefix}${this.tablePrefix}`.replace(/_{2,}/g, '_')
	}
}

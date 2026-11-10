import type { ZodSafeParseResult } from 'zod'
import type { TSource } from './source.types'

import { z } from 'zod'
import { withFallbacks } from '../withFallbacks.js'

const FALLBACKS = {
	id: '',
	title: '',
	description: '',
	databaseUrl: '',
	type: 'internal',
	authConfig: null,
	updated: '',
	created: '',
}

export class Source implements TSource {
	public id!: string | number
	public title!: string
	public description!: string
	public databaseUrl!: string
	public type!: 'internal' | 'mongodb' | 'database'
	public authConfig!: Record<string, unknown> | null
	public updated!: string
	public created!: string

	/**
	 * @param source
	 * @spec exclude Entity model field-copy boilerplate: copies typed fields off the input with || defaults; no standalone behavioural contract.
	 */
	constructor(source: TSource) {
		Object.assign(this, withFallbacks(source, FALLBACKS))
	}

	public validate(): ZodSafeParseResult<unknown> {
		const schema = z.object({
			id: z.union([z.string(), z.number()]),
			title: z.string().min(1),
			description: z.string(),
			databaseUrl: z.string().url(),
			type: z.enum(['internal', 'mongodb', 'database']),
		})

		return schema.safeParse(this)
	}
}

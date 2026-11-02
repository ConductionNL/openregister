/**
 * View entity class
 *
 * @package
 * @author   Conduction Development Team <dev@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license  EUPL-1.2
 * @version  1.0.0
 */

import type { ZodSafeParseResult } from 'zod'
import type { TView } from './view.types'

import { z } from 'zod'
import { COPY, NO_QUOTA, NO_USAGE, withFallbacks } from '../withFallbacks.js'

const FALLBACKS = {
	id: COPY,
	uuid: '',
	name: '',
	description: '',
	owner: '',
	isPublic: false,
	isDefault: false,
	query: {},
	favoredBy: [],
	quota: NO_QUOTA,
	usage: NO_USAGE,
	created: '',
	updated: '',
}

export class View implements TView {
	public id?: number
	public uuid?: string
	public name!: string
	public description?: string
	public owner?: string
	public isPublic?: boolean
	public isDefault?: boolean
	public query?: Record<string, any>
	public favoredBy?: string[]
	public quota?: TView['quota']
	public usage?: TView['usage']
	public created?: string
	public updated?: string

	/**
	 * @param view
	 * @spec exclude Entity model field-copy boilerplate: copies typed fields off the input with || defaults; no standalone behavioural contract.
	 */
	constructor(view: TView) {
		Object.assign(this, withFallbacks(view, FALLBACKS))
	}

	public validate(): ZodSafeParseResult<unknown> {
		const schema = z.object({
			id: z.number().optional(),
			uuid: z.string().optional(),
			name: z.string().min(1, 'View name is required'),
			description: z.string().optional(),
			owner: z.string().optional(),
			isPublic: z.boolean().optional(),
			isDefault: z.boolean().optional(),
			query: z.record(z.string(), z.any()).optional(),
			favoredBy: z.array(z.string()).optional(),
			quota: z
				.object({
					storage: z.number().nullable().optional(),
					bandwidth: z.number().nullable().optional(),
					requests: z.number().nullable().optional(),
					users: z.number().nullable().optional(),
					groups: z.number().nullable().optional(),
				})
				.optional(),
			usage: z
				.object({
					storage: z.number().optional(),
					bandwidth: z.number().optional(),
					requests: z.number().optional(),
					users: z.number().optional(),
					groups: z.number().optional(),
				})
				.optional(),
			created: z.string().optional(),
			updated: z.string().optional(),
		})

		return schema.safeParse(this)
	}
}

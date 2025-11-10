/**
 * View entity class
 *
 * @package
 * @author   Conduction Development Team <dev@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license  EUPL-1.2
 * @version  1.0.0
 */

import { SafeParseReturnType, z } from 'zod'
import { TView } from './view.types'

export class View implements TView {

	public id?: number
	public uuid?: string
	public name: string
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

	constructor(view: TView) {
		this.id = view.id
		this.uuid = view.uuid || ''
		this.name = view.name || ''
		this.description = view.description || ''
		this.owner = view.owner || ''
		this.isPublic = view.isPublic || false
		this.isDefault = view.isDefault || false
		this.query = view.query || {}
		this.favoredBy = view.favoredBy || []
		this.quota = view.quota || {
			storage: null,
			bandwidth: null,
			requests: null,
			users: null,
			groups: null,
		}
		this.usage = view.usage || {
			storage: 0,
			bandwidth: 0,
			requests: 0,
			users: 0,
			groups: 0,
		}
		this.created = view.created || ''
		this.updated = view.updated || ''
	}

	public validate(): SafeParseReturnType<TView, unknown> {
		const schema = z.object({
			id: z.number().optional(),
			uuid: z.string().optional(),
			name: z.string().min(1, 'View name is required'),
			description: z.string().optional(),
			owner: z.string().optional(),
			isPublic: z.boolean().optional(),
			isDefault: z.boolean().optional(),
			query: z.record(z.any()).optional(),
			favoredBy: z.array(z.string()).optional(),
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
			created: z.string().optional(),
			updated: z.string().optional(),
		})

		return schema.safeParse(this)
	}

}

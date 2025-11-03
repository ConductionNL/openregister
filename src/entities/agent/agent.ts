/**
 * Agent entity class
 *
 * @module Entities
 * @package
 * @author   Conduction Development Team <dev@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license  EUPL-1.2
 * @version  1.0.0
 * @see      https://www.openregister.nl
 */

import { SafeParseReturnType, z } from 'zod'
import { TAgent } from './agent.types'

export class Agent implements TAgent {

	public id?: number
	public uuid?: string
	public name: string
	public description?: string
	public type?: string
	public provider?: string
	public model?: string
	public prompt?: string
	public temperature?: number
	public maxTokens?: number
	public configuration?: Record<string, any>
	public organisation?: number
	public owner?: string
	public active?: boolean
	public enableRag?: boolean
	public ragSearchMode?: string
	public ragNumSources?: number
	public ragIncludeFiles?: boolean
	public ragIncludeObjects?: boolean
	public quota?: TAgent['quota']
	public usage?: TAgent['usage']
	public created?: string
	public updated?: string

	constructor(agent: TAgent) {
		this.id = agent.id
		this.uuid = agent.uuid || ''
		this.name = agent.name || ''
		this.description = agent.description || ''
		this.type = agent.type || 'chat'
		this.provider = agent.provider || ''
		this.model = agent.model || ''
		this.prompt = agent.prompt || ''
		this.temperature = agent.temperature ?? 0.7
		this.maxTokens = agent.maxTokens || 1000
		this.configuration = agent.configuration || {}
		this.organisation = agent.organisation
		this.owner = agent.owner || ''
		this.active = agent.active !== false
		this.enableRag = agent.enableRag || false
		this.ragSearchMode = agent.ragSearchMode || 'hybrid'
		this.ragNumSources = agent.ragNumSources || 5
		this.ragIncludeFiles = agent.ragIncludeFiles || false
		this.ragIncludeObjects = agent.ragIncludeObjects || false
		this.quota = agent.quota || {
			storage: null,
			bandwidth: null,
			requests: null,
			users: null,
			groups: null,
		}
		this.usage = agent.usage || {
			storage: 0,
			bandwidth: 0,
			requests: 0,
			users: 0,
			groups: 0,
		}
		this.created = agent.created || ''
		this.updated = agent.updated || ''
	}

	public validate(): SafeParseReturnType<TAgent, unknown> {
		const schema = z.object({
			id: z.number().optional(),
			uuid: z.string().optional(),
			name: z.string().min(1, 'Agent name is required'),
			description: z.string().optional(),
			type: z.string().optional(),
			provider: z.string().optional(),
			model: z.string().optional(),
			prompt: z.string().optional(),
			temperature: z.number().min(0).max(2).optional(),
			maxTokens: z.number().positive().optional(),
			configuration: z.record(z.any()).optional(),
			organisation: z.number().optional(),
			owner: z.string().optional(),
			active: z.boolean().optional(),
			enableRag: z.boolean().optional(),
			ragSearchMode: z.string().optional(),
			ragNumSources: z.number().positive().optional(),
			ragIncludeFiles: z.boolean().optional(),
			ragIncludeObjects: z.boolean().optional(),
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

/**
 * Conversation entity class
 *
 * @category Entities
 * @package  openregister
 * @author   Conduction Development Team <dev@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license  EUPL-1.2
 * @version  1.0.0
 * @link     https://www.openregister.nl
 */

import { SafeParseReturnType, z } from 'zod'
import { TConversation } from './conversation.types'

export class Conversation implements TConversation {

	public id?: number
	public uuid?: string
	public title?: string
	public userId?: string
	public organisation?: number
	public agentId?: number
	public metadata?: Record<string, any>
	public deletedAt?: string | null
	public created?: string
	public updated?: string
	public messages?: any[]
	public messageCount?: number

	constructor(conversation: TConversation) {
		this.id = conversation.id
		this.uuid = conversation.uuid || ''
		this.title = conversation.title || 'New Conversation'
		this.userId = conversation.userId || ''
		this.organisation = conversation.organisation
		this.agentId = conversation.agentId
		this.metadata = conversation.metadata || {}
		this.deletedAt = conversation.deletedAt || null
		this.created = conversation.created || new Date().toISOString()
		this.updated = conversation.updated || new Date().toISOString()
		this.messages = conversation.messages || []
		this.messageCount = conversation.messageCount || 0
	}

	public validate(): SafeParseReturnType<TConversation, unknown> {
		const schema = z.object({
			id: z.number().optional(),
			uuid: z.string().optional(),
			title: z.string().optional(),
			userId: z.string().optional(),
			organisation: z.number().optional(),
			agentId: z.number().optional(),
			metadata: z.record(z.any()).optional(),
			deletedAt: z.string().nullable().optional(),
			created: z.string().optional(),
			updated: z.string().optional(),
			messages: z.array(z.any()).optional(),
			messageCount: z.number().optional(),
		})

		return schema.safeParse(this)
	}

}


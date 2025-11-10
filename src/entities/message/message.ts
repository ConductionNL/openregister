/**
 * Message entity class
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
import { TMessage } from './message.types'

export class Message implements TMessage {

	public id?: number
	public uuid?: string
	public conversationId?: number
	public role: 'user' | 'assistant' | 'system'
	public content: string
	public sources?: any[]
	public created?: string

	constructor(message: TMessage) {
		this.id = message.id
		this.uuid = message.uuid || ''
		this.conversationId = message.conversationId
		this.role = message.role || 'user'
		this.content = message.content || ''
		this.sources = message.sources || []
		this.created = message.created || new Date().toISOString()
	}

	public validate(): SafeParseReturnType<TMessage, unknown> {
		const schema = z.object({
			id: z.number().optional(),
			uuid: z.string().optional(),
			conversationId: z.number().optional(),
			role: z.enum(['user', 'assistant', 'system']),
			content: z.string().min(1, 'Message content is required'),
			sources: z.array(z.any()).optional(),
			created: z.string().optional(),
		})

		return schema.safeParse(this)
	}

}


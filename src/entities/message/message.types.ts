/**
 * Message entity type definitions
 *
 * @category Entities
 * @package  openregister
 * @author   Conduction Development Team <dev@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license  EUPL-1.2
 * @version  1.0.0
 * @link     https://www.openregister.nl
 */

export type TMessage = {
	id?: number
	uuid?: string
	conversationId?: number
	role: 'user' | 'assistant' | 'system'
	content: string
	sources?: TMessageSource[]
	created?: string
}

export type TMessageSource = {
	type: 'file' | 'object'
	id: string
	name: string
	relevance?: number
	excerpt?: string
}

export type TMessagePath = {
	messageId?: string
}


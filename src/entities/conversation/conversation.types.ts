/**
 * Conversation entity type definitions
 *
 * @category Entities
 * @package  openregister
 * @author   Conduction Development Team <dev@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license  EUPL-1.2
 * @version  1.0.0
 * @link     https://www.openregister.nl
 */

export type TConversation = {
	id?: number
	uuid?: string
	title?: string
	userId?: string
	organisation?: number
	agentId?: number
	metadata?: Record<string, any>
	deletedAt?: string | null
	created?: string
	updated?: string
	// Populated from API
	messages?: any[]
	messageCount?: number
}

export type TConversationPath = {
	conversationId?: string
}


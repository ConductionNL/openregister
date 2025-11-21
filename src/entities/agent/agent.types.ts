/**
 * Agent entity type definitions
 *
 * @package
 * @author   Conduction Development Team <dev@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license  EUPL-1.2
 * @version  1.0.0
 */

export type TAgent = {
	id?: number
	uuid?: string
	name: string
	description?: string
	type?: string
	provider?: string
	model?: string
	prompt?: string
	temperature?: number
	maxTokens?: number
	configuration?: Record<string, any>
	organisation?: number
	owner?: string
	active?: boolean
	enableRag?: boolean
	ragSearchMode?: string
	ragNumSources?: number
	ragIncludeFiles?: boolean
	ragIncludeObjects?: boolean
	requestQuota?: number
	tokenQuota?: number
	groups?: string[]
	// New properties for fine-grained control
	views?: string[]
	searchFiles?: boolean
	searchObjects?: boolean
	isPrivate?: boolean
	invitedUsers?: string[]
	// Tool support
	tools?: string[] // Array of enabled tool names: 'register', 'schema', 'objects'
	user?: string // User ID for cron/background scenarios
	created?: string
	updated?: string
}

export type TAgentPath = {
	agentId?: string
}

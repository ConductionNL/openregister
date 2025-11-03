/**
 * Agent entity type definitions
 *
 * @module Entities
 * @package
 * @author   Conduction Development Team <dev@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license  EUPL-1.2
 * @version  1.0.0
 * @see      https://www.openregister.nl
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
	quota?: {
		storage?: number | null
		bandwidth?: number | null
		requests?: number | null
		users?: number | null
		groups?: number | null
	}
	usage?: {
		storage?: number
		bandwidth?: number
		requests?: number
		users?: number
		groups?: number
	}
	created?: string
	updated?: string
}

export type TAgentPath = {
	agentId?: string
}

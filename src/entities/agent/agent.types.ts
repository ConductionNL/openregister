/**
 * Agent entity type definitions
 *
 * @category Entities
 * @package  openregister
 * @author   Conduction Development Team <dev@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license  EUPL-1.2
 * @version  1.0.0
 * @link     https://www.openregister.nl
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
	created?: string
	updated?: string
}

export type TAgentPath = {
	agentId?: string
}



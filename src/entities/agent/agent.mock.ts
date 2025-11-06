/**
 * Agent entity mock data for testing
 *
 * @category Entities
 * @package  openregister
 * @author   Conduction Development Team <dev@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license  EUPL-1.2
 * @version  1.0.0
 * @link     https://www.openregister.nl
 */

import { TAgent } from './agent.types'
import { Agent } from './agent'

export const mockAgentData = (): TAgent[] => [
	{
		id: 1,
		uuid: '15551d6f-44e3-43f3-a9d2-59e583c91eb0',
		name: 'Customer Support Agent',
		description: 'An AI agent for handling customer support inquiries',
		type: 'chat',
		provider: 'openai',
		model: 'gpt-4o-mini',
		prompt: 'You are a helpful customer support agent. Be friendly, professional, and assist users with their questions.',
		temperature: 0.7,
		maxTokens: 1000,
		active: true,
		enableRag: true,
		ragSearchMode: 'hybrid',
		ragNumSources: 5,
		ragIncludeFiles: true,
		ragIncludeObjects: true,
		created: '2024-11-02T10:00:00Z',
		updated: '2024-11-02T10:00:00Z',
	},
	{
		id: 2,
		uuid: '8c87a20c-b3f1-4d13-bf3e-789234d1c81d',
		name: 'Data Analysis Agent',
		description: 'Analyzes data and provides insights',
		type: 'analysis',
		provider: 'openai',
		model: 'gpt-4o',
		prompt: 'You are a data analysis expert. Provide clear insights and recommendations based on data.',
		temperature: 0.3,
		maxTokens: 2000,
		active: true,
		enableRag: false,
		created: '2024-11-01T14:30:00Z',
		updated: '2024-11-01T14:30:00Z',
	},
]

export const mockAgent = (data: TAgent[] = mockAgentData()): Agent[] => data.map(item => new Agent(item))





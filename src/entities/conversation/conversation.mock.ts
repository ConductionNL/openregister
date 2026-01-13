/**
 * Conversation entity mock data
 *
 * @package
 * @author   Conduction Development Team <dev@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license  EUPL-1.2
 * @version  1.0.0
 */

import { Conversation } from './conversation'
import { TConversation } from './conversation.types'

export const mockConversationData = (): TConversation[] => [
	{
		id: 1,
		uuid: '550e8400-e29b-41d4-a716-446655440001',
		title: 'Help with User Management',
		userId: 'admin',
		organisation: 1,
		agentId: 1,
		metadata: { source: 'web' },
		deletedAt: null,
		created: '2024-01-15T10:00:00Z',
		updated: '2024-01-15T10:30:00Z',
		messageCount: 5,
	},
	{
		id: 2,
		uuid: '550e8400-e29b-41d4-a716-446655440002',
		title: 'API Documentation Questions',
		userId: 'admin',
		organisation: 1,
		agentId: 2,
		metadata: { tags: ['api', 'documentation'] },
		deletedAt: null,
		created: '2024-01-16T14:00:00Z',
		updated: '2024-01-16T14:45:00Z',
		messageCount: 8,
	},
	{
		id: 3,
		uuid: '550e8400-e29b-41d4-a716-446655440003',
		title: 'Database Schema Design',
		userId: 'admin',
		organisation: 1,
		agentId: 1,
		metadata: { priority: 'high' },
		deletedAt: null,
		created: '2024-01-17T09:00:00Z',
		updated: '2024-01-17T09:20:00Z',
		messageCount: 3,
	},
]

export const mockConversation = (data: TConversation[] = mockConversationData()): Conversation[] => data.map((item) => new Conversation(item))

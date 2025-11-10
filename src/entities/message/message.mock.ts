/**
 * Message entity mock data
 *
 * @package
 * @author   Conduction Development Team <dev@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license  EUPL-1.2
 * @version  1.0.0
 */

import { Message } from './message'
import { TMessage } from './message.types'

export const mockMessageData = (): TMessage[] => [
	{
		id: 1,
		uuid: '660e8400-e29b-41d4-a716-446655440001',
		conversationId: 1,
		role: 'user',
		content: 'How do I add a new user to the system?',
		created: '2024-01-15T10:00:00Z',
	},
	{
		id: 2,
		uuid: '660e8400-e29b-41d4-a716-446655440002',
		conversationId: 1,
		role: 'assistant',
		content: 'To add a new user, navigate to the Users section and click on "Add User".',
		sources: [
			{
				type: 'file',
				id: 'doc-001',
				name: 'user-management.pdf',
				relevance: 0.95,
				excerpt: 'User management section explains...',
			},
		],
		created: '2024-01-15T10:00:15Z',
	},
	{
		id: 3,
		uuid: '660e8400-e29b-41d4-a716-446655440003',
		conversationId: 1,
		role: 'user',
		content: 'What permissions should I assign?',
		created: '2024-01-15T10:01:00Z',
	},
	{
		id: 4,
		uuid: '660e8400-e29b-41d4-a716-446655440004',
		conversationId: 1,
		role: 'assistant',
		content: 'The default permissions for a new user include read access. You can customize permissions based on the role.',
		sources: [
			{
				type: 'object',
				id: 'role-001',
				name: 'Default User Role',
				relevance: 0.88,
			},
		],
		created: '2024-01-15T10:01:20Z',
	},
]

export const mockMessage = (data: TMessage[] = mockMessageData()): Message[] => data.map((item) => new Message(item))

import { AuditTrail } from './auditTrail'
import { TAuditTrail } from './auditTrail.types'

export const mockAuditTrailData = (): TAuditTrail[] => [
	{
		id: '1234a1e5-b54d-43ad-abd1-4b5bff5fcd3f',
		uuid: 'uuid-1234a1e5-b54d-43ad-abd1-4b5bff5fcd3f',
		schema: 1,
		register: 1,
		object: 1,
		action: 'create',
		changed: JSON.stringify({ key: 'value' }),
		user: 'user1',
		userName: 'User One',
		session: 'session1',
		request: 'request1',
		ipAddress: '127.0.0.1',
		version: '1.0',
		created: new Date().toISOString(),
	},
	{
		id: '5678a1e5-b54d-43ad-abd1-4b5bff5fcd3f',
		uuid: 'uuid-5678a1e5-b54d-43ad-abd1-4b5bff5fcd3f',
		schema: 2,
		register: 2,
		object: 2,
		action: 'update',
		changed: JSON.stringify({ key: 'value' }),
		user: 'user2',
		userName: 'User Two',
		session: 'session2',
		request: 'request2',
		ipAddress: '127.0.0.2',
		version: '1.1',
		created: new Date().toISOString(),
	},
]

export const mockAuditTrail = (data: TAuditTrail[] = mockAuditTrailData()): TAuditTrail[] => data.map(item => new AuditTrail(item))

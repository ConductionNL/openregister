import { Organisation } from './organisation'
import { TOrganisation } from './organisation.types'

export const mockOrganisationData = (): TOrganisation[] => [
	{
		id: 1,
		uuid: '123e4567-e89b-12d3-a456-426614174000',
		name: 'Default Organisation',
		description: 'Default organisation for users without specific organisation membership',
		users: ['alice', 'bob', 'charlie'],
		userCount: 3,
		isDefault: true,
		owner: 'system',
		created: new Date().toISOString(),
		updated: new Date().toISOString(),
	},
	{
		id: 2,
		uuid: '456e7890-e89b-12d3-a456-426614174001',
		name: 'ACME Corporation',
		description: 'Main corporate organisation for ACME Inc.',
		users: ['alice', 'diana'],
		userCount: 2,
		isDefault: false,
		owner: 'alice',
		created: new Date().toISOString(),
		updated: new Date().toISOString(),
	},
	{
		id: 3,
		uuid: '789e0123-e89b-12d3-a456-426614174002',
		name: 'Tech Startup',
		description: 'Innovation and development team organisation',
		users: ['bob', 'eve'],
		userCount: 2,
		isDefault: false,
		owner: 'bob',
		created: new Date().toISOString(),
		updated: new Date().toISOString(),
	},
]

export const mockOrganisation = (data: TOrganisation[] = mockOrganisationData()): Organisation[] => data.map(item => new Organisation(item))

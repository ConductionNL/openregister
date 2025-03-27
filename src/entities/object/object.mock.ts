import { ObjectEntity } from './object'
import { TObject } from './object.types'

export const mockObjectData = (): TObject[] => [
	{
		'@self': {
			id: '1234a1e5-b54d-43ad-abd1-4b5bff5fcd3f',
			uuid: 'uuid-1234a1e5-b54d-43ad-abd1-4b5bff5fcd3f',
			uri: 'https://example.com/character/1234a1e5-b54d-43ad-abd1-4b5bff5fcd3f',
			register: 'Character Register',
			schema: 'character_schema',
			relations: JSON.stringify({ key: 'value' }),
			files: JSON.stringify({ key: 'value' }),
			folder: 'https://example.com/character/1234a1e5-b54d-43ad-abd1-4b5bff5fcd3f',
			created: new Date().toISOString(),
			updated: new Date().toISOString(),
			locked: ['token1', 'token2'],
			owner: 'user1',
		},
	},
	{
		'@self': {
			id: '5678a1e5-b54d-43ad-abd1-4b5bff5fcd3f',
			uuid: 'uuid-5678a1e5-b54d-43ad-abd1-4b5bff5fcd3f',
			uri: 'https://example.com/item/5678a1e5-b54d-43ad-abd1-4b5bff5fcd3f',
			register: 'Item Register',
			schema: 'item_schema',
			relations: JSON.stringify({ key: 'value' }),
			files: JSON.stringify({ key: 'value' }),
			folder: 'https://example.com/item/5678a1e5-b54d-43ad-abd1-4b5bff5fcd3f',
			created: new Date().toISOString(),
			updated: new Date().toISOString(),
			locked: null,
			owner: 'user2',
		},
	},
]

export const mockObject = (data: TObject[] = mockObjectData()): TObject[] => data.map(item => new ObjectEntity(item))

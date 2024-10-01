import { ObjectEntity } from './object'
import { TObject } from './object.types'

export const mockObjectData = (): TObject[] => [
	{
		id: '1234a1e5-b54d-43ad-abd1-4b5bff5fcd3f',
		uuid: 'uuid-1234a1e5-b54d-43ad-abd1-4b5bff5fcd3f',
		register: 'Character Register',
		schema: 'character_schema',
		object: JSON.stringify({ key: 'value' }),
		created: new Date().toISOString(),
		updated: new Date().toISOString(),
	},
	{
		id: '5678a1e5-b54d-43ad-abd1-4b5bff5fcd3f',
		uuid: 'uuid-5678a1e5-b54d-43ad-abd1-4b5bff5fcd3f',
		register: 'Item Register',
		schema: 'item_schema',
		object: JSON.stringify({ key: 'value' }),
		created: new Date().toISOString(),
		updated: new Date().toISOString(),
	},
]

export const mockObject = (data: TObject[] = mockObjectData()): TObject[] => data.map(item => new ObjectEntity(item))

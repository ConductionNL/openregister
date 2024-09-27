import { Register } from './register'
import { TRegister } from './register.types'

export const mockRegisterData = (): TRegister[] => [
	{
		id: '1234a1e5-b54d-43ad-abd1-4b5bff5fcd3f',
		title: 'Character Register',
		description: 'Stores character data for the game',
		schemas: ['5678a1e5-b54d-43ad-abd1-4b5bff5fcd3f'],
		source: '1',
		databaseId: 'db1-a1e5-b54d-43ad-abd1-4b5bff5fcd3f',
		tablePrefix: 'character_',
		created: new Date().toISOString(),
		updated: new Date().toISOString(),
	},
	{
		id: '5678a1e5-b54d-43ad-abd1-4b5bff5fcd3f',
		title: 'Item Register',
		description: 'Stores item data for the game',
		schemas: ['9012a1e5-b54d-43ad-abd1-4b5bff5fcd3f'],
		source: '1',
		databaseId: 'db2-a1e5-b54d-43ad-abd1-4b5bff5fcd3f',
		tablePrefix: 'item_',
		created: new Date().toISOString(),
		updated: new Date().toISOString(),
	},
]

export const mockRegister = (data: TRegister[] = mockRegisterData()): TRegister[] => data.map(item => new Register(item))

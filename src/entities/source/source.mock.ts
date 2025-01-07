import { Source } from './source'
import { TSource } from './source.types'

export const mockSourceData = (): TSource[] => [
	{
		id: 1,
		title: 'Main MongoDB Database',
		description: 'Primary database for user data',
		databaseUrl: 'mongodb://user:password@localhost:27017/maindb',
		type: 'mongodb',
		updated: new Date().toISOString(),
		created: new Date().toISOString(),
	},
]

export const mockSource = (data: TSource[] = mockSourceData()): TSource[] => data.map(item => new Source(item))

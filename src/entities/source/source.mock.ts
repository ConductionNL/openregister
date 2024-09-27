import { Source } from './source'
import { TSource } from './source.types'

export const mockSourceData = (): TSource[] => [
	{
		id: 1,
		title: 'Main PostgreSQL Database',
		description: 'Primary database for user data',
		databaseUrl: 'postgresql://user:password@localhost:5432/maindb',
		type: 'postgresql',
		updated: new Date().toISOString(),
		created: new Date().toISOString(),
	},
]

export const mockSource = (data: TSource[] = mockSourceData()): TSource[] => data.map(item => new Source(item))

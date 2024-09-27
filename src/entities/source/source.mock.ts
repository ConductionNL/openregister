import { Source } from './source'
import { TSource } from './source.types'

export const mockSourceData = (): TSource[] => [
	{
		id: 1,
		title: 'Main PostgreSQL Database',
		description: 'Primary database for user data',
		databaseUrl: 'postgresql://user:password@localhost:5432/maindb',
		type: 'postgresql',
		updated: {
			date: '2024-03-15 09:30:00.000000',
			timezone_type: 3,
			timezone: 'UTC',
		},
		created: {
			date: '2024-03-15 09:30:00.000000',
			timezone_type: 3,
			timezone: 'UTC',
		},
	},
	// ... existing code ...
]

export const mockSource = (data: TSource[] = mockSourceData()): TSource[] => data.map(item => new Source(item))

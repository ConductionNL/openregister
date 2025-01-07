import { Database } from './database'
import { TDatabase } from './database.types'

export const mockDatabaseData = (): TDatabase[] => [
	{
		id: 'db1-a1e5-b54d-43ad-abd1-4b5bff5fcd3f',
		name: 'Main MySQL Database',
		url: 'mysql://user:password@localhost:3306/main_db',
		tablePrefix: 'myorganisation_',
	},
	{
		id: 'db2-a1e5-b54d-43ad-abd1-4b5bff5fcd3f',
		name: 'Analytics PostgreSQL Database',
		url: 'postgresql://analytics_user:securepass456@analytics.example.com:5432/analytics_db?sslmode=require',
		tablePrefix: 'analytics_',
	},
	{
		id: 'db3-a1e5-b54d-43ad-abd1-4b5bff5fcd3f',
		name: 'MongoDB Database',
		url: 'mongodb://user:pass@mongo.example.com:27017/mydb',
		tablePrefix: '',
	},
]

export const mockDatabase = (data: TDatabase[] = mockDatabaseData()): TDatabase[] => data.map(item => new Database(item))

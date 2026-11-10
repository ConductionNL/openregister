import type { TSource } from './source.types'

import { Source } from './source'

/**
 *
 */
export function mockSourceData(): TSource[] {
	return [
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
}

/**
 *
 * @param data
 */
export function mockSource(data: TSource[] = mockSourceData()): TSource[] {
	return data.map((item) => new Source(item))
}

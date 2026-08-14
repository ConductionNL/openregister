import type { SafeParseReturnType } from 'zod'
import type { TDatabase } from './database.types'

import { z } from 'zod'

export class Database implements TDatabase {
	public id: string
	public name: string
	public url: string
	public tablePrefix: string

	/**
	 * @param database
	 * @spec exclude Entity model field-copy boilerplate: copies typed fields off the input with || defaults; no standalone behavioural contract.
	 */
	constructor(database: TDatabase) {
		this.id = database.id || ''
		this.name = database.name
		this.url = database.url
		this.tablePrefix = database.tablePrefix || ''
	}

	public validate(): SafeParseReturnType<TDatabase, unknown> {
		const schema = z.object({
			id: z.string().min(1),
			name: z.string().min(1),
			url: z.string().url(),
			tablePrefix: z.string(),
		})

		return schema.safeParse(this)
	}

	// Helper method to get the database type from the URL
	public getDatabaseType(): string {
		const urlObj = new URL(this.url)
		return urlObj.protocol.replace(':', '')
	}
}

import { SafeParseReturnType, z } from 'zod'
import { TConfiguration } from './configuration.types'

/**
 * Entity class representing a Configuration with validation
 */
export class ConfigurationEntity implements TConfiguration {

	id: string
	title: string
	description: string | null
	type: string
	owner: string
	created: string
	updated: string

	constructor(configuration: TConfiguration) {
		this.id = configuration.id || ''
		this.title = configuration.title || ''
		this.description = configuration.description || null
		this.type = configuration.type || ''
		this.owner = configuration.owner || ''
		this.created = configuration.created || ''
		this.updated = configuration.updated || ''
	}

	/**
	 * Validates the configuration against a schema
	 */
	public validate(): SafeParseReturnType<TConfiguration, unknown> {
		const schema = z.object({
			id: z.string().min(1),
			title: z.string().min(1),
			description: z.string().nullable(),
			type: z.string().min(1),
			owner: z.string().min(1),
			created: z.string().min(1),
			updated: z.string().min(1),
		})

		return schema.safeParse(this)
	}

}

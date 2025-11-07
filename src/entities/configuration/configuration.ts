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
	application: string
	owner: string
	organisation: number | null
	registers?: number[]
	schemas?: number[]
	created: string
	updated: string

	constructor(configuration: TConfiguration) {
		this.id = configuration.id || ''
		this.title = configuration.title || ''
		this.description = configuration.description || null
		this.type = configuration.type || ''
		this.application = configuration.application || ''
		this.owner = configuration.owner || ''
		this.organisation = configuration.organisation || null
		this.registers = configuration.registers || []
		this.schemas = configuration.schemas || []
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
			type: z.string(),
			application: z.string(),
			owner: z.string(),
			organisation: z.number().nullable(),
			registers: z.array(z.number()).optional(),
			schemas: z.array(z.number()).optional(),
			created: z.string(),
			updated: z.string(),
		})

		return schema.safeParse(this)
	}

}

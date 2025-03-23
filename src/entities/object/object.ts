import { SafeParseReturnType, z } from 'zod'
import { TObject } from './object.types'

/**
 * Entity class representing an Object with validation
 */
export class ObjectEntity implements TObject {

	'@self': {
		id: string
		uuid: string
		uri: string
		register: string
		schema: string
		relations: string
		files: string
		folder: string
		updated: string
		created: string
		locked: string[] | null
		owner: string
	}

	[key: string]: unknown

	constructor(object: TObject) {
		this['@self'] = {
			id: object['@self']?.id || '',
			uuid: object['@self']?.uuid || '',
			uri: object['@self']?.uri || '',
			register: object['@self']?.register || '',
			schema: object['@self']?.schema || '',
			relations: object['@self']?.relations || '',
			files: object['@self']?.files || '',
			folder: object['@self']?.folder || '',
			updated: object['@self']?.updated || '',
			created: object['@self']?.created || '',
			locked: object['@self']?.locked || null,
			owner: object['@self']?.owner || '',
		}

		// Copy any additional properties
		Object.keys(object).forEach(key => {
			if (key !== '@self') {
				this[key] = object[key]
			}
		})
	}

	/**
	 * Validates the object against a schema
	 */
	public validate(): SafeParseReturnType<TObject, unknown> {
		const schema = z.object({
			'@self': z.object({
				id: z.string().min(1),
				uuid: z.string().min(1),
				register: z.string().min(1),
				schema: z.string().min(1),
				relations: z.string(),
				files: z.string(),
				folder: z.string(),
				updated: z.string(),
				created: z.string(),
				locked: z.array(z.string()).nullable(),
				owner: z.string(),
			}),
		}).passthrough()

		return schema.safeParse(this)
	}

}

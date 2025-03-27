import { SafeParseReturnType, z } from 'zod'
import { TObject } from './object.types'

/**
 * Entity class representing an Object with validation
 */
export class ObjectEntity implements TObject {

	'@self': {
		id: string
		uri: string
		register: string
		schema: string
		relations: string
		files: string
		folder: string
		updated: string
		created: string
		locked: string[] | null
		owner: string | null
		organisation: string | null
		application: string | null
		version: string | null
		deleted: string[] | null
		geo: string[] | null
		retention: string[] | null		
	}

	[key: string]: unknown

	constructor(object: TObject) {
		this['@self'] = {
			id: object['@self']?.id || '',
			uri: object['@self']?.uri || '',
			register: object['@self']?.register || '',
			schema: object['@self']?.schema || '',
			relations: object['@self']?.relations || '',
			files: object['@self']?.files || '',
			folder: object['@self']?.folder || '',
			updated: object['@self']?.updated || '',
			created: object['@self']?.created || '',
			locked: object['@self']?.locked || null,
			owner: object['@self']?.owner || null,
			organisation: object['@self']?.organisation || null,
			application: object['@self']?.application || null,
			version: object['@self']?.version || null,
			deleted: object['@self']?.deleted || null,
			geo: object['@self']?.geo || null,
			retention: object['@self']?.retention || null,
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
				relations: z.array(z.string()).nullable(),
				files: z.array(z.string()).nullable(),
				folder: z.string().min(1),
				updated: z.string().min(1),
				created: z.string().min(1),
				locked: z.array(z.string()).nullable(),
				owner: z.string().nullable(),
				organisation: z.string().nullable(),
				application: z.string().nullable(),
				version: z.string().nullable(),
				deleted: z.array(z.string()).nullable(),
				geo: z.array(z.string()).nullable(),
				retention: z.array(z.string()).nullable(),
			}),
		}).passthrough()

		return schema.safeParse(this)
	}

}

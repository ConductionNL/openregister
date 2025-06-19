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
		version: string | null
		register: string
		schema: string
		schemaVersion: string | null
		relations: string | Array<unknown> | null
		files: string | Array<unknown> | null
		folder: string | null
		textRepresentation: string | null
		locked: Array<unknown> | null
		owner: string | null
		authorization: Array<unknown> | null
		application: string | null
		organisation: string | null
		validation: Array<unknown> | null
		deleted: Array<unknown> | null
		geo: Array<unknown> | null
		retention: Array<unknown> | null
		size: string | null
		updated: string
		created: string
		published: string | null
		depublished: string | null
	}

	[key: string]: unknown

	constructor(object: TObject) {
		this['@self'] = {
			id: object['@self']?.id || '',
			uuid: object['@self']?.uuid || '',
			uri: object['@self']?.uri || '',
			version: object['@self']?.version || null,
			register: object['@self']?.register || '',
			schema: object['@self']?.schema || '',
			schemaVersion: object['@self']?.schemaVersion || null,
			relations: object['@self']?.relations || null,
			files: object['@self']?.files || null,
			folder: object['@self']?.folder || null,
			textRepresentation: object['@self']?.textRepresentation || null,
			locked: object['@self']?.locked || null,
			owner: object['@self']?.owner || null,
			authorization: object['@self']?.authorization || null,
			application: object['@self']?.application || null,
			organisation: object['@self']?.organisation || null,
			validation: object['@self']?.validation || null,
			deleted: object['@self']?.deleted || null,
			geo: object['@self']?.geo || null,
			retention: object['@self']?.retention || null,
			size: object['@self']?.size || null,
			updated: object['@self']?.updated || '',
			created: object['@self']?.created || '',
			published: object['@self']?.published || null,
			depublished: object['@self']?.depublished || null,
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
				uri: z.string().min(1),
				version: z.string().nullable(),
				register: z.string().min(1),
				schema: z.string().min(1),
				schemaVersion: z.string().nullable(),
				relations: z.union([z.string(), z.array(z.unknown())]).nullable(),
				files: z.union([z.string(), z.array(z.unknown())]).nullable(),
				folder: z.string().nullable(),
				textRepresentation: z.string().nullable(),
				locked: z.array(z.unknown()).nullable(),
				owner: z.string().nullable(),
				authorization: z.array(z.unknown()).nullable(),
				application: z.string().nullable(),
				organisation: z.string().nullable(),
				validation: z.array(z.unknown()).nullable(),
				deleted: z.array(z.unknown()).nullable(),
				geo: z.array(z.unknown()).nullable(),
				retention: z.array(z.unknown()).nullable(),
				size: z.string().nullable(),
				updated: z.string().min(1),
				created: z.string().min(1),
				published: z.string().nullable(),
				depublished: z.string().nullable(),
			}),
		}).passthrough()

		return schema.safeParse(this)
	}

}

import { SafeParseReturnType, z } from 'zod'
import { TObject } from './object.types'

/**
 * Entity class representing an Object with validation
 */
export class ObjectEntity implements TObject {

	public id: string
	public uuid: string
	public uri: string
	public register: string
	public schema: string
	public object: string
	public relations: string
	public files: string
	public updated: string
	public created: string
	public locked: string[] | null // Array of lock tokens or null if not locked
	public owner: string // Owner of the object

	constructor(object: TObject) {
		this.id = object.id || ''
		this.uuid = object.uuid
		this.uri = object.uri
		this.register = object.register
		this.schema = object.schema
		this.object = object.object
		this.relations = object.relations
		this.files = object.files
		this.updated = object.updated || ''
		this.created = object.created || ''
		this.locked = object.locked || null
		this.owner = object.owner || ''
	}

	/**
	 * Validates the object against a schema
	 * @returns {SafeParseReturnType<TObject, unknown>} Object containing validation result with success/error status
	 */
	public validate(): SafeParseReturnType<TObject, unknown> {
		const schema = z.object({
			id: z.string().min(1),
			uuid: z.string().min(1),
			register: z.string().min(1),
			schema: z.string().min(1),
			object: z.string(),
			relations: z.string(),
			files: z.string(),
			updated: z.string(),
			created: z.string(),
			locked: z.array(z.string()).nullable(),
			owner: z.string(),
		})

		return schema.safeParse(this)
	}

}

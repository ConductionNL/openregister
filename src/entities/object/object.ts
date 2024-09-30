import { SafeParseReturnType, z } from 'zod'
import { TObject } from './object.types'

export class ObjectEntity implements TObject {

	public id: string
	public uuid: string
	public register: string
	public schema: string
	public object: string
	public updated: string
	public created: string

	constructor(object: TObject) {
		this.id = object.id || ''
		this.uuid = object.uuid
		this.register = object.register
		this.schema = object.schema
		this.object = object.object
		this.updated = object.updated || ''
		this.created = object.created || ''
	}

	public validate(): SafeParseReturnType<TObject, unknown> {
		const schema = z.object({
			id: z.string().min(1),
			uuid: z.string().min(1),
			register: z.string().min(1),
			schema: z.string().min(1),
			object: z.string(),
			updated: z.string(),
			created: z.string(),
		})

		return schema.safeParse(this)
	}

}
